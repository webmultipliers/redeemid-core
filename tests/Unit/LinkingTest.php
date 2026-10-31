<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Auth\Linking;
use RedeemID\Auth\Pkce;

/**
 * A held sign-in (the "existing account" and "confirm link" steps) is bound
 * to the browser that started it: a ref copied from a URL is worthless in
 * any other browser.
 */
final class LinkingTest extends TestCase {
	public function test_held_record_opens_only_for_its_browser(): void {
		$cookie  = Pkce::random( 32 );
		$pending = [ 'kind' => 'existing', 'browser' => hash( 'sha256', $cookie ) ];

		$this->assertTrue( Linking::bound( $pending, $cookie ) );
		$this->assertFalse( Linking::bound( $pending, Pkce::random( 32 ) ) );
		$this->assertFalse( Linking::bound( $pending, '' ) );
	}

	public function test_record_without_a_browser_binding_is_refused(): void {
		$cookie = Pkce::random( 32 );

		$this->assertFalse( Linking::bound( [ 'kind' => 'existing' ], $cookie ) );
		$this->assertFalse( Linking::bound( [ 'kind' => 'existing', 'browser' => '' ], $cookie ) );
		$this->assertFalse( Linking::bound( [ 'kind' => 'existing', 'browser' => [ 'x' ] ], $cookie ) );
	}
}
