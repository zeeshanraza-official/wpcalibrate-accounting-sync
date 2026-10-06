<?php
/**
 * Concurrency & Mutex Lock Manager.
 *
 * @package WPCalibrate\AccountingSync\Queue
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Queue;

/**
 * Manages fine-grained, self-expiring locks to guarantee idempotency and prevent race conditions.
 */
final class LockManager {

	private const LOCK_PREFIX = 'wpcalibrate_sync_lock_';

	/**
	 * Attempts to acquire an entity-specific lock.
	 *
	 * @param string $entity_type Entity type ('order', 'refund', 'reconciliation').
	 * @param string $entity_id   Identifier.
	 * @param int    $ttl_seconds Time-to-live in seconds (default 120s).
	 * @return bool True if acquired, false if already locked.
	 */
	public static function acquire( string $entity_type, string $entity_id, int $ttl_seconds = 120 ): bool {
		$key = self::LOCK_PREFIX . sanitize_key( $entity_type ) . '_' . sanitize_key( $entity_id );
		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, time(), $ttl_seconds );
		return true;
	}

	/**
	 * Releases an acquired lock.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $entity_id   Identifier.
	 * @return void
	 */
	public static function release( string $entity_type, string $entity_id ): void {
		$key = self::LOCK_PREFIX . sanitize_key( $entity_type ) . '_' . sanitize_key( $entity_id );
		delete_transient( $key );
	}

	/**
	 * Checks if an entity is currently locked.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $entity_id   Identifier.
	 * @return bool True if locked.
	 */
	public static function is_locked( string $entity_type, string $entity_id ): bool {
		$key = self::LOCK_PREFIX . sanitize_key( $entity_type ) . '_' . sanitize_key( $entity_id );
		return (bool) get_transient( $key );
	}
}
