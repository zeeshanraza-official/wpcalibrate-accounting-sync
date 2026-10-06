<?php
/**
 * Entity Mapping Repository.
 *
 * @package WPCalibrate\AccountingSync\Database
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Database;

/**
 * Encapsulates persistent entity mappings between WooCommerce and QuickBooks Online.
 */
final class MappingRepository {

	/**
	 * Get table name with WordPress prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpcalibrate_sync_mappings';
	}

	/**
	 * Finds mapped QuickBooks entity ID for a given WooCommerce entity.
	 *
	 * @param string $environment Environment ('sandbox' or 'production').
	 * @param string $realm_id    QuickBooks Realm/Company ID.
	 * @param string $wc_type     WooCommerce entity type ('order', 'customer', 'product', 'variation', 'refund').
	 * @param int    $wc_id       WooCommerce entity ID.
	 * @return string|null QuickBooks entity ID or null if unmapped.
	 */
	public static function get_qbo_id( string $environment, string $realm_id, string $wc_type, int $wc_id ): ?string {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT qbo_entity_id FROM {$table} WHERE environment = %s AND realm_id = %s AND wc_entity_type = %s AND wc_entity_id = %d LIMIT 1",
				$environment,
				$realm_id,
				$wc_type,
				$wc_id
			)
		);

		return $result !== null ? (string) $result : null;
	}

	/**
	 * Finds mapped WooCommerce entity ID for a given QuickBooks entity.
	 *
	 * @param string $environment Environment ('sandbox' or 'production').
	 * @param string $realm_id    QuickBooks Realm/Company ID.
	 * @param string $qbo_type    QuickBooks entity type ('SalesReceipt', 'Invoice', 'Payment', 'Customer', 'Item', etc.).
	 * @param string $qbo_id      QuickBooks entity ID.
	 * @return int|null WooCommerce entity ID or null if unmapped.
	 */
	public static function get_wc_id( string $environment, string $realm_id, string $qbo_type, string $qbo_id ): ?int {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT wc_entity_id FROM {$table} WHERE environment = %s AND realm_id = %s AND qbo_entity_type = %s AND qbo_entity_id = %s LIMIT 1",
				$environment,
				$realm_id,
				$qbo_type,
				$qbo_id
			)
		);

		return $result !== null ? (int) $result : null;
	}

	/**
	 * Saves or updates a mapping between WooCommerce and QuickBooks Online.
	 *
	 * @param string      $environment Environment ('sandbox' or 'production').
	 * @param string      $realm_id    QuickBooks Realm/Company ID.
	 * @param string      $wc_type     WooCommerce entity type.
	 * @param int         $wc_id       WooCommerce entity ID.
	 * @param string      $qbo_type    QuickBooks entity type.
	 * @param string      $qbo_id      QuickBooks entity ID.
	 * @param string|null $sync_hash   Optional sync hash for duplicate/change detection.
	 * @param string      $status      Sync status ('synced', 'failed', 'pending').
	 * @return bool True on success, false on failure.
	 */
	public static function set_mapping(
		string $environment,
		string $realm_id,
		string $wc_type,
		int $wc_id,
		string $qbo_type,
		string $qbo_id,
		?string $sync_hash = null,
		string $status = 'synced'
	): bool {
		global $wpdb;

		$table = self::get_table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE environment = %s AND realm_id = %s AND wc_entity_type = %s AND wc_entity_id = %d LIMIT 1",
				$environment,
				$realm_id,
				$wc_type,
				$wc_id
			)
		);

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$updated = $wpdb->update(
				$table,
				[
					'qbo_entity_type'  => $qbo_type,
					'qbo_entity_id'    => $qbo_id,
					'sync_hash'        => $sync_hash,
					'last_sync_status' => $status,
					'last_sync_at'     => $now,
				],
				[ 'id' => (int) $existing_id ],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
			return false !== $updated;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'environment'      => $environment,
				'realm_id'         => $realm_id,
				'wc_entity_type'   => $wc_type,
				'wc_entity_id'     => $wc_id,
				'qbo_entity_type'  => $qbo_type,
				'qbo_entity_id'    => $qbo_id,
				'sync_hash'        => $sync_hash,
				'last_sync_status' => $status,
				'last_sync_at'     => $now,
				'created_at'       => $now,
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return false !== $inserted;
	}

	/**
	 * Deletes a mapping.
	 *
	 * @param string $environment Environment.
	 * @param string $realm_id    Realm ID.
	 * @param string $wc_type     WC Entity Type.
	 * @param int    $wc_id       WC Entity ID.
	 * @return bool
	 */
	public static function delete_mapping( string $environment, string $realm_id, string $wc_type, int $wc_id ): bool {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete(
			$table,
			[
				'environment'    => $environment,
				'realm_id'       => $realm_id,
				'wc_entity_type' => $wc_type,
				'wc_entity_id'   => $wc_id,
			],
			[ '%s', '%s', '%s', '%d' ]
		);

		return false !== $deleted;
	}

	/**
	 * Queries mappings for admin table listing with pagination and search.
	 *
	 * @param string $environment Environment.
	 * @param string $realm_id    Realm ID.
	 * @param string $wc_type     Optional WC Entity Type filter.
	 * @param int    $limit       Limit per page.
	 * @param int    $offset      Offset.
	 * @param string $search      Optional search term.
	 * @return array{items: array<int, object>, total: int}
	 */
	public static function get_paginated(
		string $environment,
		string $realm_id,
		string $wc_type = '',
		int $limit = 20,
		int $offset = 0,
		string $search = ''
	): array {
		global $wpdb;

		$table  = self::get_table_name();
		$where  = [ 'environment = %s' ];
		$params = [ $environment ];

		if ( '' !== $realm_id ) {
			$where[]  = 'realm_id = %s';
			$params[] = $realm_id;
		}

		if ( '' !== $wc_type ) {
			$where[]  = 'wc_entity_type = %s';
			$params[] = $wc_type;
		}

		if ( '' !== $search ) {
			$where[]  = '(qbo_entity_id LIKE %s OR CAST(wc_entity_id AS CHAR) LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);

		return [
			'items' => is_array( $items ) ? $items : [],
			'total' => $total,
		];
	}
}
