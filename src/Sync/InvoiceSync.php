<?php
/**
 * QuickBooks Invoice + Payment Synchronization.
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
 * Handles export of WooCommerce orders as QuickBooks Online Invoices and conditional Payments.
 */
final class InvoiceSync {

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
	 * Synchronizes an order to QuickBooks as an Invoice and linked Payment.
	 *
	 * @param WC_Order $order WooCommerce Order.
	 * @return array{invoice_id: string, payment_id: string|null}
	 * @throws RuntimeException If synchronization fails.
	 */
	public function sync( WC_Order $order ): array {
		$order_id = $order->get_id();

		// 1. Resolve or create Invoice.
		$invoice_id = MappingRepository::get_qbo_id(
			$this->environment,
			$this->realm_id,
			'order_invoice',
			$order_id
		);

		$norm = OrderNormalizer::normalize( $order );

		$customer_id = $this->customer_sync->resolve_customer(
			$norm['customer_id'],
			$norm['billing']['email'],
			$norm['billing']['first_name'],
			$norm['billing']['last_name'],
			$norm['billing']['company'],
			$norm['billing']
		);

		if ( empty( $invoice_id ) ) {
			$invoice_id = $this->create_invoice( $norm, $customer_id );
			MappingRepository::set_mapping(
				$this->environment,
				$this->realm_id,
				'order_invoice',
				$order_id,
				'Invoice',
				$invoice_id,
				md5( (string) $norm['total'] ),
				'synced'
			);

			$order->add_order_note(
				sprintf(
					/* translators: 1: Environment, 2: Invoice ID */
					esc_html__( '[WPCalibrate] Created QuickBooks %1$s Invoice #%2$s', 'wpcalibrate-accounting-sync' ),
					ucfirst( $this->environment ),
					$invoice_id
				)
			);
		}

		// 2. Conditionally create Payment if order is marked paid.
		$payment_id = null;
		if ( $norm['is_paid'] ) {
			$existing_payment_id = MappingRepository::get_qbo_id(
				$this->environment,
				$this->realm_id,
				'order_payment',
				$order_id
			);

			if ( ! empty( $existing_payment_id ) ) {
				$payment_id = $existing_payment_id;
				Logger::info( 'Order', (string) $order_id, 'sync_invoice', "Payment #{$payment_id} already exists for Order #{$order_id}" );
			} else {
				$payment_id = $this->create_payment( $norm, $customer_id, $invoice_id );
				MappingRepository::set_mapping(
					$this->environment,
					$this->realm_id,
					'order_payment',
					$order_id,
					'Payment',
					$payment_id,
					md5( (string) $norm['total'] ),
					'synced'
				);

				$order->add_order_note(
					sprintf(
						/* translators: 1: Environment, 2: Payment ID, 3: Invoice ID */
						esc_html__( '[WPCalibrate] Created QuickBooks %1$s Payment #%2$s linked to Invoice #%3$s', 'wpcalibrate-accounting-sync' ),
						ucfirst( $this->environment ),
						$payment_id,
						$invoice_id
					)
				);
			}
		}

		// Also record the primary 'order' mapping.
		MappingRepository::set_mapping(
			$this->environment,
			$this->realm_id,
			'order',
			$order_id,
			'Invoice',
			$invoice_id,
			md5( (string) $norm['total'] . ':' . $norm['status'] ),
			'synced'
		);

		return [
			'invoice_id' => $invoice_id,
			'payment_id' => $payment_id,
		];
	}

	/**
	 * Creates Invoice entity in QuickBooks Online.
	 */
	private function create_invoice( array $norm, string $customer_id ): string {
		$mapping_rules = get_option( 'wpcalibrate_accounting_sync_mapping_rules', [] );
		$lines         = [];

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

			$tax_map     = $mapping_rules['tax_map'] ?? [];
			$tax_code_id = ! empty( $tax_map[ sanitize_key( $line['tax_class'] ?: 'standard' ) ] ) ? (string) $tax_map[ sanitize_key( $line['tax_class'] ?: 'standard' ) ] : null;
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

		if ( $norm['shipping_total'] > 0 ) {
			$shipping_item_id = (string) ( $mapping_rules['shipping_item_id'] ?? ( $mapping_rules['fallback_item_id'] ?? '' ) );
			if ( ! empty( $shipping_item_id ) ) {
				$lines[] = [
					'DetailType'          => 'SalesItemLineDetail',
					'Amount'              => round( (float) $norm['shipping_total'], 2 ),
					'SalesItemLineDetail' => [
						'ItemRef'   => [ 'value' => $shipping_item_id ],
						'UnitPrice' => round( (float) $norm['shipping_total'], 2 ),
						'Qty'       => 1,
					],
					'Description'         => 'Shipping',
				];
			}
		}

		if ( $norm['discount_total'] > 0 ) {
			$lines[] = [
				'DetailType'         => 'DiscountLineDetail',
				'Amount'             => round( (float) $norm['discount_total'], 2 ),
				'DiscountLineDetail' => [ 'PercentBased' => false ],
			];
		}

		$payload = [
			'DocNumber'   => substr( (string) $norm['order_number'], 0, 21 ),
			'TxnDate'     => $norm['date_created'],
			'CustomerRef' => [ 'value' => $customer_id ],
			'Line'        => $lines,
		];

		try {
			$res = $this->client->post( 'invoice', $payload );
			$invoice_id = (string) ( $res['Invoice']['Id'] ?? '' );
			if ( empty( $invoice_id ) ) {
				throw new RuntimeException( 'QuickBooks did not return an Invoice Id.' );
			}
			return $invoice_id;
		} catch ( Exception $e ) {
			throw new RuntimeException( esc_html( 'Failed to create QuickBooks Invoice: ' . $e->getMessage() ) );
		}
	}

	/**
	 * Creates Payment linked to Invoice.
	 */
	private function create_payment( array $norm, string $customer_id, string $invoice_id ): string {
		$mapping_rules      = get_option( 'wpcalibrate_accounting_sync_mapping_rules', [] );
		$account_map        = $mapping_rules['payment_account_map'] ?? [];
		$deposit_account_id = $account_map[ $norm['payment_method'] ] ?? ( $mapping_rules['default_deposit_account_id'] ?? null );
		$method_map         = $mapping_rules['payment_method_map'] ?? [];
		$payment_method_id  = $method_map[ $norm['payment_method'] ] ?? null;

		$payload = [
			'CustomerRef' => [ 'value' => $customer_id ],
			'TotalAmt'    => round( (float) $norm['total'], 2 ),
			'TxnDate'     => $norm['date_paid'],
			'Line'        => [
				[
					'Amount'    => round( (float) $norm['total'], 2 ),
					'LinkedTxn' => [
						[
							'TxnId'   => $invoice_id,
							'TxnType' => 'Invoice',
						],
					],
				],
			],
		];

		if ( ! empty( $deposit_account_id ) ) {
			$payload['DepositToAccountRef'] = [ 'value' => (string) $deposit_account_id ];
		}
		if ( ! empty( $payment_method_id ) ) {
			$payload['PaymentMethodRef'] = [ 'value' => (string) $payment_method_id ];
		}
		if ( ! empty( $norm['transaction_id'] ) ) {
			$payload['PaymentRefNum'] = substr( $norm['transaction_id'], 0, 21 );
		}

		try {
			$res = $this->client->post( 'payment', $payload );
			$payment_id = (string) ( $res['Payment']['Id'] ?? '' );
			if ( empty( $payment_id ) ) {
				throw new RuntimeException( 'QuickBooks did not return a Payment Id.' );
			}
			return $payment_id;
		} catch ( Exception $e ) {
			throw new RuntimeException( esc_html( 'Failed to create QuickBooks Payment: ' . $e->getMessage() ) );
		}
	}
}
