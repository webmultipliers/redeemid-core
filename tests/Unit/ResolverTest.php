<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Access\Checker;
use WP2_Shopify\Access\Context;
use WP2_Shopify\Access\Levels;
use WP2_Shopify\Access\Resolver;
use WP2_Shopify\Access\TermRule;
use WP2_Shopify\Entitlement\Decision;
use WP2_Shopify\Entitlement\Evaluator;
use WP2_Shopify\Entitlement\Member;

/**
 * The precedence table from the design doc, one test per row.
 */
final class ResolverTest extends TestCase {
	private const NOW = 1_800_000_000;
	private const DAY = 86400;

	private Levels $levels;

	protected function setUp(): void {
		$this->levels = Levels::from_config(
			[
				'members' => [ 'label' => 'Members', 'groups' => [ [ 'tags' => [ 'member' ] ] ] ],
				'silver'  => [ 'label' => 'Silver', 'groups' => [ [ 'tags' => [ 'silver-member' ] ] ], 'includes' => [ 'members' ] ],
				'gold'    => [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold-member' ] ] ], 'includes' => [ 'silver' ] ],
				'staff'   => [ 'label' => 'Staff', 'groups' => [ [ 'tags' => [ 'staff', 'verified' ], 'match' => 'all' ] ] ],
			]
		);
	}

	private function resolve( Context $c, int $now = self::NOW ) {
		return ( new Resolver( $this->levels ) )->resolve( $c, $now );
	}

	private function needs( string $term, array $levels, int $priority = 0 ): TermRule {
		return new TermRule( 'Category', $term, TermRule::REQUIRES, $levels, null, $priority );
	}

	private function opens( string $term, ?int $until ): TermRule {
		return new TermRule( 'Tag', $term, TermRule::OPENS, [], $until );
	}

	private function member( array $tags ): Member {
		return new Member( true, true, $tags, 'enabled', self::NOW - 60 );
	}

	private function checker(): Checker {
		return new Checker( $this->levels, new Evaluator() );
	}

	public function test_nothing_applies_means_open(): void {
		$rule = $this->resolve( new Context() );

		$this->assertTrue( $rule->open() );
		$this->assertFalse( $rule->meter_eligible );
	}

	public function test_post_type_rule_applies(): void {
		$rule = $this->resolve( new Context( type_levels: [ 'members' ], type_label: 'Post type: Lesson' ) );

		$this->assertSame( [ [ 'members' ] ], $rule->clauses );
		$this->assertSame( 'Post type: Lesson', $rule->source );
	}

	public function test_site_default_is_last(): void {
		$this->assertSame( [ [ 'members' ] ], $this->resolve( new Context( site_levels: [ 'members' ] ) )->clauses );
		$this->assertSame( [ [ 'gold' ] ], $this->resolve( new Context( type_levels: [ 'gold' ], site_levels: [ 'members' ] ) )->clauses );
	}

	public function test_term_beats_post_type(): void {
		$rule = $this->resolve( new Context( terms: [ $this->needs( 'Investigations', [ 'gold' ] ) ], type_levels: [ 'members' ] ) );

		$this->assertSame( [ [ 'gold' ] ], $rule->clauses );
		$this->assertSame( 'Category: Investigations', $rule->source );
	}

	public function test_item_public_beats_everything(): void {
		$rule = $this->resolve( new Context( item_mode: Context::ITEM_PUBLIC, terms: [ $this->needs( 'Investigations', [ 'gold' ] ) ], type_levels: [ 'members' ] ) );

		$this->assertTrue( $rule->open() );
		$this->assertSame( 'Item: Public', $rule->source );
	}

	public function test_item_level_replaces_inherited(): void {
		$rule = $this->resolve( new Context( item_mode: Context::ITEM_LEVEL, item_levels: [ 'members' ], terms: [ $this->needs( 'Investigations', [ 'gold' ] ) ] ) );

		$this->assertSame( [ [ 'members' ] ], $rule->clauses );
		$this->assertSame( 'Item', $rule->source );
	}

	public function test_item_level_with_no_level_fails_closed_to_signed_in(): void {
		$this->assertSame( [ [ 'signed-in' ] ], $this->resolve( new Context( item_mode: Context::ITEM_LEVEL ) )->clauses );
	}

	public function test_item_level_gives_a_rule_when_no_global_rule_exists(): void {
		$this->assertSame( [ [ 'silver' ] ], $this->resolve( new Context( item_mode: Context::ITEM_LEVEL, item_levels: [ 'silver' ] ) )->clauses );
	}

	public function test_item_add_ands_onto_inherited(): void {
		$rule = $this->resolve( new Context( item_mode: Context::ITEM_ADD, item_levels: [ 'staff' ], type_levels: [ 'members' ], type_label: 'Post type: Post' ) );

		$this->assertSame( [ [ 'members' ], [ 'staff' ] ], $rule->clauses );
		$this->assertSame( 'Post type: Post + Item', $rule->source );
	}

	public function test_item_add_of_a_narrower_level_collapses(): void {
		// Gold already covers a Members category, so Members + Gold is just Gold.
		$rule = $this->resolve( new Context( item_mode: Context::ITEM_ADD, item_levels: [ 'gold' ], terms: [ $this->needs( 'News', [ 'members' ] ) ] ) );

		$this->assertSame( [ [ 'gold' ] ], $rule->clauses );
	}

	public function test_most_restrictive_expands_inclusion_first(): void {
		$rule = $this->resolve( new Context( terms: [ $this->needs( 'Culture', [ 'silver' ] ), $this->needs( 'Investigations', [ 'gold' ] ) ] ) );

		$this->assertSame( [ [ 'gold' ] ], $rule->clauses, 'Gold includes Silver, so it is needed once' );
		$this->assertSame( 'Category: Investigations', $rule->source );
		$this->assertSame( [ 'Category: Culture', 'Category: Investigations' ], $rule->contributing );
		$this->assertSame( 'Gold, from Category: Investigations (also Category: Culture)', $rule->explain( $this->levels ) );
	}

	public function test_most_restrictive_keeps_unrelated_levels_both(): void {
		$rule = $this->resolve( new Context( terms: [ $this->needs( 'Culture', [ 'silver' ] ), $this->needs( 'Internal', [ 'staff' ] ) ] ) );

		$this->assertSame( [ [ 'silver' ], [ 'staff' ] ], $rule->clauses );
		$this->assertSame( 'Category: Culture + Category: Internal', $rule->source );
	}

	public function test_unknown_conflict_mode_falls_back_to_most_restrictive(): void {
		$rule = $this->resolve( new Context( terms: [ $this->needs( 'Culture', [ 'silver' ] ), $this->needs( 'Internal', [ 'staff' ] ) ], conflict: 'nonsense' ) );

		$this->assertSame( [ [ 'silver' ], [ 'staff' ] ], $rule->clauses );
	}

	public function test_least_restrictive_takes_any_and_drops_wider_levels(): void {
		$rule = $this->resolve( new Context( terms: [ $this->needs( 'Culture', [ 'silver' ] ), $this->needs( 'Investigations', [ 'gold' ] ) ], conflict: Context::CONFLICT_LEAST ) );

		$this->assertSame( [ [ 'silver' ] ], $rule->clauses, 'Gold holders pass Silver anyway' );
	}

	public function test_priority_mode_takes_highest_priority_term(): void {
		$rule = $this->resolve(
			new Context(
				terms: [ $this->needs( 'Culture', [ 'silver' ], 1 ), $this->needs( 'Investigations', [ 'gold' ], 5 ), $this->needs( 'Internal', [ 'staff' ], 2 ) ],
				conflict: Context::CONFLICT_PRIORITY
			)
		);

		$this->assertSame( [ [ 'gold' ] ], $rule->clauses );
		$this->assertSame( 'Category: Investigations', $rule->source );
		$this->assertCount( 3, $rule->contributing );
	}

	public function test_unexpired_opens_beats_requires(): void {
		$rule = $this->resolve( new Context( terms: [ $this->needs( 'Investigations', [ 'gold' ] ), $this->opens( 'Free this week', self::NOW + self::DAY ) ] ) );

		$this->assertTrue( $rule->open() );
		$this->assertSame( 'Tag: Free this week', $rule->source );
	}

	public function test_expired_opens_stops_applying_without_an_edit(): void {
		$rule = $this->resolve( new Context( terms: [ $this->needs( 'Investigations', [ 'gold' ] ), $this->opens( 'Free this week', self::NOW - 1 ) ] ) );

		$this->assertSame( [ [ 'gold' ] ], $rule->clauses );
		$this->assertContains( 'Tag: Free this week', $rule->contributing );
	}

	public function test_undated_opens_never_applies(): void {
		$this->assertSame( [ [ 'gold' ] ], $this->resolve( new Context( terms: [ $this->needs( 'Investigations', [ 'gold' ] ), $this->opens( 'Free', null ) ] ) )->clauses );
	}

	public function test_item_level_relocks_an_opened_item(): void {
		$rule = $this->resolve( new Context( item_mode: Context::ITEM_LEVEL, item_levels: [ 'gold' ], terms: [ $this->opens( 'Free this week', self::NOW + self::DAY ) ] ) );

		$this->assertSame( [ [ 'gold' ] ], $rule->clauses );
	}

	public function test_embargo_opens_on_its_date_at_request_time(): void {
		$c = new Context( type_levels: [ 'members' ], locked_until: self::NOW + self::DAY );

		$this->assertFalse( $this->resolve( $c )->open() );
		$this->assertTrue( $this->resolve( $c, self::NOW + self::DAY )->open() );
	}

	public function test_free_window_locks_after_its_date(): void {
		$c = new Context( type_levels: [ 'members' ], public_until: self::NOW + self::DAY );

		$this->assertTrue( $this->resolve( $c )->open() );
		$this->assertFalse( $this->resolve( $c, self::NOW + self::DAY )->open() );
	}

	public function test_post_type_archive_window_opens_old_items(): void {
		$c = new Context( type_levels: [ 'members' ], type_label: 'Post type: Post', published: self::NOW - 91 * self::DAY, type_public_after: 90 * self::DAY );

		$this->assertTrue( $this->resolve( $c )->open() );
		$this->assertSame( 'Post type: Post: archive window', $this->resolve( $c )->source );
	}

	public function test_windows_never_lock_an_open_item(): void {
		$this->assertTrue( $this->resolve( new Context( public_until: self::NOW - 1 ) )->open() );
	}

	public function test_meter_flag_on_locked_items_unless_exempt(): void {
		$this->assertTrue( $this->resolve( new Context( type_levels: [ 'members' ] ) )->meter_eligible );
		$this->assertFalse( $this->resolve( new Context( type_levels: [ 'members' ], meter_exempt: true ) )->meter_eligible );
	}

	public function test_public_level_in_a_rule_opens_it(): void {
		$this->assertTrue( $this->resolve( new Context( type_levels: [ 'public' ] ) )->open() );
	}

	public function test_checker_gold_member_reads_silver_story(): void {
		$rule = $this->resolve( new Context( terms: [ $this->needs( 'Culture', [ 'silver' ] ) ] ) );

		$this->assertTrue( $this->checker()->check( $rule, $this->member( [ 'Gold-Member' ] ), self::NOW )->granted() );
	}

	public function test_checker_silver_member_is_offered_gold(): void {
		$rule    = $this->resolve( new Context( terms: [ $this->needs( 'Culture', [ 'silver' ] ), $this->needs( 'Investigations', [ 'gold' ] ) ] ) );
		$verdict = $this->checker()->check( $rule, $this->member( [ 'silver-member' ] ), self::NOW );

		$this->assertFalse( $verdict->granted() );
		$this->assertSame( Decision::MISSING_TAGS, $verdict->decision->reason );
		$this->assertSame( 'gold', $verdict->next_level );
	}

	public function test_checker_guest_reason_wins_over_missing_tags(): void {
		$rule    = $this->resolve( new Context( type_levels: [ 'members' ] ) );
		$verdict = $this->checker()->check( $rule, Member::guest(), self::NOW );

		$this->assertSame( Decision::GUEST, $verdict->decision->reason );
	}

	public function test_checker_every_clause_must_pass(): void {
		$rule = $this->resolve( new Context( terms: [ $this->needs( 'Culture', [ 'silver' ] ), $this->needs( 'Internal', [ 'staff' ] ) ] ) );

		$this->assertFalse( $this->checker()->check( $rule, $this->member( [ 'silver-member' ] ), self::NOW )->granted() );
		$this->assertFalse( $this->checker()->check( $rule, $this->member( [ 'silver-member', 'staff' ] ), self::NOW )->granted(), 'Staff needs both of its tags' );
		$this->assertTrue( $this->checker()->check( $rule, $this->member( [ 'silver-member', 'staff', 'verified' ] ), self::NOW )->granted() );
	}

	public function test_checker_unknown_level_fails_closed(): void {
		$rule    = $this->resolve( new Context( type_levels: [ 'platinum' ] ) );
		$verdict = $this->checker()->check( $rule, $this->member( [ 'gold-member' ] ), self::NOW );

		$this->assertFalse( $verdict->granted() );
		$this->assertSame( Decision::BAD_RULE, $verdict->decision->reason );
	}

	public function test_checker_stale_mirror_fails_closed(): void {
		$rule  = $this->resolve( new Context( type_levels: [ 'gold' ] ) );
		$stale = new Member( true, true, [ 'gold-member' ], 'enabled', self::NOW - 30 * self::DAY );

		$this->assertSame( Decision::STALE, $this->checker()->check( $rule, $stale, self::NOW )->decision->reason );
	}
}
