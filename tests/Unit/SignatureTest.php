<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Webhooks\Signature;

final class SignatureTest extends TestCase {
	private const SECRET = 'test-secret-not-real';

	public function test_matches_shopify_algorithm(): void {
		$body = (string) file_get_contents( __DIR__ . '/../fixtures/customers-update.json' );

		// Independent computation: base64 of the raw binary HMAC-SHA256.
		$expected = base64_encode( hash_hmac( 'sha256', $body, self::SECRET, true ) );

		$this->assertTrue( Signature::verify( $body, $expected, self::SECRET ) );
	}

	public function test_rejects_tampered_body(): void {
		$body = '{"id":1}';
		$sig  = Signature::compute( $body, self::SECRET );

		$this->assertFalse( Signature::verify( '{"id":2}', $sig, self::SECRET ) );
	}

	public function test_rejects_wrong_secret_missing_header_and_missing_secret(): void {
		$body = '{"id":1}';
		$sig  = Signature::compute( $body, self::SECRET );

		$this->assertFalse( Signature::verify( $body, $sig, 'other' ) );
		$this->assertFalse( Signature::verify( $body, '', self::SECRET ) );
		$this->assertFalse( Signature::verify( $body, $sig, '' ) );
	}

	public function test_hex_digest_is_not_accepted(): void {
		$body = '{"id":1}';

		$this->assertFalse( Signature::verify( $body, hash_hmac( 'sha256', $body, self::SECRET ), self::SECRET ) );
	}
}
