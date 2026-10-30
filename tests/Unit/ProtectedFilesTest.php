<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Admin\FileFields;
use RedeemID\Files\Download;
use RedeemID\Files\Probe;
use RedeemID\Files\ProtectedFiles;

final class ProtectedFilesTest extends TestCase {
	private function allowed( ?array $rule, bool $reads_parent = false, array $levels = [], array $features = [] ): bool {
		return null !== $rule && ProtectedFiles::allowed(
			$rule,
			static fn(): bool => $reads_parent,
			static fn( string $slug ): bool => in_array( $slug, $levels, true ),
			static fn( string $slug ): bool => in_array( $slug, $features, true )
		);
	}

	public function test_range_header_parsing(): void {
		$this->assertNull( Download::range( null, 100 ) );
		$this->assertNull( Download::range( 'bytes=0-1,5-6', 100 ) );
		$this->assertNull( Download::range( 'bytes=-', 100 ) );
		$this->assertSame( [ 0, 99 ], Download::range( 'bytes=0-', 100 ) );
		$this->assertSame( [ 10, 19 ], Download::range( 'bytes=10-19', 100 ) );
		$this->assertSame( [ 90, 99 ], Download::range( 'bytes=90-500', 100 ) );
		$this->assertSame( [ 80, 99 ], Download::range( 'bytes=-20', 100 ) );
		$this->assertSame( [ 0, 99 ], Download::range( 'bytes=-500', 100 ) );
		$this->assertFalse( Download::range( 'bytes=100-', 100 ) );
		$this->assertFalse( Download::range( 'bytes=20-10', 100 ) );
		$this->assertFalse( Download::range( 'bytes=-0', 100 ) );
		$this->assertFalse( Download::range( 'bytes=0-', 0 ) );
	}

	public function test_sanitize_returns_null_for_a_public_file(): void {
		$this->assertNull( ProtectedFiles::sanitize( '' ) );
		$this->assertNull( ProtectedFiles::sanitize( [ 'mode' => 'everyone' ] ) );
	}

	public function test_sanitize_keeps_only_what_the_mode_uses(): void {
		$this->assertSame( [ 'mode' => 'levels', 'levels' => [ 'gold' ], 'feature' => '' ], ProtectedFiles::sanitize( [ 'mode' => 'levels', 'levels' => [ 'Gold', 'gold', '' ], 'feature' => 'x' ] ) );
		$this->assertSame( [ 'mode' => 'inherit', 'levels' => [], 'feature' => '' ], ProtectedFiles::sanitize( [ 'mode' => 'inherit', 'levels' => [ 'gold' ] ] ) );
	}

	public function test_inherit_follows_the_parent_item(): void {
		$rule = ProtectedFiles::sanitize( [ 'mode' => 'inherit' ] );

		$this->assertTrue( $this->allowed( $rule, reads_parent: true ) );
		$this->assertFalse( $this->allowed( $rule, reads_parent: false ) );
	}

	public function test_levels_need_any_one_level(): void {
		$rule = ProtectedFiles::sanitize( [ 'mode' => 'levels', 'levels' => [ 'gold', 'vip' ] ] );

		$this->assertTrue( $this->allowed( $rule, levels: [ 'vip' ] ) );
		$this->assertFalse( $this->allowed( $rule, levels: [ 'silver' ] ) );
		$this->assertFalse( $this->allowed( ProtectedFiles::sanitize( [ 'mode' => 'levels', 'levels' => [] ] ), levels: [ 'gold' ] ), 'no level grants nobody' );
	}

	public function test_feature_needs_the_feature(): void {
		$rule = ProtectedFiles::sanitize( [ 'mode' => 'feature', 'feature' => 'downloads' ] );

		$this->assertTrue( $this->allowed( $rule, features: [ 'downloads' ] ) );
		$this->assertFalse( $this->allowed( $rule, reads_parent: true, levels: [ 'gold' ] ) );
		$this->assertFalse( $this->allowed( ProtectedFiles::sanitize( [ 'mode' => 'feature', 'feature' => '' ] ), features: [ '' ] ) );
	}

	public function test_relocate_moves_into_and_out_of_the_protected_folder(): void {
		$this->assertSame( 'redeemid-protected/2026/10/guide.pdf', ProtectedFiles::relocate( '2026/10/guide.pdf', true ) );
		$this->assertSame( 'redeemid-protected/2026/10/guide.pdf', ProtectedFiles::relocate( 'redeemid-protected/2026/10/guide.pdf', true ) );
		$this->assertSame( '2026/10/guide.pdf', ProtectedFiles::relocate( 'redeemid-protected/2026/10/guide.pdf', false ) );
		$this->assertSame( '2026/10/guide.pdf', ProtectedFiles::relocate( '2026/10/guide.pdf', false ) );
	}

	public function test_disposition(): void {
		$this->assertSame( 'inline', Download::disposition( 'application/pdf' ) );
		$this->assertSame( 'inline', Download::disposition( 'image/jpeg' ) );
		$this->assertSame( 'attachment', Download::disposition( 'image/svg+xml' ), 'an SVG can carry script' );
		$this->assertSame( 'attachment', Download::disposition( 'application/zip' ) );
		$this->assertSame( 'attachment', Download::disposition( 'text/html' ) );
	}

	public function test_probe_verdict(): void {
		$this->assertSame( Probe::OPEN, Probe::verdict( 200, 'abc123', 'abc123' ) );
		$this->assertSame( Probe::CLOSED, Probe::verdict( 403, 'Forbidden', 'abc123' ) );
		$this->assertSame( Probe::CLOSED, Probe::verdict( 200, '<html>not found page</html>', 'abc123' ), 'a theme 404 page served with 200 is not the file' );
		$this->assertSame( Probe::UNKNOWN, Probe::verdict( 0, '', 'abc123' ) );
	}

	public function test_field_choices_round_trip(): void {
		foreach ( [ 'public', 'inherit', 'level:gold', 'feature:downloads' ] as $choice ) {
			$this->assertSame( $choice, FileFields::choice( FileFields::rule( $choice ) ) );
		}
	}
}
