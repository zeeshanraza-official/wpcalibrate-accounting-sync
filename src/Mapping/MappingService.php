<?php
/**
 * QuickBooks Reference Data & Mapping Service.
 *
 * @package WPCalibrate\AccountingSync\Mapping
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Mapping;

use WPCalibrate\AccountingSync\Client\QuickBooksClient;
use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Logging\Logger;
use Exception;

/**
 * Retrieves and caches QuickBooks reference entities (Accounts, TaxCodes, PaymentMethods, Items).
 */
final class MappingService {

	private const CACHE_TTL = 3600; // 1 hour.

	/**
	 * Returns cached or fresh list of QuickBooks Accounts.
	 *
	 * @param string $environment Environment.
	 * @param bool   $force_refresh Bypass cache.
	 * @return array<int, array{id: string, name: string, type: string, classification: string}>
	 */
	public static function get_accounts( string $environment, bool $force_refresh = false ): array {
		if ( ! TokenManager::is_connected( $environment ) ) {
			return [];
		}

		$tokens    = TokenManager::get_tokens( $environment );
		$realm_id  = $tokens['realm_id'] ?? '';
		$cache_key = 'wpcalibrate_sync_qbo_accounts_' . $environment . '_' . $realm_id;

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		try {
			$client = new QuickBooksClient( $environment );
			$res    = $client->query( 'select Id, Name, AccountType, Classification from Account where Active = true maxresults 200' );
			$items  = $res['QueryResponse']['Account'] ?? [];

			$accounts = [];
			foreach ( $items as $acc ) {
				$accounts[] = [
					'id'             => (string) ( $acc['Id'] ?? '' ),
					'name'           => (string) ( $acc['Name'] ?? '' ),
					'type'           => (string) ( $acc['AccountType'] ?? '' ),
					'classification' => (string) ( $acc['Classification'] ?? '' ),
				];
			}

			set_transient( $cache_key, $accounts, self::CACHE_TTL );
			return $accounts;
		} catch ( Exception $e ) {
			Logger::error( 'Mapping', $realm_id, 'get_accounts', 'Failed to retrieve QuickBooks accounts: ' . $e->getMessage() );
			return [];
		}
	}

	/**
	 * Returns cached or fresh list of QuickBooks Tax Codes.
	 *
	 * @param string $environment Environment.
	 * @param bool   $force_refresh Bypass cache.
	 * @return array<int, array{id: string, name: string, description: string, taxable: bool}>
	 */
	public static function get_tax_codes( string $environment, bool $force_refresh = false ): array {
		if ( ! TokenManager::is_connected( $environment ) ) {
			return [];
		}

		$tokens    = TokenManager::get_tokens( $environment );
		$realm_id  = $tokens['realm_id'] ?? '';
		$cache_key = 'wpcalibrate_sync_qbo_tax_codes_' . $environment . '_' . $realm_id;

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		try {
			$client = new QuickBooksClient( $environment );
			$res    = $client->query( 'select Id, Name, Description, Taxable from TaxCode where Active = true maxresults 200' );
			$items  = $res['QueryResponse']['TaxCode'] ?? [];

			$codes = [];
			foreach ( $items as $tc ) {
				$codes[] = [
					'id'          => (string) ( $tc['Id'] ?? '' ),
					'name'        => (string) ( $tc['Name'] ?? '' ),
					'description' => (string) ( $tc['Description'] ?? '' ),
					'taxable'     => ! empty( $tc['Taxable'] ),
				];
			}

			set_transient( $cache_key, $codes, self::CACHE_TTL );
			return $codes;
		} catch ( Exception $e ) {
			Logger::error( 'Mapping', $realm_id, 'get_tax_codes', 'Failed to retrieve QuickBooks tax codes: ' . $e->getMessage() );
			return [];
		}
	}

	/**
	 * Returns cached or fresh list of QuickBooks Payment Methods.
	 *
	 * @param string $environment Environment.
	 * @param bool   $force_refresh Bypass cache.
	 * @return array<int, array{id: string, name: string, type: string}>
	 */
	public static function get_payment_methods( string $environment, bool $force_refresh = false ): array {
		if ( ! TokenManager::is_connected( $environment ) ) {
			return [];
		}

		$tokens    = TokenManager::get_tokens( $environment );
		$realm_id  = $tokens['realm_id'] ?? '';
		$cache_key = 'wpcalibrate_sync_qbo_payment_methods_' . $environment . '_' . $realm_id;

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		try {
			$client = new QuickBooksClient( $environment );
			$res    = $client->query( 'select Id, Name, Type from PaymentMethod where Active = true maxresults 100' );
			$items  = $res['QueryResponse']['PaymentMethod'] ?? [];

			$methods = [];
			foreach ( $items as $pm ) {
				$methods[] = [
					'id'   => (string) ( $pm['Id'] ?? '' ),
					'name' => (string) ( $pm['Name'] ?? '' ),
					'type' => (string) ( $pm['Type'] ?? '' ),
				];
			}

			set_transient( $cache_key, $methods, self::CACHE_TTL );
			return $methods;
		} catch ( Exception $e ) {
			Logger::error( 'Mapping', $realm_id, 'get_payment_methods', 'Failed to retrieve QuickBooks payment methods: ' . $e->getMessage() );
			return [];
		}
	}

	/**
	 * Returns cached or fresh list of QuickBooks Items.
	 *
	 * @param string $environment Environment.
	 * @param bool   $force_refresh Bypass cache.
	 * @return array<int, array{id: string, name: string, type: string, sku: string}>
	 */
	public static function get_items( string $environment, bool $force_refresh = false ): array {
		if ( ! TokenManager::is_connected( $environment ) ) {
			return [];
		}

		$tokens    = TokenManager::get_tokens( $environment );
		$realm_id  = $tokens['realm_id'] ?? '';
		$cache_key = 'wpcalibrate_sync_qbo_items_' . $environment . '_' . $realm_id;

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		try {
			$client = new QuickBooksClient( $environment );
			$res    = $client->query( 'select Id, Name, Type, Sku from Item where Active = true maxresults 200' );
			$items  = $res['QueryResponse']['Item'] ?? [];

			$result = [];
			foreach ( $items as $it ) {
				$result[] = [
					'id'   => (string) ( $it['Id'] ?? '' ),
					'name' => (string) ( $it['Name'] ?? '' ),
					'type' => (string) ( $it['Type'] ?? '' ),
					'sku'  => (string) ( $it['Sku'] ?? '' ),
				];
			}

			set_transient( $cache_key, $result, self::CACHE_TTL );
			return $result;
		} catch ( Exception $e ) {
			Logger::error( 'Mapping', $realm_id, 'get_items', 'Failed to retrieve QuickBooks items: ' . $e->getMessage() );
			return [];
		}
	}

	/**
	 * Flushes all cached reference data for an environment.
	 *
	 * @param string $environment Environment.
	 * @return void
	 */
	public static function flush_cache( string $environment ): void {
		$tokens   = TokenManager::get_tokens( $environment );
		$realm_id = $tokens['realm_id'] ?? '';

		delete_transient( 'wpcalibrate_sync_qbo_accounts_' . $environment . '_' . $realm_id );
		delete_transient( 'wpcalibrate_sync_qbo_tax_codes_' . $environment . '_' . $realm_id );
		delete_transient( 'wpcalibrate_sync_qbo_payment_methods_' . $environment . '_' . $realm_id );
		delete_transient( 'wpcalibrate_sync_qbo_items_' . $environment . '_' . $realm_id );
	}
}
