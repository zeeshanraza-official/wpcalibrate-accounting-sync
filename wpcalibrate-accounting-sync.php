<?php
/**
 * Plugin Name:       WPCalibrate Accounting Sync
 * Plugin URI:        https://marketplace.wpcalibrate.com/
 * Description:       Connects WooCommerce with QuickBooks Online for automatic, reliable accounting synchronization without modifying core files.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            WPCalibrate
 * Author URI:        https://wpcalibrate.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpcalibrate-accounting-sync
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.1.2
 *
 * GitHub Plugin URI: https://github.com/zeeshanraza-official/wpcalibrate-accounting-sync
 * Primary Branch:    main
 * Release Asset:     true
 * Update URI:        https://github.com/zeeshanraza-official/wpcalibrate-accounting-sync
 *
 * @package WPCalibrate\AccountingSync
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'WPCALIBRATE_ACCOUNTING_SYNC_VERSION', '1.0.0' );
define( 'WPCALIBRATE_ACCOUNTING_SYNC_FILE', __FILE__ );
define( 'WPCALIBRATE_ACCOUNTING_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPCALIBRATE_ACCOUNTING_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCALIBRATE_ACCOUNTING_SYNC_BASENAME', plugin_basename( __FILE__ ) );

// Enforce PHP 8.2+ requirement before loading any modern syntax.
if ( version_compare( PHP_VERSION, '8.2.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
				esc_html__( 'WPCalibrate Accounting Sync', 'wpcalibrate-accounting-sync' ),
				sprintf(
					/* translators: 1: Required PHP version, 2: Current PHP version */
					esc_html__( 'This plugin requires PHP %1$s or higher. Your server is running PHP %2$s. Please upgrade PHP to use this plugin.', 'wpcalibrate-accounting-sync' ),
					'8.2',
					esc_html( PHP_VERSION )
				)
			);
		}
	);
	return;
}

// Register PSR-4 autoloader.
require_once WPCALIBRATE_ACCOUNTING_SYNC_PATH . 'src/Autoloader.php';
\WPCalibrate\AccountingSync\Autoloader::register( WPCALIBRATE_ACCOUNTING_SYNC_PATH . 'src' );

/**
 * Declare WooCommerce High-Performance Order Storage (HPOS) and Blocks compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Bootstrap the plugin on plugins_loaded.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// Initialize the main plugin instance.
		\WPCalibrate\AccountingSync\Plugin::instance()->boot();
	},
	10
);

/**
 * Register activation and deactivation hooks.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		\WPCalibrate\AccountingSync\Plugin::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		\WPCalibrate\AccountingSync\Plugin::deactivate();
	}
);
