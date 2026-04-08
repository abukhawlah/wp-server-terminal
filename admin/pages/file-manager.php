<?php
/**
 * File manager admin page template.
 *
 * Rendered by WST_Admin_Menu after all capability, HTTPS, and re-authentication
 * checks have passed. Provides the file list table, an inline CodeMirror editor
 * panel, drag-and-drop upload zone, and a breadcrumb navigation bar. All
 * interactive behaviour is driven by admin/js/file-manager.js.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wst-wrap">
	<h1><?php esc_html_e( 'File Manager', 'wp-server-terminal' ); ?></h1>

	<div class="wst-fm-toolbar">
		<nav id="wst-breadcrumb" aria-label="<?php esc_attr_e( 'File path', 'wp-server-terminal' ); ?>"></nav>

		<div class="wst-fm-actions">
			<button id="wst-upload-btn" class="button">
				<?php esc_html_e( 'Upload', 'wp-server-terminal' ); ?>
			</button>
			<input
				type="file"
				id="wst-upload-input"
				multiple
				style="display:none;"
				aria-hidden="true"
			>
			<button id="wst-new-folder-btn" class="button">
				<?php esc_html_e( 'New Folder', 'wp-server-terminal' ); ?>
			</button>
			<button id="wst-new-file-btn" class="button">
				<?php esc_html_e( 'New File', 'wp-server-terminal' ); ?>
			</button>
		</div>
	</div>

	<div class="wst-fm-layout">
		<div id="wst-file-list-container">
			<table id="wst-file-list" class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'wp-server-terminal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'wp-server-terminal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Modified', 'wp-server-terminal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Permissions', 'wp-server-terminal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'wp-server-terminal' ); ?></th>
					</tr>
				</thead>
				<tbody id="wst-file-list-body"></tbody>
			</table>
		</div>

		<div id="wst-editor-panel" style="display:none;">
			<div class="wst-editor-toolbar">
				<span id="wst-editor-filename"></span>
				<button id="wst-editor-save" class="button button-primary">
					<?php esc_html_e( 'Save (Ctrl+S)', 'wp-server-terminal' ); ?>
				</button>
				<button id="wst-editor-close" class="button">
					<?php esc_html_e( 'Close', 'wp-server-terminal' ); ?>
				</button>
			</div>
			<div id="wst-codemirror-container"></div>
		</div>
	</div>

	<div id="wst-drop-zone" class="wst-drop-zone">
		<?php esc_html_e( 'Drop files here to upload', 'wp-server-terminal' ); ?>
	</div>
</div>
