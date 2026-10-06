<?php
/**
 * Uninstall routine for WPCalibrate Accounting Sync.
 *
 * Triggered only when the user deletes the plugin from the WordPress admin plugins screen.
 * Respects the 'delete_data_on_uninstall' setting.
 *
 * @package WPCalibrate\AccountingSync
 */

declare(strict_types=1);

// Prevent direct execution or execution outside of WordPress plugin deletion.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Check the configured setting. Default is FALSE (preserve business data for reinstall).
$general_settings = get_option( 'wpcalibrate_accounting_sync_general', [] );
$delete_data      = ! empty( $general_settings['delete_data_on_uninstall'] );

if ( ! $delete_data ) {
	// Data preservation requested by administrator. Do not delete tables or settings.
	return;
}

// 1. Delete plugin custom database tables.
$table_mappings = $wpdb->prefix . 'wpcalibrate_sync_mappings';
$table_logs     = $wpdb->prefix . 'wpcalibrate_sync_logs';
$table_webhooks = $wpdb->prefix . 'wpcalibrate_sync_webhooks';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$table_mappings}, {$table_logs}, {$table_webhooks}" );

// 2. Delete plugin options.
$options_to_delete = [
	'wpcalibrate_accounting_sync_version',
	'wpcalibrate_accounting_sync_db_version',
	'wpcalibrate_accounting_sync_general',
	'wpcalibrate_accounting_sync_connection_sandbox',
	'wpcalibrate_accounting_sync_connection_production',
	'wpcalibrate_accounting_sync_tokens_sandbox',
	'wpcalibrate_accounting_sync_tokens_production',
	'wpcalibrate_accounting_sync_settings',
	'wpcalibrate_accounting_sync_mapping_rules',
	'wpcalibrate_accounting_sync_license',
	'wpcalibrate_accounting_sync_installed_at',
];

foreach ( $options_to_delete as $option_name ) {
	delete_option( $option_name );
}

// 3. Clear plugin transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_wpcalibrate_sync_%',
		'_transient_timeout_wpcalibrate_sync_%'
	)
);

// 4. Cancel Action Scheduler scheduled actions registered by this plugin if Action Scheduler is available.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wpcalibrate_accounting_sync_reconciliation' );
	as_unschedule_all_actions( 'wpcalibrate_accounting_sync_process_order' );
	as_unschedule_all_actions( 'wpcalibrate_accounting_sync_process_refund' );
	as_unschedule_all_actions( 'wpcalibrate_accounting_sync_process_inventory' );
	as_unschedule_all_actions( 'wpcalibrate_accounting_sync_process_webhook' );
	as_unschedule_all_actions( 'wpcalibrate_accounting_sync_prune_logs' );
}
