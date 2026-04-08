<?php
/**
 * Plugin deactivator class.
 *
 * Handles cleanup tasks that must run when the plugin is deactivated through
 * the WordPress admin: capability removal, cron unscheduling, and transient
 * purging. This is intentionally lightweight — full data removal (tables and
 * options) happens only on uninstall via uninstall.php.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_Deactivator
 *
 * Contains the static deactivation callback registered via
 * register_deactivation_hook().
 *
 * @since 1.0.0
 */
class WST_Deactivator {

	/**
	 * Run all deactivation routines.
	 *
	 * Called automatically by WordPress when an admin deactivates the plugin
	 * through the Plugins screen or via WP-CLI. The method is intentionally
	 * non-destructive: it does not drop database tables or delete options so
	 * that data is preserved if the plugin is reactivated later.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		// -----------------------------------------------------------------------
		// 1. Remove plugin-specific capabilities from the Administrator role.
		//    This prevents stale capability checks if the plugin is later deleted
		//    without going through the normal uninstall flow.
		// -----------------------------------------------------------------------
		WST_Capabilities::remove_capabilities();

		// -----------------------------------------------------------------------
		// 2. Unschedule the daily log-cleanup cron event.
		//    wp_next_scheduled() returns false when the event is not registered,
		//    so this block is safe to call even if the event never fired.
		// -----------------------------------------------------------------------
		$timestamp = wp_next_scheduled( 'wst_cleanup_logs' );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'wst_cleanup_logs' );
		}

		// -----------------------------------------------------------------------
		// 3. Delete all wst_* transients so no stale cached data lingers after
		//    deactivation.
		// -----------------------------------------------------------------------
		self::delete_wst_transients();
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Remove every transient whose name begins with 'wst_'.
	 *
	 * WordPress stores transients in the options table under the key pattern
	 * `_transient_{name}`. We query that table directly to find all WST
	 * transients, then call delete_transient() for each so that the object
	 * cache is also invalidated.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @return void
	 */
	private static function delete_wst_transients() {
		global $wpdb;

		// Retrieve all option names that match the WST transient key pattern.
		// We only query for the value rows (not the _timeout_ rows) because
		// delete_transient() removes both rows automatically.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name
				 FROM {$wpdb->options}
				 WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_wst_' ) . '%'
			)
		);

		if ( empty( $transient_option_names ) ) {
			return;
		}

		foreach ( $transient_option_names as $option_name ) {
			// Strip the '_transient_' prefix to obtain the raw transient name
			// that delete_transient() expects.
			$transient_name = substr( $option_name, strlen( '_transient_' ) );
			delete_transient( $transient_name );
		}
	}
}
