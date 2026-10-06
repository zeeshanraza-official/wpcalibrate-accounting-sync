<?php
/**
 * Token Storage & Encryption Manager.
 *
 * @package WPCalibrate\AccountingSync\Auth
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Auth;

/**
 * Handles encrypted token persistence and lifecycle checks.
 */
final class TokenManager {

	private const CIPHER = 'aes-256-ctr';

	/**
	 * Returns the option key for an environment.
	 *
	 * @param string $environment Environment ('sandbox' or 'production').
	 * @return string Option name.
	 */
	public static function get_option_name( string $environment ): string {
		return 'wpcalibrate_accounting_sync_tokens_' . sanitize_key( $environment );
	}

	/**
	 * Derives encryption key from WordPress salts.
	 *
	 * @return string 32-byte binary key.
	 */
	private static function get_encryption_key(): string {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'wpcalibrate_default_auth_salt_fallback_key';
		$key  = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'wpcalibrate_default_secure_auth_key';
		return hash( 'sha256', $salt . $key, true );
	}

	/**
	 * Encrypts a string.
	 *
	 * @param string $plaintext Plaintext string.
	 * @return string Base64 encoded IV + ciphertext.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key       = self::get_encryption_key();
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv        = openssl_random_pseudo_bytes( $iv_length );

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return '';
		}

		return base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypts a string.
	 *
	 * @param string $encrypted_payload Base64 encoded payload.
	 * @return string Plaintext.
	 */
	public static function decrypt( string $encrypted_payload ): string {
		if ( '' === $encrypted_payload ) {
			return '';
		}

		$raw = base64_decode( $encrypted_payload, true );
		if ( false === $raw ) {
			return '';
		}

		$key       = self::get_encryption_key();
		$iv_length = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $raw ) <= $iv_length ) {
			return '';
		}

		$iv         = substr( $raw, 0, $iv_length );
		$ciphertext = substr( $raw, $iv_length );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		return false !== $plaintext ? $plaintext : '';
	}

	/**
	 * Retrieves decrypted tokens for an environment.
	 *
	 * @param string $environment Environment ('sandbox' or 'production').
	 * @return array<string, mixed>|null Tokens array or null if not connected.
	 */
	public static function get_tokens( string $environment ): ?array {
		$data = get_option( self::get_option_name( $environment ), null );
		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			return null;
		}

		return [
			'access_token'              => self::decrypt( (string) $data['access_token'] ),
			'refresh_token'             => self::decrypt( (string) $data['refresh_token'] ),
			'expires_at'                => (int) ( $data['expires_at'] ?? 0 ),
			'refresh_token_expires_at'  => (int) ( $data['refresh_token_expires_at'] ?? 0 ),
			'realm_id'                  => (string) ( $data['realm_id'] ?? '' ),
			'connected_at'              => (string) ( $data['connected_at'] ?? '' ),
		];
	}

	/**
	 * Saves tokens for an environment.
	 *
	 * @param string               $environment Environment.
	 * @param array<string, mixed> $tokens     Token bundle.
	 * @return bool
	 */
	public static function save_tokens( string $environment, array $tokens ): bool {
		$encrypted_data = [
			'access_token'              => self::encrypt( (string) ( $tokens['access_token'] ?? '' ) ),
			'refresh_token'             => self::encrypt( (string) ( $tokens['refresh_token'] ?? '' ) ),
			'expires_at'                => (int) ( $tokens['expires_at'] ?? 0 ),
			'refresh_token_expires_at'  => (int) ( $tokens['refresh_token_expires_at'] ?? 0 ),
			'realm_id'                  => (string) ( $tokens['realm_id'] ?? '' ),
			'connected_at'              => (string) ( $tokens['connected_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		];

		// Save with autoload = false to prevent bloating every request.
		return update_option( self::get_option_name( $environment ), $encrypted_data, false );
	}

	/**
	 * Deletes tokens for an environment (disconnects).
	 *
	 * @param string $environment Environment.
	 * @return bool
	 */
	public static function delete_tokens( string $environment ): bool {
		return delete_option( self::get_option_name( $environment ) );
	}

	/**
	 * Checks if currently connected to QuickBooks Online.
	 *
	 * @param string $environment Environment.
	 * @return bool
	 */
	public static function is_connected( string $environment ): bool {
		$tokens = self::get_tokens( $environment );
		return ! empty( $tokens['access_token'] ) && ! empty( $tokens['realm_id'] );
	}

	/**
	 * Checks if the current access token is expired or expiring within 120 seconds.
	 *
	 * @param string $environment Environment.
	 * @return bool
	 */
	public static function is_token_expired( string $environment ): bool {
		$tokens = self::get_tokens( $environment );
		if ( null === $tokens ) {
			return true;
		}

		// Consider expired if current time + 120s exceeds expires_at.
		return time() + 120 >= $tokens['expires_at'];
	}

	/**
	 * Acquires a refresh lock to prevent concurrent refresh requests.
	 *
	 * @param string $environment Environment.
	 * @return bool True if acquired, false if another process holds the lock.
	 */
	public static function acquire_refresh_lock( string $environment ): bool {
		$lock_key = 'wpcalibrate_sync_lock_refresh_' . sanitize_key( $environment );
		if ( get_transient( $lock_key ) ) {
			return false;
		}
		set_transient( $lock_key, 1, 30 ); // 30-second lock.
		return true;
	}

	/**
	 * Releases the refresh lock.
	 *
	 * @param string $environment Environment.
	 * @return void
	 */
	public static function release_refresh_lock( string $environment ): void {
		$lock_key = 'wpcalibrate_sync_lock_refresh_' . sanitize_key( $environment );
		delete_transient( $lock_key );
	}
}
