<?php
/**
 * Structured Logger.
 *
 * @package WPCalibrate\AccountingSync\Logging
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Logging;

use WPCalibrate\AccountingSync\Database\LogRepository;

/**
 * Structured logging service with automated data redaction.
 */
final class Logger {

	/**
	 * Log severity levels.
	 */
	public const LEVEL_DEBUG   = 'debug';
	public const LEVEL_INFO    = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	/**
	 * Active correlation ID.
	 *
	 * @var string
	 */
	private static string $correlation_id = '';

	/**
	 * Gets or creates an active correlation ID for request tracing.
	 *
	 * @return string
	 */
	public static function get_correlation_id(): string {
		if ( empty( self::$correlation_id ) ) {
			self::$correlation_id = wp_generate_uuid4();
		}
		return self::$correlation_id;
	}

	/**
	 * Sets a specific correlation ID (e.g. for background jobs).
	 *
	 * @param string $id Correlation ID.
	 * @return void
	 */
	public static function set_correlation_id( string $id ): void {
		self::$correlation_id = $id;
	}

	/**
	 * Logs a message.
	 *
	 * @param string      $severity    Severity level.
	 * @param string      $entity_type Entity type.
	 * @param string      $entity_id   Entity ID.
	 * @param string      $operation   Operation name.
	 * @param string      $message     Descriptive message.
	 * @param mixed       $context     Arbitrary context data (will be redacted).
	 * @param string|null $environment Optional environment ('sandbox' or 'production'). Defaults to active environment.
	 * @return void
	 */
	public static function log(
		string $severity,
		string $entity_type,
		string $entity_id,
		string $operation,
		string $message,
		mixed $context = null,
		?string $environment = null
	): void {
		if ( null === $environment ) {
			$settings    = get_option( 'wpcalibrate_accounting_sync_general', [] );
			$environment = ! empty( $settings['environment'] ) ? (string) $settings['environment'] : 'sandbox';
		}

		$correlation_id = self::get_correlation_id();

		// Redact context before serialization.
		$sanitized_context = null;
		if ( null !== $context ) {
			$redacted_data     = Redactor::redact( $context );
			$sanitized_context = wp_json_encode( $redacted_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		}

		// Insert into custom table.
		LogRepository::insert(
			$environment,
			$correlation_id,
			$severity,
			$entity_type,
			$entity_id,
			$operation,
			$message,
			$sanitized_context
		);

		// Mirror to WooCommerce logger if available and level is warning or error.
		if ( function_exists( 'wc_get_logger' ) && in_array( $severity, [ self::LEVEL_WARNING, self::LEVEL_ERROR ], true ) ) {
			$wc_logger = wc_get_logger();
			$wc_context = [ 'source' => 'wpcalibrate-accounting-sync' ];
			if ( self::LEVEL_ERROR === $severity ) {
				$wc_logger->error( "[$correlation_id] $operation - $message", $wc_context );
			} else {
				$wc_logger->warning( "[$correlation_id] $operation - $message", $wc_context );
			}
		}
	}

	/**
	 * Log info.
	 */
	public static function info( string $entity_type, string $entity_id, string $operation, string $message, mixed $context = null ): void {
		self::log( self::LEVEL_INFO, $entity_type, $entity_id, $operation, $message, $context );
	}

	/**
	 * Log debug.
	 */
	public static function debug( string $entity_type, string $entity_id, string $operation, string $message, mixed $context = null ): void {
		self::log( self::LEVEL_DEBUG, $entity_type, $entity_id, $operation, $message, $context );
	}

	/**
	 * Log warning.
	 */
	public static function warning( string $entity_type, string $entity_id, string $operation, string $message, mixed $context = null ): void {
		self::log( self::LEVEL_WARNING, $entity_type, $entity_id, $operation, $message, $context );
	}

	/**
	 * Log error.
	 */
	public static function error( string $entity_type, string $entity_id, string $operation, string $message, mixed $context = null ): void {
		self::log( self::LEVEL_ERROR, $entity_type, $entity_id, $operation, $message, $context );
	}
}
