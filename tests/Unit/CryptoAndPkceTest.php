<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Auth\Pkce;
use WP2_Shopify\Support\Crypto;

final class CryptoAndPkceTest extends TestCase {
	private const SALTS = 'a-long-test-salt-material-that-is-not-real-1234567890';

	public function test_round_trip_and_ciphertext_is_not_plaintext(): void {
		$crypto = Crypto::from_config( '', self::SALTS );
		$cipher = $crypto->encrypt( 'refresh-token-value' );

		$this->assertStringNotContainsString( 'refresh-token-value', $cipher );
		$this->assertTrue( Crypto::is_encrypted( $cipher ) );
		$this->assertSame( 'refresh-token-value', $crypto->decrypt( $cipher ) );
	}

	public function test_tampering_or_other_key_returns_null(): void {
		$cipher = Crypto::from_config( '', self::SALTS )->encrypt( 'x' );

		$this->assertNull( Crypto::from_config( '', self::SALTS . 'changed' )->decrypt( $cipher ) );
		$this->assertNull( Crypto::from_config( '', self::SALTS )->decrypt( substr( $cipher, 0, -2 ) . 'AA' ) );
		$this->assertNull( Crypto::from_config( '', self::SALTS )->decrypt( 'plaintext' ) );
	}

	public function test_explicit_key_must_be_32_bytes(): void {
		$crypto = Crypto::from_config( base64_encode( random_bytes( 32 ) ), '' );
		$this->assertSame( 'v', $crypto->decrypt( $crypto->encrypt( 'v' ) ) );

		$this->expectException( \InvalidArgumentException::class );
		Crypto::from_config( base64_encode( 'short' ), '' );
	}

	public function test_poc_style_key_is_rejected_loudly(): void {
		// The POC stored base64(random 32 bytes) and handed it to Defuse, which failed silently.
		$this->expectException( \InvalidArgumentException::class );
		Crypto::from_config( '', 'short' );
	}

	public function test_pkce_challenge_matches_rfc7636_example(): void {
		// RFC 7636 Appendix B.
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
	}

	public function test_verifier_length_in_range(): void {
		$length = strlen( Pkce::verifier() );

		$this->assertGreaterThanOrEqual( 43, $length );
		$this->assertLessThanOrEqual( 128, $length );
	}

	public function test_id_token_claim_checks(): void {
		$claims = [ 'iss' => 'https://issuer', 'aud' => 'client', 'exp' => 2000, 'nonce' => 'n1' ];

		$this->assertNull( Pkce::check_id_token_claims( $claims, 'https://issuer', 'client', 'n1', 1000 ) );
		$this->assertSame( 'id_token_issuer', Pkce::check_id_token_claims( $claims, 'https://other', 'client', 'n1', 1000 ) );
		$this->assertSame( 'id_token_audience', Pkce::check_id_token_claims( $claims, 'https://issuer', 'other', 'n1', 1000 ) );
		$this->assertSame( 'id_token_expired', Pkce::check_id_token_claims( $claims, 'https://issuer', 'client', 'n1', 5000 ) );
		$this->assertSame( 'id_token_nonce', Pkce::check_id_token_claims( $claims, 'https://issuer', 'client', 'n2', 1000 ) );
		$this->assertSame( 'id_token_nonce', Pkce::check_id_token_claims( [ 'nonce' => null ] + $claims, 'https://issuer', 'client', 'n1', 1000 ) );
	}

	public function test_issuer_must_be_shopify_without_discovery(): void {
		$claims = [ 'iss' => 'https://shopify.com/authentication/1', 'aud' => 'client', 'exp' => 2000, 'nonce' => 'n1' ];

		$this->assertNull( Pkce::check_id_token_claims( $claims, '', 'client', 'n1', 1000 ) );
		$this->assertSame( 'id_token_issuer', Pkce::check_id_token_claims( [ 'iss' => 'https://evil.example/' ] + $claims, '', 'client', 'n1', 1000 ) );
		$this->assertSame( 'id_token_issuer', Pkce::check_id_token_claims( [ 'iss' => null ] + $claims, '', 'client', 'n1', 1000 ) );
	}

	public function test_jwt_claims_decodes_payload(): void {
		$payload = rtrim( strtr( base64_encode( '{"sub":"1","nonce":"n"}' ), '+/', '-_' ), '=' );

		$this->assertSame( [ 'sub' => '1', 'nonce' => 'n' ], Pkce::jwt_claims( 'h.' . $payload . '.s' ) );
		$this->assertNull( Pkce::jwt_claims( 'not-a-jwt' ) );
	}
}
