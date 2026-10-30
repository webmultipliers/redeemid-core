<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedeemID\Integrations\OfficialPlugin;

final class OfficialPluginTest extends TestCase {
	public static function states(): array {
		return [
			'not loaded'                 => [ '', 'example-store.myshopify.com', 'example-store.myshopify.com', OfficialPlugin::ABSENT ],
			'loaded, not connected'      => [ '1.0.0', '', 'example-store.myshopify.com', OfficialPlugin::UNCONNECTED ],
			'same store'                 => [ '1.0.0', 'example-store.myshopify.com', 'example-store.myshopify.com', OfficialPlugin::SAME_STORE ],
			'same store, other spelling' => [ '1.0.0', 'https://EXAMPLE-STORE.myshopify.com/', 'example-store.myshopify.com', OfficialPlugin::SAME_STORE ],
			'other store'                => [ '1.0.0', 'another-store.myshopify.com', 'example-store.myshopify.com', OfficialPlugin::OTHER_STORE ],
			'RedeemID not configured'         => [ '1.0.0', 'example-store.myshopify.com', '', OfficialPlugin::SAME_STORE ],
		];
	}

	#[DataProvider( 'states' )]
	public function test_classify( string $version, string $theirs, string $ours, string $expected ): void {
		$this->assertSame( $expected, OfficialPlugin::classify( $version, $theirs, $ours ) );
	}

	public function test_normalise_strips_scheme_path_and_case(): void {
		$this->assertSame( 'shop.myshopify.com', OfficialPlugin::normalise( ' HTTPS://Shop.myshopify.com/admin ' ) );
		$this->assertSame( '', OfficialPlugin::normalise( '' ) );
	}

	public function test_advisories_cover_versions_up_to_the_affected_one(): void {
		$this->assertSame( [ 'CVE-2026-57748' ], array_column( OfficialPlugin::advisories( '1.0.0' ), 'id' ) );
		$this->assertSame( [ 'CVE-2026-57748' ], array_column( OfficialPlugin::advisories( '0.9.1' ), 'id' ) );
		$this->assertSame( [], OfficialPlugin::advisories( '1.0.1' ) );
		$this->assertSame( [], OfficialPlugin::advisories( '' ), 'not installed' );
	}
}
