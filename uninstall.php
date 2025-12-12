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

global $wpdb;

// Drop the customer visibility table.
$table_name = $wpdb->prefix . 'dpv_customer_visibility';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Delete all plugin options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all post meta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all user meta.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete any transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%\_transient\_timeout\_dpv\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
