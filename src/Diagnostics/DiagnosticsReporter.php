<?php
/**
 * System Diagnostics Reporter.
 *
 * @package WPCalibrate\AccountingSync\Diagnostics
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Diagnostics;

use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Database\Schema;
use WPCalibrate\AccountingSync\Database\MappingRepository;
use WPCalibrate\AccountingSync\Database\LogRepository;
use WPCalibrate\AccountingSync\Webhooks\WebhookReceiver;

/**
 * Compiles technical environment health and diagnostics without exposing secrets.
 */
final class DiagnosticsReporter {

	/**
	 * Generates full diagnostics report dictionary.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_report(): array {
		global $wp_version;

		$general     = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$environment = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'Not installed';

		$hpos_enabled = false;
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		$is_connected = TokenManager::is_connected( $environment );
		$tokens       = TokenManager::get_tokens( $environment );
		$realm_id     = $tokens['realm_id'] ?? 'Not connected';
		$connected_at = $tokens['connected_at'] ?? 'Never';

		$as_active = function_exists( 'as_enqueue_async_action' );

		$webhook_url = rest_url( WebhookReceiver::REST_NAMESPACE . WebhookReceiver::REST_ROUTE );

		$extensions = [
			'curl'     => extension_loaded( 'curl' ),
			'openssl'  => extension_loaded( 'openssl' ),
			'json'     => extension_loaded( 'json' ),
			'mbstring' => extension_loaded( 'mbstring' ),
		];

		return [
			'system' => [
				'plugin_version'     => WPCALIBRATE_ACCOUNTING_SYNC_VERSION,
				'db_schema_version'  => Schema::DB_VERSION,
				'wordpress_version'  => $wp_version,
				'php_version'        => PHP_VERSION,
				'woocommerce_version'=> $wc_version,
				'hpos_enabled'       => $hpos_enabled,
				'action_scheduler'   => $as_active,
				'disable_wp_cron'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'extensions'         => $extensions,
			],
			'connection' => [
				'environment'        => $environment,
				'is_connected'       => $is_connected,
				'realm_id'           => $realm_id,
				'connected_at'       => $connected_at,
				'accounting_mode'    => $general['accounting_mode'] ?? 'sales_receipt',
				'sync_enabled'       => ! empty( $general['sync_enabled'] ),
				'auto_sync'          => ! empty( $general['auto_sync'] ),
				'inventory_direction'=> $general['inventory_sync_direction'] ?? 'disabled',
			],
			'webhooks' => [
				'endpoint_url'       => $webhook_url,
				'rest_available'     => function_exists( 'rest_url' ),
			],
		];
	}

	/**
	 * Formats report as clean sanitized markdown/text for Copy Diagnostics feature.
	 *
	 * @return string
	 */
	public static function get_formatted_text(): string {
		$r = self::get_report();

		$lines   = [];
		$lines[] = '### WPCalibrate Accounting Sync — System Status';
		$lines[] = '------------------------------------------------';
		$lines[] = 'Plugin Version:      ' . $r['system']['plugin_version'];
		$lines[] = 'DB Schema Version:   ' . $r['system']['db_schema_version'];
		$lines[] = 'WordPress Version:   ' . $r['system']['wordpress_version'];
		$lines[] = 'PHP Version:         ' . $r['system']['php_version'];
		$lines[] = 'WooCommerce Version: ' . $r['system']['woocommerce_version'];
		$lines[] = 'HPOS Active:         ' . ( $r['system']['hpos_enabled'] ? 'Yes' : 'No' );
		$lines[] = 'Action Scheduler:    ' . ( $r['system']['action_scheduler'] ? 'Active' : 'Missing' );
		$lines[] = 'DISABLE_WP_CRON:     ' . ( $r['system']['disable_wp_cron'] ? 'True (External Cron)' : 'False (Default)' );
		$lines[] = 'PHP Extensions:      ' . sprintf( 'cURL: %s, OpenSSL: %s, JSON: %s, mbstring: %s',
			$r['system']['extensions']['curl'] ? 'OK' : 'Missing',
			$r['system']['extensions']['openssl'] ? 'OK' : 'Missing',
			$r['system']['extensions']['json'] ? 'OK' : 'Missing',
			$r['system']['extensions']['mbstring'] ? 'OK' : 'Missing'
		);
		$lines[] = '';
		$lines[] = '### QuickBooks Connection';
		$lines[] = 'Environment:         ' . ucfirst( (string) $r['connection']['environment'] );
		$lines[] = 'Connection Status:   ' . ( $r['connection']['is_connected'] ? 'Connected' : 'Disconnected' );
		$lines[] = 'Realm/Company ID:    ' . $r['connection']['realm_id'];
		$lines[] = 'Connected At:        ' . $r['connection']['connected_at'];
		$lines[] = 'Accounting Mode:     ' . $r['connection']['accounting_mode'];
		$lines[] = 'Sync Enabled:        ' . ( $r['connection']['sync_enabled'] ? 'Yes' : 'No' );
		$lines[] = 'Auto Sync:           ' . ( $r['connection']['auto_sync'] ? 'Yes' : 'No' );
		$lines[] = 'Inventory Direction: ' . $r['connection']['inventory_direction'];
		$lines[] = '';
		$lines[] = '### Webhook Endpoint';
		$lines[] = 'REST Endpoint:       ' . $r['webhooks']['endpoint_url'];

		return implode( "\n", $lines );
	}
}
