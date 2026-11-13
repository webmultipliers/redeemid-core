<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Commerce\Arrivals;

/**
 * Visitors sent over by the store's redirect theme: discount links and
 * store sign-in.
 */
final class ArrivalsTest extends TestCase {
	public function test_only_requests_with_the_themes_parameters_arrive(): void {
		$this->assertTrue( Arrivals::carries( [ 'discount' => 'SAVE10' ] ) );
		$this->assertTrue( Arrivals::carries( [ 'variant' => '2', 'logged_in' => 'true' ] ) );
		$this->assertTrue( Arrivals::carries( [ 'company_location_id' => '9' ] ) );
		$this->assertFalse( Arrivals::carries( [ 'q' => 'gold', 'redeemid_notice' => 'cart_added' ] ) );
		$this->assertFalse( Arrivals::carries( [] ) );
	}

	public function test_discount_codes_are_what_a_link_could_carry(): void {
		$this->assertSame( 'SAVE10', Arrivals::code( '  SAVE10 ' ) );
		$this->assertSame( 'Spring sale 20%', Arrivals::code( 'Spring sale 20%' ) );
		$this->assertSame( '', Arrivals::code( '' ) );
		$this->assertSame( '', Arrivals::code( "a\nb" ) );
		$this->assertSame( '', Arrivals::code( str_repeat( 'x', 256 ) ) );
	}
}
