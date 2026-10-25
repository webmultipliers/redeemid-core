<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Access\Context;
use WP2_Shopify\Access\ItemRule;

final class ItemRuleTest extends TestCase {
	public function test_garbage_becomes_inherit(): void {
		$this->assertSame(
			[ 'mode' => Context::ITEM_INHERIT, 'levels' => [], 'locked_until' => null, 'public_until' => null, 'meter_exempt' => false, 'drip_days' => 0 ],
			ItemRule::sanitize( 'nonsense' )
		);
	}

	public function test_values_are_cleaned(): void {
		$rule = ItemRule::sanitize( [ 'mode' => 'level', 'levels' => [ 'Gold', 'gold', ' ', 'Silver Tier' ], 'locked_until' => '1800000000', 'public_until' => -5, 'meter_exempt' => '1' ] );

		$this->assertSame( Context::ITEM_LEVEL, $rule['mode'] );
		$this->assertSame( [ 'gold', 'silver-tier' ], $rule['levels'] );
		$this->assertSame( 1800000000, $rule['locked_until'] );
		$this->assertNull( $rule['public_until'] );
		$this->assertTrue( $rule['meter_exempt'] );
	}

	public function test_drip_days_are_clamped(): void {
		$this->assertSame( 30, ItemRule::sanitize( [ 'drip_days' => '30' ] )['drip_days'] );
		$this->assertSame( 0, ItemRule::sanitize( [ 'drip_days' => -4 ] )['drip_days'] );
		$this->assertSame( 3650, ItemRule::sanitize( [ 'drip_days' => 99999 ] )['drip_days'] );
	}

	public function test_unknown_mode_is_inherit(): void {
		$this->assertSame( Context::ITEM_INHERIT, ItemRule::sanitize( [ 'mode' => 'everyone' ] )['mode'] );
	}
}
