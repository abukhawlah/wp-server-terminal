<?php
/**
 * Plugin activator class.
 *
 * Handles all one-time setup tasks that must run when the plugin is first
 * activated: environment checks, database table creation, default options,
 * capability registration, and cron scheduling.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_Activator
 *
 * Contains the static activation callback registered via
 * register_activation_hook().
 *
 * @since 1.0.0
 */
class WST_Activator {

	/**
	 * The target database schema version for this plugin release.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Run all activation routines.
	 *
	 * Called by WordPress when the plugin is activated through the admin UI or
	 * via WP-CLI. Returns false (and stores an admin notice transient) when the
	 * environment does not meet minimum requirements — notably when the server
	 * OS is not Linux.
	 *
	 * @since  1.0.0
	 *
	 * @return bool True on success, false when requirements are not met.
	 */
	public static function activate() {
		// -----------------------------------------------------------------------
		// 1. Require a Linux OS — the terminal and file-manager modules use
		//    Linux-specific shell utilities (bash, proc filesystem, etc.).
		// -----------------------------------------------------------------------
		if ( ! self::is_linux() ) {
			set_transient(
				'wst_activation_error',
				__(
					'WP Server Terminal requires a Linux server. The plugin has been deactivated.',
					'wp-server-terminal'
				),
				30
			);
			return false;
		}

		// -----------------------------------------------------------------------
		// 2. Create (or upgrade) the audit log database table.
		// -----------------------------------------------------------------------
		self::create_tables();

		// -----------------------------------------------------------------------
		// 3. Persist default plugin options (only if they do not already exist so
		//    re-activation does not overwrite user-configured values).
		// -----------------------------------------------------------------------
		self::set_default_options();

		// -----------------------------------------------------------------------
		// 4. Record the current database schema version and first-run timestamp.
		// -----------------------------------------------------------------------
		update_option( 'wst_db_version', self::DB_VERSION, false );

		if ( ! get_option( 'wst_first_run' ) ) {
			update_option( 'wst_first_run', time(), false );
		}

		// -----------------------------------------------------------------------
		// 5. Register plugin-specific capabilities on the Administrator role.
		// -----------------------------------------------------------------------
		WST_Capabilities::add_capabilities();

		// -----------------------------------------------------------------------
		// 6. Schedule the daily log-cleanup cron event (idempotent).
		// -----------------------------------------------------------------------
		if ( ! wp_next_scheduled( 'wst_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'wst_cleanup_logs' );
		}

		return true;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Determine whether the current server is running Linux.
	 *
	 * Uses the PHP_OS_FAMILY constant (PHP 7.2+) when available and falls back
	 * to a case-insensitive prefix check on PHP_OS for older environments.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @return bool True when the OS is Linux.
	 */
	private static function is_linux() {
		if ( defined( 'PHP_OS_FAMILY' ) ) {
			return 'Linux' === PHP_OS_FAMILY;
		}

		// PHP_OS may be 'Linux', 'linux', or a variant — compare case-insensitively.
		return ( 0 === strncasecmp( PHP_OS, 'Linux', 5 ) );
	}

	/**
	 * Create or upgrade the audit log database table using dbDelta().
	 *
	 * dbDelta() is idempotent: it only alters the table when the schema differs
	 * from the existing table, so it is safe to call on every activation.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . 'wst_audit_log';
		$charset_collate = $wpdb->get_charset_collate();

		// NOTE: dbDelta() is strict about formatting — two spaces after PRIMARY KEY
		// and each KEY definition, and no trailing comma on the last field before
		// the closing parenthesis.
		$sql = "CREATE TABLE {$table_name} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_ip varchar(45) NOT NULL DEFAULT '',
  action_type varchar(50) NOT NULL DEFAULT '',
  command text NOT NULL,
  result_status varchar(20) NOT NULL DEFAULT 'success',
  severity varchar(10) NOT NULL DEFAULT 'info',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY action_type (action_type),
  KEY created_at (created_at)
) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Persist default option values when they do not already exist.
	 *
	 * Using add_option() ensures that an existing user-configured value is never
	 * overwritten during plugin re-activation.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @return void
	 */
	private static function set_default_options() {
		$defaults = array(
			/**
			 * Idle session timeout in seconds (default: 30 minutes).
			 *
			 * @var int
			 */
			'session_timeout'   => 1800,

			/**
			 * Shell commands that are blocked outright, regardless of the user's
			 * capabilities. These patterns are matched as substrings.
			 *
			 * @var string[]
			 */
			'blocked_commands'  => array(
				'rm -rf /',
				'mkfs',
				'dd if=',
				':(){ :|:& };:',
				'shutdown',
				'reboot',
				'halt',
				'poweroff',
				'init 0',
				'init 6',
			),

			/**
			 * Maximum number of bytes captured from a command's output before the
			 * output is truncated (default: 1 MiB).
			 *
			 * @var int
			 */
			'max_output_size'   => 1048576,

			/**
			 * Whether the WP-CLI tab is available to authorised users.
			 *
			 * @var bool
			 */
			'enable_wpcli'      => true,

			/**
			 * Whether the File Manager tab is available.
			 *
			 * @var bool
			 */
			'enable_file_manager' => true,

			/**
			 * Whether the Database Manager tab is available.
			 *
			 * @var bool
			 */
			'enable_db_manager' => true,

			/**
			 * Number of days before audit log entries are automatically pruned.
			 *
			 * @var int
			 */
			'log_retention_days' => 90,

			/**
			 * Absolute server paths that the file manager may access.
			 * Defaults to the WordPress root directory.
			 *
			 * @var string[]
			 */
			'allowed_dirs'      => array( ABSPATH ),
		);

		// add_option() is a no-op when the key already exists — exactly the
		// behaviour we want here.
		add_option( 'wst_settings', $defaults, '', false );
	}
}
