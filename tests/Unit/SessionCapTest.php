<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Auth\SessionCap;

final class SessionCapTest extends TestCase {
	private const NOW = 1_800_000_000;

	private static function session( int $login, int $ttl = 3600 ): array {
		return [ 'login' => $login, 'expiration' => self::NOW + $ttl ];
	}

	public function test_under_the_cap_keeps_everything(): void {
		$sessions = [ 'a' => self::session( 1 ), 'b' => self::session( 2 ) ];

		$this->assertSame( $sessions, SessionCap::keep( $sessions, 2, 'b', self::NOW ) );
	}

	public function test_off_keeps_every_live_session(): void {
		$sessions = [ 'a' => self::session( 1 ), 'b' => self::session( 2 ), 'c' => self::session( 3 ) ];

		$this->assertCount( 3, SessionCap::keep( $sessions, 0, 'c', self::NOW ) );
	}

	public function test_over_the_cap_ends_the_oldest(): void {
		$sessions = [ 'old' => self::session( 1 ), 'mid' => self::session( 2 ), 'new' => self::session( 3 ) ];

		$this->assertSame( [ 'new', 'mid' ], array_keys( SessionCap::keep( $sessions, 2, 'new', self::NOW ) ) );
	}

	public function test_the_sign_in_that_just_happened_always_stays(): void {
		// A clock skew could make the new session look older than the others.
		$sessions = [ 'a' => self::session( 5 ), 'b' => self::session( 6 ), 'now' => self::session( 1 ) ];

		$this->assertSame( [ 'now' ], array_keys( SessionCap::keep( $sessions, 1, 'now', self::NOW ) ) );
	}

	public function test_expired_sessions_do_not_count(): void {
		$sessions = [ 'dead' => self::session( 1, -10 ), 'a' => self::session( 2 ), 'b' => self::session( 3 ) ];

		$this->assertSame( [ 'a', 'b' ], array_keys( SessionCap::keep( $sessions, 2, 'b', self::NOW ) ) );
	}
}
