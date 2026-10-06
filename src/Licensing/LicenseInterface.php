<?php
/**
 * License Provider Interface.
 *
 * @package WPCalibrate\AccountingSync\Licensing
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Licensing;

/**
 * Contract for remote license validation adapters.
 */
interface LicenseInterface {

	/**
	 * Activates a license key with remote provider.
	 *
	 * @param string $license_key License key.
	 * @return array{success: bool, status: string, message: string, expires_at?: string}
	 */
	public function activate( string $license_key ): array;

	/**
	 * Deactivates an active license.
	 *
	 * @param string $license_key License key.
	 * @return array{success: bool, status: string, message: string}
	 */
	public function deactivate( string $license_key ): array;

	/**
	 * Checks status of license.
	 *
	 * @param string $license_key License key.
	 * @return array{status: string, expires_at?: string, customer_name?: string}
	 */
	public function check_status( string $license_key ): array;
}
