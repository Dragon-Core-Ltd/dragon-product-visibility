<?php
/**
 * Product Metabox Class - Adds visibility settings to WooCommerce product edit page
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product_Metabox Class
 */
class Product_Metabox {

    /**
     * Single instance
     *
     * @var Product_Metabox|null
     */
    private static ?Product_Metabox $instance = null;

    /**
     * Get instance
     */
    public static function instance(): Product_Metabox {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Add product data tab
        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_tab']);

        // Add tab content
        add_action('woocommerce_product_data_panels', [$this, 'add_tab_content']);

        // Save product data
        add_action('woocommerce_process_product_meta', [$this, 'save_product_data']);

        // Also for simple products
        add_action('woocommerce_process_product_meta_simple', [$this, 'save_product_data']);

        // And variable products
        add_action('woocommerce_process_product_meta_variable', [$this, 'save_product_data']);
    }

    /**
     * Add product data tab
     *
     * @param array $tabs Existing tabs
     */
    public function add_product_tab(array $tabs): array {
        $tabs['visibility_restrictions'] = [
            'label' => __('Visibility Restrictions', 'dragon-product-visibility'),
            'target' => 'visibility_restrictions_data',
            'class' => ['show_if_simple', 'show_if_variable', 'show_if_grouped', 'show_if_external'],
            'priority' => 80,
        ];

        return $tabs;
    }

    /**
     * Add tab content
     */
    public function add_tab_content(): void {
        global $post;

        // Get saved data
        $restriction_mode = get_post_meta($post->ID, '_dpv_restriction_mode', true);
        $visible_roles = get_post_meta($post->ID, '_dpv_visible_roles', true);
        if (!is_array($visible_roles)) {
            $visible_roles = [];
        }

        // Get customers from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'dpv_customer_visibility';
        $selected_customers = [];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking table existence.
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Loading saved customers.
            $customer_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT customer_id FROM {$table_name} WHERE product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
                $post->ID
            ));

            foreach ($customer_ids as $customer_id) {
                $user = get_userdata($customer_id);
                if ($user) {
                    $selected_customers[] = [
                        'id' => $customer_id,
                        'text' => $user->display_name . ' (' . $user->user_email . ')',
                    ];
                }
            }
        }

        // Get all available roles
        $all_roles = Admin::get_all_roles();

        ?>
        <div id="visibility_restrictions_data" class="panel woocommerce_options_panel">
            <div class="options_group dpv-options-group">
                <h3 class="dpv-section-title">
                    <?php esc_html_e('Product Visibility Restrictions', 'dragon-product-visibility'); ?>
                </h3>
                <p class="dpv-description">
                    <?php esc_html_e('Control which customers can see and purchase this product. By default, products are visible to everyone.', 'dragon-product-visibility'); ?>
                </p>

                <?php wp_nonce_field('dpv_save_visibility', 'dpv_visibility_nonce'); ?>

                <!-- Restriction Mode -->
                <p class="form-field dpv-restriction-mode-field">
                    <label for="dpv_restriction_mode"><?php esc_html_e('Restriction Mode', 'dragon-product-visibility'); ?></label>
                    <select id="dpv_restriction_mode" name="dpv_restriction_mode" class="select short">
                        <option value="none" <?php selected($restriction_mode, 'none'); ?>>
                            <?php esc_html_e('No restrictions (visible to all)', 'dragon-product-visibility'); ?>
                        </option>
                        <option value="whitelist" <?php selected($restriction_mode, 'whitelist'); ?>>
                            <?php esc_html_e('Whitelist - Only selected can see', 'dragon-product-visibility'); ?>
                        </option>
                        <option value="blacklist" <?php selected($restriction_mode, 'blacklist'); ?>>
                            <?php esc_html_e('Blacklist - Hide from selected', 'dragon-product-visibility'); ?>
                        </option>
                    </select>
                </p>
            </div>

            <div class="options_group dpv-restrictions-panel" style="<?php echo ($restriction_mode === 'none') ? 'display:none;' : ''; ?>" data-mode="<?php echo esc_attr($restriction_mode); ?>">

                <!-- Customer Selection -->
                <div class="dpv-section">
                    <h4 class="dpv-customers-title"><?php esc_html_e('Specific Customers', 'dragon-product-visibility'); ?></h4>
                    <p class="description dpv-customers-desc dpv-whitelist-text" <?php echo ($restriction_mode === 'blacklist') ? 'style="display:none;"' : ''; ?>>
                        <?php esc_html_e('Search and select individual customers who CAN view this product.', 'dragon-product-visibility'); ?>
                    </p>
                    <p class="description dpv-customers-desc dpv-blacklist-text" <?php echo ($restriction_mode !== 'blacklist') ? 'style="display:none;"' : ''; ?>>
                        <?php esc_html_e('Search and select individual customers who CANNOT view this product.', 'dragon-product-visibility'); ?>
                    </p>

                    <p class="form-field">
                        <label for="dpv_customers" class="dpv-customers-label">
                            <span class="dpv-whitelist-text" <?php echo ($restriction_mode === 'blacklist') ? 'style="display:none;"' : ''; ?>><?php esc_html_e('Allowed Customers', 'dragon-product-visibility'); ?></span>
                            <span class="dpv-blacklist-text" <?php echo ($restriction_mode !== 'blacklist') ? 'style="display:none;"' : ''; ?>><?php esc_html_e('Blocked Customers', 'dragon-product-visibility'); ?></span>
                        </label>
                        <select id="dpv_customers" name="dpv_customers[]" class="dpv-customer-select" multiple="multiple" style="width: 100%;">
                            <?php foreach ($selected_customers as $customer) : ?>
                                <option value="<?php echo esc_attr($customer['id']); ?>" selected="selected">
                                    <?php echo esc_html($customer['text']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </div>

                <!-- Role Selection -->
                <div class="dpv-section">
                    <h4 class="dpv-roles-title"><?php esc_html_e('User Roles', 'dragon-product-visibility'); ?></h4>
                    <p class="description dpv-roles-desc dpv-whitelist-text" <?php echo ($restriction_mode === 'blacklist') ? 'style="display:none;"' : ''; ?>>
                        <?php esc_html_e('Select user roles that CAN view this product.', 'dragon-product-visibility'); ?>
                    </p>
                    <p class="description dpv-roles-desc dpv-blacklist-text" <?php echo ($restriction_mode !== 'blacklist') ? 'style="display:none;"' : ''; ?>>
                        <?php esc_html_e('Select user roles that CANNOT view this product.', 'dragon-product-visibility'); ?>
                    </p>

                    <p class="form-field">
                        <label for="dpv_roles" class="dpv-roles-label">
                            <span class="dpv-whitelist-text" <?php echo ($restriction_mode === 'blacklist') ? 'style="display:none;"' : ''; ?>><?php esc_html_e('Allowed Roles', 'dragon-product-visibility'); ?></span>
                            <span class="dpv-blacklist-text" <?php echo ($restriction_mode !== 'blacklist') ? 'style="display:none;"' : ''; ?>><?php esc_html_e('Blocked Roles', 'dragon-product-visibility'); ?></span>
                        </label>
                        <select id="dpv_roles" name="dpv_roles[]" class="dpv-role-select" multiple="multiple" style="width: 100%;">
                            <?php foreach ($all_roles as $role_key => $role_name) : ?>
                                <option value="<?php echo esc_attr($role_key); ?>" <?php selected(in_array($role_key, $visible_roles)); ?>>
                                    <?php echo esc_html($role_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </div>


                <div class="dpv-info-box dpv-whitelist-text" <?php echo ($restriction_mode === 'blacklist') ? 'style="display:none;"' : ''; ?>>
                    <p>
                        <strong><?php esc_html_e('Whitelist Mode:', 'dragon-product-visibility'); ?></strong>
                        <?php esc_html_e('Only users in the allowed list will see this product. Everyone else will be blocked.', 'dragon-product-visibility'); ?>
                    </p>
                </div>
                <div class="dpv-info-box dpv-blacklist-text" <?php echo ($restriction_mode !== 'blacklist') ? 'style="display:none;"' : ''; ?>>
                    <p>
                        <strong><?php esc_html_e('Blacklist Mode:', 'dragon-product-visibility'); ?></strong>
                        <?php esc_html_e('Users in the blocked list will NOT see this product. Everyone else can view it normally.', 'dragon-product-visibility'); ?>
                    </p>
                </div>

            </div>
        </div>

        <style>
            #visibility_restrictions_data .dpv-section-title {
                padding: 10px 12px;
                margin: 0;
                border-bottom: 1px solid #eee;
                font-size: 14px;
            }
            #visibility_restrictions_data .dpv-description {
                padding: 10px 12px;
                margin: 0;
                color: #666;
                font-style: italic;
            }
            #visibility_restrictions_data .dpv-section {
                padding: 15px 12px;
                border-bottom: 1px solid #eee;
            }
            #visibility_restrictions_data .dpv-section h4 {
                margin: 0 0 5px 0;
                font-weight: 600;
            }
            #visibility_restrictions_data .dpv-section .description {
                margin: 0 0 10px 0;
                color: #666;
            }
            #visibility_restrictions_data .dpv-info-box {
                background: #f8f9fa;
                padding: 12px;
                margin: 15px 12px;
                border-left: 4px solid #0073aa;
            }
            #visibility_restrictions_data .dpv-info-box p {
                margin: 0;
            }
            #visibility_restrictions_data .select2-container {
                width: 100% !important;
            }
        </style>
        <?php
    }

    /**
     * Save product data
     *
     * @param int $post_id Product ID
     */
    public function save_product_data(int $post_id): void {
        // Verify nonce
        if (!isset($_POST['dpv_visibility_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dpv_visibility_nonce'])), 'dpv_save_visibility')) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_product', $post_id)) {
            return;
        }

        // Save restriction mode
        $restriction_mode = isset($_POST['dpv_restriction_mode']) ? sanitize_text_field(wp_unslash($_POST['dpv_restriction_mode'])) : 'none';
        update_post_meta($post_id, '_dpv_restriction_mode', $restriction_mode);

        // Save roles
        $roles = isset($_POST['dpv_roles']) ? array_map('sanitize_text_field', wp_unslash($_POST['dpv_roles'])) : [];
        update_post_meta($post_id, '_dpv_visible_roles', $roles);

        // Save customers
        $customers = isset($_POST['dpv_customers']) ? array_map('absint', wp_unslash($_POST['dpv_customers'])) : [];
        $this->save_customer_visibility($post_id, $customers);
    }

    /**
     * Save customer visibility to custom table
     *
     * @param int   $product_id Product ID
     * @param array $customer_ids Customer IDs
     */
    private function save_customer_visibility(int $product_id, array $customer_ids): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dpv_customer_visibility';

        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check.
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
            Install::activate(); // Create tables if missing
        }

        // Delete existing entries
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Clearing before save.
        $wpdb->delete($table_name, ['product_id' => $product_id], ['%d']);

        // Insert new entries
        foreach ($customer_ids as $customer_id) {
            if ($customer_id > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserting visibility rules.
                $wpdb->insert(
                    $table_name,
                    [
                        'product_id' => $product_id,
                        'customer_id' => $customer_id,
                    ],
                    ['%d', '%d']
                );
            }
        }
    }
}
