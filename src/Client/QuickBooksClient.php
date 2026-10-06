<?php
/**
 * Authenticated QuickBooks API Client.
 *
 * @package WPCalibrate\AccountingSync\Client
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Client;

use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Auth\OAuthClient;
use WPCalibrate\AccountingSync\Logging\Logger;
use RuntimeException;

/**
 * Handles all authorized API transport to QuickBooks Online.
 */
final class QuickBooksClient {

	private string $environment;
	private string $realm_id;
	private string $client_id;
	private string $client_secret;

	/**
	 * Constructor.
	 *
	 * @param string|null $environment Environment ('sandbox' or 'production'). Defaults to active environment.
	 */
	public function __construct( ?string $environment = null ) {
		if ( null === $environment ) {
			$general           = get_option( 'wpcalibrate_accounting_sync_general', [] );
			$this->environment = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';
		} else {
			$this->environment = $environment;
		}

		$conn_key = 'wpcalibrate_accounting_sync_connection_' . sanitize_key( $this->environment );
		$conn_opt = get_option( $conn_key, [] );

		$this->client_id     = (string) ( $conn_opt['client_id'] ?? '' );
		$this->client_secret = (string) ( $conn_opt['client_secret'] ?? '' );

		$tokens         = TokenManager::get_tokens( $this->environment );
		$this->realm_id = $tokens['realm_id'] ?? '';
	}

	/**
	 * Gets active environment.
	 *
	 * @return string
	 */
	public function get_environment(): string {
		return $this->environment;
	}

	/**
	 * Gets connected Realm ID.
	 *
	 * @return string
	 */
	public function get_realm_id(): string {
		return $this->realm_id;
	}

	/**
	 * Ensures a valid access token exists, refreshing if needed.
	 *
	 * @return string Valid access token.
	 * @throws RuntimeException If disconnected or refresh fails.
	 */
	private function get_valid_access_token(): string {
		if ( ! TokenManager::is_connected( $this->environment ) ) {
			throw new RuntimeException( 'QuickBooks is not connected. Please connect your account in Settings > Connection.' );
		}

		if ( TokenManager::is_token_expired( $this->environment ) ) {
			return OAuthClient::refresh_token( $this->environment, $this->client_id, $this->client_secret );
		}

		$tokens = TokenManager::get_tokens( $this->environment );
		return $tokens['access_token'] ?? '';
	}

	/**
	 * Executes an authenticated GET request.
	 *
	 * @param string               $endpoint Resource endpoint (e.g. 'companyinfo/123' or 'item/45').
	 * @param array<string, mixed> $params   Query parameters.
	 * @return array<string, mixed> Parsed response.
	 */
	public function get( string $endpoint, array $params = [] ): array {
		$token = $this->get_valid_access_token();
		$url   = EndpointRegistry::get_resource_url( $this->environment, $this->realm_id, $endpoint );

		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$response = wp_remote_get(
			$url,
			[
				'headers'   => [
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				],
				'timeout'   => 30,
				'sslverify' => true,
			]
		);

		return ResponseHandler::parse( $response );
	}

	/**
	 * Executes an authenticated POST request.
	 *
	 * @param string               $endpoint Resource endpoint (e.g. 'salesreceipt', 'invoice', 'customer').
	 * @param array<string, mixed> $payload  JSON body payload.
	 * @return array<string, mixed> Parsed response.
	 */
	public function post( string $endpoint, array $payload ): array {
		$token = $this->get_valid_access_token();
		$url   = EndpointRegistry::get_resource_url( $this->environment, $this->realm_id, $endpoint );

		$response = wp_remote_post(
			$url,
			[
				'headers'   => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				],
				'body'      => wp_json_encode( $payload ),
				'timeout'   => 30,
				'sslverify' => true,
			]
		);

		return ResponseHandler::parse( $response );
	}

	/**
	 * Executes a QuickBooks SQL-like query.
	 *
	 * @param string $query Query string (e.g. 'select * from Customer where Active = true maxresults 100').
	 * @return array<string, mixed> Parsed response.
	 */
	public function query( string $query ): array {
		$token = $this->get_valid_access_token();
		$url   = EndpointRegistry::get_query_url( $this->environment, $this->realm_id, $query );

		$response = wp_remote_get(
			$url,
			[
				'headers'   => [
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				],
				'timeout'   => 30,
				'sslverify' => true,
			]
		);

		return ResponseHandler::parse( $response );
	}

	/**
	 * Tests API connectivity by fetching CompanyInfo.
	 *
	 * @return array<string, mixed> Company info data.
	 */
	public function test_connection(): array {
		$data = $this->get( 'companyinfo/' . $this->realm_id );
		return $data['CompanyInfo'] ?? [];
	}
}
