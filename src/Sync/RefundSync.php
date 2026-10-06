<?php
/**
 * WooCommerce Refund Synchronization.
 *
 * @package WPCalibrate\AccountingSync\Sync
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Sync;

use WPCalibrate\AccountingSync\Client\QuickBooksClient;
use WPCalibrate\AccountingSync\Database\MappingRepository;
use WPCalibrate\AccountingSync\Logging\Logger;
use WC_Order_Refund;
use WC_Order;
use Exception;
use RuntimeException;

/**
 * Handles export of WooCommerce full and partial refunds as QuickBooks Online Refund Receipts.
 */
final class RefundSync {

	private QuickBooksClient $client;
	private CustomerSync $customer_sync;
	private ProductSync $product_sync;
	private string $environment;
	private string $realm_id;

	/**
	 * Constructor.
	 *
	 * @param QuickBooksClient $client API client.
	 */
	public function __construct( QuickBooksClient $client ) {
		$this->client        = $client;
		$this->environment   = $client->get_environment();
		$this->realm_id      = $client->get_realm_id();
		$this->customer_sync = new CustomerSync( $client );
		$this->product_sync  = new ProductSync( $client );
	}

	/**
	 * Synchronizes a WooCommerce Refund.
	 *
	 * @param WC_Order_Refund $refund WooCommerce Refund object.
	 * @return string QuickBooks RefundReceipt ID.
	 * @throws RuntimeException On failure.
	 */
	public function sync( WC_Order_Refund $refund ): string {
		$refund_id = $refund->get_id();
		$parent_id = $refund->get_parent_id();

		// 1. Idempotency check.
		$existing_qbo_id = MappingRepository::get_qbo_id(
			$this->environment,
			$this->realm_id,
			'refund',
			$refund_id
		);

		if ( ! empty( $existing_qbo_id ) ) {
			Logger::info( 'Refund', (string) $refund_id, 'sync_refund', "Refund #{$refund_id} already synced to RefundReceipt #{$existing_qbo_id}" );
			return $existing_qbo_id;
		}

		$parent_order = wc_get_order( $parent_id );
		if ( ! $parent_order instanceof WC_Order ) {
			throw new RuntimeException( esc_html( sprintf( 'Parent order #%d for refund #%d not found.', $parent_id, $refund_id ) ) );
		}

		// Ensure parent order is mapped in QBO.
		$parent_qbo_id = MappingRepository::get_qbo_id(
			$this->environment,
			$this->realm_id,
			'order',
			$parent_id
		);

		if ( empty( $parent_qbo_id ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Parent order #%d must be synced to QuickBooks before syncing refund #%d.', $parent_id, $refund_id ) ) );
		}

		// Resolve Customer.
		$norm_parent = OrderNormalizer::normalize( $parent_order );
		$customer_id = $this->customer_sync->resolve_customer(
			$norm_parent['customer_id'],
			$norm_parent['billing']['email'],
			$norm_parent['billing']['first_name'],
			$norm_parent['billing']['last_name'],
			$norm_parent['billing']['company'],
			$norm_parent['billing']
		);

		$refund_amount = abs( (float) $refund->get_amount() );
		if ( $refund_amount <= 0 ) {
			throw new RuntimeException( 'Refund amount must be greater than zero.' );
		}

		$mapping_rules    = get_option( 'wpcalibrate_accounting_sync_mapping_rules', [] );
		$fallback_item_id = (string) ( $mapping_rules['fallback_item_id'] ?? '' );
		if ( empty( $fallback_item_id ) ) {
			$fallback_item_id = (string) ( $mapping_rules['default_sales_item_id'] ?? '1' );
		}

		$lines = [];
		$refund_items = $refund->get_items();

		if ( ! empty( $refund_items ) ) {
			foreach ( $refund_items as $item ) {
				$prod_id  = $item->get_product_id();
				$line_amt = abs( (float) $item->get_total() );
				$qty      = abs( (float) $item->get_quantity() );
				if ( $qty <= 0 ) {
					$qty = 1;
				}

				$qbo_item_id = $this->product_sync->resolve_item_id(
					$prod_id,
					$item->get_name(),
					'',
					$line_amt / $qty
				);

				$lines[] = [
					'DetailType'          => 'SalesItemLineDetail',
					'Amount'              => round( $line_amt, 2 ),
					'SalesItemLineDetail' => [
						'ItemRef'   => [ 'value' => $qbo_item_id ],
						'UnitPrice' => round( $line_amt / $qty, 2 ),
						'Qty'       => round( $qty, 2 ),
					],
					'Description'         => substr( 'Refund: ' . $item->get_name(), 0, 4000 ),
				];
			}
		}

		// Fallback single line if lines couldn't be parsed item-by-item.
		if ( empty( $lines ) ) {
			$lines[] = [
				'DetailType'          => 'SalesItemLineDetail',
				'Amount'              => round( $refund_amount, 2 ),
				'SalesItemLineDetail' => [
					'ItemRef'   => [ 'value' => $fallback_item_id ],
					'UnitPrice' => round( $refund_amount, 2 ),
					'Qty'       => 1,
				],
				'Description'         => substr( 'Refund for Order #' . $parent_id . ': ' . $refund->get_reason(), 0, 4000 ),
			];
		}

		$deposit_account_id = $mapping_rules['payment_account_map'][ $norm_parent['payment_method'] ] ?? ( $mapping_rules['default_deposit_account_id'] ?? null );

		$payload = [
			'CustomerRef' => [ 'value' => $customer_id ],
			'TxnDate'     => $refund->get_date_created() ? $refund->get_date_created()->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'TotalAmt'    => round( $refund_amount, 2 ),
			'Line'        => $lines,
		];

		if ( ! empty( $deposit_account_id ) ) {
			$payload['DepositToAccountRef'] = [ 'value' => (string) $deposit_account_id ];
		}

		try {
			$res = $this->client->post( 'refundreceipt', $payload );
			$qbo_id = (string) ( $res['RefundReceipt']['Id'] ?? '' );
			if ( empty( $qbo_id ) ) {
				throw new RuntimeException( 'QuickBooks did not return a RefundReceipt Id.' );
			}

			MappingRepository::set_mapping(
				$this->environment,
				$this->realm_id,
				'refund',
				$refund_id,
				'RefundReceipt',
				$qbo_id,
				md5( (string) $refund_amount ),
				'synced'
			);

			$parent_order->add_order_note(
				sprintf(
					/* translators: 1: Environment, 2: RefundReceipt ID, 3: Amount */
					esc_html__( '[WPCalibrate] Exported Refund to QuickBooks %1$s as RefundReceipt #%2$s (Amount: %3$s)', 'wpcalibrate-accounting-sync' ),
					ucfirst( $this->environment ),
					$qbo_id,
					wc_price( $refund_amount )
				)
			);

			Logger::info( 'Refund', (string) $refund_id, 'sync_refund', "Successfully synced Refund #{$refund_id} to RefundReceipt #{$qbo_id}" );

			return $qbo_id;
		} catch ( Exception $e ) {
			Logger::error( 'Refund', (string) $refund_id, 'sync_refund', "Failed to sync refund #{$refund_id}: " . $e->getMessage() );
			throw new RuntimeException( esc_html( 'QuickBooks Refund sync failed: ' . $e->getMessage() ) );
		}
	}
}
