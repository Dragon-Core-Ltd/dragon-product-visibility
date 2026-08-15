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

		// Get visibility rules AJAX
		add_action( 'wp_ajax_dragonproductvisibility_get_visibility_rules', array( $this, 'get_visibility_rules' ) );
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

		// Check permissions
		if ( ! current_user_can( 'edit_products' ) ) {
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

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID', 'dragon-product-visibility' ) ) );
			return;
		}

		// Get submitted data
		$restriction_mode = isset( $_POST['restriction_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['restriction_mode'] ) ) : 'none';
		$customer_ids     = isset( $_POST['customer_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['customer_ids'] ) ) : array();
		$role_ids         = isset( $_POST['role_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['role_ids'] ) ) : array();

		// Save restriction mode
		update_post_meta( $product_id, '_dpv_restriction_mode', $restriction_mode );

		// Save roles
		update_post_meta( $product_id, '_dpv_visible_roles', $role_ids );

		// Save customer-specific visibility
		$this->save_customer_visibility( $product_id, $customer_ids );

		wp_send_json_success( array( 'message' => __( 'Visibility rules saved', 'dragon-product-visibility' ) ) );
	}

	/**
	 * Save customer visibility to custom table
	 *
	 * @param int   $product_id   Product ID
	 * @param array $customer_ids Array of customer IDs
	 */
	private function save_customer_visibility( int $product_id, array $customer_ids ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'dpv_customer_visibility';

		// Delete existing rules for this product
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Clearing existing rules before save.
		$wpdb->delete( $table_name, array( 'product_id' => $product_id ), array( '%d' ) );

		// Insert new rules
		if ( ! empty( $customer_ids ) ) {
			foreach ( $customer_ids as $customer_id ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserting customer visibility rules.
				$wpdb->insert(
					$table_name,
					array(
						'product_id'  => $product_id,
						'customer_id' => $customer_id,
					),
					array( '%d', '%d' )
				);
			}
		}
	}

	/**
	 * Get visibility rules via AJAX
	 */
	public function get_visibility_rules(): void {
		// Verify nonce
		if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'dragonproductvisibility_admin_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'dragon-product-visibility' ) ) );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'dragon-product-visibility' ) ) );
			return;
		}

		$product_id = isset( $_REQUEST['product_id'] ) ? absint( $_REQUEST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID', 'dragon-product-visibility' ) ) );
			return;
		}

		global $wpdb;

		// Get restriction mode
		$restriction_mode = get_post_meta( $product_id, '_dpv_restriction_mode', true );
		if ( ! $restriction_mode ) {
			$restriction_mode = 'none';
		}

		// Get roles
		$roles = get_post_meta( $product_id, '_dpv_visible_roles', true );
		if ( ! is_array( $roles ) ) {
			$roles = array();
		}

		// Get customers from custom table
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetching visibility rules for product.
		$customers = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT customer_id FROM %i WHERE product_id = %d',
				$wpdb->prefix . 'dpv_customer_visibility',
				$product_id
			)
		);

		// Get customer details for display
		$customer_details = array();
		if ( ! empty( $customers ) ) {
			foreach ( $customers as $customer_id ) {
				$user = get_userdata( $customer_id );
				if ( $user ) {
					$customer_details[] = array(
						'id'   => $customer_id,
						'text' => $user->display_name . ' (' . $user->user_email . ')',
					);
				}
			}
		}

		wp_send_json_success(
			array(
				'restriction_mode' => $restriction_mode,
				'roles'            => $roles,
				'customers'        => $customer_details,
			)
		);
	}
}
