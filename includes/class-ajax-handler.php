<?php
/**
 * AJAX Handler
 *
 * Central router for all WP Server Terminal AJAX endpoints.
 * All endpoints require a logged-in user — no nopriv hooks are registered.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_Ajax_Handler
 *
 * Registers and dispatches every wp_ajax_wst_* action.
 */
class WST_Ajax_Handler {

	// -------------------------------------------------------------------------
	// Security helpers
	// -------------------------------------------------------------------------

	/**
	 * Set security-related HTTP response headers.
	 *
	 * Called at the top of every public handle_* method to harden AJAX
	 * responses against MIME-sniffing and framing attacks. Must be invoked
	 * before any output is sent so the headers are not discarded.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function set_security_headers() {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
	}

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Constructor.
	 *
	 * Registers all wp_ajax_ hooks for authenticated users.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$actions = [
			'wst_exec',
			'wst_file_list',
			'wst_file_read',
			'wst_file_write',
			'wst_file_delete',
			'wst_file_upload',
			'wst_file_mkdir',
			'wst_file_rename',
			'wst_sql_exec',
			'wst_sql_tables',
			'wst_verify_password',
		];

		foreach ( $actions as $action ) {
			$method = 'handle_' . str_replace( 'wst_', '', $action );
			add_action( 'wp_ajax_' . $action, [ $this, $method ] );
		}

		// Download is GET-based (streams a file rather than returning JSON).
		add_action( 'wp_ajax_wst_file_download', [ $this, 'handle_file_download' ] );
	}

	// -------------------------------------------------------------------------
	// Terminal / WP-CLI
	// -------------------------------------------------------------------------

	/**
	 * Handle wst_exec — execute a shell or WP-CLI command.
	 *
	 * Accepts the following POST fields:
	 *   - command  (string) The command string to run.
	 *   - wpcli    (string) "1" to route through WP-CLI instead of the shell.
	 *   - abort    (string) "1" to signal an abort (best-effort; returns early).
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_exec() {
		$this->set_security_headers();
		WST_Security::verify_ajax_request();
		WST_Security::check_reauth_or_die();

		$command  = sanitize_text_field( wp_unslash( $_POST['command'] ?? '' ) );
		$is_wpcli = ! empty( $_POST['wpcli'] ) && '1' === $_POST['wpcli'];
		$abort    = ! empty( $_POST['abort'] ) && '1' === $_POST['abort'];

		if ( $abort ) {
			// Best-effort abort signal — actual process clean-up is server-side.
			wp_send_json_success( [ 'aborted' => true ] );
		}

		if ( empty( $command ) ) {
			wp_send_json_error( [ 'message' => 'No command provided.' ], 400 );
		}

		// Check command against the security blocklist.
		$sanitized = WST_Security::sanitize_command( $command );
		if ( is_wp_error( $sanitized ) ) {
			WST_Audit_Log::log( WST_Audit_Log::ACTION_COMMAND, $command, 'blocked', 'warning' );
			wp_send_json_error(
				[
					'code'    => $sanitized->get_error_code(),
					'message' => $sanitized->get_error_message(),
				],
				403
			);
		}

		if ( $is_wpcli ) {
			if ( ! WST_Settings::get( 'enable_wpcli', true ) ) {
				wp_send_json_error( [ 'message' => 'WP-CLI is disabled in settings.' ], 403 );
			}
			$result = WST_WPCLI::execute( $sanitized );
		} else {
			$result = WST_Terminal::execute( $sanitized );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				500
			);
		}

		WST_Security::extend_reauth_session();
		wp_send_json_success( $result );
	}

	// -------------------------------------------------------------------------
	// File Manager
	// -------------------------------------------------------------------------

	/**
	 * Handle wst_file_list — list the contents of a directory.
	 *
	 * POST fields:
	 *   - path (string) Absolute path to list. Defaults to ABSPATH when empty.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_file_list() {
		$this->set_security_headers();
		WST_Security::verify_ajax_request();
		WST_Security::check_reauth_or_die();

		if ( ! WST_Settings::get( 'enable_file_manager', true ) ) {
			wp_send_json_error( array( 'message' => __( 'File manager is disabled in settings.', 'wp-server-terminal' ) ), 403 );
		}

		$path = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );

		if ( empty( $path ) ) {
			$path = ABSPATH;
		}

		$result = WST_File_Manager::list_directory( $path );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				400
			);
		}

		WST_Security::extend_reauth_session();
		wp_send_json_success( $result );
	}

	/**
	 * Handle wst_file_read — read and return the contents of a file.
	 *
	 * POST fields:
	 *   - path (string) Absolute path to the file to read.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_file_read() {
		$this->set_security_headers();
		WST_Security::verify_ajax_request();
		WST_Security::check_reauth_or_die();

		if ( ! WST_Settings::get( 'enable_file_manager', true ) ) {
			wp_send_json_error( array( 'message' => __( 'File manager is disabled in settings.', 'wp-server-terminal' ) ), 403 );
		}

		$path   = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
		$result = WST_File_Manager::read_file( $path );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				400
			);
		}

		WST_Security::extend_reauth_session();
		wp_send_json_success( $result );
	}

	/**
	 * Handle wst_file_write — write content to a file.
	 *
	 * Content is intentionally NOT passed through sanitize_text_field so that
	 * code formatting, indentation, and special characters are preserved.
	 *
	 * POST fields:
	 *   - path    (string) Absolute path to the file.
	 *   - content (string) Raw file content to write.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_file_write() {
		$this->set_security_headers();
		WST_Security::verify_ajax_request();
		WST_Security::check_reauth_or_die();

		if ( ! WST_Settings::get( 'enable_file_manager', true ) ) {
			wp_send_json_error( array( 'message' => __( 'File manager is disabled in settings.', 'wp-server-terminal' ) ), 403 );
		}

		$path    = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
		$content = wp_unslash( $_POST['content'] ?? '' );

		if ( empty( $path ) ) {
			wp_send_json_error( [ 'message' => 'No file path provided.' ], 400 );
		}

		$result = WST_File_Manager::write_file( $path, $content );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				400
			);
		}

		WST_Security::extend_reauth_session();
		wp_send_json_success( $result );
	}

	/**
	 * Handle wst_file_delete — delete a file.
	 *
	 * POST fields:
	 *   - path (string) Absolute path to the file to delete.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_file_delete() {
		$this->set_security_headers();
		WST_Security::verify_ajax_request();
		WST_Security::check_reauth_or_die();

		if ( ! WST_Settings::get( 'enable_file_manager', true ) ) {
			wp_send_json_error( array( 'message' => __( 'File manager is disabled in settings.', 'wp-server-terminal' ) ), 403 );
		}

		$path   = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
		$result = WST_File_Manager::delete_file( $path );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				400
			);
		}

		WST_Security::extend_reauth_session();
		wp_send_json_success( $result );
	}

	/**
	 * Handle wst_file_upload — upload a file to a target directory.
	 *
	 * POST fields:
	 *   - target_dir (string) Absolute path to the destination directory.
	 *     Defaults to ABSPATH when empty.
	 *
	 * The uploaded file must be sent under the key "wst_file".
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_file_upload() {
		$this->set_security_headers();
		WST_Security::verify_ajax_request();
		WST_Security::check_reauth_or_die();

		if ( ! WST_Settings::get( 'enable_file_manager', true ) ) {
			wp_send_json_error( array( 'message' => __( 'File manager is disabled in settings.', 'wp-server-terminal' ) ), 403 );
		}

		$target_dir = sanitize_text_field( wp_unslash( $_POST['target_dir'] ?? ABSPATH ) );
		$result     = WST_File_Manager::upload_file( $target_dir, 'wst_file' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				400
			);
		}

		WST_Security::extend_reauth_session();
		wp_send_json_success( $result );
	}

	/**
	 * Handle wst_file_mkdir — create a new directory.
	 *
	 * POST fields:
	 *   - path (string) Absolute path of the directory to create.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_file_mkdir() {
		$this->set_security_headers();
		WST_Security::verify_ajax_request();
		WST_Security::check_reauth_or_die();

		if ( ! WST_Settings::get( 'enable_file_manager', true ) ) {
			wp_send_json_error( array( 'message' => __( 'File manager is disabled in settings.', 'wp-server-terminal' ) ), 403 );
		}

		$path   = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
		$result = WST_File_Manager::create_directory( $path );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				400
			);
		}

		WST_Security::extend_reauth_session();
		wp_send_json_success( $result );
	}

	/**
	 * Handle wst_file_rename — rename a file or directory.
	 *
	 * POST fields:
	 *   - old_path (string) Absolute path to the existing file/directory.
	 *   - new_name (string) New filename (basename only, not a full path).
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_file_rename() {
		$this->set_security_headers();
		WST_Security::verify_ajax_request();
		WST_Security::check_reauth_or_die();

		if ( ! WST_Settings::get( 'enable_file_manager', true ) ) {
			wp_send_json_error( array( 'message' => __( 'File manager is disabled in settings.', 'wp-server-terminal' ) ), 403 );
		}

		$old_path = sanitize_text_field( wp_unslash( $_POST['old_path'] ?? '' ) );
		$new_name = sanitize_file_name( wp_unslash( $_POST['new_name'] ?? '' ) );
		$result   = WST_File_Manager::rename_file( $old_path, $new_name );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				400
			);
		}

		WST_Security::extend_reauth_session();
		wp_send_json_success( $result );
	}

	/**
	 * Handle wst_file_download — stream a file directly to the browser.
	 *
	 * This is a GET-based endpoint that sends raw file bytes rather than JSON.
	 * Nonce and capability are verified manually before any output is sent.
	 *
	 * GET parameters:
	 *   - nonce (string) wst_nonce nonce value.
	 *   - path  (string) Absolute path to the file to download.
	 *
	 * @since 1.0.0
	 * @return void Streams file and exits.
	 */
	public function handle_file_download() {
		$this->set_security_headers();
		// Verify nonce manually — false means die() on failure.
		if ( ! check_ajax_referer( 'wst_nonce', 'nonce', false ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wp-server-terminal' ) );
		}

		// Capability check.
		if ( ! WST_Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You do not have permission to download files.', 'wp-server-terminal' ) );
		}

		// Re-auth check — redirect to admin if not authenticated.
		if ( ! WST_Security::is_reauthed() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-server-terminal' ) );
			exit;
		}

		$path = sanitize_text_field( wp_unslash( $_GET['path'] ?? '' ) );
		$path = WST_Security::sanitize_path( $path );

		if ( is_wp_error( $path ) ) {
			wp_die( esc_html( $path->get_error_message() ) );
		}

		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'wp-server-terminal' ) );
		}

		$filename = basename( $path );
		$filesize = filesize( $path );

		// Send download headers.
		header( 'Content-Type: application/octet-stream' );
		$safe_filename = preg_replace( '/[^\x20-\x7E]/', '_', $filename );
		$safe_filename = str_replace( array( '"', '\\', "\r", "\n" ), '_', $safe_filename );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Content-Length: ' . $filesize );

		// No-cache headers.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Cache-Control: post-check=0, pre-check=0', false );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Stream the file.
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	// -------------------------------------------------------------------------
	// Database Manager
	// -------------------------------------------------------------------------

	/**
	 * Handle wst_sql_exec — execute a raw SQL query.
	 *
	 * The SQL string is intentionally NOT sanitized beyond unslashing so that
	 * SQL syntax (quotes, wildcards, etc.) is preserved intact.
	 *
	 * POST fields:
	 *   - sql (string) The SQL statement to execute.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_sql_exec() {
		$this->set_security_headers();
		WST_Security::verify_ajax_request();
		WST_Security::check_reauth_or_die();

		$sql = trim( wp_unslash( $_POST['sql'] ?? '' ) );

		if ( empty( $sql ) ) {
			wp_send_json_error( [ 'message' => 'No SQL query provided.' ], 400 );
		}

		if ( ! WST_Settings::get( 'enable_db_manager', true ) ) {
			wp_send_json_error( [ 'message' => 'Database manager is disabled in settings.' ], 403 );
		}

		$result = WST_Database_Manager::execute_query( $sql );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				400
			);
		}

		WST_Security::extend_reauth_session();
		wp_send_json_success( $result );
	}

	/**
	 * Handle wst_sql_tables — list all database tables.
	 *
	 * No POST body parameters are required.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_sql_tables() {
		$this->set_security_headers();
		WST_Security::verify_ajax_request();
		WST_Security::check_reauth_or_die();

		if ( ! WST_Settings::get( 'enable_db_manager', true ) ) {
			wp_send_json_error( [ 'message' => 'Database manager is disabled in settings.' ], 403 );
		}

		$result = WST_Database_Manager::list_tables();

		WST_Security::extend_reauth_session();
		wp_send_json_success( $result );
	}

	// -------------------------------------------------------------------------
	// Authentication
	// -------------------------------------------------------------------------

	/**
	 * Handle wst_verify_password — validate the re-authentication password.
	 *
	 * The password is intentionally NOT sanitized so that special characters
	 * in passwords are passed through to wp_check_password() unchanged.
	 *
	 * POST fields:
	 *   - nonce    (string) wst_nonce nonce value.
	 *   - password (string) The user's current WordPress password.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public function handle_verify_password() {
		$this->set_security_headers();
		check_ajax_referer( 'wst_nonce', 'nonce' );

		if ( ! WST_Capabilities::current_user_can() ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$password = $_POST['password'] ?? '';
		$result   = WST_Security::verify_reauth( $password );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				403
			);
		}

		wp_send_json_success( [ 'authenticated' => true ] );
	}
}
