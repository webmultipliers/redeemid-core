<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Auth\OAuth;

/**
 * The sign-in page opens in the site's language.
 */
final class SignInLocaleTest extends TestCase {
	public function test_wordpress_locales_become_language_tags(): void {
		$this->assertSame( 'fr-CA', OAuth::locale( 'fr_CA' ) );
		$this->assertSame( 'de-DE', OAuth::locale( 'de_DE_formal' ), 'Variants after the region are dropped' );
		$this->assertSame( 'ja', OAuth::locale( 'ja' ) );
		$this->assertSame( 'pt-BR', OAuth::locale( 'pt_BR' ) );
		$this->assertSame( '', OAuth::locale( '' ) );
		$this->assertSame( '', OAuth::locale( 'not a locale' ) );
	}
}
