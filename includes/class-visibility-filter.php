<?php
/**
 * Visibility Filter Class - Handles all product visibility filtering
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visibility_Filter Class
 */
class Visibility_Filter {

	/**
	 * Single instance
	 *
	 * @var Visibility_Filter|null
	 */
	private static ?Visibility_Filter $instance = null;

	/**
	 * Cache of restricted product IDs for current user
	 *
	 * @var array|null
	 */
	private ?array $restricted_products_cache = null;

	/**
	 * Cache of allowed product IDs for current user
	 *
	 * @var array|null
	 */
	private ?array $allowed_products_cache = null;

	/**
	 * Per-request cache of product IDs the current user is explicitly listed for.
	 *
	 * @var array|null
	 */
	private ?array $user_customer_products = null;

	/**
	 * Per-request cache of product IDs the current user is denied by a bulk
	 * (category/tag) rule.
	 *
	 * @var array|null
	 */
	private ?array $bulk_denied_products = null;

	/**
	 * True while resolving the bulk-rule product set, so the internal term query
	 * does not recurse back through this class's own query filters.
	 *
	 * @var bool
	 */
	private bool $resolving = false;

	/**
	 * Get instance
	 */
	public static function instance(): Visibility_Filter {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		// Don't apply filters for admins/shop managers
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks(): void {
		// Filter main product queries (shop, archives, search)
		add_action( 'woocommerce_product_query', array( $this, 'filter_product_query' ), 10, 2 );

		// Filter single product page access. Runs ahead of redirect_canonical
		// (priority 10), which could otherwise send the request elsewhere first.
		add_action( 'template_redirect', array( $this, 'check_single_product_access' ), 5 );

		// Filter product visibility checks
		add_filter( 'woocommerce_product_is_visible', array( $this, 'filter_product_visibility' ), 10, 2 );

		// Filter related products
		add_filter( 'woocommerce_related_products', array( $this, 'filter_related_products' ), 10, 3 );

		// Cross-sells and up-sells. WC_Data::get_prop() fires {prefix}{prop}, and
		// a variation's prefix is woocommerce_product_variation_get_. These also
		// feed the Store API _links.cross_sells / _links.upsells.
		foreach ( array( 'woocommerce_product_get_', 'woocommerce_product_variation_get_' ) as $prefix ) {
			add_filter( $prefix . 'cross_sell_ids', array( $this, 'filter_product_ids' ), 10, 2 );
			add_filter( $prefix . 'upsell_ids', array( $this, 'filter_product_ids' ), 10, 2 );
		}

		// A grouped product's children (the grouped add-to-cart table, the
		// grouped-product blocks and the Store API grouped_products field).
		add_filter( 'woocommerce_product_get_children', array( $this, 'filter_product_children' ), 10, 2 );

		// Filter search results
		add_filter( 'posts_where', array( $this, 'filter_search_where' ), 10, 2 );

		// Filter product listings that bypass woocommerce_product_query: the WC
		// Store API (/wp-json/wc/store/v1/products) and the core product sitemap
		// both run their own WP_Query, so hook pre_get_posts for product queries.
		add_action( 'pre_get_posts', array( $this, 'filter_product_pre_get_posts' ) );

		// Block direct REST fetches of a single restricted product (Store API
		// products/{id} and products/{slug} under wc/store and wc/store/v1, and
		// core wp/v2/product/{id}), which resolve the product directly and never
		// run a filtered query, and Store API cart and shopper-list writes, whose
		// own validation names the product. Batch sub-requests pass through here too.
		add_filter( 'rest_request_before_callbacks', array( $this, 'filter_rest_single_product' ), 10, 3 );

		// The [products] shortcode family caches its result IDs per query args
		// for 30 days, so the exclusions must be part of those args.
		add_filter( 'woocommerce_shortcode_products_query', array( $this, 'filter_shortcode_products_query' ), 10, 1 );

		// Variation data for a restricted product (?wc-ajax=get_variation and the
		// variation form data).
		add_filter( 'woocommerce_available_variation', array( $this, 'filter_available_variation' ), 10, 2 );

		// Reviews of restricted products (Store API products/reviews, core
		// wp/v2/comments, review widgets).
		add_action( 'pre_get_comments', array( $this, 'filter_comment_query' ) );

		// oEmbed of a restricted product URL (/wp-json/oembed/1.0/embed).
		add_filter( 'oembed_request_post_id', array( $this, 'filter_oembed_post_id' ), 10, 1 );

		// Filter WooCommerce blocks
		add_filter( 'woocommerce_blocks_product_grid_item_html', array( $this, 'filter_block_product' ), 10, 3 );

		// Blocks that render one product named by ID (Single Product, Featured
		// Product) read it directly. pre_render_block also runs for inner
		// blocks, and short-circuits before the block's own inner blocks render.
		add_filter( 'pre_render_block', array( $this, 'filter_pre_render_block' ), 10, 2 );

		// Shortcodes that render one product named by id or sku without a
		// filtered query ([add_to_cart], [add_to_cart_url], [product_page]).
		add_filter( 'pre_do_shortcode_tag', array( $this, 'filter_pre_do_shortcode_tag' ), 10, 3 );

		// Media attached to a restricted product (/wp/v2/media, ?parent=).
		add_filter( 'rest_attachment_query', array( $this, 'filter_rest_attachment_query' ), 10, 2 );

		// Add to cart validation
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 4 );

		// A canonical redirect (?p=, ?attachment_id=, the 404 slug guess) would
		// send the visitor to the address of a product they may not see.
		add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ), 10, 2 );

		// WooCommerce's /checkout-link/?products= handler (template_redirect,
		// priority 10) adds each product through the Store API cart controller,
		// whose errors name the product, and passes them on in the URL.
		add_action( 'template_redirect', array( $this, 'refuse_restricted_checkout_link' ), 5 );

		// ?wc-ajax=add_to_cart answers a refused add with the product's address.
		add_filter( 'woocommerce_cart_redirect_after_error', array( $this, 'filter_cart_redirect_after_error' ), 10, 2 );

		// A comment posted to a restricted product, and the site comments feed,
		// which reads comments with its own query rather than WP_Comment_Query.
		add_action( 'pre_comment_on_post', array( $this, 'refuse_comment_on_restricted_product' ) );
		add_filter( 'comment_feed_where', array( $this, 'filter_comment_feed_where' ) );

		// WooCommerce's widgets and legacy product grid blocks cache their output
		// for every visitor alike; a visitor with hidden products bypasses them.
		add_filter( 'dynamic_sidebar_params', array( $this, 'filter_dynamic_sidebar_params' ) );
		add_filter( 'woocommerce_blocks_product_grid_is_cacheable', array( $this, 'filter_product_grid_is_cacheable' ) );

		// Validate cart items on cart/checkout pages
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_items' ) );
	}

	/**
	 * Filter main WooCommerce product query
	 *
	 * @param \WP_Query $query Query object
	 * @param \WC_Query $wc_query WooCommerce query object
	 */
	public function filter_product_query( \WP_Query $query, $wc_query ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature of the woocommerce_product_query action (2 args).
		if ( self::in_admin_screen() ) {
			return;
		}

		$restricted_ids = $this->get_restricted_product_ids();

		if ( ! empty( $restricted_ids ) ) {
			$query->set(
				'post__not_in',
				array_merge(
					(array) $query->get( 'post__not_in' ),
					$restricted_ids
				)
			);
		}
	}

	/**
	 * Check access to single product page
	 */
	public function check_single_product_access(): void {
		if ( is_attachment() ) {
			$this->check_attachment_access();
			return;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		global $post;

		if ( ! $post || ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		if ( ! $this->user_can_view_product( $post->ID ) ) {
			// Redirect to shop page
			$redirect_url = apply_filters( 'dragonproductvisibility_restricted_redirect_url', wc_get_page_permalink( 'shop' ) );

			// Show notice before redirect
			$this->add_restricted_notice();

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Turn the attachment page of an image attached to a restricted product
	 * into a 404.
	 *
	 * Runs ahead of redirect_canonical, which would otherwise send the visitor
	 * on to the file when attachment pages are disabled.
	 */
	private function check_attachment_access(): void {
		global $post, $wp_query;

		if ( ! is_object( $post ) || ! isset( $post->ID ) || ! $this->attachment_is_restricted( (int) $post->ID ) ) {
			return;
		}

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Whether an attachment belongs to a product the current user may not see.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function attachment_is_restricted( int $attachment_id ): bool {
		$parent_id = $this->attachment_product_id( $attachment_id );

		return $parent_id > 0 && ! $this->user_can_view_product( $parent_id );
	}

	/**
	 * The product (or variation) an attachment is attached to.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int 0 when it is not an attachment of a product.
	 */
	private function attachment_product_id( int $attachment_id ): int {
		$attachment = $attachment_id ? get_post( $attachment_id ) : null;
		if ( ! $attachment || 'attachment' !== $attachment->post_type || (int) $attachment->post_parent <= 0 ) {
			return 0;
		}

		$parent = get_post( (int) $attachment->post_parent );
		if ( ! $parent || ! in_array( $parent->post_type, array( 'product', 'product_variation' ), true ) ) {
			return 0;
		}

		return (int) $parent->ID;
	}

	/**
	 * Queue the "no access" notice shown on the page the visitor is sent to.
	 *
	 * WooCommerce stores notices in the session and only saves a session for a
	 * visitor with a session cookie, so a first-time guest (the usual case for a
	 * whitelisted product) would lose the notice across the redirect. The
	 * cookie is started first; the session is saved on shutdown.
	 */
	private function add_restricted_notice(): void {
		if ( ! function_exists( 'wc_add_notice' ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		wc_add_notice(
			apply_filters(
				'dragonproductvisibility_restricted_product_message',
				__( 'Sorry, you do not have access to view this product.', 'dragon-product-visibility' )
			),
			'error'
		);
	}

	/**
	 * Filter product visibility
	 *
	 * @param bool $visible Whether product is visible
	 * @param int  $product_id Product ID
	 */
	public function filter_product_visibility( bool $visible, int $product_id ): bool {
		if ( ! $visible ) {
			return $visible;
		}

		return $this->user_can_view_product( $product_id );
	}

	/**
	 * Filter related products
	 *
	 * @param array $related_posts Related product IDs
	 * @param int   $product_id Current product ID
	 * @param array $args Query args
	 */
	public function filter_related_products( array $related_posts, int $product_id, array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature of the woocommerce_related_products filter (3 args).
		if ( empty( $related_posts ) ) {
			return $related_posts;
		}

		$restricted_ids = $this->get_restricted_product_ids();

		if ( ! empty( $restricted_ids ) ) {
			$related_posts = array_diff( $related_posts, $restricted_ids );
		}

		return $related_posts;
	}

	/**
	 * Filter array of product IDs (cross-sells, up-sells)
	 *
	 * @param array       $product_ids Product IDs
	 * @param \WC_Product $product Product object
	 */
	public function filter_product_ids( array $product_ids, \WC_Product $product ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature of the woocommerce_product_get_{crosssell,upsell}_ids filters (2 args).
		if ( empty( $product_ids ) ) {
			return $product_ids;
		}

		$restricted_ids = $this->get_restricted_product_ids();

		if ( ! empty( $restricted_ids ) ) {
			$product_ids = array_diff( $product_ids, $restricted_ids );
		}

		return array_values( $product_ids );
	}

	/**
	 * Remove restricted products from a grouped product's children.
	 *
	 * Only the view context reaches this filter, so WooCommerce's own price
	 * sync (which reads the children in edit context) is unaffected.
	 *
	 * @param mixed $children Child product IDs.
	 * @param mixed $product  Grouped product.
	 * @return mixed
	 */
	public function filter_product_children( $children, $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature of the woocommerce_product_get_children filter (2 args).
		if ( self::in_admin_screen() || $this->resolving || ! is_array( $children ) || empty( $children ) ) {
			return $children;
		}

		$restricted_ids = $this->get_restricted_product_ids();
		if ( empty( $restricted_ids ) ) {
			return $children;
		}

		return array_values( array_diff( array_map( 'intval', $children ), $restricted_ids ) );
	}

	/**
	 * Filter search results
	 *
	 * @param string    $where WHERE clause
	 * @param \WP_Query $query Query object
	 */
	public function filter_search_where( string $where, \WP_Query $query ): string {
		if ( self::in_admin_screen() || $this->resolving || ! $query->is_search() ) {
			return $where;
		}

		// Cover generic searches, not just post_type=product. A plain ?s= search
		// has no post_type (so it searches all types, products included), and a
		// multi-type search passes an array. Only bail when products are
		// definitely out of scope.
		$types = array_filter( (array) $query->get( 'post_type' ) );
		if ( ! empty( $types ) && ! in_array( 'any', $types, true ) && ! in_array( 'product', $types, true ) ) {
			return $where;
		}

		global $wpdb;

		$restricted_ids = $this->get_restricted_product_ids();

		if ( ! empty( $restricted_ids ) ) {
			$ids_string = implode( ',', array_map( 'intval', $restricted_ids ) );
			$where     .= " AND {$wpdb->posts}.ID NOT IN ({$ids_string})";
		}

		return $where;
	}

	/**
	 * Exclude restricted products, and the variations of restricted products,
	 * from any front-end product query.
	 *
	 * woocommerce_product_query only fires for the classic shop/archive query,
	 * so listings that build their own WP_Query - the Store API products
	 * collection and the core product sitemap - bypass it. This catches every
	 * query that explicitly targets the product or product_variation post type
	 * (the Store API lists variations for ?type=variation, ?sku= and ?slug=).
	 *
	 * Two queries are deliberately left alone:
	 * - the main query of a single product URL, so the request reaches
	 *   check_single_product_access() and is redirected with a notice rather
	 *   than turned into a plain 404;
	 * - a variation query scoped to one parent (post_parent), which is how
	 *   WooCommerce reads a product's children. It caches that result for every
	 *   visitor, so filtering it for one shopper would empty the product for all.
	 *
	 * @param \WP_Query $query Query object.
	 */
	public function filter_product_pre_get_posts( \WP_Query $query ): void {
		if ( self::in_admin_screen() || $this->resolving ) {
			return;
		}

		$types         = array_filter( (array) $query->get( 'post_type' ) );
		$has_products  = in_array( 'product', $types, true );
		$has_variation = in_array( 'product_variation', $types, true );

		if ( ! $has_products && ! $has_variation ) {
			return;
		}

		if ( $query->is_main_query() && $query->is_singular() ) {
			return;
		}

		if ( $has_variation && ! $has_products && '' !== (string) $query->get( 'post_parent' ) ) {
			return;
		}

		$restricted_ids = $this->get_restricted_product_ids();

		if ( empty( $restricted_ids ) ) {
			return;
		}

		foreach ( $this->restrict_query_vars( $query->query_vars, $has_products, $has_variation, $restricted_ids ) as $key => $value ) {
			$query->set( $key, $value );
		}
	}

	/**
	 * The query vars that exclude restricted products (and their variations).
	 *
	 * WP_Query ignores post__not_in whenever p or post__in is set, and
	 * post_parent__not_in whenever post_parent__in is set, so an explicit ID
	 * list (Store API ?include= / ?parent= / ?on_sale=, wp/v2 ?include=, the
	 * [products ids=""] shortcode) is filtered directly as well.
	 *
	 * @param array $vars           Current query vars.
	 * @param bool  $has_products   Whether the query targets products.
	 * @param bool  $has_variation  Whether the query targets variations.
	 * @param int[] $restricted_ids Restricted product IDs.
	 * @return array Changed vars only.
	 */
	private function restrict_query_vars( array $vars, bool $has_products, bool $has_variation, array $restricted_ids ): array {
		$changes = array();

		if ( $has_products ) {
			$changes['post__not_in'] = self::merge_ids( $vars['post__not_in'] ?? array(), $restricted_ids );
		}

		// Products never have a parent, so this only drops variations.
		if ( $has_variation ) {
			$changes['post_parent__not_in'] = self::merge_ids( $vars['post_parent__not_in'] ?? array(), $restricted_ids );
		}

		$single = absint( $vars['p'] ?? 0 );
		if ( $single > 0 && ! $this->user_can_view_product( $single ) ) {
			// p=0 alone would widen the query to everything, so pin it to nothing.
			$changes['p']        = 0;
			$changes['post__in'] = array( 0 );
			return $changes;
		}

		if ( $has_products && ! empty( $vars['post__in'] ) ) {
			$changes['post__in'] = self::without_ids( $vars['post__in'], $restricted_ids );
		}

		if ( $has_variation && ! empty( $vars['post_parent__in'] ) ) {
			$changes['post_parent__in'] = self::without_ids( $vars['post_parent__in'], $restricted_ids );
		}

		return $changes;
	}

	/**
	 * Remove IDs from an explicit ID list, keeping it non-empty.
	 *
	 * An emptied list would mean "no constraint" to WP_Query, so it becomes
	 * array( 0 ), which matches nothing.
	 *
	 * @param mixed $existing Current ID list.
	 * @param int[] $ids      IDs to remove.
	 * @return int[]
	 */
	private static function without_ids( $existing, array $ids ): array {
		$kept = array_values( array_diff( wp_parse_id_list( $existing ), $ids ) );

		return empty( $kept ) ? array( 0 ) : $kept;
	}

	/**
	 * Apply the exclusions to the [products] shortcode family's query args.
	 *
	 * WC_Shortcode_Products caches result IDs in a transient named after these
	 * args, so excluding here gives each distinct set of restrictions its own
	 * cache entry instead of serving one visitor's list to another.
	 *
	 * @param array $query_args Shortcode query args.
	 * @return array
	 */
	public function filter_shortcode_products_query( $query_args ) {
		if ( ! is_array( $query_args ) || $this->resolving ) {
			return $query_args;
		}

		$restricted_ids = $this->get_restricted_product_ids();
		if ( empty( $restricted_ids ) ) {
			return $query_args;
		}

		return array_merge( $query_args, $this->restrict_query_vars( $query_args, true, false, $restricted_ids ) );
	}

	/**
	 * Blocks that render one product named by their productId attribute.
	 *
	 * The atomic product blocks (price, title, image, button...) carry a
	 * productId attribute too, but render from the postId context, which only
	 * a Single Product block or a (filtered) product query supplies.
	 */
	private const PRODUCT_ID_BLOCKS = array( 'woocommerce/single-product', 'woocommerce/featured-product' );

	/**
	 * Render nothing for a block that names a restricted product.
	 *
	 * @param string|null $pre          Output from an earlier filter, or null.
	 * @param mixed       $parsed_block Parsed block.
	 * @return string|null
	 */
	public function filter_pre_render_block( $pre, $parsed_block ) {
		if ( null !== $pre || ! is_array( $parsed_block ) ) {
			return $pre;
		}

		if ( ! in_array( $parsed_block['blockName'] ?? '', self::PRODUCT_ID_BLOCKS, true ) ) {
			return $pre;
		}

		$attrs      = is_array( $parsed_block['attrs'] ?? null ) ? $parsed_block['attrs'] : array();
		$product_id = isset( $attrs['productId'] ) && is_scalar( $attrs['productId'] ) ? absint( $attrs['productId'] ) : 0;

		if ( $product_id > 0 && ! $this->user_can_view_product( $product_id ) ) {
			return '';
		}

		return $pre;
	}

	/**
	 * WooCommerce shortcode callbacks that render one product named by id or
	 * sku, keyed by callback so a renamed tag ({$shortcode}_shortcode_tag) is
	 * still recognised.
	 */
	private const PRODUCT_SHORTCODES = array(
		'WC_Shortcodes::product_add_to_cart'     => 'add_to_cart',
		'WC_Shortcodes::product_add_to_cart_url' => 'add_to_cart_url',
		'WC_Shortcodes::product_page'            => 'product_page',
	);

	/**
	 * Render nothing for a product shortcode that names a restricted product.
	 *
	 * [product] and [products] run a filtered query (see
	 * filter_shortcode_products_query()), so they are not listed here.
	 *
	 * @param false|string $output Output from an earlier filter, or false.
	 * @param string       $tag    Shortcode tag.
	 * @param array|string $attr   Shortcode attributes ('' when there are none).
	 * @return false|string
	 */
	public function filter_pre_do_shortcode_tag( $output, $tag, $attr ) {
		if ( false !== $output || ! is_array( $attr ) ) {
			return $output;
		}

		global $shortcode_tags;

		$callback = is_array( $shortcode_tags ) ? ( $shortcode_tags[ $tag ] ?? null ) : null;
		if ( is_array( $callback ) && 2 === count( $callback ) && is_string( $callback[0] ?? null ) && is_string( $callback[1] ?? null ) ) {
			$callback = $callback[0] . '::' . $callback[1];
		}
		if ( ! is_string( $callback ) || ! isset( self::PRODUCT_SHORTCODES[ ltrim( $callback, '\\' ) ] ) ) {
			return $output;
		}

		$product_id = $this->shortcode_product_id( self::PRODUCT_SHORTCODES[ ltrim( $callback, '\\' ) ], $attr );

		if ( $product_id > 0 && ! $this->user_can_view_product( $product_id ) ) {
			return '';
		}

		return $output;
	}

	/**
	 * The product a WooCommerce product shortcode would render, resolved with
	 * that shortcode's own id-over-sku precedence.
	 *
	 * @param string $shortcode add_to_cart, add_to_cart_url or product_page.
	 * @param array  $attr      Shortcode attributes.
	 * @return int 0 when nothing is named.
	 */
	private function shortcode_product_id( string $shortcode, array $attr ): int {
		$id  = $attr['id'] ?? null;
		$sku = $attr['sku'] ?? null;

		if ( 'add_to_cart' === $shortcode ) {
			// shortcode_atts() defaults both to '', then ! empty() decides.
			if ( ! empty( $id ) ) {
				return is_scalar( $id ) ? absint( $id ) : 0;
			}
			return empty( $sku ) ? 0 : $this->product_id_for_sku( $sku );
		}

		if ( 'add_to_cart_url' === $shortcode ) {
			// isset( id ) wins even when it is empty.
			if ( isset( $id ) ) {
				return is_scalar( $id ) ? absint( $id ) : 0;
			}
			return isset( $sku ) ? $this->product_id_for_sku( $sku ) : 0;
		}

		// product_page: absint( id ), falling back to the sku when that is 0.
		$product_id = ( isset( $id ) && is_scalar( $id ) ) ? absint( $id ) : 0;
		if ( ! $product_id && isset( $sku ) ) {
			$product_id = $this->product_id_for_sku( $sku );
		}

		return $product_id;
	}

	/**
	 * The product or variation ID for a SKU.
	 *
	 * @param mixed $sku SKU attribute.
	 * @return int
	 */
	private function product_id_for_sku( $sku ): int {
		if ( ! is_scalar( $sku ) || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return 0;
		}

		return absint( wc_get_product_id_by_sku( (string) $sku ) );
	}

	/**
	 * Leave media attached to restricted products out of /wp/v2/media.
	 *
	 * WP_Query ignores post_parent__not_in whenever post_parent__in is set
	 * (?parent=), so an explicit parent list is filtered directly. An emptied
	 * parent list cannot become array( 0 ), which means unattached media, so
	 * the query is pinned to nothing with post__in instead.
	 *
	 * The edit context is left alone: core only serves it to users who can
	 * edit the media, and the editor's media library relies on it.
	 *
	 * @param mixed $args    WP_Query args.
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function filter_rest_attachment_query( $args, $request ) {
		if ( ! is_array( $args ) || $this->resolving ) {
			return $args;
		}

		if ( $request instanceof \WP_REST_Request && 'edit' === $request->get_param( 'context' ) ) {
			return $args;
		}

		$restricted_ids = $this->get_restricted_product_ids();
		if ( empty( $restricted_ids ) ) {
			return $args;
		}

		if ( ! empty( $args['post_parent__in'] ) ) {
			$kept = array_values( array_diff( wp_parse_id_list( $args['post_parent__in'] ), $restricted_ids ) );

			if ( empty( $kept ) ) {
				$args['post__in'] = array( 0 );
			} else {
				$args['post_parent__in'] = $kept;
			}

			return $args;
		}

		$args['post_parent__not_in'] = self::merge_ids( $args['post_parent__not_in'] ?? array(), $restricted_ids );

		return $args;
	}

	/**
	 * Withhold variation data for a restricted product.
	 *
	 * WooCommerce array_filter()s get_available_variations(), and
	 * ?wc-ajax=get_variation sends false as "no matching variation".
	 *
	 * @param mixed $data    Variation data.
	 * @param mixed $product Parent product.
	 * @return mixed
	 */
	public function filter_available_variation( $data, $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) && ! $this->user_can_view_product( (int) $product->get_id() ) ) {
			return false;
		}

		return $data;
	}

	/**
	 * Exclude reviews of restricted products from front-end comment queries.
	 *
	 * WP_Comment_Query applies post__not_in alongside post__in and post_id, so
	 * a query naming a restricted product returns nothing.
	 *
	 * @param object $query WP_Comment_Query.
	 */
	public function filter_comment_query( $query ): void {
		if ( self::in_admin_screen() || $this->resolving || ! is_object( $query ) || ! isset( $query->query_vars ) || ! is_array( $query->query_vars ) ) {
			return;
		}

		$restricted_ids = $this->get_restricted_product_ids();
		if ( empty( $restricted_ids ) ) {
			return;
		}

		$query->query_vars['post__not_in'] = self::merge_ids( $query->query_vars['post__not_in'] ?? array(), $restricted_ids );
	}

	/**
	 * Refuse an oEmbed of a restricted product.
	 *
	 * @param int $post_id Post ID resolved from the embed URL.
	 * @return int 0 (not found) for a restricted product.
	 */
	public function filter_oembed_post_id( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return $post_id;
		}

		$post = get_post( $post_id );
		if ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) && ! $this->user_can_view_product( $post_id ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Whether this is a wp-admin screen, where the filters stand aside.
	 *
	 * is_admin() is also true for every admin-ajax.php request, including a
	 * guest's front-end live search, quick view or load-more, so those are
	 * filtered like any other front-end request.
	 *
	 * @return bool
	 */
	private static function in_admin_screen(): bool {
		return is_admin() && ! wp_doing_ajax();
	}

	/**
	 * Add IDs to an existing ID list query var without losing what is there.
	 *
	 * @param mixed $existing Current query var value.
	 * @param int[] $ids      IDs to add.
	 * @return int[]
	 */
	private static function merge_ids( $existing, array $ids ): array {
		$merged = wp_parse_id_list( $existing );

		return array_values( array_unique( array_merge( $merged, $ids ) ) );
	}

	/**
	 * Block direct REST fetches of a single restricted product.
	 *
	 * The Store API products/{id} and products/{slug} routes (registered under
	 * both wc/store and wc/store/v1) and core's wp/v2/product/{id} resolve the
	 * product directly (no filtered query), so a restricted product would be
	 * returned in full to a guest. Deny it with a 404 before the route callback
	 * runs. List/search routes are handled by the query filters above.
	 *
	 * The route is recognised by the matched handler's controller, falling back
	 * to a case-insensitive path match (core matches routes with the i flag).
	 * The ID/slug is read with get_param(), exactly as the route callback reads
	 * it: a query-string ?id= or ?slug= outranks the URL segment.
	 *
	 * @param mixed            $response Result to send (WP_Error short-circuits).
	 * @param array            $handler  Matched route handler.
	 * @param \WP_REST_Request $request  Current request.
	 * @return mixed
	 */
	public function filter_rest_single_product( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		$handler = is_array( $handler ) ? $handler : array();

		$product_ids = array_merge(
			array( $this->rest_requested_product_id( $handler, $request ) ),
			$this->rest_written_product_ids( $handler, $request )
		);

		foreach ( $product_ids as $product_id ) {
			if ( $product_id > 0 && ! $this->user_can_view_product( $product_id ) ) {
				return new \WP_Error(
					'woocommerce_rest_product_invalid_id',
					__( 'Sorry, you do not have access to view this product.', 'dragon-product-visibility' ),
					array( 'status' => 404 )
				);
			}
		}

		$media_id = $this->rest_requested_media_id( $handler, $request );

		if ( $media_id > 0 && $this->attachment_is_restricted( $media_id ) ) {
			return new \WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid post ID.', 'dragon-product-visibility' ),
				array( 'status' => 404 )
			);
		}

		$comment_product_id = $this->rest_requested_comment_product_id( $handler, $request );

		if ( $comment_product_id > 0 && ! $this->user_can_view_product( $comment_product_id ) ) {
			return new \WP_Error(
				'rest_comment_invalid_id',
				__( 'Invalid comment ID.', 'dragon-product-visibility' ),
				array( 'status' => 404 )
			);
		}

		return $response;
	}

	/**
	 * The controller object behind a matched REST handler.
	 *
	 * @param array $handler Matched route handler.
	 * @return object|null
	 */
	private static function rest_controller( array $handler ) {
		$callback = $handler['callback'] ?? null;

		return ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ) ? $callback[0] : null;
	}

	/**
	 * Whether a REST request only reads.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return bool
	 */
	private static function rest_is_read( \WP_REST_Request $request ): bool {
		return in_array( strtoupper( (string) $request->get_method() ), array( 'GET', 'HEAD' ), true );
	}

	/**
	 * The attachment a single-media read (/wp/v2/media/{id}) would serve.
	 *
	 * The edit context is left alone, as in filter_rest_attachment_query().
	 *
	 * @param array             $handler Matched route handler.
	 * @param \WP_REST_Request $request Current request.
	 * @return int 0 when the request is not a single-media read.
	 */
	private function rest_requested_media_id( array $handler, \WP_REST_Request $request ): int {
		$is_media = self::rest_controller( $handler ) instanceof \WP_REST_Attachments_Controller
			|| preg_match( '#^/wp/v2/media/\d+/?$#i', (string) $request->get_route() );

		if ( ! $is_media || ! self::rest_is_read( $request ) || 'edit' === $request->get_param( 'context' ) ) {
			return 0;
		}

		return absint( $request->get_param( 'id' ) );
	}

	/**
	 * The product whose review a single-comment read (/wp/v2/comments/{id})
	 * would serve. That route reads the comment directly, so the
	 * pre_get_comments exclusion never sees it.
	 *
	 * @param array             $handler Matched route handler.
	 * @param \WP_REST_Request $request Current request.
	 * @return int 0 when the request is not a single read of a product review.
	 */
	private function rest_requested_comment_product_id( array $handler, \WP_REST_Request $request ): int {
		$is_comment = self::rest_controller( $handler ) instanceof \WP_REST_Comments_Controller
			|| preg_match( '#^/wp/v2/comments/\d+/?$#i', (string) $request->get_route() );

		if ( ! $is_comment || ! self::rest_is_read( $request ) ) {
			return 0;
		}

		$comment_id = absint( $request->get_param( 'id' ) );
		$comment    = $comment_id ? get_comment( $comment_id ) : null;
		if ( ! is_object( $comment ) || empty( $comment->comment_post_ID ) ) {
			return 0;
		}

		$post_id = absint( $comment->comment_post_ID );
		$post    = get_post( $post_id );

		return ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) ? $post_id : 0;
	}

	/**
	 * The product or variation a single-item REST request would serve.
	 *
	 * @param array            $handler Matched route handler.
	 * @param \WP_REST_Request $request Current request.
	 * @return int 0 when the request is not a single-product read.
	 */
	private function rest_requested_product_id( array $handler, \WP_REST_Request $request ): int {
		$callback   = $handler['callback'] ?? null;
		$controller = ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ) ? $callback[0] : null;
		$route      = (string) $request->get_route();

		// Store API sibling routes that also sit under /products/. They must never
		// be treated as a product slug, or a product whose slug collides with one
		// of these names would 404 the sibling route.
		$reserved_segments = array( 'attributes', 'categories', 'brands', 'tags', 'reviews', 'collection-data' );

		// Store API route classes are matched by basename (they lived under the
		// WooCommerce Blocks namespace before WooCommerce 6.5), and the path is
		// always checked too, so an unrecognised or proxying controller is still
		// judged by the route it answers.
		$class = $controller ? get_class( $controller ) : '';
		$base  = substr( (string) strrchr( '\\' . $class, '\\' ), 1 );

		$store_by_id   = 'ProductsById' === $base || preg_match( '#^/wc/store(?:/v\d+)?/products/\d+/?$#i', $route );
		$store_by_slug = false;
		$route_slug    = '';

		if ( ! $store_by_id ) {
			if ( preg_match( '#^/wc/store(?:/v\d+)?/products/(\S+)$#i', $route, $matches ) ) {
				$first         = strtolower( (string) strtok( $matches[1], '/' ) );
				$store_by_slug = ! in_array( $first, $reserved_segments, true );
				$route_slug    = rawurldecode( $matches[1] );
			}
			$store_by_slug = $store_by_slug || 'ProductsBySlug' === $base;
		}

		if ( $store_by_id ) {
			// products/{id} - a product or a variation ID.
			return absint( $request->get_param( 'id' ) );
		}

		if ( $store_by_slug ) {
			// products/{slug} - the route sanitize_title()s the slug (so
			// "secret-/widget" is "secret-widget"); a non-existent slug is 0.
			$slug = $request->get_param( 'slug' );
			$slug = is_scalar( $slug ) ? (string) $slug : $route_slug;
			return $this->product_id_for_slug( sanitize_title( $slug ) );
		}

		// Reads only: a write needs edit_products, and refusing it would break a
		// product save by a non-manager role that holds that capability.
		$core_item = ( $controller instanceof \WP_REST_Posts_Controller || preg_match( '#^/wp/v2/(?:product|product_variation)/\d+/?$#i', $route ) )
			&& in_array( strtoupper( (string) $request->get_method() ), array( 'GET', 'HEAD' ), true );

		if ( $core_item ) {
			$id   = absint( $request->get_param( 'id' ) );
			$post = $id ? get_post( $id ) : null;
			if ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * The products a REST write would add or comment on: Store API
	 * cart/add-item and cart/items (the "id" field) and
	 * shopper-lists/{slug}/items ("product_id" and "variation_id"), and a new
	 * comment on a product (core comments, "post"). WooCommerce validates the
	 * cart adds with messages that carry the product's name, stock and
	 * attributes, before the add-to-cart filter runs, so they are judged here
	 * first.
	 *
	 * @param array            $handler Matched route handler.
	 * @param \WP_REST_Request $request Current request.
	 * @return int[] Empty when the request is not one of those writes.
	 */
	private function rest_written_product_ids( array $handler, \WP_REST_Request $request ): array {
		if ( self::rest_is_read( $request ) ) {
			return array();
		}

		$controller = self::rest_controller( $handler );
		$base       = $controller ? substr( (string) strrchr( '\\' . get_class( $controller ), '\\' ), 1 ) : '';
		$route      = (string) $request->get_route();

		if ( in_array( $base, array( 'CartAddItem', 'CartItems' ), true ) || preg_match( '#^/wc/store(?:/v\d+)?/cart/(?:add-item|items)/?$#i', $route ) ) {
			return array( self::rest_id_param( $request, 'id' ) );
		}

		if ( 'ShopperListItems' === $base || preg_match( '#^/wc/store(?:/v\d+)?/shopper-lists/[a-z0-9-]+/items/?$#i', $route ) ) {
			return array( self::rest_id_param( $request, 'product_id' ), self::rest_id_param( $request, 'variation_id' ) );
		}

		// Core's comment creation (POST /wp/v2/comments) does not pass through
		// pre_comment_on_post, and its reply links to the product.
		if ( 'POST' === strtoupper( (string) $request->get_method() )
			&& ( $controller instanceof \WP_REST_Comments_Controller || preg_match( '#^/wp/v2/comments/?$#i', $route ) ) ) {
			$post_id = self::rest_id_param( $request, 'post' );
			$post    = $post_id ? get_post( $post_id ) : null;

			return ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) ? array( $post_id ) : array();
		}

		return array();
	}

	/**
	 * An ID parameter of a REST request; 0 when it is missing or not a scalar.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @param string           $key     Parameter name.
	 * @return int
	 */
	private static function rest_id_param( \WP_REST_Request $request, string $key ): int {
		$value = $request->get_param( $key );

		return is_scalar( $value ) ? absint( $value ) : 0;
	}

	/**
	 * The ID the Store API products/{slug} route would serve for a slug.
	 *
	 * Mirrors that route: a product slug first, then a variation slug. A
	 * variation ID is returned as-is; user_can_view_product() judges it by its
	 * parent.
	 *
	 * @param string $slug Sanitised slug.
	 * @return int 0 when nothing matches.
	 */
	private function product_id_for_slug( string $slug ): int {
		if ( '' === $slug ) {
			return 0;
		}

		$product = get_page_by_path( $slug, OBJECT, 'product' );
		if ( $product ) {
			return (int) $product->ID;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same lookup the Store API route makes; there is no core API for a variation slug.
		$variation_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'product_variation' LIMIT 1",
				$slug
			)
		);

		return (int) $variation_id;
	}

	/**
	 * Filter products in WooCommerce blocks
	 *
	 * @param string      $html Product HTML
	 * @param object      $data Product data
	 * @param \WC_Product $product Product object
	 */
	public function filter_block_product( string $html, object $data, \WC_Product $product ): string {
		if ( ! $this->user_can_view_product( $product->get_id() ) ) {
			return '';
		}

		return $html;
	}

	/**
	 * Validate add to cart
	 *
	 * WooCommerce passes the parent as $product_id and the variation separately;
	 * both are checked so a caller that passes a variation either way is still
	 * refused.
	 *
	 * The grouped add-to-cart form passes each quantity[] key as the product
	 * ID, so the ID can be any string; one that is not a product ID is left to
	 * WooCommerce.
	 *
	 * @param mixed $passed       Whether validation passed.
	 * @param mixed $product_id   Product ID.
	 * @param mixed $quantity     Quantity.
	 * @param mixed $variation_id Variation ID (0 for a simple product).
	 * @return mixed
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature of the woocommerce_add_to_cart_validation filter.
		$product_id = is_scalar( $product_id ) ? absint( $product_id ) : 0;

		if ( ! $passed || 0 === $product_id ) {
			return $passed;
		}

		$variation_id = is_scalar( $variation_id ) ? absint( $variation_id ) : 0;

		if ( ! $this->user_can_view_product( $product_id ) || ( $variation_id > 0 && ! $this->user_can_view_product( $variation_id ) ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice(
					__( 'Sorry, you cannot purchase this product.', 'dragon-product-visibility' ),
					'error'
				);
			}
			return false;
		}

		return $passed;
	}

	/**
	 * Cancel a canonical redirect whose target is a product, variation or
	 * product image the current user may not see.
	 *
	 * Core redirects ?p=, ?page_id= and ?attachment_id= to the post's permalink
	 * and guesses a 404's target by slug prefix, which would hand out the
	 * address (and so the slug) of a restricted product.
	 *
	 * @param mixed  $redirect_url  Where core would redirect, or false.
	 * @param string $requested_url The requested URL.
	 * @return mixed False to cancel the redirect.
	 */
	public function filter_redirect_canonical( $redirect_url, $requested_url = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature of the redirect_canonical filter (2 args).
		// Nothing is hidden from this visitor, so there is nothing to cancel.
		if ( ! is_string( $redirect_url ) || '' === $redirect_url || empty( $this->get_restricted_product_ids() ) ) {
			return $redirect_url;
		}

		$ids = array(
			absint( get_query_var( 'p' ) ),
			absint( get_query_var( 'page_id' ) ),
			absint( get_query_var( 'attachment_id' ) ),
		);

		// url_to_postid() runs a product query, which this class would otherwise
		// strip the restricted product out of.
		$this->resolving = true;
		try {
			$ids[] = absint( url_to_postid( $redirect_url ) );
		} finally {
			$this->resolving = false;
		}

		foreach ( array_unique( array_filter( $ids ) ) as $post_id ) {
			if ( $this->post_is_restricted( (int) $post_id ) ) {
				return false;
			}
		}

		return $redirect_url;
	}

	/**
	 * Whether a post is a product, variation or product image the current user
	 * may not see.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function post_is_restricted( int $post_id ): bool {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return false;
		}

		if ( in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return ! $this->user_can_view_product( $post_id );
		}

		return 'attachment' === $post->post_type && $this->attachment_is_restricted( $post_id );
	}

	/**
	 * Refuse a WooCommerce checkout link (/checkout-link/?products=) that names
	 * a product the user may not see, before WooCommerce empties the cart and
	 * tries to add it. WooCommerce would pass the add's error, which names the
	 * product, back in the wc_error query string.
	 */
	public function refuse_restricted_checkout_link(): void {
		if ( ! get_query_var( 'checkout-link' ) || ! isset( $_GET['products'] ) || ! is_string( $_GET['products'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce's checkout link is a plain shareable URL with no nonce; this only refuses it.
			return;
		}

		$products = sanitize_text_field( wp_unslash( $_GET['products'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.

		foreach ( self::checkout_link_product_ids( $products ) as $product_id ) {
			if ( ! $this->user_can_view_product( $product_id ) ) {
				wp_safe_redirect(
					add_query_arg(
						'wc_error',
						rawurlencode( __( 'Sorry, you cannot purchase this product.', 'dragon-product-visibility' ) ),
						wc_get_cart_url()
					)
				);
				exit;
			}
		}
	}

	/**
	 * The product IDs a checkout link's products list asks WooCommerce to add,
	 * read the way WooCommerce reads it: comma-separated "id" or "id:quantity"
	 * entries, skipping any whose ID or quantity is not a positive number.
	 *
	 * @param string $products Sanitized products list.
	 * @return int[]
	 */
	public static function checkout_link_product_ids( string $products ): array {
		$ids = array();

		foreach ( array_filter( explode( ',', $products ) ) as $entry ) {
			$parts      = explode( ':', $entry );
			$product_id = absint( $parts[0] );
			$quantity   = isset( $parts[1] ) ? absint( $parts[1] ) : 1;

			if ( $product_id && $quantity ) {
				$ids[] = $product_id;
			}
		}

		return $ids;
	}

	/**
	 * Send a refused ?wc-ajax=add_to_cart to the shop instead of the product.
	 *
	 * @param mixed $url        Where WooCommerce would send the visitor.
	 * @param mixed $product_id Product ID.
	 * @return mixed
	 */
	public function filter_cart_redirect_after_error( $url, $product_id = 0 ) {
		$product_id = is_scalar( $product_id ) ? absint( $product_id ) : 0;

		if ( $product_id > 0 && ! $this->user_can_view_product( $product_id ) && function_exists( 'wc_get_page_permalink' ) ) {
			return wc_get_page_permalink( 'shop' );
		}

		return $url;
	}

	/**
	 * Refuse a comment or review posted to a product the user may not see.
	 *
	 * @param mixed $comment_post_id Post being commented on.
	 */
	public function refuse_comment_on_restricted_product( $comment_post_id ): void {
		$post_id = is_scalar( $comment_post_id ) ? absint( $comment_post_id ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) && ! $this->user_can_view_product( $post_id ) ) {
			wp_die(
				esc_html__( 'Sorry, you do not have access to view this product.', 'dragon-product-visibility' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Leave reviews of restricted products out of the site comments feed.
	 *
	 * @param mixed $where WHERE clause of the comments feed query.
	 * @return mixed
	 */
	public function filter_comment_feed_where( $where ) {
		if ( self::in_admin_screen() || $this->resolving || ! is_string( $where ) ) {
			return $where;
		}

		$restricted_ids = $this->get_restricted_product_ids();
		if ( empty( $restricted_ids ) ) {
			return $where;
		}

		global $wpdb;

		return $where . " AND {$wpdb->comments}.comment_post_ID NOT IN (" . implode( ',', array_map( 'intval', $restricted_ids ) ) . ')';
	}

	/**
	 * Keep a WooCommerce widget out of its shared output cache for a visitor
	 * with hidden products. WC_Widget reads and writes that cache only when the
	 * widget has an ID.
	 *
	 * @param mixed $params Widget display parameters.
	 * @return mixed
	 */
	public function filter_dynamic_sidebar_params( $params ) {
		if ( ! is_array( $params ) || ! isset( $params[0] ) || ! is_array( $params[0] ) || empty( $params[0]['widget_id'] ) ) {
			return $params;
		}

		global $wp_registered_widgets;

		$callback = $wp_registered_widgets[ $params[0]['widget_id'] ]['callback'] ?? null;
		$widget   = ( is_array( $callback ) && isset( $callback[0] ) ) ? $callback[0] : null;

		if ( $widget instanceof \WC_Widget && ! empty( $this->get_restricted_product_ids() ) ) {
			$params[0]['widget_id'] = '';
		}

		return $params;
	}

	/**
	 * Keep the legacy product grid blocks out of their shared result cache for
	 * a visitor with hidden products.
	 *
	 * @param mixed $is_cacheable Whether the grid's results may be cached.
	 * @return mixed
	 */
	public function filter_product_grid_is_cacheable( $is_cacheable ) {
		return empty( $this->get_restricted_product_ids() ) ? $is_cacheable : false;
	}

	/**
	 * Validate cart items on cart/checkout pages
	 */
	public function validate_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_add_notice' ) || ! WC()->cart ) {
			return;
		}

		$restricted_items = array();

		foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
			$product_id = $cart_item['product_id'];

			if ( ! $this->user_can_view_product( $product_id ) ) {
				$product            = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
				$restricted_items[] = array(
					'key'  => $cart_key,
					'name' => $product ? $product->get_name() : __( 'Product', 'dragon-product-visibility' ),
				);
			}
		}

		if ( ! empty( $restricted_items ) ) {
			// Remove restricted items from cart
			foreach ( $restricted_items as $item ) {
				WC()->cart->remove_cart_item( $item['key'] );
			}

			// Build error message
			$product_names = array_column( $restricted_items, 'name' );
			$message       = sprintf(
				/* translators: %s: list of the product names removed from the cart. */
				_n(
					'%s has been removed from your cart as you no longer have access to purchase it.',
					'%s have been removed from your cart as you no longer have access to purchase them.',
					count( $restricted_items ),
					'dragon-product-visibility'
				),
				'<strong>' . wp_sprintf_l( '%l', array_map( 'esc_html', $product_names ) ) . '</strong>'
			);

			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Check if current user can view a specific product
	 *
	 * @param int $product_id Product ID
	 */
	public function user_can_view_product( int $product_id ): bool {
		// Admins and shop managers can always see everything
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		// Rules live on the product, never on its variations, so a variation is
		// only as visible as its parent.
		return $this->product_visible( $this->rule_owner_id( $product_id ) );
	}

	/**
	 * Whether the current user may see a product (not a variation), judged by
	 * its own rules and then the bulk rules.
	 *
	 * @param int $product_id Product ID.
	 */
	private function product_visible( int $product_id ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		// Get restriction mode for this product
		$restriction_mode = get_post_meta( $product_id, '_dpv_restriction_mode', true );

		// An explicit per-product rule takes precedence over any category/tag rule,
		// so it decides on its own (a per-product allow can even override a hiding
		// category rule).
		if ( 'whitelist' === $restriction_mode ) {
			return $this->user_is_in_whitelist( $product_id, get_current_user_id() );
		}
		if ( 'blacklist' === $restriction_mode ) {
			return ! $this->user_is_in_blacklist( $product_id, get_current_user_id() );
		}

		// No per-product rule: fall through to any bulk category/tag rules.
		return $this->product_allowed_by_bulk_rules( $product_id );
	}

	/**
	 * The product whose rules decide a given ID: the parent for a variation,
	 * the ID itself otherwise.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return int
	 */
	private function rule_owner_id( int $product_id ): int {
		$post = get_post( $product_id );

		if ( $post && 'product_variation' === $post->post_type && (int) $post->post_parent > 0 ) {
			return (int) $post->post_parent;
		}

		return $product_id;
	}

	/**
	 * Whether the current user may see a product under the bulk category/tag rules.
	 *
	 * The product is hidden if it falls under any bulk rule (its own term or, for
	 * categories, a descendant of the rule's term) that denies the user's roles,
	 * most-restrictive wins. Only called for products with no per-product rule.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private function product_allowed_by_bulk_rules( int $product_id ): bool {
		$rules = Bulk_Rules::all();
		if ( empty( $rules ) ) {
			return true;
		}

		$user_roles = $this->current_user_roles();

		foreach ( $rules as $rule ) {
			if ( ! Bulk_Rules::rule_denies_roles( $rule, $user_roles ) ) {
				continue;
			}
			if ( $this->product_in_rule_term( $product_id, $rule ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a product belongs to a bulk rule's term: directly, or (for the
	 * hierarchical product_cat taxonomy) as a descendant of it, so a parent-
	 * category rule also covers products in its child categories.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $rule       Bulk rule.
	 * @return bool
	 */
	private function product_in_rule_term( int $product_id, array $rule ): bool {
		$terms = get_the_terms( $product_id, $rule['taxonomy'] );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return false;
		}

		$term_ids = array();
		foreach ( $terms as $term ) {
			$term_ids[] = (int) $term->term_id;
			if ( 'product_cat' === $rule['taxonomy'] ) {
				foreach ( get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor ) {
					$term_ids[] = (int) $ancestor;
				}
			}
		}

		return in_array( (int) $rule['term_id'], $term_ids, true );
	}

	/**
	 * Roles held by the current user (empty for guests).
	 *
	 * @return string[]
	 */
	private function current_user_roles(): array {
		$user = wp_get_current_user();

		return ( $user && ! empty( $user->roles ) ) ? array_map( 'strval', (array) $user->roles ) : array();
	}

	/**
	 * Check if user is in the whitelist for a product
	 *
	 * @param int $product_id Product ID
	 * @param int $user_id User ID
	 */
	private function user_is_in_whitelist( int $product_id, int $user_id ): bool {
		// Check customer-specific list
		if ( $this->user_is_in_customer_list( $product_id, $user_id ) ) {
			return true;
		}

		// Check role-based list
		if ( $this->user_has_listed_role( $product_id, $user_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if user is in the blacklist for a product
	 *
	 * @param int $product_id Product ID
	 * @param int $user_id User ID
	 */
	private function user_is_in_blacklist( int $product_id, int $user_id ): bool {
		// Check customer-specific list
		if ( $this->user_is_in_customer_list( $product_id, $user_id ) ) {
			return true;
		}

		// Check role-based list
		if ( $this->user_has_listed_role( $product_id, $user_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if user is in the customer list for a product
	 * Used by both whitelist (allowed) and blacklist (blocked) modes
	 *
	 * @param int $product_id Product ID
	 * @param int $user_id User ID
	 */
	private function user_is_in_customer_list( int $product_id, int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		return in_array( $product_id, $this->get_user_customer_products(), true );
	}

	/**
	 * All product IDs the current user is explicitly listed for.
	 *
	 * Fetched once per request in a single query, so the restricted-product
	 * sweep doesn't run a customer-list query per product (the N+1 that made
	 * every catalog page cost one query per restricted product).
	 *
	 * @return int[]
	 */
	private function get_user_customer_products(): array {
		if ( ! is_null( $this->user_customer_products ) ) {
			return $this->user_customer_products;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->user_customer_products = array();
			return $this->user_customer_products;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read; the whole set is fetched once and cached on the instance for the request.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT product_id FROM %i WHERE customer_id = %d',
				$wpdb->prefix . 'dpv_customer_visibility',
				$user_id
			)
		);

		$this->user_customer_products = array_map( 'intval', (array) $ids );

		return $this->user_customer_products;
	}

	/**
	 * Check if user has a listed role for a product
	 * Used by both whitelist (allowed) and blacklist (blocked) modes
	 *
	 * @param int $product_id Product ID
	 * @param int $user_id User ID
	 */
	private function user_has_listed_role( int $product_id, int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		$allowed_roles = get_post_meta( $product_id, '_dpv_visible_roles', true );

		if ( ! is_array( $allowed_roles ) || empty( $allowed_roles ) ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$user_roles = (array) $user->roles;

		return ! empty( array_intersect( $user_roles, $allowed_roles ) );
	}

	/**
	 * Get all restricted product IDs for current user
	 */
	public function get_restricted_product_ids(): array {
		// Return cached result if available
		if ( ! is_null( $this->restricted_products_cache ) ) {
			return $this->restricted_products_cache;
		}

		// Guard against re-entrancy: resolving bulk rules runs an internal product
		// query, which must not recurse back into this method.
		if ( $this->resolving ) {
			return array();
		}

		global $wpdb;

		$restricted_ids = array();

		// Products carrying an explicit per-product rule.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance query, caching handled by class.
		$products_with_restrictions = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_dpv_restriction_mode'
             AND meta_value IN ('whitelist', 'blacklist')"
		);

		$per_product = array_values( array_unique( array_map( 'intval', (array) $products_with_restrictions ) ) );

		// Products the current user is denied by a bulk category/tag rule. Adding
		// these to the set (rather than only the per-product ones) is what keeps a
		// category rule enforced on the listing/search/sitemap paths.
		$bulk_denied = $this->get_bulk_denied_product_ids();

		if ( ! empty( $per_product ) ) {
			// Prime the meta and term caches for the products with their own rule
			// in one query each, so the loop below hits the cache.
			update_meta_cache( 'post', $per_product );
			update_object_term_cache( $per_product, 'product' );
		}

		// A product with its own rule gets the full decision. This re-check is
		// what lets an explicit per-product allow override a hiding category rule:
		// a product in a denied category but individually whitelisted for the user
		// resolves visible. Every candidate is a product (rules and bulk terms sit
		// on products), so the variation-to-parent lookup is skipped.
		foreach ( $per_product as $product_id ) {
			if ( ! $this->product_visible( $product_id ) ) {
				$restricted_ids[] = $product_id;
			}
		}

		// Any other product a bulk rule denies is decided by that rule alone, so
		// its meta is never loaded: on a large hidden category that would load
		// every product's meta on every request.
		foreach ( array_diff( $bulk_denied, $per_product ) as $product_id ) {
			$restricted_ids[] = (int) $product_id;
		}

		$this->restricted_products_cache = $restricted_ids;

		return $this->restricted_products_cache;
	}

	/**
	 * Product IDs the current user is denied by a bulk (category/tag) rule.
	 *
	 * Only rules that actually deny the user's roles are expanded to products, and
	 * the expansion runs one product query with all denying terms OR'd together
	 * (categories include their descendants). Cached per request.
	 *
	 * @return int[]
	 */
	private function get_bulk_denied_product_ids(): array {
		if ( ! is_null( $this->bulk_denied_products ) ) {
			return $this->bulk_denied_products;
		}

		$rules = Bulk_Rules::all();
		if ( empty( $rules ) ) {
			$this->bulk_denied_products = array();
			return $this->bulk_denied_products;
		}

		$user_roles = $this->current_user_roles();
		$tax_query  = array( 'relation' => 'OR' );

		foreach ( $rules as $rule ) {
			if ( ! Bulk_Rules::rule_denies_roles( $rule, $user_roles ) ) {
				continue;
			}
			$tax_query[] = array(
				'taxonomy'         => $rule['taxonomy'],
				'field'            => 'term_id',
				'terms'            => (int) $rule['term_id'],
				'include_children' => ( 'product_cat' === $rule['taxonomy'] ),
			);
		}

		// No denying rule applies to this user.
		if ( count( $tax_query ) < 2 ) {
			$this->bulk_denied_products = array();
			return $this->bulk_denied_products;
		}

		// try/finally so a throw inside the internal query (e.g. a third-party
		// filter) can never leave $resolving stuck true, which would make every
		// later get_restricted_product_ids() call in this request return empty and
		// stop filtering restricted products.
		$this->resolving = true;
		try {
			$ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					// get_posts() suppresses query filters by default, so this
					// plugin's own filters do not run on the internal query.
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Enforcing category/tag visibility inherently requires a taxonomy query; result is cached per request.
					'tax_query'      => $tax_query,
				)
			);
		} finally {
			$this->resolving = false;
		}

		$this->bulk_denied_products = array_map( 'intval', (array) $ids );

		return $this->bulk_denied_products;
	}

	/**
	 * Clear the cache
	 */
	public function clear_cache(): void {
		$this->restricted_products_cache = null;
		$this->allowed_products_cache    = null;
		$this->user_customer_products    = null;
		$this->bulk_denied_products      = null;
	}
}
