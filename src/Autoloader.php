<?php
/**
 * Custom PSR-4 Autoloader.
 *
 * @package WPCalibrate\AccountingSync
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync;

/**
 * High-performance, self-contained PSR-4 Autoloader.
 */
final class Autoloader {

	/**
	 * Namespace prefix.
	 *
	 * @var string
	 */
	private const NAMESPACE_PREFIX = 'WPCalibrate\\AccountingSync\\';

	/**
	 * Base directory for the namespace prefix.
	 *
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * Registered status flag.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Registers the autoloader.
	 *
	 * @param string $base_dir Base directory pointing to src/.
	 * @return void
	 */
	public static function register( string $base_dir ): void {
		if ( self::$registered ) {
			return;
		}

		self::$base_dir = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;

		spl_autoload_register( [ self::class, 'load' ] );
		self::$registered = true;
	}

	/**
	 * Loads the class file.
	 *
	 * @param string $class Fully qualified class name.
	 * @return bool True if loaded, false otherwise.
	 */
	public static function load( string $class ): bool {
		$len = strlen( self::NAMESPACE_PREFIX );
		if ( strncmp( self::NAMESPACE_PREFIX, $class, $len ) !== 0 ) {
			return false;
		}

		$relative_class = substr( $class, $len );
		$file = self::$base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
			return true;
		}

		return false;
	}
}
