<?php
/**
 * Admin Class
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Class
 */
class Admin {

	/**
	 * Single instance
	 *
	 * @var Admin|null
	 */
	private static ?Admin $instance = null;

	/**
	 * Get instance
	 */
	public static function instance(): Admin {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page
	 */
	public function enqueue_scripts( string $hook ): void {
		global $post_type;

		// Only load on product edit pages
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== $post_type ) {
			return;
		}

		// Enqueue Select2 (WooCommerce includes this)
		wp_enqueue_script( 'select2' );
		wp_enqueue_style( 'select2' );

		// Enqueue our admin script
		wp_enqueue_script(
			'dpv-admin',
			DPV_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'select2' ),
			DPV_VERSION,
			true
		);

		// Enqueue our admin styles
		wp_enqueue_style(
			'dpv-admin',
			DPV_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			DPV_VERSION
		);

		// Localize script
		wp_localize_script(
			'dpv-admin',
			'dpv_admin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'dpv_admin_nonce' ),
				'i18n'     => array(
					'search_customers' => __( 'Search for customers...', 'dragon-product-visibility' ),
					'select_roles'     => __( 'Select roles...', 'dragon-product-visibility' ),
					'no_results'       => __( 'No results found', 'dragon-product-visibility' ),
					'searching'        => __( 'Searching...', 'dragon-product-visibility' ),
					'save_success'     => __( 'Visibility rules saved successfully!', 'dragon-product-visibility' ),
					'save_error'       => __( 'Error saving visibility rules.', 'dragon-product-visibility' ),
				),
			)
		);
	}

	/**
	 * Show activation notice
	 */
	public function activation_notice(): void {
		if ( get_transient( 'dpv_activated' ) ) {
			delete_transient( 'dpv_activated' );
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					echo wp_kses_post(
						__( '<strong>Dragon Product Visibility</strong> has been activated. Edit any product to configure visibility restrictions.', 'dragon-product-visibility' )
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Get all WordPress roles
	 */
	public static function get_all_roles(): array {
		$roles = array();

		foreach ( wp_roles()->roles as $role_key => $role ) {
			$roles[ $role_key ] = $role['name'];
		}

		return $roles;
	}
}
