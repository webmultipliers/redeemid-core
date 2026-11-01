<?php

namespace RedeemID\Blocks;

use RedeemID\Access\LevelStore;
use RedeemID\Access\Membership;
use RedeemID\Auth\ShadowUsers;
use RedeemID\Config;
use RedeemID\Features\Registry;
use RedeemID\Settings;
use RedeemID\Sync\LevelHistory;
use RedeemID\Sync\Mirror;
use RedeemID\Sync\PostCheckout;

/**
 * What the Member Account block (blocks/member-account) shows a signed-in
 * customer: their levels by label (never raw tags), when they joined and
 * renew, levels that ended, the features they hold, when Shopify last synced,
 * and where to manage their account and subscription.
 *
 * Dates come from the membership.member_since, membership.renews_on and
 * membership.gift_until customer metafields when the shop keeps them (Shopify Flow or a
 * subscription app writes them), else from level history. All of it is for
 * display; access is decided from the synced tags alone.
 */
final class Account {
	/**
	 * @return array{
	 *     state: string,
	 *     name: string,
	 *     email: string,
	 *     levels: array<int, array{slug: string, label: string, badge: string, since: string}>,
	 *     ended: array<int, array{label: string, until: string}>,
	 *     renews: string,
	 *     gift_until: string,
	 *     features: string[],
	 *     synced: int,
	 *     checking_out: bool,
	 *     account_url: string,
	 *     subscription_url: string,
	 * }
	 */
	public static function view( int $user_id ): array {
		$view = [
			'state'            => 'guest',
			'name'             => '',
			'email'            => '',
			'levels'           => [],
			'ended'            => [],
			'renews'           => '',
			'gift_until'       => '',
			'features'         => [],
			'synced'           => 0,
			'checking_out'     => false,
			'account_url'      => '',
			'subscription_url' => (string) Settings::get( 'subscription_url' ),
		];

		$user = $user_id ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			return $view;
		}

		$view['name'] = $user->display_name;

		if ( ! Mirror::customer_id( $user_id ) ) {
			$view['state'] = ShadowUsers::is_shadow( $user_id ) ? 'syncing' : 'not_linked';

			return $view;
		}

		$member  = Mirror::member( $user_id );
		$levels  = LevelStore::levels();
		$history = LevelHistory::get( $user_id );
		$since   = (string) get_user_meta( $user_id, Mirror::SINCE, true );
		$held    = Membership::held( $levels, $member, time() );
		$top     = Membership::top( $levels, $held );

		$view['state']        = 'disabled' === $member->status ? 'disabled' : 'linked';
		$view['email']        = (string) get_user_meta( $user_id, Mirror::EMAIL, true );
		$view['renews']       = (string) get_user_meta( $user_id, Mirror::RENEWS, true );
		$view['gift_until']   = self::upcoming( (string) get_user_meta( $user_id, Mirror::GIFT_UNTIL, true ), wp_date( 'Y-m-d' ) );
		$view['synced']       = (int) $member->last_sync;
		$view['checking_out'] = PostCheckout::active( $user_id );
		$view['account_url']  = '' !== Config::store_domain() ? 'https://' . Config::store_domain() . '/account' : '';

		foreach ( $top as $slug ) {
			$level  = $levels->get( $slug );
			$joined = (int) ( $history[ $slug ]['since'] ?? 0 );

			$view['levels'][] = [
				'slug'  => $slug,
				'label' => $level ? $level->label : $slug,
				'badge' => $level ? $level->badge : '',
				'since' => '' !== $since ? $since : ( $joined ? wp_date( 'Y-m-d', $joined ) : '' ),
			];
		}

		foreach ( $history as $slug => $entry ) {
			$level = $levels->get( (string) $slug );

			if ( $level && ! empty( $entry['until'] ) && ! in_array( (string) $slug, $held, true ) ) {
				$view['ended'][] = [ 'label' => $level->label, 'until' => wp_date( 'Y-m-d', (int) $entry['until'] ) ];
			}
		}

		$view['features'] = array_values( array_map( static fn( $f ) => (string) $f['label'], Registry::granted( $user_id ) ) );

		return $view;
	}

	/**
	 * A Y-m-d date in the site's date format.
	 */
	public static function date( string $ymd ): string {
		$time = strtotime( $ymd . ' 12:00:00' );

		return $time ? wp_date( (string) get_option( 'date_format' ), $time ) : $ymd;
	}

	/**
	 * $ymd when it is $today or later, else ''. A gift that has ended is not
	 * shown (Flow removes its tag; this only keeps the card tidy). Pure.
	 */
	public static function upcoming( string $ymd, string $today ): string {
		return '' !== $ymd && $ymd >= $today ? $ymd : '';
	}
}
