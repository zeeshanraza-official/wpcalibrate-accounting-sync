<?php
/**
 * Scheduled Reconciliation Engine.
 *
 * @package WPCalibrate\AccountingSync\Reconciliation
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Reconciliation;

use WPCalibrate\AccountingSync\Client\QuickBooksClient;
use WPCalibrate\AccountingSync\Database\MappingRepository;
use WPCalibrate\AccountingSync\Queue\ActionSchedulerQueue;
use WPCalibrate\AccountingSync\Queue\LockManager;
use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Logging\Logger;
use Exception;

/**
 * Periodically reconciles WooCommerce orders against QuickBooks to catch missed events without downloading entire datasets.
 */
final class ReconciliationEngine {

	public const HOOK_RECONCILE = 'wpcalibrate_accounting_sync_reconciliation';

	/**
	 * Hook registrations.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::HOOK_RECONCILE, [ $this, 'run_reconciliation' ] );

		// Schedule recurring job if not yet scheduled and Action Scheduler is available.
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK_RECONCILE ) ) {
			$general  = get_option( 'wpcalibrate_accounting_sync_general', [] );
			$interval = (string) ( $general['reconciliation_interval'] ?? 'daily' );
			$seconds  = 'hourly' === $interval ? HOUR_IN_SECONDS : DAY_IN_SECONDS;

			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, $seconds, self::HOOK_RECONCILE );
		}
	}

	/**
	 * Executes reconciliation run with mutex lock.
	 *
	 * @return array{checked: int, recovered: int, status: string}
	 */
	public function run_reconciliation(): array {
		if ( ! LockManager::acquire( 'reconciliation', 'global', 600 ) ) {
			Logger::warning( 'Reconciliation', '0', 'run', 'Skipping reconciliation: Previous run is still in progress.' );
			return [ 'checked' => 0, 'recovered' => 0, 'status' => 'locked' ];
		}

		$general     = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$environment = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

		if ( empty( $general['sync_enabled'] ) || ! TokenManager::is_connected( $environment ) ) {
			LockManager::release( 'reconciliation', 'global' );
			return [ 'checked' => 0, 'recovered' => 0, 'status' => 'disabled_or_disconnected' ];
		}

		$tokens   = TokenManager::get_tokens( $environment );
		$realm_id = $tokens['realm_id'] ?? '';

		$checked_count   = 0;
		$recovered_count = 0;

		try {
			// Query WooCommerce orders from the last 48 hours that match export statuses.
			$statuses = (array) ( $general['export_order_statuses'] ?? [ 'wc-completed', 'wc-processing' ] );

			$orders = wc_get_orders(
				[
					'limit'        => 50,
					'status'       => $statuses,
					'date_created' => '>' . gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ),
					'return'       => 'ids',
				]
			);

			foreach ( $orders as $order_id ) {
				$checked_count++;

				$mapped_id = MappingRepository::get_qbo_id(
					$environment,
					$realm_id,
					'order',
					(int) $order_id
				);

				if ( empty( $mapped_id ) ) {
					// Missed synchronization detected: safely enqueue background export.
					ActionSchedulerQueue::enqueue_order_sync( (int) $order_id );
					$recovered_count++;
				}
			}

			// Store last reconciliation state.
			update_option(
				'wpcalibrate_accounting_sync_last_reconciliation',
				[
					'timestamp' => gmdate( 'Y-m-d H:i:s' ),
					'checked'   => $checked_count,
					'recovered' => $recovered_count,
					'status'    => 'success',
				]
			);

			Logger::info( 'Reconciliation', '0', 'reconciliation_complete', "Reconciliation finished: checked {$checked_count} orders, recovered {$recovered_count} missed exports." );

			return [
				'checked'   => $checked_count,
				'recovered' => $recovered_count,
				'status'    => 'success',
			];
		} catch ( Exception $e ) {
			Logger::error( 'Reconciliation', '0', 'reconciliation_error', 'Reconciliation failed: ' . $e->getMessage() );
			return [
				'checked'   => $checked_count,
				'recovered' => $recovered_count,
				'status'    => 'error',
			];
		} finally {
			LockManager::release( 'reconciliation', 'global' );
		}
	}
}
