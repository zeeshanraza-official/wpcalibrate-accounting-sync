<?php
/**
 * QuickBooks Online Endpoints and Configuration Registry.
 *
 * @package WPCalibrate\AccountingSync\Client
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Client;

/**
 * Manages Intuit OAuth and API URLs based on active environment.
 */
final class EndpointRegistry {

	public const ENV_SANDBOX    = 'sandbox';
	public const ENV_PRODUCTION = 'production';

	public const OAUTH_AUTH_URL   = 'https://appcenter.intuit.com/connect/oauth2';
	public const OAUTH_TOKEN_URL  = 'https://oauth.platform.intuit.com/oauth/v1/tokens/bearer';
	public const OAUTH_REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';
	public const OAUTH_USERINFO   = 'https://accounts.platform.intuit.com/v1/openid/connect/userinfo';

	public const API_SANDBOX_BASE    = 'https://sandbox-quickbooks.api.intuit.com';
	public const API_PRODUCTION_BASE = 'https://quickbooks.api.intuit.com';

	public const DEFAULT_SCOPE         = 'com.intuit.quickbooks.accounting';
	public const DEFAULT_MINOR_VERSION = '75';

	/**
	 * Returns base API URL for environment.
	 *
	 * @param string $environment Environment ('sandbox' or 'production').
	 * @return string Base API URL.
	 */
	public static function get_api_base( string $environment ): string {
		return self::ENV_PRODUCTION === $environment ? self::API_PRODUCTION_BASE : self::API_SANDBOX_BASE;
	}

	/**
	 * Returns full endpoint URL for a QuickBooks resource.
	 *
	 * @param string $environment Environment.
	 * @param string $realm_id    QuickBooks Company/Realm ID.
	 * @param string $endpoint    Resource endpoint (e.g. 'companyinfo', 'salesreceipt', 'invoice').
	 * @return string Full URL.
	 */
	public static function get_resource_url( string $environment, string $realm_id, string $endpoint ): string {
		$base = self::get_api_base( $environment );
		$endpoint = ltrim( $endpoint, '/' );
		return sprintf(
			'%s/v3/company/%s/%s?minorversion=%s',
			$base,
			rawurlencode( $realm_id ),
			$endpoint,
			self::DEFAULT_MINOR_VERSION
		);
	}

	/**
	 * Returns query endpoint URL.
	 *
	 * @param string $environment Environment.
	 * @param string $realm_id    Realm ID.
	 * @param string $query       SQL query string.
	 * @return string Query URL.
	 */
	public static function get_query_url( string $environment, string $realm_id, string $query ): string {
		$base = self::get_api_base( $environment );
		return sprintf(
			'%s/v3/company/%s/query?query=%s&minorversion=%s',
			$base,
			rawurlencode( $realm_id ),
			rawurlencode( $query ),
			self::DEFAULT_MINOR_VERSION
		);
	}
}
