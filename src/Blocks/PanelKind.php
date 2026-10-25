<?php

namespace WP2_Shopify\Blocks;

use WP2_Shopify\Entitlement\Decision;

/**
 * What the locked panel offers, from why the visitor was turned away. Pure,
 * so every branch is unit tested; Gate::fallback_panel() words it.
 *
 * - sign_in:  a guest. Log in with Shopify (members and guest buyers alike).
 * - connect:  signed in to WordPress, no Shopify customer linked.
 * - finishing: just checked out, or never synced: refresh while access arrives.
 * - renew:    held the level before, lost it.
 * - upgrade:  holds another level, not this one.
 * - join:     linked, holds no level.
 * - disabled: the customer is disabled in Shopify.
 * - none:     nothing to offer (a broken rule).
 */
final class PanelKind {
	public const SIGN_IN   = 'sign_in';
	public const CONNECT   = 'connect';
	public const FINISHING = 'finishing';
	public const RENEW     = 'renew';
	public const UPGRADE   = 'upgrade';
	public const JOIN      = 'join';
	public const DISABLED  = 'disabled';
	public const NONE      = 'none';

	/**
	 * @param bool $checking_out The customer is in the post-checkout window.
	 * @param bool $lapsed       Their level history shows the wanted level ended.
	 * @param bool $has_level    They hold some other paid level.
	 */
	public static function for( string $reason, bool $checking_out = false, bool $lapsed = false, bool $has_level = false ): string {
		return match ( $reason ) {
			Decision::GUEST        => self::SIGN_IN,
			Decision::NOT_LINKED   => self::CONNECT,
			Decision::DISABLED     => self::DISABLED,
			Decision::NO_MIRROR    => self::FINISHING,
			Decision::MISSING_TAGS => $checking_out ? self::FINISHING : ( $lapsed ? self::RENEW : ( $has_level ? self::UPGRADE : self::JOIN ) ),
			default                => self::NONE,
		};
	}
}
