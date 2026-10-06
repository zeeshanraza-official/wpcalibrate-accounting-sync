<?php
/**
 * GitHub-based Update Checker for WordPress Dashboard updates.
 *
 * @package WPCalibrate\AccountingSync\Admin
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class GitHubUpdater
 */
class GitHubUpdater {

	/**
	 * Main plugin file path.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plugin basename (e.g. wpcalibrate-accounting-sync/wpcalibrate-accounting-sync.php).
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * Plugin slug (e.g. wpcalibrate-accounting-sync).
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * GitHub repository owner.
	 *
	 * @var string
	 */
	private string $repo_owner;

	/**
	 * GitHub repository name.
	 *
	 * @var string
	 */
	private string $repo_name;

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private string $current_version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Main plugin file.
	 * @param string $repo_owner  GitHub repository owner.
	 * @param string $repo_name   GitHub repository name.
	 */
	public function __construct(
		string $plugin_file = '',
		string $repo_owner = 'zeeshanraza-official',
		string $repo_name = 'wpcalibrate-accounting-sync'
	) {
		$this->plugin_file     = ! empty( $plugin_file ) ? $plugin_file : ( defined( 'WPCALIBRATE_ACCOUNTING_SYNC_FILE' ) ? WPCALIBRATE_ACCOUNTING_SYNC_FILE : __FILE__ );
		$this->plugin_basename = function_exists( 'plugin_basename' ) ? plugin_basename( $this->plugin_file ) : 'wpcalibrate-accounting-sync/wpcalibrate-accounting-sync.php';
		$this->slug            = dirname( $this->plugin_basename );
		$this->repo_owner      = $repo_owner;
		$this->repo_name       = $repo_name;
		$this->current_version = defined( 'WPCALIBRATE_ACCOUNTING_SYNC_VERSION' ) ? WPCALIBRATE_ACCOUNTING_SYNC_VERSION : '1.0.0';
	}

	/**
	 * Register update hooks into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugins_api' ], 20, 3 );
		add_filter( 'upgrader_post_install', [ $this, 'post_install' ], 10, 3 );
	}

	/**
	 * Check for updates against GitHub Releases API.
	 *
	 * @param mixed $transient Plugins update transient.
	 * @return mixed
	 */
	public function check_update( mixed $transient ): mixed {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release || empty( $release['tag_name'] ) ) {
			return $transient;
		}

		$new_version = ltrim( (string) $release['tag_name'], 'vV' );

		if ( version_compare( $new_version, $this->current_version, '>' ) ) {
			$download_url = $this->get_package_url( $release );

			$item = (object) [
				'id'            => $this->plugin_basename,
				'slug'          => $this->slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $new_version,
				'url'           => $release['html_url'] ?? "https://github.com/{$this->repo_owner}/{$this->repo_name}",
				'package'       => $download_url,
				'icons'         => [
					'1x' => defined( 'WPCALIBRATE_ACCOUNTING_SYNC_URL' ) ? WPCALIBRATE_ACCOUNTING_SYNC_URL . 'branding/icon-128x128.png' : '',
					'2x' => defined( 'WPCALIBRATE_ACCOUNTING_SYNC_URL' ) ? WPCALIBRATE_ACCOUNTING_SYNC_URL . 'branding/icon-256x256.png' : '',
				],
				'banners'       => [
					'low'  => defined( 'WPCALIBRATE_ACCOUNTING_SYNC_URL' ) ? WPCALIBRATE_ACCOUNTING_SYNC_URL . 'branding/banner-772x250.png' : '',
					'high' => defined( 'WPCALIBRATE_ACCOUNTING_SYNC_URL' ) ? WPCALIBRATE_ACCOUNTING_SYNC_URL . 'branding/banner-1544x500.png' : '',
				],
				'requires'      => '6.4',
				'tested'        => '7.1.2',
				'requires_php'  => '8.2',
			];

			$transient->response[ $this->plugin_basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Filter plugins_api to show details popup in WP admin.
	 *
	 * @param mixed  $result Current result.
	 * @param string $action API action.
	 * @param object $args   Arguments.
	 * @return mixed
	 */
	public function plugins_api( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		$version = $release && ! empty( $release['tag_name'] ) ? ltrim( (string) $release['tag_name'], 'vV' ) : $this->current_version;

		return (object) [
			'name'          => 'WPCalibrate Accounting Sync',
			'slug'          => $this->slug,
			'version'       => $version,
			'author'        => '<a href="https://wpcalibrate.com">WPCalibrate</a>',
			'homepage'      => "https://github.com/{$this->repo_owner}/{$this->repo_name}",
			'requires'      => '6.4',
			'tested'        => '7.1.2',
			'requires_php'  => '8.2',
			'download_link' => $this->get_package_url( $release ),
			'sections'      => [
				'description' => esc_html__( 'Connects WooCommerce with QuickBooks Online for automatic, reliable accounting synchronization without modifying core files.', 'wpcalibrate-accounting-sync' ),
				'changelog'   => ! empty( $release['body'] ) ? nl2br( esc_html( (string) $release['body'] ) ) : esc_html__( 'See GitHub release notes for details.', 'wpcalibrate-accounting-sync' ),
			],
			'banners'       => [
				'low'  => defined( 'WPCALIBRATE_ACCOUNTING_SYNC_URL' ) ? WPCALIBRATE_ACCOUNTING_SYNC_URL . 'branding/banner-772x250.png' : '',
				'high' => defined( 'WPCALIBRATE_ACCOUNTING_SYNC_URL' ) ? WPCALIBRATE_ACCOUNTING_SYNC_URL . 'branding/banner-1544x500.png' : '',
			],
		];
	}

	/**
	 * Ensure correct directory naming after update installation.
	 *
	 * @param bool  $response   Installation response.
	 * @param array $hook_extra Extra hook parameters.
	 * @param array $result     Installation result.
	 * @return array
	 */
	public function post_install( bool $response, array $hook_extra, array $result ): array {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $result;
		}

		$proper_destination = ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' ) . '/' . $this->slug;
		if ( isset( $result['destination'] ) && $result['destination'] !== $proper_destination && $wp_filesystem ) {
			$wp_filesystem->move( $result['destination'], $proper_destination );
			$result['destination'] = $proper_destination;
		}

		return $result;
	}

	/**
	 * Get the download URL for the package from release assets or zipball.
	 *
	 * @param array|null $release GitHub release payload.
	 * @return string
	 */
	private function get_package_url( ?array $release ): string {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! empty( $asset['name'] ) && str_ends_with( (string) $asset['name'], '.zip' ) && ! empty( $asset['browser_download_url'] ) ) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return (string) ( $release['zipball_url'] ?? "https://github.com/{$this->repo_owner}/{$this->repo_name}/archive/refs/heads/main.zip" );
	}

	/**
	 * Fetch latest release from GitHub API with caching.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_latest_release(): ?array {
		$cache_key = 'wpcalibrate_accounting_sync_gh_release';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$url      = "https://api.github.com/repos/{$this->repo_owner}/{$this->repo_name}/releases/latest";
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . ( $GLOBALS['wp_version'] ?? '6.4' ) . '; WPCalibrate/' . $this->current_version,
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache failure for 15 minutes to avoid excessive external requests.
			if ( defined( 'MINUTE_IN_SECONDS' ) ) {
				set_transient( $cache_key, [], 15 * MINUTE_IN_SECONDS );
			}
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
			if ( defined( 'HOUR_IN_SECONDS' ) ) {
				set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );
			}
			return $data;
		}

		return null;
	}
}
