<?php
/**
 * Audit log class.
 *
 * Provides a static interface for writing and querying audit log entries stored
 * in the {prefix}wst_audit_log database table. Log entries record who ran what
 * command, from which IP address, and whether the operation succeeded.
 *
 * A scheduled daily cleanup prunes entries older than the configured retention
 * window so the table does not grow unboundedly.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_Audit_Log
 *
 * Static helper layer for all audit-log operations.
 *
 * @since 1.0.0
 */
class WST_Audit_Log {

	// ---------------------------------------------------------------------------
	// Action type constants
	// ---------------------------------------------------------------------------

	/**
	 * Action type: shell command execution.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const ACTION_COMMAND = 'command_exec';

	/**
	 * Action type: WP-CLI command execution.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const ACTION_WPCLI = 'wpcli_exec';

	/**
	 * Action type: file read via the file manager.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const ACTION_FILE_READ = 'file_read';

	/**
	 * Action type: file write via the file manager.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const ACTION_FILE_WRITE = 'file_write';

	/**
	 * Action type: file deletion via the file manager.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const ACTION_FILE_DELETE = 'file_delete';

	/**
	 * Action type: file upload via the file manager.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const ACTION_FILE_UPLOAD = 'file_upload';

	/**
	 * Action type: SQL query execution via the database manager.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const ACTION_SQL_EXEC = 'sql_exec';

	/**
	 * Action type: user login event.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const ACTION_LOGIN = 'login';

	/**
	 * Action type: plugin settings change.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const ACTION_SETTINGS = 'settings_change';

	// ---------------------------------------------------------------------------
	// Write
	// ---------------------------------------------------------------------------

	/**
	 * Insert a new entry into the audit log.
	 *
	 * Captures the current user's ID and client IP automatically so callers
	 * only need to supply contextual information about the action.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $action_type   One of the ACTION_* class constants.
	 * @param  string $command       The raw command string or a short description
	 *                               of the operation being logged. Truncated to
	 *                               65 535 bytes before storage.
	 * @param  string $result_status 'success' or 'failure'. Defaults to 'success'.
	 * @param  string $severity      'info', 'warning', or 'critical'. Defaults to 'info'.
	 * @return int|false             The insert ID on success, false on failure.
	 */
	public static function log( $action_type, $command, $result_status = 'success', $severity = 'info' ) {
		global $wpdb;

		$user_id = get_current_user_id();
		$user_ip = WST_Security::get_client_ip();

		// TEXT columns in MySQL accept up to 65 535 bytes; guard against overflows.
		$command = substr( $command, 0, 65535 );

		$wpdb->insert(
			$wpdb->prefix . 'wst_audit_log',
			compact( 'user_id', 'user_ip', 'action_type', 'command', 'result_status', 'severity' ) + array(
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id ? $wpdb->insert_id : false;
	}

	// ---------------------------------------------------------------------------
	// Read
	// ---------------------------------------------------------------------------

	/**
	 * Retrieve a paginated, filtered set of audit log entries.
	 *
	 * All filter arguments are optional. Any combination may be supplied; only
	 * the non-empty / non-zero values are appended as WHERE clauses.
	 *
	 * @since  1.0.0
	 *
	 * @param  array $args {
	 *     Optional query arguments.
	 *
	 *     @type int    $per_page    Rows per page. Default 50.
	 *     @type int    $page        1-based page number. Default 1.
	 *     @type int    $user_id     Filter by WordPress user ID. 0 means all. Default 0.
	 *     @type string $action_type Filter by action type constant value. Default ''.
	 *     @type string $severity    Filter by severity level. Default ''.
	 *     @type string $date_from   Inclusive lower date bound (MySQL-compatible). Default ''.
	 *     @type string $date_to     Inclusive upper date bound (MySQL-compatible). Default ''.
	 *     @type string $orderby     Column to sort by. Default 'created_at'.
	 *     @type string $order       Sort direction, 'ASC' or 'DESC'. Default 'DESC'.
	 * }
	 * @return array {
	 *     @type object[] $items  Row objects from wpdb.
	 *     @type int      $total  Total matching rows across all pages.
	 *     @type int      $pages  Total number of pages.
	 * }
	 */
	public static function get_logs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page'    => 50,
			'page'        => 1,
			'user_id'     => 0,
			'action_type' => '',
			'severity'    => '',
			'date_from'   => '',
			'date_to'     => '',
			'orderby'     => 'created_at',
			'order'       => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$table      = $wpdb->prefix . 'wst_audit_log';
		$where      = array( '1=1' );
		$where_args = array();

		if ( ! empty( $args['user_id'] ) ) {
			$where[]      = 'user_id = %d';
			$where_args[] = (int) $args['user_id'];
		}

		if ( ! empty( $args['action_type'] ) ) {
			$where[]      = 'action_type = %s';
			$where_args[] = $args['action_type'];
		}

		if ( ! empty( $args['severity'] ) ) {
			$where[]      = 'severity = %s';
			$where_args[] = $args['severity'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]      = 'created_at >= %s';
			$where_args[] = $args['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]      = 'created_at <= %s';
			$where_args[] = $args['date_to'] . ' 23:59:59';
		}

		$where_clause = implode( ' AND ', $where );

		// Sanitise ORDER BY against a known whitelist to prevent SQL injection.
		$allowed_orderby = array( 'created_at', 'action_type', 'severity', 'user_id', 'result_status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		// Build and execute the count query first.
		if ( ! empty( $where_args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} WHERE {$where_clause}",
					$where_args
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}" );
		}

		// Build and execute the data query.
		$data_sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$data_args   = array_merge( $where_args, array( $per_page, $offset ) );
		$items       = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$data_sql,
				$data_args
			)
		);

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
			'pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Return the total count of audit log entries matching the supplied filters.
	 *
	 * Accepts the same filter arguments as get_logs() but ignores pagination
	 * parameters (per_page, page, orderby, order).
	 *
	 * @since  1.0.0
	 *
	 * @param  array $args Same filter keys as get_logs(). Pagination keys ignored.
	 * @return int         Total number of matching rows.
	 */
	public static function get_log_count( $args = array() ) {
		$result = self::get_logs(
			array_merge(
				$args,
				array(
					'per_page' => PHP_INT_MAX,
					'page'     => 1,
				)
			)
		);

		return (int) $result['total'];
	}

	// ---------------------------------------------------------------------------
	// Maintenance
	// ---------------------------------------------------------------------------

	/**
	 * Delete audit log entries older than the configured retention window.
	 *
	 * Reads the `log_retention_days` plugin setting (defaulting to 90 days when
	 * the option is absent) and issues a single DELETE query. This method is
	 * called by the `wst_cleanup_logs` daily cron event.
	 *
	 * @since  1.0.0
	 *
	 * @return int|false Number of rows deleted, or false on database error.
	 */
	public static function cleanup() {
		global $wpdb;

		$days = (int) WST_Settings::get( 'log_retention_days', 90 );
		$days = max( 1, $days );

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wst_audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}
}
