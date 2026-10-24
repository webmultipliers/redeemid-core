<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP2_Shopify\Access\BlockAccess;
use WP2_Shopify\Access\Levels;
use WP2_Shopify\Entitlement\Evaluator;
use WP2_Shopify\Entitlement\Member;
use WP2_Shopify\Features\Cache;
use WP2_Shopify\Features\Feature;
use WP2_Shopify\Features\Grant;
use WP2_Shopify\Features\Resolver;
use WP2_Shopify\Features\Route;
use WP2_Shopify\Features\Surfaces;

/**
 * The feature registry's pure core: the acceptance matrix and named tests
 * from the spec (section 12), on the same Levels, Checker and Evaluator that
 * gate content.
 */
final class FeaturesTest extends TestCase {
	private const NOW = 1800000000;

	private static function levels(): Levels {
		return Levels::from_config(
			[
				'members' => [ 'label' => 'Members', 'groups' => [ [ 'tags' => [ 'member' ] ] ] ],
				'silver'  => [ 'label' => 'Silver', 'groups' => [ [ 'tags' => [ 'silver' ] ] ], 'includes' => [ 'members' ] ],
				'gold'    => [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold' ] ] ], 'includes' => [ 'silver' ] ],
			]
		);
	}

	private static function resolver( array $tests = [] ): Resolver {
		return new Resolver( self::levels(), new Evaluator( 7 * 86400 ), $tests );
	}

	/**
	 * @return array<string, Feature>
	 */
	private static function features(): array {
		return [
			'gold-only'   => Feature::from_array( 'gold-only', [ 'label' => 'Gold only', 'cache' => 'private', 'condition' => 'gold' ] ),
			'gold-or-tag' => Feature::from_array( 'gold-or-tag', [ 'label' => 'Downloads', 'cache' => 'private', 'condition' => "gold\nAdd-on purchase = tag:download-addon" ] ),
			'signed-in'   => Feature::from_array( 'signed-in', [ 'label' => 'Reading list', 'cache' => 'fragment', 'condition' => 'signed-in' ] ),
		];
	}

	private static function member( array $tags = [], string $status = 'enabled', ?int $last_sync = self::NOW, bool $linked = true ): Member {
		return new Member( signed_in: true, linked: $linked, tags: $tags, status: $status, last_sync: $last_sync );
	}

	/**
	 * Spec section 12, visitor-state matrix: [ member, gold only, gold or add-on, signed in ].
	 *
	 * @return array<string, array{0: Member, 1: bool, 2: bool, 3: bool}>
	 */
	public static function matrix(): array {
		return [
			'guest'                       => [ Member::guest(), false, false, false ],
			'signed in, unlinked'         => [ self::member( [], 'enabled', null, false ), false, false, true ],
			'members level'               => [ self::member( [ 'member' ] ), false, false, true ],
			'silver'                      => [ self::member( [ 'silver' ] ), false, false, true ],
			'silver + add-on'             => [ self::member( [ 'silver', 'download-addon' ] ), false, true, true ],
			'gold'                        => [ self::member( [ 'gold' ] ), true, true, true ],
			'gold + add-on'               => [ self::member( [ 'gold', 'download-addon' ] ), true, true, true ],
			'disabled gold'               => [ self::member( [ 'gold', 'download-addon' ], 'disabled' ), false, false, false ],
			'stale gold'                  => [ self::member( [ 'gold', 'download-addon' ], 'enabled', self::NOW - 8 * 86400 ), false, false, false ],
			'linked but never synced'     => [ self::member( [ 'gold' ], 'enabled', null ), false, false, false ],
		];
	}

	#[DataProvider( 'matrix' )]
	public function test_visitor_state_matrix( Member $member, bool $gold_only, bool $gold_or_tag, bool $signed_in ): void {
		$grants = self::resolver()->resolve( array_values( self::features() ), $member, self::NOW );

		$this->assertSame( $gold_only, $grants['gold-only']->granted, 'Gold only' );
		$this->assertSame( $gold_or_tag, $grants['gold-or-tag']->granted, 'Gold or add-on' );
		$this->assertSame( $signed_in, $grants['signed-in']->granted, 'Signed in' );
	}

	public function test_two_routes_are_one_grant_with_both_routes_shown(): void {
		$grant = self::resolver()->decide( self::features()['gold-or-tag'], self::member( [ 'gold', 'download-addon' ] ), self::NOW );

		$this->assertTrue( $grant->granted );
		$this->assertSame( [ 'Gold', 'Add-on purchase' ], $grant->routes );
		$this->assertSame( 'Gold', $grant->provenance() );
	}

	public function test_stacking_add_on_grants_without_gold(): void {
		$resolver = self::resolver();
		$silver   = self::member( [ 'silver', 'download-addon' ] );
		$grant    = $resolver->decide( self::features()['gold-or-tag'], $silver, self::NOW );

		$this->assertTrue( $grant->granted );
		$this->assertSame( 'Add-on purchase', $grant->provenance() );
		$this->assertFalse( $resolver->decide( self::features()['gold-only'], $silver, self::NOW )->granted, 'The add-on does not open the tier' );
	}

	public function test_revoke_drops_only_when_every_route_is_lost(): void {
		$resolver = self::resolver();
		$feature  = self::features()['gold-or-tag'];

		// The add-on is refunded: the tag leaves the mirror.
		$this->assertFalse( $resolver->decide( $feature, self::member( [ 'silver' ] ), self::NOW )->granted, 'Silver had no other route' );

		$gold = $resolver->decide( $feature, self::member( [ 'gold' ] ), self::NOW );
		$this->assertTrue( $gold->granted, 'Gold keeps it' );
		$this->assertSame( [ 'Gold' ], $gold->routes, 'Provenance switches to the remaining route' );

		// A Gold + add-on member downgrades to Silver and keeps the add-on.
		$this->assertSame( [ 'Add-on purchase' ], $resolver->decide( $feature, self::member( [ 'silver', 'download-addon' ] ), self::NOW )->routes );
	}

	public function test_stale_mirror_withholds_and_a_resync_restores(): void {
		$resolver = self::resolver();
		$feature  = self::features()['gold-only'];
		$stale    = $resolver->decide( $feature, self::member( [ 'gold' ], 'enabled', self::NOW - 8 * 86400 ), self::NOW );

		$this->assertFalse( $stale->granted );
		$this->assertSame( 'stale', $stale->reason );
		$this->assertTrue( $resolver->decide( $feature, self::member( [ 'gold' ], 'enabled', self::NOW ), self::NOW )->granted );
	}

	public function test_inclusion_is_expanded_before_the_feature_condition(): void {
		$feature = Feature::from_array( 'silver-perk', [ 'cache' => 'private', 'condition' => 'silver' ] );

		$this->assertTrue( self::resolver()->decide( $feature, self::member( [ 'gold' ] ), self::NOW )->granted, 'Gold includes Silver' );
		$this->assertFalse( self::resolver()->decide( $feature, self::member( [ 'member' ] ), self::NOW )->granted );
	}

	public function test_a_route_needs_every_test(): void {
		$feature = Feature::from_array( 'vip', [ 'cache' => 'private', 'condition' => 'silver, tag:vip' ] );

		$this->assertFalse( self::resolver()->decide( $feature, self::member( [ 'silver' ] ), self::NOW )->granted );
		$this->assertFalse( self::resolver()->decide( $feature, self::member( [ 'vip' ] ), self::NOW )->granted );
		$this->assertTrue( self::resolver()->decide( $feature, self::member( [ 'silver', 'VIP' ] ), self::NOW )->granted, 'Tags compare case-insensitively' );
	}

	public function test_empty_condition_grants_nobody_and_public_grants_guests(): void {
		$none   = Feature::from_array( 'none', [ 'cache' => 'private' ] );
		$public = Feature::from_array( 'public', [ 'cache' => 'private', 'condition' => 'public' ] );

		$this->assertSame( Grant::NO_CONDITION, self::resolver()->decide( $none, self::member( [ 'gold' ] ), self::NOW )->reason );
		$this->assertTrue( self::resolver()->decide( $public, Member::guest(), self::NOW )->granted );
		$this->assertTrue( self::resolver()->decide( $public, self::member( [], 'disabled' ), self::NOW )->granted, 'Public is for everyone' );
	}

	public function test_unknown_level_and_broken_custom_tests_fail_closed_and_others_still_resolve(): void {
		$tests    = [
			'boom'   => [ 'label' => 'Boom', 'callback' => static function () { throw new \RuntimeException( 'x' ); } ],
			'truthy' => [ 'label' => 'Truthy', 'callback' => static fn() => 1 ],
		];
		$features = [
			Feature::from_array( 'a', [ 'cache' => 'private', 'condition' => 'platinum' ] ),
			Feature::from_array( 'b', [ 'cache' => 'private', 'condition' => 'test:boom' ] ),
			Feature::from_array( 'c', [ 'cache' => 'private', 'condition' => 'test:missing' ] ),
			Feature::from_array( 'd', [ 'cache' => 'private', 'condition' => 'test:truthy' ] ),
			Feature::from_array( 'e', [ 'cache' => 'private', 'condition' => 'gold' ] ),
		];
		$grants   = self::resolver( $tests )->resolve( $features, self::member( [ 'gold' ] ), self::NOW );

		$this->assertFalse( $grants['a']->granted );
		$this->assertSame( 'test_error', $grants['b']->trace[0]['tests'][0]['reason'] );
		$this->assertSame( 'unknown_test', $grants['c']->reason );
		$this->assertFalse( $grants['d']->granted, 'Only true grants, not a truthy value' );
		$this->assertTrue( $grants['e']->granted, 'Other features still resolve' );
	}

	public function test_custom_test_grants_an_unlinked_staff_member_but_never_a_guest_or_disabled_customer(): void {
		$tests   = [ 'staff' => [ 'label' => 'Staff', 'callback' => static fn( Member $m, string $arg, int $user_id ) => 7 === $user_id ] ];
		$feature = Feature::from_array( 'staff-tools', [ 'cache' => 'private', 'condition' => 'test:staff' ] );

		$grant = self::resolver( $tests )->decide( $feature, self::member( [], 'enabled', null, false ), self::NOW, 7 );
		$this->assertTrue( $grant->granted );
		$this->assertSame( 'Staff', $grant->provenance() );
		$this->assertFalse( self::resolver( $tests )->decide( $feature, Member::guest(), self::NOW, 7 )->granted );
		$this->assertFalse( self::resolver( $tests )->decide( $feature, self::member( [], 'disabled' ), self::NOW, 7 )->granted );
	}

	public function test_custom_test_receives_its_argument(): void {
		$seen    = null;
		$tests   = [ 'role' => [ 'callback' => static function ( Member $m, string $arg ) use ( &$seen ) { $seen = $arg; return true; } ] ];
		$feature = Feature::from_array( 'r', [ 'cache' => 'private', 'condition' => 'test:role=editor' ] );

		self::resolver( $tests )->decide( $feature, self::member(), self::NOW );
		$this->assertSame( 'editor', $seen );
	}

	public function test_route_text_round_trips(): void {
		$text   = "gold\nAdd-on purchase = tag:Download-Addon\nsilver, test:role=editor";
		$routes = Route::parse( $text );

		$this->assertCount( 3, $routes );
		$this->assertSame( 'Add-on purchase', $routes[1]->label );
		$this->assertSame( [ 'type' => 'tag', 'value' => 'Download-Addon', 'arg' => '' ], $routes[1]->tests[0], 'Tags keep their spelling' );
		$this->assertSame( [ 'type' => 'test', 'value' => 'role', 'arg' => 'editor' ], $routes[2]->tests[1] );
		$this->assertSame( $text, Route::format( $routes ) );
		$this->assertTrue( $routes[0]->is_level_only( 'gold' ) );
		$this->assertFalse( $routes[2]->is_level_only( 'silver' ) );
	}

	public function test_labels_never_name_a_tag(): void {
		$resolver = self::resolver();
		$route    = Route::parse( 'tag:secret-tag-name' )[0];

		$this->assertSame( 'Add-on purchase', $resolver->label( $route ) );
		$this->assertStringContainsString( 'secret-tag-name', $resolver->describe( $route ), 'Admin wording may' );
	}

	public function test_registration_defaults_fail_safe(): void {
		$f = Feature::from_array( 'x', [ 'enforcement' => [ 'telepathy' ] ] );

		$this->assertSame( Feature::CACHE_PRIVATE, $f->cache );
		$this->assertSame( [ 'bad_enforcement', 'no_cache' ], $f->problems );
		$this->assertSame( [ Feature::PHP ], $f->enforcement );
		$this->assertFalse( $f->managed );
		$this->assertSame( [], $f->routes, 'Default state: off' );

		$mapped = Feature::from_array( 'x', [ 'cache' => 'vary' ], [ [ 'tests' => [ [ 'type' => 'level', 'value' => 'gold' ] ] ] ] );
		$this->assertFalse( $mapped->managed );
		$this->assertCount( 1, $mapped->routes );

		$code = Feature::from_array( 'x', [ 'cache' => 'vary', 'condition' => 'silver' ], [ [ 'tests' => [ [ 'type' => 'level', 'value' => 'gold' ] ] ] ] );
		$this->assertTrue( $code->managed, 'A condition in code wins and is read-only' );
		$this->assertSame( [ 'silver' ], $code->routes[0]->levels() );
	}

	public function test_slugs_and_classes(): void {
		$this->assertSame( 'acme/early-access', Feature::slug( ' Acme/Early Access ' ) );
		$this->assertSame( 'acme--early-access', Feature::css( 'acme/early-access' ) );
	}

	public function test_block_feature_visibility_including_the_inverse(): void {
		$has    = static fn( string $slug ) => 'ad-free' === $slug;
		$yes    = static fn() => true;
		$prompt = [ 'show' => BlockAccess::EVERYONE, 'levels' => [], 'feature' => 'ad-free', 'when' => BlockAccess::LACKS ];
		$perk   = [ 'show' => BlockAccess::EVERYONE, 'levels' => [], 'feature' => 'ad-free', 'when' => BlockAccess::HAS ];

		$this->assertTrue( BlockAccess::visible( $perk, true, $yes, $has ) );
		$this->assertFalse( BlockAccess::visible( $prompt, true, $yes, $has ) );
		$this->assertTrue( BlockAccess::visible( $prompt, true, $yes, static fn() => false ), 'Upgrade prompt for non-holders' );
		$this->assertFalse( BlockAccess::visible( $perk, true, $yes ), 'No feature callback: nobody holds it' );
		$this->assertFalse( BlockAccess::visible( [ 'show' => BlockAccess::READERS ] + $perk, false, $yes, $has ), 'Both conditions must pass' );

		$block = [ 'blockName' => 'core/group', 'attrs' => [ BlockAccess::ATTR => [ 'feature' => 'ad-free' ] ] ];
		$this->assertTrue( BlockAccess::members_only( $block ), 'Holder-only text is not public text' );
		$this->assertSame( BlockAccess::HAS, BlockAccess::setting( $block )['when'] );
		$this->assertTrue( BlockAccess::limited( BlockAccess::setting( $block ) ) );
	}

	public function test_body_classes_name_the_feature_only_when_declared_and_held(): void {
		$features = [
			'ad-free' => Feature::from_array( 'ad-free', [ 'cache' => 'private', 'enforcement' => [ 'body' ] ] ),
			'quiet'   => Feature::from_array( 'quiet', [ 'cache' => 'private', 'enforcement' => [ 'php' ] ] ),
		];

		$this->assertSame( [ 'wp2-feature-ad-free' ], Surfaces::classes( $features, [ 'ad-free', 'quiet' ] ) );
		$this->assertSame( [], Surfaces::classes( $features, [] ) );
	}

	public function test_js_signal_is_opt_in_booleans_only(): void {
		$features = [
			'ad-free' => Feature::from_array( 'ad-free', [ 'cache' => 'private', 'js' => true ] ),
			'secret'  => Feature::from_array( 'secret', [ 'cache' => 'private' ] ),
		];

		$this->assertSame( [ 'ad-free' => false ], Surfaces::js_values( $features, static fn() => false ) );
	}

	public function test_vary_key_is_stable_and_opaque(): void {
		$this->assertSame( Cache::key( [ 'b', 'a' ], 's' ), Cache::key( [ 'a', 'b' ], 's' ) );
		$this->assertStringNotContainsString( 'ad-free', Cache::key( [ 'ad-free' ], 's' ), 'The cookie names no feature' );
		$this->assertSame( '', Cache::key( [], 's' ) );
		$this->assertSame( 16, strlen( Cache::key( [ 'x' ], 's' ) ) );
	}
}
