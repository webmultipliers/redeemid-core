<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Support\ConfigValues;
use RedeemID\Support\Crypto;

final class ConfigValuesTest extends TestCase {
	private const DEFINITIONS = [
		'REDEEMID_STORE_DOMAIN'        => [ 'secret' => false ],
		'REDEEMID_CLIENT_ID'           => [ 'secret' => false ],
		'REDEEMID_ADMIN_CLIENT_SECRET' => [ 'secret' => true ],
		'REDEEMID_ENCRYPTION_KEY'      => [ 'secret' => true, 'editable' => false ],
	];

	private Crypto $crypto;

	protected function setUp(): void {
		$this->crypto = Crypto::from_config( base64_encode( str_repeat( 'k', 32 ) ), '' );
	}

	private function values( array $stored = [], array $constants = [], ?Crypto $crypto = null ): ConfigValues {
		$crypto ??= $this->crypto;

		return new ConfigValues(
			self::DEFINITIONS,
			static fn( string $name ): ?string => $constants[ $name ] ?? null,
			$stored,
			static fn(): ?Crypto => $crypto
		);
	}

	public function test_a_constant_wins_over_a_saved_value_and_locks_the_field(): void {
		$values = $this->values( [ 'REDEEMID_CLIENT_ID' => 'saved' ], [ 'REDEEMID_CLIENT_ID' => ' from-config ' ] );

		$this->assertSame( [ 'value' => 'from-config', 'source' => ConfigValues::CONSTANT ], $values->resolve( 'REDEEMID_CLIENT_ID' ) );
		$this->assertTrue( $values->locked( 'REDEEMID_CLIENT_ID' ) );

		$result = $values->apply( [ 'REDEEMID_CLIENT_ID' => 'attempt' ] );
		$this->assertSame( 'saved', $result['stored']['REDEEMID_CLIENT_ID'] );
		$this->assertSame( [], $result['changed'] );
	}

	public function test_saved_plain_values_are_read_back(): void {
		$this->assertSame( [ 'value' => 'abc', 'source' => ConfigValues::SETTINGS ], $this->values( [ 'REDEEMID_CLIENT_ID' => 'abc' ] )->resolve( 'REDEEMID_CLIENT_ID' ) );
		$this->assertSame( [ 'value' => '', 'source' => ConfigValues::NONE ], $this->values()->resolve( 'REDEEMID_CLIENT_ID' ) );
	}

	public function test_secrets_are_stored_encrypted_and_decrypted_on_read(): void {
		$result = $this->values()->apply( [ 'REDEEMID_ADMIN_CLIENT_SECRET' => 'shpss_secret' ] );
		$stored = $result['stored']['REDEEMID_ADMIN_CLIENT_SECRET'];

		$this->assertTrue( Crypto::is_encrypted( $stored ) );
		$this->assertStringNotContainsString( 'shpss_secret', $stored );
		$this->assertSame( [ 'REDEEMID_ADMIN_CLIENT_SECRET' ], $result['changed'] );
		$this->assertSame( 'shpss_secret', $this->values( $result['stored'] )->resolve( 'REDEEMID_ADMIN_CLIENT_SECRET' )['value'] );
	}

	public function test_a_blank_secret_keeps_the_saved_one_and_clear_removes_it(): void {
		$stored = [ 'REDEEMID_ADMIN_CLIENT_SECRET' => $this->crypto->encrypt( 'keep-me' ) ];

		$kept = $this->values( $stored )->apply( [ 'REDEEMID_ADMIN_CLIENT_SECRET' => '' ] );
		$this->assertSame( $stored, $kept['stored'] );
		$this->assertSame( [], $kept['changed'] );

		$same = $this->values( $stored )->apply( [ 'REDEEMID_ADMIN_CLIENT_SECRET' => 'keep-me' ] );
		$this->assertSame( $stored, $same['stored'], 'Re-entering the same secret does not rewrite it.' );

		$cleared = $this->values( $stored )->apply( [], [ 'REDEEMID_ADMIN_CLIENT_SECRET' ] );
		$this->assertArrayNotHasKey( 'REDEEMID_ADMIN_CLIENT_SECRET', $cleared['stored'] );
		$this->assertSame( [ 'REDEEMID_ADMIN_CLIENT_SECRET' ], $cleared['changed'] );
	}

	public function test_a_blank_plain_field_removes_the_saved_value(): void {
		$result = $this->values( [ 'REDEEMID_CLIENT_ID' => 'abc' ] )->apply( [ 'REDEEMID_CLIENT_ID' => ' ' ] );

		$this->assertArrayNotHasKey( 'REDEEMID_CLIENT_ID', $result['stored'] );
		$this->assertSame( [ 'REDEEMID_CLIENT_ID' ], $result['changed'] );
	}

	public function test_a_secret_from_another_key_is_unreadable_not_used(): void {
		$other  = Crypto::from_config( base64_encode( str_repeat( 'o', 32 ) ), '' );
		$values = $this->values( [ 'REDEEMID_ADMIN_CLIENT_SECRET' => $other->encrypt( 'old' ) ] );

		$this->assertSame( [ 'value' => '', 'source' => ConfigValues::UNREADABLE ], $values->resolve( 'REDEEMID_ADMIN_CLIENT_SECRET' ) );
	}

	public function test_without_a_key_secrets_are_refused_not_stored_in_plain_text(): void {
		$values = new ConfigValues( self::DEFINITIONS, static fn(): ?string => null, [], static fn(): ?Crypto => null );
		$result = $values->apply( [ 'REDEEMID_ADMIN_CLIENT_SECRET' => 'shpss_secret', 'REDEEMID_CLIENT_ID' => 'abc' ] );

		$this->assertSame( [ 'REDEEMID_ADMIN_CLIENT_SECRET' => ConfigValues::NO_KEY ], $result['errors'] );
		$this->assertSame( [ 'REDEEMID_CLIENT_ID' => 'abc' ], $result['stored'] );
	}

	public function test_the_encryption_key_is_never_read_from_or_saved_to_the_option(): void {
		$values = $this->values( [ 'REDEEMID_ENCRYPTION_KEY' => 'planted' ] );

		$this->assertFalse( $values->editable( 'REDEEMID_ENCRYPTION_KEY' ) );
		$this->assertSame( '', $values->resolve( 'REDEEMID_ENCRYPTION_KEY' )['value'] );
		$this->assertSame( [ 'REDEEMID_ENCRYPTION_KEY' => 'planted' ], $values->apply( [ 'REDEEMID_ENCRYPTION_KEY' => 'new' ] )['stored'] );
	}

	public function test_the_store_domain_is_normalised_and_must_be_myshopify(): void {
		$ok = $this->values()->apply( [ 'REDEEMID_STORE_DOMAIN' => 'HTTPS://Demo-Store.myshopify.com/admin' ] );
		$this->assertSame( 'demo-store.myshopify.com', $ok['stored']['REDEEMID_STORE_DOMAIN'] );

		$bad = $this->values( [ 'REDEEMID_STORE_DOMAIN' => 'demo-store.myshopify.com' ] )->apply( [ 'REDEEMID_STORE_DOMAIN' => 'shop.example.com' ] );
		$this->assertSame( [ 'REDEEMID_STORE_DOMAIN' => ConfigValues::BAD_DOMAIN ], $bad['errors'] );
		$this->assertSame( 'demo-store.myshopify.com', $bad['stored']['REDEEMID_STORE_DOMAIN'], 'An invalid value keeps the previous one.' );
	}

	public function test_values_with_spaces_or_line_breaks_are_refused(): void {
		$result = $this->values()->apply( [ 'REDEEMID_CLIENT_ID' => "abc\ndef", 'REDEEMID_ADMIN_CLIENT_SECRET' => 'a b' ] );

		$this->assertSame( ConfigValues::BAD_FORMAT, $result['errors']['REDEEMID_CLIENT_ID'] );
		$this->assertSame( ConfigValues::BAD_FORMAT, $result['errors']['REDEEMID_ADMIN_CLIENT_SECRET'] );
		$this->assertSame( [], $result['stored'] );
		$this->assertSame( ConfigValues::TOO_LONG, ConfigValues::invalid( 'REDEEMID_CLIENT_ID', str_repeat( 'a', ConfigValues::MAX_LENGTH + 1 ) ) );
	}

	public function test_non_scalar_input_is_treated_as_blank(): void {
		$result = $this->values( [ 'REDEEMID_CLIENT_ID' => 'abc' ] )->apply( [ 'REDEEMID_CLIENT_ID' => [ 'x' ] ] );

		$this->assertArrayNotHasKey( 'REDEEMID_CLIENT_ID', $result['stored'] );
	}
}
