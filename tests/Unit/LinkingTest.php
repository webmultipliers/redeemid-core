<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Auth\Linking;

/**
 * Who may link to, or sign in through, a Shopify customer.
 */
final class LinkingTest extends TestCase {
	public function test_other_sites_skips_this_one_and_junk(): void {
		$this->assertSame( [ 1, 3 ], Linking::other_sites( [ 1, '2', 3, 3, 0 ], 2 ), 'Staff anywhere on a network counts: check every other site once' );
		$this->assertSame( [], Linking::other_sites( [ 2 ], 2 ) );
	}
}
