<?php

namespace WP2_Shopify\Access;

use WP2_Shopify\Entitlement\Evaluator;
use WP2_Shopify\Entitlement\Member;

/**
 * Which paid levels a member holds, for the account card, the locked panel
 * and level history. Pure: built-in Public and Signed in are never "held".
 */
final class Membership {
	/**
	 * @return string[] Slugs of the non-built-in levels $member holds, directly or through an included level, in the order levels are defined.
	 */
	public static function held( Levels $levels, Member $member, int $now ): array {
		$checker = new Checker( $levels, new Evaluator() );
		$held    = [];

		foreach ( $levels->all() as $slug => $level ) {
			if ( ! $level->builtin() && $checker->holds( (string) $slug, $member, $now )->granted ) {
				$held[] = (string) $slug;
			}
		}

		return $held;
	}

	/**
	 * The levels $member holds that no other held level already includes:
	 * "Gold", not "Gold, Silver", when Gold includes Silver.
	 *
	 * @param string[] $held From held().
	 * @return string[]
	 */
	public static function top( Levels $levels, array $held ): array {
		return array_values(
			array_filter(
				$held,
				static function ( string $slug ) use ( $levels, $held ): bool {
					foreach ( $held as $other ) {
						if ( $other !== $slug && $levels->covers( $other, $slug ) && ! $levels->covers( $slug, $other ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * The landing page for a member: the first held top level, in level order,
	 * that has one. '' when none does.
	 *
	 * @param string[] $top From top().
	 */
	public static function home( Levels $levels, array $top ): string {
		foreach ( $top as $slug ) {
			$url = $levels->get( $slug )?->home_url ?? '';

			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}
}
