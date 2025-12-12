<?php
/**
 * Plugin Name: Dragon Product Visibility for WooCommerce
 * Plugin URI: https://dcplugins.com/plugins/dragon-product-visibility
 * Description: Restrict WooCommerce product visibility by specific customers or user roles. Products can be made exclusive to certain users without complex membership plugins.
 * Version: 1.0.0
 * Author: Dragon Core
 * Author URI: https://dcplugins.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dragon-product-visibility
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.4
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DPV_ is the plugin prefix.
define('DPV_VERSION', '1.0.0');
define('DPV_PLUGIN_FILE', __FILE__);
define('DPV_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('DPV_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DPV_PLUGIN_BASENAME', plugin_basename(__FILE__));
// phpcs:enable

/**
 * Main plugin class
 */
final class Plugin {

    /**
     * Single instance of the class
     *
     * @var Plugin
     */
    private static $instance = null;

    /**
     * Get single instance of the class
     *
     * @return Plugin
     */
    public static function instance(): Plugin {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes(): void {
        // Core classes
        require_once DPV_PLUGIN_PATH . 'includes/class-install.php';
        require_once DPV_PLUGIN_PATH . 'includes/class-visibility-filter.php';
        require_once DPV_PLUGIN_PATH . 'includes/class-ajax.php';

        // Admin classes
        if (is_admin()) {
            require_once DPV_PLUGIN_PATH . 'includes/admin/class-admin.php';
            require_once DPV_PLUGIN_PATH . 'includes/admin/class-product-metabox.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Activation/deactivation hooks
        register_activation_hook(DPV_PLUGIN_FILE, [Install::class, 'activate']);
        register_deactivation_hook(DPV_PLUGIN_FILE, [Install::class, 'deactivate']);

        // Init hook
        add_action('init', [$this, 'init'], 0);


        // Check if WooCommerce is active
        add_action('admin_notices', [$this, 'check_woocommerce']);

        // Declare HPOS compatibility
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
    }

    /**
     * Initialize plugin
     */
    public function init(): void {
        // Initialize visibility filter (frontend)
        if (!is_admin() || wp_doing_ajax()) {
            Visibility_Filter::instance();
        }

        // Initialize AJAX handlers
        Ajax::instance();

        // Initialize admin
        if (is_admin()) {
            Admin::instance();
            Product_Metabox::instance();
        }
    }

    /**
     * Check if WooCommerce is active
     */
    public function check_woocommerce(): void {
        // Don't show if WooCommerce is active
        if (class_exists('WooCommerce')) {
            return;
        }

        // Don't show if user dismissed the notice
        $dismissed = get_user_meta(get_current_user_id(), 'dpv_wc_notice_dismissed', true);
        if ($dismissed) {
            return;
        }

        // Handle dismissal via AJAX
        if (isset($_GET['dpv_dismiss_wc_notice']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'dpv_dismiss_notice')) {
            update_user_meta(get_current_user_id(), 'dpv_wc_notice_dismissed', 1);
            return;
        }

        $dismiss_url = wp_nonce_url(add_query_arg('dpv_dismiss_wc_notice', '1'), 'dpv_dismiss_notice');
        ?>
        <div class="notice notice-warning is-dismissible" data-dpv-notice="wc-required">
            <p>
                <?php echo esc_html__('Dragon Product Visibility for WooCommerce requires WooCommerce to be installed and active.', 'dragon-product-visibility'); ?>
                <a href="<?php echo esc_url($dismiss_url); ?>" style="margin-left: 10px;"><?php esc_html_e('Dismiss permanently', 'dragon-product-visibility'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Declare HPOS (High-Performance Order Storage) compatibility
     */
    public function declare_hpos_compatibility(): void {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', DPV_PLUGIN_FILE, true);
        }
    }

    /**
     * Get plugin URL
     */
    public function plugin_url(): string {
        return DPV_PLUGIN_URL;
    }

    /**
     * Get plugin path
     */
    public function plugin_path(): string {
        return DPV_PLUGIN_PATH;
    }
}

/**
 * Main instance of Plugin
 *
 * @return Plugin
 */
function dpv(): Plugin {
    return Plugin::instance();
}

// Initialize
add_action('plugins_loaded', __NAMESPACE__ . '\dpv');
