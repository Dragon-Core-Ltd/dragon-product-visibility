<?php
/**
 * Installation related functions and actions
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Install Class
 */
class Install {

	/**
	 * Activation hook
	 */
	public static function activate(): void {
		self::create_tables();
		self::create_options();

		// Clear any cached data
		wp_cache_flush();

		// Set flag to show activation notice
		set_transient( 'dpv_activated', true, 30 );
	}

	/**
	 * Deactivation hook
	 */
	public static function deactivate(): void {
		// Clear scheduled events if any
		wp_clear_scheduled_hook( 'dpv_daily_cleanup' );
	}

	/**
	 * Create database tables
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'dpv_customer_visibility';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table creation with dbDelta requires direct SQL.
		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY customer_id (customer_id),
            UNIQUE KEY product_customer (product_id, customer_id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
        // phpcs:enable

		// Store the DB version
		update_option( 'dpv_db_version', DPV_VERSION );
	}

	/**
	 * Create default options
	 */
	private static function create_options(): void {
		$default_options = array(
			'dpv_version'                       => DPV_VERSION,
			'dpv_restriction_mode'              => 'whitelist',
			'dpv_hide_restricted_completely'    => 'yes',
			'dpv_show_message_on_direct_access' => 'yes',
			'dpv_restricted_redirect'           => 'shop',
		);

		foreach ( $default_options as $key => $value ) {
			if ( get_option( $key ) === false ) {
				add_option( $key, $value );
			}
		}
	}

	/**
	 * Check if tables exist
	 */
	public static function tables_exist(): bool {
		global $wpdb;
		$table_name = $wpdb->prefix . 'dpv_customer_visibility';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema check on activation.
		return $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		) === $table_name;
	}
}
