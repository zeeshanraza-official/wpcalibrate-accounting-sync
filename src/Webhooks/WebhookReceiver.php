<?php
/**
 * QuickBooks Webhook REST Controller.
 *
 * @package WPCalibrate\AccountingSync\Webhooks
 */

declare(strict_types=1);

namespace WPCalibrate\AccountingSync\Webhooks;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPCalibrate\AccountingSync\Database\WebhookRepository;
use WPCalibrate\AccountingSync\Queue\ActionSchedulerQueue;
use WPCalibrate\AccountingSync\Logging\Logger;

/**
 * Handles incoming QuickBooks webhooks at /wp-json/wpcalibrate/v1/qbo-webhook.
 */
final class WebhookReceiver {

	public const REST_NAMESPACE = 'wpcalibrate/v1';
	public const REST_ROUTE     = '/qbo-webhook';

	/**
	 * Hook registrations.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => [ $this, 'verify_request' ],
			]
		);
	}

	/**
	 * Permission callback: Verifies Intuit HMAC-SHA256 signature.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool True if authorized, false otherwise.
	 */
	public function verify_request( WP_REST_Request $request ): bool {
		$signature = (string) $request->get_header( 'intuit-signature' );
		if ( empty( $signature ) ) {
			return false;
		}

		$general     = get_option( 'wpcalibrate_accounting_sync_general', [] );
		$environment = ! empty( $general['environment'] ) ? (string) $general['environment'] : 'sandbox';

		$conn_opt       = get_option( 'wpcalibrate_accounting_sync_connection_' . sanitize_key( $environment ), [] );
		$verifier_token = (string) ( $conn_opt['webhook_verifier_token'] ?? '' );

		if ( empty( $verifier_token ) ) {
			return false; // Webhook verifier token not yet configured.
		}

		$raw_payload = $request->get_body();
		return SignatureVerifier::verify( $raw_payload, $signature, $verifier_token );
	}

	/**
	 * Processes verified incoming webhook payload.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$data = $request->get_json_params();

		if ( empty( $data['eventNotifications'] ) || ! is_array( $data['eventNotifications'] ) ) {
			return new WP_REST_Response( [ 'success' => true, 'message' => 'No notifications' ], 200 );
		}

		$processed_count = 0;

		foreach ( $data['eventNotifications'] as $notification ) {
			$realm_id = (string) ( $notification['realmId'] ?? '' );
			$entities = $notification['dataChangeEvent']['entities'] ?? [];

			if ( ! is_array( $entities ) ) {
				continue;
			}

			foreach ( $entities as $entity ) {
				$name         = (string) ( $entity['name'] ?? '' );
				$id           = (string) ( $entity['id'] ?? '' );
				$operation    = (string) ( $entity['operation'] ?? '' );
				$last_updated = (string) ( $entity['lastUpdated'] ?? '' );

				// Generate deterministic event signature.
				$event_id = hash( 'sha256', $realm_id . ':' . $name . ':' . $id . ':' . $operation . ':' . $last_updated );

				if ( WebhookRepository::is_duplicate( $event_id ) ) {
					continue; // Deduplicate repeated event.
				}

				WebhookRepository::record_event( $event_id, $realm_id, $name, $id, $operation, 'received' );
				ActionSchedulerQueue::enqueue_webhook_process( $event_id, $entity );
				$processed_count++;
			}
		}

		Logger::info( 'Webhook', '0', 'receive', "Received and enqueued {$processed_count} webhook events from Intuit." );

		return new WP_REST_Response( [ 'success' => true, 'queued' => $processed_count ], 200 );
	}
}
