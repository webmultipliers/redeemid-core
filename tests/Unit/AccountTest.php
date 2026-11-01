<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Blocks\Account;

final class AccountTest extends TestCase {
	public function test_gift_line_shows_until_the_gift_ends(): void {
		$this->assertSame( '2026-12-01', Account::upcoming( '2026-12-01', '2026-11-01' ) );
		$this->assertSame( '2026-11-01', Account::upcoming( '2026-11-01', '2026-11-01' ) );
		$this->assertSame( '', Account::upcoming( '2026-10-31', '2026-11-01' ) );
		$this->assertSame( '', Account::upcoming( '', '2026-11-01' ) );
	}
}
