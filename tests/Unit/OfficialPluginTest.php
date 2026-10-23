<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP2_Shopify\Integrations\OfficialPlugin;

final class OfficialPluginTest extends TestCase {
	public static function states(): array {
		return [
			'not loaded'                 => [ '', 'hf96dz-hn.myshopify.com', 'hf96dz-hn.myshopify.com', OfficialPlugin::ABSENT ],
			'loaded, not connected'      => [ '1.0.0', '', 'hf96dz-hn.myshopify.com', OfficialPlugin::UNCONNECTED ],
			'same store'                 => [ '1.0.0', 'hf96dz-hn.myshopify.com', 'hf96dz-hn.myshopify.com', OfficialPlugin::SAME_STORE ],
			'same store, other spelling' => [ '1.0.0', 'https://HF96DZ-HN.myshopify.com/', 'hf96dz-hn.myshopify.com', OfficialPlugin::SAME_STORE ],
			'other store'                => [ '1.0.0', 'another-store.myshopify.com', 'hf96dz-hn.myshopify.com', OfficialPlugin::OTHER_STORE ],
			'WP2 not configured'         => [ '1.0.0', 'hf96dz-hn.myshopify.com', '', OfficialPlugin::SAME_STORE ],
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
}
