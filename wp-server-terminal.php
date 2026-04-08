<?php
/**
 * WP Server Terminal
 *
 * @package           WP_Server_Terminal
 * @author            Asif Amod
 * @copyright         2024 Asif Amod
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       WP Server Terminal
 * Plugin URI:        https://github.com/abukhawlah/wp-server-terminal
 * Description:       Gives WordPress admins SSH-like access: shell terminal, WP-CLI, file manager, database manager, and audit log — all from the WordPress admin.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Asif Amod
 * Author URI:        https://github.com/abukhawlah
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-server-terminal
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * @var string
 */
define( 'WST_VERSION', '1.0.0' );

/**
 * Absolute path to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'WST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Full URL to the plugin directory, with trailing slash.
 *
 * @var string
 */
define( 'WST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename (e.g. "wp-server-terminal/wp-server-terminal.php").
 *
 * @var string
 */
define( 'WST_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ---------------------------------------------------------------------------
// PHP version check — must happen before any other plugin code is loaded.
// ---------------------------------------------------------------------------

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	/**
	 * Show an admin notice when the server PHP version is too old.
	 *
	 * Hooked early so it fires even before the plugin is fully bootstrapped.
	 *
	 * @return void
	 */
	function wst_php_version_notice() {
		$message = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( '<strong>WP Server Terminal</strong> requires PHP %1$s or higher. Your server is running PHP %2$s. The plugin has been deactivated.', 'wp-server-terminal' ),
			'7.4',
			PHP_VERSION
		);
		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
	}
	add_action( 'admin_notices', 'wst_php_version_notice' );

	// Deactivate the plugin gracefully.
	add_action(
		'admin_init',
		function () {
			deactivate_plugins( WST_PLUGIN_BASENAME );
		}
	);

	return;
}

// ---------------------------------------------------------------------------
// WordPress version check.
// ---------------------------------------------------------------------------

global $wp_version;

if ( version_compare( $wp_version, '6.0', '<' ) ) {
	/**
	 * Show an admin notice when the WordPress version is too old.
	 *
	 * @return void
	 */
	function wst_wp_version_notice() {
		global $wp_version;
		$message = sprintf(
			/* translators: 1: required WP version, 2: current WP version */
			__( '<strong>WP Server Terminal</strong> requires WordPress %1$s or higher. Your installation is running WordPress %2$s. The plugin has been deactivated.', 'wp-server-terminal' ),
			'6.0',
			$wp_version
		);
		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
	}
	add_action( 'admin_notices', 'wst_wp_version_notice' );

	add_action(
		'admin_init',
		function () {
			deactivate_plugins( WST_PLUGIN_BASENAME );
		}
	);

	return;
}

// ---------------------------------------------------------------------------
// Load all class files.
// ---------------------------------------------------------------------------

$wst_includes = array(
	'class-activator.php',
	'class-deactivator.php',
	'class-capabilities.php',
	'class-admin-menu.php',
	'class-audit-log.php',
	'class-settings.php',
	'class-security.php',
	'class-terminal.php',
	'class-wpcli.php',
	'class-file-manager.php',
	'class-database-manager.php',
	'class-ajax-handler.php',
);

foreach ( $wst_includes as $wst_file ) {
	require_once WST_PLUGIN_DIR . 'includes/' . $wst_file;
}

// ---------------------------------------------------------------------------
// Activation / deactivation hooks.
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, array( 'WST_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WST_Deactivator', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Bootstrap on init.
// ---------------------------------------------------------------------------

/**
 * Instantiate the plugin's core objects on the 'init' hook.
 *
 * Priority 10 is the WordPress default and ensures all post types, taxonomies,
 * and other early-init registrations are already in place.
 *
 * @return void
 */
function wst_init() {
	new WST_Admin_Menu();
	new WST_Ajax_Handler();
	new WST_Settings();

	// WP-Cron cleanup handler.
	add_action( 'wst_cleanup_logs', array( 'WST_Audit_Log', 'cleanup' ) );

	// Trash cleanup (piggyback on same cron).
	add_action( 'wst_cleanup_logs', array( 'WST_File_Manager', 'clean_trash' ) );
}
add_action( 'init', 'wst_init', 10 );
