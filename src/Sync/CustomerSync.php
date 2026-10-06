<?php
/**
 * Customer Synchronization Service.
 *
 * @package WPCalibrate\AccountingSync\Sync
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Sync;

use WPCalibrate\AccountingSync\Client\QuickBooksClient;
use WPCalibrate\AccountingSync\Database\MappingRepository;
use WPCalibrate\AccountingSync\Logging\Logger;
use Exception;
use RuntimeException;

/**
 * Handles matching, creation, and mapping of WooCommerce customers in QuickBooks Online.
 */
final class CustomerSync {

	private QuickBooksClient $client;
	private string $environment;
	private string $realm_id;

	/**
	 * Constructor.
	 *
	 * @param QuickBooksClient $client Authenticated API client.
	 */
	public function __construct( QuickBooksClient $client ) {
		$this->client      = $client;
		$this->environment = $client->get_environment();
		$this->realm_id    = $client->get_realm_id();
	}

	/**
	 * Resolves or creates a QuickBooks Customer ID for an order or user.
	 *
	 * @param int         $wc_customer_id WooCommerce user ID (0 for guests).
	 * @param string      $email          Customer email address.
	 * @param string      $first_name     First name.
	 * @param string      $last_name      Last name.
	 * @param string      $company        Company name.
	 * @param array<string, mixed> $billing Billing address details.
	 * @return string QuickBooks Customer ID.
	 * @throws RuntimeException If customer cannot be found or created.
	 */
	public function resolve_customer(
		int $wc_customer_id,
		string $email,
		string $first_name = '',
		string $last_name = '',
		string $company = '',
		array $billing = []
	): string {
		$email = strtolower( trim( $email ) );

		// 1. Check existing mapping for registered customer.
		if ( $wc_customer_id > 0 ) {
			$mapped_id = MappingRepository::get_qbo_id(
				$this->environment,
				$this->realm_id,
				'customer',
				$wc_customer_id
			);
			if ( ! empty( $mapped_id ) ) {
				return $mapped_id;
			}
		}

		// 2. Query QuickBooks by exact email match.
		if ( ! empty( $email ) ) {
			$qbo_id = $this->find_qbo_customer_by_email( $email );
			if ( ! empty( $qbo_id ) ) {
				if ( $wc_customer_id > 0 ) {
					MappingRepository::set_mapping(
						$this->environment,
						$this->realm_id,
						'customer',
						$wc_customer_id,
						'Customer',
						$qbo_id
					);
				}
				return $qbo_id;
			}
		}

		// 3. For guest customers where generic guest customer is configured, use it.
		$general_settings = get_option( 'wpcalibrate_accounting_sync_general', [] );
		if ( 0 === $wc_customer_id && ! empty( $general_settings['use_generic_guest_customer'] ) ) {
			$guest_id = (string) ( $general_settings['generic_guest_customer_id'] ?? '' );
			if ( ! empty( $guest_id ) ) {
				return $guest_id;
			}
		}

		// 4. Create new Customer in QuickBooks deterministically.
		$new_qbo_id = $this->create_qbo_customer( $wc_customer_id, $email, $first_name, $last_name, $company, $billing );

		if ( $wc_customer_id > 0 ) {
			MappingRepository::set_mapping(
				$this->environment,
				$this->realm_id,
				'customer',
				$wc_customer_id,
				'Customer',
				$new_qbo_id
			);
		}

		return $new_qbo_id;
	}

	/**
	 * Searches QuickBooks for a customer with exact email address.
	 *
	 * @param string $email Normalized email address.
	 * @return string|null QuickBooks Customer ID if found.
	 */
	public function find_qbo_customer_by_email( string $email ): ?string {
		try {
			$safe_email = addslashes( $email );
			$res = $this->client->query( "select Id, DisplayName from Customer where PrimaryEmailAddr = '{$safe_email}' maxresults 1" );
			$customers = $res['QueryResponse']['Customer'] ?? [];
			if ( ! empty( $customers[0]['Id'] ) ) {
				return (string) $customers[0]['Id'];
			}
		} catch ( Exception $e ) {
			Logger::warning( 'Customer', $email, 'find_by_email', 'Error searching customer by email: ' . $e->getMessage() );
		}
		return null;
	}

	/**
	 * Creates a new customer entity in QuickBooks Online.
	 *
	 * @param int         $wc_id      WooCommerce customer ID.
	 * @param string      $email      Email address.
	 * @param string      $first_name First name.
	 * @param string      $last_name  Last name.
	 * @param string      $company    Company name.
	 * @param array<string, mixed> $billing Billing address.
	 * @return string Created QuickBooks Customer ID.
	 * @throws RuntimeException On API failure.
	 */
	private function create_qbo_customer(
		int $wc_id,
		string $email,
		string $first_name,
		string $last_name,
		string $company,
		array $billing
	): string {
		$display_name = trim( $first_name . ' ' . $last_name );
		if ( empty( $display_name ) ) {
			$display_name = ! empty( $company ) ? $company : ( ! empty( $email ) ? $email : 'Guest Customer' );
		}

		// Ensure display name uniqueness in QBO by appending email or WC ID if needed.
		$payload = [
			'DisplayName'      => substr( $display_name . ( $wc_id > 0 ? " (#$wc_id)" : '' ), 0, 100 ),
			'GivenName'        => substr( $first_name, 0, 25 ),
			'FamilyName'       => substr( $last_name, 0, 25 ),
			'CompanyName'      => substr( $company, 0, 50 ),
			'PrimaryEmailAddr' => [ 'Address' => $email ],
		];

		if ( ! empty( $billing['phone'] ) ) {
			$payload['PrimaryPhone'] = [ 'FreeFormNumber' => substr( (string) $billing['phone'], 0, 30 ) ];
		}

		if ( ! empty( $billing['address_1'] ) ) {
			$payload['BillAddr'] = [
				'Line1'                  => substr( (string) $billing['address_1'], 0, 500 ),
				'Line2'                  => substr( (string) ( $billing['address_2'] ?? '' ), 0, 500 ),
				'City'                   => substr( (string) ( $billing['city'] ?? '' ), 0, 255 ),
				'CountrySubDivisionCode' => substr( (string) ( $billing['state'] ?? '' ), 0, 255 ),
				'PostalCode'             => substr( (string) ( $billing['postcode'] ?? '' ), 0, 31 ),
				'Country'                => substr( (string) ( $billing['country'] ?? '' ), 0, 255 ),
			];
		}

		try {
			$res = $this->client->post( 'customer', $payload );
			$qbo_id = (string) ( $res['Customer']['Id'] ?? '' );
			if ( empty( $qbo_id ) ) {
				throw new RuntimeException( 'Failed to obtain Customer ID from QuickBooks creation response.' );
			}

			Logger::info( 'Customer', $qbo_id, 'create', "Created QuickBooks Customer for {$email}" );
			return $qbo_id;
		} catch ( Exception $e ) {
			// If duplicate display name error occurs, try appending random suffix.
			if ( str_contains( strtolower( $e->getMessage() ), 'duplicate' ) ) {
				$payload['DisplayName'] = substr( $display_name . ' (' . wp_generate_password( 4, false ) . ')', 0, 100 );
				$res = $this->client->post( 'customer', $payload );
				return (string) ( $res['Customer']['Id'] ?? '' );
			}
			throw new RuntimeException( esc_html( 'Failed to create QuickBooks customer: ' . $e->getMessage() ) );
		}
	}
}
