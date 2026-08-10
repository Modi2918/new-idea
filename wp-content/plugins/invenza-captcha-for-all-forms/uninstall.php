<?php
/**
 * Uninstall Invcaf CAPTCHA plugin.
 *
 * @package Invcaf
 *
 * Fired when the plugin is uninstalled.
 *
 * Deletes settings, transients, custom database tables,
 * and license options if permitted by settings.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Clean up plugin data for a single site.
 *
 * @param wpdb $wpdb WordPress database abstraction object.
 */
function invcaf_uninstall_site_data( $wpdb ) {
	$settings = get_option( 'invcaf_settings', get_option( 'invenza_captcha_settings', array() ) );

	// Only delete if the user explicitly enabled "Delete data on uninstall".
	if ( ! empty( $settings['delete_on_uninstall'] ) ) {

		// Remove settings and verification records.
		delete_option( 'invcaf_settings' );
		delete_option( 'invenza_captcha_settings' );
		delete_option( 'invcaf_last_verification' );
		delete_option( 'invenza_captcha_last_verification' );

		// Drop the events/logs table (table name: invcaf_events & legacy invenza_captcha_events).
		$table_events        = esc_sql( $wpdb->prefix . 'invcaf_events' );
		$table_events_legacy = esc_sql( $wpdb->prefix . 'invenza_captcha_events' );

		// Direct query required to drop custom table on uninstall. No cache needed. Table names cannot be prepared.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_events}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_events_legacy}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		// Direct query required to bulk delete transients via wildcard LIKE. No cache needed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( '_transient_invcaf_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_invcaf_' ) . '%',
				$wpdb->esc_like( '_transient_invenza_captcha_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_invenza_captcha_' ) . '%'
			)
		);
	}
}

if ( is_multisite() ) {
	$invcaf_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	if ( ! empty( $invcaf_site_ids ) ) {
		foreach ( $invcaf_site_ids as $invcaf_site_id ) {
			switch_to_blog( $invcaf_site_id );
			invcaf_uninstall_site_data( $wpdb );
			restore_current_blog();
		}
	}
} else {
	invcaf_uninstall_site_data( $wpdb );
}
