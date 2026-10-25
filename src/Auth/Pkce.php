<?php

namespace RedeemID\Auth;

/**
 * PKCE and OAuth random values (RFC 7636). Generated server-side only; the
 * verifier never reaches the browser.
 */
final class Pkce {
	public static function random( int $bytes = 32 ): string {
		return self::base64url( random_bytes( $bytes ) );
	}

	public static function verifier(): string {
		// 64 random bytes -> 86 characters, inside RFC 7636's 43..128 range.
		return self::random( 64 );
	}

	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	public static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Decode the claims of a JWT without verifying its signature. Callers must
	 * only use this on an ID token received directly from the token endpoint
	 * over TLS (OIDC Core 3.1.3.7), and must still check iss, aud, exp, nonce.
	 */
	public static function jwt_claims( string $jwt ): ?array {
		$parts = explode( '.', $jwt );

		if ( 3 !== count( $parts ) ) {
			return null;
		}

		$json = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
		$data = false === $json ? null : json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Check the ID token claims that matter for this flow.
	 *
	 * @return string|null Null when valid, otherwise a short error code.
	 */
	public static function check_id_token_claims( array $claims, string $issuer, string $client_id, string $nonce, int $now, int $leeway = 120 ): ?string {
		$iss = is_string( $claims['iss'] ?? null ) ? $claims['iss'] : '';

		// Without a discovered issuer, still insist the token comes from Shopify's identity service.
		if ( '' !== $issuer ? $iss !== $issuer : ! str_starts_with( $iss, 'https://shopify.com/' ) ) {
			return 'id_token_issuer';
		}

		$aud = $claims['aud'] ?? null;
		$aud = is_array( $aud ) ? $aud : [ $aud ];

		if ( ! in_array( $client_id, $aud, true ) ) {
			return 'id_token_audience';
		}

		if ( ! isset( $claims['exp'] ) || (int) $claims['exp'] + $leeway < $now ) {
			return 'id_token_expired';
		}

		if ( ! isset( $claims['nonce'] ) || ! is_string( $claims['nonce'] ) || ! hash_equals( $nonce, $claims['nonce'] ) ) {
			return 'id_token_nonce';
		}

		return null;
	}
}
