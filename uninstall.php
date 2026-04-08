<?php
/**
 * Uninstall routine for WP Server Terminal.
 *
 * This file is executed automatically by WordPress when the plugin is deleted
 * through the "Plugins" screen. It removes all data the plugin has created:
 * the audit log database table, plugin options, and any transients.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

// Exit immediately if WordPress did not trigger this uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ---------------------------------------------------------------------------
// 1. Drop the audit log table.
// ---------------------------------------------------------------------------

$audit_log_table = $wpdb->prefix . 'wst_audit_log';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	"DROP TABLE IF EXISTS `{$audit_log_table}`"
);

// ---------------------------------------------------------------------------
// 2. Delete plugin options.
// ---------------------------------------------------------------------------

$wst_options = array(
	'wst_settings',
	'wst_db_version',
	'wst_first_run',
);

foreach ( $wst_options as $option_name ) {
	delete_option( $option_name );
}

// ---------------------------------------------------------------------------
// 3. Delete all wst_* transients.
//
// WordPress stores transients in the options table with the key pattern:
//   _transient_{name}          (value)
//   _transient_timeout_{name}  (expiry timestamp)
//
// We query both prefixes so we remove every trace of WST transient data.
// ---------------------------------------------------------------------------

// Collect transient option names whose base name begins with 'wst_'.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$transient_rows = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name
		 FROM {$wpdb->options}
		 WHERE option_name LIKE %s
		    OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wst_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wst_' ) . '%'
	)
);

if ( ! empty( $transient_rows ) ) {
	foreach ( $transient_rows as $option_name ) {
		// Strip the leading '_transient_' or '_transient_timeout_' prefix so we
		// can call delete_transient() with the original transient name, which
		// handles cache invalidation in addition to the database deletion.
		if ( 0 === strpos( $option_name, '_transient_timeout_' ) ) {
			// Timeout row — handled automatically when the value row is deleted.
			continue;
		}

		$transient_name = substr( $option_name, strlen( '_transient_' ) );
		delete_transient( $transient_name );
	}
}
