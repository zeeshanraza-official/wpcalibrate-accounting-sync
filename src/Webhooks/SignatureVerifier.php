<?php
/**
 * Intuit Webhook Signature Verifier.
 *
 * @package WPCalibrate\AccountingSync\Webhooks
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Webhooks;

/**
 * Validates the authenticity of inbound Intuit Webhook payloads via HMAC-SHA256.
 */
final class SignatureVerifier {

	/**
	 * Verifies Intuit webhook signature.
	 *
	 * @param string $raw_payload Raw request body string.
	 * @param string $signature   Value of HTTP intuit-signature header.
	 * @param string $verifier_token Configured Intuit Webhook Verifier Token.
	 * @return bool True if valid, false otherwise.
	 */
	public static function verify( string $raw_payload, string $signature, string $verifier_token ): bool {
		if ( empty( $signature ) || empty( $verifier_token ) ) {
			return false;
		}

		$computed_hash = base64_encode( hash_hmac( 'sha256', $raw_payload, $verifier_token, true ) );

		return hash_equals( $computed_hash, trim( $signature ) );
	}
}
