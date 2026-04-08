<?php
/**
 * Plugin settings class.
 *
 * Registers all plugin settings, sections, and fields using the WordPress
 * Settings API, and provides static accessors for reading stored values from
 * anywhere in the plugin.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_Settings
 *
 * Manages plugin settings using the WordPress Settings API.
 *
 * @since 1.0.0
 */
class WST_Settings {

	/**
	 * Wire up the Settings API registration on admin_init.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	// ---------------------------------------------------------------------------
	// Settings API registration
	// ---------------------------------------------------------------------------

	/**
	 * Register the setting, sections, and fields for the settings page.
	 *
	 * Called on the admin_init hook.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'wst_settings_group',
			'wst_settings',
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);

		// -----------------------------------------------------------------------
		// Sections
		// -----------------------------------------------------------------------

		add_settings_section(
			'wst_access_control',
			__( 'Access Control', 'wp-server-terminal' ),
			array( $this, 'render_section_access_control' ),
			'wst-settings'
		);

		add_settings_section(
			'wst_terminal_settings',
			__( 'Terminal Settings', 'wp-server-terminal' ),
			array( $this, 'render_section_terminal_settings' ),
			'wst-settings'
		);

		add_settings_section(
			'wst_security_settings',
			__( 'Security', 'wp-server-terminal' ),
			array( $this, 'render_section_security_settings' ),
			'wst-settings'
		);

		add_settings_section(
			'wst_feature_toggles',
			__( 'Features', 'wp-server-terminal' ),
			array( $this, 'render_section_feature_toggles' ),
			'wst-settings'
		);

		// -----------------------------------------------------------------------
		// Fields — Access Control
		// -----------------------------------------------------------------------

		add_settings_field(
			'wst_allowed_ips',
			__( 'IP Allowlist (leave empty to allow all IPs)', 'wp-server-terminal' ),
			array( $this, 'render_field_allowed_ips' ),
			'wst-settings',
			'wst_access_control'
		);

		// -----------------------------------------------------------------------
		// Fields — Terminal Settings
		// -----------------------------------------------------------------------

		add_settings_field(
			'wst_session_timeout',
			__( 'Re-auth Session Timeout (seconds)', 'wp-server-terminal' ),
			array( $this, 'render_field_session_timeout' ),
			'wst-settings',
			'wst_terminal_settings'
		);

		add_settings_field(
			'wst_max_output_size',
			__( 'Max Output Size (bytes)', 'wp-server-terminal' ),
			array( $this, 'render_field_max_output_size' ),
			'wst-settings',
			'wst_terminal_settings'
		);

		add_settings_field(
			'wst_command_timeout',
			__( 'Command Timeout (seconds)', 'wp-server-terminal' ),
			array( $this, 'render_field_command_timeout' ),
			'wst-settings',
			'wst_terminal_settings'
		);

		// -----------------------------------------------------------------------
		// Fields — Security
		// -----------------------------------------------------------------------

		add_settings_field(
			'wst_blocked_commands',
			__( 'Blocked Command Patterns', 'wp-server-terminal' ),
			array( $this, 'render_field_blocked_commands' ),
			'wst-settings',
			'wst_security_settings'
		);

		// -----------------------------------------------------------------------
		// Fields — Feature Toggles
		// -----------------------------------------------------------------------

		add_settings_field(
			'wst_enable_wpcli',
			__( 'Enable WP-CLI Runner', 'wp-server-terminal' ),
			array( $this, 'render_field_enable_wpcli' ),
			'wst-settings',
			'wst_feature_toggles'
		);

		add_settings_field(
			'wst_enable_file_manager',
			__( 'Enable File Manager', 'wp-server-terminal' ),
			array( $this, 'render_field_enable_file_manager' ),
			'wst-settings',
			'wst_feature_toggles'
		);

		add_settings_field(
			'wst_enable_db_manager',
			__( 'Enable Database Manager', 'wp-server-terminal' ),
			array( $this, 'render_field_enable_db_manager' ),
			'wst-settings',
			'wst_feature_toggles'
		);

		add_settings_field(
			'wst_log_retention_days',
			__( 'Audit Log Retention (days)', 'wp-server-terminal' ),
			array( $this, 'render_field_log_retention_days' ),
			'wst-settings',
			'wst_feature_toggles'
		);

		add_settings_field(
			'wst_allowed_dirs',
			__( 'Allowed Filesystem Roots (default: WordPress root)', 'wp-server-terminal' ),
			array( $this, 'render_field_allowed_dirs' ),
			'wst-settings',
			'wst_feature_toggles'
		);
	}

	// ---------------------------------------------------------------------------
	// Section callbacks
	// ---------------------------------------------------------------------------

	/**
	 * Render the Access Control section description.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_section_access_control() {
		echo '<p>' . esc_html__( 'Restrict plugin access by IP address. Leave the allowlist empty to permit access from any IP.', 'wp-server-terminal' ) . '</p>';
	}

	/**
	 * Render the Terminal Settings section description.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_section_terminal_settings() {
		echo '<p>' . esc_html__( 'Configure terminal session and output behaviour.', 'wp-server-terminal' ) . '</p>';
	}

	/**
	 * Render the Security section description.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_section_security_settings() {
		echo '<p>' . esc_html__( 'Define shell command patterns that are unconditionally blocked.', 'wp-server-terminal' ) . '</p>';
	}

	/**
	 * Render the Features section description.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_section_feature_toggles() {
		echo '<p>' . esc_html__( 'Enable or disable individual plugin modules and configure audit log retention.', 'wp-server-terminal' ) . '</p>';
	}

	// ---------------------------------------------------------------------------
	// Field render callbacks
	// ---------------------------------------------------------------------------

	/**
	 * Render the allowed_ips textarea field.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_field_allowed_ips() {
		$value = self::get( 'allowed_ips', array() );

		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}

		printf(
			'<textarea id="wst_allowed_ips" name="wst_settings[allowed_ips]" rows="6" cols="50" class="large-text code">%s</textarea>
			<p class="description">%s</p>',
			esc_textarea( $value ),
			esc_html__( 'Enter one IP address per line. Both IPv4 and IPv6 addresses are accepted. Leave empty to allow all IPs.', 'wp-server-terminal' )
		);
	}

	/**
	 * Render the session_timeout number field.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_field_session_timeout() {
		$value = self::get( 'session_timeout', 1800 );

		printf(
			'<input type="number" id="wst_session_timeout" name="wst_settings[session_timeout]" value="%s" min="300" max="86400" class="small-text" />
			<p class="description">%s</p>',
			esc_attr( $value ),
			esc_html__( 'Minimum 300 (5 minutes). Maximum 86400 (24 hours).', 'wp-server-terminal' )
		);
	}

	/**
	 * Render the max_output_size number field.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_field_max_output_size() {
		$value = self::get( 'max_output_size', 1048576 );

		printf(
			'<input type="number" id="wst_max_output_size" name="wst_settings[max_output_size]" value="%s" min="102400" max="10485760" class="small-text" />
			<p class="description">%s</p>',
			esc_attr( $value ),
			esc_html__( 'Minimum 102400 (100 KiB). Maximum 10485760 (10 MiB).', 'wp-server-terminal' )
		);
	}

	/**
	 * Render the command_timeout number field.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_field_command_timeout() {
		$value = self::get( 'command_timeout', 30 );

		printf(
			'<input type="number" id="wst_command_timeout" name="wst_settings[command_timeout]" value="%s" min="5" max="3600" class="small-text" />
			<p class="description">%s</p>',
			esc_attr( $value ),
			esc_html__( 'Minimum 5 seconds. Maximum 3600 (1 hour).', 'wp-server-terminal' )
		);
	}

	/**
	 * Render the blocked_commands textarea field.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_field_blocked_commands() {
		$value = self::get( 'blocked_commands', array() );

		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}

		printf(
			'<textarea id="wst_blocked_commands" name="wst_settings[blocked_commands]" rows="8" cols="50" class="large-text code">%s</textarea>
			<p class="description">%s</p>',
			esc_textarea( $value ),
			esc_html__( 'Enter one pattern per line. Commands containing any of these strings will be blocked unconditionally.', 'wp-server-terminal' )
		);
	}

	/**
	 * Render the enable_wpcli checkbox field.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_field_enable_wpcli() {
		$checked = self::get( 'enable_wpcli', true );

		printf(
			'<label><input type="checkbox" id="wst_enable_wpcli" name="wst_settings[enable_wpcli]" value="1" %s /> %s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Allow authorised users to run WP-CLI commands from the admin.', 'wp-server-terminal' )
		);
	}

	/**
	 * Render the enable_file_manager checkbox field.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_field_enable_file_manager() {
		$checked = self::get( 'enable_file_manager', true );

		printf(
			'<label><input type="checkbox" id="wst_enable_file_manager" name="wst_settings[enable_file_manager]" value="1" %s /> %s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Allow authorised users to browse and manage server files.', 'wp-server-terminal' )
		);
	}

	/**
	 * Render the enable_db_manager checkbox field.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_field_enable_db_manager() {
		$checked = self::get( 'enable_db_manager', true );

		printf(
			'<label><input type="checkbox" id="wst_enable_db_manager" name="wst_settings[enable_db_manager]" value="1" %s /> %s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Allow authorised users to inspect and query the WordPress database.', 'wp-server-terminal' )
		);
	}

	/**
	 * Render the log_retention_days number field.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_field_log_retention_days() {
		$value = self::get( 'log_retention_days', 90 );

		printf(
			'<input type="number" id="wst_log_retention_days" name="wst_settings[log_retention_days]" value="%s" min="1" max="3650" class="small-text" />
			<p class="description">%s</p>',
			esc_attr( $value ),
			esc_html__( 'Audit log entries older than this many days are automatically deleted by the daily cron. Minimum 1, maximum 3650 (10 years).', 'wp-server-terminal' )
		);
	}

	/**
	 * Render the allowed_dirs textarea field.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function render_field_allowed_dirs() {
		$value = self::get( 'allowed_dirs', array( ABSPATH ) );

		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}

		printf(
			'<textarea id="wst_allowed_dirs" name="wst_settings[allowed_dirs]" rows="6" cols="50" class="large-text code">%s</textarea>
			<p class="description">%s</p>',
			esc_textarea( $value ),
			esc_html__( 'Enter one absolute server path per line. The file manager will only allow access within these directories. Leave empty to default to the WordPress root.', 'wp-server-terminal' )
		);
	}

	// ---------------------------------------------------------------------------
	// Sanitization
	// ---------------------------------------------------------------------------

	/**
	 * Sanitize and validate all settings before they are stored.
	 *
	 * @since  1.0.0
	 *
	 * @param  array $input Raw input values from the settings form.
	 * @return array        Sanitized settings array ready for storage.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		// session_timeout: integer, clamped to [300, 86400].
		$session_timeout              = absint( isset( $input['session_timeout'] ) ? $input['session_timeout'] : 1800 );
		$sanitized['session_timeout'] = max( 300, min( 86400, $session_timeout ) );

		// max_output_size: integer, clamped to [102400, 10485760].
		$max_output_size              = absint( isset( $input['max_output_size'] ) ? $input['max_output_size'] : 1048576 );
		$sanitized['max_output_size'] = max( 102400, min( 10485760, $max_output_size ) );

		// command_timeout: integer, clamped to [5, 3600].
		$command_timeout              = absint( isset( $input['command_timeout'] ) ? $input['command_timeout'] : 30 );
		$sanitized['command_timeout'] = max( 5, min( 3600, $command_timeout ) );

		// log_retention_days: integer, clamped to [1, 3650].
		$log_retention_days              = absint( isset( $input['log_retention_days'] ) ? $input['log_retention_days'] : 90 );
		$sanitized['log_retention_days'] = max( 1, min( 3650, $log_retention_days ) );

		// blocked_commands: array built from newline-delimited textarea value.
		$blocked_raw                    = isset( $input['blocked_commands'] ) ? $input['blocked_commands'] : '';
		$sanitized['blocked_commands']  = array_values(
			array_filter(
				array_map( 'trim', explode( "\n", $blocked_raw ) )
			)
		);

		// allowed_ips: array of validated IP addresses; invalid entries discarded.
		$ips_raw                  = isset( $input['allowed_ips'] ) ? $input['allowed_ips'] : '';
		$ips_lines                = array_filter( array_map( 'trim', explode( "\n", $ips_raw ) ) );
		$sanitized['allowed_ips'] = array_values(
			array_filter(
				$ips_lines,
				static function ( $ip ) {
					return false !== filter_var( $ip, FILTER_VALIDATE_IP );
				}
			)
		);

		// allowed_dirs: array of resolved absolute paths; unresolvable entries discarded.
		$dirs_raw                  = isset( $input['allowed_dirs'] ) ? $input['allowed_dirs'] : '';
		$dirs_lines                = array_filter( array_map( 'trim', explode( "\n", $dirs_raw ) ) );
		$sanitized['allowed_dirs'] = array_values(
			array_filter(
				array_map( 'realpath', $dirs_lines )
			)
		);

		// Feature toggle checkboxes: true only when the key is present in $input.
		$sanitized['enable_wpcli']        = (bool) isset( $input['enable_wpcli'] );
		$sanitized['enable_file_manager'] = (bool) isset( $input['enable_file_manager'] );
		$sanitized['enable_db_manager']   = (bool) isset( $input['enable_db_manager'] );

		return $sanitized;
	}

	// ---------------------------------------------------------------------------
	// Static accessors
	// ---------------------------------------------------------------------------

	/**
	 * Retrieve a single setting value by key.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $key     The setting key to retrieve.
	 * @param  mixed  $default Value to return when the key is absent.
	 * @return mixed           The stored value, or $default.
	 */
	public static function get( $key, $default = null ) {
		$settings = get_option( 'wst_settings', array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Retrieve all stored settings as an associative array.
	 *
	 * @since  1.0.0
	 *
	 * @return array All stored plugin settings, or an empty array if none.
	 */
	public static function get_all() {
		return get_option( 'wst_settings', array() );
	}
}
