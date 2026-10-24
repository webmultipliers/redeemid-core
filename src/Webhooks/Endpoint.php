<?php

namespace WP2_Shopify\Webhooks;

use WP2_Shopify\Config;
use WP2_Shopify\Support\Logger;

/**
 * POST /wp-json/wp2-shopify/v1/webhooks/events. Replaces the POC's
 * misspelled /iwebhooks/events; the old path is not kept.
 */
final class Endpoint {
	public const HOOK = 'wp2_shopify_process_webhook';

	public static function register_routes(): void {
		register_rest_route(
			'wp2-shopify/v1',
			'/webhooks/events',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'receive' ],
				// Authenticated by HMAC inside the callback, before any processing; no user session.
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$raw    = $request->get_body();
		$secret = Config::webhook_secret();

		if ( ! Signature::verify( $raw, (string) $request->get_header( 'x-shopify-hmac-sha256' ), $secret ) ) {
			Logger::log( 'webhook.rejected', [ 'reason' => 'hmac', 'topic' => (string) $request->get_header( 'x-shopify-topic' ) ] );
			update_option( 'wp2_shopify_webhook_rejected', time(), false );

			return new \WP_REST_Response( [ 'ok' => false ], 401 );
		}

		$shop = strtolower( (string) $request->get_header( 'x-shopify-shop-domain' ) );

		if ( '' !== $shop && '' !== Config::store_domain() && $shop !== Config::store_domain() ) {
			Logger::log( 'webhook.rejected', [ 'reason' => 'shop_mismatch', 'topic' => (string) $request->get_header( 'x-shopify-topic' ), 'shop' => substr( $shop, 0, 100 ) ] );

			return new \WP_REST_Response( [ 'ok' => false ], 401 );
		}

		$topic   = (string) $request->get_header( 'x-shopify-topic' );
		$payload = json_decode( $raw, true );

		$result = ( new Inbox( new WpdbInboxStore() ) )->accept(
			$topic,
			(string) $request->get_header( 'x-shopify-webhook-id' ),
			(string) $request->get_header( 'x-shopify-event-id' ),
			$raw,
			is_array( $payload ) ? $payload : [],
			time()
		);

		Logger::log( 'webhook.received', [ 'topic' => $topic, 'status' => $result['status'], 'row' => $result['id'], 'customer' => (string) ( $result['customer'] ?? '' ) ] );

		if ( InboxStore::QUEUED === $result['status'] && $result['id'] ) {
			self::schedule( $result['id'] );
		}

		// Acknowledge fast. Duplicates, ignored and dead-lettered topics still get 200 so Shopify stops retrying.
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function schedule( int $row_id, int $delay = 0 ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK, [ $row_id ], 'wp2-shopify' );
			return;
		}

		// WP-Cron fallback. Only the row ID is stored in the cron option, never the payload.
		wp_schedule_single_event( time() + $delay, self::HOOK, [ $row_id ] );
	}
}
