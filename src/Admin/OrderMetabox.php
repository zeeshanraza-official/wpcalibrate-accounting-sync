<?php
/**
 * WooCommerce Order Screen Integration Metabox.
 *
 * @package WPCalibrate\AccountingSync\Admin
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Admin;

use WPCalibrate\AccountingSync\Database\MappingRepository;
use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Queue\ActionSchedulerQueue;
use WC_Order;

/**
 * Renders accounting synchronization metadata and manual sync trigger on the order edit screen.
 */
final class OrderMetabox {

	/**
	 * Hook registrations.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_order_metabox' ], 20 );
		add_action( 'admin_post_wpcalibrate_sync_manual_order_submit', [ $this, 'handle_manual_sync' ] );
	}

	/**
	 * Registers the metabox for HPOS and classic post storage.
	 *
	 * @return void
	 */
	public function add_order_metabox(): void {
		$screen = class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'wpcalibrate_accounting_sync_box',
			__( 'QuickBooks Online Sync', 'wpcalibrate-accounting-sync' ),
			[ $this, 'render_metabox' ],
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Renders metabox contents.
	 *
	 * @param \WP_Post|WC_Order $post_or_order Post or Order object.
	 * @return void
	 */
	public function render_metabox( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$order_id    = $order->get_id();
		$general     = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$environment = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

		$tokens   = TokenManager::get_tokens( $environment );
		$realm_id = $tokens['realm_id'] ?? '';

		$qbo_id = MappingRepository::get_qbo_id( $environment, $realm_id, 'order', $order_id );

		?>
		<div class="wpcalibrate-order-box">
			<p>
				<strong><?php esc_html_e( 'Environment:', 'wpcalibrate-accounting-sync' ); ?></strong>
				<span><?php echo esc_html( ucfirst( $environment ) ); ?></span>
			</p>
			<p>
				<strong><?php esc_html_e( 'Sync Status:', 'wpcalibrate-accounting-sync' ); ?></strong>
				<?php if ( ! empty( $qbo_id ) ) : ?>
					<span style="color:#00a32a; font-weight:600;"><?php printf( esc_html__( 'Synced (#%s)', 'wpcalibrate-accounting-sync' ), esc_html( $qbo_id ) ); ?></span>
				<?php else : ?>
					<span style="color:#d63638; font-weight:600;"><?php esc_html_e( 'Not Synced', 'wpcalibrate-accounting-sync' ); ?></span>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
				<input type="hidden" name="action" value="wpcalibrate_sync_manual_order_submit" />
				<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
				<?php wp_nonce_field( 'wpcalibrate_sync_manual_order_action' ); ?>
				<button type="submit" class="button button-secondary" style="width:100%; text-align:center;">
					<?php esc_html_e( 'Sync to QuickBooks Now', 'wpcalibrate-accounting-sync' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles manual order sync POST submission.
	 *
	 * @return void
	 */
	public function handle_manual_sync(): void {
		if ( ! current_user_can( 'trigger_accounting_sync' ) && ! current_user_can( 'manage_accounting_sync' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized user.', 'wpcalibrate-accounting-sync' ) );
		}

		check_admin_referer( 'wpcalibrate_sync_manual_order_action' );

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		if ( $order_id > 0 ) {
			ActionSchedulerQueue::enqueue_order_sync( $order_id );
		}

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}
}
