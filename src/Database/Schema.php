<?php
/**
 * Database Schema and Migrations Manager.
 *
 * @package WPCalibrate\AccountingSync\Database
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Database;

/**
 * Handles creation and upgrades of custom database tables via dbDelta().
 */
final class Schema {

	/**
	 * Current database schema version.
	 *
	 * @var string
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * Installs or updates plugin database tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset_collate = ! empty( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

		$prefix         = ! empty( $wpdb ) && isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
		$table_mappings = $prefix . 'wpcalibrate_sync_mappings';
		$table_logs     = $prefix . 'wpcalibrate_sync_logs';
		$table_webhooks = $prefix . 'wpcalibrate_sync_webhooks';

		// 1. Entity Mappings Table.
		$sql_mappings = "CREATE TABLE {$table_mappings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			environment varchar(20) NOT NULL DEFAULT 'sandbox',
			realm_id varchar(64) NOT NULL DEFAULT '',
			wc_entity_type varchar(40) NOT NULL,
			wc_entity_id bigint(20) unsigned NOT NULL,
			qbo_entity_type varchar(40) NOT NULL,
			qbo_entity_id varchar(64) NOT NULL,
			sync_hash varchar(64) DEFAULT NULL,
			last_sync_status varchar(20) NOT NULL DEFAULT 'synced',
			last_sync_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			UNIQUE KEY uk_env_wc (environment, realm_id, wc_entity_type, wc_entity_id),
			KEY ix_qbo (environment, realm_id, qbo_entity_type, qbo_entity_id),
			KEY ix_status (last_sync_status)
		) {$charset_collate};";

		// 2. Audit & Sync Logs Table.
		$sql_logs = "CREATE TABLE {$table_logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			environment varchar(20) NOT NULL DEFAULT 'sandbox',
			correlation_id varchar(64) NOT NULL DEFAULT '',
			severity varchar(20) NOT NULL DEFAULT 'info',
			entity_type varchar(40) NOT NULL DEFAULT '',
			entity_id varchar(64) NOT NULL DEFAULT '',
			operation varchar(50) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY ix_created (created_at),
			KEY ix_corr (correlation_id),
			KEY ix_severity (severity)
		) {$charset_collate};";

		// 3. Webhook Deduplication Table.
		$sql_webhooks = "CREATE TABLE {$table_webhooks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(128) NOT NULL,
			realm_id varchar(64) NOT NULL DEFAULT '',
			entity_type varchar(40) NOT NULL DEFAULT '',
			entity_id varchar(64) NOT NULL DEFAULT '',
			operation varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'received',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			UNIQUE KEY uk_event (event_id),
			KEY ix_status (status)
		) {$charset_collate};";

		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql_mappings );
			dbDelta( $sql_logs );
			dbDelta( $sql_webhooks );
		}

		update_option( 'wpcalibrate_accounting_sync_db_version', self::DB_VERSION );
	}

	/**
	 * Checks if schema upgrade is necessary and applies it.
	 *
	 * @return void
	 */
	public static function maybe_update(): void {
		$installed_version = get_option( 'wpcalibrate_accounting_sync_db_version', '0.0.0' );
		if ( version_compare( (string) $installed_version, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}
}
