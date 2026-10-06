<?php
/**
 * Shared WPCalibrate Menu Manager.
 *
 * @package WPCalibrate\AccountingSync\Admin
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Admin;

/**
 * Handles creation and re-use of the top-level WPCalibrate menu and namespaced submenu.
 */
final class MenuManager {

	/**
	 * Top-level parent menu slug.
	 *
	 * @var string
	 */
	public const PARENT_SLUG = 'wpcalibrate';

	/**
	 * Accounting Sync submenu slug.
	 *
	 * @var string
	 */
	public const SUBMENU_SLUG = 'wpcalibrate-accounting-sync';

	/**
	 * Hook registrations.
	 *
	 * @return void
	 */
	public function init(): void {
		// Priority 9 allows the first active WPCalibrate plugin to register the parent menu cleanly.
		add_action( 'admin_menu', [ $this, 'register_menus' ], 9 );
		// Clean up the duplicate parent sub-item if necessary on priority 99.
		add_action( 'admin_menu', [ $this, 'normalize_parent_submenu' ], 99 );
		// Ensure WordPress admin sidebar logo is strictly scaled to standard 20x20px.
		add_action( 'admin_head', [ $this, 'render_admin_menu_styles' ] );
	}

	/**
	 * Registers the top-level WPCalibrate menu (if not yet registered) and our submenu.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		global $menu;

		$capability = current_user_can( 'manage_accounting_sync' ) ? 'manage_accounting_sync' : 'manage_options';

		// Check if top-level 'wpcalibrate' is already registered by another WPCalibrate plugin.
		$parent_exists = false;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && $item[2] === self::PARENT_SLUG ) {
					$parent_exists = true;
					break;
				}
			}
		}

		if ( ! $parent_exists ) {
			// Register shared parent menu.
			add_menu_page(
				__( 'WPCalibrate', 'wpcalibrate-accounting-sync' ),
				__( 'WPCalibrate', 'wpcalibrate-accounting-sync' ),
				$capability,
				self::PARENT_SLUG,
				[ $this, 'render_parent_landing' ],
				WPCALIBRATE_ACCOUNTING_SYNC_URL . 'branding/icon-white.png',
				56.8
			);
		}

		// Register the dedicated WPCalibrate Accounting Sync submenu.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'WPCalibrate Accounting Sync', 'wpcalibrate-accounting-sync' ),
			__( 'Accounting Sync', 'wpcalibrate-accounting-sync' ),
			$capability,
			self::SUBMENU_SLUG,
			[ PageController::class, 'render_page' ]
		);
	}

	/**
	 * Normalizes parent submenu so clicking top-level redirects or lands cleanly on the first active item.
	 *
	 * @return void
	 */
	public function normalize_parent_submenu(): void {
		global $submenu;

		// If the top-level parent menu exists and has an auto-generated clone item, redirect it to our submenu if we own it.
		if ( isset( $submenu[ self::PARENT_SLUG ] ) && is_array( $submenu[ self::PARENT_SLUG ] ) ) {
			foreach ( $submenu[ self::PARENT_SLUG ] as $index => $sub_item ) {
				if ( isset( $sub_item[2] ) && $sub_item[2] === self::PARENT_SLUG ) {
					// Update title to 'Overview' or point it directly to our submenu if it's the sole item.
					if ( count( $submenu[ self::PARENT_SLUG ] ) === 2 ) {
						// Only parent clone + our submenu exist. Replace the clone slug with our submenu slug.
						$submenu[ self::PARENT_SLUG ][ $index ][2] = self::SUBMENU_SLUG;
					}
					break;
				}
			}
		}
	}

	/**
	 * Fallback landing callback for top-level menu if accessed directly.
	 *
	 * @return void
	 */
	public function render_parent_landing(): void {
		// Redirect to our submenu screen safely.
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SUBMENU_SLUG ) );
		exit;
	}

	/**
	 * Outputs scoped styles for the admin menu icon to guarantee standard 20x20 sizing.
	 *
	 * @return void
	 */
	public function render_admin_menu_styles(): void {
		?>
		<style id="wpcalibrate-menu-icon-style">
			#adminmenu li.toplevel_page_wpcalibrate .wp-menu-image img,
			#adminmenu li.toplevel_page_wpcalibrate div.wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate .wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate div.wp-menu-image img,
			#adminmenu li[class*="toplevel_page_wpcalibrate"] .wp-menu-image img {
				width: 20px !important;
				height: 20px !important;
				max-width: 20px !important;
				max-height: 20px !important;
				padding: 7px 0 0 0 !important;
				box-sizing: content-box !important;
				object-fit: contain !important;
				opacity: 0.85;
				transition: opacity 0.15s ease-in-out;
			}
			#adminmenu li.toplevel_page_wpcalibrate:hover .wp-menu-image img,
			#adminmenu li.toplevel_page_wpcalibrate.wp-has-current-submenu .wp-menu-image img,
			#adminmenu li.toplevel_page_wpcalibrate.current .wp-menu-image img {
				opacity: 1 !important;
			}
			@media screen and (max-width: 782px) {
				#adminmenu li.toplevel_page_wpcalibrate .wp-menu-image img,
				#adminmenu li[class*="toplevel_page_wpcalibrate"] .wp-menu-image img {
					width: 20px !important;
					height: 20px !important;
					max-width: 20px !important;
					max-height: 20px !important;
					padding: 7px 0 0 0 !important;
				}
			}
		</style>
		<?php
	}
}
