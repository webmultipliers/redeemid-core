<?php

namespace WP2_Shopify\Core;

use WP2_Shopify\Auth\Service;
use WP2_Shopify\Events\Scheduler;
use WP_REST_Request;
use WP_REST_Server;

class API {
	public static function register_routes(): void {
		register_rest_route( 'wp2-shopify/v1', '/verify-connection', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'verify_connection' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		register_rest_route( 'wp2-shopify/v1', '/iwebhooks/events', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'handle_incoming_event' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'wp2-shopify/v1', '/webhook/customers-update', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'handle_customers_update_webhook' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function verify_connection( WP_REST_Request $request ) {
		$config = Service::get_openid_configuration();

		if ( is_wp_error( $config ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => 'Failed to reach Shopify. Check your Store Domain.',
				'debug'   => $config->get_error_message(),
			] );
		}

		if ( empty( Service::get_client_id() ) ) {
			return rest_ensure_response( [ 'success' => false, 'message' => 'Client ID is missing.' ] );
		}

		return rest_ensure_response( [
			'success'   => true,
			'shop_name' => Service::get_shop_domain(),
			'message'   => 'Identity Service Reachable.',
		] );
	}

	public static function handle_incoming_event( WP_REST_Request $request ) {
		if ( ! self::validate_shopify_webhook( $request ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => 'Invalid webhook signature.',
			] );
		}

		$payload = $request->get_json_params();
		$topic   = self::resolve_webhook_topic( $request, $payload );

		if ( empty( $topic ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => 'Unable to determine webhook topic.',
			] );
		}

		$schedule = Scheduler::schedule( $topic, $payload );

		return rest_ensure_response( [
			'success' => $schedule,
			'message' => $schedule ? 'Event scheduled.' : 'Failed to queue event.',
			'topic'   => $topic,
		] );
	}

	private static function resolve_webhook_topic( WP_REST_Request $request, array $payload ): string {
		$header = (string) $request->get_header( 'x-shopify-topic' );

		if ( $header !== '' ) {
			return $header;
		}

		if ( ! empty( $payload['topic'] ) ) {
			return (string) $payload['topic'];
		}

		if ( ! empty( $payload['event'] ) ) {
			return (string) $payload['event'];
		}

		return '';
	}

	public static function validate_shopify_webhook( WP_REST_Request $request ): bool {
		$hmac_header = $request->get_header( 'x-shopify-hmac-sha256' );

		$shared_secret = '';
		if ( defined( 'WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET' ) && '' !== trim( WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET ) ) {
			$shared_secret = WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET;
		} elseif ( defined( 'SHOPIFY_SHARED_SECRET' ) && '' !== trim( SHOPIFY_SHARED_SECRET ) ) {
			$shared_secret = SHOPIFY_SHARED_SECRET;
		}

		if ( empty( $shared_secret ) || empty( $hmac_header ) ) {
			return false;
		}

		$calculated_hmac = base64_encode( hash_hmac( 'sha256', $request->get_body(), $shared_secret, true ) );

		return hash_equals( $hmac_header, $calculated_hmac );
	}

	public static function handle_customers_update_webhook( WP_REST_Request $request ) {
		if ( ! self::validate_shopify_webhook( $request ) ) {
			return rest_ensure_response( [
				'success' => false,
				'message' => 'Invalid webhook signature.',
			] );
		}

		$payload  = $request->get_json_params();
		$schedule = Scheduler::schedule( 'customers/update', $payload );

		return rest_ensure_response( [
			'success' => $schedule,
			'message' => $schedule ? 'Event scheduled.' : 'Failed to queue event.',
			'topic'   => 'customers/update',
		] );
	}
}
