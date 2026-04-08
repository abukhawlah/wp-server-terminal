<?php
/**
 * Database manager class.
 *
 * Provides a static interface for executing SQL queries, introspecting the
 * WordPress database schema, and exporting table data. All write and DDL
 * operations are recorded in the audit log. A dedicated mysqli connection is
 * maintained separately from $wpdb so that multi-result queries and
 * fetch_all() are available without affecting core WordPress behaviour.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_Database_Manager
 *
 * Static helper layer for SQL execution and database introspection.
 *
 * @since 1.0.0
 */
class WST_Database_Manager {

	// ---------------------------------------------------------------------------
	// Properties
	// ---------------------------------------------------------------------------

	/**
	 * Cached mysqli connection instance, separate from $wpdb.
	 *
	 * Lazily initialised by get_connection() and reused for subsequent calls
	 * as long as the connection remains alive (verified via mysqli_ping).
	 *
	 * @since 1.0.0
	 * @var   mysqli|null
	 */
	private static $connection = null;

	// ---------------------------------------------------------------------------
	// Connection
	// ---------------------------------------------------------------------------

	/**
	 * Return (or lazily create) the shared mysqli connection.
	 *
	 * Credentials are read from the WordPress DB_* constants so no additional
	 * configuration is required. The connection is cached for the lifetime of
	 * the request and verified with mysqli_ping before reuse.
	 *
	 * @since  1.0.0
	 *
	 * @return mysqli|WP_Error A live mysqli instance, or WP_Error on failure.
	 */
	public static function get_connection() {
		// Reuse the cached connection when it is still alive.
		if ( null !== self::$connection && mysqli_ping( self::$connection ) ) {
			return self::$connection;
		}

		$charset = defined( 'DB_CHARSET' ) && DB_CHARSET ? DB_CHARSET : 'utf8mb4';

		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli -- direct connection is intentional.
		$conn = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );

		if ( $conn->connect_error ) {
			return new WP_Error(
				'db_connect',
				'Database connection failed: ' . $conn->connect_error
			);
		}

		$conn->set_charset( $charset );

		self::$connection = $conn;

		return $conn;
	}

	// ---------------------------------------------------------------------------
	// Introspection
	// ---------------------------------------------------------------------------

	/**
	 * Return metadata for every table in the current database.
	 *
	 * Rows are sourced from SHOW TABLE STATUS so engine, approximate row count,
	 * on-disk size, and collation are all available in a single query.
	 *
	 * @since  1.0.0
	 *
	 * @return array[] Array of associative arrays, each containing:
	 *                 - name        (string)  Table name.
	 *                 - engine      (string)  Storage engine (e.g. InnoDB).
	 *                 - rows        (int)     Approximate row count.
	 *                 - size_bytes  (int)     Data + index size in bytes.
	 *                 - size_human  (string)  Human-readable size (e.g. "4 MB").
	 *                 - collation   (string)  Default table collation.
	 */
	public static function list_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( ! $rows ) {
			return array();
		}

		$tables = array();

		foreach ( $rows as $row ) {
			$size_bytes = (int) $row['Data_length'] + (int) $row['Index_length'];

			$tables[] = array(
				'name'       => $row['Name'],
				'engine'     => $row['Engine'],
				'rows'       => (int) $row['Rows'],
				'size_bytes' => $size_bytes,
				'size_human' => size_format( $size_bytes ),
				'collation'  => $row['Collation'],
			);
		}

		return $tables;
	}

	/**
	 * Return column definitions for a single table.
	 *
	 * The table name is validated against a strict character allowlist and then
	 * verified to exist before any query is issued. These two checks prevent
	 * SQL injection without relying on $wpdb->prepare() with a table identifier.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $table Unquoted table name.
	 * @return array[]|WP_Error Column rows as ARRAY_A, or WP_Error on failure.
	 */
	public static function describe_table( $table ) {
		// Validate: only alphanumeric characters and underscores are permitted.
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return new WP_Error(
				'invalid_table_name',
				'Table name contains invalid characters.'
			);
		}

		global $wpdb;

		// Confirm the table actually exists before querying it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_col( 'SHOW TABLES' );

		if ( ! in_array( $table, $existing, true ) ) {
			return new WP_Error(
				'table_not_found',
				sprintf( 'Table "%s" does not exist in the current database.', $table )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$columns = $wpdb->get_results( 'DESCRIBE `' . esc_sql( $table ) . '`', ARRAY_A );

		return $columns ? $columns : array();
	}

	/**
	 * Return the total size of the current database in bytes and human-readable form.
	 *
	 * Queries information_schema.TABLES for the active database so the result
	 * reflects only tables belonging to the current WordPress installation.
	 *
	 * @since  1.0.0
	 *
	 * @return array {
	 *     @type int    $bytes  Total size in bytes.
	 *     @type string $human  Human-readable size string (e.g. "12 MB").
	 * }
	 */
	public static function get_database_size() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bytes = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length)
				 FROM   information_schema.TABLES
				 WHERE  table_schema = %s',
				DB_NAME
			)
		);

		return array(
			'bytes' => $bytes,
			'human' => size_format( $bytes ),
		);
	}

	// ---------------------------------------------------------------------------
	// Query execution
	// ---------------------------------------------------------------------------

	/**
	 * Execute an arbitrary SQL statement and return a structured result.
	 *
	 * Query type is detected from the first keyword and determines:
	 * - Whether a LIMIT clause is automatically appended to unbounded SELECTs.
	 * - The audit log severity (info / warning / critical).
	 * - The shape of the returned array.
	 *
	 * DROP DATABASE / DROP SCHEMA statements are always blocked. When the
	 * `block_ddl` setting is truthy, all other DDL is blocked as well.
	 *
	 * If $sql contains multiple statements separated by semicolons each is
	 * executed in sequence and an array of results is returned.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $sql Raw SQL to execute.
	 * @return array|WP_Error Structured result array (or array of result arrays
	 *                        for multi-statement input), or WP_Error on failure.
	 */
	public static function execute_query( $sql ) {
		$sql = trim( $sql );

		// Handle multiple statements: split on semicolons that are not inside
		// quoted strings using a quote-aware parser.
		if ( self::has_multiple_statements( $sql ) ) {
			// Quote-aware statement splitting
			$statements   = array();
			$current      = '';
			$in_sq        = false;
			$in_dq        = false;
			$in_bt        = false;
			$len          = strlen( $sql );
			for ( $i = 0; $i < $len; $i++ ) {
				$char = $sql[ $i ];
				$prev = $i > 0 ? $sql[ $i - 1 ] : '';
				if ( '\\' !== $prev ) {
					if ( "'" === $char && ! $in_dq && ! $in_bt ) {
						$in_sq = ! $in_sq;
					} elseif ( '"' === $char && ! $in_sq && ! $in_bt ) {
						$in_dq = ! $in_dq;
					} elseif ( '`' === $char && ! $in_sq && ! $in_dq ) {
						$in_bt = ! $in_bt;
					}
				}
				if ( ';' === $char && ! $in_sq && ! $in_dq && ! $in_bt ) {
					$trimmed = trim( $current );
					if ( '' !== $trimmed ) {
						$statements[] = $trimmed;
					}
					$current = '';
				} else {
					$current .= $char;
				}
			}
			$trimmed = trim( $current );
			if ( '' !== $trimmed ) {
				$statements[] = $trimmed;
			}

			$results = array();

			foreach ( $statements as $statement ) {
				$result = self::execute_single_query( $statement );
				$results[] = $result;

				// Abort the batch on error.
				if ( is_wp_error( $result ) ) {
					break;
				}
			}

			return $results;
		}

		return self::execute_single_query( $sql );
	}

	// ---------------------------------------------------------------------------
	// Export
	// ---------------------------------------------------------------------------

	/**
	 * Export a table's structure and data as a SQL dump or CSV file.
	 *
	 * The table name is validated before any query is issued. SQL exports
	 * include a CREATE TABLE statement followed by batched INSERT statements.
	 * CSV exports include a header row followed by one data row per record.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $table  Unquoted table name.
	 * @param  string $format Output format: 'sql' (default) or 'csv'.
	 * @return array|WP_Error {
	 *     @type string $content  Generated file content.
	 *     @type string $filename Suggested download filename.
	 *     @type string $format   The format that was used.
	 * }
	 */
	public static function export_table( $table, $format = 'sql' ) {
		// Validate: only alphanumeric characters and underscores are permitted.
		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
			return new WP_Error(
				'invalid_table_name',
				'Table name contains invalid characters.'
			);
		}

		global $wpdb;

		// Confirm the table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_col( 'SHOW TABLES' );

		if ( ! in_array( $table, $existing, true ) ) {
			return new WP_Error(
				'table_not_found',
				sprintf( 'Table "%s" does not exist in the current database.', $table )
			);
		}

		if ( 'csv' === $format ) {
			$content = self::export_table_csv( $table );
		} else {
			$format  = 'sql'; // Normalise unknown formats to sql.
			$content = self::export_table_sql( $table );
		}

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return array(
			'content'  => $content,
			'filename' => $table . '.' . $format,
			'format'   => $format,
		);
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Execute a single (non-compound) SQL statement.
	 *
	 * This is the internal workhorse called by execute_query() after multi-
	 * statement detection is handled. It classifies the query, enforces DDL
	 * guards, routes to the appropriate execution path, records an audit log
	 * entry, and returns a structured result.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param  string $sql A single SQL statement (no trailing semicolon required).
	 * @return array|WP_Error Structured result, or WP_Error on failure.
	 */
	private static function execute_single_query( $sql ) {
		$sql  = trim( $sql );
		$type = self::detect_query_type( $sql );

		// Always block DROP DATABASE / DROP SCHEMA regardless of settings.
		if ( preg_match( '/^\s*(DROP\s+DATABASE|DROP\s+SCHEMA)/i', $sql ) ) {
			return new WP_Error(
				'query_blocked',
				'DROP DATABASE is not allowed.'
			);
		}

		// Enforce configurable DDL blocking
		$ddl_types = array( 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME' );
		if ( in_array( $type, $ddl_types, true ) && WST_Settings::get( 'block_ddl', false ) ) {
			return new WP_Error( 'query_blocked', __( 'DDL statements are disabled in settings.', 'wp-server-terminal' ) );
		}

		// Determine audit severity based on query class.
		$write_types    = array( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE' );
		$ddl_types      = array( 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME' );
		$read_types     = array( 'SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'CALL' );

		if ( in_array( $type, $ddl_types, true ) ) {
			$severity = 'critical';
		} elseif ( in_array( $type, $write_types, true ) ) {
			$severity = 'warning';
		} else {
			$severity = 'info';
		}

		// Route to the appropriate handler.
		if ( in_array( $type, $read_types, true ) ) {
			return self::run_read_query( $sql, $type, $severity );
		}

		if ( in_array( $type, $write_types, true ) ) {
			return self::run_write_query( $sql, $severity );
		}

		if ( in_array( $type, $ddl_types, true ) ) {
			return self::run_ddl_query( $sql, $severity );
		}

		// Fallback: attempt to run as a generic write-style query.
		return self::run_write_query( $sql, $severity );
	}

	/**
	 * Execute a read-only query (SELECT, SHOW, DESCRIBE, EXPLAIN).
	 *
	 * Unbounded SELECT statements automatically receive a LIMIT 1000 clause.
	 * Results are returned as an associative-array matrix together with column
	 * names, row count, and execution time.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param  string $sql      The SQL statement to run.
	 * @param  string $type     Detected query type keyword (e.g. 'SELECT').
	 * @param  string $severity Audit log severity level.
	 * @return array|WP_Error   Structured read result, or WP_Error on failure.
	 */
	private static function run_read_query( $sql, $type, $severity ) {
		$limited = false;

		// Append LIMIT to unbounded SELECT queries to protect memory.
		if ( 'SELECT' === $type && ! preg_match( '/\bLIMIT\b/i', $sql ) ) {
			$sql    .= ' LIMIT 1000';
			$limited = true;
		}

		$start = microtime( true );

		$conn = self::get_connection();

		if ( is_wp_error( $conn ) ) {
			return $conn;
		}

		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli -- direct connection is intentional.
		$result = $conn->query( $sql );

		if ( false === $result ) {
			WST_Audit_Log::log(
				WST_Audit_Log::ACTION_SQL_EXEC,
				substr( $sql, 0, 1000 ),
				'failure',
				$severity
			);

			return new WP_Error( 'query_error', $conn->error );
		}

		$rows = $result->fetch_all( MYSQLI_ASSOC );

		// Derive column names from the first row, or from field metadata when
		// the result set is empty.
		if ( ! empty( $rows ) ) {
			$columns = array_keys( $rows[0] );
		} else {
			$fields  = $result->fetch_fields();
			$columns = $fields ? array_column( $fields, 'name' ) : array();
		}

		$result->free();

		$elapsed = round( ( microtime( true ) - $start ) * 1000, 2 );

		WST_Audit_Log::log(
			WST_Audit_Log::ACTION_SQL_EXEC,
			substr( $sql, 0, 1000 ),
			'success',
			$severity
		);

		return array(
			'type'             => 'select',
			'rows'             => $rows,
			'columns'          => $columns,
			'row_count'        => count( $rows ),
			'execution_time_ms' => $elapsed,
			'limited'          => $limited,
		);
	}

	/**
	 * Execute a DML write query (INSERT, UPDATE, DELETE, REPLACE).
	 *
	 * Returns affected row count, last insert ID, and execution time.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param  string $sql      The SQL statement to run.
	 * @param  string $severity Audit log severity level.
	 * @return array|WP_Error   Structured write result, or WP_Error on failure.
	 */
	private static function run_write_query( $sql, $severity ) {
		$start = microtime( true );

		$conn = self::get_connection();

		if ( is_wp_error( $conn ) ) {
			return $conn;
		}

		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli -- direct connection is intentional.
		$success = $conn->query( $sql );

		if ( false === $success ) {
			WST_Audit_Log::log(
				WST_Audit_Log::ACTION_SQL_EXEC,
				substr( $sql, 0, 1000 ),
				'failure',
				$severity
			);

			return new WP_Error( 'query_error', $conn->error );
		}

		$affected   = $conn->affected_rows;
		$insert_id  = $conn->insert_id;
		$elapsed    = round( ( microtime( true ) - $start ) * 1000, 2 );

		WST_Audit_Log::log(
			WST_Audit_Log::ACTION_SQL_EXEC,
			substr( $sql, 0, 1000 ),
			'success',
			$severity
		);

		return array(
			'type'             => 'write',
			'affected_rows'    => $affected,
			'insert_id'        => $insert_id,
			'execution_time_ms' => $elapsed,
		);
	}

	/**
	 * Execute a DDL statement (CREATE, ALTER, DROP, TRUNCATE).
	 *
	 * DDL is logged with 'critical' severity. Returns a success flag and
	 * execution time. Note that DROP DATABASE is blocked before this method
	 * is ever reached.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param  string $sql      The SQL statement to run.
	 * @param  string $severity Audit log severity level (always 'critical').
	 * @return array|WP_Error   Structured DDL result, or WP_Error on failure.
	 */
	private static function run_ddl_query( $sql, $severity ) {
		$start = microtime( true );

		$conn = self::get_connection();

		if ( is_wp_error( $conn ) ) {
			return $conn;
		}

		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli -- direct connection is intentional.
		$success = $conn->query( $sql );

		if ( false === $success ) {
			WST_Audit_Log::log(
				WST_Audit_Log::ACTION_SQL_EXEC,
				substr( $sql, 0, 1000 ),
				'failure',
				$severity
			);

			return new WP_Error( 'query_error', $conn->error );
		}

		$elapsed = round( ( microtime( true ) - $start ) * 1000, 2 );

		WST_Audit_Log::log(
			WST_Audit_Log::ACTION_SQL_EXEC,
			substr( $sql, 0, 1000 ),
			'success',
			$severity
		);

		return array(
			'type'             => 'ddl',
			'success'          => true,
			'execution_time_ms' => $elapsed,
		);
	}

	/**
	 * Detect the primary SQL keyword of a statement.
	 *
	 * Strips leading whitespace and comments (-- style), then extracts the
	 * first word and uppercases it for comparison against known type lists.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param  string $sql A single SQL statement.
	 * @return string      Uppercased first keyword (e.g. 'SELECT', 'INSERT').
	 */
	private static function detect_query_type( $sql ) {
		// Strip single-line comments before extracting the keyword.
		$stripped = preg_replace( '/^--[^\n]*\n?/m', '', $sql );
		$words    = preg_split( '/\s+/', ltrim( $stripped ), 2 );

		return strtoupper( $words[0] ?? '' );
	}

	/**
	 * Determine whether $sql contains more than one statement.
	 *
	 * Uses a quote-aware character-by-character scan to count semicolons that
	 * fall outside single-quoted, double-quoted, and backtick-quoted contexts.
	 * Escaped characters (preceded by a backslash) are skipped so that an
	 * escaped quote does not incorrectly toggle the context flag.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param  string $sql Raw SQL input.
	 * @return bool        True when multiple statements are detected.
	 */
	private static function has_multiple_statements( $sql ) {
		$in_single_quote = false;
		$in_double_quote = false;
		$in_backtick     = false;
		$statement_count = 0;
		$len             = strlen( $sql );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $sql[ $i ];
			$prev = $i > 0 ? $sql[ $i - 1 ] : '';

			// Toggle string context (skip escaped chars)
			if ( '\\' === $prev ) {
				continue;
			}
			if ( "'" === $char && ! $in_double_quote && ! $in_backtick ) {
				$in_single_quote = ! $in_single_quote;
				continue;
			}
			if ( '"' === $char && ! $in_single_quote && ! $in_backtick ) {
				$in_double_quote = ! $in_double_quote;
				continue;
			}
			if ( '`' === $char && ! $in_single_quote && ! $in_double_quote ) {
				$in_backtick = ! $in_backtick;
				continue;
			}

			// Semicolon outside any quote context = statement boundary
			if ( ';' === $char && ! $in_single_quote && ! $in_double_quote && ! $in_backtick ) {
				$statement_count++;
			}
		}

		// More than one statement boundary = multi-statement input
		return $statement_count > 1 || ( 1 === $statement_count && rtrim( $sql ) !== rtrim( substr( $sql, 0, strrpos( $sql, ';' ) ) ) );
	}

	/**
	 * Generate a SQL dump string for a single table.
	 *
	 * The dump includes a DROP TABLE IF EXISTS guard, the CREATE TABLE DDL
	 * retrieved from the server, and batched INSERT statements for all rows.
	 * Table name is already validated by the caller.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param  string $table Validated, unquoted table name.
	 * @return string|WP_Error SQL dump string, or WP_Error on connection failure.
	 */
	private static function export_table_sql( $table ) {
		$conn = self::get_connection();

		if ( is_wp_error( $conn ) ) {
			return $conn;
		}

		// Retrieve the CREATE TABLE statement.
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli -- direct connection is intentional.
		$ct_result = $conn->query( 'SHOW CREATE TABLE `' . $table . '`' );
		$ct_row    = $ct_result ? $ct_result->fetch_row() : null;
		$create    = $ct_row ? $ct_row[1] : '';

		if ( $ct_result ) {
			$ct_result->free();
		}

		// Fetch all rows for INSERT generation.
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli -- direct connection is intentional.
		$rows_result = $conn->query( 'SELECT * FROM `' . $table . '`' );

		$lines   = array();
		$lines[] = '-- WP Server Terminal SQL Export';
		$lines[] = '-- Table: ' . $table;
		$lines[] = '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$lines[] = '';
		$lines[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
		$lines[] = $create . ';';
		$lines[] = '';

		if ( $rows_result && $rows_result->num_rows > 0 ) {
			// Build INSERT statements in batches of 100 rows.
			$batch     = array();
			$col_names = null;

			while ( $row = $rows_result->fetch_assoc() ) {
				if ( null === $col_names ) {
					$col_names = '`' . implode( '`, `', array_keys( $row ) ) . '`';
				}

				$values = array_map(
					static function ( $v ) use ( $conn ) {
						if ( null === $v ) {
							return 'NULL';
						}
						return "'" . $conn->real_escape_string( $v ) . "'";
					},
					array_values( $row )
				);

				$batch[] = '(' . implode( ', ', $values ) . ')';

				if ( count( $batch ) >= 100 ) {
					$lines[] = 'INSERT INTO `' . $table . '` (' . $col_names . ') VALUES';
					$lines[] = implode( ",\n", $batch ) . ';';
					$lines[] = '';
					$batch   = array();
				}
			}

			// Flush any remaining rows.
			if ( ! empty( $batch ) ) {
				$lines[] = 'INSERT INTO `' . $table . '` (' . $col_names . ') VALUES';
				$lines[] = implode( ",\n", $batch ) . ';';
				$lines[] = '';
			}

			$rows_result->free();
		} elseif ( $rows_result ) {
			$rows_result->free();
		}

		return implode( "\n", $lines );
	}

	/**
	 * Generate a CSV string for a single table.
	 *
	 * The first row is a header row containing column names. Subsequent rows
	 * contain data values with proper CSV escaping (double-quoted fields,
	 * internal double-quotes doubled). Table name is already validated by the
	 * caller.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param  string $table Validated, unquoted table name.
	 * @return string|WP_Error CSV string, or WP_Error on failure.
	 */
	private static function export_table_csv( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $table ) . '`', ARRAY_A );

		if ( empty( $rows ) ) {
			return '';
		}

		// Write to a temporary in-memory buffer so we get proper fputcsv escaping.
		$handle = fopen( 'php://temp', 'r+' );

		// Header row.
		fputcsv( $handle, array_keys( $rows[0] ) );

		// Data rows.
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_values( $row ) );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}
}
