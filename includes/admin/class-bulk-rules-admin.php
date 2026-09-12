<?php
/**
 * Bulk Rules admin screen — manage category/tag visibility rules.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Visibility Rules" page under Products for shop-wide category/tag rules.
 */
class Bulk_Rules_Admin {

	/**
	 * Single instance.
	 *
	 * @var Bulk_Rules_Admin|null
	 */
	private static ?Bulk_Rules_Admin $instance = null;

	/**
	 * Menu slug.
	 */
	const PAGE_SLUG = 'dragonproductvisibility-rules';

	/**
	 * Capability required to manage rules.
	 */
	const CAP = 'manage_woocommerce';

	/**
	 * Get instance.
	 */
	public static function instance(): Bulk_Rules_Admin {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_dragonproductvisibility_add_bulk_rule', array( $this, 'handle_add' ) );
		add_action( 'admin_post_dragonproductvisibility_delete_bulk_rule', array( $this, 'handle_delete' ) );
	}

	/**
	 * Register the submenu page under Products.
	 */
	public function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Product Visibility Rules', 'dragon-product-visibility' ),
			__( 'Visibility Rules', 'dragon-product-visibility' ),
			self::CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle adding a rule.
	 */
	public function handle_add(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dragon-product-visibility' ) );
		}
		check_admin_referer( 'dragonproductvisibility_add_bulk_rule' );

		// Term is submitted as "taxonomy:term_id".
		$term_field = isset( $_POST['dpv_term'] ) ? sanitize_text_field( wp_unslash( $_POST['dpv_term'] ) ) : '';
		$parts      = explode( ':', $term_field, 2 );
		$taxonomy   = $parts[0] ?? '';
		$term_id    = isset( $parts[1] ) ? (int) $parts[1] : 0;

		$mode  = isset( $_POST['dpv_mode'] ) ? sanitize_key( wp_unslash( $_POST['dpv_mode'] ) ) : '';
		$roles = ( isset( $_POST['dpv_roles'] ) && is_array( $_POST['dpv_roles'] ) )
			? array_map( 'sanitize_key', wp_unslash( $_POST['dpv_roles'] ) )
			: array();

		$editable = array_keys( get_editable_roles() );
		$roles    = array_values( array_intersect( $roles, $editable ) );

		$error = '';
		if ( ! in_array( $taxonomy, Bulk_Rules::TAXONOMIES, true ) || $term_id <= 0 || ! term_exists( $term_id, $taxonomy ) ) {
			$error = 'term';
		} elseif ( ! in_array( $mode, Bulk_Rules::MODES, true ) ) {
			$error = 'mode';
		} elseif ( empty( $roles ) ) {
			// A rule with no roles would be meaningless (blacklist) or hide from
			// everyone (whitelist) in a way better expressed per product; require one.
			$error = 'roles';
		}

		if ( '' !== $error ) {
			$this->redirect( array( 'dpv_error' => $error ) );
		}

		$rules   = Bulk_Rules::all();
		$rules[] = array(
			'taxonomy' => $taxonomy,
			'term_id'  => $term_id,
			'mode'     => $mode,
			'roles'    => $roles,
		);
		if ( ! Bulk_Rules::save( $rules ) ) {
			$this->redirect( array( 'dpv_error' => 'save' ) );
		}

		$this->redirect( array( 'dpv_msg' => 'added' ) );
	}

	/**
	 * Handle deleting a rule.
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dragon-product-visibility' ) );
		}
		check_admin_referer( 'dragonproductvisibility_delete_bulk_rule' );

		$id = isset( $_POST['dpv_rule_id'] ) ? sanitize_key( wp_unslash( $_POST['dpv_rule_id'] ) ) : '';
		if ( '' === $id || ! Bulk_Rules::delete( $id ) ) {
			$this->redirect( array( 'dpv_error' => 'delete' ) );
		}

		$this->redirect( array( 'dpv_msg' => 'deleted' ) );
	}

	/**
	 * Redirect back to the rules page with a status flag.
	 *
	 * @param array $args Query args.
	 */
	private function redirect( array $args ): void {
		$url = add_query_arg(
			array_merge(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE_SLUG,
				),
				$args
			),
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$rules = Bulk_Rules::all();
		require DRAGONPRODUCTVISIBILITY_PLUGIN_PATH . 'includes/admin/views/bulk-rules.php';
	}
}
