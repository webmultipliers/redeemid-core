<?php

namespace RedeemID\Webhooks;

use RedeemID\Shopify\Admin;

/**
 * Registers the plugin's webhook subscriptions through the Admin API, so they
 * belong to the app and are signed with its client secret. Webhooks created in
 * Shopify's Settings → Notifications use a different key and lack some topics.
 *
 * The compliance topics cannot be subscribed through the API; Shopify only
 * sends them to apps that declare them in the app's configuration.
 */
final class Registrar {
	public const RESULT = 'redeemid_webhook_registration';

	private const API_UNSUPPORTED = [ 'customers/data_request', 'customers/redact', 'shop/redact' ];

	/**
	 * Shopify's GraphQL enum for a topic: customer.tags_added → CUSTOMER_TAGS_ADDED.
	 */
	public static function enum( string $topic ): string {
		return strtoupper( str_replace( [ '/', '.' ], '_', $topic ) );
	}

	/**
	 * @return string[] Topics the plugin subscribes to through the API.
	 */
	public static function topics(): array {
		return array_values( array_diff( Topics::subscribable(), self::API_UNSUPPORTED ) );
	}

	/**
	 * Topics still missing a subscription to $uri.
	 *
	 * @param array<int, array{topic: string, uri: string}> $existing Enum topics and URIs already subscribed.
	 * @return string[]
	 */
	public static function missing( array $existing, string $uri ): array {
		$have = [];

		foreach ( $existing as $sub ) {
			if ( rtrim( $sub['uri'], '/' ) === rtrim( $uri, '/' ) ) {
				$have[ $sub['topic'] ] = true;
			}
		}

		return array_values( array_filter( self::topics(), static fn( string $topic ) => ! isset( $have[ self::enum( $topic ) ] ) ) );
	}

	/**
	 * @return array{at: int, created: string[], existing: int, failed: array<string, string>, error: string}
	 */
	public static function sync(): array {
		$uri    = rest_url( 'redeemid/v1/webhooks/events' );
		$result = [ 'at' => time(), 'created' => [], 'existing' => 0, 'failed' => [], 'error' => '' ];
		$data   = Admin::query( 'query RedeemIDWebhooks { webhookSubscriptions(first: 100) { nodes { topic uri } } }', [], 'admin.webhooks' );

		if ( is_wp_error( $data ) ) {
			$result['error'] = $data->get_error_message();
			update_option( self::RESULT, $result, false );

			return $result;
		}

		$existing = [];

		foreach ( (array) ( $data['webhookSubscriptions']['nodes'] ?? [] ) as $node ) {
			$existing[] = [ 'topic' => (string) ( $node['topic'] ?? '' ), 'uri' => (string) ( $node['uri'] ?? '' ) ];
		}

		$missing            = self::missing( $existing, $uri );
		$result['existing'] = count( self::topics() ) - count( $missing );

		foreach ( $missing as $topic ) {
			$created = Admin::query(
				'mutation RedeemIDWebhookCreate($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) { webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) { webhookSubscription { id } userErrors { field message } } }',
				[ 'topic' => self::enum( $topic ), 'sub' => [ 'uri' => $uri, 'format' => 'JSON' ] ],
				'admin.webhook_create'
			);

			$error = is_wp_error( $created ) ? $created->get_error_message() : (string) ( $created['webhookSubscriptionCreate']['userErrors'][0]['message'] ?? '' );

			if ( '' === $error ) {
				$result['created'][] = $topic;
			} else {
				$result['failed'][ $topic ] = $error;
			}
		}

		update_option( self::RESULT, $result, false );

		return $result;
	}
}
