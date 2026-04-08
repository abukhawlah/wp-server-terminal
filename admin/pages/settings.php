<?php
/**
 * Admin settings page template.
 *
 * Included by WST_Admin_Menu when rendering the plugin settings page.
 * Renders the full settings form using the WordPress Settings API and displays
 * an environment information panel so administrators can verify their server
 * meets the plugin's requirements.
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

// ---------------------------------------------------------------------------
// Gather environment information for the info panel.
// ---------------------------------------------------------------------------

$wst_php_version    = PHP_VERSION;
$wst_server_os      = php_uname( 's' ) . ' ' . php_uname( 'r' );
$wst_proc_open      = function_exists( 'proc_open' );
$wst_wpcli_path     = function_exists( 'shell_exec' ) ? trim( (string) shell_exec( 'which wp' ) ) : '';
$wst_wpcli_detected = ! empty( $wst_wpcli_path );
$wst_is_https       = WST_Security::is_https();

?>
<div class="wrap">
	<h1><?php esc_html_e( 'Server Terminal — Settings', 'wp-server-terminal' ); ?></h1>

	<?php if ( ! $wst_is_https ) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Warning:', 'wp-server-terminal' ); ?></strong>
				<?php esc_html_e( 'This site is not served over HTTPS. All data transmitted through this plugin is unencrypted.', 'wp-server-terminal' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="card" style="max-width:800px;margin-bottom:20px;">
		<h2><?php esc_html_e( 'Server Environment', 'wp-server-terminal' ); ?></h2>
		<table class="widefat striped" style="border:none;">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'PHP Version', 'wp-server-terminal' ); ?></strong></td>
					<td><?php echo esc_html( $wst_php_version ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Server OS', 'wp-server-terminal' ); ?></strong></td>
					<td><?php echo esc_html( $wst_server_os ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'proc_open Available', 'wp-server-terminal' ); ?></strong></td>
					<td>
						<?php if ( $wst_proc_open ) : ?>
							<span style="color:#46b450;">&#10003; <?php esc_html_e( 'Yes', 'wp-server-terminal' ); ?></span>
						<?php else : ?>
							<span style="color:#dc3232;">&#10007; <?php esc_html_e( 'No', 'wp-server-terminal' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'WP-CLI Detected', 'wp-server-terminal' ); ?></strong></td>
					<td>
						<?php if ( $wst_wpcli_detected ) : ?>
							<span style="color:#46b450;">&#10003; <?php esc_html_e( 'Yes', 'wp-server-terminal' ); ?>
								<code style="margin-left:6px;"><?php echo esc_html( $wst_wpcli_path ); ?></code>
							</span>
						<?php else : ?>
							<span style="color:#dc3232;">&#10007; <?php esc_html_e( 'No', 'wp-server-terminal' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'HTTPS', 'wp-server-terminal' ); ?></strong></td>
					<td>
						<?php if ( $wst_is_https ) : ?>
							<span style="color:#46b450;">&#10003; <?php esc_html_e( 'Yes', 'wp-server-terminal' ); ?></span>
						<?php else : ?>
							<span style="color:#dc3232;">&#10007; <?php esc_html_e( 'No', 'wp-server-terminal' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'wst_settings_group' );
		do_settings_sections( 'wst-settings' );
		submit_button();
		?>
	</form>
</div>
