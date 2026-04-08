<?php
/**
 * WP-CLI runner class.
 *
 * Wraps WST_Terminal to provide a dedicated execution path for WP-CLI commands.
 * Handles binary detection, path normalisation, root-flag injection, and audit
 * logging so callers can treat WP-CLI commands the same way they treat ordinary
 * shell commands.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_WPCLI
 *
 * Static service layer for discovering and executing WP-CLI commands through
 * the WST_Terminal execution engine.
 *
 * @since 1.0.0
 */
class WST_WPCLI {

	// ---------------------------------------------------------------------------
	// Binary detection
	// ---------------------------------------------------------------------------

	/**
	 * Locate the WP-CLI binary on the current server.
	 *
	 * Resolution order:
	 *  1. WP_CLI_PATH constant (if defined and the file exists).
	 *  2. `which wp` via shell_exec().
	 *  3. Common fixed paths: /usr/local/bin/wp, /usr/bin/wp, ~/bin/wp.
	 *  4. ABSPATH . 'wp-cli.phar'.
	 *
	 * The resolved path is cached in a one-hour transient to avoid redundant
	 * filesystem lookups on every request.
	 *
	 * @since  1.0.0
	 *
	 * @return string|WP_Error Absolute path to the wp binary on success, or a
	 *                         WP_Error with code 'wpcli_not_found' on failure.
	 */
	public static function detect() {
		$cached = get_transient( 'wst_wpcli_path' );

		if ( false !== $cached ) {
			return $cached;
		}

		$candidates = array();

		// 1. Developer-supplied constant.
		if ( defined( 'WP_CLI_PATH' ) && file_exists( WP_CLI_PATH ) ) {
			$candidates[] = WP_CLI_PATH;
		}

		// 2. PATH-based lookup via which.
		$which = shell_exec( 'which wp 2>/dev/null' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( ! empty( $which ) ) {
			$candidates[] = trim( $which );
		}

		// 3. Common fixed paths.
		$home         = isset( $_SERVER['HOME'] ) ? $_SERVER['HOME'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$candidates[] = '/usr/local/bin/wp';
		$candidates[] = '/usr/bin/wp';

		if ( '' !== $home ) {
			$candidates[] = $home . '/bin/wp';
		}

		// 4. wp-cli.phar bundled alongside WordPress.
		$candidates[] = ABSPATH . 'wp-cli.phar';

		foreach ( $candidates as $path ) {
			if ( '' !== $path && is_executable( $path ) ) {
				set_transient( 'wst_wpcli_path', $path, HOUR_IN_SECONDS );
				return $path;
			}
		}

		return new WP_Error(
			'wpcli_not_found',
			'WP-CLI not found. Install from https://wp-cli.org'
		);
	}

	// ---------------------------------------------------------------------------
	// Availability check
	// ---------------------------------------------------------------------------

	/**
	 * Determine whether WP-CLI is available on this server.
	 *
	 * @since  1.0.0
	 *
	 * @return bool True when a usable wp binary was found, false otherwise.
	 */
	public static function is_available() {
		return ! is_wp_error( self::detect() );
	}

	// ---------------------------------------------------------------------------
	// Command execution
	// ---------------------------------------------------------------------------

	/**
	 * Execute a WP-CLI command through WST_Terminal.
	 *
	 * The leading 'wp ' prefix is stripped from $command if present so that
	 * callers may pass either 'plugin list' or 'wp plugin list' interchangeably.
	 * --path and --no-color flags are appended automatically. --allow-root is
	 * appended when PHP is running as the root OS user.
	 *
	 * Every execution attempt is recorded in the audit log via WST_Audit_Log.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $command WP-CLI subcommand (with or without leading 'wp ').
	 * @return array|WP_Error  Same array structure as WST_Terminal::execute() on
	 *                         success, or a WP_Error when the binary cannot be found.
	 */
	public static function execute( $command ) {
		$wpcli_path = self::detect();

		if ( is_wp_error( $wpcli_path ) ) {
			return $wpcli_path;
		}

		// Normalise: strip leading 'wp ' so the binary path is not duplicated.
		if ( 0 === strpos( $command, 'wp ' ) ) {
			$command = substr( $command, 3 );
		}

		// Build the full shell command.
		$full_command = "{$wpcli_path} {$command} --path=" . escapeshellarg( ABSPATH ) . ' --no-color';

		// WP-CLI refuses to run as root unless explicitly permitted.
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$full_command .= ' --allow-root';
		}

		$result = WST_Terminal::execute( $full_command, ABSPATH );

		// Record the command in the audit log with a [WP-CLI] prefix.
		$status = ( isset( $result['exit_code'] ) && 0 === $result['exit_code'] ) ? 'success' : 'failure';
		WST_Audit_Log::log( WST_Audit_Log::ACTION_WPCLI, '[WP-CLI] ' . $command, $status );

		return $result;
	}

	// ---------------------------------------------------------------------------
	// Introspection helpers
	// ---------------------------------------------------------------------------

	/**
	 * Return the installed WP-CLI version string.
	 *
	 * @since  1.0.0
	 *
	 * @return string|null Version string (e.g. 'WP-CLI 2.9.0') on success, null on failure.
	 */
	public static function get_version() {
		$result = self::execute( '--version' );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		if ( isset( $result['exit_code'] ) && 0 === $result['exit_code'] && ! empty( $result['stdout'] ) ) {
			return trim( $result['stdout'] );
		}

		return null;
	}

	/**
	 * Return the list of top-level WP-CLI commands available on this installation.
	 *
	 * The result is cached in a six-hour transient to avoid the overhead of
	 * spawning a WP-CLI process on every page load.
	 *
	 * @since  1.0.0
	 *
	 * @return array Decoded JSON array of command descriptors, or an empty array
	 *               when WP-CLI is unavailable or returns invalid output.
	 */
	public static function get_available_commands() {
		$cached = get_transient( 'wst_wpcli_commands' );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = self::execute( 'help --format=json' );

		if ( is_wp_error( $result ) ) {
			return array();
		}

		if ( isset( $result['exit_code'] ) && 0 === $result['exit_code'] && ! empty( $result['stdout'] ) ) {
			$decoded = json_decode( $result['stdout'], true );

			if ( is_array( $decoded ) ) {
				set_transient( 'wst_wpcli_commands', $decoded, 6 * HOUR_IN_SECONDS );
				return $decoded;
			}
		}

		return array();
	}
}
