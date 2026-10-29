<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Access\Badges;

final class BadgeInkTest extends TestCase {
	public function test_light_colours_get_black_text(): void {
		// The Badge colour field's own placeholder: white on it is 2.4:1.
		$this->assertSame( '#000', Badges::ink( '#c9a227' ) );
		$this->assertSame( '#000', Badges::ink( '#ffffff' ) );
		$this->assertSame( '#000', Badges::ink( '#fc0' ) );
	}

	public function test_dark_colours_get_white_text(): void {
		$this->assertSame( '#fff', Badges::ink( '#1c1f23' ) );
		$this->assertSame( '#fff', Badges::ink( '#8a1c1c' ) );
		$this->assertSame( '#fff', Badges::ink( '#2271b1' ) );
	}

	public function test_anything_else_keeps_the_old_white(): void {
		$this->assertSame( '#fff', Badges::ink( '' ) );
		$this->assertSame( '#fff', Badges::ink( 'gold' ) );
	}
}
