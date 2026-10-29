<?php

namespace RedeemID\Access;

use RedeemID\Entitlement\Decision;
use RedeemID\Sync\Mirror;

/**
 * The meter: a signed-in reader without access may open a few locked items
 * each month (or week), set on Access → Content rules. It is a site policy
 * like the archive window, not a membership: it holds no level, grants no
 * feature, and Shopify never hears of it.
 *
 * Only the signed-in version exists. A meter for anonymous visitors would
 * have to live in a cookie anyone can clear, and would split the page cache
 * per visitor, so guests get the wall and a nudge to sign in.
 *
 * An item counts once per period, when the reader opens its own page: never
 * from a loop, feed, REST, search or embed, and never in a staff preview. The
 * count lives in the reader's user meta. Items marked "Never count toward a
 * meter" and open items are never metered. Drip is for members, so the meter
 * never opens an item a member is still waiting for.
 *
 * period_key() and consume() are pure.
 */
final class Meter {
	public const META = '_redeemid_meter';

	/**
	 * @return array{limit: int, period: string}
	 */
	public static function settings(): array {
		return RuleStore::rules()->meter;
	}

	public static function on(): bool {
		return self::settings()['limit'] > 0;
	}

	/**
	 * The period $now falls in, in the site's timezone: "m:2026-10" or "w:2026-W44".
	 */
	public static function period_key( string $period, int $now, \DateTimeZone $tz ): string {
		$date = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );

		return Rules::METER_WEEK === $period ? 'w:' . $date->format( 'o-\WW' ) : 'm:' . $date->format( 'Y-m' );
	}

	/**
	 * Count $post_id for this period if there's room. An item already counted
	 * this period reads again for free.
	 *
	 * @param array{period?: string, items?: int[]} $state
	 * @return array{state: array{period: string, items: int[]}, allowed: bool, counted: bool}
	 */
	public static function consume( array $state, string $period, int $post_id, int $limit ): array {
		$items = ( $state['period'] ?? '' ) === $period ? array_values( array_map( 'intval', (array) ( $state['items'] ?? [] ) ) ) : [];

		if ( in_array( $post_id, $items, true ) ) {
			return [ 'state' => [ 'period' => $period, 'items' => $items ], 'allowed' => true, 'counted' => false ];
		}

		if ( count( $items ) >= $limit ) {
			return [ 'state' => [ 'period' => $period, 'items' => $items ], 'allowed' => false, 'counted' => false ];
		}

		$items[] = $post_id;

		return [ 'state' => [ 'period' => $period, 'items' => $items ], 'allowed' => true, 'counted' => true ];
	}

	/**
	 * How much of this period's meter the user has used.
	 *
	 * @return array{used: int, limit: int}
	 */
	public static function usage( int $user_id ): array {
		$settings = self::settings();
		$state    = $user_id > 0 ? get_user_meta( $user_id, self::META, true ) : [];
		$state    = is_array( $state ) ? $state : [];
		$period   = self::period_key( $settings['period'], time(), wp_timezone() );

		return [
			'used'  => ( $state['period'] ?? '' ) === $period ? count( (array) ( $state['items'] ?? [] ) ) : 0,
			'limit' => $settings['limit'],
		];
	}

	/**
	 * Open a denied item as one of the reader's metered items, when the meter
	 * applies here and they have room.
	 */
	public static function apply( Verdict $verdict, \WP_Post $post, EffectiveRule $rule ): Verdict {
		$user_id  = get_current_user_id();
		$settings = self::settings();

		if (
			$verdict->granted()
			|| $settings['limit'] < 1
			|| ! $rule->meter_eligible
			|| $user_id < 1
			|| null !== Viewer::preview_as()
			|| in_array( $verdict->decision->reason, [ Decision::DRIP, Decision::BAD_RULE, Decision::DISABLED ], true )
			|| 'disabled' === get_user_meta( $user_id, Mirror::STATUS, true )
			|| ! self::reading( $post )
		) {
			return $verdict;
		}

		$stored = get_user_meta( $user_id, self::META, true );
		$result = self::consume( is_array( $stored ) ? $stored : [], self::period_key( $settings['period'], time(), wp_timezone() ), $post->ID, $settings['limit'] );

		if ( $result['counted'] ) {
			update_user_meta( $user_id, self::META, $result['state'] );
		}

		return $result['allowed'] ? new Verdict( Decision::metered(), $verdict->next_level ) : $verdict;
	}

	/**
	 * Is this request the reader opening $post's own page?
	 */
	private static function reading( \WP_Post $post ): bool {
		if ( wp_is_serving_rest_request() || is_feed() || is_embed() || is_search() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'GRAPHQL_REQUEST' ) && GRAPHQL_REQUEST ) ) {
			return false;
		}

		return is_singular() && (int) get_queried_object_id() === (int) $post->ID;
	}

	/**
	 * The line shown with a metered item or at the wall, or ''.
	 */
	public static function note( Verdict $verdict, EffectiveRule $rule ): string {
		$settings = self::settings();

		if ( $settings['limit'] < 1 || ! $rule->meter_eligible ) {
			return '';
		}

		$week = Rules::METER_WEEK === $settings['period'];

		if ( ! is_user_logged_in() ) {
			return $week
				/* translators: %d: number of free items */
				? sprintf( _n( 'Sign in to read %d members-only story free each week.', 'Sign in to read %d members-only stories free each week.', $settings['limit'], 'redeemid' ), $settings['limit'] )
				/* translators: %d: number of free items */
				: sprintf( _n( 'Sign in to read %d members-only story free each month.', 'Sign in to read %d members-only stories free each month.', $settings['limit'], 'redeemid' ), $settings['limit'] );
		}

		$usage = self::usage( get_current_user_id() );

		if ( Decision::METERED === $verdict->decision->reason ) {
			return sprintf(
				/* translators: 1: free items used, 2: free items allowed */
				$week ? __( 'Free story %1$d of %2$d this week.', 'redeemid' ) : __( 'Free story %1$d of %2$d this month.', 'redeemid' ),
				$usage['used'],
				$usage['limit']
			);
		}

		if ( $verdict->granted() || $usage['used'] < $usage['limit'] ) {
			return '';
		}

		return sprintf(
			/* translators: %d: free items allowed */
			$week ? __( 'You\'ve read your %d free stories this week.', 'redeemid' ) : __( 'You\'ve read your %d free stories this month.', 'redeemid' ),
			$usage['limit']
		);
	}
}
