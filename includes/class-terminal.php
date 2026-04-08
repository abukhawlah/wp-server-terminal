<?php
/**
 * Terminal execution class.
 *
 * Handles shell command execution, working-directory tracking per user,
 * and executor auto-detection across proc_open / exec / shell_exec fallback
 * chains. All public methods are static so the class acts as a stateless
 * service layer that can be called from any context without instantiation.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_Terminal
 *
 * Provides shell command execution with CWD tracking, output size limits,
 * configurable timeouts, and graceful degradation across available PHP
 * execution functions.
 *
 * @since 1.0.0
 */
class WST_Terminal {

	// ---------------------------------------------------------------------------
	// Constants
	// ---------------------------------------------------------------------------

	/**
	 * Executor identifier for proc_open().
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const EXECUTOR_PROC_OPEN = 'proc_open';

	/**
	 * Executor identifier for exec().
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const EXECUTOR_EXEC = 'exec';

	/**
	 * Executor identifier for shell_exec().
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const EXECUTOR_SHELL_EXEC = 'shell_exec';

	/**
	 * Transient key prefix for per-user working-directory storage.
	 *
	 * The full key is this prefix concatenated with the user ID.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const CWD_TRANSIENT_PREFIX = 'wst_cwd_';

	/**
	 * Null-byte-delimited marker injected into command output to carry the
	 * post-execution working directory back to the caller.
	 *
	 * Format in raw output: \x00CWD\x00{/absolute/path}\x00
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const CWD_MARKER_START = "\x00CWD\x00";

	/**
	 * Closing null byte that terminates the CWD marker sequence.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const CWD_MARKER_END = "\x00";

	// ---------------------------------------------------------------------------
	// Executor detection
	// ---------------------------------------------------------------------------

	/**
	 * Detect the best available PHP function for executing shell commands.
	 *
	 * Checks each candidate in priority order (proc_open → exec → shell_exec →
	 * passthru) and returns the name of the first one that is not disabled.
	 * The result is cached in a one-hour transient to avoid repeated ini_get()
	 * calls on every request.
	 *
	 * @since  1.0.0
	 *
	 * @return string|WP_Error The name of the available executor function, or a
	 *                         WP_Error when no execution function is available.
	 */
	public static function detect_executor() {
		$cached = get_transient( 'wst_executor' );

		if ( false !== $cached ) {
			return $cached;
		}

		$candidates = array(
			self::EXECUTOR_PROC_OPEN,
			self::EXECUTOR_EXEC,
			self::EXECUTOR_SHELL_EXEC,
			'passthru',
		);

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		foreach ( $candidates as $func ) {
			if ( function_exists( $func ) && ! in_array( $func, $disabled, true ) ) {
				set_transient( 'wst_executor', $func, HOUR_IN_SECONDS );
				return $func;
			}
		}

		return new WP_Error(
			'no_executor',
			'No command execution functions are available. Check PHP disable_functions setting.'
		);
	}

	// ---------------------------------------------------------------------------
	// Working-directory management
	// ---------------------------------------------------------------------------

	/**
	 * Retrieve the stored working directory for the given user.
	 *
	 * Falls back to ABSPATH when no valid directory has been stored yet.
	 *
	 * @since  1.0.0
	 *
	 * @param  int $user_id WordPress user ID. Defaults to the current user when 0.
	 * @return string        Absolute path to the working directory.
	 */
	public static function get_cwd( $user_id = 0 ) {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		$cwd = get_transient( self::CWD_TRANSIENT_PREFIX . $user_id );

		if ( empty( $cwd ) || ! is_dir( $cwd ) ) {
			return ABSPATH;
		}

		return $cwd;
	}

	/**
	 * Persist the working directory for the given user.
	 *
	 * The path is validated with is_dir() before storage. Invalid paths are
	 * silently discarded to avoid poisoning the stored state.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $path    Absolute filesystem path to store.
	 * @param  int    $user_id WordPress user ID. Defaults to the current user when 0.
	 * @return void
	 */
	public static function set_cwd( $path, $user_id = 0 ) {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		set_transient( self::CWD_TRANSIENT_PREFIX . $user_id, $path, DAY_IN_SECONDS );
	}

	// ---------------------------------------------------------------------------
	// Command execution
	// ---------------------------------------------------------------------------

	/**
	 * Execute a shell command and return its output, exit code, and new CWD.
	 *
	 * The command is modified before execution to append a `printf` call that
	 * writes the post-execution working directory into stdout using a null-byte-
	 * delimited marker sequence. This marker is parsed out before the result is
	 * returned so the caller receives clean output.
	 *
	 * Execution strategy:
	 *  - proc_open  : full stdout/stderr separation, non-blocking I/O, real exit code.
	 *  - exec       : merged stdout+stderr (2>&1), real exit code, no streaming.
	 *  - shell_exec : merged stdout+stderr (2>&1), exit code always 0.
	 *
	 * @since  1.0.0
	 *
	 * @param  string      $command The shell command to execute.
	 * @param  string|null $cwd     Working directory. Null uses the stored per-user CWD.
	 * @param  int|null    $timeout Execution timeout in seconds. Null reads the plugin setting.
	 * @return array {
	 *     @type string $stdout    Standard output from the command (clean, marker stripped).
	 *     @type string $stderr    Standard error output (empty for exec/shell_exec fallbacks).
	 *     @type int    $exit_code Process exit code (-1 on executor failure, 0 for shell_exec).
	 *     @type string $new_cwd   Resolved working directory after the command ran.
	 *     @type bool   $truncated True when output was cut short due to the size limit.
	 * }
	 */
	public static function execute( $command, $cwd = null, $timeout = null ) {
		if ( null === $cwd ) {
			$cwd = self::get_cwd();
		}

		if ( null === $timeout ) {
			$timeout = (int) WST_Settings::get( 'command_timeout', 30 );
		}

		$max_output = (int) WST_Settings::get( 'max_output_size', 1048576 );

		// Allow the command to run as long as needed; PHP's own limit would kill
		// long-running processes from underneath us.
		set_time_limit( 0 );
		ignore_user_abort( true );

		$executor = self::detect_executor();

		if ( is_wp_error( $executor ) ) {
			return array(
				'stdout'    => '',
				'stderr'    => (string) $executor->get_error_message(),
				'exit_code' => -1,
				'new_cwd'   => $cwd,
				'truncated' => false,
			);
		}

		// Wrap the command so that the shell reports its own CWD after the user's
		// command finishes. We capture the user command's exit status first, then
		// emit the CWD marker, and finally re-exit with the original code so the
		// caller sees the correct exit status.
		//
		// The marker uses literal null bytes (written as $'\0' in bash/sh ANSI-C
		// quoting) which are safe delimiters — they cannot appear in a valid path
		// and are extremely unlikely to occur in normal command output.
		//
		// Full sequence:
		//   { user_command ; }; _wst_e=$?; printf '\0CWD\0%s\0' "$(pwd)"; exit $_wst_e
		$wrapped = '{ ' . $command . '; }; _wst_e=$?; printf $\'\\0CWD\\0%s\\0\' "$(pwd)"; exit $_wst_e';

		if ( self::EXECUTOR_PROC_OPEN === $executor ) {
			return self::execute_proc_open( $wrapped, $cwd, $timeout, $max_output );
		}

		if ( self::EXECUTOR_EXEC === $executor ) {
			return self::execute_exec( $wrapped, $cwd, $timeout, $max_output );
		}

		// shell_exec and passthru both fall through to the shell_exec implementation.
		return self::execute_shell_exec( $wrapped, $cwd, $max_output );
	}

	// ---------------------------------------------------------------------------
	// Private execution helpers
	// ---------------------------------------------------------------------------

	/**
	 * Execute a command using proc_open() with non-blocking I/O.
	 *
	 * Provides full stdout/stderr separation, real exit codes, streaming output
	 * collection with size limits, and SIGTERM/SIGKILL-based timeout enforcement.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $command    Wrapped shell command (includes CWD marker suffix).
	 * @param  string $cwd        Working directory for the child process.
	 * @param  int    $timeout    Maximum allowed wall-clock seconds.
	 * @param  int    $max_output Maximum combined stdout+stderr bytes before truncation.
	 * @return array              See execute() return structure.
	 */
	private static function execute_proc_open( $command, $cwd, $timeout, $max_output ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),  // stdin  — child reads from this pipe.
			1 => array( 'pipe', 'w' ),  // stdout — child writes to this pipe.
			2 => array( 'pipe', 'w' ),  // stderr — child writes to this pipe.
		);

		$env     = null; // Inherit the parent process environment.
		$process = proc_open( $command, $descriptors, $pipes, $cwd, $env );

		if ( ! is_resource( $process ) ) {
			return array(
				'stdout'    => '',
				'stderr'    => 'Failed to open process.',
				'exit_code' => -1,
				'new_cwd'   => $cwd,
				'truncated' => false,
			);
		}

		// Close stdin immediately — we never feed data to the child process.
		fclose( $pipes[0] );

		// Switch both output pipes to non-blocking mode so stream_select() can
		// multiplex them without hanging on a single pipe.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout    = '';
		$stderr    = '';
		$start     = time();
		$truncated = false;
		$status    = array( 'running' => true, 'exitcode' => -1 );

		while ( true ) {
			$read   = array( $pipes[1], $pipes[2] );
			$write  = null;
			$except = null;

			// Wait up to 1 second for activity on either pipe.
			$changed = stream_select( $read, $write, $except, 1, 0 );

			if ( false === $changed ) {
				// stream_select() encountered an error (e.g. interrupted by signal).
				break;
			}

			// Drain all pipes that became readable during this select window.
			foreach ( $read as $stream ) {
				$chunk = fread( $stream, 8192 );

				if ( false === $chunk || '' === $chunk ) {
					continue;
				}

				if ( $stream === $pipes[1] ) {
					$stdout .= $chunk;
				} else {
					$stderr .= $chunk;
				}
			}

			// Enforce output size limit: terminate the child and flag the result.
			if ( ( strlen( $stdout ) + strlen( $stderr ) ) > $max_output ) {
				$truncated = true;
				proc_terminate( $process, 15 ); // SIGTERM — ask nicely first.
				sleep( 1 );
				proc_terminate( $process, 9 );  // SIGKILL — force if still running.
				break;
			}

			// Enforce wall-clock timeout.
			if ( ( time() - $start ) >= $timeout ) {
				proc_terminate( $process, 15 ); // SIGTERM.
				sleep( 1 );
				proc_terminate( $process, 9 );  // SIGKILL.
				$stderr .= "\n[Command timed out after {$timeout} seconds]";
				break;
			}

			// Check whether the child process has already exited.
			$status = proc_get_status( $process );

			if ( ! $status['running'] ) {
				// Drain any output buffered since the last select() call.
				$remaining_stdout = stream_get_contents( $pipes[1] );
				$remaining_stderr = stream_get_contents( $pipes[2] );

				if ( false !== $remaining_stdout ) {
					$stdout .= $remaining_stdout;
				}

				if ( false !== $remaining_stderr ) {
					$stderr .= $remaining_stderr;
				}

				break;
			}
		}

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		// proc_close() returns -1 when the process was already collected via
		// proc_get_status(); use the status exit code in that case.
		$raw_exit = proc_close( $process );
		$exit_code = ( -1 !== $raw_exit ) ? $raw_exit : (int) $status['exitcode'];

		return self::build_result( $stdout, $stderr, $exit_code, $cwd, $truncated );
	}

	/**
	 * Execute a command using exec() as a fallback to proc_open.
	 *
	 * stdout and stderr are merged via 2>&1 shell redirection. The real exit code
	 * is available from exec()'s third argument. Long-running commands cannot be
	 * streamed or interrupted mid-execution through this path.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $command    Wrapped shell command (includes CWD marker suffix).
	 * @param  string $cwd        Working directory passed via cd before the command.
	 * @param  int    $timeout    Not enforceable with exec(); included for API parity.
	 * @param  int    $max_output Maximum combined output bytes before truncation.
	 * @return array              See execute() return structure.
	 */
	private static function execute_exec( $command, $cwd, $timeout, $max_output ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		// Change directory inline so exec() uses the correct CWD.
		// Wrap in a subshell so the 2>&1 redirect covers the user's command and
		// the CWD marker printf, while still capturing the correct exit code.
		$full_command = '( cd ' . escapeshellarg( $cwd ) . ' && ' . $command . ' ) 2>&1';

		$output_lines = array();
		$exit_code    = 0;

		exec( $full_command, $output_lines, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		$stdout    = implode( "\n", $output_lines );
		$truncated = false;

		if ( strlen( $stdout ) > $max_output ) {
			$stdout    = substr( $stdout, 0, $max_output );
			$truncated = true;
		}

		return self::build_result( $stdout, '', (int) $exit_code, $cwd, $truncated );
	}

	/**
	 * Execute a command using shell_exec() as the last-resort fallback.
	 *
	 * stdout and stderr are merged via 2>&1 shell redirection. The exit code is
	 * always reported as 0 because shell_exec() does not expose it.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $command    Wrapped shell command (includes CWD marker suffix).
	 * @param  string $cwd        Working directory passed via cd before the command.
	 * @param  int    $max_output Maximum combined output bytes before truncation.
	 * @return array              See execute() return structure.
	 */
	private static function execute_shell_exec( $command, $cwd, $max_output ) {
		// Wrap in a subshell so 2>&1 covers the entire command including the
		// CWD marker printf, and cd is scoped to the subshell.
		$full_command = '( cd ' . escapeshellarg( $cwd ) . ' && ' . $command . ' ) 2>&1';

		$output    = shell_exec( $full_command ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$stdout    = ( null === $output || false === $output ) ? '' : $output;
		$truncated = false;

		if ( strlen( $stdout ) > $max_output ) {
			$stdout    = substr( $stdout, 0, $max_output );
			$truncated = true;
		}

		return self::build_result( $stdout, '', 0, $cwd, $truncated );
	}

	// ---------------------------------------------------------------------------
	// Result parsing helpers
	// ---------------------------------------------------------------------------

	/**
	 * Parse the CWD marker from raw output, update the stored CWD, and return the
	 * canonical result array.
	 *
	 * The CWD marker appended to every wrapped command has the form:
	 *   \x00CWD\x00{/absolute/path}\x00
	 *
	 * It is stripped from $stdout before the result is returned. If the marker is
	 * not present (e.g. the command failed before reaching the printf call), the
	 * previously known CWD is kept unchanged.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $stdout    Raw standard output, possibly containing the CWD marker.
	 * @param  string $stderr    Standard error output.
	 * @param  int    $exit_code Process exit code.
	 * @param  string $cwd       Previous working directory (used as fallback).
	 * @param  bool   $truncated Whether the output was cut short by the size limit.
	 * @return array             See execute() return structure.
	 */
	private static function build_result( $stdout, $stderr, $exit_code, $cwd, $truncated ) {
		$new_cwd = $cwd;

		// Locate the CWD marker injected by the wrapped command.
		$marker_start = self::CWD_MARKER_START; // "\x00CWD\x00"
		$marker_end   = self::CWD_MARKER_END;   // "\x00"

		$start_pos = strrpos( $stdout, $marker_start );

		if ( false !== $start_pos ) {
			$path_start = $start_pos + strlen( $marker_start );
			$end_pos    = strpos( $stdout, $marker_end, $path_start );

			if ( false !== $end_pos ) {
				$parsed_cwd = substr( $stdout, $path_start, $end_pos - $path_start );
				$parsed_cwd = trim( $parsed_cwd );

				// Strip the entire marker sequence (including surrounding null bytes)
				// from the displayed output.
				$stdout = substr( $stdout, 0, $start_pos )
					. substr( $stdout, $end_pos + strlen( $marker_end ) );

				// Only trust the parsed path if it resolves to a real directory.
				if ( '' !== $parsed_cwd ) {
					$resolved = realpath( $parsed_cwd );

					if ( false !== $resolved && is_dir( $resolved ) ) {
						$new_cwd = $resolved;
					}
				}
			}
		}

		// Persist the new working directory for the current user.
		self::set_cwd( $new_cwd );

		// Strip trailing null bytes that may appear in binary-safe output.
		$stdout = rtrim( $stdout, "\x00" );

		return array(
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'exit_code' => $exit_code,
			'new_cwd'   => $new_cwd,
			'truncated' => $truncated,
		);
	}

	// ---------------------------------------------------------------------------
	// Diagnostics
	// ---------------------------------------------------------------------------

	/**
	 * Collect diagnostic information about the execution environment.
	 *
	 * Returns an associative array suitable for display on an admin diagnostics
	 * page or inclusion in a bug report. All values are gathered at call time;
	 * nothing is cached.
	 *
	 * @since  1.0.0
	 *
	 * @return array {
	 *     @type string|WP_Error $executor             Best available executor (or WP_Error).
	 *     @type bool            $proc_open_available   Whether proc_open() can be called.
	 *     @type bool            $exec_available        Whether exec() can be called.
	 *     @type bool            $shell_exec_available  Whether shell_exec() can be called.
	 *     @type string          $php_max_exec_time     Value of max_execution_time ini setting.
	 *     @type string          $open_basedir          Value of open_basedir ini setting.
	 *     @type string          $php_user              OS user name the PHP process runs as.
	 *     @type string|WP_Error $wpcli_path            Path to WP-CLI binary (or WP_Error).
	 * }
	 */
	public static function get_diagnostic_info() {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		// Resolve the OS user name for the PHP process.
		$php_user = '';

		if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
			$pw_entry = posix_getpwuid( posix_geteuid() );
			$php_user = is_array( $pw_entry ) ? (string) $pw_entry['name'] : '';
		}

		if ( '' === $php_user && function_exists( 'get_current_user' ) ) {
			$php_user = (string) get_current_user();
		}

		// Detect WP-CLI path only when the class is available.
		$wpcli_path = class_exists( 'WST_WPCLI' )
			? WST_WPCLI::detect()
			: new WP_Error( 'wpcli_class_missing', 'WST_WPCLI class is not loaded.' );

		return array(
			'executor'            => self::detect_executor(),
			'proc_open_available' => function_exists( 'proc_open' ) && ! in_array( 'proc_open', $disabled, true ),
			'exec_available'      => function_exists( 'exec' ) && ! in_array( 'exec', $disabled, true ),
			'shell_exec_available' => function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', $disabled, true ),
			'php_max_exec_time'   => (string) ini_get( 'max_execution_time' ),
			'open_basedir'        => (string) ini_get( 'open_basedir' ),
			'php_user'            => $php_user,
			'wpcli_path'          => $wpcli_path,
		);
	}
}
