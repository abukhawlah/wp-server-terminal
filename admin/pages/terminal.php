<?php
/**
 * Terminal admin page template.
 *
 * Rendered by WST_Admin_Menu after all capability, HTTPS, and re-authentication
 * checks have passed. Outputs the xterm.js container and supporting toolbar UI;
 * all interactive behaviour is driven by admin/js/terminal.js.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wst-wrap">
	<h1><?php esc_html_e( 'Server Terminal', 'wp-server-terminal' ); ?></h1>

	<div class="wst-toolbar">
		<span class="wst-cwd-label"><?php esc_html_e( 'Directory:', 'wp-server-terminal' ); ?> </span>
		<span id="wst-cwd"><?php esc_html_e( 'Loading...', 'wp-server-terminal' ); ?></span>
		<button id="wst-wpcli-toggle" class="button">
			<?php esc_html_e( 'WP-CLI Mode: OFF', 'wp-server-terminal' ); ?>
		</button>
		<button id="wst-clear" class="button">
			<?php esc_html_e( 'Clear', 'wp-server-terminal' ); ?>
		</button>
	</div>

	<div id="wst-terminal-container"></div>

	<div id="wst-status-bar">
		<span id="wst-session-status" class="wst-status-authed">
			<?php esc_html_e( 'Session Active', 'wp-server-terminal' ); ?>
		</span>
	</div>
</div>
