<?php
/**
 * QuickBooks Response Parser & Error Translator.
 *
 * @package WPCalibrate\AccountingSync\Client
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Client;

use RuntimeException;

/**
 * Handles HTTP response validation and parses Intuit Fault/Error payloads.
 */
final class ResponseHandler {

	/**
	 * Parses and validates raw response from wp_remote_request().
	 *
	 * @param array<string, mixed>|\WP_Error $response Response from WordPress HTTP API.
	 * @return array<string, mixed> Decoded JSON data.
	 * @throws RuntimeException On transport or API errors.
	 */
	public static function parse( mixed $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException(
				esc_html( sprintf( 'Network error communicating with QuickBooks: %s', $response->get_error_message() ) )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			if ( $status_code >= 400 ) {
				throw new RuntimeException(
					esc_html( sprintf( 'QuickBooks API error (HTTP %d): %s', $status_code, wp_strip_all_tags( substr( $body, 0, 300 ) ) ) )
				);
			}
			throw new RuntimeException( 'Invalid response received from QuickBooks API (malformed JSON).' );
		}

		// Check for Intuit Fault object.
		if ( isset( $data['Fault'] ) && is_array( $data['Fault'] ) ) {
			$error_message = self::extract_fault_message( $data['Fault'] );
			throw new RuntimeException( esc_html( sprintf( 'QuickBooks API Fault (HTTP %d): %s', $status_code, $error_message ) ) );
		}

		if ( $status_code >= 400 ) {
			throw new RuntimeException(
				esc_html( sprintf( 'QuickBooks API request failed with HTTP status %d.', $status_code ) )
			);
		}

		return $data;
	}

	/**
	 * Formats Intuit Fault structure into a clean, actionable error string.
	 *
	 * @param array<string, mixed> $fault Fault dictionary.
	 * @return string Actionable error message.
	 */
	private static function extract_fault_message( array $fault ): string {
		$messages = [];
		if ( ! empty( $fault['Error'] ) && is_array( $fault['Error'] ) ) {
			foreach ( $fault['Error'] as $err ) {
				$msg    = $err['Message'] ?? 'Unknown error';
				$detail = $err['Detail'] ?? '';
				$code   = $err['code'] ?? '';

				$formatted = $msg;
				if ( ! empty( $detail ) && $detail !== $msg ) {
					$formatted .= ' — ' . $detail;
				}
				if ( ! empty( $code ) ) {
					$formatted .= ' (Code: ' . $code . ')';
				}
				$messages[] = $formatted;
			}
		}

		return ! empty( $messages ) ? implode( '; ', $messages ) : ( $fault['type'] ?? 'Unknown Fault' );
	}
}
