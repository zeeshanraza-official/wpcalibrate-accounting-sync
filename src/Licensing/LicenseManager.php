<?php
/**
 * License Manager.
 *
 * @package WPCalibrate\AccountingSync\Licensing
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Licensing;

use WPCalibrate\AccountingSync\Logging\Redactor;

/**
 * Manages license key persistence and provider state without faking remote activation.
 */
final class LicenseManager {

	public const OPTION_NAME = 'wpcalibrate_accounting_sync_license';

	/**
	 * Provider adapter instance.
	 *
	 * @var LicenseInterface|null
	 */
	private ?LicenseInterface $provider;

	/**
	 * Constructor.
	 *
	 * @param LicenseInterface|null $provider Provider implementation.
	 */
	public function __construct( ?LicenseInterface $provider = null ) {
		$this->provider = $provider;
	}

	/**
	 * Retrieves current license information.
	 *
	 * @return array{key: string, masked_key: string, status: string, message: string}
	 */
	public static function get_license_data(): array {
		$data = get_option( self::OPTION_NAME, [] );
		$key  = is_array( $data ) && ! empty( $data['key'] ) ? (string) $data['key'] : '';

		return [
			'key'        => $key,
			'masked_key' => ! empty( $key ) ? Redactor::mask_secret( $key, 4 ) : '',
			'status'     => 'not_configured',
			'message'    => __( 'Commercial licensing provider API endpoint has not been configured in this build. Core plugin functionality is unblocked for administrative use.', 'wpcalibrate-accounting-sync' ),
		];
	}

	/**
	 * Saves license key.
	 *
	 * @param string $key License key.
	 * @return bool
	 */
	public static function save_key( string $key ): bool {
		$clean_key = sanitize_text_field( trim( $key ) );
		return update_option(
			self::OPTION_NAME,
			[
				'key'        => $clean_key,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			false
		);
	}

	/**
	 * Deletes stored license.
	 *
	 * @return bool
	 */
	public static function clear_license(): bool {
		return delete_option( self::OPTION_NAME );
	}
}
