<?php
/**
 * AJAX Handler Class
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajax Class
 */
class Ajax {

	/**
	 * Single instance
	 *
	 * @var Ajax|null
	 */
	private static ?Ajax $instance = null;

	/**
	 * Get instance
	 */
	public static function instance(): Ajax {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		// Customer search AJAX
		add_action( 'wp_ajax_dragonproductvisibility_search_customers', array( $this, 'search_customers' ) );

		// Save visibility rules AJAX
		add_action( 'wp_ajax_dragonproductvisibility_save_visibility_rules', array( $this, 'save_visibility_rules' ) );
	}

	/**
	 * Search customers via AJAX (for Select2)
	 */
	public function search_customers(): void {
		// Verify nonce
		if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'dragonproductvisibility_admin_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'dragon-product-visibility' ) ) );
			return;
		}

		// The results carry every matching account's email address, so editing
		// products is not enough: the user must be able to see the user list.
		if ( ! current_user_can( 'edit_products' ) || ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'list_users' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'dragon-product-visibility' ) ) );
			return;
		}

		global $wpdb;

		$search_term = isset( $_REQUEST['search_term'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search_term'] ) ) : '';
		$results     = array();

		if ( strlen( $search_term ) < 1 ) {
			// Return recent customers if no search term
			$recent_customers = get_user_meta( get_current_user_id(), 'dpv_recent_customer_searches', true );
			if ( ! empty( $recent_customers ) && is_array( $recent_customers ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- User search query.
				$users = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID as id, CONCAT(display_name, ' (', user_email, ')') as text
                     FROM {$wpdb->users}
                     WHERE ID IN (" . implode( ',', array_fill( 0, count( $recent_customers ), '%d' ) ) . ')
                     ORDER BY display_name
                     LIMIT 10',
						$recent_customers
					)
				);
				wp_send_json( $users );
				return;
			}
		}

		// Search users by name, email, or login
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- User search query with user input.
		$users = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID as id, CONCAT(display_name, ' (', user_email, ')') as text
             FROM {$wpdb->users}
             WHERE user_login LIKE %s
                OR user_email LIKE %s
                OR display_name LIKE %s
             ORDER BY display_name
             LIMIT 50",
				'%' . $wpdb->esc_like( $search_term ) . '%',
				'%' . $wpdb->esc_like( $search_term ) . '%',
				'%' . $wpdb->esc_like( $search_term ) . '%'
			)
		);

		wp_send_json( $users );
	}

	/**
	 * Save visibility rules via AJAX
	 */
	public function save_visibility_rules(): void {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'dragonproductvisibility_admin_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'dragon-product-visibility' ) ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'dragon-product-visibility' ) ) );
			return;
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? get_post( $product_id ) : null;

		if ( ! $product || 'product' !== $product->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID', 'dragon-product-visibility' ) ) );
			return;
		}

		// The generic capability above only says the user edits products at all;
		// this one is mapped by WooCommerce onto the specific product.
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'dragon-product-visibility' ) ) );
			return;
		}

		// Get submitted data
		$restriction_mode = isset( $_POST['restriction_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['restriction_mode'] ) ) : 'none';
		$customer_ids     = isset( $_POST['customer_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['customer_ids'] ) ) : array();
		$role_ids         = isset( $_POST['role_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['role_ids'] ) ) : array();

		$result = Customer_Visibility::save_rules( $product_id, $restriction_mode, $role_ids, $customer_ids );

		if ( ! $result['saved'] ) {
			// The message describes the rules that are actually stored now, which
			// is not always the set this request asked for nor the set that was
			// there before.
			wp_send_json_error(
				array(
					'message'  => $result['message'],
					'restored' => $result['restored'],
					'stored'   => $result['stored'],
				)
			);
			return;
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}
}
