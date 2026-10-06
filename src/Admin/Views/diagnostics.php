<?php
/**
 * Admin Diagnostics View.
 *
 * @package WPCalibrate\AccountingSync\Admin\Views
 */

declare(strict_types=1);

use WPCalibrate\AccountingSync\Diagnostics\DiagnosticsReporter;

$report         = DiagnosticsReporter::get_report();
$formatted_text = DiagnosticsReporter::get_formatted_text();
?>

<div class="wpcalibrate-panel">
	<div class="wpcalibrate-panel-header-actions">
		<h2><?php esc_html_e( 'System Health & Diagnostics', 'wpcalibrate-accounting-sync' ); ?></h2>
		<button type="button" class="button button-primary wpcalibrate-copy-btn" data-target="#wpcalibrate_diag_text">
			<?php esc_html_e( 'Copy System Status', 'wpcalibrate-accounting-sync' ); ?>
		</button>
	</div>

	<table class="widefat striped" style="margin-top: 15px;">
		<thead>
			<tr>
				<th colspan="2"><?php esc_html_e( 'WordPress & Server Environment', 'wpcalibrate-accounting-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><strong><?php esc_html_e( 'Plugin Version', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td><?php echo esc_html( $report['system']['plugin_version'] ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Database Schema Version', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td><?php echo esc_html( $report['system']['db_schema_version'] ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'WordPress Version', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td><?php echo esc_html( $report['system']['wordpress_version'] ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'WooCommerce Version', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td><?php echo esc_html( $report['system']['woocommerce_version'] ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'HPOS (Custom Order Tables)', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td>
					<span class="wpcalibrate-status-pill <?php echo $report['system']['hpos_enabled'] ? 'wpcalibrate-pill-success' : 'wpcalibrate-pill-info'; ?>">
						<?php echo $report['system']['hpos_enabled'] ? esc_html__( 'Enabled', 'wpcalibrate-accounting-sync' ) : esc_html__( 'Disabled / Legacy Posts', 'wpcalibrate-accounting-sync' ); ?>
					</span>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'WooCommerce Action Scheduler', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td>
					<span class="wpcalibrate-status-pill <?php echo $report['system']['action_scheduler'] ? 'wpcalibrate-pill-success' : 'wpcalibrate-pill-danger'; ?>">
						<?php echo $report['system']['action_scheduler'] ? esc_html__( 'Active', 'wpcalibrate-accounting-sync' ) : esc_html__( 'Missing', 'wpcalibrate-accounting-sync' ); ?>
					</span>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'PHP Version', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td><?php echo esc_html( $report['system']['php_version'] ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'PHP Extensions', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td>
					<?php foreach ( $report['system']['extensions'] as $ext => $loaded ) : ?>
						<span class="wpcalibrate-status-pill <?php echo $loaded ? 'wpcalibrate-pill-success' : 'wpcalibrate-pill-danger'; ?>" style="margin-right: 5px;">
							<?php echo esc_html( $ext . ': ' . ( $loaded ? 'OK' : 'Missing' ) ); ?>
						</span>
					<?php endforeach; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<table class="widefat striped" style="margin-top: 20px;">
		<thead>
			<tr>
				<th colspan="2"><?php esc_html_e( 'QuickBooks Online Connection', 'wpcalibrate-accounting-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><strong><?php esc_html_e( 'Active Environment', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td><?php echo esc_html( ucfirst( (string) $report['connection']['environment'] ) ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Connection Status', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td>
					<span class="wpcalibrate-status-pill <?php echo $report['connection']['is_connected'] ? 'wpcalibrate-pill-success' : 'wpcalibrate-pill-danger'; ?>">
						<?php echo $report['connection']['is_connected'] ? esc_html__( 'Connected', 'wpcalibrate-accounting-sync' ) : esc_html__( 'Disconnected', 'wpcalibrate-accounting-sync' ); ?>
					</span>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Connected Company / Realm ID', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td><code><?php echo esc_html( (string) $report['connection']['realm_id'] ); ?></code></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Webhook Inbound Endpoint', 'wpcalibrate-accounting-sync' ); ?></strong></td>
				<td><code><?php echo esc_html( (string) $report['webhooks']['endpoint_url'] ); ?></code></td>
			</tr>
		</tbody>
	</table>

	<div style="margin-top: 25px;">
		<h3><?php esc_html_e( 'Sanitized Technical Report', 'wpcalibrate-accounting-sync' ); ?></h3>
		<textarea id="wpcalibrate_diag_text" readonly rows="12" class="large-text code" style="background:#f6f7f7;"><?php echo esc_textarea( $formatted_text ); ?></textarea>
	</div>
</div>
