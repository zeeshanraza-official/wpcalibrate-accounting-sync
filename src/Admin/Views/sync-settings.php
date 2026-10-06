<?php
/**
 * Admin Sync Settings View.
 *
 * @package WPCalibrate\AccountingSync\Admin\Views
 */

declare(strict_types=1);

$general = get_option( 'wpcalibrate_accounting_sync_general', [] );

$env              = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';
$sync_enabled     = ! empty( $general['sync_enabled'] );
$auto_sync        = ! empty( $general['auto_sync'] );
$mode             = $general['accounting_mode'] ?? 'sales_receipt';
$export_statuses  = (array) ( $general['export_order_statuses'] ?? [ 'wc-completed', 'wc-processing' ] );
$product_dir      = $general['product_sync_direction'] ?? 'wc_to_qbo';
$auto_create_prod = ! empty( $general['auto_create_products'] );
$inv_dir          = $general['inventory_sync_direction'] ?? 'disabled';
$recon_interval   = $general['reconciliation_interval'] ?? 'daily';
$retention_days   = (int) ( $general['log_retention_days'] ?? 30 );
$delete_data      = ! empty( $general['delete_data_on_uninstall'] );

$wc_order_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [
	'wc-pending'    => 'Pending payment',
	'wc-processing' => 'Processing',
	'wc-on-hold'    => 'On hold',
	'wc-completed'  => 'Completed',
	'wc-cancelled'  => 'Cancelled',
	'wc-refunded'   => 'Refunded',
	'wc-failed'     => 'Failed',
];
?>

<div class="wpcalibrate-panel">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcalibrate-form">
		<input type="hidden" name="action" value="wpcalibrate_sync_save_general" />
		<?php wp_nonce_field( 'wpcalibrate_sync_save_general_action' ); ?>

		<h2><?php esc_html_e( 'General & Automation Controls', 'wpcalibrate-accounting-sync' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Master Synchronization', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<label class="wpcalibrate-switch-label">
						<input type="checkbox" name="sync_enabled" value="1" <?php checked( $sync_enabled ); ?> />
						<strong><?php esc_html_e( 'Enable Accounting Synchronization', 'wpcalibrate-accounting-sync' ); ?></strong>
					</label>
					<p class="description"><?php esc_html_e( 'When paused, no automatic background exports or webhook changes will occur.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="environment"><?php esc_html_e( 'Active QuickBooks Environment', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<select name="environment" id="environment">
						<option value="sandbox" <?php selected( $env, 'sandbox' ); ?>><?php esc_html_e( 'Sandbox (Test Store)', 'wpcalibrate-accounting-sync' ); ?></option>
						<option value="production" <?php selected( $env, 'production' ); ?>><?php esc_html_e( 'Production (Live Company)', 'wpcalibrate-accounting-sync' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Choose which QuickBooks environment drives active transactions. Credentials and mappings are isolated per environment.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Automatic Export', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="auto_sync" value="1" <?php checked( $auto_sync ); ?> />
						<?php esc_html_e( 'Automatically queue synchronization when WooCommerce order status changes', 'wpcalibrate-accounting-sync' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Exports run asynchronously via Action Scheduler so store checkouts are never slowed down by external API requests.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Export Order Statuses', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Export Order Statuses', 'wpcalibrate-accounting-sync' ); ?></legend>
						<?php foreach ( $wc_order_statuses as $st_key => $st_name ) : ?>
							<label style="display:block; margin-bottom: 4px;">
								<input type="checkbox" name="export_order_statuses[]" value="<?php echo esc_attr( $st_key ); ?>" <?php checked( in_array( $st_key, $export_statuses, true ) ); ?> />
								<?php echo esc_html( $st_name ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Orders transitioning into any selected status will be queued for QuickBooks Online export.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="accounting_mode"><?php esc_html_e( 'Order Accounting Mode', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<select name="accounting_mode" id="accounting_mode">
						<option value="sales_receipt" <?php selected( $mode, 'sales_receipt' ); ?>><?php esc_html_e( 'Sales Receipt (Paid Orders)', 'wpcalibrate-accounting-sync' ); ?></option>
						<option value="invoice_payment" <?php selected( $mode, 'invoice_payment' ); ?>><?php esc_html_e( 'Invoice + Payment (Creates Invoice, Links Payment when paid)', 'wpcalibrate-accounting-sync' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Sales Receipt mode creates a single combined receipt for immediate sales. Invoice + Payment creates an accounts receivable invoice and posts linked payment.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="inventory_sync_direction"><?php esc_html_e( 'Inventory Synchronization', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<select name="inventory_sync_direction" id="inventory_sync_direction">
						<option value="disabled" <?php selected( $inv_dir, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'wpcalibrate-accounting-sync' ); ?></option>
						<option value="wc_to_qbo" <?php selected( $inv_dir, 'wc_to_qbo' ); ?>><?php esc_html_e( 'WooCommerce to QuickBooks Online', 'wpcalibrate-accounting-sync' ); ?></option>
						<option value="qbo_to_wc" <?php selected( $inv_dir, 'qbo_to_wc' ); ?>><?php esc_html_e( 'QuickBooks Online to WooCommerce (via Webhook)', 'wpcalibrate-accounting-sync' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Strict echo-loop prevention ensures that stock updates never bounce back and forth between platforms.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-Create Missing Products', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="auto_create_products" value="1" <?php checked( $auto_create_prod ); ?> />
						<?php esc_html_e( 'Automatically create new items in QuickBooks when an unmapped product is sold', 'wpcalibrate-accounting-sync' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Requires Default Income Account to be configured in Mapping tab.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="reconciliation_interval"><?php esc_html_e( 'Reconciliation Interval', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<select name="reconciliation_interval" id="reconciliation_interval">
						<option value="daily" <?php selected( $recon_interval, 'daily' ); ?>><?php esc_html_e( 'Daily (Recommended)', 'wpcalibrate-accounting-sync' ); ?></option>
						<option value="hourly" <?php selected( $recon_interval, 'hourly' ); ?>><?php esc_html_e( 'Hourly (High volume)', 'wpcalibrate-accounting-sync' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="log_retention_days"><?php esc_html_e( 'Log Retention (Days)', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<input type="number" name="log_retention_days" id="log_retention_days" value="<?php echo esc_attr( (string) $retention_days ); ?>" min="1" max="365" class="small-text" />
					<p class="description"><?php esc_html_e( 'Audit logs older than this duration are automatically pruned.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall Data Retention', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $delete_data ); ?> />
						<span class="wpcalibrate-danger-text"><?php esc_html_e( 'Delete all plugin settings, mappings, and logs upon plugin deletion', 'wpcalibrate-accounting-sync' ); ?></span>
					</label>
					<p class="description"><?php esc_html_e( 'By default, plugin data is preserved so reinstalling will restore your mappings. Checking this deletes all local plugin tables upon WordPress uninstall. Remote QuickBooks data is never deleted.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Sync Settings', 'wpcalibrate-accounting-sync' ); ?></button>
		</p>
	</form>
</div>
