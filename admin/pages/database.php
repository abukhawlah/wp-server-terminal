<?php
/**
 * Database manager admin page template.
 *
 * Rendered by WST_Admin_Menu after all capability, HTTPS, and re-authentication
 * checks have passed. Provides a sidebar table list, a CodeMirror SQL editor,
 * an execute toolbar with query history, and a results area. All interactive
 * behaviour is driven by admin/js/database.js.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wst-wrap">
	<h1><?php esc_html_e( 'Database Manager', 'wp-server-terminal' ); ?></h1>

	<div class="wst-db-layout">
		<div id="wst-table-list-sidebar">
			<h3><?php esc_html_e( 'Tables', 'wp-server-terminal' ); ?></h3>
			<ul id="wst-table-list"></ul>
		</div>

		<div class="wst-db-main">
			<div id="wst-sql-editor-container">
				<div id="wst-sql-editor"></div>

				<div class="wst-db-toolbar">
					<button id="wst-sql-execute" class="button button-primary">
						<?php esc_html_e( 'Execute (Ctrl+Enter)', 'wp-server-terminal' ); ?>
					</button>
					<button id="wst-sql-history-btn" class="button">
						<?php esc_html_e( 'History', 'wp-server-terminal' ); ?>
					</button>
					<select id="wst-sql-history" style="display:none;" aria-label="<?php esc_attr_e( 'Query history', 'wp-server-terminal' ); ?>"></select>
				</div>
			</div>

			<div id="wst-sql-results">
				<div id="wst-sql-meta"></div>
				<div id="wst-sql-results-table-wrap"></div>
				<div id="wst-sql-error" style="display:none;"></div>
			</div>
		</div>
	</div>
</div>
