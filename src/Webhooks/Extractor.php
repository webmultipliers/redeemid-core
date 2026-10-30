<?php

namespace RedeemID\Webhooks;

use RedeemID\Support\CustomerId;

/**
 * Reduce a webhook payload to the few fields its handler needs.
 * Full payloads are never stored; this is what goes into the inbox row.
 */
final class Extractor {
	public static function extract( string $kind, string $topic, array $payload ): array {
		$customer = CustomerId::from_payload( $payload, $topic );
		$data     = [ 'customer' => $customer?->numeric ];

		// Customer.updated_at orders profile and status webhooks; Shopify may deliver them out of order.
		if ( in_array( $kind, [ Topics::PROFILE, Topics::STATUS ], true ) ) {
			$updated            = is_string( $payload['updated_at'] ?? null ) ? strtotime( $payload['updated_at'] ) : false;
			$data['updated_at'] = $updated ?: null;
		}

		switch ( $kind ) {
			case Topics::PROFILE:
				$data += [
					'first_name'     => self::str( $payload['first_name'] ?? null ),
					'last_name'      => self::str( $payload['last_name'] ?? null ),
					'email'          => self::str( $payload['email'] ?? null ),
					'verified_email' => isset( $payload['verified_email'] ) ? (bool) $payload['verified_email'] : null,
				];
				// Customer.state is left out on purpose: it only means something under
				// classic customer accounts (status comes from customers/disable|enable).
				// Older payloads may still carry tags; a missing field never means "tags removed".
				if ( array_key_exists( 'tags', $payload ) ) {
					$data['tags'] = self::tags( $payload['tags'] );
				}
				break;

			case Topics::STATUS:
				$data['status'] = str_ends_with( $topic, '/disable' ) ? 'disabled' : 'enabled';
				break;

			case Topics::TAGS:
				$data['tags']   = self::tags( $payload['tags'] ?? [] );
				$data['change'] = str_ends_with( $topic, 'tags_added' ) ? 'added' : 'removed';
				break;

			case Topics::CONSENT:
				$data['channel'] = str_contains( $topic, 'email' ) ? 'email' : 'sms';
				$consent         = $payload['email_address_consent'] ?? $payload['phone_number_consent'] ?? $payload['email_marketing_consent'] ?? $payload['sms_marketing_consent'] ?? $payload;
				$data['state']   = self::str( is_array( $consent ) ? ( $consent['state'] ?? null ) : null );
				break;

			case Topics::SUMMARY:
				// The 2026-10 payload is camelCase (numberOfOrders, amountSpent.currencyCode); snake_case kept for older ones.
				$orders = $payload['numberOfOrders'] ?? $payload['number_of_orders'] ?? $payload['orders_count'] ?? null;
				$spent  = $payload['amountSpent'] ?? $payload['amount_spent'] ?? null;
				$data  += [
					'orders_count' => is_numeric( $orders ) ? (int) $orders : null,
					'amount_spent' => self::str( is_array( $spent ) ? ( $spent['amount'] ?? null ) : ( $payload['total_spent'] ?? null ) ),
					'currency'     => self::str( is_array( $spent ) ? ( $spent['currencyCode'] ?? $spent['currency_code'] ?? null ) : ( $payload['currency'] ?? null ) ),
				];
				break;

			case Topics::MERGE:
				// The surviving and merged-away customers, as GIDs (2026-10 sample payload). A failed merge changes nothing.
				if ( 'failed' === ( $payload['status'] ?? '' ) ) {
					break;
				}
				$data['survivor'] = CustomerId::parse( $payload['admin_graphql_api_customer_kept_id'] ?? null )?->numeric;
				$data['merged']   = CustomerId::parse( $payload['admin_graphql_api_customer_deleted_id'] ?? null )?->numeric;
				break;

			case Topics::COMPLIANCE:
				$data['shop']    = self::str( $payload['shop_domain'] ?? null );
				$data['request'] = isset( $payload['data_request']['id'] ) && is_scalar( $payload['data_request']['id'] ) ? (string) $payload['data_request']['id'] : null;
				break;
		}

		return array_filter( $data, static fn( $v ) => null !== $v );
	}

	/**
	 * @return string[]
	 */
	public static function tags( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		$tags = [];

		foreach ( (array) $raw as $tag ) {
			if ( is_string( $tag ) && '' !== trim( $tag ) ) {
				$tags[] = trim( $tag );
			}
		}

		return array_values( array_unique( $tags ) );
	}

	private static function str( mixed $value ): ?string {
		if ( null === $value || is_array( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}
}
