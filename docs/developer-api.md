# Developer API

RedeemID's developer API is what other code may rely on: PHP functions, actions, filters, REST routes, the browser client and the event stream. Its version is `REDEEMID_API_VERSION` (currently 1.1.0). The minor part goes up when something is added, and the major part when something listed as stable changes or goes away.

- **Stable** means it changes only with a changelog entry and a version bump. Before RedeemID 1.0.0 a breaking change can still happen, but never silently.
- **Experimental** means public and documented, but its shape may change in any release.
- **Anything not on this page is internal**, even a public PHP method. Build on what's here.

`RedeemID\Api\Contract` holds the same list in code, and a test fails when the code, that list and this page disagree.

One rule runs through all of it: **extensions read access, they never store it.** Shopify is the only source of entitlements. Code that builds on RedeemID asks it at request time ("can this user read this?") instead of copying a grant into its own tables, so nothing can outlive what Shopify holds. Writes go to Shopify (the `redeemid/edit-member-tags` ability), never to a WordPress-side grant.

## PHP functions (stable)

| Function | Returns |
| --- | --- |
| `redeemid_can_read( int\|WP_Post $post, int\|WP_User\|null $user = null ): bool` | Can this user read the item in full, through every rule layer, drip and WordPress's own status checks. Never the editor bypass, never the meter. |
| `redeemid_user_levels( int\|WP_User\|null $user = null ): string[]` | Slugs of the paid levels held, including levels held through another. |
| `redeemid_has_feature( string $slug, int\|WP_User\|null $user = null ): bool` | Does the user hold a feature. |
| `redeemid_granted_features( int\|WP_User\|null $user = null ): array` | Granted features: slug => label, provenance and routes. |
| `redeemid_menu_features( int\|WP_User\|null $user = null ): array` | Granted features flagged primary, at most three. |
| `redeemid_meter( int\|WP_User\|null $user = null ): array` | `{on, used, limit, period}`: the meter as this user sees it this period. |

No user means the current visitor. None returns Shopify tags, an email or the customer record, and none calls Shopify. A page that prints member-only output from these must not be served from a shared page cache.

## The event stream (stable)

Every event goes through one action, `redeemid_event`, in one shape. Subscribe there to forward events to any analytics tool, CRM or queue. RedeemID never sends an event anywhere by itself and names no vendor.

```php
add_action( 'redeemid_event', function ( string $name, array $props, array $event ) {
	// $event = [ 'name' => ..., 'version' => 1, 'time' => <unix>, 'props' => [...] ]
	my_analytics_send( $name, $props );
}, 10, 3 );
```

Each event also fires a named shortcut with `( array $props, array $event )`:

| Event | Shortcut action | When | Props |
| --- | --- | --- | --- |
| `wall_shown` | `redeemid_wall_shown` | A locked item's own page rendered its wall for this reader | `post_id`, `post_type`, `reason`, `level`, `user_id` |
| `meter_used` | `redeemid_meter_used` | A metered item was counted, once per item per period | `post_id`, `used`, `limit`, `period`, `user_id` |
| `meter_exhausted` | `redeemid_meter_exhausted` | That count reached the limit | `post_id`, `limit`, `period`, `user_id` |
| `checkout_started` | `redeemid_checkout_started` | A plan card, product or collection buy-now button sent someone to Shopify checkout | `variant`, `quantity`, `subscription`, `signed_in`, `user_id`, `post_id` (the post the button sat on, or 0) |
| `level_gained` | `redeemid_level_gained` | Shopify's tags or status gave a user a level | `level`, `user_id` |
| `level_lost` | `redeemid_level_lost` | Shopify's tags or status took a level away | `level`, `user_id` |

- `reason` is why the wall shows: `guest`, `not_linked`, `missing_tags`, `disabled`, `no_mirror`, `bad_rule` or `drip`. `level` is the level that would open the item, or empty.
- `user_id` is the WordPress user ID, 0 for guests. Props are scalars only, and never an email, a Shopify tag or a customer ID.
- **Caching:** a guest page served from a page cache never runs PHP, so `wall_shown` on the server counts signed-in readers and cache misses only. For guests, use the browser client's `wall_shown` below.
- RedeemID → Reports counts `meter_used` and `meter_exhausted` by day. Walls shown are not stored, because that would be a database write per page view.

## Actions (stable)

- The event stream: `redeemid_event` and its shortcuts above.
- Sign-in: `redeemid_signed_in` (`int $user_id, CustomerId $id`), `redeemid_linked` (`int $user_id, CustomerId $id`), `redeemid_disconnected` (`int $user_id`) and `redeemid_before_logout` (`int $user_id`).
- Membership: `redeemid_levels_changed` (`int $user_id, string[] $added, string[] $removed`) and `redeemid_features_changed` (`int $user_id, string[] $added, string[] $removed`), `redeemid_features_mapping_changed` (`string[] $slugs`).
- Content: `redeemid_access_changed` (`int[] $post_ids`, empty means any item), `redeemid_access_levels_changed` and `redeemid_access_locked_changed` (`int $post_id, bool $locked`).
- Privacy: `redeemid_shopify_erasure_requested` (`int $request_id, string $customer_gid`).
- Logging: `redeemid_log` (`string $event, array $context`), redacted operational log lines.

## Filters

Stable:

- **Sign-in:** `redeemid_auth_callback_slug`, `redeemid_default_redirect`, `redeemid_customer_admin_redirect`, `redeemid_sign_in_redirect`, `redeemid_offer_existing_account`
- **Settings:** `redeemid_setting_{key}`
- **Access:** `redeemid_access_levels`, `redeemid_access_rules`, `redeemid_gated_post_types`, `redeemid_access_context`, `redeemid_effective_rule`, `redeemid_teaser_settings`, `redeemid_paywall_schema`, `redeemid_drip_days`
- **Features:** `redeemid_features`, `redeemid_feature_tests`, `redeemid_feature_decision`
- **Commerce:** `redeemid_context_country`, `redeemid_buyer_ip`, `redeemid_cart_attribution`, `redeemid_checkout_banner`, `redeemid_storefront_path`, `redeemid_product_url`, `redeemid_cart_enabled`
- **Display:** `redeemid_locked_panel`, `redeemid_health_checks`
- **Files:** `redeemid_file_sendfile`
- **Admin:** `redeemid_customer_badges`
- **Privacy:** `redeemid_reassign_user`, `redeemid_redact_opens_wp_request`

Experimental: `redeemid_customer_is_fixture`, `redeemid_official_state`, `redeemid_pre_sync_now` and `redeemid_client_config` (the browser client's config, printed into cacheable pages: never add personal data or a nonce).

## REST routes

The namespace is `redeemid/v1`. Write routes need the `wp_rest` nonce.

Stable:

| Route | Returns |
| --- | --- |
| `GET /state` | The caller's own state, as the browser client reads it: `{apiVersion, signedIn, linked, levels, features, meter, nonce}` |
| `GET /auth/status` | The caller's own display-safe sign-in state |
| `GET /auth/login` | A redirect to Shopify sign-in |
| `POST /auth/logout` | `{redirect}`, the Shopify end-session URL |
| `POST /auth/refresh` | Refreshes the Shopify session; never returns tokens |
| `POST /auth/disconnect` | Unlinks the Shopify customer from the current user |
| `GET /account` | The caller's own membership, as the Member Account block shows it |
| `POST /account/sync` | Re-syncs the current user's record (rate-limited) |
| `GET /features` | The caller's own granted feature slugs |

Internal (for RedeemID's own blocks and Shopify): `POST /cart/identity`, `GET /editor/products`, `GET /editor/collections`, `POST /webhooks/events`.

## Browser client (stable)

The `redeemid-client` script handle is registered on every front-end page and loads only when something needs it: list it as a dependency of your script, or enqueue it.

```php
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_script( 'my-analytics', plugins_url( 'analytics.js', __FILE__ ), [ 'redeemid-client' ], '1.0', true );
} );
```

It puts no personal data and no nonce in the page, so pages stay cacheable. The reader's state is fetched on demand, same-origin only, and never cached.

| Method | Does |
| --- | --- |
| `redeemid.state()` | A promise for the reader's state (fetched once, then shared) |
| `redeemid.refresh()` | Fetches the state again |
| `redeemid.signIn( returnTo )` | Sends the reader to Shopify sign-in, back to `returnTo` (this page by default) |
| `redeemid.signOut( returnTo )` | Signs out of WordPress and Shopify |
| `redeemid.subscribe( fn, { replay } )` | Calls `fn( name, props, event )` for every event; returns an unsubscribe function |
| `redeemid.emit( name, props )` | Adds a site's own event to the stream. Names must start with `site_` |

A subscriber also gets the events that fired before it subscribed (up to the last 100), so it can wait for consent first. Pass `{ replay: false }` to skip them. Every event is also dispatched on `document` as a `CustomEvent` named `redeemid:<name>`, with the event as `detail`.

Browser events have the same shape as the server's (`{name, version, time, props}`) and never carry a user ID:

| Event | When | Props |
| --- | --- | --- |
| `ready` | The page's walls and meter notes have been read | `api_version` |
| `state` | `state()` or `refresh()` returned | `signedIn`, `linked`, `levels`, `features` (comma-separated slugs) |
| `wall_shown` | A wall is on the page, cached or not | `post_id`, `level`, `reason`, `view` (`page`, or `list` for one in a loop) |
| `metered_view` | A metered item is open on the page | `post_id` |
| `sign_in_click` | A Shopify sign-in link was clicked | `source` (`wall` or `page`) |
| `checkout_click` | A buy-now button was pressed | `variant`, `subscription`, `source` |

## Example adapters

RedeemID ships no vendor code. Each adapter is a few lines in your own script, and you decide when it runs (after consent, for instance). These show the pattern; check each tool's current docs for its call.

```js
// Plausible
redeemid.subscribe( ( name, props ) => window.plausible?.( `redeemid_${ name }`, { props } ) );

// Matomo
redeemid.subscribe( ( name, props ) => window._paq?.push( [ 'trackEvent', 'RedeemID', name, String( props.post_id ?? props.variant ?? '' ) ] ) );

// PostHog
redeemid.subscribe( ( name, props ) => window.posthog?.capture( `redeemid_${ name }`, props ) );

// A tag manager's data layer
redeemid.subscribe( ( name, props ) => ( window.dataLayer = window.dataLayer || [] ).push( { event: `redeemid_${ name }`, ...props } ) );
```

On the server, the same pattern sends events from PHP, for example to a queue or a measurement endpoint:

```php
add_action( 'redeemid_event', function ( string $name, array $props ) {
	wp_remote_post( MY_COLLECTOR_URL, [
		'blocking' => false,
		'body'     => wp_json_encode( [ 'event' => $name, 'props' => $props ] ),
		'headers'  => [ 'Content-Type' => 'application/json' ],
	] );
}, 10, 2 );
```

## Connecting other plugins

Answer the other plugin's access question from RedeemID at request time, and store nothing. For example, open a LearnDash course to anyone who can read the course post under RedeemID's rules (LearnDash's `sfwd_lms_has_access` filter):

```php
add_filter( 'sfwd_lms_has_access', function ( $has_access, $post_id, $user_id ) {
	return $has_access || redeemid_can_read( (int) $post_id, $user_id ? (int) $user_id : null );
}, 10, 3 );
```

Or show a bbPress forum only to Gold members:

```php
add_filter( 'bbp_user_can_view_forum', function ( $can, $forum_id, $user_id ) {
	return $can && ( 'members-forum' !== get_post_field( 'post_name', $forum_id ) || in_array( 'gold', redeemid_user_levels( $user_id ), true ) );
}, 10, 3 );
```

The hook names belong to those plugins: check them against the version you run. Because these answer at request time, a member who loses their Shopify tag loses the course or the forum on their next page view, with nothing to clean up.

To react to membership changes in another system (welcome a member in a community plugin, say), use `redeemid_level_gained` and `redeemid_level_lost`, and keep any copy you make as display, not as access.

## Abilities

On WordPress 6.9 and later the same questions are open to the Abilities API and AI agents, plus three staff actions. See [Abilities and MCP](abilities.md).
