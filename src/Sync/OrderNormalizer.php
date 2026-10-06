<?php
/**
 * WooCommerce Order Normalizer.
 *
 * @package WPCalibrate\AccountingSync\Sync
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Sync;

use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Item_Fee;
use RuntimeException;

/**
 * Normalizes WC_Order CRUD objects into a clean, normalized data structure for QuickBooks Online export.
 */
final class OrderNormalizer {

	/**
	 * Extracts and normalizes order data.
	 *
	 * @param WC_Order $order WooCommerce Order.
	 * @return array<string, mixed> Normalized order dictionary.
	 * @throws RuntimeException If order data is invalid or corrupt.
	 */
	public static function normalize( WC_Order $order ): array {
		$order_id = $order->get_id();
		if ( ! $order_id ) {
			throw new RuntimeException( 'Cannot normalize order with invalid ID.' );
		}

		$lines    = [];
		$currency = $order->get_currency();

		// 1. Line Items (Products / Variations).
		/** @var WC_Order_Item_Product $item */
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$effective_id = $variation_id > 0 ? $variation_id : $product_id;
			$product      = $item->get_product();

			$qty       = (float) $item->get_quantity();
			$subtotal  = (float) $item->get_subtotal();
			$total     = (float) $item->get_total();
			$tax_total = (float) $item->get_total_tax();

			// Calculate unit price from subtotal or total / qty.
			$unit_price = $qty > 0 ? round( $subtotal / $qty, 4 ) : 0.0;
			$discount   = max( 0.0, round( $subtotal - $total, 4 ) );

			$sku = $product ? (string) $product->get_sku() : '';

			$lines[] = [
				'type'         => 'SalesItemLineDetail',
				'item_id'      => $effective_id,
				'name'         => $item->get_name(),
				'sku'          => $sku,
				'quantity'     => $qty,
				'unit_price'   => $unit_price,
				'amount'       => round( $total, 2 ),
				'tax_amount'   => round( $tax_total, 2 ),
				'tax_class'    => (string) $item->get_tax_class(),
				'discount'     => $discount,
			];
		}

		// 2. Shipping Lines.
		$shipping_lines = [];
		/** @var WC_Order_Item_Shipping $shipping_item */
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			$shipping_lines[] = [
				'name'       => $shipping_item->get_name(),
				'method_id'  => $shipping_item->get_method_id(),
				'amount'     => (float) $shipping_item->get_total(),
				'tax_amount' => (float) $shipping_item->get_total_tax(),
			];
		}

		// 3. Fee Lines.
		$fee_lines = [];
		/** @var WC_Order_Item_Fee $fee_item */
		foreach ( $order->get_items( 'fee' ) as $fee_item ) {
			$fee_lines[] = [
				'name'       => $fee_item->get_name(),
				'amount'     => (float) $fee_item->get_total(),
				'tax_amount' => (float) $fee_item->get_total_tax(),
			];
		}

		// 4. Billing Details.
		$billing = [
			'first_name' => (string) $order->get_billing_first_name(),
			'last_name'  => (string) $order->get_billing_last_name(),
			'company'    => (string) $order->get_billing_company(),
			'email'      => (string) $order->get_billing_email(),
			'phone'      => (string) $order->get_billing_phone(),
			'address_1'  => (string) $order->get_billing_address_1(),
			'address_2'  => (string) $order->get_billing_address_2(),
			'city'       => (string) $order->get_billing_city(),
			'state'      => (string) $order->get_billing_state(),
			'postcode'   => (string) $order->get_billing_postcode(),
			'country'    => (string) $order->get_billing_country(),
		];

		// 5. Shipping Details.
		$shipping = [
			'first_name' => (string) $order->get_shipping_first_name(),
			'last_name'  => (string) $order->get_shipping_last_name(),
			'company'    => (string) $order->get_shipping_company(),
			'address_1'  => (string) $order->get_shipping_address_1(),
			'address_2'  => (string) $order->get_shipping_address_2(),
			'city'       => (string) $order->get_shipping_city(),
			'state'      => (string) $order->get_shipping_state(),
			'postcode'   => (string) $order->get_shipping_postcode(),
			'country'    => (string) $order->get_shipping_country(),
		];

		// 6. Payment Information.
		$payment_method       = (string) $order->get_payment_method();
		$payment_method_title = (string) $order->get_payment_method_title();
		$transaction_id       = (string) $order->get_transaction_id();
		$is_paid              = $order->is_paid();

		// 7. Dates.
		$date_created = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$date_paid    = $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d' ) : $date_created;

		return [
			'order_id'             => $order_id,
			'order_number'         => (string) $order->get_order_number(),
			'status'               => (string) $order->get_status(),
			'currency'             => $currency,
			'date_created'         => $date_created,
			'date_paid'            => $date_paid,
			'customer_id'          => (int) $order->get_customer_id(),
			'is_paid'              => $is_paid,
			'total'                => (float) $order->get_total(),
			'subtotal'             => (float) $order->get_subtotal(),
			'discount_total'       => (float) $order->get_discount_total(),
			'shipping_total'       => (float) $order->get_shipping_total(),
			'tax_total'            => (float) $order->get_total_tax(),
			'prices_include_tax'   => $order->get_prices_include_tax(),
			'lines'                => $lines,
			'shipping_lines'       => $shipping_lines,
			'fee_lines'            => $fee_lines,
			'billing'              => $billing,
			'shipping'             => $shipping,
			'payment_method'       => $payment_method,
			'payment_method_title' => $payment_method_title,
			'transaction_id'       => $transaction_id,
		];
	}
}
