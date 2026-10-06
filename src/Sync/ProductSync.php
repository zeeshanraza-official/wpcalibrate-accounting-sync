<?php
/**
 * Product & Item Synchronization Service.
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
 * Handles mapping, resolution, and optional creation of WooCommerce products/variations as QuickBooks Items.
 */
final class ProductSync {

	private QuickBooksClient $client;
	private string $environment;
	private string $realm_id;

	/**
	 * Constructor.
	 *
	 * @param QuickBooksClient $client Authenticated API client.
	 */
	public function __construct( QuickBooksClient $client ) {
		$this->client      = $client;
		$this->environment = $client->get_environment();
		$this->realm_id    = $client->get_realm_id();
	}

	/**
	 * Resolves a QuickBooks Item ID for an order line item.
	 *
	 * @param int         $product_id   WooCommerce product or variation ID (0 if deleted).
	 * @param string      $item_name    Line item name as captured on the order.
	 * @param string      $sku          SKU if present.
	 * @param float       $price        Unit price.
	 * @return string QuickBooks Item ID.
	 * @throws RuntimeException If item cannot be resolved and no fallback exists.
	 */
	public function resolve_item_id( int $product_id, string $item_name, string $sku = '', float $price = 0.0 ): string {
		$mapping_rules    = get_option( 'wpcalibrate_accounting_sync_mapping_rules', [] );
		$fallback_item_id = (string) ( $mapping_rules['fallback_item_id'] ?? '' );

		// 1. Check existing mapping table if product ID is valid.
		if ( $product_id > 0 ) {
			$mapped_id = MappingRepository::get_qbo_id(
				$this->environment,
				$this->realm_id,
				'product',
				$product_id
			);
			if ( ! empty( $mapped_id ) ) {
				return $mapped_id;
			}
		}

		// 2. Query QuickBooks by SKU or Name.
		if ( ! empty( $sku ) ) {
			$qbo_id = $this->find_qbo_item_by_sku( $sku );
			if ( ! empty( $qbo_id ) ) {
				if ( $product_id > 0 ) {
					MappingRepository::set_mapping( $this->environment, $this->realm_id, 'product', $product_id, 'Item', $qbo_id );
				}
				return $qbo_id;
			}
		}

		if ( ! empty( $item_name ) ) {
			$qbo_id = $this->find_qbo_item_by_name( $item_name );
			if ( ! empty( $qbo_id ) ) {
				if ( $product_id > 0 ) {
					MappingRepository::set_mapping( $this->environment, $this->realm_id, 'product', $product_id, 'Item', $qbo_id );
				}
				return $qbo_id;
			}
		}

		// 3. Auto-create item in QBO if auto creation is enabled.
		$general = get_option( 'wpcalibrate_accounting_sync_general', [] );
		if ( ! empty( $general['auto_create_products'] ) ) {
			$income_account_id = (string) ( $mapping_rules['default_income_account_id'] ?? '' );
			if ( ! empty( $income_account_id ) ) {
				$new_item_id = $this->create_qbo_item( $item_name, $sku, $price, $income_account_id );
				if ( $product_id > 0 ) {
					MappingRepository::set_mapping( $this->environment, $this->realm_id, 'product', $product_id, 'Item', $new_item_id );
				}
				return $new_item_id;
			}
		}

		// 4. Use fallback item if configured.
		if ( ! empty( $fallback_item_id ) ) {
			return $fallback_item_id;
		}

		throw new RuntimeException(
			esc_html( sprintf( 'No QuickBooks Item mapped for product "%s" (SKU: %s, ID: %d) and no default fallback item configured.', $item_name, $sku, $product_id ) )
		);
	}

	/**
	 * Finds Item in QuickBooks by exact SKU.
	 *
	 * @param string $sku SKU.
	 * @return string|null Item ID if found.
	 */
	public function find_qbo_item_by_sku( string $sku ): ?string {
		try {
			$safe_sku = addslashes( $sku );
			$res = $this->client->query( "select Id, Name, Sku from Item where Sku = '{$safe_sku}' and Active = true maxresults 1" );
			$items = $res['QueryResponse']['Item'] ?? [];
			if ( ! empty( $items[0]['Id'] ) ) {
				return (string) $items[0]['Id'];
			}
		} catch ( Exception $e ) {
			Logger::warning( 'Product', $sku, 'find_by_sku', 'Error finding QBO item by SKU: ' . $e->getMessage() );
		}
		return null;
	}

	/**
	 * Finds Item in QuickBooks by exact Name.
	 *
	 * @param string $name Item name.
	 * @return string|null Item ID if found.
	 */
	public function find_qbo_item_by_name( string $name ): ?string {
		try {
			$safe_name = addslashes( substr( $name, 0, 100 ) );
			$res = $this->client->query( "select Id, Name from Item where Name = '{$safe_name}' and Active = true maxresults 1" );
			$items = $res['QueryResponse']['Item'] ?? [];
			if ( ! empty( $items[0]['Id'] ) ) {
				return (string) $items[0]['Id'];
			}
		} catch ( Exception $e ) {
			Logger::warning( 'Product', $name, 'find_by_name', 'Error finding QBO item by name: ' . $e->getMessage() );
		}
		return null;
	}

	/**
	 * Creates a Non-Inventory / Service Item in QuickBooks Online.
	 *
	 * @param string $name              Product name.
	 * @param string $sku               SKU.
	 * @param float  $price             Sales price.
	 * @param string $income_account_id Income Account Reference ID.
	 * @return string Created Item ID.
	 * @throws RuntimeException On creation failure.
	 */
	public function create_qbo_item( string $name, string $sku, float $price, string $income_account_id ): string {
		$clean_name = substr( trim( $name ), 0, 100 );
		if ( empty( $clean_name ) ) {
			$clean_name = 'WooCommerce Item ' . wp_generate_password( 4, false );
		}

		$payload = [
			'Name'             => $clean_name,
			'Type'             => 'Service', // Or NonInventory depending on configuration.
			'UnitPrice'        => round( $price, 2 ),
			'IncomeAccountRef' => [
				'value' => $income_account_id,
			],
		];

		if ( ! empty( $sku ) ) {
			$payload['Sku'] = substr( $sku, 0, 100 );
		}

		try {
			$res = $this->client->post( 'item', $payload );
			$item_id = (string) ( $res['Item']['Id'] ?? '' );
			if ( empty( $item_id ) ) {
				throw new RuntimeException( 'Failed to obtain Item ID from QuickBooks creation response.' );
			}

			Logger::info( 'Product', $item_id, 'create_item', "Created QuickBooks Item for \"{$clean_name}\"" );
			return $item_id;
		} catch ( Exception $e ) {
			// If name collision occurs, try with random suffix.
			if ( str_contains( strtolower( $e->getMessage() ), 'duplicate' ) ) {
				$payload['Name'] = substr( $clean_name . ' (' . wp_generate_password( 4, false ) . ')', 0, 100 );
				$res = $this->client->post( 'item', $payload );
				return (string) ( $res['Item']['Id'] ?? '' );
			}
			throw new RuntimeException( esc_html( 'Failed to create QuickBooks item: ' . $e->getMessage() ) );
		}
	}
}
