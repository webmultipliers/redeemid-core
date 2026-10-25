<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Access\Teaser;

final class TeaserTest extends TestCase {
	private static function block( string $name, string $text = '', array $inner = [] ): array {
		$html = '' === $text ? '' : '<p>' . $text . '</p>';

		return [ 'blockName' => $name, 'attrs' => [], 'innerBlocks' => $inner, 'innerHTML' => $html, 'innerContent' => [ $html ] ];
	}

	private static function gap(): array {
		return [ 'blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n\n", 'innerContent' => [ "\n\n" ] ];
	}

	private static function p( string $text ): array {
		return self::block( 'core/paragraph', $text );
	}

	private static function brk(): array {
		return self::block( Teaser::BREAK_BLOCK );
	}

	private static function texts( array $cut ): array {
		return array_values( array_filter( array_map( static fn( $b ) => trim( strip_tags( $b['innerHTML'] ) ), $cut['blocks'] ), 'strlen' ) );
	}

	public function test_break_beats_the_post_type_setting(): void {
		$cut = ( new Teaser( Teaser::MODE_BLOCKS, 5 ) )->cut( [ self::p( 'one' ), self::gap(), self::brk(), self::gap(), self::p( 'SECRET' ), self::p( 'more' ) ], 'An excerpt' );

		$this->assertSame( Teaser::SOURCE_BREAK, $cut['source'] );
		$this->assertSame( [ 'one' ], self::texts( $cut ) );
	}

	public function test_break_at_the_top_keeps_nothing_even_with_an_excerpt(): void {
		$cut = ( new Teaser() )->cut( [ self::brk(), self::p( 'SECRET' ) ], 'An excerpt' );

		$this->assertSame( Teaser::SOURCE_BREAK, $cut['source'] );
		$this->assertSame( [], $cut['blocks'] );
	}

	public function test_nested_break_locks_its_whole_container(): void {
		$group = self::block( 'core/group', '', [ self::p( 'inside before' ), self::brk(), self::p( 'SECRET' ) ] );
		$cut   = ( new Teaser() )->cut( [ self::p( 'intro' ), $group, self::p( 'after' ) ] );

		$this->assertSame( [ 'intro' ], self::texts( $cut ) );
	}

	public function test_first_n_blocks_without_a_break(): void {
		$cut = ( new Teaser( Teaser::MODE_BLOCKS, 2 ) )->cut( [ self::p( 'a' ), self::gap(), self::p( 'b' ), self::gap(), self::p( 'SECRET' ) ] );

		$this->assertSame( Teaser::SOURCE_SETTING, $cut['source'] );
		$this->assertSame( [ 'a', 'b' ], self::texts( $cut ) );
		$this->assertSame( 'core/paragraph', end( $cut['blocks'] )['blockName'], 'No trailing whitespace chunk' );
	}

	public function test_words_mode_never_cuts_a_block_in_half(): void {
		$cut = ( new Teaser( Teaser::MODE_WORDS, 6 ) )->cut( [ self::p( 'one two three' ), self::p( 'four five six seven' ), self::p( 'SECRET' ) ] );

		$this->assertSame( [ 'one two three' ], self::texts( $cut ) );
	}

	public function test_words_count_nested_blocks(): void {
		$group = self::block( 'core/group', '', [ self::p( 'one two three four five' ) ] );
		$cut   = ( new Teaser( Teaser::MODE_WORDS, 3 ) )->cut( [ $group ], '' );

		$this->assertSame( Teaser::SOURCE_NONE, $cut['source'] );
	}

	public function test_excerpt_is_the_last_resort(): void {
		$cut = ( new Teaser( Teaser::MODE_EXCERPT ) )->cut( [ self::p( 'SECRET' ) ], 'Hand <b>written</b>' );

		$this->assertSame( Teaser::SOURCE_EXCERPT, $cut['source'] );
		$this->assertSame( '<p>Hand &lt;b&gt;written&lt;/b&gt;</p>', $cut['blocks'][0]['innerHTML'] );
	}

	public function test_setting_that_keeps_nothing_falls_back_to_the_excerpt(): void {
		$cut = ( new Teaser( Teaser::MODE_BLOCKS, 0 ) )->cut( [ self::p( 'SECRET' ) ], 'Excerpt' );

		$this->assertSame( Teaser::SOURCE_EXCERPT, $cut['source'] );
	}

	public function test_none_mode_keeps_nothing_and_ignores_the_excerpt(): void {
		$cut = ( new Teaser( Teaser::MODE_NONE ) )->cut( [ self::p( 'SECRET' ) ], 'Excerpt' );

		$this->assertSame( [ 'blocks' => [], 'source' => Teaser::SOURCE_NONE ], $cut );
	}

	public function test_classic_content_is_never_counted_as_one_block(): void {
		$classic = [ 'blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<p>Intro</p><p>SECRET</p>', 'innerContent' => [ '' ] ];
		$cut     = ( new Teaser( Teaser::MODE_BLOCKS, 2 ) )->cut( [ $classic ] );

		$this->assertSame( Teaser::SOURCE_NONE, $cut['source'] );
	}

	public function test_from_array_cleans_input(): void {
		$this->assertEquals( new Teaser( Teaser::MODE_BLOCKS, 0 ), Teaser::from_array( [ 'mode' => 'bogus', 'count' => -4 ] ) );
		$this->assertEquals( new Teaser( Teaser::MODE_WORDS, 1000 ), Teaser::from_array( [ 'mode' => 'words', 'count' => 99999 ] ) );
	}
}
