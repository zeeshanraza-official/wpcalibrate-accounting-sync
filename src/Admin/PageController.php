<?php
/**
 * Admin Page & Tab Controller.
 *
 * @package WPCalibrate\AccountingSync\Admin
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Admin;

use WPCalibrate\AccountingSync\Auth\TokenManager;
use WPCalibrate\AccountingSync\Auth\OAuthClient;
use WPCalibrate\AccountingSync\Auth\StateManager;
use WPCalibrate\AccountingSync\Logging\Logger;

/**
 * Routes tabs and renders plugin administration views.
 */
final class PageController {

	public const PAGE_SLUG = 'wpcalibrate-accounting-sync';

	private bool $is_woocommerce_active;

	/**
	 * Constructor.
	 *
	 * @param bool $is_woocommerce_active WC active status.
	 */
	public function __construct( bool $is_woocommerce_active ) {
		$this->is_woocommerce_active = $is_woocommerce_active;
	}

	/**
	 * Hook registrations.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init', [ $this, 'handle_oauth_callback' ] );
	}

	/**
	 * Enqueues scoped admin CSS and JS only on this plugin's admin screens.
	 *
	 * @param string $hook Screen hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		// Strict screen check: only load on our settings submenu.
		if ( ! str_contains( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'wpcalibrate-accounting-sync-admin',
			WPCALIBRATE_ACCOUNTING_SYNC_URL . 'assets/css/admin.css',
			[],
			WPCALIBRATE_ACCOUNTING_SYNC_VERSION
		);

		wp_enqueue_script(
			'wpcalibrate-accounting-sync-admin',
			WPCALIBRATE_ACCOUNTING_SYNC_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WPCALIBRATE_ACCOUNTING_SYNC_VERSION,
			true
		);

		wp_enqueue_script(
			'wpcalibrate-accounting-sync-historical',
			WPCALIBRATE_ACCOUNTING_SYNC_URL . 'assets/js/historical-sync.js',
			[ 'jquery' ],
			WPCALIBRATE_ACCOUNTING_SYNC_VERSION,
			true
		);

		wp_localize_script(
			'wpcalibrate-accounting-sync-admin',
			'wpcalibrateSyncData',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wpcalibrate_sync_ajax_nonce' ),
				'copiedMsg' => __( 'Copied to clipboard!', 'wpcalibrate-accounting-sync' ),
			]
		);
	}

	/**
	 * Handles Intuit OAuth 2.0 redirect callback.
	 *
	 * @return void
	 */
	public function handle_oauth_callback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== self::PAGE_SLUG ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) || empty( $_GET['realmId'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_accounting_sync' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized user.', 'wpcalibrate-accounting-sync' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state    = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$realm_id = sanitize_text_field( wp_unslash( $_GET['realmId'] ) );

		$verified_env = StateManager::validate_state( $state );
		if ( ! $verified_env ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page'   => self::PAGE_SLUG,
						'tab'    => 'connection',
						'status' => 'error',
						'msg'    => rawurlencode( __( 'Invalid or expired OAuth state parameter. Please try connecting again.', 'wpcalibrate-accounting-sync' ) ),
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$conn_key    = 'wpcalibrate_accounting_sync_connection_' . sanitize_key( $verified_env );
		$conn_opt    = get_option( $conn_key, [] );
		$client_id   = (string) ( $conn_opt['client_id'] ?? '' );
		$client_sec  = (string) ( $conn_opt['client_secret'] ?? '' );

		try {
			OAuthClient::exchange_code_for_tokens( $verified_env, $client_id, $client_sec, $code, $realm_id );
			wp_safe_redirect(
				add_query_arg(
					[
						'page'   => self::PAGE_SLUG,
						'tab'    => 'connection',
						'status' => 'success',
						'msg'    => rawurlencode( __( 'QuickBooks company successfully connected!', 'wpcalibrate-accounting-sync' ) ),
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		} catch ( \Exception $e ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page'   => self::PAGE_SLUG,
						'tab'    => 'connection',
						'status' => 'error',
						'msg'    => rawurlencode( $e->getMessage() ),
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Main admin page renderer.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_accounting_sync' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wpcalibrate-accounting-sync' ) );
		}

		$allowed_tabs = [
			'overview'     => __( 'Overview', 'wpcalibrate-accounting-sync' ),
			'connection'   => __( 'Connection', 'wpcalibrate-accounting-sync' ),
			'sync'         => __( 'Sync Settings', 'wpcalibrate-accounting-sync' ),
			'mapping'      => __( 'Mapping', 'wpcalibrate-accounting-sync' ),
			'historical'   => __( 'Historical Sync', 'wpcalibrate-accounting-sync' ),
			'logs'         => __( 'Logs', 'wpcalibrate-accounting-sync' ),
			'diagnostics'  => __( 'Diagnostics', 'wpcalibrate-accounting-sync' ),
			'license'      => __( 'License', 'wpcalibrate-accounting-sync' ),
			'support'      => __( 'Support', 'wpcalibrate-accounting-sync' ),
		];

		// Template mapping dictionary ensuring clean separation of tab slugs and file names.
		$tab_templates = [
			'overview'        => 'overview.php',
			'connection'      => 'connection.php',
			'sync'            => 'sync-settings.php',
			'sync-settings'   => 'sync-settings.php',
			'mapping'         => 'mapping.php',
			'historical'      => 'historical-sync.php',
			'historical-sync' => 'historical-sync.php',
			'logs'            => 'logs.php',
			'diagnostics'     => 'diagnostics.php',
			'license'         => 'license.php',
			'support'         => 'support.php',
		];

		// Normalize tab alias if user navigated via hyphenated slug.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		if ( 'sync-settings' === $raw_tab ) {
			$raw_tab = 'sync';
		} elseif ( 'historical-sync' === $raw_tab ) {
			$raw_tab = 'historical';
		}

		$active_tab = array_key_exists( $raw_tab, $allowed_tabs ) ? $raw_tab : 'overview';

		$general_settings = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$environment      = ! empty( $general_settings['environment'] ) ? (string) $general_settings['environment'] : 'sandbox';
		$is_connected     = TokenManager::is_connected( $environment );

		?>
		<div class="wrap wpcalibrate-admin-wrap" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
			<header class="wpcalibrate-header">
				<div class="wpcalibrate-header-brand">
					<img src="<?php echo esc_url( WPCALIBRATE_ACCOUNTING_SYNC_URL . 'branding/icon-dark.png' ); ?>" alt="WPCalibrate" class="wpcalibrate-logo" />
					<div class="wpcalibrate-title-group">
						<h1 class="wpcalibrate-title"><?php esc_html_e( 'WPCalibrate Accounting Sync', 'wpcalibrate-accounting-sync' ); ?></h1>
						<span class="wpcalibrate-version"><?php echo esc_html( 'v' . WPCALIBRATE_ACCOUNTING_SYNC_VERSION ); ?></span>
					</div>
				</div>
				<div class="wpcalibrate-env-badge wpcalibrate-env-<?php echo esc_attr( $environment ); ?>" aria-label="<?php esc_attr_e( 'Active QuickBooks Environment', 'wpcalibrate-accounting-sync' ); ?>">
					<span class="wpcalibrate-badge-dot"></span>
					<?php echo esc_html( strtoupper( $environment ) ); ?>
				</div>
			</header>

			<?php
			// Render query notice if passed.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['status'] ) && ! empty( $_GET['msg'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$status = sanitize_key( wp_unslash( $_GET['status'] ) );
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$msg    = sanitize_text_field( wp_unslash( rawurldecode( $_GET['msg'] ) ) );
				$class  = 'success' === $status ? 'notice-success' : 'notice-error';
				printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $msg ) );
			}
			?>

			<nav class="nav-tab-wrapper wpcalibrate-nav-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings Navigation', 'wpcalibrate-accounting-sync' ); ?>">
				<?php foreach ( $allowed_tabs as $tab_key => $tab_label ) : ?>
					<?php
					$tab_url = add_query_arg(
						[
							'page' => self::PAGE_SLUG,
							'tab'  => $tab_key,
						],
						admin_url( 'admin.php' )
					);
					$is_active = ( $active_tab === $tab_key );
					?>
					<a href="<?php echo esc_url( $tab_url ); ?>"
					   class="nav-tab <?php echo $is_active ? 'nav-tab-active' : ''; ?>"
					   role="tab"
					   aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<main class="wpcalibrate-tab-content" role="tabpanel">
				<?php
				$template_filename = $tab_templates[ $active_tab ] ?? ( $active_tab . '.php' );
				$view_file         = WPCALIBRATE_ACCOUNTING_SYNC_PATH . 'src/Admin/Views/' . $template_filename;

				if ( file_exists( $view_file ) ) {
					require $view_file;
				} else {
					echo '<p>' . esc_html__( 'View template not found.', 'wpcalibrate-accounting-sync' ) . '</p>';
				}
				?>
			</main>
		</div>
		<?php
	}
}
