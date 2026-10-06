<?php
/**
 * Admin Overview View.
 *
 * @package WPCalibrate\AccountingSync\Admin\Views
 */

declare(strict_types=1);

use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Database\MappingRepository;
use WPCalibrate\AccountingSync\Database\LogRepository;

$general      = get_option( 'wpcalibrate_accounting_sync_general', [] );
$environment  = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';
$is_connected = TokenManager::is_connected( $environment );
$tokens       = TokenManager::get_tokens( $environment );
$realm_id     = $tokens['realm_id'] ?? '';
$connected_at = $tokens['connected_at'] ?? '';
$last_recon   = get_option( 'wpcalibrate_accounting_sync_last_reconciliation', [] );
?>

<div class="wpcalibrate-dashboard-grid">
	<!-- Connection Status Card -->
	<div class="wpcalibrate-card">
		<div class="wpcalibrate-card-header">
			<h2><?php esc_html_e( 'Connection Status', 'wpcalibrate-accounting-sync' ); ?></h2>
			<span class="wpcalibrate-status-pill <?php echo $is_connected ? 'wpcalibrate-pill-success' : 'wpcalibrate-pill-danger'; ?>">
				<?php echo $is_connected ? esc_html__( 'Connected', 'wpcalibrate-accounting-sync' ) : esc_html__( 'Disconnected', 'wpcalibrate-accounting-sync' ); ?>
			</span>
		</div>
		<div class="wpcalibrate-card-body">
			<ul class="wpcalibrate-meta-list">
				<li>
					<span class="wpcalibrate-meta-label"><?php esc_html_e( 'Environment:', 'wpcalibrate-accounting-sync' ); ?></span>
					<strong class="wpcalibrate-meta-value"><?php echo esc_html( ucfirst( $environment ) ); ?></strong>
				</li>
				<li>
					<span class="wpcalibrate-meta-label"><?php esc_html_e( 'QuickBooks Company ID:', 'wpcalibrate-accounting-sync' ); ?></span>
					<span class="wpcalibrate-meta-value"><?php echo ! empty( $realm_id ) ? esc_html( $realm_id ) : esc_html__( 'None', 'wpcalibrate-accounting-sync' ); ?></span>
				</li>
				<li>
					<span class="wpcalibrate-meta-label"><?php esc_html_e( 'Connected Since:', 'wpcalibrate-accounting-sync' ); ?></span>
					<span class="wpcalibrate-meta-value"><?php echo ! empty( $connected_at ) ? esc_html( $connected_at ) : esc_html__( 'N/A', 'wpcalibrate-accounting-sync' ); ?></span>
				</li>
			</ul>
		</div>
		<div class="wpcalibrate-card-footer">
			<?php if ( $is_connected ) : ?>
				<button type="button" class="button button-secondary wpcalibrate-test-connection-btn">
					<?php esc_html_e( 'Test Connection', 'wpcalibrate-accounting-sync' ); ?>
				</button>
			<?php else : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcalibrate-accounting-sync&tab=connection' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Connect QuickBooks Account', 'wpcalibrate-accounting-sync' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>

	<!-- Sync Configuration Overview Card -->
	<div class="wpcalibrate-card">
		<div class="wpcalibrate-card-header">
			<h2><?php esc_html_e( 'Synchronization State', 'wpcalibrate-accounting-sync' ); ?></h2>
			<span class="wpcalibrate-status-pill <?php echo ! empty( $general['sync_enabled'] ) ? 'wpcalibrate-pill-success' : 'wpcalibrate-pill-warning'; ?>">
				<?php echo ! empty( $general['sync_enabled'] ) ? esc_html__( 'Active', 'wpcalibrate-accounting-sync' ) : esc_html__( 'Paused', 'wpcalibrate-accounting-sync' ); ?>
			</span>
		</div>
		<div class="wpcalibrate-card-body">
			<ul class="wpcalibrate-meta-list">
				<li>
					<span class="wpcalibrate-meta-label"><?php esc_html_e( 'Accounting Mode:', 'wpcalibrate-accounting-sync' ); ?></span>
					<strong class="wpcalibrate-meta-value">
						<?php echo ( $general['accounting_mode'] ?? 'sales_receipt' ) === 'invoice_payment' ? esc_html__( 'Invoice + Payment', 'wpcalibrate-accounting-sync' ) : esc_html__( 'Sales Receipt', 'wpcalibrate-accounting-sync' ); ?>
					</strong>
				</li>
				<li>
					<span class="wpcalibrate-meta-label"><?php esc_html_e( 'Automatic Sync:', 'wpcalibrate-accounting-sync' ); ?></span>
					<span class="wpcalibrate-meta-value"><?php echo ! empty( $general['auto_sync'] ) ? esc_html__( 'Enabled (Async)', 'wpcalibrate-accounting-sync' ) : esc_html__( 'Disabled', 'wpcalibrate-accounting-sync' ); ?></span>
				</li>
				<li>
					<span class="wpcalibrate-meta-label"><?php esc_html_e( 'Inventory Sync:', 'wpcalibrate-accounting-sync' ); ?></span>
					<span class="wpcalibrate-meta-value"><?php echo esc_html( ucfirst( $general['inventory_sync_direction'] ?? 'disabled' ) ); ?></span>
				</li>
			</ul>
		</div>
		<div class="wpcalibrate-card-footer">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcalibrate-accounting-sync&tab=sync' ) ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Configure Settings', 'wpcalibrate-accounting-sync' ); ?>
			</a>
		</div>
	</div>

	<!-- Reconciliation Card -->
	<div class="wpcalibrate-card">
		<div class="wpcalibrate-card-header">
			<h2><?php esc_html_e( 'Reconciliation', 'wpcalibrate-accounting-sync' ); ?></h2>
			<span class="wpcalibrate-status-pill wpcalibrate-pill-info">
				<?php esc_html_e( 'Scheduled', 'wpcalibrate-accounting-sync' ); ?>
			</span>
		</div>
		<div class="wpcalibrate-card-body">
			<p class="description">
				<?php esc_html_e( 'Periodic safety net that catches any missed orders within a 48-hour sliding window.', 'wpcalibrate-accounting-sync' ); ?>
			</p>
			<ul class="wpcalibrate-meta-list">
				<li>
					<span class="wpcalibrate-meta-label"><?php esc_html_e( 'Last Run:', 'wpcalibrate-accounting-sync' ); ?></span>
					<span class="wpcalibrate-meta-value"><?php echo ! empty( $last_recon['timestamp'] ) ? esc_html( $last_recon['timestamp'] ) : esc_html__( 'Never', 'wpcalibrate-accounting-sync' ); ?></span>
				</li>
				<?php if ( ! empty( $last_recon ) ) : ?>
					<li>
						<span class="wpcalibrate-meta-label"><?php esc_html_e( 'Checked / Recovered:', 'wpcalibrate-accounting-sync' ); ?></span>
						<span class="wpcalibrate-meta-value"><?php echo esc_html( ( $last_recon['checked'] ?? 0 ) . ' / ' . ( $last_recon['recovered'] ?? 0 ) ); ?></span>
					</li>
				<?php endif; ?>
			</ul>
		</div>
		<div class="wpcalibrate-card-footer">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpcalibrate_sync_run_reconciliation" />
				<?php wp_nonce_field( 'wpcalibrate_sync_reconcile_action' ); ?>
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Reconcile Now', 'wpcalibrate-accounting-sync' ); ?>
				</button>
			</form>
		</div>
	</div>
</div>

<!-- Purpose & Overview Section -->
<div class="wpcalibrate-panel wpcalibrate-guide-section">
	<div class="wpcalibrate-guide-header">
		<span class="dashicons dashicons-info wpcalibrate-guide-icon" aria-hidden="true"></span>
		<div>
			<h2><?php esc_html_e( 'Purpose of WPCalibrate Accounting Sync', 'wpcalibrate-accounting-sync' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'WPCalibrate Accounting Sync provides an automated, enterprise-grade bridge between WooCommerce and Intuit QuickBooks Online.', 'wpcalibrate-accounting-sync' ); ?>
			</p>
		</div>
	</div>
	<div class="wpcalibrate-guide-content">
		<p>
			<?php esc_html_e( 'Managing e-commerce books manually often causes bookkeeping errors, inventory discrepancies, and wasted administrative hours. This plugin solves those challenges by transforming WooCommerce order events directly into compliant QuickBooks accounting entries in real time, with 100% data traceability and zero alterations to WordPress core files.', 'wpcalibrate-accounting-sync' ); ?>
		</p>
		<div class="wpcalibrate-features-grid">
			<div class="wpcalibrate-feature-item">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<div>
					<strong><?php esc_html_e( 'Zero Double-Entry Overhead', 'wpcalibrate-accounting-sync' ); ?></strong>
					<p><?php esc_html_e( 'Orders, payments, refunds, taxes, and fees sync automatically in the background.', 'wpcalibrate-accounting-sync' ); ?></p>
				</div>
			</div>
			<div class="wpcalibrate-feature-item">
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<div>
					<strong><?php esc_html_e( 'Bank-Grade Security', 'wpcalibrate-accounting-sync' ); ?></strong>
					<p><?php esc_html_e( 'OAuth 2.0 tokens are encrypted using AES-256-GCM. Financial PII and card data are scrubbed before logging.', 'wpcalibrate-accounting-sync' ); ?></p>
				</div>
			</div>
			<div class="wpcalibrate-feature-item">
				<span class="dashicons dashicons-update" aria-hidden="true"></span>
				<div>
					<strong><?php esc_html_e( 'Two-Way Inventory Sync', 'wpcalibrate-accounting-sync' ); ?></strong>
					<p><?php esc_html_e( 'Keep product stock quantities synchronized across both platforms with automated loop prevention.', 'wpcalibrate-accounting-sync' ); ?></p>
				</div>
			</div>
			<div class="wpcalibrate-feature-item">
				<span class="dashicons dashicons-database" aria-hidden="true"></span>
				<div>
					<strong><?php esc_html_e( 'HPOS & WooCommerce 10+ Ready', 'wpcalibrate-accounting-sync' ); ?></strong>
					<p><?php esc_html_e( 'Built with pure WooCommerce CRUD, fully compatible with High-Performance Order Storage (HPOS).', 'wpcalibrate-accounting-sync' ); ?></p>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- How to Use: Step-by-Step Workflow Guide -->
<div class="wpcalibrate-panel wpcalibrate-guide-section">
	<div class="wpcalibrate-guide-header">
		<span class="dashicons dashicons-controls-forward wpcalibrate-guide-icon" aria-hidden="true"></span>
		<div>
			<h2><?php esc_html_e( 'How to Use: 5-Step Workflow Guide', 'wpcalibrate-accounting-sync' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Follow these sequential steps to configure, test, and activate seamless accounting synchronization.', 'wpcalibrate-accounting-sync' ); ?>
			</p>
		</div>
	</div>

	<div class="wpcalibrate-steps-list">
		<!-- Step 1 -->
		<div class="wpcalibrate-step-card">
			<div class="wpcalibrate-step-badge">1</div>
			<div class="wpcalibrate-step-details">
				<h3><?php esc_html_e( 'Step 1: Connect Your QuickBooks Account', 'wpcalibrate-accounting-sync' ); ?></h3>
				<p>
					<?php esc_html_e( 'Start on the Connection tab. Choose whether you are testing on Sandbox or connecting a live Production company. Enter your Intuit Client ID and Client Secret, then click "Connect to QuickBooks" to authorize via official OAuth 2.0.', 'wpcalibrate-accounting-sync' ); ?>
				</p>
			</div>
			<div class="wpcalibrate-step-action">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcalibrate-accounting-sync&tab=connection' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Go to Connection', 'wpcalibrate-accounting-sync' ); ?> &rarr;
				</a>
			</div>
		</div>

		<!-- Step 2 -->
		<div class="wpcalibrate-step-card">
			<div class="wpcalibrate-step-badge">2</div>
			<div class="wpcalibrate-step-details">
				<h3><?php esc_html_e( 'Step 2: Choose Accounting Mode & Rules', 'wpcalibrate-accounting-sync' ); ?></h3>
				<p>
					<?php esc_html_e( 'Navigate to Sync Settings to choose your accounting workflow: "Sales Receipt" (single-entry for paid consumer orders) or "Invoice + Payment" (ideal for B2B stores or orders requiring formal invoices). Enable automated background sync and configure inventory sync direction.', 'wpcalibrate-accounting-sync' ); ?>
				</p>
			</div>
			<div class="wpcalibrate-step-action">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcalibrate-accounting-sync&tab=sync' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Configure Sync Settings', 'wpcalibrate-accounting-sync' ); ?> &rarr;
				</a>
			</div>
		</div>

		<!-- Step 3 -->
		<div class="wpcalibrate-step-card">
			<div class="wpcalibrate-step-badge">3</div>
			<div class="wpcalibrate-step-details">
				<h3><?php esc_html_e( 'Step 3: Map Accounts, Taxes & Payment Gateways', 'wpcalibrate-accounting-sync' ); ?></h3>
				<p>
					<?php esc_html_e( 'Open the Mapping tab to align your store financial entities. Map WooCommerce tax rates to QBO TaxCodes, assign deposit accounts for each payment gateway (e.g., Stripe, PayPal, COD), and specify income accounts for shipping fees and unmapped items.', 'wpcalibrate-accounting-sync' ); ?>
				</p>
			</div>
			<div class="wpcalibrate-step-action">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcalibrate-accounting-sync&tab=mapping' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Setup Account Mappings', 'wpcalibrate-accounting-sync' ); ?> &rarr;
				</a>
			</div>
		</div>

		<!-- Step 4 -->
		<div class="wpcalibrate-step-card">
			<div class="wpcalibrate-step-badge">4</div>
			<div class="wpcalibrate-step-details">
				<h3><?php esc_html_e( 'Step 4: Run Dry Run & Historical Sync', 'wpcalibrate-accounting-sync' ); ?></h3>
				<p>
					<?php esc_html_e( 'If you have existing orders placed prior to installing the plugin, visit Historical Sync. First, run a safe "Dry Run" estimation to review eligible orders, then launch a non-blocking background batch to synchronize historical sales.', 'wpcalibrate-accounting-sync' ); ?>
				</p>
			</div>
			<div class="wpcalibrate-step-action">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcalibrate-accounting-sync&tab=historical' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Historical Sync', 'wpcalibrate-accounting-sync' ); ?> &rarr;
				</a>
			</div>
		</div>

		<!-- Step 5 -->
		<div class="wpcalibrate-step-card">
			<div class="wpcalibrate-step-badge">5</div>
			<div class="wpcalibrate-step-details">
				<h3><?php esc_html_e( 'Step 5: Monitor Audit Logs & Reconciliation', 'wpcalibrate-accounting-sync' ); ?></h3>
				<p>
					<?php esc_html_e( 'Every synchronization attempt, webhook, and background worker job is recorded in the Logs tab with redactors in place. The built-in 48-hour reconciliation safety net automatically recovers any orders affected by external network interruptions.', 'wpcalibrate-accounting-sync' ); ?>
				</p>
			</div>
			<div class="wpcalibrate-step-action">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpcalibrate-accounting-sync&tab=logs' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'View Logs', 'wpcalibrate-accounting-sync' ); ?> &rarr;
				</a>
			</div>
		</div>
	</div>
</div>

<!-- Method & Architecture Section -->
<div class="wpcalibrate-panel wpcalibrate-guide-section">
	<div class="wpcalibrate-guide-header">
		<span class="dashicons dashicons-admin-generic wpcalibrate-guide-icon" aria-hidden="true"></span>
		<div>
			<h2><?php esc_html_e( 'Method & Synchronization Mechanics', 'wpcalibrate-accounting-sync' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'An overview of the engineering architecture and reliability controls powering the integration.', 'wpcalibrate-accounting-sync' ); ?>
			</p>
		</div>
	</div>

	<div class="wpcalibrate-method-grid">
		<div class="wpcalibrate-method-card">
			<h4>
				<span class="dashicons dashicons-performance" aria-hidden="true"></span>
				<?php esc_html_e( 'Asynchronous Action Scheduler', 'wpcalibrate-accounting-sync' ); ?>
			</h4>
			<p>
				<?php esc_html_e( 'API calls to QuickBooks Online are dispatched asynchronously via WooCommerce Action Scheduler. Customer checkout completes instantly without being blocked by third-party network latency.', 'wpcalibrate-accounting-sync' ); ?>
			</p>
		</div>

		<div class="wpcalibrate-method-card">
			<h4>
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<?php esc_html_e( 'Idempotent Ledger & Mutex Locks', 'wpcalibrate-accounting-sync' ); ?>
			</h4>
			<p>
				<?php esc_html_e( 'Every entity mapping is tracked with a payload hash in a dedicated ledger table. Microsecond mutex locks guarantee that simultaneous status changes cannot produce duplicate invoices or double payments.', 'wpcalibrate-accounting-sync' ); ?>
			</p>
		</div>

		<div class="wpcalibrate-method-card">
			<h4>
				<span class="dashicons dashicons-randomize" aria-hidden="true"></span>
				<?php esc_html_e( 'Two-Way Loop Prevention', 'wpcalibrate-accounting-sync' ); ?>
			</h4>
			<p>
				<?php esc_html_e( 'When inventory is adjusted from QuickBooks, a transient origin marker is attached to the WooCommerce stock update to ensure the change is not echoed back to QuickBooks in an infinite update loop.', 'wpcalibrate-accounting-sync' ); ?>
			</p>
		</div>

		<div class="wpcalibrate-method-card">
			<h4>
				<span class="dashicons dashicons-backup" aria-hidden="true"></span>
				<?php esc_html_e( 'Sliding-Window Reconciliation', 'wpcalibrate-accounting-sync' ); ?>
			</h4>
			<p>
				<?php esc_html_e( 'A background health checker periodically scans orders across a 48-hour sliding window. If an order missed background processing during server maintenance, reconciliation automatically schedules it for sync.', 'wpcalibrate-accounting-sync' ); ?>
			</p>
		</div>
	</div>
</div>

