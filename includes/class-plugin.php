<?php
/**
 * Main plugin class
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		self::migrate_legacy_prefix();
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Move options off the pre-1.0.1 three-letter (dpv_) prefix.
	 *
	 * The option prefix was renamed to the namespace-derived
	 * `dragonproductvisibility_` to satisfy the WordPress.org uniqueness rule.
	 * Stored option values are carried across once, on the first load after the
	 * update. Database tables and post/user meta keep their original keys (they
	 * are matched by exact name and are not covered by the naming rule), so no
	 * per-row product data is touched.
	 */
	private static function migrate_legacy_prefix(): void {
		// db_version is a schema marker managed by activation, not user data.
		delete_option( 'dpv_db_version' );

		$options = array(
			'version',
			'restriction_mode',
			'hide_restricted_completely',
			'show_message_on_direct_access',
			'restricted_redirect',
		);

		// Copy each legacy value onto the new name, then remove the legacy copy —
		// per option, with no shared db_version guard, so the settings are carried
		// even on a deactivate/reactivate update (where activation would otherwise
		// re-stamp the new db_version before the copy could run).
		foreach ( $options as $name ) {
			$legacy = get_option( 'dpv_' . $name, null );
			if ( null !== $legacy ) {
				update_option( 'dragonproductvisibility_' . $name, $legacy );
				delete_option( 'dpv_' . $name );
			}
		}
	}

	/**
	 * Include required files
	 */
	private function includes(): void {
		// Core classes.
		require_once DRAGONPRODUCTVISIBILITY_PLUGIN_PATH . 'includes/class-install.php';
		require_once DRAGONPRODUCTVISIBILITY_PLUGIN_PATH . 'includes/class-visibility-filter.php';
		require_once DRAGONPRODUCTVISIBILITY_PLUGIN_PATH . 'includes/class-ajax.php';

		// Admin classes.
		if ( is_admin() ) {
			require_once DRAGONPRODUCTVISIBILITY_PLUGIN_PATH . 'includes/admin/class-admin.php';
			require_once DRAGONPRODUCTVISIBILITY_PLUGIN_PATH . 'includes/admin/class-product-metabox.php';
		}
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks(): void {
		// Init hook.
		add_action( 'init', array( $this, 'init' ), 0 );

		// Check if WooCommerce is active.
		add_action( 'admin_notices', array( $this, 'check_woocommerce' ) );

		// Declare HPOS compatibility.
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	/**
	 * Initialize plugin
	 */
	public function init(): void {
		// Initialize visibility filter (frontend).
		if ( ! is_admin() || wp_doing_ajax() ) {
			Visibility_Filter::instance();
		}

		// Initialize AJAX handlers.
		Ajax::instance();

		// Initialize admin.
		if ( is_admin() ) {
			Admin::instance();
			Product_Metabox::instance();
		}
	}

	/**
	 * Check if WooCommerce is active
	 */
	public function check_woocommerce(): void {
		// Don't show if WooCommerce is active.
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Only where someone can act on it: the plugins list. Without WooCommerce
		// the plugin has no screens of its own, so nowhere else is relevant.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		// Don't show if user dismissed the notice.
		$dismissed = get_user_meta( get_current_user_id(), 'dpv_wc_notice_dismissed', true );
		if ( $dismissed ) {
			return;
		}

		// Handle dismissal via AJAX.
		if ( isset( $_GET['dragonproductvisibility_dismiss_wc_notice'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'dragonproductvisibility_dismiss_notice' ) ) {
			update_user_meta( get_current_user_id(), 'dpv_wc_notice_dismissed', 1 );
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'dragonproductvisibility_dismiss_wc_notice', '1' ), 'dragonproductvisibility_dismiss_notice' );
		?>
		<div class="notice notice-warning is-dismissible" data-dpv-notice="wc-required">
			<p>
				<?php echo esc_html__( 'Dragon Product Visibility for WooCommerce requires WooCommerce to be installed and active.', 'dragon-product-visibility' ); ?>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left: 10px;"><?php esc_html_e( 'Dismiss permanently', 'dragon-product-visibility' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Declare HPOS (High-Performance Order Storage) compatibility
	 */
	public function declare_hpos_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DRAGONPRODUCTVISIBILITY_PLUGIN_FILE, true );
		}
	}

	/**
	 * Get plugin URL
	 */
	public function plugin_url(): string {
		return DRAGONPRODUCTVISIBILITY_PLUGIN_URL;
	}

	/**
	 * Get plugin path
	 */
	public function plugin_path(): string {
		return DRAGONPRODUCTVISIBILITY_PLUGIN_PATH;
	}
}
