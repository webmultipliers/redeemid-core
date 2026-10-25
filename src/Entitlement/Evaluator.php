<?php

namespace RedeemID\Entitlement;

/**
 * Pure entitlement decision. No WordPress calls, so the whole
 * truth table is unit tested. Every unknown fails closed.
 */
final class Evaluator {
	public function decide( Rule $rule, Member $member, int $now ): Decision {
		if ( 'tags' !== $rule->source ) {
			// Order / subscription sources don't exist yet, so they fail closed.
			return Decision::deny( Decision::BAD_RULE );
		}

		if ( ! $member->signed_in ) {
			return ( $rule->require_login || $rule->required_tags )
				? Decision::deny( Decision::GUEST )
				: Decision::grant();
		}

		// A customer Shopify disabled is locked out of signed-in content too. An
		// account that was never linked has no Shopify status and still passes.
		if ( $member->linked && 'disabled' === $member->status ) {
			return Decision::deny( Decision::DISABLED );
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
	 * Why a signed-in visitor's synced record can't be used for a tag check:
	 * not linked, disabled, or never synced. Null when it can. The mirror's age
	 * doesn't matter: the last tags Shopify sent stay in force until it sends others.
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

		return null;
	}

	/**
	 * Shopify treats tags case-insensitively; compare them the same way.
	 */
	public static function normalize_tag( string $tag ): string {
		return mb_strtolower( trim( $tag ) );
	}
}
