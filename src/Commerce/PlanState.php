<?php

namespace RedeemID\Commerce;

use RedeemID\Access\LevelStore;
use RedeemID\Access\Levels;
use RedeemID\Access\Membership;
use RedeemID\Access\Viewer;

/**
 * What a plan card should say to a signed-in member, from which level its
 * product leads to (the level's Shopify products) and the levels they hold.
 * It changes the card's wording only; Shopify still decides who holds what.
 *
 * - current:  they hold that level directly. "Your plan".
 * - included: a level they hold already includes it (a Silver card for a
 *   Gold member). "Included in Gold".
 * - upgrade:  the card's level includes one they hold (a Gold card for a
 *   Silver member). The button says "Upgrade".
 * - add:      they hold levels, none related to this one (an add-on).
 * - '':       a guest, someone with no level, or a product no level names.
 *
 * level_for() and state() are pure; viewer() reads the current visitor.
 */
final class PlanState {
	public const CURRENT  = 'current';
	public const INCLUDED = 'included';
	public const UPGRADE  = 'upgrade';
	public const ADD      = 'add';

	/**
	 * The level whose products list $handle, or null.
	 */
	public static function level_for( Levels $levels, string $handle ): ?string {
		foreach ( $levels->all() as $slug => $level ) {
			if ( '' !== $handle && in_array( $handle, $level->products, true ) ) {
				return (string) $slug;
			}
		}

		return null;
	}

	/**
	 * @param string[] $held     Levels held, directly or through inclusion (Membership::held()).
	 * @param string[] $direct   Of those, the ones no other held level includes (Membership::top()).
	 * @return array{state: string, via: string} via: the held level that includes it, for "included".
	 */
	public static function state( Levels $levels, ?string $level, array $held, array $direct ): array {
		if ( null === $level || ! $held ) {
			return [ 'state' => '', 'via' => '' ];
		}

		if ( in_array( $level, $direct, true ) ) {
			return [ 'state' => self::CURRENT, 'via' => '' ];
		}

		if ( in_array( $level, $held, true ) ) {
			foreach ( $direct as $top ) {
				if ( $levels->covers( $top, $level ) ) {
					return [ 'state' => self::INCLUDED, 'via' => $top ];
				}
			}

			return [ 'state' => self::CURRENT, 'via' => '' ];
		}

		foreach ( $held as $slug ) {
			if ( $levels->covers( $level, $slug ) ) {
				return [ 'state' => self::UPGRADE, 'via' => $slug ];
			}
		}

		return [ 'state' => self::ADD, 'via' => '' ];
	}

	/**
	 * The card state for the current visitor. A card that says something
	 * personal keeps the page out of shared page caches; for guests the card
	 * is the same for everyone and the page stays cacheable.
	 *
	 * @return array{state: string, via: string}
	 */
	public static function viewer( string $handle ): array {
		$levels = LevelStore::levels();
		$level  = self::level_for( $levels, $handle );

		if ( null === $level || ( ! is_user_logged_in() && null === Viewer::preview_as() ) ) {
			return [ 'state' => '', 'via' => '' ];
		}

		$held  = Membership::held( $levels, Viewer::member(), time() );
		$state = self::state( $levels, $level, $held, Membership::top( $levels, $held ) );

		if ( '' !== $state['state'] && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}

		return $state;
	}
}
