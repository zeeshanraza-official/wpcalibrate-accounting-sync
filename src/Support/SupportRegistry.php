<?php
/**
 * Support & Branding Metadata Registry.
 *
 * @package WPCalibrate\AccountingSync\Support
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Support;

/**
 * Provides authoritative contact, website, and support metadata.
 */
final class SupportRegistry {

	public const AUTHOR_NAME    = 'WPCalibrate';
	public const AUTHOR_EMAIL   = 'support@wpcalibrate.com';
	public const AUTHOR_WEBSITE = 'https://wpcalibrate.com';
	public const MARKETPLACE    = 'https://marketplace.wpcalibrate.com/';
	public const PHONE_WHATSAPP = '+447474795976';

	/**
	 * Returns all support details.
	 *
	 * @return array<string, string>
	 */
	public static function get_details(): array {
		return [
			'author'         => self::AUTHOR_NAME,
			'email'          => self::AUTHOR_EMAIL,
			'website'        => self::AUTHOR_WEBSITE,
			'marketplace'    => self::MARKETPLACE,
			'phone_whatsapp' => self::PHONE_WHATSAPP,
		];
	}
}
