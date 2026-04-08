<?php
/**
 * Admin menu class.
 *
 * Registers the plugin's admin menu pages and submenus, enqueues page-specific
 * assets, and handles the per-page re-authentication gate before delegating
 * rendering to the appropriate page template file.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_Admin_Menu
 *
 * Hooks into WordPress to register menus, enqueue scripts/styles, and render
 * admin page templates for the WP Server Terminal plugin.
 *
 * @since 1.0.0
 */
class WST_Admin_Menu {

	// ---------------------------------------------------------------------------
	// Constructor
	// ---------------------------------------------------------------------------

	/**
	 * Register all necessary WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'show_activation_notices' ) );
	}

	// ---------------------------------------------------------------------------
	// Menu Registration
	// ---------------------------------------------------------------------------

	/**
	 * Register the top-level menu page and all submenu pages.
	 *
	 * Silently returns when the current user does not hold the required
	 * capability so that the menu items are never added for unprivileged users.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function register_menus() {
		if ( ! WST_Capabilities::current_user_can() ) {
			return;
		}

		add_menu_page(
			__( 'Server Terminal', 'wp-server-terminal' ),
			__( 'Server Terminal', 'wp-server-terminal' ),
			'manage_server_terminal',
			'wst-terminal',
			array( $this, 'render_terminal_page' ),
			'dashicons-terminal',
			75
		);

		add_submenu_page(
			'wst-terminal',
			__( 'Terminal', 'wp-server-terminal' ),
			__( 'Terminal', 'wp-server-terminal' ),
			'manage_server_terminal',
			'wst-terminal',
			array( $this, 'render_terminal_page' )
		);

		add_submenu_page(
			'wst-terminal',
			__( 'File Manager', 'wp-server-terminal' ),
			__( 'File Manager', 'wp-server-terminal' ),
			'manage_server_terminal',
			'wst-file-manager',
			array( $this, 'render_file_manager_page' )
		);

		add_submenu_page(
			'wst-terminal',
			__( 'Database', 'wp-server-terminal' ),
			__( 'Database', 'wp-server-terminal' ),
			'manage_server_terminal',
			'wst-database',
			array( $this, 'render_database_page' )
		);

		add_submenu_page(
			'wst-terminal',
			__( 'Audit Log', 'wp-server-terminal' ),
			__( 'Audit Log', 'wp-server-terminal' ),
			'manage_server_terminal',
			'wst-audit-log',
			array( $this, 'render_audit_log_page' )
		);

		add_submenu_page(
			'wst-terminal',
			__( 'Settings', 'wp-server-terminal' ),
			__( 'Settings', 'wp-server-terminal' ),
			'manage_server_terminal',
			'wst-settings',
			array( $this, 'render_settings_page' )
		);
	}

	// ---------------------------------------------------------------------------
	// Asset Enqueueing
	// ---------------------------------------------------------------------------

	/**
	 * Enqueue page-specific styles and scripts for WP Server Terminal admin pages.
	 *
	 * Assets are only registered and enqueued on hooks whose name contains the
	 * 'wst-' slug, preventing unnecessary asset loading on unrelated admin pages.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		// Bail early if we are not on a plugin page.
		if ( false === strpos( $hook, 'wst-' ) ) {
			return;
		}

		// -----------------------------------------------------------------
		// Shared admin stylesheet — loaded on every plugin page.
		// -----------------------------------------------------------------
		wp_enqueue_style(
			'wst-admin',
			WST_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			WST_VERSION
		);

		// -----------------------------------------------------------------
		// Terminal page assets.
		// -----------------------------------------------------------------
		if ( false !== strpos( $hook, 'wst-terminal' ) ) {
			wp_enqueue_style(
				'wst-xterm-css',
				WST_PLUGIN_URL . 'assets/xterm/xterm.css',
				array(),
				WST_VERSION
			);

			wp_enqueue_script(
				'wst-xterm',
				WST_PLUGIN_URL . 'assets/xterm/xterm.js',
				array(),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-xterm-fit',
				WST_PLUGIN_URL . 'assets/xterm/xterm-addon-fit.js',
				array( 'wst-xterm' ),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-terminal-js',
				WST_PLUGIN_URL . 'admin/js/terminal.js',
				array( 'wst-xterm', 'wst-xterm-fit' ),
				WST_VERSION,
				true
			);

			wp_localize_script(
				'wst-terminal-js',
				'wstTerminal',
				array(
					'ajaxurl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => WST_Security::generate_nonce(),
					'plugin_url' => WST_PLUGIN_URL,
				)
			);
		}

		// -----------------------------------------------------------------
		// File manager page assets.
		// -----------------------------------------------------------------
		if ( false !== strpos( $hook, 'wst-file-manager' ) ) {
			wp_enqueue_style(
				'wst-codemirror-css',
				WST_PLUGIN_URL . 'assets/codemirror/codemirror.css',
				array(),
				WST_VERSION
			);

			wp_enqueue_style(
				'wst-codemirror-theme',
				WST_PLUGIN_URL . 'assets/codemirror/theme-dracula.css',
				array( 'wst-codemirror-css' ),
				WST_VERSION
			);

			wp_enqueue_script(
				'wst-codemirror',
				WST_PLUGIN_URL . 'assets/codemirror/codemirror.js',
				array(),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-codemirror-mode-xml',
				WST_PLUGIN_URL . 'assets/codemirror/mode-xml.js',
				array( 'wst-codemirror' ),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-codemirror-mode-clike',
				WST_PLUGIN_URL . 'assets/codemirror/mode-clike.js',
				array( 'wst-codemirror' ),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-codemirror-mode-css',
				WST_PLUGIN_URL . 'assets/codemirror/mode-css.js',
				array( 'wst-codemirror' ),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-codemirror-mode-javascript',
				WST_PLUGIN_URL . 'assets/codemirror/mode-javascript.js',
				array( 'wst-codemirror' ),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-codemirror-mode-sql',
				WST_PLUGIN_URL . 'assets/codemirror/mode-sql.js',
				array( 'wst-codemirror' ),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-codemirror-mode-htmlmixed',
				WST_PLUGIN_URL . 'assets/codemirror/mode-htmlmixed.js',
				array( 'wst-codemirror', 'wst-codemirror-mode-xml', 'wst-codemirror-mode-javascript', 'wst-codemirror-mode-css' ),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-codemirror-mode-php',
				WST_PLUGIN_URL . 'assets/codemirror/mode-php.js',
				array( 'wst-codemirror', 'wst-codemirror-mode-htmlmixed', 'wst-codemirror-mode-clike' ),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-codemirror-mode-shell',
				WST_PLUGIN_URL . 'assets/codemirror/mode-shell.js',
				array( 'wst-codemirror' ),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-file-manager-js',
				WST_PLUGIN_URL . 'admin/js/file-manager.js',
				array( 'wst-codemirror' ),
				WST_VERSION,
				true
			);

			wp_localize_script(
				'wst-file-manager-js',
				'wstFileManager',
				array(
					'ajaxurl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => WST_Security::generate_nonce(),
					'plugin_url' => WST_PLUGIN_URL,
				)
			);
		}

		// -----------------------------------------------------------------
		// Database page assets.
		// -----------------------------------------------------------------
		if ( false !== strpos( $hook, 'wst-database' ) ) {
			// CodeMirror may already be registered by the file manager on
			// multitab navigations; only register it when absent.
			if ( ! wp_script_is( 'wst-codemirror', 'registered' ) ) {
				wp_enqueue_style(
					'wst-codemirror-css',
					WST_PLUGIN_URL . 'assets/codemirror/codemirror.css',
					array(),
					WST_VERSION
				);

				wp_enqueue_script(
					'wst-codemirror',
					WST_PLUGIN_URL . 'assets/codemirror/codemirror.js',
					array(),
					WST_VERSION,
					true
				);
			}

			wp_enqueue_style(
				'wst-codemirror-theme',
				WST_PLUGIN_URL . 'assets/codemirror/theme-dracula.css',
				array( 'wst-codemirror-css' ),
				WST_VERSION
			);

			wp_enqueue_script(
				'wst-codemirror-mode-sql',
				WST_PLUGIN_URL . 'assets/codemirror/mode-sql.js',
				array( 'wst-codemirror' ),
				WST_VERSION,
				true
			);

			wp_enqueue_script(
				'wst-database-js',
				WST_PLUGIN_URL . 'admin/js/database.js',
				array( 'wst-codemirror', 'wst-codemirror-mode-sql' ),
				WST_VERSION,
				true
			);

			wp_localize_script(
				'wst-database-js',
				'wstDatabase',
				array(
					'ajaxurl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => WST_Security::generate_nonce(),
					'plugin_url' => WST_PLUGIN_URL,
				)
			);
		}
	}

	// ---------------------------------------------------------------------------
	// Activation Notices
	// ---------------------------------------------------------------------------

	/**
	 * Display any activation error stored in a transient, then delete it.
	 *
	 * Also displays environment health notices on plugin pages.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function show_activation_notices() {
		$error = get_transient( 'wst_activation_error' );

		if ( false !== $error && '' !== $error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses_post( $error )
			);

			delete_transient( 'wst_activation_error' );
		}

		// Health notices only on plugin pages.
		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, 'wst-' ) ) {
			// Show first-run security acknowledgment
			require_once WST_PLUGIN_DIR . 'admin/pages/first-run-notice.php';

			$health_notices = $this->check_environment_health();
			foreach ( $health_notices as $notice ) {
				printf(
					'<div class="notice notice-%s"><p>%s</p></div>',
					esc_attr( $notice['type'] ),
					wp_kses_post( $notice['message'] )
				);
			}
		}
	}

	/**
	 * Run a series of environment health checks and return an array of notice arrays.
	 *
	 * Each element has keys 'type' (error|warning) and 'message'.
	 *
	 * @since  1.0.0
	 *
	 * @return array<int, array{type: string, message: string}>
	 */
	private function check_environment_health() {
		$notices = array();

		// Check executor availability.
		$executor = WST_Terminal::detect_executor();
		if ( is_wp_error( $executor ) ) {
			$notices[] = array(
				'type'    => 'error',
				'message' => __( 'WP Server Terminal: No command execution functions available (proc_open, exec, shell_exec are all disabled). Shell terminal will not work. Check your PHP disable_functions setting.', 'wp-server-terminal' ),
			);
		} elseif ( 'proc_open' !== $executor ) {
			$notices[] = array(
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: %s: executor function name */
					__( 'WP Server Terminal: proc_open is disabled. Using %s as fallback. CWD tracking and stderr separation are limited.', 'wp-server-terminal' ),
					esc_html( $executor )
				),
			);
		}

		// WP-CLI check.
		if ( WST_Settings::get( 'enable_wpcli', true ) && ! WST_WPCLI::is_available() ) {
			$notices[] = array(
				'type'    => 'warning',
				'message' => __( 'WP Server Terminal: WP-CLI not found. WP-CLI runner is enabled in settings but the wp binary could not be located. Install WP-CLI or disable this feature in settings.', 'wp-server-terminal' ),
			);
		}

		// PHP max_execution_time check.
		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( $max_exec > 0 && $max_exec < 30 ) {
			$notices[] = array(
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: %d: max_execution_time value */
					__( 'WP Server Terminal: PHP max_execution_time is set to %d seconds, which may cause long-running commands to be killed by PHP before the plugin timeout.', 'wp-server-terminal' ),
					$max_exec
				),
			);
		}

		// Linux check.
		if ( 'Linux' !== PHP_OS_FAMILY ) {
			$notices[] = array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: OS name */
					__( 'WP Server Terminal: This plugin requires a Linux server. Detected OS: %s. Shell terminal features will not work.', 'wp-server-terminal' ),
					esc_html( PHP_OS )
				),
			);
		}

		return $notices;
	}

	// ---------------------------------------------------------------------------
	// Render Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Perform the common security and authentication checks shared by every page.
	 *
	 * Outputs the HTTPS warning when applicable, and either renders the
	 * re-authentication form or returns true to signal that the caller may
	 * proceed to render page content.
	 *
	 * @since  1.0.0
	 *
	 * @return bool True when the user is re-authenticated and content may render.
	 */
	private function run_page_checks() {
		// -----------------------------------------------------------------------
		// Capability guard — terminates the request for unauthorised users.
		// -----------------------------------------------------------------------
		if ( ! WST_Capabilities::current_user_can() ) {
			wp_die(
				esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-server-terminal' ),
				esc_html__( 'Access Denied', 'wp-server-terminal' ),
				array( 'response' => 403 )
			);
		}

		// -----------------------------------------------------------------------
		// HTTPS warning — shown on every page load when the site is not secure.
		// -----------------------------------------------------------------------
		if ( ! WST_Security::is_https() ) {
			echo '<div class="notice notice-error" style="border-left-color:#dc3232;">';
			echo '<p><strong>';
			esc_html_e( 'Security Warning:', 'wp-server-terminal' );
			echo '</strong> ';
			esc_html_e( 'This site is not served over HTTPS. All data transmitted through this plugin — including shell commands and file contents — is unencrypted and may be intercepted.', 'wp-server-terminal' );
			echo '</p></div>';
		}

		// -----------------------------------------------------------------------
		// Re-authentication gate.
		// -----------------------------------------------------------------------
		if ( ! WST_Security::is_reauthed() ) {
			$this->render_reauth_form();
			return false;
		}

		return true;
	}

	/**
	 * Render the re-authentication form and process any submitted password.
	 *
	 * When the user submits the form the password is verified via
	 * WST_Security::verify_reauth(). On success the page refreshes to strip the
	 * POST data; on failure an inline error message is displayed.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	private function render_reauth_form() {
		$error_message = '';

		// Process a submitted password.
		if ( isset( $_POST['wst_password'] ) ) {
			// Verify the nonce before touching any POST data.
			if ( ! isset( $_POST['wst_reauth_nonce'] ) ||
				! wp_verify_nonce(
					sanitize_text_field( wp_unslash( $_POST['wst_reauth_nonce'] ) ),
					'wst_reauth'
				)
			) {
				$error_message = esc_html__( 'Security check failed. Please try again.', 'wp-server-terminal' );
			} else {
				$password = wp_unslash( $_POST['wst_password'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$result   = WST_Security::verify_reauth( $password );

				if ( true === $result ) {
					// Redirect to clear POST data and reload the page authenticated.
					wp_safe_redirect( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
					exit;
				}

				if ( is_wp_error( $result ) ) {
					$error_message = esc_html( $result->get_error_message() );
				}
			}
		}

		// -----------------------------------------------------------------------
		// Render the form.
		// -----------------------------------------------------------------------
		?>
		<div class="wrap wst-reauth-wrap" style="max-width:420px;margin:60px auto;text-align:center;">
			<h2><?php esc_html_e( 'Authentication Required', 'wp-server-terminal' ); ?></h2>
			<p><?php esc_html_e( 'Enter your WordPress password to continue.', 'wp-server-terminal' ); ?></p>

			<?php if ( '' !== $error_message ) : ?>
				<div class="notice notice-error inline" style="text-align:left;">
					<p><?php echo esc_html( $error_message ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" style="margin-top:16px;">
				<?php wp_nonce_field( 'wst_reauth', 'wst_reauth_nonce' ); ?>
				<p>
					<input
						type="password"
						name="wst_password"
						id="wst_password"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Password', 'wp-server-terminal' ); ?>"
						autocomplete="current-password"
						required
						style="width:100%;"
					>
				</p>
				<p>
					<?php
					submit_button(
						__( 'Verify Identity', 'wp-server-terminal' ),
						'primary',
						'wst_reauth_submit',
						false,
						array( 'style' => 'width:100%;' )
					);
					?>
				</p>
			</form>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Page Render Methods
	// ---------------------------------------------------------------------------

	/**
	 * Render the terminal admin page.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_terminal_page() {
		if ( ! $this->run_page_checks() ) {
			return;
		}

		require_once WST_PLUGIN_DIR . 'admin/pages/terminal.php';
	}

	/**
	 * Render the file manager admin page.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_file_manager_page() {
		if ( ! $this->run_page_checks() ) {
			return;
		}

		require_once WST_PLUGIN_DIR . 'admin/pages/file-manager.php';
	}

	/**
	 * Render the database manager admin page.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_database_page() {
		if ( ! $this->run_page_checks() ) {
			return;
		}

		require_once WST_PLUGIN_DIR . 'admin/pages/database.php';
	}

	/**
	 * Render the audit log admin page.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_audit_log_page() {
		if ( ! $this->run_page_checks() ) {
			return;
		}

		require_once WST_PLUGIN_DIR . 'admin/pages/audit-log.php';
	}

	/**
	 * Render the settings admin page.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! $this->run_page_checks() ) {
			return;
		}

		require_once WST_PLUGIN_DIR . 'admin/pages/settings.php';
	}
}
