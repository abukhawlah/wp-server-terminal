<?php
/**
 * First-run security acknowledgment notice.
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Only show if not yet acknowledged
if ( get_option( 'wst_security_acknowledged' ) ) {
    return;
}

// Handle acknowledgment
if ( isset( $_POST['wst_acknowledge_security'] ) && wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'wst_acknowledge_security' ) && WST_Capabilities::current_user_can() ) {
    update_option( 'wst_security_acknowledged', true );
    wp_safe_redirect( remove_query_arg( 'wst_acknowledged' ) );
    exit;
}
?>
<div class="notice notice-warning wst-first-run-notice" style="border-left-color:#d63638;padding:16px 16px 16px 12px;">
    <h3 style="margin-top:0;color:#d63638;"><?php esc_html_e( 'Security Warning — WP Server Terminal', 'wp-server-terminal' ); ?></h3>
    <p><?php esc_html_e( 'This plugin provides full server access equivalent to SSH. Any WordPress user with the manage_server_terminal capability can execute arbitrary commands on this server.', 'wp-server-terminal' ); ?></p>
    <ul style="list-style:disc;margin-left:20px;">
        <li><?php esc_html_e( 'Ensure only trusted administrators have this capability.', 'wp-server-terminal' ); ?></li>
        <li><?php esc_html_e( 'This plugin should ONLY be used on HTTPS sites.', 'wp-server-terminal' ); ?></li>
        <li><?php esc_html_e( 'Commands run as the PHP process user (typically www-data or apache), not root.', 'wp-server-terminal' ); ?></li>
        <li><?php esc_html_e( 'All actions are logged in the Audit Log.', 'wp-server-terminal' ); ?></li>
    </ul>
    <form method="post">
        <?php wp_nonce_field( 'wst_acknowledge_security' ); ?>
        <input type="hidden" name="wst_acknowledge_security" value="1">
        <button type="submit" class="button button-primary"><?php esc_html_e( 'I understand the risks — proceed', 'wp-server-terminal' ); ?></button>
    </form>
</div>
