<?php
/**
 * QuickBooks Online OAuth 2.0 Client.
 *
 * @package WPCalibrate\AccountingSync\Auth
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Auth;

use WPCalibrate\AccountingSync\Client\EndpointRegistry;
use WPCalibrate\AccountingSync\Logging\Logger;
use RuntimeException;

/**
 * Orchestrates OAuth 2.0 authorization, code exchange, refresh, and disconnect.
 */
final class OAuthClient {

	/**
	 * Returns the redirect URI configured for this site.
	 *
	 * @return string
	 */
	public static function get_redirect_uri(): string {
		return admin_url( 'admin.php?page=wpcalibrate-accounting-sync&tab=connection' );
	}

	/**
	 * Generates the Intuit OAuth 2.0 Authorization URL.
	 *
	 * @param string $environment Target environment ('sandbox' or 'production').
	 * @param string $client_id   Client ID.
	 * @return string Full authorization URL.
	 */
	public static function get_authorization_url( string $environment, string $client_id ): string {
		$state = StateManager::generate_state( $environment );
		$params = [
			'client_id'     => $client_id,
			'response_type' => 'code',
			'scope'         => EndpointRegistry::DEFAULT_SCOPE,
			'redirect_uri'  => self::get_redirect_uri(),
			'state'         => $state,
		];

		return EndpointRegistry::OAUTH_AUTH_URL . '?' . http_build_query( $params );
	}

	/**
	 * Exchanges authorization code for access and refresh tokens.
	 *
	 * @param string $environment   Target environment.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client Secret.
	 * @param string $code          Authorization code from Intuit callback.
	 * @param string $realm_id      Connected Realm/Company ID.
	 * @return bool True on success.
	 * @throws RuntimeException On exchange failure.
	 */
	public static function exchange_code_for_tokens(
		string $environment,
		string $client_id,
		string $client_secret,
		string $code,
		string $realm_id
	): bool {
		$body = [
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => self::get_redirect_uri(),
		];

		$response = wp_remote_post(
			EndpointRegistry::OAUTH_TOKEN_URL,
			[
				'headers'   => [
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				],
				'body'      => http_build_query( $body ),
				'timeout'   => 30,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'OAuth', $realm_id, 'token_exchange', 'HTTP error during token exchange', $response->get_error_message() );
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code !== 200 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error_desc = is_array( $data ) ? ( $data['error_description'] ?? ( $data['error'] ?? 'Unknown token exchange error' ) ) : 'Invalid response';
			Logger::error( 'OAuth', $realm_id, 'token_exchange', 'Failed token exchange', $error_desc );
			throw new RuntimeException( esc_html( 'Failed to obtain tokens from QuickBooks: ' . $error_desc ) );
		}

		$now        = time();
		$expires_in = (int) ( $data['expires_in'] ?? 3600 );
		$refresh_in = (int) ( $data['x_refresh_token_expires_in'] ?? ( 100 * DAY_IN_SECONDS ) );

		$tokens = [
			'access_token'             => (string) $data['access_token'],
			'refresh_token'            => (string) $data['refresh_token'],
			'expires_at'               => $now + $expires_in - 120, // 2-min safety margin.
			'refresh_token_expires_at' => $now + $refresh_in,
			'realm_id'                 => $realm_id,
			'connected_at'             => gmdate( 'Y-m-d H:i:s' ),
		];

		TokenManager::save_tokens( $environment, $tokens );
		Logger::info( 'OAuth', $realm_id, 'connect', 'Successfully connected QuickBooks company' );

		return true;
	}

	/**
	 * Refreshes an expired access token using the stored refresh token.
	 *
	 * @param string $environment Target environment.
	 * @param string $client_id   Client ID.
	 * @param string $client_secret Client Secret.
	 * @return string New access token.
	 * @throws RuntimeException On refresh failure.
	 */
	public static function refresh_token( string $environment, string $client_id, string $client_secret ): string {
		$tokens = TokenManager::get_tokens( $environment );
		if ( empty( $tokens['refresh_token'] ) ) {
			throw new RuntimeException( 'Cannot refresh token: No refresh token exists. Please reconnect your QuickBooks account.' );
		}

		// Prevent race condition across concurrent processes.
		if ( ! TokenManager::acquire_refresh_lock( $environment ) ) {
			// Another process is already refreshing. Wait briefly and return current token.
			sleep( 2 );
			$updated = TokenManager::get_tokens( $environment );
			if ( ! empty( $updated['access_token'] ) ) {
				return $updated['access_token'];
			}
		}

		try {
			$body = [
				'grant_type'    => 'refresh_token',
				'refresh_token' => $tokens['refresh_token'],
			];

			$response = wp_remote_post(
				EndpointRegistry::OAUTH_TOKEN_URL,
				[
					'headers'   => [
						'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
						'Content-Type'  => 'application/x-www-form-urlencoded',
						'Accept'        => 'application/json',
					],
					'body'      => http_build_query( $body ),
					'timeout'   => 30,
					'sslverify' => true,
				]
			);

			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( esc_html( $response->get_error_message() ) );
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$data        = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $status_code !== 200 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
				$error_desc = is_array( $data ) ? ( $data['error_description'] ?? ( $data['error'] ?? 'Refresh failed' ) ) : 'Invalid refresh response';
				Logger::error( 'OAuth', $tokens['realm_id'], 'token_refresh', 'Token refresh failed: ' . $error_desc );
				throw new RuntimeException( esc_html( 'QuickBooks token refresh failed: ' . $error_desc . '. Please reauthorize the connection.' ) );
			}

			$now        = time();
			$expires_in = (int) ( $data['expires_in'] ?? 3600 );
			$refresh_in = (int) ( $data['x_refresh_token_expires_in'] ?? ( 100 * DAY_IN_SECONDS ) );

			$new_tokens = [
				'access_token'             => (string) $data['access_token'],
				'refresh_token'            => (string) $data['refresh_token'],
				'expires_at'               => $now + $expires_in - 120,
				'refresh_token_expires_at' => $now + $refresh_in,
				'realm_id'                 => $tokens['realm_id'],
				'connected_at'             => $tokens['connected_at'],
			];

			TokenManager::save_tokens( $environment, $new_tokens );
			Logger::info( 'OAuth', $tokens['realm_id'], 'token_refresh', 'QuickBooks access token successfully refreshed' );

			return (string) $data['access_token'];
		} finally {
			TokenManager::release_refresh_lock( $environment );
		}
	}

	/**
	 * Disconnects QuickBooks by revoking token and deleting stored credentials.
	 *
	 * @param string $environment   Target environment.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client Secret.
	 * @return void
	 */
	public static function disconnect( string $environment, string $client_id, string $client_secret ): void {
		$tokens = TokenManager::get_tokens( $environment );
		if ( ! empty( $tokens['refresh_token'] ) ) {
			// Best effort revocation.
			wp_remote_post(
				EndpointRegistry::OAUTH_REVOKE_URL,
				[
					'headers' => [
						'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
						'Content-Type'  => 'application/json',
					],
					'body'    => wp_json_encode( [ 'token' => $tokens['refresh_token'] ] ),
					'timeout' => 15,
				]
			);
		}

		TokenManager::delete_tokens( $environment );
		Logger::info( 'OAuth', $tokens['realm_id'] ?? '', 'disconnect', 'QuickBooks company disconnected' );
	}
}
