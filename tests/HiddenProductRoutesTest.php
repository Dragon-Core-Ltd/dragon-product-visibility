<?php
/**
 * Routes that would name, link or cache a hidden product: Store API cart and
 * list writes, canonical redirects, the add-to-cart error redirect, comments
 * and the comments feed, and WooCommerce's shared widget and grid caches.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Visibility_Filter;
use PHPUnit\Framework\TestCase;

final class HiddenProductRoutesTest extends TestCase {

	private const HIDDEN           = 100;
	private const HIDDEN_VARIATION = 101;
	private const VISIBLE          = 200;
	private const BLOG_POST        = 300;
	private const HIDDEN_IMAGE     = 400;
	private const VISIBLE_IMAGE    = 401;

	private Visibility_Filter $filter;

	protected function setUp(): void {
		\dpv_test_reset();

		// A shopper, not a shop manager.
		$GLOBALS['dpv_test_can'] = static function ( $cap ) {
			return 'manage_woocommerce' !== $cap;
		};

		$GLOBALS['dpv_test_posts'] = array(
			self::HIDDEN           => $this->post( self::HIDDEN, 'product', 0, 'secret-widget' ),
			self::HIDDEN_VARIATION => $this->post( self::HIDDEN_VARIATION, 'product_variation', self::HIDDEN, 'secret-widget-red' ),
			self::VISIBLE          => $this->post( self::VISIBLE, 'product', 0, 'open-widget' ),
			self::BLOG_POST        => $this->post( self::BLOG_POST, 'post', 0, 'a-blog-post' ),
			self::HIDDEN_IMAGE     => $this->post( self::HIDDEN_IMAGE, 'attachment', self::HIDDEN, 'secret-photo' ),
			self::VISIBLE_IMAGE    => $this->post( self::VISIBLE_IMAGE, 'attachment', self::VISIBLE, 'open-photo' ),
		);

		$GLOBALS['dpv_test_meta'][ self::HIDDEN ]['_dpv_restriction_mode'] = 'whitelist';

		$this->filter                       = ( new \ReflectionClass( Visibility_Filter::class ) )->newInstanceWithoutConstructor();
		$GLOBALS['dpv_test_pre_get_posts'] = array( $this->filter, 'filter_product_pre_get_posts' );
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
	 * A Store API write as core hands it to rest_request_before_callbacks.
	 */
	private function store_write( string $route, array $body, string $method = 'POST', string $controller = '' ) {
		$request = new \WP_REST_Request( $method, $route );
		$request->set_body_params( $body );

		$handler = array();
		if ( '' !== $controller ) {
			$handler = array( 'callback' => array( new $controller(), 'get_response' ) );
		}

		return $this->filter->filter_rest_single_product( null, $handler, $request );
	}

	private function assert_refused( $result, string $what ): void {
		$this->assertInstanceOf( \WP_Error::class, $result, $what );
		$this->assertSame( 'woocommerce_rest_product_invalid_id', $result->code, $what );
		$this->assertSame( 404, $result->data['status'], $what );
		$this->assertStringNotContainsString( 'secret', $result->message, $what );
	}

	// --- F1: Store API cart writes --------------------------------------------------

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cart_routes(): array {
		return array(
			'add-item v1'        => array( '/wc/store/v1/cart/add-item' ),
			'add-item'           => array( '/wc/store/cart/add-item' ),
			'items v1'           => array( '/wc/store/v1/cart/items' ),
			'items, other case'  => array( '/WC/Store/V1/Cart/Items/' ),
		);
	}

	/**
	 * @dataProvider cart_routes
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'cart_routes' )]
	public function test_store_api_cart_write_of_a_hidden_product_is_refused_before_woocommerce_names_it( string $route ): void {
		$this->assert_refused( $this->store_write( $route, array( 'id' => self::HIDDEN, 'quantity' => 0 ) ), "$route, product" );
		$this->assert_refused( $this->store_write( $route, array( 'id' => (string) self::HIDDEN_VARIATION ) ), "$route, variation" );
	}

	/**
	 * @dataProvider cart_routes
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'cart_routes' )]
	public function test_store_api_cart_write_of_a_visible_product_goes_through( string $route ): void {
		$this->assertNull( $this->store_write( $route, array( 'id' => self::VISIBLE ) ) );
	}

	public function test_store_api_cart_routes_are_recognised_by_controller(): void {
		$this->assert_refused( $this->store_write( '/proxy/whatever', array( 'id' => self::HIDDEN ), 'POST', \Automattic\WooCommerce\StoreApi\Routes\V1\CartAddItem::class ), 'CartAddItem' );
		$this->assert_refused( $this->store_write( '/proxy/whatever', array( 'id' => self::HIDDEN ), 'POST', \Automattic\WooCommerce\StoreApi\Routes\V1\CartItems::class ), 'CartItems' );
	}

	public function test_store_api_cart_reads_are_left_alone(): void {
		// GET cart/items lists the visitor's own cart.
		$this->assertNull( $this->store_write( '/wc/store/v1/cart/items', array( 'id' => self::HIDDEN ), 'GET' ) );
	}

	public function test_store_api_cart_write_with_a_malformed_id_is_left_to_woocommerce(): void {
		$this->assertNull( $this->store_write( '/wc/store/v1/cart/add-item', array( 'id' => array( self::HIDDEN ) ) ) );
		$this->assertNull( $this->store_write( '/wc/store/v1/cart/add-item', array() ) );
	}

	public function test_store_api_cart_write_by_a_shop_manager_goes_through(): void {
		$GLOBALS['dpv_test_can'] = null;

		$this->assertNull( $this->store_write( '/wc/store/v1/cart/add-item', array( 'id' => self::HIDDEN ) ) );
	}

	// --- F4: shopper lists -------------------------------------------------------------

	public function test_shopper_list_add_of_a_hidden_product_or_variation_is_refused(): void {
		$this->assert_refused( $this->store_write( '/wc/store/v1/shopper-lists/wishlist/items', array( 'product_id' => self::HIDDEN ) ), 'product_id' );
		$this->assert_refused( $this->store_write( '/wc/store/v1/shopper-lists/save-for-later/items', array( 'product_id' => self::VISIBLE, 'variation_id' => self::HIDDEN_VARIATION ) ), 'variation_id' );
		$this->assert_refused( $this->store_write( '/proxy', array( 'product_id' => self::HIDDEN ), 'POST', \Automattic\WooCommerce\StoreApi\Routes\V1\ShopperListItems::class ), 'controller' );
	}

	public function test_shopper_list_add_of_a_visible_product_and_list_reads_go_through(): void {
		$this->assertNull( $this->store_write( '/wc/store/v1/shopper-lists/wishlist/items', array( 'product_id' => self::VISIBLE ) ) );
		$this->assertNull( $this->store_write( '/wc/store/v1/shopper-lists/wishlist/items', array( 'product_id' => self::HIDDEN ), 'GET' ) );
	}

	// --- REST comment creation -----------------------------------------------------------

	private function comment_create( array $body, string $route = '/wp/v2/comments', ?object $controller = null ) {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_body_params( $body );
		$handler = $controller ? array( 'callback' => array( $controller, 'create_item' ) ) : array();

		return $this->filter->filter_rest_single_product( null, $handler, $request );
	}

	public function test_rest_comment_on_a_hidden_product_or_variation_is_refused(): void {
		$this->assert_refused( $this->comment_create( array( 'post' => self::HIDDEN, 'content' => 'x' ) ), 'product' );
		$this->assert_refused( $this->comment_create( array( 'post' => (string) self::HIDDEN_VARIATION ) ), 'variation' );
		$this->assert_refused( $this->comment_create( array( 'post' => self::HIDDEN ), '/WP/V2/Comments/' ), 'route case' );
		$this->assert_refused( $this->comment_create( array( 'post' => self::HIDDEN ), '/proxy', new \WP_REST_Comments_Controller() ), 'controller' );
	}

	public function test_rest_comment_on_visible_content_goes_through(): void {
		$this->assertNull( $this->comment_create( array( 'post' => self::VISIBLE ) ) );
		$this->assertNull( $this->comment_create( array( 'post' => self::BLOG_POST ) ) );
		$this->assertNull( $this->comment_create( array( 'post' => array( self::HIDDEN ) ) ) );
		$this->assertNull( $this->comment_create( array() ) );
	}

	public function test_rest_comment_listing_is_left_to_the_comment_query_filter(): void {
		$request = new \WP_REST_Request( 'GET', '/wp/v2/comments' );
		$request->set_query_params( array( 'post' => self::HIDDEN ) );

		$this->assertNull( $this->filter->filter_rest_single_product( null, array(), $request ) );
	}

	// --- checkout links -------------------------------------------------------------------

	/**
	 * @return array<string, array{0: string, 1: int[]}>
	 */
	public static function checkout_link_lists(): array {
		return array(
			'one id'             => array( '100', array( 100 ) ),
			'id and quantity'    => array( '100:3', array( 100 ) ),
			'several'            => array( '200:1,100:2,101', array( 200, 100, 101 ) ),
			'zero quantity'      => array( '100:0,200:1', array( 200 ) ),
			'junk and empties'   => array( ',abc,0:1,,200:x', array() ),
			'spaces'             => array( ' 200 : 1 ', array( 200 ) ),
			'empty'              => array( '', array() ),
		);
	}

	/**
	 * @dataProvider checkout_link_lists
	 * @param int[] $expected IDs WooCommerce would try to add.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'checkout_link_lists' )]
	public function test_checkout_link_ids_are_read_as_woocommerce_reads_them( string $raw, array $expected ): void {
		$this->assertSame( $expected, Visibility_Filter::checkout_link_product_ids( $raw ) );
	}

	private function checkout_link( $products ): ?string {
		$GLOBALS['dpv_test_query_vars']['checkout-link'] = 'true';
		$_GET['products']                                = $products;
		try {
			$this->filter->refuse_restricted_checkout_link();
		} catch ( \Dpv_Test_Redirect $redirect ) {
			return $redirect->location;
		}
		return null;
	}

	public function test_a_checkout_link_naming_a_hidden_product_is_refused_without_naming_it(): void {
		foreach ( array( '100', '100:5', '200:1,100:1', '101:1' ) as $products ) {
			$location = $this->checkout_link( $products );

			$this->assertNotNull( $location, $products );
			$this->assertStringStartsWith( 'https://example.test/cart/?wc_error=', $location, $products );
			$this->assertSame( 'Sorry, you cannot purchase this product.', rawurldecode( substr( $location, strlen( 'https://example.test/cart/?wc_error=' ) ) ), $products );
		}
	}

	public function test_checkout_links_for_visible_products_are_left_to_woocommerce(): void {
		$this->assertNull( $this->checkout_link( '200:2' ) );
		$this->assertNull( $this->checkout_link( '100:0,200:1' ), 'a zero-quantity entry is never added' );
		$this->assertNull( $this->checkout_link( array( '100' ) ) );
	}

	public function test_requests_that_are_not_checkout_links_are_left_alone(): void {
		$_GET['products'] = '100';

		$this->filter->refuse_restricted_checkout_link();

		$this->addToAssertionCount( 1 );
	}

	public function test_a_shop_manager_checkout_link_is_left_to_woocommerce(): void {
		$GLOBALS['dpv_test_can'] = null;

		$this->assertNull( $this->checkout_link( '100' ) );
	}

	// --- F2: canonical redirects ----------------------------------------------------------

	public function test_canonical_redirect_from_p_to_a_hidden_product_is_cancelled(): void {
		$GLOBALS['dpv_test_query_vars']['p'] = (string) self::HIDDEN;

		$this->assertFalse( $this->filter->filter_redirect_canonical( 'https://example.test/product/secret-widget/', 'https://example.test/?p=100' ) );
	}

	public function test_canonical_redirect_from_attachment_id_to_a_hidden_products_image_is_cancelled(): void {
		$GLOBALS['dpv_test_query_vars']['attachment_id'] = (string) self::HIDDEN_IMAGE;

		$this->assertFalse( $this->filter->filter_redirect_canonical( 'https://example.test/product/secret-widget/secret-photo/', 'https://example.test/?attachment_id=400' ) );
	}

	public function test_canonical_404_guess_that_lands_on_a_hidden_product_is_cancelled(): void {
		// No query var names it: only the guessed URL does, and the lookup of that
		// URL runs a product query this plugin filters.
		$url                                   = 'https://example.test/product/secret-widget/';
		$GLOBALS['dpv_test_url_posts'][ $url ] = self::HIDDEN;

		$this->assertFalse( $this->filter->filter_redirect_canonical( $url, 'https://example.test/product/secr' ) );
	}

	public function test_canonical_redirect_to_a_hidden_variation_url_is_cancelled(): void {
		$url                                   = 'https://example.test/?product_variation=secret-widget-red';
		$GLOBALS['dpv_test_url_posts'][ $url ] = self::HIDDEN_VARIATION;

		$this->assertFalse( $this->filter->filter_redirect_canonical( $url, 'https://example.test/?p=101' ) );
	}

	public function test_canonical_redirects_to_visible_content_are_kept(): void {
		$GLOBALS['dpv_test_query_vars']['p'] = (string) self::VISIBLE;
		$url                                 = 'https://example.test/product/open-widget/';
		$GLOBALS['dpv_test_url_posts'][ $url ] = self::VISIBLE;
		$this->assertSame( $url, $this->filter->filter_redirect_canonical( $url, 'https://example.test/?p=200' ) );

		$GLOBALS['dpv_test_query_vars'] = array( 'attachment_id' => (string) self::VISIBLE_IMAGE );
		$this->assertSame( 'https://example.test/x/', $this->filter->filter_redirect_canonical( 'https://example.test/x/', 'https://example.test/?attachment_id=401' ) );

		$GLOBALS['dpv_test_query_vars'] = array( 'p' => (string) self::BLOG_POST );
		$this->assertSame( 'https://example.test/a-blog-post/', $this->filter->filter_redirect_canonical( 'https://example.test/a-blog-post/', 'https://example.test/?p=300' ) );
	}

	public function test_canonical_redirect_runs_no_lookup_for_a_visitor_with_nothing_hidden(): void {
		unset( $GLOBALS['dpv_test_meta'][ self::HIDDEN ] );
		$url                                   = 'https://example.test/product/secret-widget/';
		$GLOBALS['dpv_test_url_posts'][ $url ] = self::HIDDEN;

		$this->assertSame( $url, $this->filter->filter_redirect_canonical( $url, 'https://example.test/?p=100' ) );
		$this->assertSame( 0, $GLOBALS['dpv_test_url_lookups'] );
	}

	public function test_canonical_filter_passes_a_cancelled_redirect_through(): void {
		$this->assertFalse( $this->filter->filter_redirect_canonical( false, 'https://example.test/' ) );
	}

	public function test_canonical_lookup_leaves_the_product_filters_working_afterwards(): void {
		$url                                   = 'https://example.test/product/secret-widget/';
		$GLOBALS['dpv_test_url_posts'][ $url ] = self::HIDDEN;
		$this->filter->filter_redirect_canonical( $url, 'https://example.test/product/secr' );

		$query = new \WP_Query( array( 'post_type' => 'product' ) );
		$this->filter->filter_product_pre_get_posts( $query );

		$this->assertContains( self::HIDDEN, $query->get( 'post__not_in' ) );
	}

	// --- F2: add-to-cart error redirect ------------------------------------------------

	public function test_add_to_cart_error_for_a_hidden_product_sends_the_visitor_to_the_shop(): void {
		$this->assertSame( 'https://example.test/shop/', $this->filter->filter_cart_redirect_after_error( 'https://example.test/product/secret-widget/', self::HIDDEN ) );
		$this->assertSame( 'https://example.test/shop/', $this->filter->filter_cart_redirect_after_error( 'https://example.test/product/secret-widget/', (string) self::HIDDEN_VARIATION ) );
	}

	public function test_add_to_cart_error_for_a_visible_product_keeps_its_page(): void {
		$this->assertSame( 'https://example.test/product/open-widget/', $this->filter->filter_cart_redirect_after_error( 'https://example.test/product/open-widget/', self::VISIBLE ) );
		$this->assertSame( 'https://example.test/x/', $this->filter->filter_cart_redirect_after_error( 'https://example.test/x/', 0 ) );
	}

	// --- F2: comments on a hidden product ------------------------------------------------

	public function test_a_comment_on_a_hidden_product_is_refused(): void {
		try {
			$this->filter->refuse_comment_on_restricted_product( (string) self::HIDDEN );
			$this->fail( 'the comment was not refused' );
		} catch ( \Dpv_Test_Die $die ) {
			$this->assertSame( 403, $die->status );
			$this->assertStringNotContainsString( 'secret', $die->getMessage() );
		}
	}

	public function test_comments_on_visible_products_and_posts_go_through(): void {
		$this->filter->refuse_comment_on_restricted_product( self::VISIBLE );
		$this->filter->refuse_comment_on_restricted_product( self::BLOG_POST );
		$this->filter->refuse_comment_on_restricted_product( 0 );

		$this->addToAssertionCount( 1 );
	}

	// --- F3: the comments feed ---------------------------------------------------------------

	public function test_comments_feed_excludes_reviews_of_hidden_products(): void {
		$where = $this->filter->filter_comment_feed_where( "WHERE comment_approved = '1' AND wp_posts.post_status = 'publish'" );

		$this->assertSame( "WHERE comment_approved = '1' AND wp_posts.post_status = 'publish' AND wp_comments.comment_post_ID NOT IN (100)", $where );
	}

	public function test_comments_feed_is_unchanged_when_nothing_is_hidden(): void {
		$GLOBALS['dpv_test_can'] = null;

		$this->assertSame( "WHERE comment_approved = '1'", $this->filter->filter_comment_feed_where( "WHERE comment_approved = '1'" ) );
	}

	// --- C4: add-to-cart validation arguments -----------------------------------------------

	public function test_add_to_cart_validation_tolerates_a_non_numeric_grouped_item_key(): void {
		// The grouped add-to-cart form passes each quantity[] key as the ID.
		$this->assertTrue( $this->filter->validate_add_to_cart( true, 'abc', 1 ) );
		$this->assertSame( array(), $GLOBALS['dpv_test_notices'] );
	}

	public function test_add_to_cart_validation_still_refuses_hidden_ids_given_as_strings(): void {
		$this->assertFalse( $this->filter->validate_add_to_cart( true, (string) self::HIDDEN, '1' ) );
		$this->assertFalse( $this->filter->validate_add_to_cart( true, (string) self::VISIBLE, '1', (string) self::HIDDEN_VARIATION ) );
		$this->assertTrue( $this->filter->validate_add_to_cart( true, (string) self::VISIBLE, '2' ) );
	}

	public function test_add_to_cart_validation_passes_an_earlier_verdict_through_unchanged(): void {
		$this->assertFalse( $this->filter->validate_add_to_cart( false, self::VISIBLE, 1 ) );
		$this->assertNull( $this->filter->validate_add_to_cart( null, self::VISIBLE, 1 ) );
		$this->assertSame( 1, $this->filter->validate_add_to_cart( 1, 0, 1 ) );
	}

	// --- C1: WooCommerce widget HTML cache ----------------------------------------------------

	private function sidebar_params( string $widget_id ): array {
		return array(
			array(
				'name'      => 'Sidebar',
				'widget_id' => $widget_id,
			),
			array( 'number' => 2 ),
		);
	}

	public function test_woocommerce_widgets_skip_their_shared_cache_for_a_visitor_with_hidden_products(): void {
		$GLOBALS['wp_registered_widgets']['woocommerce_products-2'] = array( 'callback' => array( new \WC_Widget(), 'display_callback' ) );

		$params = $this->filter->filter_dynamic_sidebar_params( $this->sidebar_params( 'woocommerce_products-2' ) );

		$this->assertSame( '', $params[0]['widget_id'] );
		$this->assertSame( 'Sidebar', $params[0]['name'] );
		$this->assertSame( array( 'number' => 2 ), $params[1] );
	}

	public function test_other_widgets_and_unrestricted_visitors_keep_the_widget_id(): void {
		$GLOBALS['wp_registered_widgets']['text-3']                 = array( 'callback' => array( new \stdClass(), 'display_callback' ) );
		$GLOBALS['wp_registered_widgets']['woocommerce_products-2'] = array( 'callback' => array( new \WC_Widget(), 'display_callback' ) );

		$this->assertSame( 'text-3', $this->filter->filter_dynamic_sidebar_params( $this->sidebar_params( 'text-3' ) )[0]['widget_id'] );
		$this->assertSame( 'gone-1', $this->filter->filter_dynamic_sidebar_params( $this->sidebar_params( 'gone-1' ) )[0]['widget_id'] );

		$GLOBALS['dpv_test_can'] = null;
		$this->assertSame( 'woocommerce_products-2', $this->filter->filter_dynamic_sidebar_params( $this->sidebar_params( 'woocommerce_products-2' ) )[0]['widget_id'] );
	}

	// --- C3: legacy product grid block cache ---------------------------------------------------

	public function test_product_grid_cache_is_skipped_for_a_visitor_with_hidden_products(): void {
		$this->assertFalse( $this->filter->filter_product_grid_is_cacheable( true ) );
	}

	public function test_product_grid_cache_is_kept_when_nothing_is_hidden(): void {
		$GLOBALS['dpv_test_can'] = null;

		$this->assertTrue( $this->filter->filter_product_grid_is_cacheable( true ) );
	}

	// --- hooks ---------------------------------------------------------------------------------

	public function test_every_new_route_is_hooked(): void {
		$GLOBALS['dpv_test_actions'] = array();
		$init                        = new \ReflectionMethod( Visibility_Filter::class, 'init_hooks' );
		$init->setAccessible( true );
		$init->invoke( $this->filter );

		$hooked = array();
		foreach ( $GLOBALS['dpv_test_actions'] as $action ) {
			$hooked[ $action[0] ] = $action[3];
		}

		$this->assertSame( 2, $hooked['redirect_canonical'] ?? null );
		$this->assertSame( 2, $hooked['woocommerce_cart_redirect_after_error'] ?? null );
		$this->assertArrayHasKey( 'pre_comment_on_post', $hooked );
		$this->assertArrayHasKey( 'comment_feed_where', $hooked );
		$this->assertArrayHasKey( 'dynamic_sidebar_params', $hooked );
		$this->assertArrayHasKey( 'woocommerce_blocks_product_grid_is_cacheable', $hooked );

		$checkout = array_values(
			array_filter(
				$GLOBALS['dpv_test_actions'],
				static function ( $action ) {
					return 'template_redirect' === $action[0] && is_array( $action[1] ) && 'refuse_restricted_checkout_link' === $action[1][1];
				}
			)
		);
		$this->assertCount( 1, $checkout );
		$this->assertLessThan( 10, $checkout[0][2], 'it must run before WooCommerce handles the link at priority 10' );
	}
}
