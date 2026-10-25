<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Legacy;
use RedeemID\Support\Crypto;

final class LegacyTest extends TestCase {
	private const SALTS = 'a-long-test-salt-material-that-is-not-real-1234567890';

	public function test_stored_keys_get_the_new_prefix(): void {
		$this->assertSame( 'redeemid_config', Legacy::key( 'wp2_shopify_config' ) );
		$this->assertSame( '_redeemid_customer_id', Legacy::key( '_wp2_shopify_customer_id' ) );
		$this->assertSame( '_redeemid_access_effective', Legacy::key( '_wp2_access_effective' ) );
		$this->assertSame( 'redeemid_oauth_x', Legacy::key( 'wp2s_oauth_x' ) );
		$this->assertSame( 'unrelated_wp2_shopify', Legacy::key( 'unrelated_wp2_shopify' ) );
	}

	public function test_poc_last_sync_does_not_collide_with_the_mirror_stamp(): void {
		$this->assertSame( Legacy::POC_LAST_SYNC, Legacy::key( '_wp2_last_sync' ) );
		$this->assertNotSame( Legacy::key( '_wp2_last_sync' ), Legacy::key( '_wp2_shopify_last_sync' ) );
	}

	public function test_saved_blocks_are_renamed(): void {
		$old = '<!-- wp:wp2-shopify/membership-gate {"wp2Access":{"show":"members"}} --><!-- wp:paragraph --><p>wp2-shopify stays in text</p><!-- /wp:paragraph --><!-- /wp:wp2-shopify/membership-gate -->';
		$new = '<!-- wp:redeemid/membership-gate {"redeemidAccess":{"show":"members"}} --><!-- wp:paragraph --><p>wp2-shopify stays in text</p><!-- /wp:paragraph --><!-- /wp:redeemid/membership-gate -->';

		$this->assertSame( $new, Legacy::content( $old ) );
	}

	public function test_old_constant_names(): void {
		$this->assertSame( 'WP2_SHOPIFY_STORE_DOMAIN', Legacy::constant_name( 'REDEEMID_STORE_DOMAIN' ) );
		$this->assertNull( Legacy::constant_name( 'COOKIE_DOMAIN' ) );
	}

	public function test_values_sealed_before_the_rename_still_open(): void {
		$crypto = Crypto::from_config( '', self::SALTS );
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key    = hash_hkdf( 'sha256', self::SALTS, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'wp2-shopify/token-encryption/v1' );
		$old    = 'wp2s1:' . sodium_bin2base64( $nonce . sodium_crypto_secretbox( 'secret', $nonce, $key ), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );

		$this->assertTrue( Crypto::is_legacy( $old ) );
		$this->assertTrue( Crypto::is_encrypted( $old ) );
		$this->assertSame( 'secret', $crypto->decrypt( $old ) );
		$this->assertFalse( Crypto::is_legacy( $crypto->encrypt( 'secret' ) ) );
	}
}
