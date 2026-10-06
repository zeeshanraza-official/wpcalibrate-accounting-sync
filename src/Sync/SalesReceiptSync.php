<?php
/**
 * QuickBooks Sales Receipt Synchronization.
 *
 * @package WPCalibrate\AccountingSync\Sync
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Sync;

use WPCalibrate\AccountingSync\Client\QuickBooksClient;
use WPCalibrate\AccountingSync\Database\MappingRepository;
use WPCalibrate\AccountingSync\Logging\Logger;
use WC_Order;
use Exception;
use RuntimeException;

/**
 * Handles export of paid WooCommerce orders as QuickBooks Online Sales Receipts.
 */
final class SalesReceiptSync {

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
	 * Synchronizes an order to QuickBooks as a Sales Receipt.
	 *
	 * @param WC_Order $order WooCommerce Order.
	 * @return string QuickBooks SalesReceipt ID.
	 * @throws RuntimeException If synchronization fails.
	 */
	public function sync( WC_Order $order ): string {
		$order_id = $order->get_id();

		// 1. Idempotency Check: verify if already synced.
		$existing_qbo_id = MappingRepository::get_qbo_id(
			$this->environment,
			$this->realm_id,
			'order',
			$order_id
		);

		if ( ! empty( $existing_qbo_id ) ) {
			Logger::info( 'Order', (string) $order_id, 'sync_sales_receipt', "Order #{$order_id} already synced to SalesReceipt #{$existing_qbo_id} (Skipping duplicate create)" );
			return $existing_qbo_id;
		}

		// 2. Normalize order data.
		$norm = OrderNormalizer::normalize( $order );

		// Validate paid status.
		if ( ! $norm['is_paid'] ) {
			throw new RuntimeException( esc_html( sprintf( 'Order #%d is not marked as paid. Sales Receipts require completed payment.', $order_id ) ) );
		}

		// 3. Resolve Customer in QuickBooks.
		$customer_id = $this->customer_sync->resolve_customer(
			$norm['customer_id'],
			$norm['billing']['email'],
			$norm['billing']['first_name'],
			$norm['billing']['last_name'],
			$norm['billing']['company'],
			$norm['billing']
		);

		// 4. Build Sales Receipt Lines.
		$lines = [];
		$mapping_rules = get_option( 'wpcalibrate_accounting_sync_mapping_rules', [] );

		foreach ( $norm['lines'] as $line ) {
			$qbo_item_id = $this->product_sync->resolve_item_id(
				(int) $line['item_id'],
				$line['name'],
				$line['sku'],
				(float) $line['unit_price']
			);

			$item_detail = [
				'ItemRef'   => [ 'value' => $qbo_item_id ],
				'UnitPrice' => round( (float) $line['unit_price'], 2 ),
				'Qty'       => round( (float) $line['quantity'], 2 ),
			];

			// Tax code reference if tax mapping is present.
			$tax_code_id = $this->resolve_tax_code_id( $line['tax_class'], $mapping_rules );
			if ( ! empty( $tax_code_id ) ) {
				$item_detail['TaxCodeRef'] = [ 'value' => $tax_code_id ];
			}

			$lines[] = [
				'DetailType'          => 'SalesItemLineDetail',
				'Amount'              => round( (float) $line['amount'], 2 ),
				'SalesItemLineDetail' => $item_detail,
				'Description'         => substr( $line['name'], 0, 4000 ),
			];
		}

		// 5. Add Shipping Line.
		if ( $norm['shipping_total'] > 0 ) {
			$shipping_item_id = (string) ( $mapping_rules['shipping_item_id'] ?? '' );
			if ( empty( $shipping_item_id ) ) {
				$shipping_item_id = (string) ( $mapping_rules['fallback_item_id'] ?? '' );
			}

			if ( ! empty( $shipping_item_id ) ) {
				$lines[] = [
					'DetailType'          => 'SalesItemLineDetail',
					'Amount'              => round( (float) $norm['shipping_total'], 2 ),
					'SalesItemLineDetail' => [
						'ItemRef'   => [ 'value' => $shipping_item_id ],
						'UnitPrice' => round( (float) $norm['shipping_total'], 2 ),
						'Qty'       => 1,
					],
					'Description'         => 'Shipping Fee',
				];
			}
		}

		// 6. Add Discount Line if discounts exist.
		if ( $norm['discount_total'] > 0 ) {
			$lines[] = [
				'DetailType'         => 'DiscountLineDetail',
				'Amount'             => round( (float) $norm['discount_total'], 2 ),
				'DiscountLineDetail' => [
					'PercentBased' => false,
				],
			];
		}

		// 7. Resolve Deposit Account & Payment Method.
		$deposit_account_id = $this->resolve_deposit_account_id( $norm['payment_method'], $mapping_rules );
		$payment_method_id  = $this->resolve_payment_method_id( $norm['payment_method'], $mapping_rules );

		// 8. Assemble full SalesReceipt payload.
		$payload = [
			'DocNumber'   => substr( (string) $norm['order_number'], 0, 21 ),
			'TxnDate'     => $norm['date_paid'],
			'CustomerRef' => [ 'value' => $customer_id ],
			'Line'        => $lines,
		];

		if ( ! empty( $deposit_account_id ) ) {
			$payload['DepositToAccountRef'] = [ 'value' => $deposit_account_id ];
		}

		if ( ! empty( $payment_method_id ) ) {
			$payload['PaymentMethodRef'] = [ 'value' => $payment_method_id ];
		}

		if ( ! empty( $norm['transaction_id'] ) ) {
			$payload['PaymentRefNum'] = substr( $norm['transaction_id'], 0, 21 );
		}

		// Post to QuickBooks.
		try {
			$res = $this->client->post( 'salesreceipt', $payload );
			$qbo_id = (string) ( $res['SalesReceipt']['Id'] ?? '' );

			if ( empty( $qbo_id ) ) {
				throw new RuntimeException( 'Invalid response from QuickBooks: missing SalesReceipt Id.' );
			}

			// Save mapping.
			$sync_hash = md5( (string) $norm['total'] . ':' . $norm['status'] );
			MappingRepository::set_mapping(
				$this->environment,
				$this->realm_id,
				'order',
				$order_id,
				'SalesReceipt',
				$qbo_id,
				$sync_hash,
				'synced'
			);

			// Add order note for tracking.
			$order->add_order_note(
				sprintf(
					/* translators: 1: Environment, 2: Sales Receipt ID */
					esc_html__( '[WPCalibrate] Exported to QuickBooks %1$s as Sales Receipt #%2$s', 'wpcalibrate-accounting-sync' ),
					ucfirst( $this->environment ),
					$qbo_id
				)
			);

			Logger::info( 'Order', (string) $order_id, 'sync_sales_receipt', "Successfully synced Order #{$order_id} to QuickBooks Sales Receipt #{$qbo_id}" );

			return $qbo_id;
		} catch ( Exception $e ) {
			Logger::error( 'Order', (string) $order_id, 'sync_sales_receipt', "Failed to sync Order #{$order_id}: " . $e->getMessage() );
			throw new RuntimeException( esc_html( 'QuickBooks Sales Receipt export failed: ' . $e->getMessage() ) );
		}
	}

	/**
	 * Resolves QuickBooks Tax Code for a WooCommerce tax class.
	 */
	private function resolve_tax_code_id( string $tax_class, array $mapping_rules ): ?string {
		$tax_map = $mapping_rules['tax_map'] ?? [];
		$key     = sanitize_key( $tax_class ?: 'standard' );
		return ! empty( $tax_map[ $key ] ) ? (string) $tax_map[ $key ] : null;
	}

	/**
	 * Resolves Deposit Account ID for payment gateway.
	 */
	private function resolve_deposit_account_id( string $payment_method, array $mapping_rules ): ?string {
		$account_map = $mapping_rules['payment_account_map'] ?? [];
		if ( ! empty( $account_map[ $payment_method ] ) ) {
			return (string) $account_map[ $payment_method ];
		}
		return ! empty( $mapping_rules['default_deposit_account_id'] ) ? (string) $mapping_rules['default_deposit_account_id'] : null;
	}

	/**
	 * Resolves QuickBooks Payment Method ID for gateway.
	 */
	private function resolve_payment_method_id( string $payment_method, array $mapping_rules ): ?string {
		$method_map = $mapping_rules['payment_method_map'] ?? [];
		return ! empty( $method_map[ $payment_method ] ) ? (string) $method_map[ $payment_method ] : null;
	}
}
