<?php
/**
 * Action Scheduler Queue & Background Processor.
 *
 * @package WPCalibrate\AccountingSync\Queue
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Queue;

use WPCalibrate\AccountingSync\Client\QuickBooksClient;
use WPCalibrate\AccountingSync\Sync\SalesReceiptSync;
use WPCalibrate\AccountingSync\Sync\InvoiceSync;
use WPCalibrate\AccountingSync\Sync\RefundSync;
use WPCalibrate\AccountingSync\Sync\InventorySync;
use WPCalibrate\AccountingSync\Database\LogRepository;
use WPCalibrate\AccountingSync\Database\WebhookRepository;
use WPCalibrate\AccountingSync\Logging\Logger;
use Exception;

/**
 * Handles scheduling, concurrency control, and retries using WooCommerce Action Scheduler.
 */
final class ActionSchedulerQueue {

	public const HOOK_PROCESS_ORDER     = 'wpcalibrate_accounting_sync_process_order';
	public const HOOK_PROCESS_REFUND    = 'wpcalibrate_accounting_sync_process_refund';
	public const HOOK_PROCESS_INVENTORY = 'wpcalibrate_accounting_sync_process_inventory';
	public const HOOK_PROCESS_WEBHOOK   = 'wpcalibrate_accounting_sync_process_webhook';
	public const HOOK_PRUNE_LOGS        = 'wpcalibrate_accounting_sync_prune_logs';

	private const MAX_ATTEMPTS = 3;

	/**
	 * Hook registrations.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::HOOK_PROCESS_ORDER, [ $this, 'handle_order_sync' ], 10, 2 );
		add_action( self::HOOK_PROCESS_REFUND, [ $this, 'handle_refund_sync' ], 10, 2 );
		add_action( self::HOOK_PROCESS_INVENTORY, [ $this, 'handle_inventory_sync' ], 10, 2 );
		add_action( self::HOOK_PROCESS_WEBHOOK, [ $this, 'handle_webhook_process' ], 10, 2 );
		add_action( self::HOOK_PRUNE_LOGS, [ $this, 'handle_log_prune' ] );

		// Register daily log pruning if not scheduled.
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK_PRUNE_LOGS ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_PRUNE_LOGS );
		}
	}

	/**
	 * Enqueues an order for background synchronization.
	 *
	 * @param int $order_id      WooCommerce order ID.
	 * @param int $delay_seconds Delay in seconds.
	 * @return void
	 */
	public static function enqueue_order_sync( int $order_id, int $delay_seconds = 0 ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		// Avoid duplicate queueing of the same order if already pending.
		if ( as_has_scheduled_action( self::HOOK_PROCESS_ORDER, [ $order_id, 1 ] ) ) {
			return;
		}

		if ( $delay_seconds > 0 ) {
			as_schedule_single_action( time() + $delay_seconds, self::HOOK_PROCESS_ORDER, [ $order_id, 1 ] );
		} else {
			as_enqueue_async_action( self::HOOK_PROCESS_ORDER, [ $order_id, 1 ] );
		}
	}

	/**
	 * Enqueues a refund for background synchronization.
	 *
	 * @param int $refund_id     WooCommerce refund ID.
	 * @param int $delay_seconds Delay in seconds.
	 * @return void
	 */
	public static function enqueue_refund_sync( int $refund_id, int $delay_seconds = 0 ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( $delay_seconds > 0 ) {
			as_schedule_single_action( time() + $delay_seconds, self::HOOK_PROCESS_REFUND, [ $refund_id, 1 ] );
		} else {
			as_enqueue_async_action( self::HOOK_PROCESS_REFUND, [ $refund_id, 1 ] );
		}
	}

	/**
	 * Enqueues inventory synchronization.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function enqueue_inventory_sync( int $product_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK_PROCESS_INVENTORY, [ $product_id, 1 ] );
	}

	/**
	 * Enqueues webhook payload processing.
	 *
	 * @param string               $event_id Event ID.
	 * @param array<string, mixed> $payload  Webhook event data.
	 * @return void
	 */
	public static function enqueue_webhook_process( string $event_id, array $payload ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK_PROCESS_WEBHOOK, [ $event_id, $payload ] );
	}

	/**
	 * Background worker for order synchronization.
	 *
	 * @param int $order_id Order ID.
	 * @param int $attempt  Current retry attempt.
	 * @return void
	 */
	public function handle_order_sync( int $order_id, int $attempt = 1 ): void {
		if ( ! LockManager::acquire( 'order', (string) $order_id ) ) {
			// Reschedule if currently locked by concurrent process.
			as_schedule_single_action( time() + 30, self::HOOK_PROCESS_ORDER, [ $order_id, $attempt ] );
			return;
		}

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				Logger::error( 'Order', (string) $order_id, 'queue_sync', "Order #{$order_id} not found." );
				return;
			}

			$general = get_option( 'wpcalibrate_accounting_sync_general', [] );
			$mode    = $general['accounting_mode'] ?? 'sales_receipt';

			$client = new QuickBooksClient();

			if ( 'invoice_payment' === $mode ) {
				$sync = new InvoiceSync( $client );
				$sync->sync( $order );
			} else {
				$sync = new SalesReceiptSync( $client );
				$sync->sync( $order );
			}
		} catch ( Exception $e ) {
			$is_retryable = $this->is_retryable_error( $e->getMessage() );

			if ( $is_retryable && $attempt < self::MAX_ATTEMPTS ) {
				$backoff = $attempt === 1 ? 60 : 300;
				as_schedule_single_action( time() + $backoff, self::HOOK_PROCESS_ORDER, [ $order_id, $attempt + 1 ] );
				Logger::warning(
					'Order',
					(string) $order_id,
					'queue_retry',
					sprintf( 'Order #%d sync failed (attempt %d/%d). Retrying in %d seconds. Reason: %s', $order_id, $attempt, self::MAX_ATTEMPTS, $backoff, $e->getMessage() )
				);
			} else {
				Logger::error(
					'Order',
					(string) $order_id,
					'queue_failed',
					sprintf( 'Order #%d sync permanently failed after %d attempts: %s', $order_id, $attempt, $e->getMessage() )
				);
			}
		} finally {
			LockManager::release( 'order', (string) $order_id );
		}
	}

	/**
	 * Background worker for refund synchronization.
	 */
	public function handle_refund_sync( int $refund_id, int $attempt = 1 ): void {
		if ( ! LockManager::acquire( 'refund', (string) $refund_id ) ) {
			as_schedule_single_action( time() + 30, self::HOOK_PROCESS_REFUND, [ $refund_id, $attempt ] );
			return;
		}

		try {
			$refund = wc_get_order( $refund_id );
			if ( ! $refund instanceof \WC_Order_Refund ) {
				return;
			}

			$client = new QuickBooksClient();
			$sync   = new RefundSync( $client );
			$sync->sync( $refund );
		} catch ( Exception $e ) {
			if ( $attempt < self::MAX_ATTEMPTS && $this->is_retryable_error( $e->getMessage() ) ) {
				as_schedule_single_action( time() + 120, self::HOOK_PROCESS_REFUND, [ $refund_id, $attempt + 1 ] );
			} else {
				Logger::error( 'Refund', (string) $refund_id, 'queue_refund_failed', 'Refund sync failed: ' . $e->getMessage() );
			}
		} finally {
			LockManager::release( 'refund', (string) $refund_id );
		}
	}

	/**
	 * Background worker for inventory synchronization.
	 */
	public function handle_inventory_sync( int $product_id, int $attempt = 1 ): void {
		try {
			$client = new QuickBooksClient();
			$sync   = new InventorySync( $client );
			$sync->sync_wc_to_qbo( $product_id );
		} catch ( Exception $e ) {
			Logger::warning( 'Inventory', (string) $product_id, 'queue_inventory', 'Inventory sync failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Background worker for webhook processing.
	 */
	public function handle_webhook_process( string $event_id, array $payload ): void {
		try {
			$entity_name = $payload['name'] ?? '';
			$entity_id   = (string) ( $payload['id'] ?? '' );
			$operation   = $payload['operation'] ?? '';

			if ( 'Item' === $entity_name && 'Update' === $operation ) {
				$client = new QuickBooksClient();
				$res    = $client->get( 'item/' . $entity_id );
				$item   = $res['Item'] ?? null;
				if ( $item && isset( $item['QtyOnHand'] ) ) {
					$inv_sync = new InventorySync( $client );
					$inv_sync->sync_qbo_to_wc( $entity_id, (float) $item['QtyOnHand'] );
				}
			}

			WebhookRepository::update_status( $event_id, 'processed' );
		} catch ( Exception $e ) {
			WebhookRepository::update_status( $event_id, 'failed' );
			Logger::error( 'Webhook', $event_id, 'webhook_worker', 'Webhook background processing failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Scheduled log pruning handler.
	 */
	public function handle_log_prune(): void {
		$general = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$days    = (int) ( $general['log_retention_days'] ?? 30 );
		$pruned  = LogRepository::prune( $days );
		WebhookRepository::prune( 14 );

		if ( $pruned > 0 ) {
			Logger::info( 'System', '0', 'prune_logs', "Pruned {$pruned} audit log entries older than {$days} days." );
		}
	}

	/**
	 * Determines whether an error is transient/retryable.
	 */
	private function is_retryable_error( string $error_message ): bool {
		$lower = strtolower( $error_message );
		$retryable_keywords = [
			'timeout',
			'timed out',
			'curl error 28',
			'connection reset',
			'429',
			'rate limit',
			'502 bad gateway',
			'503 service unavailable',
			'504 gateway timeout',
			'token refresh',
		];

		foreach ( $retryable_keywords as $keyword ) {
			if ( str_contains( $lower, $keyword ) ) {
				return true;
			}
		}

		return false;
	}
}
