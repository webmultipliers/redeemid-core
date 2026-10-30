<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Privacy;
use RedeemID\Sync\Mirror;

final class PrivacyTest extends TestCase {
	public function test_export_covers_every_mirrored_key(): void {
		$this->assertSame( [], array_values( array_diff( Mirror::ALL_KEYS, Privacy::EXPORTED ) ) );
	}

	public function test_erase_covers_request_bookkeeping(): void {
		$this->assertContains( '_redeemid_last_sync_request', Privacy::ALSO_ERASED );
	}
}
