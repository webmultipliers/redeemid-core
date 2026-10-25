<?php

namespace WP2_Shopify\Webhooks;

use WP2_Shopify\Support\CustomerId;

/**
 * Reduce a webhook payload to the few fields its handler needs.
 * Full payloads are never stored; this is what goes into the inbox row.
 */
final class Extractor {
	public static function extract( string $kind, string $topic, array $payload ): array {
		$customer = CustomerId::from_payload( $payload, $topic );
		$data     = [ 'customer' => $customer?->numeric ];

		switch ( $kind ) {
			case Topics::PROFILE:
				$data += [
					'first_name'     => self::str( $payload['first_name'] ?? null ),
					'last_name'      => self::str( $payload['last_name'] ?? null ),
					'email'          => self::str( $payload['email'] ?? null ),
					'state'          => self::str( $payload['state'] ?? null ),
					'verified_email' => isset( $payload['verified_email'] ) ? (bool) $payload['verified_email'] : null,
				];
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
				$data += [
					'orders_count' => isset( $payload['number_of_orders'] ) ? (int) $payload['number_of_orders'] : ( isset( $payload['orders_count'] ) ? (int) $payload['orders_count'] : null ),
					'amount_spent' => self::str( $payload['amount_spent']['amount'] ?? $payload['total_spent'] ?? null ),
					'currency'     => self::str( $payload['amount_spent']['currency_code'] ?? $payload['currency'] ?? null ),
				];
				break;

			case Topics::MERGE:
				// The surviving and merged-away customers. Field names verified at build time; both spellings accepted.
				$data['survivor'] = CustomerId::parse( $payload['customer_kept_id'] ?? $payload['customerKeptId'] ?? null )?->numeric;
				$data['merged']   = CustomerId::parse( $payload['customer_deleted_id'] ?? $payload['customerDeletedId'] ?? null )?->numeric;
				break;

			case Topics::COMPLIANCE:
				$data['shop'] = self::str( $payload['shop_domain'] ?? null );
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
