<?php
/**
 * Admin Logs View.
 *
 * @package WPCalibrate\AccountingSync\Admin\Views
 */

declare(strict_types=1);

use WPCalibrate\AccountingSync\Database\LogRepository;

$general     = get_option( 'wpcalibrate_accounting_sync_general', [] );
$environment = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$limit        = 25;
$offset       = ( $current_page - 1 ) * $limit;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$severity     = sanitize_key( wp_unslash( $_GET['severity'] ?? '' ) );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$search       = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

$logs_data   = LogRepository::get_paginated( $environment, $severity, $search, $limit, $offset );
$total_pages = ceil( $logs_data['total'] / $limit );
?>

<div class="wpcalibrate-panel">
	<div class="wpcalibrate-panel-header-actions">
		<h2><?php esc_html_e( 'Audit & Synchronization Logs', 'wpcalibrate-accounting-sync' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to clear all logs for this environment?', 'wpcalibrate-accounting-sync' ); ?>');">
			<input type="hidden" name="action" value="wpcalibrate_sync_clear_logs" />
			<?php wp_nonce_field( 'wpcalibrate_sync_clear_logs_action' ); ?>
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Clear All Logs', 'wpcalibrate-accounting-sync' ); ?></button>
		</form>
	</div>

	<!-- Filter Bar -->
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wpcalibrate-filter-bar">
		<input type="hidden" name="page" value="wpcalibrate-accounting-sync" />
		<input type="hidden" name="tab" value="logs" />

		<select name="severity">
			<option value=""><?php esc_html_e( 'All Severities', 'wpcalibrate-accounting-sync' ); ?></option>
			<option value="info" <?php selected( $severity, 'info' ); ?>><?php esc_html_e( 'Info', 'wpcalibrate-accounting-sync' ); ?></option>
			<option value="warning" <?php selected( $severity, 'warning' ); ?>><?php esc_html_e( 'Warning', 'wpcalibrate-accounting-sync' ); ?></option>
			<option value="error" <?php selected( $severity, 'error' ); ?>><?php esc_html_e( 'Error', 'wpcalibrate-accounting-sync' ); ?></option>
			<option value="debug" <?php selected( $severity, 'debug' ); ?>><?php esc_html_e( 'Debug', 'wpcalibrate-accounting-sync' ); ?></option>
		</select>

		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search message or correlation ID...', 'wpcalibrate-accounting-sync' ); ?>" />
		<button type="submit" class="button button-secondary"><?php esc_html_e( 'Filter', 'wpcalibrate-accounting-sync' ); ?></button>
	</form>

	<table class="widefat striped" style="margin-top: 15px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Timestamp (UTC)', 'wpcalibrate-accounting-sync' ); ?></th>
				<th><?php esc_html_e( 'Severity', 'wpcalibrate-accounting-sync' ); ?></th>
				<th><?php esc_html_e( 'Entity', 'wpcalibrate-accounting-sync' ); ?></th>
				<th><?php esc_html_e( 'Operation', 'wpcalibrate-accounting-sync' ); ?></th>
				<th><?php esc_html_e( 'Message', 'wpcalibrate-accounting-sync' ); ?></th>
				<th><?php esc_html_e( 'Correlation ID', 'wpcalibrate-accounting-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $logs_data['items'] ) ) : ?>
				<?php foreach ( $logs_data['items'] as $log_row ) : ?>
					<tr>
						<td><?php echo esc_html( $log_row->created_at ); ?></td>
						<td>
							<span class="wpcalibrate-status-pill wpcalibrate-pill-<?php echo esc_attr( $log_row->severity ); ?>">
								<?php echo esc_html( strtoupper( $log_row->severity ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $log_row->entity_type . ( ! empty( $log_row->entity_id ) ? ' #' . $log_row->entity_id : '' ) ); ?></td>
						<td><code><?php echo esc_html( $log_row->operation ); ?></code></td>
						<td><?php echo esc_html( $log_row->message ); ?></td>
						<td><small class="code"><?php echo esc_html( substr( $log_row->correlation_id, 0, 8 ) ); ?></small></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="6"><?php esc_html_e( 'No log entries found for the selected filter.', 'wpcalibrate-accounting-sync' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<!-- Pagination -->
	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php printf( esc_html__( '%d items', 'wpcalibrate-accounting-sync' ), (int) $logs_data['total'] ); ?></span>
				<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
					<a class="button <?php echo $i === $current_page ? 'button-primary disabled' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => 'wpcalibrate-accounting-sync', 'tab' => 'logs', 'paged' => $i, 'severity' => $severity, 's' => $search ], admin_url( 'admin.php' ) ) ); ?>">
						<?php echo esc_html( (string) $i ); ?>
					</a>
				<?php endfor; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
