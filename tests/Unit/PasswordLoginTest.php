<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Auth\PasswordLogin;

final class PasswordLoginTest extends TestCase {
	public function test_only_linked_members_lose_the_password_when_on(): void {
		$this->assertTrue( PasswordLogin::applies( true, true, false ) );
		$this->assertFalse( PasswordLogin::applies( false, true, false ), 'Off by default: linked users keep their password' );
		$this->assertFalse( PasswordLogin::applies( true, false, false ), 'Users with no Shopify link keep their password' );
		$this->assertFalse( PasswordLogin::applies( true, true, true ), 'Staff always keep the WordPress login' );
	}
}
