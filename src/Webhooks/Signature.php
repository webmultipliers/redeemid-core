<?php

namespace RedeemID\Webhooks;

/**
 * Shopify webhook HMAC check: base64(HMAC-SHA256(raw body, secret)),
 * compared in constant time before anything else touches the payload.
 */
final class Signature {
	public static function compute( string $raw_body, string $secret ): string {
		return base64_encode( hash_hmac( 'sha256', $raw_body, $secret, true ) );
	}

	public static function verify( string $raw_body, string $header, string $secret ): bool {
		if ( '' === $secret || '' === trim( $header ) ) {
			return false;
		}

		return hash_equals( self::compute( $raw_body, $secret ), trim( $header ) );
	}
}
