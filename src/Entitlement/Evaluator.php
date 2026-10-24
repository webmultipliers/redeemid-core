<?php

namespace WP2_Shopify\Entitlement;

/**
 * Pure entitlement decision (spec 7.1, 7.3). No WordPress calls, so the whole
 * truth table is unit tested. Every unknown fails closed.
 */
final class Evaluator {
	public const STALE_HONOR       = 'honor';
	public const STALE_FAIL_CLOSED = 'fail_closed';

	/**
	 * @param int    $freshness_window Seconds a synced mirror stays trustworthy; 0 disables the limit.
	 * @param string $stale_behavior   What to do when the last sync attempt failed (Shopify unreachable).
	 */
	public function __construct(
		private readonly int $freshness_window = 7 * 86400,
		private readonly string $stale_behavior = self::STALE_HONOR,
	) {}

	public function decide( Rule $rule, Member $member, int $now ): Decision {
		if ( 'tags' !== $rule->source ) {
			// Order / subscription sources are Phase 3; until they exist they fail closed.
			return Decision::deny( Decision::BAD_RULE );
		}

		if ( ! $member->signed_in ) {
			return ( $rule->require_login || $rule->required_tags )
				? Decision::deny( Decision::GUEST )
				: Decision::grant();
		}

		if ( ! $rule->required_tags ) {
			return Decision::grant();
		}

		$problem = $this->account_problem( $member, $now );

		if ( $problem ) {
			return $problem;
		}

		$have = array_map( [ self::class, 'normalize_tag' ], $member->tags );
		$need = array_map( [ self::class, 'normalize_tag' ], $rule->required_tags );
		$hits = array_intersect( $need, $have );

		$ok = Rule::MATCH_ALL === $rule->match_mode
			? count( array_unique( $hits ) ) === count( array_unique( $need ) )
			: count( $hits ) > 0;

		return $ok ? Decision::grant() : Decision::deny( Decision::MISSING_TAGS );
	}

	/**
	 * Why a signed-in visitor's synced record can't be trusted for a tag
	 * check: not linked, disabled, never synced, or stale. Null when it can.
	 */
	public function account_problem( Member $member, int $now ): ?Decision {
		if ( ! $member->linked ) {
			return Decision::deny( Decision::NOT_LINKED );
		}

		if ( 'disabled' === $member->status ) {
			return Decision::deny( Decision::DISABLED );
		}

		if ( null === $member->last_sync ) {
			return Decision::deny( Decision::NO_MIRROR );
		}

		if ( $this->freshness_window > 0 && $now - $member->last_sync > $this->freshness_window ) {
			return Decision::deny( Decision::STALE );
		}

		if ( $member->last_sync_failed && self::STALE_FAIL_CLOSED === $this->stale_behavior ) {
			return Decision::deny( Decision::STALE );
		}

		return null;
	}

	/**
	 * Shopify treats tags case-insensitively; compare them the same way.
	 */
	public static function normalize_tag( string $tag ): string {
		return mb_strtolower( trim( $tag ) );
	}
}
