<?php
/**
 * Admin Support View.
 *
 * @package WPCalibrate\AccountingSync\Admin\Views
 */

declare(strict_types=1);

use WPCalibrate\AccountingSync\Support\SupportRegistry;

$support = SupportRegistry::get_details();
?>

<div class="wpcalibrate-panel">
	<div class="wpcalibrate-support-card">
		<img src="<?php echo esc_url( WPCALIBRATE_ACCOUNTING_SYNC_URL . 'branding/icon-dark.png' ); ?>" alt="WPCalibrate" class="wpcalibrate-support-logo" />
		<h2><?php esc_html_e( 'Official Product Support & Documentation', 'wpcalibrate-accounting-sync' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Need help setting up your QuickBooks Online integration or have questions regarding accounting workflows? Get in touch with the WPCalibrate engineering team.', 'wpcalibrate-accounting-sync' ); ?>
		</p>

		<table class="form-table" role="presentation" style="margin-top: 20px;">
			<tr>
				<th scope="row"><?php esc_html_e( 'Support Email', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<a href="mailto:<?php echo esc_attr( $support['email'] ); ?>">
						<?php echo esc_html( $support['email'] ); ?>
					</a>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Website', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<a href="<?php echo esc_url( $support['website'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $support['website'] ); ?>
					</a>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Marketplace & Extensions', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<a href="<?php echo esc_url( $support['marketplace'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $support['marketplace'] ); ?>
					</a>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Phone / WhatsApp Support', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<a href="https://wa.me/<?php echo esc_attr( ltrim( $support['phone_whatsapp'], '+' ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $support['phone_whatsapp'] ); ?>
					</a>
				</td>
			</tr>
		</table>
	</div>
</div>
