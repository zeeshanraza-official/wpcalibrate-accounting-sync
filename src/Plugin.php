<?php
/**
 * Main Plugin Container & Lifecycle Manager.
 *
 * @package WPCalibrate\AccountingSync
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync;

use WPCalibrate\AccountingSync\Admin\MenuManager;
use WPCalibrate\AccountingSync\Admin\PageController;
use WPCalibrate\AccountingSync\Admin\SettingsController;
use WPCalibrate\AccountingSync\Admin\AjaxHandler;
use WPCalibrate\AccountingSync\Admin\Notices;
use WPCalibrate\AccountingSync\Database\Schema;
use WPCalibrate\AccountingSync\Queue\ActionSchedulerQueue;
use WPCalibrate\AccountingSync\Webhooks\WebhookReceiver;
use WPCalibrate\AccountingSync\Sync\SyncManager;
use WPCalibrate\AccountingSync\Reconciliation\ReconciliationEngine;
use WPCalibrate\AccountingSync\Logging\Logger;

/**
 * Main singleton plugin orchestrator.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Bootstrapped flag.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * WooCommerce active flag.
	 *
	 * @var bool
	 */
	private bool $is_woocommerce_active = false;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {}

	/**
	 * Check whether WooCommerce is active and meets requirements.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active(): bool {
		return $this->is_woocommerce_active;
	}

	/**
	 * Plugin activation routine.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Install custom database tables.
		Schema::install();

		// Add custom capabilities to Administrator role.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( 'manage_accounting_sync' );
			$admin_role->add_cap( 'view_accounting_sync_logs' );
			$admin_role->add_cap( 'trigger_accounting_sync' );
		}

		// Initialize default general settings if empty.
		if ( false === get_option( 'wpcalibrate_accounting_sync_general' ) ) {
			update_option(
				'wpcalibrate_accounting_sync_general',
				[
					'environment'                => 'sandbox',
					'sync_enabled'               => 0,
					'accounting_mode'            => 'sales_receipt', // 'sales_receipt' or 'invoice_payment'
					'export_order_statuses'      => [ 'wc-completed', 'wc-processing' ],
					'auto_sync'                  => 1,
					'inventory_sync_direction'   => 'disabled',      // 'disabled', 'wc_to_qbo', 'qbo_to_wc'
					'product_sync_direction'     => 'wc_to_qbo',     // 'wc_to_qbo', 'manual_only'
					'reconciliation_interval'    => 'daily',
					'log_retention_days'         => 30,
					'delete_data_on_uninstall'   => 0,
				]
			);
		}

		// Record installation timestamp and version.
		if ( false === get_option( 'wpcalibrate_accounting_sync_installed_at' ) ) {
			update_option( 'wpcalibrate_accounting_sync_installed_at', gmdate( 'Y-m-d H:i:s' ) );
		}
		update_option( 'wpcalibrate_accounting_sync_version', WPCALIBRATE_ACCOUNTING_SYNC_VERSION );
	}

	/**
	 * Plugin deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Cancel scheduled reconciliation and log pruning jobs.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wpcalibrate_accounting_sync_reconciliation' );
			as_unschedule_all_actions( 'wpcalibrate_accounting_sync_prune_logs' );
		}
	}

	/**
	 * Boot the plugin services.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		// Load textdomain.
		load_plugin_textdomain(
			'wpcalibrate-accounting-sync',
			false,
			dirname( WPCALIBRATE_ACCOUNTING_SYNC_BASENAME ) . '/languages'
		);

		// Verify WooCommerce presence.
		$this->is_woocommerce_active = class_exists( 'WooCommerce' );

		// Initialize schema checks / idempotent migrations.
		Schema::maybe_update();

		// Initialize Admin Menu Manager and notices.
		$menu_manager       = new MenuManager();
		$menu_manager->init();

		$notices = new Notices( $this->is_woocommerce_active );
		$notices->init();

		// Always initialize page controller so admin screens render safely.
		$page_controller = new PageController( $this->is_woocommerce_active );
		$page_controller->init();

		$settings_controller = new SettingsController();
		$settings_controller->init();

		$ajax_handler = new AjaxHandler();
		$ajax_handler->init();

		$github_updater = new \WPCalibrate\AccountingSync\Admin\GitHubUpdater();
		$github_updater->register();

		// If WooCommerce is inactive, do not initialize sync, webhooks, or background processing.
		if ( ! $this->is_woocommerce_active ) {
			$this->booted = true;
			return;
		}

		// Initialize Action Scheduler Queue & Jobs.
		$queue = new ActionSchedulerQueue();
		$queue->init();

		// Initialize Webhook REST Endpoint.
		$webhooks = new WebhookReceiver();
		$webhooks->init();

		// Initialize Sync Manager & Lifecycle Hooks.
		$sync_manager = new SyncManager();
		$sync_manager->init();

		// Initialize Order Edit Metabox.
		$order_metabox = new \WPCalibrate\AccountingSync\Admin\OrderMetabox();
		$order_metabox->init();

		// Initialize Reconciliation Engine.
		$reconciliation = new ReconciliationEngine();
		$reconciliation->init();

		$this->booted = true;
	}
}
