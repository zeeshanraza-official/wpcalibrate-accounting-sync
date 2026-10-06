<?php
/**
 * Admin Historical Sync View.
 *
 * @package WPCalibrate\AccountingSync\Admin\Views
 */

declare(strict_types=1);

$wc_order_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [
	'wc-completed'  => 'Completed',
	'wc-processing' => 'Processing',
];
?>

<div class="wpcalibrate-panel">
	<h2><?php esc_html_e( 'Historical Synchronization', 'wpcalibrate-accounting-sync' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Export past WooCommerce orders to QuickBooks Online. Orders are dispatched in non-blocking asynchronous batches via Action Scheduler so your server remains responsive.', 'wpcalibrate-accounting-sync' ); ?>
	</p>

	<div class="wpcalibrate-historical-form">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="hist_date_from"><?php esc_html_e( 'Date From', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<input type="date" id="hist_date_from" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Optional start date filter.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hist_date_to"><?php esc_html_e( 'Date To', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<input type="date" id="hist_date_to" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Optional end date filter.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Order Statuses', 'wpcalibrate-accounting-sync' ); ?></th>
				<td>
					<fieldset>
						<?php foreach ( $wc_order_statuses as $st_key => $st_name ) : ?>
							<label style="display:inline-block; margin-right: 15px;">
								<input type="checkbox" name="hist_statuses[]" value="<?php echo esc_attr( $st_key ); ?>" <?php checked( in_array( $st_key, [ 'wc-completed', 'wc-processing' ], true ) ); ?> />
								<?php echo esc_html( $st_name ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
		</table>

		<div class="wpcalibrate-btn-group" style="margin-top: 15px;">
			<button type="button" class="button button-secondary" id="wpcalibrate_dry_run_btn">
				<?php esc_html_e( 'Estimate Count (Dry Run)', 'wpcalibrate-accounting-sync' ); ?>
			</button>
			<button type="button" class="button button-primary" id="wpcalibrate_start_hist_btn" disabled>
				<?php esc_html_e( 'Start Historical Sync', 'wpcalibrate-accounting-sync' ); ?>
			</button>
			<button type="button" class="button button-link-delete" id="wpcalibrate_cancel_hist_btn" style="display:none;">
				<?php esc_html_e( 'Cancel Sync', 'wpcalibrate-accounting-sync' ); ?>
			</button>
		</div>

		<!-- Dry run result message -->
		<div id="wpcalibrate_dry_run_notice" class="notice notice-info inline" style="display:none; margin-top: 15px;">
			<p id="wpcalibrate_dry_run_text"></p>
		</div>

		<!-- Progress Bar Container -->
		<div id="wpcalibrate_progress_container" style="display:none; margin-top: 25px;">
			<h3><?php esc_html_e( 'Queueing Orders into Background Processor...', 'wpcalibrate-accounting-sync' ); ?></h3>
			<div class="wpcalibrate-progress-bar-wrap">
				<div id="wpcalibrate_progress_bar" class="wpcalibrate-progress-bar" style="width: 0%;"></div>
			</div>
			<p id="wpcalibrate_progress_status" style="margin-top: 8px; font-weight: 600;">0 / 0</p>
		</div>
	</div>
</div>
