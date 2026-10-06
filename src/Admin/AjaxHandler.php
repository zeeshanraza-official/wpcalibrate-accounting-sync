<?php
/**
 * Admin AJAX Controller.
 *
 * @package WPCalibrate\AccountingSync\Admin
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Admin;

use WPCalibrate\AccountingSync\Client\QuickBooksClient;
use WPCalibrate\AccountingSync\Queue\ActionSchedulerQueue;
use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Logging\Logger;
use Exception;

/**
 * Handles AJAX requests for dry-run estimation, historical batching, and manual sync triggers.
 */
final class AjaxHandler {

	/**
	 * Hook registrations.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_ajax_wpcalibrate_sync_dry_run_historical', [ $this, 'dry_run_historical' ] );
		add_action( 'wp_ajax_wpcalibrate_sync_process_historical_batch', [ $this, 'process_historical_batch' ] );
		add_action( 'wp_ajax_wpcalibrate_sync_manual_order', [ $this, 'manual_order_sync' ] );
		add_action( 'wp_ajax_wpcalibrate_sync_test_connection', [ $this, 'test_connection' ] );
	}

	/**
	 * Verifies AJAX permissions and nonce.
	 *
	 * @return void
	 */
	private function verify_ajax(): void {
		check_ajax_referer( 'wpcalibrate_sync_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'trigger_accounting_sync' ) && ! current_user_can( 'manage_accounting_sync' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wpcalibrate-accounting-sync' ) ], 403 );
		}
	}

	/**
	 * AJAX endpoint: Estimates historical order count (Dry Run).
	 */
	public function dry_run_historical(): void {
		$this->verify_ajax();

		$date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
		$statuses  = array_map( 'sanitize_text_field', (array) ( $_POST['statuses'] ?? [ 'wc-completed', 'wc-processing' ] ) );

		$args = [
			'limit'  => -1,
			'status' => $statuses,
			'return' => 'ids',
		];

		if ( ! empty( $date_from ) ) {
			$args['date_created'] = '>=' . $date_from;
		}

		if ( ! empty( $date_to ) ) {
			$args['date_created'] = ( ! empty( $args['date_created'] ) ? $args['date_created'] . '...' : '<=' ) . $date_to . ' 23:59:59';
		}

		$order_ids = wc_get_orders( $args );
		$count     = count( $order_ids );

		wp_send_json_success(
			[
				'count'     => $count,
				'order_ids' => $order_ids,
				'message'   => sprintf(
					/* translators: %d: Estimated count */
					__( 'Found %d matching orders ready for historical synchronization.', 'wpcalibrate-accounting-sync' ),
					$count
				),
			]
		);
	}

	/**
	 * AJAX endpoint: Processes a chunk of historical orders into background queue.
	 */
	public function process_historical_batch(): void {
		$this->verify_ajax();

		$order_ids = array_map( 'intval', (array) ( $_POST['order_ids'] ?? [] ) );
		$offset    = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
		$batch_size = max( 1, min( 50, (int) ( $_POST['batch_size'] ?? 20 ) ) );

		$chunk = array_slice( $order_ids, $offset, $batch_size );

		foreach ( $chunk as $order_id ) {
			ActionSchedulerQueue::enqueue_order_sync( $order_id );
		}

		$next_offset = $offset + count( $chunk );
		$completed   = $next_offset >= count( $order_ids );

		wp_send_json_success(
			[
				'processed' => count( $chunk ),
				'offset'    => $next_offset,
				'completed' => $completed,
			]
		);
	}

	/**
	 * AJAX endpoint: Manually queues a single order.
	 */
	public function manual_order_sync(): void {
		$this->verify_ajax();

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'wpcalibrate-accounting-sync' ) ] );
		}

		ActionSchedulerQueue::enqueue_order_sync( $order_id );

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: Order ID */
					__( 'Order #%d has been queued for background synchronization.', 'wpcalibrate-accounting-sync' ),
					$order_id
				),
			]
		);
	}

	/**
	 * AJAX endpoint: Tests live connection.
	 */
	public function test_connection(): void {
		$this->verify_ajax();

		$general     = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$environment = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

		if ( ! TokenManager::is_connected( $environment ) ) {
			wp_send_json_error( [ 'message' => __( 'QuickBooks is not connected.', 'wpcalibrate-accounting-sync' ) ] );
		}

		try {
			$client = new QuickBooksClient( $environment );
			$info   = $client->test_connection();
			$name   = (string) ( $info['CompanyName'] ?? 'QuickBooks Company' );

			wp_send_json_success(
				[
					'message'      => sprintf(
						/* translators: 1: Company name, 2: Realm ID */
						__( 'Connection active! Connected to "%1$s" (Company ID: %2$s).', 'wpcalibrate-accounting-sync' ),
						$name,
						$client->get_realm_id()
					),
					'company_name' => $name,
				]
			);
		} catch ( Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}
}
