<?php

namespace RedeemID\Support;

/**
 * Canonical Shopify customer identity.
 *
 * Login returns a GID (gid://shopify/Customer/123), REST webhook payloads carry
 * the numeric ID (123), and some payloads carry either as a string or integer.
 * Everything inbound is normalised to both forms before any lookup.
 */
final class CustomerId {
	private const GID_PREFIX = 'gid://shopify/Customer/';

	private function __construct(
		public readonly string $numeric,
	) {}

	/**
	 * @param mixed $value A GID, a numeric ID (string or int), or anything else.
	 */
	public static function parse( mixed $value ): ?self {
		if ( is_int( $value ) ) {
			return $value > 0 ? new self( (string) $value ) : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( str_starts_with( $value, self::GID_PREFIX ) ) {
			$value = substr( $value, strlen( self::GID_PREFIX ) );
			// A GID may carry a query string, e.g. ?legacy=...
			$value = (string) strtok( $value, '?' );
		}

		if ( '' === $value || ! ctype_digit( $value ) ) {
			return null;
		}

		$value = ltrim( $value, '0' );

		return '' === $value ? null : new self( $value );
	}

	/**
	 * Pull the customer identifier out of a webhook payload, whatever its shape.
	 */
	public static function from_payload( array $payload, string $topic = '' ): ?self {
		// Customer topics (customers/*, customers_*_consent/update, customer.*) put the customer at the root; order topics nest it.
		$candidates = str_starts_with( $topic, 'customers' ) || str_starts_with( $topic, 'customer.' )
			? [ $payload['id'] ?? null, $payload['customerId'] ?? null, $payload['customer_id'] ?? null, $payload['customer']['id'] ?? null ]
			: [ $payload['customer']['id'] ?? null, $payload['customer_id'] ?? null, $payload['customerId'] ?? null ];

		foreach ( $candidates as $candidate ) {
			$id = self::parse( $candidate );

			if ( $id ) {
				return $id;
			}
		}

		return null;
	}

	public function gid(): string {
		return self::GID_PREFIX . $this->numeric;
	}
}
