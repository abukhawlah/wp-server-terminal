<?php
/**
 * Audit log admin page template.
 *
 * Included by WST_Admin_Menu when rendering the plugin audit log page.
 * Defines WST_Audit_Log_Table (a WP_List_Table subclass) inline — this is a
 * page template that is loaded on demand, not autoloaded with the plugin, so
 * the class definition lives here rather than in includes/.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Capability check — must happen before any output is generated.
if ( ! WST_Capabilities::current_user_can() ) {
	wp_die(
		esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-server-terminal' ),
		esc_html__( 'Access Denied', 'wp-server-terminal' ),
		array( 'response' => 403 )
	);
}

// WP_List_Table is not loaded on every admin page — pull it in when needed.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// ---------------------------------------------------------------------------
// WP_List_Table subclass
// ---------------------------------------------------------------------------

/**
 * Class WST_Audit_Log_Table
 *
 * Renders a paginated, sortable, filterable table of audit log entries using
 * the WordPress list-table API.
 *
 * @since 1.0.0
 */
class WST_Audit_Log_Table extends WP_List_Table {

	/**
	 * Set up the list table with appropriate singular/plural labels.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'audit log entry', 'wp-server-terminal' ),
				'plural'   => __( 'audit log entries', 'wp-server-terminal' ),
				'ajax'     => false,
			)
		);
	}

	// ---------------------------------------------------------------------------
	// Column definitions
	// ---------------------------------------------------------------------------

	/**
	 * Return all column definitions for the table header.
	 *
	 * @since  1.0.0
	 *
	 * @return array Associative array of column_key => column label.
	 */
	public function get_columns() {
		return array(
			'date'          => __( 'Date', 'wp-server-terminal' ),
			'user'          => __( 'User', 'wp-server-terminal' ),
			'action_type'   => __( 'Action', 'wp-server-terminal' ),
			'command'       => __( 'Command', 'wp-server-terminal' ),
			'result_status' => __( 'Status', 'wp-server-terminal' ),
			'severity'      => __( 'Severity', 'wp-server-terminal' ),
			'user_ip'       => __( 'IP Address', 'wp-server-terminal' ),
		);
	}

	/**
	 * Return the sortable column definitions.
	 *
	 * Each value is an array of [orderby_value, is_already_sorted_desc].
	 *
	 * @since  1.0.0
	 *
	 * @return array Sortable column map.
	 */
	protected function get_sortable_columns() {
		return array(
			'date'        => array( 'created_at', true ),
			'action_type' => array( 'action_type', false ),
			'severity'    => array( 'severity', false ),
		);
	}

	// ---------------------------------------------------------------------------
	// Data loading
	// ---------------------------------------------------------------------------

	/**
	 * Query the database and populate $this->items with the current page's rows.
	 *
	 * Also sets up the pagination state so that WP_List_Table can render the
	 * navigation controls correctly.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 50;
		$page     = $this->get_pagenum();

		// Read filter values from the request (GET form).
		$action_type = isset( $_REQUEST['wst_action_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wst_action_type'] ) ) : '';
		$severity    = isset( $_REQUEST['wst_severity'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wst_severity'] ) ) : '';
		$date_from   = isset( $_REQUEST['wst_date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wst_date_from'] ) ) : '';
		$date_to     = isset( $_REQUEST['wst_date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wst_date_to'] ) ) : '';

		// Resolve sorting parameters from the request.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'DESC';

		$result = WST_Audit_Log::get_logs(
			array(
				'per_page'    => $per_page,
				'page'        => $page,
				'action_type' => $action_type,
				'severity'    => $severity,
				'date_from'   => $date_from,
				'date_to'     => $date_to,
				'orderby'     => $orderby,
				'order'       => $order,
			)
		);

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => $result['pages'],
			)
		);
	}

	// ---------------------------------------------------------------------------
	// Column rendering
	// ---------------------------------------------------------------------------

	/**
	 * Render an individual cell for columns that do not have a dedicated method.
	 *
	 * @since  1.0.0
	 *
	 * @param  object $item        The current row object.
	 * @param  string $column_name The column key being rendered.
	 * @return string              HTML-safe cell content.
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {

			case 'date':
				return $this->column_date( $item );

			case 'user':
				$user = get_user_by( 'id', (int) $item->user_id );
				return $user instanceof WP_User
					? esc_html( $user->user_login )
					: esc_html__( 'Unknown', 'wp-server-terminal' );

			case 'action_type':
				return esc_html( $item->action_type );

			case 'command':
				$truncated = mb_strlen( $item->command ) > 100
					? mb_substr( $item->command, 0, 100 ) . '&hellip;'
					: $item->command;
				return esc_html( $truncated );

			case 'result_status':
				if ( 'success' === $item->result_status ) {
					return '<span style="color:#46b450;font-weight:600;">' . esc_html__( 'Success', 'wp-server-terminal' ) . '</span>';
				}
				return '<span style="color:#dc3232;font-weight:600;">' . esc_html__( 'Failure', 'wp-server-terminal' ) . '</span>';

			case 'severity':
				switch ( $item->severity ) {
					case 'warning':
						return '<span style="color:#f56e28;font-weight:600;">' . esc_html__( 'Warning', 'wp-server-terminal' ) . '</span>';
					case 'critical':
						return '<span style="color:#dc3232;font-weight:600;">' . esc_html__( 'Critical', 'wp-server-terminal' ) . '</span>';
					default: // info
						return '<span style="color:#72777c;">' . esc_html__( 'Info', 'wp-server-terminal' ) . '</span>';
				}

			case 'user_ip':
				return esc_html( $item->user_ip );

			default:
				return '';
		}
	}

	/**
	 * Render the 'date' column cell.
	 *
	 * Formats the stored UTC-equivalent MySQL datetime using the site's
	 * configured date and time format strings.
	 *
	 * @since  1.0.0
	 *
	 * @param  object $item The current row object.
	 * @return string       HTML-safe formatted date/time string.
	 */
	public function column_date( $item ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return esc_html( wp_date( $format, strtotime( $item->created_at ) ) );
	}

	/**
	 * Output the message shown when the table has no rows.
	 *
	 * @since  1.0.0
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No audit log entries found.', 'wp-server-terminal' );
	}

	// ---------------------------------------------------------------------------
	// Extra navigation (filter controls)
	// ---------------------------------------------------------------------------

	/**
	 * Render the filter bar above (or below) the table.
	 *
	 * Outputs dropdowns for action type and severity, date-range inputs, and a
	 * submit button. The controls POST back to the same page via the enclosing
	 * form rendered by the page template.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $which 'top' or 'bottom' — the position of the nav bar.
	 * @return void
	 */
	public function extra_tablenav( $which ) {
		// Only render the filter controls in the top nav bar.
		if ( 'top' !== $which ) {
			return;
		}

		$current_action_type = isset( $_REQUEST['wst_action_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wst_action_type'] ) ) : '';
		$current_severity    = isset( $_REQUEST['wst_severity'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wst_severity'] ) ) : '';
		$current_date_from   = isset( $_REQUEST['wst_date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wst_date_from'] ) ) : '';
		$current_date_to     = isset( $_REQUEST['wst_date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wst_date_to'] ) ) : '';

		$action_types = array(
			''                                => __( 'All Actions', 'wp-server-terminal' ),
			WST_Audit_Log::ACTION_COMMAND     => __( 'Shell Command', 'wp-server-terminal' ),
			WST_Audit_Log::ACTION_WPCLI       => __( 'WP-CLI', 'wp-server-terminal' ),
			WST_Audit_Log::ACTION_FILE_READ   => __( 'File Read', 'wp-server-terminal' ),
			WST_Audit_Log::ACTION_FILE_WRITE  => __( 'File Write', 'wp-server-terminal' ),
			WST_Audit_Log::ACTION_FILE_DELETE => __( 'File Delete', 'wp-server-terminal' ),
			WST_Audit_Log::ACTION_FILE_UPLOAD => __( 'File Upload', 'wp-server-terminal' ),
			WST_Audit_Log::ACTION_SQL_EXEC    => __( 'SQL Query', 'wp-server-terminal' ),
			WST_Audit_Log::ACTION_LOGIN       => __( 'Login', 'wp-server-terminal' ),
			WST_Audit_Log::ACTION_SETTINGS    => __( 'Settings Change', 'wp-server-terminal' ),
		);

		$severities = array(
			''         => __( 'All Severities', 'wp-server-terminal' ),
			'info'     => __( 'Info', 'wp-server-terminal' ),
			'warning'  => __( 'Warning', 'wp-server-terminal' ),
			'critical' => __( 'Critical', 'wp-server-terminal' ),
		);

		?>
		<div class="alignleft actions">

			<label for="wst-filter-action-type" class="screen-reader-text">
				<?php esc_html_e( 'Filter by action type', 'wp-server-terminal' ); ?>
			</label>
			<select id="wst-filter-action-type" name="wst_action_type">
				<?php foreach ( $action_types as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_action_type, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="wst-filter-severity" class="screen-reader-text">
				<?php esc_html_e( 'Filter by severity', 'wp-server-terminal' ); ?>
			</label>
			<select id="wst-filter-severity" name="wst_severity">
				<?php foreach ( $severities as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_severity, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="wst-filter-date-from" class="screen-reader-text">
				<?php esc_html_e( 'Date from', 'wp-server-terminal' ); ?>
			</label>
			<input
				type="date"
				id="wst-filter-date-from"
				name="wst_date_from"
				value="<?php echo esc_attr( $current_date_from ); ?>"
				placeholder="<?php esc_attr_e( 'Date from', 'wp-server-terminal' ); ?>"
			/>

			<label for="wst-filter-date-to" class="screen-reader-text">
				<?php esc_html_e( 'Date to', 'wp-server-terminal' ); ?>
			</label>
			<input
				type="date"
				id="wst-filter-date-to"
				name="wst_date_to"
				value="<?php echo esc_attr( $current_date_to ); ?>"
				placeholder="<?php esc_attr_e( 'Date to', 'wp-server-terminal' ); ?>"
			/>

			<?php submit_button( __( 'Filter', 'wp-server-terminal' ), 'secondary', 'wst_filter_submit', false ); ?>

		</div>
		<?php
	}
}

// ---------------------------------------------------------------------------
// Instantiate and prepare the table before any output.
// ---------------------------------------------------------------------------

$wst_audit_table = new WST_Audit_Log_Table();
$wst_audit_table->prepare_items();

// Preserve the current admin page slug so the filter form posts back correctly.
$wst_page_slug = isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '';

?>
<div class="wrap wst-wrap">

	<h1><?php esc_html_e( 'Audit Log', 'wp-server-terminal' ); ?></h1>

	<form method="get" action="">
		<?php
		// Keep WordPress routing parameters in the hidden form so that filter
		// submissions stay on the correct admin page.
		?>
		<input type="hidden" name="page" value="<?php echo esc_attr( $wst_page_slug ); ?>" />

		<?php $wst_audit_table->display(); ?>
	</form>

</div>
