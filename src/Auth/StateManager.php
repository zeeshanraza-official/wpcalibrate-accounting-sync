<?php
/**
 * OAuth State & CSRF Manager.
 *
 * @package WPCalibrate\AccountingSync\Auth
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Auth;

/**
 * Generates and validates cryptographically secure CSRF state tokens for OAuth 2.0.
 */
final class StateManager {

	private const TRANSIENT_PREFIX = 'wpcalibrate_sync_state_';
	private const EXPIRY_SECONDS   = 600; // 10 minutes.

	/**
	 * Generates a unique state token for the current administrator.
	 *
	 * @param string $environment Target environment ('sandbox' or 'production').
	 * @return string State token.
	 */
	public static function generate_state( string $environment ): string {
		$random = bin2hex( random_bytes( 24 ) );
		$state  = $environment . ':' . $random;

		set_transient( self::TRANSIENT_PREFIX . $random, $environment, self::EXPIRY_SECONDS );

		return $state;
	}

	/**
	 * Validates an incoming state parameter from OAuth callback.
	 *
	 * @param string $state Incoming state string.
	 * @return string|null Verified environment if valid, null otherwise.
	 */
	public static function validate_state( string $state ): ?string {
		if ( ! str_contains( $state, ':' ) ) {
			return null;
		}

		[ $env, $random ] = explode( ':', $state, 2 );

		$transient_key = self::TRANSIENT_PREFIX . $random;
		$stored_env    = get_transient( $transient_key );

		if ( false === $stored_env || ! is_string( $stored_env ) ) {
			return null;
		}

		// Delete immediately to prevent replay attacks.
		delete_transient( $transient_key );

		if ( hash_equals( $stored_env, $env ) ) {
			return $env;
		}

		return null;
	}
}
