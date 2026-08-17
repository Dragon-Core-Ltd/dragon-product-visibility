<?php
/**
 * Uninstall Dragon Product Visibility
 *
 * Removes all plugin data when uninstalled through WordPress admin.
 *
 * @package DragonProductVisibility
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Respect the site owner's data: nothing is removed unless they explicitly
// opted in (the "Delete all data on uninstall" setting). Without the opt-in,
// tables and options survive so a reinstall picks up exactly where it left off.
if ( ! get_option( 'dpv_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// Drop the customer visibility table.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'dpv_customer_visibility' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Table removal on uninstall.

// Delete all plugin options (current namespace-derived prefix and the
// pre-1.0.1 dpv_ prefix, in case an install never ran the migration).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dragonproductvisibility\_%' OR option_name LIKE 'dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all post meta. Meta keys were deliberately left on the dpv_ prefix
// (meta keys are matched by exact name), so both forms are removed here.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'dpv\_%' OR meta_key LIKE '\_dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all user meta.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete any transients (both prefixes).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_dragonproductvisibility\_%' OR option_name LIKE '%\_transient\_dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_timeout\_dragonproductvisibility\_%' OR option_name LIKE '%\_transient\_timeout\_dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
