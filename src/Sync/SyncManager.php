<?php
/**
 * WooCommerce Synchronization Triggers Manager.
 *
 * @package WPCalibrate\AccountingSync\Sync
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Sync;

use WPCalibrate\AccountingSync\Queue\ActionSchedulerQueue;
use WPCalibrate\AccountingSync\Logging\Logger;

/**
 * Listens to WooCommerce lifecycle events and safely enqueues background sync tasks.
 */
final class SyncManager {

	/**
	 * Hook registrations.
	 *
	 * @return void
	 */
	public function init(): void {
		// Order status change trigger.
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );

		// Payment complete trigger.
		add_action( 'woocommerce_payment_complete', [ $this, 'on_payment_complete' ], 10, 1 );

		// Order refund trigger.
		add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 10, 2 );

		// Product stock change triggers.
		add_action( 'woocommerce_product_set_stock', [ $this, 'on_product_stock_changed' ], 10, 1 );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'on_product_stock_changed' ], 10, 1 );
	}

	/**
	 * Handles WooCommerce order status transition.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $status_from Old status.
	 * @param string   $status_to   New status.
	 * @param \WC_Order $order     Order object.
	 * @return void
	 */
	public function on_order_status_changed( int $order_id, string $status_from, string $status_to, \WC_Order $order ): void {
		$general = get_option( 'wpcalibrate_accounting_sync_general', [] );
		if ( empty( $general['sync_enabled'] ) || empty( $general['auto_sync'] ) ) {
			return;
		}

		$configured_statuses = (array) ( $general['export_order_statuses'] ?? [ 'wc-completed', 'wc-processing' ] );

		// Normalize status string (with or without 'wc-' prefix).
		$normalized_status = 'wc-' . ltrim( $status_to, 'wc-' );

		if ( in_array( $normalized_status, $configured_statuses, true ) || in_array( $status_to, $configured_statuses, true ) ) {
			Logger::info( 'Order', (string) $order_id, 'trigger_status_changed', "Enqueueing Order #{$order_id} sync on transition to {$status_to}" );
			ActionSchedulerQueue::enqueue_order_sync( $order_id );
		}
	}

	/**
	 * Handles payment completion.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function on_payment_complete( int $order_id ): void {
		$general = get_option( 'wpcalibrate_accounting_sync_general', [] );
		if ( empty( $general['sync_enabled'] ) || empty( $general['auto_sync'] ) ) {
			return;
		}

		ActionSchedulerQueue::enqueue_order_sync( $order_id );
	}

	/**
	 * Handles order refund created.
	 *
	 * @param int $order_id  Parent order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public function on_order_refunded( int $order_id, int $refund_id ): void {
		$general = get_option( 'wpcalibrate_accounting_sync_general', [] );
		if ( empty( $general['sync_enabled'] ) || empty( $general['auto_sync'] ) ) {
			return;
		}

		Logger::info( 'Refund', (string) $refund_id, 'trigger_refund', "Enqueueing Refund #{$refund_id} for Order #{$order_id}" );
		ActionSchedulerQueue::enqueue_refund_sync( $refund_id );
	}

	/**
	 * Handles product stock change.
	 *
	 * @param \WC_Product $product Product or variation object.
	 * @return void
	 */
	public function on_product_stock_changed( \WC_Product $product ): void {
		$general = get_option( 'wpcalibrate_accounting_sync_general', [] );
		if ( empty( $general['sync_enabled'] ) || ( $general['inventory_sync_direction'] ?? '' ) !== 'wc_to_qbo' ) {
			return;
		}

		$product_id = $product->get_id();
		ActionSchedulerQueue::enqueue_inventory_sync( $product_id );
	}
}
