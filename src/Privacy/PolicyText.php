<?php

namespace RedeemID\Privacy;

use RedeemID\Support\EventLog;

/**
 * Suggested text for the site's privacy policy, in Settings → Privacy →
 * Policy Guide. Site owners copy and adapt it; RedeemID never edits the policy.
 */
final class PolicyText {
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'add' ] );
	}

	public static function add(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'RedeemID', 'redeemid' ), wp_kses_post( wpautop( self::text(), false ) ) );
		}
	}

	public static function text(): string {
		$days = (int) ( EventLog::RETENTION / DAY_IN_SECONDS );

		return implode(
			"\n\n",
			[
				'<strong class="privacy-policy-tutorial">' . __( 'Suggested text:', 'redeemid' ) . '</strong> ' . __( 'Membership on this site comes from your customer account at our Shopify store. When you sign in with it, we keep a copy of a few account details on your account here: your Shopify customer ID, name and email address, the tags that decide your membership, your account status, your email and SMS marketing choices, a purchase summary (number of orders and amount spent), and the dates your membership started and renews. Shopify stays the record of your account; this copy is updated whenever Shopify reports a change, and is used only to decide what you can read here.', 'redeemid' ),
				__( 'If this site limits free reading, we record the members-only items you opened this period. If you start a checkout or add to a cart from this site, we keep the Shopify cart reference for up to 14 days.', 'redeemid' ),
				/* translators: %d: number of days */
				sprintf( __( 'We keep a log of membership events, such as sign-ins, syncs and updates received from Shopify, for %d days.', 'redeemid' ), $days ),
				...( \RedeemID\Api\Outbound::enabled() ? [ __( 'We send records of some activity on this site, such as reaching the free reading limit, starting a checkout or a membership level changing, to [name the service that receives event webhooks]. These records carry your account number on this site and, if we chose to, your Shopify customer ID, but not your name or email address. Erasing your data here does not remove what that service already received; ask us and we will pass the request on.', 'redeemid' ) ] : [] ),
				__( 'Cookies: redeemid_oauth_state and redeemid_pending (for ten minutes while you sign in), redeemid_cart and redeemid_cart_count (your cart, for up to 14 days), and redeemid_fset (which membership features you hold, so pages can be cached correctly; removed when you close your browser).', 'redeemid' ),
				__( 'You can ask for a copy of this data, or for it to be erased, from your account page or by contacting us. Erasing it here does not delete your Shopify customer account; ask us if you want that too, and we will pass the request to Shopify.', 'redeemid' ),
			]
		);
	}
}
