<?php
/**
 * Sensitive Data Redactor.
 *
 * @package WPCalibrate\AccountingSync\Logging
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Logging;

/**
 * Strips secrets, tokens, credentials, and payment card numbers from log strings and arrays.
 */
final class Redactor {

	/**
	 * Keys that must always be masked.
	 *
	 * @var array<string>
	 */
	private const SENSITIVE_KEYS = [
		'access_token',
		'refresh_token',
		'client_secret',
		'client_id',
		'authorization',
		'intuit-signature',
		'verifier_token',
		'webhook_token',
		'license_key',
		'password',
		'card_number',
		'account_number',
		'cvv',
		'cvc',
		'secret',
		'token',
	];

	/**
	 * Redacts sensitive fields from an associative array or scalar.
	 *
	 * @param mixed $data Input data.
	 * @return mixed Redacted data.
	 */
	public static function redact( mixed $data ): mixed {
		if ( is_array( $data ) ) {
			$clean = [];
			foreach ( $data as $key => $val ) {
				$key_lower = strtolower( (string) $key );
				$is_secret = false;
				foreach ( self::SENSITIVE_KEYS as $secret_key ) {
					if ( str_contains( $key_lower, $secret_key ) ) {
						$is_secret = true;
						break;
					}
				}

				if ( $is_secret ) {
					$clean[ $key ] = '***REDACTED***';
				} else {
					$clean[ $key ] = self::redact( $val );
				}
			}
			return $clean;
		}

		if ( is_string( $data ) ) {
			// Redact Bearer tokens.
			$data = preg_replace( '/Bearer\s+[A-Za-z0-9_\-\.\~]+/i', 'Bearer ***REDACTED***', $data );

			// Redact potential 13-19 digit payment card numbers.
			$data = preg_replace( '/\b(?:\d[ -]*?){13,19}\b/', '***CARD-REDACTED***', $data ?? '' );

			return $data;
		}

		return $data;
	}

	/**
	 * Masks a secret string for display in UI (e.g. showing only last 4 characters).
	 *
	 * @param string $secret Secret string.
	 * @param int    $visible_chars Trailing characters to leave visible.
	 * @return string Masked string.
	 */
	public static function mask_secret( string $secret, int $visible_chars = 4 ): string {
		$len = strlen( $secret );
		if ( $len === 0 ) {
			return '';
		}

		if ( $len <= $visible_chars ) {
			return str_repeat( '•', $len );
		}

		return str_repeat( '•', 8 ) . substr( $secret, -$visible_chars );
	}
}
