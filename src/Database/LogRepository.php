<?php
/**
 * Log Repository.
 *
 * @package WPCalibrate\AccountingSync\Database
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Database;

/**
 * Handles persistence and retrieval of synchronization audit logs.
 */
final class LogRepository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpcalibrate_sync_logs';
	}

	/**
	 * Records an audit log entry.
	 *
	 * @param string      $environment    Environment ('sandbox' or 'production').
	 * @param string      $correlation_id Trace correlation ID.
	 * @param string      $severity       Severity ('debug', 'info', 'warning', 'error').
	 * @param string      $entity_type    Entity type.
	 * @param string      $entity_id      Entity identifier.
	 * @param string      $operation      Operation name.
	 * @param string      $message        Log message.
	 * @param string|null $context_json   Sanitized JSON context.
	 * @return bool
	 */
	public static function insert(
		string $environment,
		string $correlation_id,
		string $severity,
		string $entity_type,
		string $entity_id,
		string $operation,
		string $message,
		?string $context_json = null
	): bool {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			[
				'environment'    => $environment,
				'correlation_id' => $correlation_id,
				'severity'       => $severity,
				'entity_type'    => $entity_type,
				'entity_id'      => $entity_id,
				'operation'      => $operation,
				'message'        => $message,
				'context'        => $context_json,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Retrieve paginated logs with filtering.
	 *
	 * @param string $environment Environment filter.
	 * @param string $severity    Optional severity filter.
	 * @param string $search      Optional search term.
	 * @param int    $limit       Rows per page.
	 * @param int    $offset      Offset.
	 * @return array{items: array<int, object>, total: int}
	 */
	public static function get_paginated(
		string $environment,
		string $severity = '',
		string $search = '',
		int $limit = 25,
		int $offset = 0
	): array {
		global $wpdb;

		$table  = self::get_table_name();
		$where  = [ 'environment = %s' ];
		$params = [ $environment ];

		if ( '' !== $severity ) {
			$where[]  = 'severity = %s';
			$params[] = $severity;
		}

		if ( '' !== $search ) {
			$where[]  = '(message LIKE %s OR correlation_id LIKE %s OR entity_id LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
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

	/**
	 * Prunes logs older than a given number of days.
	 *
	 * @param int $days Number of days to retain.
	 * @return int Number of deleted rows.
	 */
	public static function prune( int $days ): int {
		global $wpdb;

		if ( $days < 1 ) {
			$days = 30;
		}

		$table = self::get_table_name();
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

	/**
	 * Clears all logs for an environment.
	 *
	 * @param string $environment Environment to clear.
	 * @return bool
	 */
	public static function clear_all( string $environment ): bool {
		global $wpdb;

		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete(
			$table,
			[ 'environment' => $environment ],
			[ '%s' ]
		);

		return false !== $deleted;
	}
}
