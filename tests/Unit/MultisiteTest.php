<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Support\Multisite;

final class MultisiteTest extends TestCase {
	public function test_redeemid_keys_take_the_site_prefix(): void {
		$this->assertSame( 'wp_2__redeemid_tags', Multisite::scope( '_redeemid_tags', 'wp_2_' ) );
	}

	public function test_main_site_and_single_sites_keep_plain_keys(): void {
		$this->assertSame( '_redeemid_tags', Multisite::scope( '_redeemid_tags', '' ) );
	}

	public function test_other_keys_are_left_alone(): void {
		$this->assertSame( 'session_tokens', Multisite::scope( 'session_tokens', 'wp_2_' ) );
		$this->assertSame( 'redeemid_settings', Multisite::scope( 'redeemid_settings', 'wp_2_' ) );
	}
}
