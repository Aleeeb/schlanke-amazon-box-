<?php
/**
 * Removes all data and settings when the plugin is deleted.
 *
 * @package OneAB_Stats
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'oneab_stats' ) );
delete_option( 'oneab_stats_settings' );
delete_option( 'oneab_stats_salt' );
delete_option( 'oneab_stats_db' );
wp_clear_scheduled_hook( 'oneab_stats_cleanup' );
