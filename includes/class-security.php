<?php
/**
 * Security class.
 *
 * Centralises every security concern for the WP Server Terminal plugin:
 * nonce verification, capability checks, IP allow-listing, rate limiting,
 * re-authentication, command sanitisation, and path validation.
 *
 * All methods are static so the class acts as a stateless security layer that
 * can be called from any context without instantiation.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_Security
 *
 * Handles all security checks and sanitisation routines for the plugin.
 *
 * @since 1.0.0
 */
class WST_Security {

	// ---------------------------------------------------------------------------
	// Constants
	// ---------------------------------------------------------------------------

	/**
	 * The action string used when creating and verifying nonces.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const NONCE_ACTION = 'wst_nonce';

	/**
	 * Transient key prefix for re-authentication sessions.
	 *
	 * The full key is this prefix concatenated with the user ID.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const REAUTH_TRANSIENT_PREFIX = 'wst_reauth_';

	/**
	 * Transient key prefix for brute-force lockout records.
	 *
	 * The full key is this prefix concatenated with the user ID.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const LOCKOUT_TRANSIENT_PREFIX = 'wst_lockout_';

	/**
	 * Transient key prefix for per-user rate-limit counters.
	 *
	 * The full key is this prefix concatenated with the user ID.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const RATE_TRANSIENT_PREFIX = 'wst_rate_';

	// ---------------------------------------------------------------------------
	// AJAX / Request Verification
	// ---------------------------------------------------------------------------

	/**
	 * Verify an incoming AJAX request against all security layers.
	 *
	 * Performs four sequential checks and terminates the request immediately
	 * with an appropriate JSON error response if any check fails:
	 *
	 *  1. Nonce validation (`wst_nonce`).
	 *  2. Custom capability check (`manage_server_terminal`).
	 *  3. IP allow-list check.
	 *  4. Per-user rate-limit check.
	 *
	 * @since  1.0.0
	 *
	 * @return true Returns true only when all checks pass.
	 */
	public static function verify_ajax_request() {
		// -----------------------------------------------------------------------
		// 1. Nonce check — guards against CSRF.
		// -----------------------------------------------------------------------
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => 'Security check failed.',
				),
				403
			);
			die;
		}

		// -----------------------------------------------------------------------
		// 2. Capability check — only authorised users may proceed.
		// -----------------------------------------------------------------------
		if ( ! WST_Capabilities::current_user_can() ) {
			wp_send_json_error(
				array(
					'code'    => 'permission_denied',
					'message' => 'Permission denied.',
				),
				403
			);
			die;
		}

		// -----------------------------------------------------------------------
		// 3. IP allow-list check — blocks requests from unlisted addresses.
		// -----------------------------------------------------------------------
		if ( ! static::check_ip_allowlist() ) {
			wp_send_json_error(
				array(
					'code'    => 'ip_denied',
					'message' => 'Your IP is not allowed.',
				),
				403
			);
			die;
		}

		// -----------------------------------------------------------------------
		// 4. Rate-limit check — throttles excessive requests per user.
		// -----------------------------------------------------------------------
		if ( ! static::check_rate_limit() ) {
			wp_send_json_error(
				array(
					'code'    => 'rate_limited',
					'message' => 'Too many requests.',
				),
				429
			);
			die;
		}

		return true;
	}

	// ---------------------------------------------------------------------------
	// Re-authentication
	// ---------------------------------------------------------------------------

	/**
	 * Determine whether the current user has an active re-authentication session.
	 *
	 * Reads the re-auth transient for the current user and casts the stored
	 * value to a boolean. The transient is absent (returns false) when the
	 * session has expired or was never created.
	 *
	 * @since  1.0.0
	 *
	 * @return bool True when an active re-auth session exists for the current user.
	 */
	public static function is_reauthed() {
		$user_id = get_current_user_id();

		return (bool) get_transient( self::REAUTH_TRANSIENT_PREFIX . $user_id );
	}

	/**
	 * Verify a plaintext password against the current user's stored hash.
	 *
	 * On success a re-authentication transient is created with the configured
	 * session timeout (defaulting to 1 800 seconds / 30 minutes) and any
	 * outstanding failed-attempt counter is cleared.
	 *
	 * On failure the failed-attempt counter is incremented. After five
	 * consecutive failures a lockout transient is set for 900 seconds (15
	 * minutes), preventing further attempts.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $password The plaintext password submitted by the user.
	 * @return true|WP_Error    True on success; WP_Error on failure or lockout.
	 */
	public static function verify_reauth( $password ) {
		$user    = wp_get_current_user();
		$user_id = (int) $user->ID;

		// -----------------------------------------------------------------------
		// Reject immediately if the user is currently locked out.
		// -----------------------------------------------------------------------
		if ( get_transient( self::LOCKOUT_TRANSIENT_PREFIX . $user_id ) ) {
			return new WP_Error(
				'locked_out',
				'Too many failed attempts. Try again later.'
			);
		}

		// -----------------------------------------------------------------------
		// Validate the supplied password against the stored hash.
		// -----------------------------------------------------------------------
		if ( wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			// Success: retrieve the configured session timeout.
			$settings        = get_option( 'wst_settings', array() );
			$session_timeout = isset( $settings['session_timeout'] ) ? (int) $settings['session_timeout'] : 1800;

			// Store the re-auth session and clear any previous failure counter.
			set_transient( self::REAUTH_TRANSIENT_PREFIX . $user_id, true, $session_timeout );
			delete_transient( 'wst_auth_fails_' . $user_id );

			return true;
		}

		// -----------------------------------------------------------------------
		// Failure path: increment the brute-force counter (15-minute TTL).
		// -----------------------------------------------------------------------
		$fails_key = 'wst_auth_fails_' . $user_id;
		$fails     = (int) get_transient( $fails_key );
		$fails++;

		set_transient( $fails_key, $fails, 15 * MINUTE_IN_SECONDS );

		// After 5 consecutive failures, lock the account for 15 minutes.
		if ( $fails >= 5 ) {
			set_transient( self::LOCKOUT_TRANSIENT_PREFIX . $user_id, true, 15 * MINUTE_IN_SECONDS );
		}

		return new WP_Error( 'invalid_password', 'Invalid password.' );
	}

	/**
	 * Extend the current user's re-authentication session TTL.
	 *
	 * When the user is actively re-authenticated this method refreshes the
	 * transient so that idle-timeout only fires after a true period of
	 * inactivity rather than after a fixed wall-clock interval.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public static function extend_reauth_session() {
		if ( ! static::is_reauthed() ) {
			return;
		}

		$user_id         = get_current_user_id();
		$settings        = get_option( 'wst_settings', array() );
		$session_timeout = isset( $settings['session_timeout'] ) ? (int) $settings['session_timeout'] : 1800;

		set_transient( self::REAUTH_TRANSIENT_PREFIX . $user_id, true, $session_timeout );
	}

	/**
	 * Terminate the current AJAX request if the user is not re-authenticated.
	 *
	 * Should be called at the top of any AJAX handler that requires the user to
	 * have recently confirmed their password.
	 *
	 * @since  1.0.0
	 *
	 * @return void Sends a JSON error response and exits when re-auth is absent.
	 */
	public static function check_reauth_or_die() {
		if ( ! static::is_reauthed() ) {
			wp_send_json_error(
				array(
					'code'    => 'reauth_required',
					'message' => 'Re-authentication required.',
				),
				403
			);
			die;
		}
	}

	// ---------------------------------------------------------------------------
	// Sanitisation
	// ---------------------------------------------------------------------------

	/**
	 * Sanitise a shell command string before it is executed.
	 *
	 * Performs the following operations in order:
	 *  1. Trim leading/trailing whitespace.
	 *  2. Strip null bytes (potential injection vector).
	 *  3. Reject the command if it contains a blocked pattern from the plugin
	 *     settings. Matching is case-insensitive and substring-based.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $cmd The raw command string supplied by the user.
	 * @return string|WP_Error Sanitised command string, or WP_Error when the
	 *                         command matches a blocked pattern.
	 */
	public static function sanitize_command( $cmd ) {
		// Trim whitespace and strip null bytes.
		$cmd = trim( $cmd );
		$cmd = str_replace( "\0", '', $cmd );

		// Retrieve the list of blocked command patterns from plugin settings.
		$settings         = get_option( 'wst_settings', array() );
		$blocked_commands = isset( $settings['blocked_commands'] ) ? (array) $settings['blocked_commands'] : array();

		foreach ( $blocked_commands as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}

			if ( false !== stripos( $cmd, $pattern ) ) {
				return new WP_Error(
					'command_blocked',
					'Command is not allowed: ' . esc_html( $pattern )
				);
			}
		}

		return $cmd;
	}

	/**
	 * Sanitise and validate a filesystem path.
	 *
	 * Resolves the supplied path to its canonical absolute form via `realpath()`
	 * and verifies that it falls within one of the directories permitted by the
	 * plugin settings. This prevents directory-traversal attacks.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $path The raw filesystem path supplied by the user.
	 * @return string|WP_Error Resolved canonical path on success, or WP_Error
	 *                         when the path does not exist or is not permitted.
	 */
	public static function sanitize_path( $path ) {
		// Resolve to a canonical absolute path; returns false for non-existent paths.
		$resolved = realpath( $path );

		if ( false === $resolved ) {
			return new WP_Error( 'invalid_path', 'Path does not exist.' );
		}

		// Retrieve the list of directories the file manager is allowed to access.
		$settings     = get_option( 'wst_settings', array() );
		$allowed_dirs = isset( $settings['allowed_dirs'] ) ? (array) $settings['allowed_dirs'] : array( ABSPATH );

		foreach ( $allowed_dirs as $allowed_dir ) {
			$allowed_dir = rtrim( $allowed_dir, '/\\' );

			// Use strncmp so that a path like '/var/www/html' is not accidentally
			// permitted by an allowed_dir of '/var/www/h'.
			if ( 0 === strncmp( $resolved, $allowed_dir, strlen( $allowed_dir ) ) ) {
				// Confirm the next character is a separator or the path is exact,
				// preventing prefix collisions (e.g. /var/www/html vs /var/www/html2).
				$next_char = substr( $resolved, strlen( $allowed_dir ), 1 );

				if ( '' === $next_char || '/' === $next_char || '\\' === $next_char ) {
					return $resolved;
				}
			}
		}

		return new WP_Error( 'path_denied', 'Access to this path is not allowed.' );
	}

	// ---------------------------------------------------------------------------
	// IP Allow-list & Rate Limiting
	// ---------------------------------------------------------------------------

	/**
	 * Check whether the client's IP address is on the allow-list.
	 *
	 * When the allow-list configured in the plugin settings is empty, all IP
	 * addresses are permitted (open access). When one or more IPs are listed,
	 * only those addresses are allowed through.
	 *
	 * @since  1.0.0
	 *
	 * @return bool True when the client IP is allowed, false otherwise.
	 */
	public static function check_ip_allowlist() {
		$settings    = get_option( 'wst_settings', array() );
		$allowed_ips = isset( $settings['allowed_ips'] ) ? (array) $settings['allowed_ips'] : array();

		// Empty allow-list means all IPs are permitted.
		if ( empty( $allowed_ips ) ) {
			return true;
		}

		$client_ip = static::get_client_ip();

		return in_array( $client_ip, $allowed_ips, true );
	}

	/**
	 * Check whether the current user has exceeded the per-minute request limit.
	 *
	 * Uses a sliding-window approach backed by a WordPress transient. The counter
	 * key is initialised with a 60-second TTL on the first request in each
	 * window. Subsequent requests within the window increment the counter.
	 * Requests are blocked once the counter reaches or exceeds 60.
	 *
	 * Note: because WordPress transients reset their TTL on `set_transient()`,
	 * the window effectively slides on every call. For a strict fixed-window
	 * approach a custom option with a timestamp would be required.
	 *
	 * SECURITY LIMITATION — Transient-based rate limiting:
	 * WordPress transients are stored in the database by default, but on sites
	 * with object-cache plugins (e.g. Redis Object Cache, W3 Total Cache with
	 * Memcached) transients may be stored in a volatile in-memory layer that is
	 * not shared across multiple web-server processes or is flushed unexpectedly.
	 * In such environments the rate-limit counter can be bypassed by requests
	 * that hit different processes before the counter is replicated. For
	 * production deployments that require strict rate limiting, replace this
	 * implementation with one backed by a Redis INCR/EXPIRE pipeline (atomic,
	 * shared across all workers, and flush-safe).
	 *
	 * @since  1.0.0
	 *
	 * @return bool True when the request is within the allowed limit, false when
	 *              the user has been rate-limited.
	 */
	public static function check_rate_limit() {
		$user_id = get_current_user_id();
		$key     = self::RATE_TRANSIENT_PREFIX . $user_id;
		$count   = (int) get_transient( $key );

		// First request in this window — initialise the counter.
		if ( 0 === $count ) {
			set_transient( $key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		// Limit exceeded — block the request without incrementing the counter.
		if ( $count >= 60 ) {
			return false;
		}

		// Within the limit — increment the counter.
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Determine the client's IP address.
	 *
	 * Checks `HTTP_X_FORWARDED_FOR` first (honouring common reverse-proxy
	 * setups), falling back to `REMOTE_ADDR`. The resulting IP is validated with
	 * `FILTER_VALIDATE_IP`; if validation fails `'0.0.0.0'` is returned as a
	 * safe sentinel value.
	 *
	 * @since  1.0.0
	 *
	 * @return string A valid IP address string, or '0.0.0.0' on failure.
	 */
	public static function get_client_ip() {
		$ip = '';

		// HTTP_X_FORWARDED_FOR may contain a comma-separated list of IPs when
		// multiple proxies are involved. Use only the first (leftmost) entry,
		// which is the originating client address.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$parts     = explode( ',', $forwarded );
			$ip        = trim( $parts[0] );
		}

		// Fall back to the direct connection address.
		if ( '' === $ip && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		// Validate — reject anything that isn't a well-formed IP address.
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '0.0.0.0';
		}

		return $ip;
	}

	/**
	 * Determine whether the current request is served over HTTPS.
	 *
	 * Checks both the native WordPress `is_ssl()` function and the
	 * `HTTP_X_FORWARDED_PROTO` header used by many load balancers and reverse
	 * proxies that terminate TLS upstream.
	 *
	 * @since  1.0.0
	 *
	 * @return bool True when the connection is (or appears to be) HTTPS.
	 */
	public static function is_https() {
		if ( is_ssl() ) {
			return true;
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) &&
			'https' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Generate a fresh nonce for use in AJAX requests.
	 *
	 * The nonce action matches `NONCE_ACTION` so that all nonces produced by
	 * this method are automatically validated by `verify_ajax_request()`.
	 *
	 * @since  1.0.0
	 *
	 * @return string A WordPress nonce string.
	 */
	public static function generate_nonce() {
		return wp_create_nonce( self::NONCE_ACTION );
	}
}
