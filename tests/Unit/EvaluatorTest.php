<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Entitlement\Decision;
use WP2_Shopify\Entitlement\Evaluator;
use WP2_Shopify\Entitlement\Member;
use WP2_Shopify\Entitlement\Rule;

final class EvaluatorTest extends TestCase {
	private const NOW = 1_800_000_000;

	private function member( array $tags = [ 'VIP' ], string $status = 'enabled', ?int $synced = self::NOW - 60, bool $failed = false, bool $linked = true ): Member {
		return new Member( true, $linked, $tags, $status, $synced, $failed );
	}

	private function decide( Rule $rule, Member $member, string $stale = Evaluator::STALE_HONOR ): Decision {
		return ( new Evaluator( 7 * 86400, $stale ) )->decide( $rule, $member, self::NOW );
	}

	public function test_guest_is_locked_out_of_tag_rules_and_login_rules(): void {
		$this->assertSame( Decision::GUEST, $this->decide( new Rule( [ 'vip' ] ), Member::guest() )->reason );
		$this->assertSame( Decision::GUEST, $this->decide( new Rule( [], Rule::MATCH_ANY, true ), Member::guest() )->reason );
	}

	public function test_empty_rule_without_login_is_public(): void {
		$this->assertTrue( $this->decide( new Rule( [], Rule::MATCH_ANY, false ), Member::guest() )->granted );
	}

	public function test_signed_in_passes_login_only_rule_even_unlinked(): void {
		$this->assertTrue( $this->decide( new Rule(), $this->member( [], linked: false ) )->granted );
	}

	public function test_any_match_is_case_insensitive(): void {
		$this->assertTrue( $this->decide( new Rule( [ 'vip', 'gold' ] ), $this->member( [ ' vip ' ] ) )->granted );
	}

	public function test_all_match_requires_every_tag(): void {
		$rule = new Rule( [ 'vip', 'gold' ], Rule::MATCH_ALL );

		$this->assertSame( Decision::MISSING_TAGS, $this->decide( $rule, $this->member( [ 'vip' ] ) )->reason );
		$this->assertTrue( $this->decide( $rule, $this->member( [ 'Gold', 'VIP', 'other' ] ) )->granted );
	}

	public function test_unlinked_user_cannot_satisfy_tag_rule(): void {
		$this->assertSame( Decision::NOT_LINKED, $this->decide( new Rule( [ 'vip' ] ), $this->member( linked: false ) )->reason );
	}

	public function test_disabled_customer_is_locked_even_with_tags(): void {
		$this->assertSame( Decision::DISABLED, $this->decide( new Rule( [ 'vip' ] ), $this->member( status: 'disabled' ) )->reason );
	}

	public function test_never_synced_mirror_fails_closed(): void {
		$this->assertSame( Decision::NO_MIRROR, $this->decide( new Rule( [ 'vip' ] ), $this->member( synced: null ) )->reason );
	}

	public function test_mirror_older_than_window_fails_closed(): void {
		$this->assertSame( Decision::STALE, $this->decide( new Rule( [ 'vip' ] ), $this->member( synced: self::NOW - 8 * 86400 ) )->reason );
	}

	public function test_failed_sync_is_honored_or_locked_per_setting(): void {
		$member = $this->member( failed: true );

		$this->assertTrue( $this->decide( new Rule( [ 'vip' ] ), $member )->granted );
		$this->assertSame( Decision::STALE, $this->decide( new Rule( [ 'vip' ] ), $member, Evaluator::STALE_FAIL_CLOSED )->reason );
	}

	public function test_window_zero_disables_age_limit(): void {
		$decision = ( new Evaluator( 0 ) )->decide( new Rule( [ 'vip' ] ), $this->member( synced: 1 ), self::NOW );

		$this->assertTrue( $decision->granted );
	}

	public function test_unknown_rule_source_fails_closed(): void {
		$this->assertSame( Decision::BAD_RULE, $this->decide( new Rule( [ 'vip' ], source: 'orders' ), $this->member() )->reason );
	}

	public function test_rule_reads_blockstudio_repeater_rows_and_select_objects(): void {
		$rule = Rule::from_attributes(
			[
				'requiredTags' => [ [ 'tag' => 'vip' ], [ 'tag' => ' ' ], [ 'tag' => 'gold' ] ],
				'matchMode'    => [ 'value' => 'all', 'label' => 'All' ],
				'requireLogin' => false,
			]
		);

		$this->assertSame( [ 'vip', 'gold' ], $rule->required_tags );
		$this->assertSame( Rule::MATCH_ALL, $rule->match_mode );
		$this->assertFalse( $rule->require_login );
	}

	public function test_rule_defaults_require_login(): void {
		$this->assertTrue( Rule::from_attributes( [] )->require_login );
		$this->assertTrue( Rule::from_attributes( [ 'requireLogin' => null ] )->require_login );
	}
}
