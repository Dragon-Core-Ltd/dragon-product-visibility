<?php
/**
 * Front-end enforcement: a restricted product stays hidden on every route,
 * including through its variations.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Visibility_Filter;
use PHPUnit\Framework\TestCase;

final class VisibilityFilterTest extends TestCase {

	private const RESTRICTED  = 100;
	private const RESTRICTED_VARIATION = 101;
	private const VISIBLE     = 200;
	private const VISIBLE_VARIATION = 201;
	private const BLOG_POST   = 300;

	private Visibility_Filter $filter;

	protected function setUp(): void {
		\dpv_test_reset();

		// A shopper, not a shop manager: the bootstrap grants every capability
		// by default, which would make every product visible.
		$GLOBALS['dpv_test_can'] = static function ( $cap ) {
			return 'manage_woocommerce' !== $cap;
		};

		$GLOBALS['dpv_test_posts'] = array(
			self::RESTRICTED           => $this->post( self::RESTRICTED, 'product', 0, 'secret-widget' ),
			self::RESTRICTED_VARIATION => $this->post( self::RESTRICTED_VARIATION, 'product_variation', self::RESTRICTED, 'secret-widget-red' ),
			self::VISIBLE              => $this->post( self::VISIBLE, 'product', 0, 'open-widget' ),
			self::VISIBLE_VARIATION    => $this->post( self::VISIBLE_VARIATION, 'product_variation', self::VISIBLE, 'open-widget-blue' ),
			self::BLOG_POST            => $this->post( self::BLOG_POST, 'post', 0, 'a-blog-post' ),
		);

		// Whitelisted for nobody the shopper matches.
		$GLOBALS['dpv_test_meta'][ self::RESTRICTED ]['_dpv_restriction_mode'] = 'whitelist';

		$this->filter = ( new \ReflectionClass( Visibility_Filter::class ) )->newInstanceWithoutConstructor();
	}

	private function post( int $id, string $type, int $parent, string $slug ): object {
		return (object) array(
			'ID'          => $id,
			'post_type'   => $type,
			'post_parent' => $parent,
			'post_name'   => $slug,
		);
	}

	/**
	 * Run a REST request through the filter the way core would: URL params from
	 * the matched route, optional query-string params, and the matched handler.
	 */
	private function rest( string $route, array $query = array(), ?object $controller = null, string $method = 'GET' ) {
		$request = new \WP_REST_Request( $method, $route );

		if ( preg_match( '#/products/(?P<id>\d+)$#i', $route, $m ) || preg_match( '#^/wp/v2/[a-z_]+/(?P<id>\d+)$#i', $route, $m ) ) {
			$request->set_url_params( array( 'id' => $m['id'] ) );
		} elseif ( preg_match( '#/products/(?P<slug>\S+)$#i', $route, $m ) ) {
			$request->set_url_params( array( 'slug' => $m['slug'] ) );
		}
		$request->set_query_params( $query );

		$handler = $controller ? array( 'callback' => array( $controller, $controller instanceof \WP_REST_Posts_Controller ? 'get_item' : 'get_response' ) ) : array();

		return $this->filter->filter_rest_single_product( null, $handler, $request );
	}

	public function test_a_variation_inherits_its_restricted_parent(): void {
		$this->assertFalse( $this->filter->user_can_view_product( self::RESTRICTED ) );
		$this->assertFalse( $this->filter->user_can_view_product( self::RESTRICTED_VARIATION ), 'a variation must not be visible when its product is not' );
	}

	public function test_a_variation_of_a_visible_product_stays_visible(): void {
		$this->assertTrue( $this->filter->user_can_view_product( self::VISIBLE_VARIATION ) );
	}

	public function test_shop_managers_still_see_restricted_variations(): void {
		$GLOBALS['dpv_test_can'] = null;

		$this->assertTrue( $this->filter->user_can_view_product( self::RESTRICTED_VARIATION ) );
	}

	public function test_store_api_variation_listing_excludes_variations_of_restricted_products(): void {
		// Store API ?type=variation, with a caller-supplied parent_exclude.
		$query = new \WP_Query(
			array(
				'post_type'           => 'product_variation',
				'post_parent__not_in' => array( 555 ),
			)
		);

		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( array( 555, self::RESTRICTED ), $query->get( 'post_parent__not_in' ), 'restricted parents are merged into, not written over, parent_exclude' );
	}

	public function test_store_api_sku_and_slug_listing_excludes_both_products_and_variations(): void {
		// ?sku= and ?slug= search products and variations together.
		$query = new \WP_Query( array( 'post_type' => array( 'product', 'product_variation' ) ) );

		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( array( self::RESTRICTED ), $query->get( 'post__not_in' ) );
		$this->assertSame( array( self::RESTRICTED ), $query->get( 'post_parent__not_in' ) );
	}

	public function test_a_product_listing_does_not_gain_a_parent_exclusion(): void {
		$query = new \WP_Query( array( 'post_type' => 'product' ) );

		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( array( self::RESTRICTED ), $query->get( 'post__not_in' ) );
		$this->assertSame( array(), $query->get( 'post_parent__not_in' ) );
	}

	public function test_woocommerce_children_lookup_is_left_alone(): void {
		// WooCommerce's read_children() caches this result for every visitor, so
		// filtering it for one shopper would empty the product for everyone.
		$query = new \WP_Query(
			array(
				'post_type'   => 'product_variation',
				'post_parent' => self::RESTRICTED,
			)
		);

		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( array(), $query->get( 'post_parent__not_in' ) );
		$this->assertSame( array(), $query->get( 'post__not_in' ) );
	}

	public function test_store_api_single_variation_by_id_is_refused(): void {
		$response = $this->rest( '/wc/store/v1/products/' . self::RESTRICTED_VARIATION );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 404, $response->data['status'] );
	}

	public function test_store_api_single_variation_by_slug_is_refused(): void {
		$response = $this->rest( '/wc/store/v1/products/secret-widget-red' );

		$this->assertInstanceOf( \WP_Error::class, $response );
	}

	public function test_store_api_single_routes_still_serve_visible_items(): void {
		$this->assertNull( $this->rest( '/wc/store/v1/products/' . self::VISIBLE_VARIATION ) );
		$this->assertNull( $this->rest( '/wc/store/v1/products/open-widget-blue' ) );
		$this->assertNull( $this->rest( '/wc/store/v1/products/open-widget' ) );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/v1/products/secret-widget' ) );
	}

	public function test_add_to_cart_refuses_a_variation_id_of_a_restricted_product(): void {
		$this->assertFalse( $this->filter->validate_add_to_cart( true, self::RESTRICTED_VARIATION, 1 ) );
	}

	public function test_add_to_cart_refuses_the_store_api_parent_and_variation_pair(): void {
		// Store API and the classic form pass the parent as product_id.
		$this->assertFalse( $this->filter->validate_add_to_cart( true, self::RESTRICTED, 1, self::RESTRICTED_VARIATION ) );
		$this->assertTrue( $this->filter->validate_add_to_cart( true, self::VISIBLE, 1, self::VISIBLE_VARIATION ) );
	}

	public function test_add_to_cart_checks_the_variation_argument(): void {
		// A third party passing a visible product id with a restricted variation.
		$this->assertFalse( $this->filter->validate_add_to_cart( true, self::VISIBLE, 1, self::RESTRICTED_VARIATION ) );
	}

	public function test_add_to_cart_validation_receives_the_variation_argument(): void {
		$this->register_hooks();

		$this->assertSame( 4, $this->hook( 'woocommerce_add_to_cart_validation' )[3] );
	}

	public function test_restricted_product_url_is_left_to_the_redirect_instead_of_a_404(): void {
		// The main query for /product/secret-widget/: filtering it would 404
		// before template_redirect can send the shopper to the shop.
		$query              = new \WP_Query( array( 'post_type' => 'product' ) );
		$query->main        = true;
		$query->is_singular = true;

		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( array(), $query->get( 'post__not_in' ) );
	}

	public function test_non_singular_main_query_is_still_filtered(): void {
		$query       = new \WP_Query( array( 'post_type' => 'product' ) );
		$query->main = true;

		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( array( self::RESTRICTED ), $query->get( 'post__not_in' ) );
	}

	public function test_single_product_check_runs_before_canonical_redirects(): void {
		$this->register_hooks();

		$this->assertLessThan( 10, $this->hook( 'template_redirect' )[2], 'redirect_canonical runs at 10 and could redirect first' );
	}

	private function register_hooks(): void {
		$init = new \ReflectionMethod( Visibility_Filter::class, 'init_hooks' );
		$init->setAccessible( true );
		$init->invoke( $this->filter );
	}

	private function hook( string $tag ): array {
		foreach ( $GLOBALS['dpv_test_actions'] as $action ) {
			if ( $tag === $action[0] && is_array( $action[1] ) && $action[1][0] === $this->filter ) {
				return $action;
			}
		}
		$this->fail( "{$tag} is not hooked" );
	}

	private function add_restricted_notice(): void {
		$method = new \ReflectionMethod( Visibility_Filter::class, 'add_restricted_notice' );
		$method->setAccessible( true );
		$method->invoke( $this->filter );
	}

	public function test_a_guest_without_a_session_gets_one_so_the_redirect_notice_survives(): void {
		// WooCommerce only saves session data (and so notices) for a visitor with
		// a session cookie; a first-time guest would lose the notice.
		$this->add_restricted_notice();

		$this->assertTrue( $GLOBALS['dpv_test_wc']->session->cookie_set );
		$this->assertCount( 1, $GLOBALS['dpv_test_notices'] );
		$this->assertSame( 'error', $GLOBALS['dpv_test_notices'][0][1] );
	}

	public function test_an_existing_session_is_left_as_it_is(): void {
		$GLOBALS['dpv_test_wc']->session->cookie = true;

		$this->add_restricted_notice();

		$this->assertFalse( $GLOBALS['dpv_test_wc']->session->cookie_set );
		$this->assertCount( 1, $GLOBALS['dpv_test_notices'] );
	}

	public function test_store_api_unversioned_namespace_is_refused(): void {
		// WooCommerce registers every Store API route under wc/store as well as wc/store/v1.
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/products/' . self::RESTRICTED ) );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/products/secret-widget' ) );
		$this->assertNull( $this->rest( '/wc/store/products/' . self::VISIBLE ) );
	}

	public function test_store_api_route_case_does_not_bypass_the_check(): void {
		// Core matches routes with the i flag, so /Products/ still reaches the route.
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/v1/Products/' . self::RESTRICTED ) );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/WC/Store/V1/products/secret-widget' ) );
	}

	public function test_store_api_matched_controller_is_recognised_whatever_the_path(): void {
		$by_id   = new \Automattic\WooCommerce\StoreApi\Routes\V1\ProductsById();
		$by_slug = new \Automattic\WooCommerce\StoreApi\Routes\V1\ProductsBySlug();

		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/products/' . self::RESTRICTED, array(), $by_id ) );
		// The slug route matches [\S]+ and sanitize_title() drops the slash.
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/v1/products/secret-/widget', array(), $by_slug ) );
		$this->assertNull( $this->rest( '/wc/store/v1/products/open-widget', array(), $by_slug ) );
	}

	public function test_store_api_slug_with_a_slash_is_refused_without_a_handler(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/v1/products/secret-/widget' ) );
	}

	public function test_store_api_query_string_id_outranks_the_url_segment(): void {
		// WP_REST_Request reads GET before URL params, and the route reads $request['id'].
		$by_id   = new \Automattic\WooCommerce\StoreApi\Routes\V1\ProductsById();
		$by_slug = new \Automattic\WooCommerce\StoreApi\Routes\V1\ProductsBySlug();

		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/v1/products/' . self::VISIBLE, array( 'id' => self::RESTRICTED ), $by_id ) );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/v1/products/open-widget', array( 'slug' => 'secret-widget' ), $by_slug ) );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/v1/products/' . self::VISIBLE, array( 'id' => self::RESTRICTED ) ) );
	}

	public function test_store_api_sibling_routes_are_not_treated_as_slugs(): void {
		$GLOBALS['dpv_test_posts'][900] = $this->post( 900, 'product', 0, 'reviews' );
		$GLOBALS['dpv_test_meta'][900]['_dpv_restriction_mode'] = 'whitelist';

		$this->assertNull( $this->rest( '/wc/store/v1/products/reviews' ) );
		$this->assertNull( $this->rest( '/wc/store/v1/products/Reviews' ) );
		$this->assertNull( $this->rest( '/wc/store/v1/products/attributes/3/terms' ) );
		$this->assertNull( $this->rest( '/wc/store/v1/products/reviews', array(), new \Automattic\WooCommerce\StoreApi\Routes\V1\ProductReviews() ) );
	}

	public function test_core_wp_v2_product_item_is_refused(): void {
		$controller = new \WP_REST_Posts_Controller();

		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wp/v2/product/' . self::RESTRICTED, array(), $controller ) );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wp/v2/product/' . self::RESTRICTED ) );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wp/v2/Product/' . self::RESTRICTED ) );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wp/v2/product/' . self::VISIBLE, array( 'id' => self::RESTRICTED ), $controller ) );
		$this->assertNull( $this->rest( '/wp/v2/product/' . self::VISIBLE, array(), $controller ) );
		$this->assertNull( $this->rest( '/wp/v2/posts/' . self::BLOG_POST, array(), $controller ) );
	}

	public function test_explicit_include_list_cannot_name_a_restricted_product(): void {
		// WP_Query ignores post__not_in whenever post__in is set (Store API
		// ?include= and ?on_sale=, wp/v2 ?include=).
		$query = new \WP_Query(
			array(
				'post_type' => 'product',
				'post__in'  => array( self::RESTRICTED, self::VISIBLE ),
			)
		);

		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( array( self::VISIBLE ), $query->get( 'post__in' ) );
	}

	public function test_an_include_list_of_only_restricted_products_matches_nothing(): void {
		$query = new \WP_Query(
			array(
				'post_type' => 'product',
				'post__in'  => array( self::RESTRICTED ),
			)
		);

		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( array( 0 ), $query->get( 'post__in' ), 'an emptied list would mean no constraint' );
	}

	public function test_a_single_id_query_for_a_restricted_product_matches_nothing(): void {
		$query = new \WP_Query(
			array(
				'post_type' => 'product',
				'p'         => self::RESTRICTED,
			)
		);

		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( 0, $query->get( 'p' ) );
		$this->assertSame( array( 0 ), $query->get( 'post__in' ) );
	}

	public function test_a_parent_list_cannot_name_a_restricted_product(): void {
		// Store API ?type=variation&parent[]= sets post_parent__in, which makes
		// WP_Query ignore post_parent__not_in.
		$query = new \WP_Query(
			array(
				'post_type'       => 'product_variation',
				'post_parent__in' => array( self::RESTRICTED ),
			)
		);

		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( array( 0 ), $query->get( 'post_parent__in' ) );
	}

	public function test_shortcode_query_args_carry_the_exclusions_so_the_cache_key_differs(): void {
		$args = array(
			'post_type' => 'product',
			'post__in'  => array( self::RESTRICTED, self::VISIBLE ),
		);

		$filtered = $this->filter->filter_shortcode_products_query( $args );

		$this->assertSame( array( self::RESTRICTED ), $filtered['post__not_in'] );
		$this->assertSame( array( self::VISIBLE ), $filtered['post__in'] );
		$this->assertNotSame( $args, $filtered, 'WooCommerce names the result transient after these args' );
	}

	public function test_shortcode_query_args_are_unchanged_when_nothing_is_restricted(): void {
		$GLOBALS['dpv_test_can'] = null;
		unset( $GLOBALS['dpv_test_meta'][ self::RESTRICTED ] );
		$args = array( 'post_type' => 'product' );

		$this->assertSame( $args, $this->filter->filter_shortcode_products_query( $args ) );
	}

	public function test_variation_data_of_a_restricted_product_is_withheld(): void {
		$this->assertFalse( $this->filter->filter_available_variation( array( 'sku' => 'S' ), $this->product( self::RESTRICTED ) ) );
		$this->assertSame( array( 'sku' => 'O' ), $this->filter->filter_available_variation( array( 'sku' => 'O' ), $this->product( self::VISIBLE ) ) );
	}

	public function test_reviews_of_restricted_products_are_excluded_from_comment_queries(): void {
		$query = new \WP_Comment_Query( array( 'post__in' => array( self::RESTRICTED ) ) );

		$this->filter->filter_comment_query( $query );

		$this->assertSame( array( self::RESTRICTED ), $query->query_vars['post__not_in'] );
	}

	public function test_comment_queries_in_admin_are_left_alone(): void {
		$GLOBALS['dpv_test_is_admin'] = true;
		$query                        = new \WP_Comment_Query( array() );

		$this->filter->filter_comment_query( $query );

		$this->assertArrayNotHasKey( 'post__not_in', $query->query_vars );
	}

	public function test_oembed_of_a_restricted_product_is_refused(): void {
		$this->assertSame( 0, $this->filter->filter_oembed_post_id( self::RESTRICTED ) );
		$this->assertSame( 0, $this->filter->filter_oembed_post_id( self::RESTRICTED_VARIATION ) );
		$this->assertSame( self::VISIBLE, $this->filter->filter_oembed_post_id( self::VISIBLE ) );
		$this->assertSame( self::BLOG_POST, $this->filter->filter_oembed_post_id( self::BLOG_POST ) );
	}

	public function test_every_product_data_route_is_hooked(): void {
		$this->register_hooks();

		foreach ( array( 'rest_request_before_callbacks', 'woocommerce_shortcode_products_query', 'woocommerce_available_variation', 'pre_get_comments', 'oembed_request_post_id', 'pre_get_posts' ) as $tag ) {
			$this->assertNotEmpty( $this->hook( $tag ) );
		}
		$this->assertGreaterThanOrEqual( 2, $this->hook( 'woocommerce_available_variation' )[3] );
	}

	public function test_an_unrecognised_controller_is_still_judged_by_its_route(): void {
		// A proxy, subclass or older WooCommerce (Blocks-namespace Store API).
		$other = new class() {
			public function get_response( $request ) {
				return $request;
			}
		};

		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/v1/products/' . self::RESTRICTED, array(), $other ) );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wc/store/v1/products/secret-widget', array(), $other ) );
	}

	public function test_core_product_writes_are_left_to_their_own_permission_check(): void {
		$this->assertNull( $this->rest( '/wp/v2/product/' . self::RESTRICTED, array(), new \WP_REST_Posts_Controller(), 'POST' ) );
	}

	public function test_front_end_admin_ajax_queries_are_filtered(): void {
		// is_admin() is true for every admin-ajax.php request, a guest's live
		// search or load-more included.
		$GLOBALS['dpv_test_is_admin']   = true;
		$GLOBALS['dpv_test_doing_ajax'] = true;

		$query = new \WP_Query( array( 'post_type' => 'product' ) );
		$this->filter->filter_product_pre_get_posts( $query );
		$this->assertSame( array( self::RESTRICTED ), $query->get( 'post__not_in' ) );

		$comments = new \WP_Comment_Query( array() );
		$this->filter->filter_comment_query( $comments );
		$this->assertSame( array( self::RESTRICTED ), $comments->query_vars['post__not_in'] );
	}

	public function test_admin_screen_queries_are_left_alone(): void {
		$GLOBALS['dpv_test_is_admin'] = true;

		$query = new \WP_Query( array( 'post_type' => 'product' ) );
		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertSame( array(), $query->get( 'post__not_in' ) );
	}

	/**
	 * Run pre_render_block the way core does for a parsed block.
	 */
	private function block( string $name, array $attrs, $pre = null ) {
		return $this->filter->filter_pre_render_block(
			$pre,
			array(
				'blockName'   => $name,
				'attrs'       => $attrs,
				'innerBlocks' => array(),
			)
		);
	}

	public function test_single_product_block_of_a_restricted_product_renders_nothing(): void {
		$this->assertSame( '', $this->block( 'woocommerce/single-product', array( 'productId' => self::RESTRICTED ) ) );
		$this->assertNull( $this->block( 'woocommerce/single-product', array( 'productId' => self::VISIBLE ) ) );
	}

	public function test_featured_product_block_of_a_restricted_product_or_its_variation_renders_nothing(): void {
		$this->assertSame( '', $this->block( 'woocommerce/featured-product', array( 'productId' => self::RESTRICTED ) ) );
		$this->assertSame( '', $this->block( 'woocommerce/featured-product', array( 'productId' => self::RESTRICTED_VARIATION ) ) );
		$this->assertNull( $this->block( 'woocommerce/featured-product', array( 'productId' => self::VISIBLE_VARIATION ) ) );
	}

	public function test_block_short_circuit_leaves_other_blocks_and_other_plugins_output_alone(): void {
		$this->assertNull( $this->block( 'core/paragraph', array( 'productId' => self::RESTRICTED ) ) );
		$this->assertNull( $this->block( 'woocommerce/single-product', array() ) );
		$this->assertSame( 'from another plugin', $this->block( 'woocommerce/single-product', array( 'productId' => self::VISIBLE ), 'from another plugin' ) );
	}

	public function test_product_blocks_are_short_circuited_before_their_inner_blocks_render(): void {
		$this->register_hooks();

		$this->assertGreaterThanOrEqual( 2, $this->hook( 'pre_render_block' )[3] );
	}

	/**
	 * Run pre_do_shortcode_tag for a registered tag.
	 */
	private function shortcode( string $tag, $attr ) {
		return $this->filter->filter_pre_do_shortcode_tag( false, $tag, $attr );
	}

	private function register_wc_shortcodes(): void {
		// Tags are filterable ({$shortcode}_shortcode_tag), so a renamed tag must
		// still be recognised by its callback.
		$GLOBALS['shortcode_tags'] = array(
			'add_to_cart'     => 'WC_Shortcodes::product_add_to_cart',
			'buy_url'         => 'WC_Shortcodes::product_add_to_cart_url',
			'product_page'    => 'WC_Shortcodes::product_page',
			'gallery'         => 'gallery_shortcode',
		);
		$GLOBALS['dpv_test_skus'] = array(
			'SECRET' => self::RESTRICTED,
			'SECRET-RED' => self::RESTRICTED_VARIATION,
			'OPEN'   => self::VISIBLE,
		);
	}

	public function test_add_to_cart_shortcode_for_a_restricted_product_renders_nothing(): void {
		$this->register_wc_shortcodes();

		$this->assertSame( '', $this->shortcode( 'add_to_cart', array( 'id' => (string) self::RESTRICTED ) ) );
		$this->assertSame( '', $this->shortcode( 'add_to_cart', array( 'id' => (string) self::RESTRICTED_VARIATION ) ) );
		$this->assertSame( '', $this->shortcode( 'add_to_cart', array( 'sku' => 'SECRET' ) ) );
		$this->assertFalse( $this->shortcode( 'add_to_cart', array( 'id' => (string) self::VISIBLE ) ) );
		$this->assertFalse( $this->shortcode( 'add_to_cart', array( 'sku' => 'OPEN' ) ) );
	}

	public function test_add_to_cart_shortcode_follows_its_own_id_over_sku_precedence(): void {
		$this->register_wc_shortcodes();

		// product_add_to_cart(): ! empty( id ), else ! empty( sku ).
		$this->assertFalse( $this->shortcode( 'add_to_cart', array( 'id' => (string) self::VISIBLE, 'sku' => 'SECRET' ) ) );
		$this->assertSame( '', $this->shortcode( 'add_to_cart', array( 'id' => '', 'sku' => 'SECRET' ) ) );
	}

	public function test_add_to_cart_url_shortcode_is_recognised_under_a_renamed_tag(): void {
		$this->register_wc_shortcodes();

		$this->assertSame( '', $this->shortcode( 'buy_url', array( 'id' => (string) self::RESTRICTED ) ) );
		$this->assertSame( '', $this->shortcode( 'buy_url', array( 'sku' => 'SECRET-RED' ) ) );
		// product_add_to_cart_url(): isset( id ) wins even when empty.
		$this->assertFalse( $this->shortcode( 'buy_url', array( 'id' => '', 'sku' => 'SECRET' ) ) );
		$this->assertFalse( $this->shortcode( 'buy_url', array( 'id' => (string) self::VISIBLE ) ) );
	}

	public function test_product_page_shortcode_for_a_restricted_product_renders_nothing(): void {
		$this->register_wc_shortcodes();

		$this->assertSame( '', $this->shortcode( 'product_page', array( 'id' => (string) self::RESTRICTED ) ) );
		$this->assertSame( '', $this->shortcode( 'product_page', array( 'sku' => 'SECRET-RED' ) ) );
		$this->assertFalse( $this->shortcode( 'product_page', array( 'id' => (string) self::VISIBLE ) ) );
	}

	public function test_other_shortcodes_and_attributeless_calls_pass_through(): void {
		$this->register_wc_shortcodes();

		$this->assertFalse( $this->shortcode( 'gallery', array( 'id' => (string) self::RESTRICTED ) ) );
		$this->assertFalse( $this->shortcode( 'unregistered', array( 'id' => (string) self::RESTRICTED ) ) );
		$this->assertFalse( $this->shortcode( 'add_to_cart', '' ) );
		$this->assertSame( 'kept', $this->filter->filter_pre_do_shortcode_tag( 'kept', 'add_to_cart', array( 'id' => (string) self::RESTRICTED ) ) );
	}

	public function test_shortcode_short_circuit_is_hooked(): void {
		$this->register_hooks();

		$this->assertGreaterThanOrEqual( 3, $this->hook( 'pre_do_shortcode_tag' )[3] );
	}

	public function test_cross_sell_up_sell_and_grouped_getters_are_hooked_under_woocommerce_names(): void {
		$this->register_hooks();

		// WC_Data::get_prop() fires {prefix}{prop}: "cross_sell_ids", and
		// variations use the woocommerce_product_variation_get_ prefix.
		foreach ( array(
			'woocommerce_product_get_cross_sell_ids',
			'woocommerce_product_get_upsell_ids',
			'woocommerce_product_variation_get_cross_sell_ids',
			'woocommerce_product_variation_get_upsell_ids',
			'woocommerce_product_get_children',
		) as $tag ) {
			$this->assertGreaterThanOrEqual( 2, $this->hook( $tag )[3], $tag );
		}

		$tags = array_column( $GLOBALS['dpv_test_actions'], 0 );
		$this->assertNotContains( 'woocommerce_product_get_crosssell_ids', $tags, 'WooCommerce never fires this name' );
	}

	public function test_grouped_children_exclude_restricted_products(): void {
		$product = new \WC_Product( 500 );

		$this->assertSame( array( self::VISIBLE ), $this->filter->filter_product_children( array( self::RESTRICTED, self::VISIBLE ), $product ) );
	}

	public function test_grouped_children_are_left_alone_on_admin_screens(): void {
		$GLOBALS['dpv_test_is_admin'] = true;

		$this->assertSame( array( self::RESTRICTED, self::VISIBLE ), $this->filter->filter_product_children( array( self::RESTRICTED, self::VISIBLE ), new \WC_Product( 500 ) ) );
	}

	public function test_media_listing_excludes_images_attached_to_restricted_products(): void {
		$args = $this->filter->filter_rest_attachment_query( array( 'post_parent__not_in' => array( 7 ) ), new \WP_REST_Request( 'GET', '/wp/v2/media' ) );

		$this->assertSame( array( 7, self::RESTRICTED ), $args['post_parent__not_in'] );
	}

	public function test_media_listing_by_a_restricted_parent_matches_nothing(): void {
		// ?parent= sets post_parent__in, which makes WP_Query ignore
		// post_parent__not_in; post_parent__in = [0] would mean unattached media.
		$args = $this->filter->filter_rest_attachment_query( array( 'post_parent__in' => array( self::RESTRICTED ) ), new \WP_REST_Request( 'GET', '/wp/v2/media' ) );

		$this->assertSame( array( 0 ), $args['post__in'] );
		$this->assertNotSame( array( 0 ), $args['post_parent__in'] );

		$mixed = $this->filter->filter_rest_attachment_query( array( 'post_parent__in' => array( self::RESTRICTED, self::VISIBLE ) ), new \WP_REST_Request( 'GET', '/wp/v2/media' ) );
		$this->assertSame( array( self::VISIBLE ), $mixed['post_parent__in'] );
		$this->assertArrayNotHasKey( 'post__in', $mixed );
	}

	public function test_media_query_filter_is_hooked(): void {
		$this->register_hooks();

		$this->assertNotEmpty( $this->hook( 'rest_attachment_query' ) );
	}

	public function test_single_media_item_attached_to_a_restricted_product_is_refused(): void {
		$GLOBALS['dpv_test_posts'][400] = $this->post( 400, 'attachment', self::RESTRICTED, 'secret-widget-photo' );
		$GLOBALS['dpv_test_posts'][401] = $this->post( 401, 'attachment', self::VISIBLE, 'open-widget-photo' );
		$controller                     = new \WP_REST_Attachments_Controller();

		$refused = $this->rest( '/wp/v2/media/400', array(), $controller );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 404, $refused->data['status'] );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wp/v2/media/400' ) );
		$this->assertNull( $this->rest( '/wp/v2/media/401', array(), $controller ) );
	}

	public function test_single_review_of_a_restricted_product_is_refused(): void {
		$GLOBALS['dpv_test_comments'] = array(
			50 => (object) array( 'comment_ID' => '50', 'comment_post_ID' => (string) self::RESTRICTED ),
			51 => (object) array( 'comment_ID' => '51', 'comment_post_ID' => (string) self::VISIBLE ),
			52 => (object) array( 'comment_ID' => '52', 'comment_post_ID' => (string) self::BLOG_POST ),
		);
		$controller = new \WP_REST_Comments_Controller();

		$refused = $this->rest( '/wp/v2/comments/50', array(), $controller );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'rest_comment_invalid_id', $refused->code );
		$this->assertSame( 404, $refused->data['status'] );
		$this->assertInstanceOf( \WP_Error::class, $this->rest( '/wp/v2/Comments/50' ) );
		$this->assertNull( $this->rest( '/wp/v2/comments/51', array(), $controller ) );
		$this->assertNull( $this->rest( '/wp/v2/comments/52', array(), $controller ) );
		$this->assertNull( $this->rest( '/wp/v2/comments/999', array(), $controller ) );
		$this->assertNull( $this->rest( '/wp/v2/comments/50', array(), $controller, 'POST' ), 'writes keep core\'s own permission check' );
	}

	public function test_attachment_page_of_a_restricted_product_image_is_a_404(): void {
		$GLOBALS['dpv_test_posts'][400] = $this->post( 400, 'attachment', self::RESTRICTED, 'secret-widget-photo' );
		$GLOBALS['dpv_test_is_attachment'] = true;
		$GLOBALS['post']                   = $GLOBALS['dpv_test_posts'][400];
		$GLOBALS['wp_query']               = new \WP_Query( array() );

		$this->filter->check_single_product_access();

		$this->assertTrue( $GLOBALS['wp_query']->is_404 );
		$this->assertSame( 404, $GLOBALS['dpv_test_status_header'] );
	}

	public function test_attachment_page_of_a_visible_product_image_is_served(): void {
		$GLOBALS['dpv_test_posts'][401] = $this->post( 401, 'attachment', self::VISIBLE, 'open-widget-photo' );
		$GLOBALS['dpv_test_is_attachment'] = true;
		$GLOBALS['post']                   = $GLOBALS['dpv_test_posts'][401];
		$GLOBALS['wp_query']               = new \WP_Query( array() );

		$this->filter->check_single_product_access();

		$this->assertFalse( $GLOBALS['wp_query']->is_404 );
		$this->assertNull( $GLOBALS['dpv_test_status_header'] );
	}

	private function product( int $id ): object {
		return new class( $id ) {
			private int $id;

			public function __construct( int $id ) {
				$this->id = $id;
			}

			public function get_id() {
				return $this->id;
			}
		};
	}
}
