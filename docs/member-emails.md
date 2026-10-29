# Member emails

RedeemID doesn't send members email. Join, renewal, failed payment and cancellation emails belong where the billing happens: **Shopify Flow** with **Shopify Email** (or your subscription app's own notifications) knows about the order, the payment and the subscription, and sends from your store's domain with your store's branding. Start there.

Some emails only make sense on the WordPress side, such as "your Gold archive is open, here's where to start" linking to members-only pages. For those, RedeemID fires an action whenever a sync changes the levels a member holds:

```php
do_action( 'redeemid_levels_changed', int $user_id, string[] $added, string[] $removed );
```

`$added` and `$removed` are level slugs (`gold`), never tags. Levels held through another count: a member who gains Gold, which includes Silver, gets `[ 'gold', 'silver' ]`.

## A welcome email, as a starting point

Put this in a small plugin or your theme's `functions.php`, and adjust the copy:

```php
add_action(
	'redeemid_levels_changed',
	function ( int $user_id, array $added, array $removed ): void {
		if ( ! $added ) {
			return;
		}

		// Send after the sync, not during the webhook.
		wp_schedule_single_event( time(), 'mysite_member_welcome', [ $user_id, $added ] );
	},
	10,
	3
);

add_action(
	'mysite_member_welcome',
	function ( int $user_id, array $added ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$labels = wp_list_pluck( redeemid_level_options(), 'label', 'value' );
		$names  = array_map( fn( $slug ) => $labels[ $slug ] ?? $slug, $added );

		wp_mail(
			$user->user_email,
			sprintf( 'Welcome to %s', implode( ' and ', $names ) ),
			"Your membership is active. Start here: " . home_url( '/members/' )
		);
	},
	10,
	2
);
```

A customer who signed in with Shopify has a WordPress account whose stored email is a placeholder. RedeemID routes `wp_mail()` to such an account to the customer's Shopify email, so the code above reaches them.

## Things to decide before you turn it on

- **The first sync after linking.** A long-standing member who signs in on WordPress for the first time "gains" their levels on that sync. If that shouldn't trigger a welcome, skip users whose `_redeemid_linked_at` user meta is less than a few minutes old.
- **Bulk resyncs.** A staff resync of everyone can change many members at once, for example after a tag was renamed in Shopify. Queue the emails (as above) and consider a daily cap.
- **Leaving.** `$removed` fires when Shopify removes the tag, whatever the reason: cancellation, a refund, a lapsed trial or a staff edit. WordPress doesn't know which, so a "sorry to see you go" email is better sent by Flow, which does.
- **Opt-outs.** These are transactional only if they are about the membership the member just got. Anything promotional should respect the marketing consent Shopify holds.
