<?php

namespace RedeemID\Access;

use RedeemID\Entitlement\Decision;
use RedeemID\Sync\LevelHistory;
use RedeemID\Sync\Mirror;

/**
 * Drip: an item a member may read only once they have been a member for N
 * days (the Access box on the item, "Drip").
 *
 * It narrows a granted verdict, never widens one, and applies to the real
 * signed-in visitor only: previews and bypass are unaffected. A member's start
 * is the membership.member_since metafield when Shopify keeps one, else the
 * earliest start of a level they hold now in level history, else when they
 * linked. Search listings don't apply drip; the item's text is still cut on
 * every render.
 *
 * joined() and unlocks_at() are pure.
 */
final class Drip {
	public static function days( \WP_Post $post ): int {
		/**
		 * Days of membership an item needs before members can read it.
		 */
		return max( 0, (int) apply_filters( 'redeemid_drip_days', ItemRule::get( $post )['drip_days'], $post ) );
	}

	/**
	 * When the member's membership started.
	 *
	 * @param string                                   $meta_since Y-m-d from the member_since metafield, or ''.
	 * @param array<string, array{since: int, until: int}> $history Level history.
	 * @param string[]                                 $held       Levels held now.
	 */
	public static function joined( string $meta_since, array $history, array $held, int $linked_at, int $now ): int {
		$from_meta = '' !== $meta_since ? strtotime( $meta_since . ' 00:00:00 UTC' ) : false;

		if ( false !== $from_meta && $from_meta > 0 ) {
			return $from_meta;
		}

		$starts = [];

		foreach ( $held as $slug ) {
			$entry = $history[ $slug ] ?? null;

			if ( is_array( $entry ) && empty( $entry['until'] ) && (int) ( $entry['since'] ?? 0 ) > 0 ) {
				$starts[] = (int) $entry['since'];
			}
		}

		if ( $starts ) {
			return min( $starts );
		}

		return $linked_at > 0 ? $linked_at : $now;
	}

	public static function unlocks_at( int $joined, int $days ): int {
		return $joined + $days * 86400;
	}

	/**
	 * When $post opens for $user_id, or 0 when it has no drip.
	 */
	public static function opens_for( \WP_Post $post, int $user_id ): int {
		$days = self::days( $post );

		if ( ! $days || ! $user_id ) {
			return 0;
		}

		$now    = time();
		$joined = self::joined(
			(string) get_user_meta( $user_id, Mirror::SINCE, true ),
			LevelHistory::get( $user_id ),
			Membership::held( LevelStore::levels(), Mirror::member( $user_id ), $now ),
			(int) get_user_meta( $user_id, Mirror::LINKED_AT, true ),
			$now
		);

		return self::unlocks_at( $joined, $days );
	}

	/**
	 * Narrow a granted verdict for the signed-in visitor if the item hasn't dripped to them yet.
	 */
	public static function apply( Verdict $verdict, \WP_Post $post, EffectiveRule $rule ): Verdict {
		if ( ! $verdict->granted() || $rule->open() || null !== Viewer::preview_as() ) {
			return $verdict;
		}

		$opens = self::opens_for( $post, get_current_user_id() );

		return $opens > time() ? new Verdict( Decision::deny( Decision::DRIP ), $verdict->next_level ) : $verdict;
	}
}
