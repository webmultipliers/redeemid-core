<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Support\Health;

final class HealthTest extends TestCase {
	public function test_accepts_a_row_with_a_label_and_known_state(): void {
		$this->assertTrue( Health::valid( [ 'label' => 'Theme blocks', 'state' => 'ok', 'detail' => '' ] ) );
		$this->assertTrue( Health::valid( [ 'label' => 'Test content', 'state' => 'info' ] ) );
	}

	public function test_drops_rows_a_filter_got_wrong(): void {
		$this->assertFalse( Health::valid( 'ok' ) );
		$this->assertFalse( Health::valid( [ 'label' => '', 'state' => 'ok' ] ) );
		$this->assertFalse( Health::valid( [ 'label' => 'Unknown state', 'state' => 'great' ] ) );
		$this->assertFalse( Health::valid( [ 'state' => 'fail' ] ) );
	}
}
