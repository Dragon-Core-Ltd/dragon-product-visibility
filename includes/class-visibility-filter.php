<?php
/**
 * Visibility Filter Class - Handles all product visibility filtering
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access
if (!defined('ABSPATH')) {
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
     * Get instance
     */
    public static function instance(): Visibility_Filter {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Don't apply filters for admins/shop managers
        if (current_user_can('manage_woocommerce')) {
            return;
        }

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Filter main product queries (shop, archives, search)
        add_action('woocommerce_product_query', [$this, 'filter_product_query'], 10, 2);

        // Filter single product page access
        add_action('template_redirect', [$this, 'check_single_product_access'], 10);

        // Filter product visibility checks
        add_filter('woocommerce_product_is_visible', [$this, 'filter_product_visibility'], 10, 2);

        // Filter related products
        add_filter('woocommerce_related_products', [$this, 'filter_related_products'], 10, 3);

        // Filter cross-sells
        add_filter('woocommerce_product_get_crosssell_ids', [$this, 'filter_product_ids'], 10, 2);

        // Filter up-sells
        add_filter('woocommerce_product_get_upsell_ids', [$this, 'filter_product_ids'], 10, 2);

        // Filter search results
        add_filter('posts_where', [$this, 'filter_search_where'], 10, 2);

        // Filter WooCommerce blocks
        add_filter('woocommerce_blocks_product_grid_item_html', [$this, 'filter_block_product'], 10, 3);

        // Add to cart validation
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 3);

        // Validate cart items on cart/checkout pages
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart_items']);
    }

    /**
     * Filter main WooCommerce product query
     *
     * @param \WP_Query $query Query object
     * @param \WC_Query $wc_query WooCommerce query object
     */
    public function filter_product_query(\WP_Query $query, $wc_query): void {
        if (is_admin()) {
            return;
        }

        $restricted_ids = $this->get_restricted_product_ids();

        if (!empty($restricted_ids)) {
            $query->set('post__not_in', array_merge(
                (array) $query->get('post__not_in'),
                $restricted_ids
            ));
        }
    }

    /**
     * Check access to single product page
     */
    public function check_single_product_access(): void {
        if (!is_product()) {
            return;
        }

        global $post;

        if (!$post || !is_a($post, 'WP_Post')) {
            return;
        }

        if (!$this->user_can_view_product($post->ID)) {
            // Redirect to shop page
            $redirect_url = apply_filters('dpv_restricted_redirect_url', wc_get_page_permalink('shop'));

            // Show notice before redirect
            if (function_exists('wc_add_notice')) {
                wc_add_notice(
                    apply_filters(
                        'dpv_restricted_product_message',
                        __('Sorry, you do not have access to view this product.', 'dragon-product-visibility')
                    ),
                    'error'
                );
            }

            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Filter product visibility
     *
     * @param bool $visible Whether product is visible
     * @param int  $product_id Product ID
     */
    public function filter_product_visibility(bool $visible, int $product_id): bool {
        if (!$visible) {
            return $visible;
        }

        return $this->user_can_view_product($product_id);
    }

    /**
     * Filter related products
     *
     * @param array $related_posts Related product IDs
     * @param int   $product_id Current product ID
     * @param array $args Query args
     */
    public function filter_related_products(array $related_posts, int $product_id, array $args): array {
        if (empty($related_posts)) {
            return $related_posts;
        }

        $restricted_ids = $this->get_restricted_product_ids();

        if (!empty($restricted_ids)) {
            $related_posts = array_diff($related_posts, $restricted_ids);
        }

        return $related_posts;
    }

    /**
     * Filter array of product IDs (cross-sells, up-sells)
     *
     * @param array       $product_ids Product IDs
     * @param \WC_Product $product Product object
     */
    public function filter_product_ids(array $product_ids, \WC_Product $product): array {
        if (empty($product_ids)) {
            return $product_ids;
        }

        $restricted_ids = $this->get_restricted_product_ids();

        if (!empty($restricted_ids)) {
            $product_ids = array_diff($product_ids, $restricted_ids);
        }

        return array_values($product_ids);
    }

    /**
     * Filter search results
     *
     * @param string    $where WHERE clause
     * @param \WP_Query $query Query object
     */
    public function filter_search_where(string $where, \WP_Query $query): string {
        if (is_admin() || !$query->is_search()) {
            return $where;
        }

        if (!isset($query->query_vars['post_type']) || $query->query_vars['post_type'] !== 'product') {
            return $where;
        }

        global $wpdb;

        $restricted_ids = $this->get_restricted_product_ids();

        if (!empty($restricted_ids)) {
            $ids_string = implode(',', array_map('intval', $restricted_ids));
            $where .= " AND {$wpdb->posts}.ID NOT IN ({$ids_string})";
        }

        return $where;
    }

    /**
     * Filter products in WooCommerce blocks
     *
     * @param string      $html Product HTML
     * @param object      $data Product data
     * @param \WC_Product $product Product object
     */
    public function filter_block_product(string $html, object $data, \WC_Product $product): string {
        if (!$this->user_can_view_product($product->get_id())) {
            return '';
        }

        return $html;
    }

    /**
     * Validate add to cart
     *
     * @param bool $passed Whether validation passed
     * @param int  $product_id Product ID
     * @param int  $quantity Quantity
     */
    public function validate_add_to_cart(bool $passed, int $product_id, int $quantity): bool {
        if (!$passed) {
            return $passed;
        }

        if (!$this->user_can_view_product($product_id)) {
            wc_add_notice(
                __('Sorry, you cannot purchase this product.', 'dragon-product-visibility'),
                'error'
            );
            return false;
        }

        return $passed;
    }

    /**
     * Validate cart items on cart/checkout pages
     */
    public function validate_cart_items(): void {
        if (!WC()->cart) {
            return;
        }

        $restricted_items = [];

        foreach (WC()->cart->get_cart() as $cart_key => $cart_item) {
            $product_id = $cart_item['product_id'];

            if (!$this->user_can_view_product($product_id)) {
                $product = wc_get_product($product_id);
                $restricted_items[] = [
                    'key'  => $cart_key,
                    'name' => $product ? $product->get_name() : __('Product', 'dragon-product-visibility'),
                ];
            }
        }

        if (!empty($restricted_items)) {
            // Remove restricted items from cart
            foreach ($restricted_items as $item) {
                WC()->cart->remove_cart_item($item['key']);
            }

            // Build error message
            $product_names = array_column($restricted_items, 'name');
            $message = sprintf(
                _n(
                    '%s has been removed from your cart as you no longer have access to purchase it.',
                    '%s have been removed from your cart as you no longer have access to purchase them.',
                    count($restricted_items),
                    'dragon-product-visibility'
                ),
                '<strong>' . implode(', ', $product_names) . '</strong>'
            );

            wc_add_notice($message, 'error');
        }
    }

    /**
     * Check if current user can view a specific product
     *
     * @param int $product_id Product ID
     */
    public function user_can_view_product(int $product_id): bool {
        // Admins and shop managers can always see everything
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        // Get restriction mode for this product
        $restriction_mode = get_post_meta($product_id, '_dpv_restriction_mode', true);

        // If no restriction mode set, product is visible to all
        if (!$restriction_mode || $restriction_mode === 'none') {
            return true;
        }

        $current_user_id = get_current_user_id();

        // Check if user has access based on restriction mode
        if ($restriction_mode === 'whitelist') {
            // Whitelist mode: only specified users/roles can see
            return $this->user_is_in_whitelist($product_id, $current_user_id);
        } elseif ($restriction_mode === 'blacklist') {
            // Blacklist mode: specified users/roles cannot see
            return !$this->user_is_in_blacklist($product_id, $current_user_id);
        }

        return true;
    }

    /**
     * Check if user is in the whitelist for a product
     *
     * @param int $product_id Product ID
     * @param int $user_id User ID
     */
    private function user_is_in_whitelist(int $product_id, int $user_id): bool {
        // Check customer-specific list
        if ($this->user_is_in_customer_list($product_id, $user_id)) {
            return true;
        }

        // Check role-based list
        if ($this->user_has_listed_role($product_id, $user_id)) {
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
    private function user_is_in_blacklist(int $product_id, int $user_id): bool {
        // Check customer-specific list
        if ($this->user_is_in_customer_list($product_id, $user_id)) {
            return true;
        }

        // Check role-based list
        if ($this->user_has_listed_role($product_id, $user_id)) {
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
    private function user_is_in_customer_list(int $product_id, int $user_id): bool {
        if (!$user_id) {
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'dpv_customer_visibility';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance-critical visibility check.
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE product_id = %d AND customer_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
            $product_id,
            $user_id
        ));

        return $exists > 0;
    }

    /**
     * Check if user has a listed role for a product
     * Used by both whitelist (allowed) and blacklist (blocked) modes
     *
     * @param int $product_id Product ID
     * @param int $user_id User ID
     */
    private function user_has_listed_role(int $product_id, int $user_id): bool {
        if (!$user_id) {
            return false;
        }

        $allowed_roles = get_post_meta($product_id, '_dpv_visible_roles', true);

        if (!is_array($allowed_roles) || empty($allowed_roles)) {
            return false;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        $user_roles = (array) $user->roles;

        return !empty(array_intersect($user_roles, $allowed_roles));
    }

    /**
     * Get all restricted product IDs for current user
     */
    public function get_restricted_product_ids(): array {
        // Return cached result if available
        if (!is_null($this->restricted_products_cache)) {
            return $this->restricted_products_cache;
        }

        global $wpdb;

        $current_user_id = get_current_user_id();
        $restricted_ids = [];

        // Get all products with restrictions
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance query, caching handled by class.
        $products_with_restrictions = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_dpv_restriction_mode'
             AND meta_value IN ('whitelist', 'blacklist')"
        );

        if (empty($products_with_restrictions)) {
            $this->restricted_products_cache = [];
            return $this->restricted_products_cache;
        }

        // Check each restricted product
        foreach ($products_with_restrictions as $product_id) {
            if (!$this->user_can_view_product((int) $product_id)) {
                $restricted_ids[] = (int) $product_id;
            }
        }

        $this->restricted_products_cache = $restricted_ids;

        return $this->restricted_products_cache;
    }

    /**
     * Clear the cache
     */
    public function clear_cache(): void {
        $this->restricted_products_cache = null;
        $this->allowed_products_cache = null;
    }
}
