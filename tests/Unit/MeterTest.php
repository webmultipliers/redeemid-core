<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Access\Meter;
use RedeemID\Access\Rules;

final class MeterTest extends TestCase {
	public function test_period_key_follows_the_site_timezone(): void {
		$tz  = new \DateTimeZone( 'America/New_York' );
		$now = strtotime( '2026-11-01 02:00:00 UTC' ); // Still 31 October in New York.

		$this->assertSame( 'm:2026-10', Meter::period_key( Rules::METER_MONTH, $now, $tz ) );
		$this->assertSame( 'm:2026-11', Meter::period_key( Rules::METER_MONTH, $now, new \DateTimeZone( 'UTC' ) ) );
		$this->assertSame( 'w:2026-W44', Meter::period_key( Rules::METER_WEEK, $now, $tz ) );
	}

	public function test_consume_counts_new_items_until_the_limit(): void {
		$one = Meter::consume( [], 'm:2026-10', 11, 2 );
		$two = Meter::consume( $one['state'], 'm:2026-10', 12, 2 );
		$out = Meter::consume( $two['state'], 'm:2026-10', 13, 2 );

		$this->assertTrue( $one['allowed'] && $one['counted'] );
		$this->assertTrue( $two['allowed'] && $two['counted'] );
		$this->assertFalse( $out['allowed'] );
		$this->assertSame( [ 11, 12 ], $out['state']['items'] );
	}

	public function test_an_item_already_counted_reads_again_for_free(): void {
		$state = [ 'period' => 'm:2026-10', 'items' => [ 11, 12 ] ];
		$again = Meter::consume( $state, 'm:2026-10', 11, 2 );

		$this->assertTrue( $again['allowed'] );
		$this->assertFalse( $again['counted'] );
	}

	public function test_a_new_period_starts_from_zero(): void {
		$next = Meter::consume( [ 'period' => 'm:2026-09', 'items' => [ 1, 2, 3 ] ], 'm:2026-10', 4, 3 );

		$this->assertTrue( $next['counted'] );
		$this->assertSame( [ 'period' => 'm:2026-10', 'items' => [ 4 ] ], $next['state'] );
	}

	public function test_meter_settings_are_clamped(): void {
		$this->assertSame( [ 'limit' => 100, 'period' => 'week' ], Rules::meter_row( [ 'limit' => 500, 'period' => 'week' ] ) );
		$this->assertSame( [ 'limit' => 0, 'period' => 'month' ], Rules::meter_row( [ 'limit' => -3, 'period' => 'day' ] ) );
		$this->assertSame( [ 'limit' => 0, 'period' => 'month' ], Rules::from_array( [] )->meter );
	}
}
