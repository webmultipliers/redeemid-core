<?php

namespace RedeemID\Api;

/**
 * The event stream: one vendor-neutral feed of what happens on the paywall
 * journey, for analytics, CRMs and other plugins.
 *
 * Every event goes through one action, redeemid_event, with the same shape:
 * a name from CATALOG, its props, and an envelope ({name, version, time,
 * props}). Most events also fire a named shortcut action
 * (redeemid_wall_shown and so on) with the props alone. RedeemID never sends
 * an event anywhere itself: a site, or another plugin, subscribes.
 *
 * Props are scalars, limited to the keys the catalog declares, so an event
 * never carries a token, an email or a Shopify tag. user_id is the WordPress
 * user ID (0 for guests): server-side subscribers can look a user up, and
 * the browser stream (assets/client.js) never carries it.
 *
 * envelope() is pure.
 */
final class Events {
	/** The envelope shape. Bumps only when the shape itself changes. */
	public const VERSION = 1;

	public const HOOK = 'redeemid_event';

	public const WALL_SHOWN       = 'wall_shown';
	public const METER_USED       = 'meter_used';
	public const METER_EXHAUSTED  = 'meter_exhausted';
	public const CHECKOUT_STARTED = 'checkout_started';
	public const LEVEL_GAINED     = 'level_gained';
	public const LEVEL_LOST       = 'level_lost';

	/**
	 * Every server event: its named shortcut action and the props it may carry.
	 *
	 * - wall_shown: a locked item's own page rendered its wall for this reader.
	 *   Fires only when PHP runs, so a guest page served from a page cache
	 *   doesn't count: use the browser stream for guests.
	 * - meter_used: a metered item was counted against the reader's meter
	 *   (once per item per period).
	 * - meter_exhausted: that count reached the limit.
	 * - checkout_started: a plan card, product or collection buy-now button
	 *   sent someone to Shopify checkout.
	 * - level_gained / level_lost: one level, one user, because Shopify changed
	 *   their tags or status. The same moment as redeemid_levels_changed.
	 *
	 * @var array<string, array{action: string, props: string[]}>
	 */
	public const CATALOG = [
		self::WALL_SHOWN       => [
			'action' => 'redeemid_wall_shown',
			'props'  => [ 'post_id', 'post_type', 'reason', 'level', 'user_id' ],
		],
		self::METER_USED       => [
			'action' => 'redeemid_meter_used',
			'props'  => [ 'post_id', 'used', 'limit', 'period', 'user_id' ],
		],
		self::METER_EXHAUSTED  => [
			'action' => 'redeemid_meter_exhausted',
			'props'  => [ 'post_id', 'limit', 'period', 'user_id' ],
		],
		self::CHECKOUT_STARTED => [
			'action' => 'redeemid_checkout_started',
			'props'  => [ 'variant', 'quantity', 'subscription', 'signed_in', 'user_id', 'post_id' ],
		],
		self::LEVEL_GAINED     => [
			'action' => 'redeemid_level_gained',
			'props'  => [ 'level', 'user_id' ],
		],
		self::LEVEL_LOST       => [
			'action' => 'redeemid_level_lost',
			'props'  => [ 'level', 'user_id' ],
		],
	];

	/**
	 * The event as subscribers get it, or null for a name not in the catalog.
	 * Undeclared props are dropped, and a non-scalar prop becomes null.
	 *
	 * @param array<string, mixed> $props
	 * @return array{name: string, version: int, time: int, props: array<string, scalar|null>}|null
	 */
	public static function envelope( string $name, array $props, int $now ): ?array {
		if ( ! isset( self::CATALOG[ $name ] ) ) {
			return null;
		}

		$clean = [];

		foreach ( self::CATALOG[ $name ]['props'] as $key ) {
			$value         = $props[ $key ] ?? null;
			$clean[ $key ] = is_scalar( $value ) ? $value : null;
		}

		return [
			'name'    => $name,
			'version' => self::VERSION,
			'time'    => $now,
			'props'   => $clean,
		];
	}

	/**
	 * Send an event to subscribers. Unknown names are ignored.
	 *
	 * @param array<string, mixed> $props
	 */
	public static function emit( string $name, array $props ): void {
		$event = self::envelope( $name, $props, time() );

		if ( null === $event ) {
			return;
		}

		/**
		 * Every RedeemID event, in one shape. Subscribe here to forward events
		 * to any analytics or CRM service.
		 *
		 * @since 0.0.4
		 *
		 * @param string                    $name  An event name from RedeemID\Api\Events::CATALOG.
		 * @param array<string, scalar|null> $props The event's props.
		 * @param array                     $event The envelope: name, version, time, props.
		 */
		do_action( self::HOOK, $event['name'], $event['props'], $event );

		/**
		 * The named shortcut for one event, such as redeemid_wall_shown.
		 *
		 * @since 0.0.4
		 *
		 * @param array<string, scalar|null> $props
		 * @param array                     $event The envelope.
		 */
		do_action( self::CATALOG[ $name ]['action'], $event['props'], $event );
	}
}
