<?php
/**
 * Webhook Deduplication Repository.
 *
 * @package WPCalibrate\AccountingSync\Database
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Database;

/**
 * Manages webhook deduplication ledger to ensure idempotent webhook processing.
 */
final class WebhookRepository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpcalibrate_sync_webhooks';
	}

	/**
	 * Checks if an event ID has already been recorded.
	 *
	 * @param string $event_id Event identifier.
	 * @return bool True if already recorded, false otherwise.
	 */
	public static function is_duplicate( string $event_id ): bool {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE event_id = %s LIMIT 1",
				$event_id
			)
		);

		return null !== $exists;
	}

	/**
	 * Records a webhook event.
	 *
	 * @param string $event_id    Event unique ID / hash.
	 * @param string $realm_id    QuickBooks Realm ID.
	 * @param string $entity_type Entity type (Customer, Invoice, etc.).
	 * @param string $entity_id   Entity ID.
	 * @param string $operation   Operation (Create, Update, Delete, Void).
	 * @param string $status      Status ('received', 'processed', 'skipped', 'failed').
	 * @return bool
	 */
	public static function record_event(
		string $event_id,
		string $realm_id,
		string $entity_type,
		string $entity_id,
		string $operation,
		string $status = 'received'
	): bool {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			[
				'event_id'    => $event_id,
				'realm_id'    => $realm_id,
				'entity_type' => $entity_type,
				'entity_id'   => $entity_id,
				'operation'   => $operation,
				'status'      => $status,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Updates the status of an event.
	 *
	 * @param string $event_id Event ID.
	 * @param string $status   New status.
	 * @return bool
	 */
	public static function update_status( string $event_id, string $status ): bool {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$table,
			[ 'status' => $status ],
			[ 'event_id' => $event_id ],
			[ '%s' ],
			[ '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Prunes old webhook records.
	 *
	 * @param int $days Days to retain (default 14).
	 * @return int Number of rows pruned.
	 */
	public static function prune( int $days = 14 ): int {
		global $wpdb;

		$table  = self::get_table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				$cutoff
			)
		);

		return false !== $deleted ? (int) $deleted : 0;
	}
}
