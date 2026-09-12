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
		set_transient( 'dragonproductvisibility_activated', true, 30 );
	}

	/**
	 * Deactivation hook
	 */
	public static function deactivate(): void {
		// Clear scheduled events if any
		wp_clear_scheduled_hook( 'dragonproductvisibility_daily_cleanup' );
	}

	/**
	 * Transient marking that the table was confirmed present recently.
	 */
	const TABLE_CHECKED_TRANSIENT = 'dragonproductvisibility_table_checked';

	/**
	 * Create the table if it is out of date or missing. Runs on admin loads so
	 * a creation that failed during activation, or a table dropped later, is
	 * repaired. The existence check is rate-limited so SHOW TABLES does not run
	 * on every request.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'dragonproductvisibility_db_version' ) !== DRAGONPRODUCTVISIBILITY_VERSION ) {
			self::create_tables();
			return;
		}

		if ( get_transient( self::TABLE_CHECKED_TRANSIENT ) ) {
			return;
		}

		if ( self::tables_exist() || self::create_tables() ) {
			set_transient( self::TABLE_CHECKED_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Create database tables. The schema version is only stamped once the
	 * table is confirmed present, so a failed creation is retried later.
	 *
	 * @return bool True when the table exists afterwards.
	 */
	public static function create_tables(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'dpv_customer_visibility';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table creation with dbDelta requires direct SQL.
		// Plain CREATE TABLE with dbDelta's two-space PRIMARY KEY form, so dbDelta
		// recognises the table name and diffs an existing table instead of
		// re-running the raw statement.
		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY customer_id (customer_id),
            UNIQUE KEY product_customer (product_id, customer_id)
        ) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
        // phpcs:enable

		// dbDelta reports what it tried, not whether it worked.
		if ( ! self::tables_exist() ) {
			return false;
		}

		// Store the DB version. update_option() returns false for an unchanged
		// value as well as a failed write, so the stored value is read back.
		update_option( 'dragonproductvisibility_db_version', DRAGONPRODUCTVISIBILITY_VERSION );

		return get_option( 'dragonproductvisibility_db_version' ) === DRAGONPRODUCTVISIBILITY_VERSION;
	}

	/**
	 * Create default options
	 */
	private static function create_options(): void {
		$default_options = array(
			'dragonproductvisibility_version'                       => DRAGONPRODUCTVISIBILITY_VERSION,
			'dragonproductvisibility_restriction_mode'              => 'whitelist',
			'dragonproductvisibility_hide_restricted_completely'    => 'yes',
			'dragonproductvisibility_show_message_on_direct_access' => 'yes',
			'dragonproductvisibility_restricted_redirect'           => 'shop',
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check before stamping the version or writing rules.
		return $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		) === $table_name;
	}
}
