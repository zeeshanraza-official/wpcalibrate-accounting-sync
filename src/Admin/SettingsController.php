<?php
/**
 * Admin Settings Form Controller.
 *
 * @package WPCalibrate\AccountingSync\Admin
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Admin;

use WPCalibrate\AccountingSync\Auth\OAuthClient;
use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Client\QuickBooksClient;
use WPCalibrate\AccountingSync\Licensing\LicenseManager;
use WPCalibrate\AccountingSync\Mapping\MappingService;
use WPCalibrate\AccountingSync\Database\LogRepository;
use WPCalibrate\AccountingSync\Reconciliation\ReconciliationEngine;
use Exception;

/**
 * Validates and saves plugin settings with CSRF nonce and capability verification.
 */
final class SettingsController {

	/**
	 * Hook registrations.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_wpcalibrate_sync_save_general', [ $this, 'save_general' ] );
		add_action( 'admin_post_wpcalibrate_sync_save_connection', [ $this, 'save_connection' ] );
		add_action( 'admin_post_wpcalibrate_sync_connect_oauth', [ $this, 'connect_oauth' ] );
		add_action( 'admin_post_wpcalibrate_sync_disconnect_oauth', [ $this, 'disconnect_oauth' ] );
		add_action( 'admin_post_wpcalibrate_sync_save_mapping', [ $this, 'save_mapping' ] );
		add_action( 'admin_post_wpcalibrate_sync_save_license', [ $this, 'save_license' ] );
		add_action( 'admin_post_wpcalibrate_sync_refresh_reference_data', [ $this, 'refresh_reference_data' ] );
		add_action( 'admin_post_wpcalibrate_sync_clear_logs', [ $this, 'clear_logs' ] );
		add_action( 'admin_post_wpcalibrate_sync_run_reconciliation', [ $this, 'run_reconciliation' ] );
	}

	/**
	 * Verifies capability and nonce.
	 *
	 * @param string $action Nonce action.
	 * @param string $name   Nonce field name.
	 * @return void
	 */
	private function verify_request( string $action, string $name = '_wpnonce' ): void {
		if ( ! current_user_can( 'manage_accounting_sync' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized user.', 'wpcalibrate-accounting-sync' ) );
		}

		check_admin_referer( $action, $name );
	}

	/**
	 * Redirects back to tab with notice message.
	 *
	 * @param string $tab    Target tab.
	 * @param string $status Status ('success' or 'error').
	 * @param string $msg    Message.
	 * @return void
	 */
	private function redirect_with_notice( string $tab, string $status, string $msg ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'   => PageController::PAGE_SLUG,
					'tab'    => $tab,
					'status' => $status,
					'msg'    => rawurlencode( $msg ),
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Saves general & sync settings.
	 */
	public function save_general(): void {
		$this->verify_request( 'wpcalibrate_sync_save_general_action' );

		$current = get_option( 'wpcalibrate_accounting_sync_general', [] );

		$env = sanitize_key( wp_unslash( $_POST['environment'] ?? 'sandbox' ) );
		if ( ! in_array( $env, [ 'sandbox', 'production' ], true ) ) {
			$env = 'sandbox';
		}

		$mode = sanitize_key( wp_unslash( $_POST['accounting_mode'] ?? 'sales_receipt' ) );
		if ( ! in_array( $mode, [ 'sales_receipt', 'invoice_payment' ], true ) ) {
			$mode = 'sales_receipt';
		}

		$inv_dir = sanitize_key( wp_unslash( $_POST['inventory_sync_direction'] ?? 'disabled' ) );
		if ( ! in_array( $inv_dir, [ 'disabled', 'wc_to_qbo', 'qbo_to_wc' ], true ) ) {
			$inv_dir = 'disabled';
		}

		$statuses = array_map( 'sanitize_text_field', (array) ( $_POST['export_order_statuses'] ?? [ 'wc-completed', 'wc-processing' ] ) );

		$settings = [
			'environment'              => $env,
			'sync_enabled'             => ! empty( $_POST['sync_enabled'] ) ? 1 : 0,
			'auto_sync'                => ! empty( $_POST['auto_sync'] ) ? 1 : 0,
			'accounting_mode'          => $mode,
			'export_order_statuses'    => $statuses,
			'product_sync_direction'   => sanitize_key( wp_unslash( $_POST['product_sync_direction'] ?? 'wc_to_qbo' ) ),
			'auto_create_products'     => ! empty( $_POST['auto_create_products'] ) ? 1 : 0,
			'inventory_sync_direction' => $inv_dir,
			'reconciliation_interval'  => sanitize_key( wp_unslash( $_POST['reconciliation_interval'] ?? 'daily' ) ),
			'log_retention_days'       => max( 1, min( 365, (int) ( $_POST['log_retention_days'] ?? 30 ) ) ),
			'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ) ? 1 : 0,
		];

		update_option( 'wpcalibrate_accounting_sync_general', $settings );
		$this->redirect_with_notice( 'sync', 'success', __( 'Sync settings saved successfully.', 'wpcalibrate-accounting-sync' ) );
	}

	/**
	 * Saves environment connection configuration.
	 */
	public function save_connection(): void {
		$this->verify_request( 'wpcalibrate_sync_save_connection_action' );

		$target_env = sanitize_key( wp_unslash( $_POST['target_environment'] ?? 'sandbox' ) );
		if ( ! in_array( $target_env, [ 'sandbox', 'production' ], true ) ) {
			$target_env = 'sandbox';
		}

		$conn_key = 'wpcalibrate_accounting_sync_connection_' . $target_env;
		$existing = get_option( $conn_key, [] );

		$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		$secret    = trim( (string) wp_unslash( $_POST['client_secret'] ?? '' ) );
		$verifier  = trim( (string) wp_unslash( $_POST['webhook_verifier_token'] ?? '' ) );

		// Preserve existing secrets if masked.
		if ( str_contains( $secret, '•' ) || '' === $secret ) {
			$secret = $existing['client_secret'] ?? '';
		}

		if ( str_contains( $verifier, '•' ) || '' === $verifier ) {
			$verifier = $existing['webhook_verifier_token'] ?? '';
		}

		update_option(
			$conn_key,
			[
				'client_id'              => $client_id,
				'client_secret'          => $secret,
				'webhook_verifier_token' => $verifier,
			]
		);

		$this->redirect_with_notice( 'connection', 'success', __( 'Connection settings saved.', 'wpcalibrate-accounting-sync' ) );
	}

	/**
	 * Initiates OAuth connection.
	 */
	public function connect_oauth(): void {
		$this->verify_request( 'wpcalibrate_sync_connect_oauth_action' );

		$general    = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$target_env = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

		$conn_key  = 'wpcalibrate_accounting_sync_connection_' . $target_env;
		$conn_opt  = get_option( $conn_key, [] );
		$client_id = (string) ( $conn_opt['client_id'] ?? '' );

		if ( empty( $client_id ) || empty( $conn_opt['client_secret'] ) ) {
			$this->redirect_with_notice( 'connection', 'error', __( 'Please provide Client ID and Client Secret before connecting.', 'wpcalibrate-accounting-sync' ) );
		}

		$auth_url = OAuthClient::get_authorization_url( $target_env, $client_id );
		wp_redirect( $auth_url );
		exit;
	}

	/**
	 * Disconnects OAuth connection.
	 */
	public function disconnect_oauth(): void {
		$this->verify_request( 'wpcalibrate_sync_disconnect_oauth_action' );

		$general    = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$target_env = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

		$conn_key = 'wpcalibrate_accounting_sync_connection_' . $target_env;
		$conn_opt = get_option( $conn_key, [] );

		OAuthClient::disconnect( $target_env, (string) ( $conn_opt['client_id'] ?? '' ), (string) ( $conn_opt['client_secret'] ?? '' ) );
		$this->redirect_with_notice( 'connection', 'success', __( 'QuickBooks company disconnected.', 'wpcalibrate-accounting-sync' ) );
	}

	/**
	 * Saves mappings.
	 */
	public function save_mapping(): void {
		$this->verify_request( 'wpcalibrate_sync_save_mapping_action' );

		$rules = [
			'default_sales_item_id'      => sanitize_text_field( wp_unslash( $_POST['default_sales_item_id'] ?? '' ) ),
			'fallback_item_id'           => sanitize_text_field( wp_unslash( $_POST['fallback_item_id'] ?? '' ) ),
			'shipping_item_id'           => sanitize_text_field( wp_unslash( $_POST['shipping_item_id'] ?? '' ) ),
			'default_deposit_account_id' => sanitize_text_field( wp_unslash( $_POST['default_deposit_account_id'] ?? '' ) ),
			'default_income_account_id'  => sanitize_text_field( wp_unslash( $_POST['default_income_account_id'] ?? '' ) ),
			'payment_account_map'        => array_map( 'sanitize_text_field', (array) ( $_POST['payment_account_map'] ?? [] ) ),
			'payment_method_map'         => array_map( 'sanitize_text_field', (array) ( $_POST['payment_method_map'] ?? [] ) ),
			'tax_map'                    => array_map( 'sanitize_text_field', (array) ( $_POST['tax_map'] ?? [] ) ),
		];

		update_option( 'wpcalibrate_accounting_sync_mapping_rules', $rules );
		$this->redirect_with_notice( 'mapping', 'success', __( 'Mappings saved successfully.', 'wpcalibrate-accounting-sync' ) );
	}

	/**
	 * Saves license key.
	 */
	public function save_license(): void {
		$this->verify_request( 'wpcalibrate_sync_save_license_action' );

		if ( ! empty( $_POST['clear_license'] ) ) {
			LicenseManager::clear_license();
			$this->redirect_with_notice( 'license', 'success', __( 'License key cleared.', 'wpcalibrate-accounting-sync' ) );
		}

		$key = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		if ( ! str_contains( $key, '•' ) ) {
			LicenseManager::save_key( $key );
		}

		$this->redirect_with_notice( 'license', 'success', __( 'License key saved. (Remote activation not configured)', 'wpcalibrate-accounting-sync' ) );
	}

	/**
	 * Flushes and refreshes QuickBooks reference data.
	 */
	public function refresh_reference_data(): void {
		$this->verify_request( 'wpcalibrate_sync_refresh_reference_action' );

		$general    = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$target_env = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

		MappingService::flush_cache( $target_env );
		MappingService::get_accounts( $target_env, true );
		MappingService::get_items( $target_env, true );
		MappingService::get_tax_codes( $target_env, true );
		MappingService::get_payment_methods( $target_env, true );

		$this->redirect_with_notice( 'mapping', 'success', __( 'QuickBooks accounts, items, taxes, and payment methods refreshed from API.', 'wpcalibrate-accounting-sync' ) );
	}

	/**
	 * Clears audit logs.
	 */
	public function clear_logs(): void {
		$this->verify_request( 'wpcalibrate_sync_clear_logs_action' );

		$general    = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$target_env = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

		LogRepository::clear_all( $target_env );
		$this->redirect_with_notice( 'logs', 'success', __( 'Audit logs cleared.', 'wpcalibrate-accounting-sync' ) );
	}

	/**
	 * Manually triggers reconciliation.
	 */
	public function run_reconciliation(): void {
		$this->verify_request( 'wpcalibrate_sync_reconcile_action' );

		$reconciler = new ReconciliationEngine();
		$result     = $reconciler->run_reconciliation();

		$msg = sprintf(
			/* translators: 1: Checked count, 2: Recovered count */
			__( 'Reconciliation completed: Checked %1$d orders, enqueued %2$d missed exports.', 'wpcalibrate-accounting-sync' ),
			$result['checked'],
			$result['recovered']
		);

		$this->redirect_with_notice( 'overview', 'success', $msg );
	}
}
