<?php
/**
 * Admin Notices Manager.
 *
 * @package WPCalibrate\AccountingSync\Admin
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Admin;

/**
 * Handles administrator warnings and status alerts.
 */
final class Notices {

	/**
	 * WooCommerce active flag.
	 *
	 * @var bool
	 */
	private bool $is_woocommerce_active;

	/**
	 * Constructor.
	 *
	 * @param bool $is_woocommerce_active WooCommerce status.
	 */
	public function __construct( bool $is_woocommerce_active ) {
		$this->is_woocommerce_active = $is_woocommerce_active;
	}

	/**
	 * Register notice hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_notices', [ $this, 'render_admin_notices' ] );
	}

	/**
	 * Renders global admin notices.
	 *
	 * @return void
	 */
	public function render_admin_notices(): void {
		// Only display notices to users who can manage the plugin.
		if ( ! current_user_can( 'manage_accounting_sync' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 1. Missing WooCommerce Notice.
		if ( ! $this->is_woocommerce_active ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<strong><?php esc_html_e( 'WPCalibrate Accounting Sync:', 'wpcalibrate-accounting-sync' ); ?></strong>
					<?php esc_html_e( 'WooCommerce is required for this plugin to function. Please install and activate WooCommerce.', 'wpcalibrate-accounting-sync' ); ?>
				</p>
			</div>
			<?php
		}

		// 2. Action Scheduler Health Notice.
		if ( ! function_exists( 'as_enqueue_async_action' ) && $this->is_woocommerce_active ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'WPCalibrate Accounting Sync:', 'wpcalibrate-accounting-sync' ); ?></strong>
					<?php esc_html_e( 'WooCommerce Action Scheduler was not detected. Background queue processing will be paused until Action Scheduler is available.', 'wpcalibrate-accounting-sync' ); ?>
				</p>
			</div>
			<?php
		}
	}
}
