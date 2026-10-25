<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedeemID\Access\Badges;
use RedeemID\Access\BlockAccess;
use RedeemID\Access\Cli;
use RedeemID\Access\Context;
use RedeemID\Access\EffectiveRule;
use RedeemID\Access\Explain;
use RedeemID\Access\Levels;
use RedeemID\Access\Teaser;
use RedeemID\Access\TermRule;

final class BlockAccessTest extends TestCase {
	private const NOW = 1800000000;

	private static function block( string $name, array $access = [], array $inner = [], string $html = '' ): array {
		$content = $inner ? array_merge( [ '<div>' ], array_fill( 0, count( $inner ), null ), [ '</div>' ] ) : [ $html ];

		return [
			'blockName'    => $name,
			'attrs'        => $access ? [ BlockAccess::ATTR => $access ] : [],
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => $content,
		];
	}

	public function test_setting_defaults_to_everyone_and_cleans_levels(): void {
		$none = [ 'feature' => '', 'when' => BlockAccess::HAS ];

		$this->assertSame( [ 'show' => BlockAccess::EVERYONE, 'levels' => [] ] + $none, BlockAccess::setting( self::block( 'core/paragraph' ) ) );
		$this->assertSame( [ 'show' => BlockAccess::EVERYONE, 'levels' => [] ] + $none, BlockAccess::setting( self::block( 'core/paragraph', [ 'show' => 'nobody' ] ) ), 'Unknown values are ignored' );
		$this->assertSame( [ 'show' => BlockAccess::LEVELS, 'levels' => [ 'gold' ] ] + $none, BlockAccess::setting( self::block( 'core/paragraph', [ 'show' => 'levels', 'levels' => [ ' Gold ', 'gold', '' ] ] ) ) );
	}

	public function test_the_break_and_gate_areas_ignore_a_setting(): void {
		$this->assertSame( BlockAccess::EVERYONE, BlockAccess::setting( self::block( Teaser::BREAK_BLOCK, [ 'show' => 'readers' ] ) )['show'] );
		$this->assertSame( BlockAccess::EVERYONE, BlockAccess::setting( self::block( 'redeemid/membership-gate-content', [ 'show' => 'locked' ] ) )['show'] );
	}

	/**
	 * @return array<string, array{0: array, 1: bool, 2: string[], 3: bool}>
	 */
	public static function visibility(): array {
		$gold = [ 'show' => BlockAccess::LEVELS, 'levels' => [ 'gold' ] ];

		return [
			'everyone, locked visitor'          => [ [ 'show' => 'everyone', 'levels' => [] ], false, [], true ],
			'readers, reader'                   => [ [ 'show' => 'readers', 'levels' => [] ], true, [], true ],
			'readers, locked visitor'           => [ [ 'show' => 'readers', 'levels' => [] ], false, [], false ],
			'levels, reader with gold'          => [ $gold, true, [ 'gold' ], true ],
			'levels, reader without gold'       => [ $gold, true, [ 'silver' ], false ],
			'levels, gold holder locked out'    => [ $gold, false, [ 'gold' ], false ],
			'levels with none chosen'           => [ [ 'show' => 'levels', 'levels' => [] ], true, [ 'gold' ], false ],
			'locked, locked visitor'            => [ [ 'show' => 'locked', 'levels' => [] ], false, [], true ],
			'locked, reader'                    => [ [ 'show' => 'locked', 'levels' => [] ], true, [], false ],
			'without gold, silver reader'       => [ [ 'show' => 'without', 'levels' => [ 'gold' ] ], true, [ 'silver' ], true ],
			'without gold, gold reader'         => [ [ 'show' => 'without', 'levels' => [ 'gold' ] ], true, [ 'gold' ], false ],
			'without gold, guest'               => [ [ 'show' => 'without', 'levels' => [ 'gold' ] ], false, [], true ],
		];
	}

	#[DataProvider( 'visibility' )]
	public function test_visible( array $setting, bool $reads, array $held, bool $expected ): void {
		$this->assertSame( $expected, BlockAccess::visible( $setting, $reads, static fn( string $slug ) => in_array( $slug, $held, true ) ) );
	}

	public function test_public_text_strip_drops_reader_blocks_at_any_depth(): void {
		$blocks = [
			self::block( 'core/paragraph', [], [], '<p>LEAD</p>' ),
			self::block(
				'core/group',
				[],
				[
					self::block( 'core/paragraph', [ 'show' => 'readers' ], [], '<p>SECRET-READERS</p>' ),
					self::block( 'core/paragraph', [ 'show' => 'locked' ], [], '<p>UPGRADE</p>' ),
					self::block( 'core/paragraph', [ 'show' => 'levels', 'levels' => [ 'gold' ] ], [], '<p>SECRET-GOLD</p>' ),
					self::block( 'core/paragraph', [ 'show' => 'without', 'levels' => [ 'gold' ] ], [], '<p>GO-GOLD</p>' ),
				]
			),
		];

		$kept  = Teaser::strip( $blocks, [ BlockAccess::class, 'members_only' ] );
		$group = $kept[1];

		$this->assertCount( 2, $group['innerBlocks'] );
		$this->assertSame( [ '<p>UPGRADE</p>', '<p>GO-GOLD</p>' ], array_column( $group['innerBlocks'], 'innerHTML' ) );
		$this->assertSame( [ '<div>', null, null, '</div>' ], $group['innerContent'], 'One placeholder per kept block' );
	}

	public function test_conditions_lists_nested_settings(): void {
		$blocks = [ self::block( 'core/group', [ 'show' => 'readers' ], [ self::block( 'core/paragraph', [ 'show' => 'locked' ] ) ] ) ];

		$this->assertSame(
			[
				[ 'name' => 'core/group', 'show' => 'readers', 'levels' => [], 'feature' => '', 'when' => BlockAccess::HAS ],
				[ 'name' => 'core/paragraph', 'show' => 'locked', 'levels' => [], 'feature' => '', 'when' => BlockAccess::HAS ],
			],
			BlockAccess::conditions( $blocks )
		);
	}

	public function test_post_classes(): void {
		$this->assertSame( [], Badges::classes( new EffectiveRule( [], 'Default' ), false ) );
		$this->assertSame(
			[ 'redeemid-gated', 'is-locked', 'requires-gold', 'requires-silver' ],
			Badges::classes( new EffectiveRule( [ [ 'gold' ], [ 'silver', 'gold' ] ], 'Category: X' ), true )
		);
		$this->assertContains( 'is-unlocked', Badges::classes( new EffectiveRule( [ [ 'gold' ] ], 'Item' ), false ) );
	}

	public function test_explain_layers_in_words(): void {
		$levels = Levels::from_config( [ 'gold' => [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold' ] ] ] ] ] );
		$lines  = Explain::layers(
			new Context(
				item_mode: Context::ITEM_ADD,
				item_levels: [ 'gold' ],
				terms: [ new TermRule( 'Tag', 'Free', TermRule::OPENS, [], self::NOW - 1 ), new TermRule( 'Category', 'News', TermRule::REQUIRES, [ 'nope' ] ) ],
				type_levels: [],
				type_label: 'Post type: Posts',
				type_public_after: 90 * 86400,
			),
			$levels,
			self::NOW
		);

		$this->assertSame( 'Item: the inherited rule, plus Gold.', $lines[0] );
		$this->assertStringContainsString( '(not in force)', $lines[1], 'An expired Opens term says so' );
		$this->assertSame( 'Category: News: requires nope (unknown level).', $lines[2] );
		$this->assertSame( 'Post type: Posts: no rule, open after 90 days.', $lines[3] );
		$this->assertStringContainsString( 'most restrictive', $lines[4] );
	}

	public function test_cli_lines(): void {
		$lines = Cli::lines(
			[
				'post'           => [ 'id' => 7, 'title' => 'Story', 'type' => 'post', 'status' => 'publish' ],
				'gated'          => true,
				'layers'         => [ 'Item: inherits.' ],
				'rule'           => 'Gold, from Category: Investigations',
				'meter_eligible' => true,
				'next_change'    => null,
				'granted'        => false,
				'reason'         => 'missing_tags',
				'offer'          => 'Gold',
				'sees'           => 'The teaser.',
				'blocks'         => [ [ 'name' => 'core/paragraph', 'show' => 'locked', 'levels' => [], 'visible' => true ] ],
				'surfaces'       => [ 'Search: public text.' ],
			]
		);

		$this->assertSame( 'Story (#7, post, publish)', $lines[0] );
		$this->assertContains( '1. Item: inherits.', $lines );
		$this->assertContains( 'Rule:    Gold, from Category: Investigations [meter-eligible]', $lines );
		$this->assertContains( 'Viewer:  locked (missing_tags, wall offers Gold)', $lines );
		$this->assertContains( 'Block:   core/paragraph, locked: shown', $lines );
	}
}
