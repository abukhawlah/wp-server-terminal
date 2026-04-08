<?php
/**
 * Capability management class.
 *
 * Registers, grants, and revokes the custom `manage_server_terminal`
 * capability that gates access to every feature exposed by this plugin.
 * All methods are static so the class acts as a simple namespace without
 * requiring instantiation.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_Capabilities
 *
 * Centralises all capability management for the WP Server Terminal plugin.
 *
 * @since 1.0.0
 */
class WST_Capabilities {

	// ---------------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------------

	/**
	 * Grant the custom capability to the appropriate roles/users.
	 *
	 * On a standard (single-site) install the capability is added to the built-in
	 * `administrator` role. On a multisite network-activated install, the
	 * capability is additionally granted to super admins via the
	 * `grant_super_admin` action so they retain access across all sites.
	 *
	 * This method is idempotent — calling it when the capability already exists
	 * on a role is a safe no-op.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public static function add_capabilities() {
		// Grant to the administrator role on the current site.
		$administrator = get_role( 'administrator' );

		if ( $administrator instanceof WP_Role ) {
			$administrator->add_cap( 'manage_server_terminal' );
		}

		// On multisite, also ensure super admins receive the capability via the
		// grant_super_admin hook so the user meta is populated on every site.
		if ( is_multisite() ) {
			$super_admins = get_super_admins();

			foreach ( $super_admins as $login ) {
				$user = get_user_by( 'login', $login );

				if ( $user instanceof WP_User ) {
					$user->add_cap( 'manage_server_terminal' );
				}
			}
		}
	}

	/**
	 * Remove the custom capability from every role registered in WordPress.
	 *
	 * Iterates over all role slugs known to `wp_roles()` and strips the
	 * `manage_server_terminal` capability so that no leftover permission remains
	 * after the plugin is deactivated or uninstalled.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public static function remove_capabilities() {
		$wp_roles = wp_roles();

		foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );

			if ( $role instanceof WP_Role ) {
				$role->remove_cap( 'manage_server_terminal' );
			}
		}
	}

	/**
	 * Check whether the currently logged-in user holds the custom capability.
	 *
	 * Wraps the native `current_user_can()` check so that all capability
	 * decisions are routed through a single authoritative method. This makes
	 * future changes (e.g. adding context or multisite checks) trivial.
	 *
	 * @since  1.0.0
	 *
	 * @return bool True when the current user has the `manage_server_terminal`
	 *              capability, false otherwise.
	 */
	public static function current_user_can() {
		return current_user_can( 'manage_server_terminal' );
	}

	/**
	 * Return the capability string used throughout the plugin.
	 *
	 * Providing a single source of truth for the capability slug means that
	 * renaming it in the future requires editing only this one method.
	 *
	 * @since  1.0.0
	 *
	 * @return string The custom capability slug.
	 */
	public static function get_capability() {
		return 'manage_server_terminal';
	}
}
