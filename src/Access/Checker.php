<?php

namespace RedeemID\Access;

use RedeemID\Entitlement\Decision;
use RedeemID\Entitlement\Evaluator;
use RedeemID\Entitlement\Member;

/**
 * Does this visitor pass an effective rule? Each level's groups go through
 * the existing Evaluator, so unsynced or disabled accounts and guests fail
 * closed exactly as they do for a Gate block today. Pure.
 */
final class Checker {
	public function __construct(
		private readonly Levels $levels,
		private readonly Evaluator $evaluator,
	) {}

	public function check( EffectiveRule $rule, Member $member, int $now ): Verdict {
		if ( $rule->open() ) {
			return new Verdict( Decision::grant(), null );
		}

		foreach ( $rule->clauses as $clause ) {
			$best = null;

			foreach ( $clause as $slug ) {
				$decision = $this->holds( $slug, $member, $now );

				if ( $decision->granted ) {
					continue 2;
				}

				// Keep the most useful reason: a guest, unsynced or disabled reason explains more than missing tags.
				$best ??= $decision;

				if ( Decision::MISSING_TAGS === $best->reason && Decision::MISSING_TAGS !== $decision->reason ) {
					$best = $decision;
				}
			}

			return new Verdict( $best ?? Decision::deny( Decision::BAD_RULE ), $this->next_level( $clause ) );
		}

		return new Verdict( Decision::grant(), null );
	}

	/**
	 * Does the visitor hold $slug, directly or through a level that includes it?
	 * An unknown slug fails closed.
	 */
	public function holds( string $slug, Member $member, int $now ): Decision {
		if ( ! $this->levels->has( $slug ) ) {
			return Decision::deny( Decision::BAD_RULE );
		}

		$first = null;

		foreach ( $this->levels->grantors( $slug ) as $grantor ) {
			foreach ( $this->levels->get( $grantor )->groups as $group ) {
				$decision = $this->evaluator->decide( $group, $member, $now );

				if ( $decision->granted ) {
					return $decision;
				}

				$first ??= $decision;
			}
		}

		return $first ?? Decision::deny( Decision::BAD_RULE );
	}

	/**
	 * The level a wall should offer for a failing clause: the narrowest one,
	 * so the CTA asks for what this item needs and nothing more.
	 */
	private function next_level( array $clause ): ?string {
		foreach ( $clause as $slug ) {
			if ( $this->levels->has( $slug ) ) {
				return $slug;
			}
		}

		return null;
	}
}
