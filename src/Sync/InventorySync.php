<?php
/**
 * Inventory Synchronization & Loop Prevention.
 *
 * @package WPCalibrate\AccountingSync\Sync
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Sync;

use WPCalibrate\AccountingSync\Client\QuickBooksClient;
use WPCalibrate\AccountingSync\Database\MappingRepository;
use WPCalibrate\AccountingSync\Logging\Logger;
use WC_Product;
use Exception;
use RuntimeException;

/**
 * Manages inventory quantity synchronization with echo loop prevention.
 */
final class InventorySync {

	private QuickBooksClient $client;
	private string $environment;
	private string $realm_id;

	/**
	 * Constructor.
	 *
	 * @param QuickBooksClient $client API client.
	 */
	public function __construct( QuickBooksClient $client ) {
		$this->client      = $client;
		$this->environment = $client->get_environment();
		$this->realm_id    = $client->get_realm_id();
	}

	/**
	 * Pushes inventory quantity from WooCommerce to QuickBooks Online.
	 *
	 * @param int $product_id WooCommerce product or variation ID.
	 * @return bool True on success, false if skipped or disabled.
	 */
	public function sync_wc_to_qbo( int $product_id ): bool {
		$general = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$dir     = $general['inventory_sync_direction'] ?? 'disabled';

		if ( 'wc_to_qbo' !== $dir ) {
			return false; // Not enabled in this direction.
		}

		// Prevent echo loop: check transient origin marker.
		$loop_marker = get_transient( 'wpcalibrate_sync_inv_origin_' . $product_id );
		if ( 'qbo' === $loop_marker ) {
			delete_transient( 'wpcalibrate_sync_inv_origin_' . $product_id );
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
			return false;
		}

		$stock_qty = (float) $product->get_stock_quantity();

		// Find mapped QBO Item.
		$qbo_item_id = MappingRepository::get_qbo_id(
			$this->environment,
			$this->realm_id,
			'product',
			$product_id
		);

		if ( empty( $qbo_item_id ) ) {
			Logger::info( 'Inventory', (string) $product_id, 'sync_inv_wc_to_qbo', "Skipping inventory sync: Product #{$product_id} has no mapped QuickBooks Item" );
			return false;
		}

		try {
			// Fetch existing item to get SyncToken.
			$res  = $this->client->get( 'item/' . $qbo_item_id );
			$item = $res['Item'] ?? null;
			if ( ! $item || ( $item['Type'] ?? '' ) !== 'Inventory' ) {
				// Only 'Inventory' type items in QuickBooks have trackable QtyOnHand.
				return false;
			}

			$current_qbo_qty = (float) ( $item['QtyOnHand'] ?? 0 );
			if ( abs( $current_qbo_qty - $stock_qty ) < 0.0001 ) {
				return true; // Already identical, no API update needed.
			}

			// Set origin marker to prevent QBO webhook echo.
			set_transient( 'wpcalibrate_sync_inv_origin_' . $product_id, 'wc', 120 );

			$payload = [
				'Id'        => $qbo_item_id,
				'SyncToken' => $item['SyncToken'],
				'QtyOnHand' => $stock_qty,
				'sparse'    => true,
			];

			$this->client->post( 'item', $payload );
			Logger::info( 'Inventory', (string) $product_id, 'sync_inv_wc_to_qbo', "Updated QuickBooks Item #{$qbo_item_id} stock to {$stock_qty}" );
			return true;
		} catch ( Exception $e ) {
			Logger::error( 'Inventory', (string) $product_id, 'sync_inv_wc_to_qbo', 'Failed to update QBO inventory: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Pulls inventory quantity from QuickBooks Online to WooCommerce.
	 *
	 * @param string $qbo_item_id QuickBooks Item ID.
	 * @param float  $new_qty     Quantity on hand from QBO.
	 * @return bool True on success.
	 */
	public function sync_qbo_to_wc( string $qbo_item_id, float $new_qty ): bool {
		$general = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$dir     = $general['inventory_sync_direction'] ?? 'disabled';

		if ( 'qbo_to_wc' !== $dir ) {
			return false;
		}

		$wc_product_id = MappingRepository::get_wc_id(
			$this->environment,
			$this->realm_id,
			'Item',
			$qbo_item_id
		);

		if ( ! $wc_product_id ) {
			return false;
		}

		// Prevent echo loop.
		$loop_marker = get_transient( 'wpcalibrate_sync_inv_origin_' . $wc_product_id );
		if ( 'wc' === $loop_marker ) {
			delete_transient( 'wpcalibrate_sync_inv_origin_' . $wc_product_id );
			return false;
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		// Set origin marker before saving WC stock.
		set_transient( 'wpcalibrate_sync_inv_origin_' . $wc_product_id, 'qbo', 120 );

		$int_qty = max( 0, (int) round( $new_qty ) );
		$product->set_stock_quantity( $int_qty );
		$product->save();

		Logger::info( 'Inventory', (string) $wc_product_id, 'sync_inv_qbo_to_wc', "Updated WooCommerce product #{$wc_product_id} stock to {$int_qty} from QuickBooks" );
		return true;
	}
}
