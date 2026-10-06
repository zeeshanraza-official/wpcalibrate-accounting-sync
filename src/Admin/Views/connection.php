<?php
/**
 * Admin Connection View.
 *
 * @package WPCalibrate\AccountingSync\Admin\Views
 */

declare(strict_types=1);

use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Auth\OAuthClient;
use WPCalibrate\AccountingSync\Logging\Redactor;

$general      = get_option( 'wpcalibrate_accounting_sync_general', [] );
$active_env   = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

// Allow viewing Sandbox or Production config explicitly.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$view_env = isset( $_GET['env'] ) && in_array( $_GET['env'], [ 'sandbox', 'production' ], true )
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	? sanitize_key( wp_unslash( $_GET['env'] ) )
	: $active_env;

$conn_key  = 'wpcalibrate_accounting_sync_connection_' . $view_env;
$conn_opt  = get_option( $conn_key, [] );
$client_id = (string) ( $conn_opt['client_id'] ?? '' );
$secret    = (string) ( $conn_opt['client_secret'] ?? '' );
$verifier  = (string) ( $conn_opt['webhook_verifier_token'] ?? '' );

$is_connected = TokenManager::is_connected( $view_env );
$tokens       = TokenManager::get_tokens( $view_env );
$realm_id     = $tokens['realm_id'] ?? '';
$redirect_uri = OAuthClient::get_redirect_uri();
?>

<div class="wpcalibrate-panel">
	<div class="wpcalibrate-env-switch-bar">
		<span><?php esc_html_e( 'Viewing Credentials For:', 'wpcalibrate-accounting-sync' ); ?></span>
		<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'wpcalibrate-accounting-sync', 'tab' => 'connection', 'env' => 'sandbox' ], admin_url( 'admin.php' ) ) ); ?>"
		   class="button <?php echo 'sandbox' === $view_env ? 'button-primary' : 'button-secondary'; ?>">
			<?php esc_html_e( 'Sandbox', 'wpcalibrate-accounting-sync' ); ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'wpcalibrate-accounting-sync', 'tab' => 'connection', 'env' => 'production' ], admin_url( 'admin.php' ) ) ); ?>"
		   class="button <?php echo 'production' === $view_env ? 'button-primary' : 'button-secondary'; ?>">
			<?php esc_html_e( 'Production', 'wpcalibrate-accounting-sync' ); ?>
		</a>

		<?php if ( $view_env !== $active_env ) : ?>
			<span class="wpcalibrate-env-note">
				(<?php printf( esc_html__( 'Note: Active store synchronization is currently set to %s in Sync Settings.', 'wpcalibrate-accounting-sync' ), esc_html( ucfirst( $active_env ) ) ); ?>)
			</span>
		<?php endif; ?>
	</div>

	<!-- Instructions Callout -->
	<div class="wpcalibrate-info-box">
		<h3><?php esc_html_e( 'Intuit Developer Setup Instructions', 'wpcalibrate-accounting-sync' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Log in to the Intuit Developer Portal (developer.intuit.com) and navigate to your App keys.', 'wpcalibrate-accounting-sync' ); ?></li>
			<li>
				<?php esc_html_e( 'Add this exact Redirect URI to your Intuit App Redirect URIs list:', 'wpcalibrate-accounting-sync' ); ?>
				<div class="wpcalibrate-copy-field">
					<input type="text" readonly value="<?php echo esc_attr( $redirect_uri ); ?>" class="regular-text code" id="qbo_redirect_uri" />
					<button type="button" class="button wpcalibrate-copy-btn" data-target="#qbo_redirect_uri"><?php esc_html_e( 'Copy', 'wpcalibrate-accounting-sync' ); ?></button>
				</div>
			</li>
			<li><?php esc_html_e( 'Paste your Client ID and Client Secret below, click Save, then click Connect to authorize.', 'wpcalibrate-accounting-sync' ); ?></li>
		</ol>
	</div>

	<!-- Credentials Form -->
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcalibrate-form">
		<input type="hidden" name="action" value="wpcalibrate_sync_save_connection" />
		<input type="hidden" name="target_environment" value="<?php echo esc_attr( $view_env ); ?>" />
		<?php wp_nonce_field( 'wpcalibrate_sync_save_connection_action' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="client_id"><?php esc_html_e( 'Client ID', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<input type="text" name="client_id" id="client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" required autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Found in your Intuit Developer App Dashboard under Keys & credentials.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="client_secret"><?php esc_html_e( 'Client Secret', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<input type="password" name="client_secret" id="client_secret" value="<?php echo ! empty( $secret ) ? esc_attr( Redactor::mask_secret( $secret ) ) : ''; ?>" class="regular-text" autocomplete="new-password" />
					<p class="description"><?php esc_html_e( 'Masked for security. Leave unchanged to preserve existing secret.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="webhook_verifier_token"><?php esc_html_e( 'Webhook Verifier Token', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<input type="password" name="webhook_verifier_token" id="webhook_verifier_token" value="<?php echo ! empty( $verifier ) ? esc_attr( Redactor::mask_secret( $verifier ) ) : ''; ?>" class="regular-text" autocomplete="new-password" />
					<p class="description"><?php esc_html_e( 'Required for verifying QuickBooks Webhooks (from Intuit Webhooks configuration tab).', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Connection Credentials', 'wpcalibrate-accounting-sync' ); ?></button>
		</p>
	</form>

	<hr class="wpcalibrate-divider" />

	<!-- OAuth Authorization Action Buttons -->
	<div class="wpcalibrate-oauth-actions">
		<h3><?php printf( esc_html__( 'Authorization Status: %s', 'wpcalibrate-accounting-sync' ), esc_html( ucfirst( $view_env ) ) ); ?></h3>

		<?php if ( $is_connected ) : ?>
			<div class="wpcalibrate-connection-badge wpcalibrate-badge-success">
				<p>
					<strong><?php esc_html_e( 'Connected to QuickBooks Online Company:', 'wpcalibrate-accounting-sync' ); ?></strong>
					<code><?php echo esc_html( $realm_id ); ?></code>
				</p>
			</div>
			<div class="wpcalibrate-btn-group">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<input type="hidden" name="action" value="wpcalibrate_sync_connect_oauth" />
					<?php wp_nonce_field( 'wpcalibrate_sync_connect_oauth_action' ); ?>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Reauthorize Connection', 'wpcalibrate-accounting-sync' ); ?>
					</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to disconnect this QuickBooks company?', 'wpcalibrate-accounting-sync' ); ?>');">
					<input type="hidden" name="action" value="wpcalibrate_sync_disconnect_oauth" />
					<?php wp_nonce_field( 'wpcalibrate_sync_disconnect_oauth_action' ); ?>
					<button type="submit" class="button button-link-delete">
						<?php esc_html_e( 'Disconnect Company', 'wpcalibrate-accounting-sync' ); ?>
					</button>
				</form>
			</div>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Clicking Connect will redirect to Intuit to sign in and grant access to your QuickBooks Online company.', 'wpcalibrate-accounting-sync' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpcalibrate_sync_connect_oauth" />
				<?php wp_nonce_field( 'wpcalibrate_sync_connect_oauth_action' ); ?>
				<button type="submit" class="button button-primary button-hero <?php echo empty( $client_id ) ? 'disabled' : ''; ?>" <?php disabled( empty( $client_id ) ); ?>>
					<?php esc_html_e( 'Connect to QuickBooks', 'wpcalibrate-accounting-sync' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>
</div>
