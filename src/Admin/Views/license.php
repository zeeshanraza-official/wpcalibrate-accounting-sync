<?php
/**
 * Admin License View.
 *
 * @package WPCalibrate\AccountingSync\Admin\Views
 */

declare(strict_types=1);

use WPCalibrate\AccountingSync\Licensing\LicenseManager;

$lic = LicenseManager::get_license_data();
?>

<div class="wpcalibrate-panel">
	<h2><?php esc_html_e( 'Plugin License & Subscription', 'wpcalibrate-accounting-sync' ); ?></h2>

	<div class="notice notice-info inline">
		<p>
			<strong><?php esc_html_e( 'Notice:', 'wpcalibrate-accounting-sync' ); ?></strong>
			<?php echo esc_html( $lic['message'] ); ?>
		</p>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcalibrate-form">
		<input type="hidden" name="action" value="wpcalibrate_sync_save_license" />
		<?php wp_nonce_field( 'wpcalibrate_sync_save_license_action' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="license_key"><?php esc_html_e( 'License Key', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<input type="text" name="license_key" id="license_key" value="<?php echo esc_attr( $lic['masked_key'] ); ?>" class="regular-text code" placeholder="XXXX-XXXX-XXXX-XXXX" />
					<p class="description"><?php esc_html_e( 'Enter your WPCalibrate marketplace license key when commercial provider credentials are configured.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<span class="wpcalibrate-status-pill wpcalibrate-pill-warning">
						<?php esc_html_e( 'Not Configured (Remote Provider Pending)', 'wpcalibrate-accounting-sync' ); ?>
					</span>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save License Key', 'wpcalibrate-accounting-sync' ); ?></button>
			<?php if ( ! empty( $lic['key'] ) ) : ?>
				<button type="submit" name="clear_license" value="1" class="button button-secondary" style="margin-left: 10px;">
					<?php esc_html_e( 'Clear Saved Key', 'wpcalibrate-accounting-sync' ); ?>
				</button>
			<?php endif; ?>
		</p>
	</form>
</div>
