<?php
/**
 * Admin Mapping View.
 *
 * @package WPCalibrate\AccountingSync\Admin\Views
 */

declare(strict_types=1);

use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Mapping\MappingService;
use WPCalibrate\AccountingSync\Database\MappingRepository;

$general     = get_option( 'wpcalibrate_accounting_sync_general', [] );
$environment = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';
$rules       = get_option( 'wpcalibrate_accounting_sync_mapping_rules', [] );

$is_connected = TokenManager::is_connected( $environment );
$tokens       = TokenManager::get_tokens( $environment );
$realm_id     = $tokens['realm_id'] ?? '';

// Retrieve QBO Reference entities.
$accounts        = $is_connected ? MappingService::get_accounts( $environment ) : [];
$items           = $is_connected ? MappingService::get_items( $environment ) : [];
$tax_codes       = $is_connected ? MappingService::get_tax_codes( $environment ) : [];
$payment_methods = $is_connected ? MappingService::get_payment_methods( $environment ) : [];

// Retrieve WooCommerce Payment Gateways.
$wc_gateways = [];
if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
	$wc_gateways = WC()->payment_gateways()->payment_gateways();
}

// Retrieve WooCommerce Tax Classes.
$wc_tax_classes = function_exists( 'wc_get_product_tax_class_options' ) ? wc_get_product_tax_class_options() : [ 'standard' => 'Standard' ];

// Paginated Mappings list.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$limit        = 15;
$offset       = ( $current_page - 1 ) * $limit;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$search       = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

$mappings_data = MappingRepository::get_paginated( $environment, $realm_id, '', $limit, $offset, $search );
$total_pages   = ceil( $mappings_data['total'] / $limit );
?>

<div class="wpcalibrate-panel">
	<div class="wpcalibrate-panel-header-actions">
		<h2><?php esc_html_e( 'Accounting & Entity Mappings', 'wpcalibrate-accounting-sync' ); ?></h2>
		<?php if ( $is_connected ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="wpcalibrate_sync_refresh_reference_data" />
				<?php wp_nonce_field( 'wpcalibrate_sync_refresh_reference_action' ); ?>
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Refresh QuickBooks Reference Data', 'wpcalibrate-accounting-sync' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( ! $is_connected ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Connect your QuickBooks Online account in the Connection tab to load live Accounts, Tax Codes, and Items.', 'wpcalibrate-accounting-sync' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcalibrate-form">
		<input type="hidden" name="action" value="wpcalibrate_sync_save_mapping" />
		<?php wp_nonce_field( 'wpcalibrate_sync_save_mapping_action' ); ?>

		<h3><?php esc_html_e( 'Default Accounts & Items', 'wpcalibrate-accounting-sync' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="default_deposit_account_id"><?php esc_html_e( 'Default Deposit Account', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<select name="default_deposit_account_id" id="default_deposit_account_id">
						<option value=""><?php esc_html_e( '— Select QuickBooks Account —', 'wpcalibrate-accounting-sync' ); ?></option>
						<?php foreach ( $accounts as $acc ) : ?>
							<option value="<?php echo esc_attr( $acc['id'] ); ?>" <?php selected( (string) ( $rules['default_deposit_account_id'] ?? '' ), $acc['id'] ); ?>>
								<?php echo esc_html( $acc['name'] . ' (' . $acc['type'] . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Default bank or asset account for depositing receipts/payments when no gateway override exists.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="default_income_account_id"><?php esc_html_e( 'Default Income Account', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<select name="default_income_account_id" id="default_income_account_id">
						<option value=""><?php esc_html_e( '— Select QuickBooks Account —', 'wpcalibrate-accounting-sync' ); ?></option>
						<?php foreach ( $accounts as $acc ) : ?>
							<option value="<?php echo esc_attr( $acc['id'] ); ?>" <?php selected( (string) ( $rules['default_income_account_id'] ?? '' ), $acc['id'] ); ?>>
								<?php echo esc_html( $acc['name'] . ' (' . $acc['type'] . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Income account assigned when auto-creating new products in QuickBooks.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="fallback_item_id"><?php esc_html_e( 'Fallback / Default Item', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<select name="fallback_item_id" id="fallback_item_id">
						<option value=""><?php esc_html_e( '— Select QuickBooks Item —', 'wpcalibrate-accounting-sync' ); ?></option>
						<?php foreach ( $items as $it ) : ?>
							<option value="<?php echo esc_attr( $it['id'] ); ?>" <?php selected( (string) ( $rules['fallback_item_id'] ?? '' ), $it['id'] ); ?>>
								<?php echo esc_html( $it['name'] . ' (' . $it['type'] . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Item used when an order line refers to an unmapped or deleted historical product.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="shipping_item_id"><?php esc_html_e( 'Shipping Charge Item', 'wpcalibrate-accounting-sync' ); ?></label></th>
				<td>
					<select name="shipping_item_id" id="shipping_item_id">
						<option value=""><?php esc_html_e( '— Select QuickBooks Item —', 'wpcalibrate-accounting-sync' ); ?></option>
						<?php foreach ( $items as $it ) : ?>
							<option value="<?php echo esc_attr( $it['id'] ); ?>" <?php selected( (string) ( $rules['shipping_item_id'] ?? '' ), $it['id'] ); ?>>
								<?php echo esc_html( $it['name'] . ' (' . $it['type'] . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Item used to represent order shipping line amounts in QuickBooks.', 'wpcalibrate-accounting-sync' ); ?></p>
				</td>
			</tr>
		</table>

		<hr class="wpcalibrate-divider" />

		<!-- Payment Gateway Mapping -->
		<h3><?php esc_html_e( 'Payment Gateway Mappings', 'wpcalibrate-accounting-sync' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'WooCommerce Gateway', 'wpcalibrate-accounting-sync' ); ?></th>
					<th><?php esc_html_e( 'QuickBooks Payment Method', 'wpcalibrate-accounting-sync' ); ?></th>
					<th><?php esc_html_e( 'Specific Deposit Account Override', 'wpcalibrate-accounting-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $wc_gateways ) ) : ?>
					<?php foreach ( $wc_gateways as $gateway_id => $gateway ) : ?>
						<?php
						$saved_method  = $rules['payment_method_map'][ $gateway_id ] ?? '';
						$saved_account = $rules['payment_account_map'][ $gateway_id ] ?? '';
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $gateway->get_title() ); ?></strong>
								<code>(<?php echo esc_html( $gateway_id ); ?>)</code>
							</td>
							<td>
								<select name="payment_method_map[<?php echo esc_attr( $gateway_id ); ?>]">
									<option value=""><?php esc_html_e( '— Default —', 'wpcalibrate-accounting-sync' ); ?></option>
									<?php foreach ( $payment_methods as $pm ) : ?>
										<option value="<?php echo esc_attr( $pm['id'] ); ?>" <?php selected( (string) $saved_method, $pm['id'] ); ?>>
											<?php echo esc_html( $pm['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select name="payment_account_map[<?php echo esc_attr( $gateway_id ); ?>]">
									<option value=""><?php esc_html_e( '— Use Default Deposit Account —', 'wpcalibrate-accounting-sync' ); ?></option>
									<?php foreach ( $accounts as $acc ) : ?>
										<option value="<?php echo esc_attr( $acc['id'] ); ?>" <?php selected( (string) $saved_account, $acc['id'] ); ?>>
											<?php echo esc_html( $acc['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="3"><?php esc_html_e( 'No active WooCommerce gateways detected.', 'wpcalibrate-accounting-sync' ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<hr class="wpcalibrate-divider" />

		<!-- Tax Mapping -->
		<h3><?php esc_html_e( 'Tax Code Mappings', 'wpcalibrate-accounting-sync' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'WooCommerce Tax Class', 'wpcalibrate-accounting-sync' ); ?></th>
					<th><?php esc_html_e( 'QuickBooks Tax Code', 'wpcalibrate-accounting-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $wc_tax_classes as $class_slug => $class_title ) : ?>
					<?php
					$tax_key   = sanitize_key( $class_slug ?: 'standard' );
					$saved_tax = $rules['tax_map'][ $tax_key ] ?? '';
					?>
					<tr>
						<td><strong><?php echo esc_html( $class_title ); ?></strong> <code>(<?php echo esc_html( $tax_key ); ?>)</code></td>
						<td>
							<select name="tax_map[<?php echo esc_attr( $tax_key ); ?>]">
								<option value=""><?php esc_html_e( '— None / Out of Scope —', 'wpcalibrate-accounting-sync' ); ?></option>
								<?php foreach ( $tax_codes as $tc ) : ?>
									<option value="<?php echo esc_attr( $tc['id'] ); ?>" <?php selected( (string) $saved_tax, $tc['id'] ); ?>>
										<?php echo esc_html( $tc['name'] . ( $tc['description'] ? ' - ' . $tc['description'] : '' ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save All Mappings', 'wpcalibrate-accounting-sync' ); ?></button>
		</p>
	</form>

	<hr class="wpcalibrate-divider" />

	<!-- Active Synchronized Entity Mappings Registry -->
	<h3><?php esc_html_e( 'Synchronized Entity Ledger', 'wpcalibrate-accounting-sync' ); ?></h3>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'WC Entity', 'wpcalibrate-accounting-sync' ); ?></th>
				<th><?php esc_html_e( 'WC ID', 'wpcalibrate-accounting-sync' ); ?></th>
				<th><?php esc_html_e( 'QuickBooks Type', 'wpcalibrate-accounting-sync' ); ?></th>
				<th><?php esc_html_e( 'QuickBooks ID', 'wpcalibrate-accounting-sync' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wpcalibrate-accounting-sync' ); ?></th>
				<th><?php esc_html_e( 'Last Synchronized', 'wpcalibrate-accounting-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $mappings_data['items'] ) ) : ?>
				<?php foreach ( $mappings_data['items'] as $map_row ) : ?>
					<tr>
						<td><?php echo esc_html( ucfirst( $map_row->wc_entity_type ) ); ?></td>
						<td><code>#<?php echo esc_html( (string) $map_row->wc_entity_id ); ?></code></td>
						<td><?php echo esc_html( $map_row->qbo_entity_type ); ?></td>
						<td><code>#<?php echo esc_html( $map_row->qbo_entity_id ); ?></code></td>
						<td><span class="wpcalibrate-status-pill wpcalibrate-pill-success"><?php echo esc_html( $map_row->last_sync_status ); ?></span></td>
						<td><?php echo esc_html( $map_row->last_sync_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="6"><?php esc_html_e( 'No synchronized entity records recorded yet.', 'wpcalibrate-accounting-sync' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
