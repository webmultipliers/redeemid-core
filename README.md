# WP2 Shopify

Sign in with Shopify customer accounts on WordPress, and gate content by Shopify customer tags. Built on [Blockstudio 7](https://blockstudio.dev).

> **Status: 2.0.0-alpha.1 (Phase 0).** Identity, linking, webhooks, membership tagging and the gate block are in place. Catalog, cart and the account card come in later phases. See [docs/phase-0-report.md](docs/phase-0-report.md) for what was verified and what is still open.

## Requirements

- PHP 8.2+ with `sodium`
- WordPress 6.7+
- **Pretty permalinks.** The sign-in callback is a front-end route.
- A Shopify store with a headless or custom app that has Customer Account API access

If a requirement is missing, activation stops with a notice. It does not fatal.

## Install

Release zips include `vendor/`. To build from source:

```sh
composer install            # development
composer test               # unit tests
composer build-prod         # production vendor/ for a deploy or release zip
```

### Deploying from the repository (InstaWP and similar)

Have the host run `composer build-prod` in the plugin folder after each sync. That is the only build step; Blockstudio compiles block styles on first load, so `blocks/*/_dist/` must be writable.

Run these WP-CLI steps separately, once per site:

```sh
wp plugin activate <plugin-folder>
wp rewrite structure '/%postname%/'
wp rewrite flush
```

The plugin runs its own Blockstudio instance from `blocks/`. It does not use the `blockstudio/path` filter, because that filter replaces a theme's Blockstudio block folder. So it coexists with Blockstudio themes and works in any other theme.

## Configure

Credentials are constants in `wp-config.php`, never options. These seven are the whole configuration surface; Shopify → Debug shows which are present, never their values.

| Constant | Required | Purpose |
|---|---|---|
| `WP2_SHOPIFY_STORE_DOMAIN` | yes | `your-store.myshopify.com` |
| `WP2_SHOPIFY_CLIENT_ID` | yes | Customer Account API client ID. Powers sign-in. |
| `WP2_SHOPIFY_CLIENT_SECRET` | confidential clients | Customer Account API client secret. Leave unset for a public client. |
| `WP2_SHOPIFY_ADMIN_CLIENT_ID` | yes | Client ID of the Dev Dashboard app installed on the store. Powers purchase-to-tag, reconciliation, resync, tag editing and webhook registration. |
| `WP2_SHOPIFY_ADMIN_CLIENT_SECRET` | yes | That app's client secret. The plugin uses it to request 24-hour Admin tokens (client credentials grant) and to verify the app's webhooks. |
| `WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN` | for plan cards | Storefront API public token from the Headless channel. Powers plan cards and buy-now. Public by design. |
| `WP2_SHOPIFY_ENCRYPTION_KEY` | no | Base64 32-byte key for token encryption. By default a key is derived from the WordPress salts. Set this if you rotate salts. |

`WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN` from 1.x still works as an alias for the client secret, with an admin notice. Rename it to `WP2_SHOPIFY_CLIENT_SECRET`.

These constants are no longer read. If one is still defined, every admin screen shows a notice and Debug says what replaced it:

- `WP2_SHOPIFY_ADMIN_ACCESS_TOKEN` (legacy permanent Admin token): use the Dev Dashboard app constants above.
- `WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET`: the plugin registers its own webhooks, signed with the app's client secret.
- `WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN`: nothing reads it yet; it returns with the server-side cart.
- `WP2_SHOPIFY_API_VERSION`: the API version is pinned to `2026-10`.
- `WP2_SHOPIFY_COOKIE_DOMAIN`: WordPress' own `COOKIE_DOMAIN` is used.

## Admin screens

Everything lives under the top-level **Shopify** menu (Settings → Shopify redirects there):

- **Overview:** status cards for configuration, sign-in, the Admin API, webhooks, linked customers and failures in the last 24 hours, plus the settings (freshness window, stale behaviour, membership products).
- **Customers:** every WordPress user linked to a Shopify customer, with search, filters for disabled customers and failed syncs, and bulk resync or disconnect. Row actions open the customer in the Shopify admin, edit the WordPress user, resync, or switch to the user when the User Switching plugin is active. Each customer's page shows the synced data, edits tags (written to Shopify with `tagsAdd`/`tagsRemove`, then read back), and lists that customer's recent events and webhooks.
- **Webhooks:** the endpoint, whether the signing secret is set, the registration result, a **Register webhooks with Shopify** button, rejected deliveries, and every delivery received with its outcome. Dead-lettered deliveries can be retried.
- **App events:** a log of every call the plugin made to Shopify (label, endpoint, HTTP status, duration) and what it did: resyncs, reconciliation, purchase-to-tag, sign-ins, linking, webhook outcomes and admin actions, with who triggered each and from where (cron, REST, admin, site). No request or response bodies, tokens or secrets are recorded. Kept 30 days, capped at 20,000 rows.
- **Debug:** the constants above, platform checks (PHP, WordPress, Blockstudio, plugin blocks, pretty permalinks), the Shopify connection and granted scopes, buttons to refresh discovery, request a fresh Admin token and run reconciliation, and the dry-run entitlement check.

Other code can add pages under the menu: `add_submenu_page( WP2_SHOPIFY_ADMIN_MENU, ... )` on `admin_menu` after priority 10. Two filters help test setups:

- `wp2_shopify_customer_badges` (`array $badges, int $user_id`): labels shown next to a customer's name.
- `wp2_shopify_customer_is_fixture` (`bool $is_fixture, int $user_id`): return true for seeded users whose customer does not exist in Shopify. Reconciliation and resync keep their tags and only mark them fresh, tag edits stay in WordPress, and no Shopify admin link is shown.

Signed-in customers can resync themselves from the front end with `POST /wp-json/wp2-shopify/v1/account/sync` (REST nonce, once a minute).

### Shopify app settings

Shopify → Debug prints the exact values for your site:

- **Callback URI:** `https://your-site/auth-callback/`. Change the slug with the `wp2_shopify_auth_callback_slug` filter.
- **JavaScript origin:** `https://your-site`
- **Logout URI:** `https://your-site/`

### Admin app

New custom apps are made in Shopify's Dev Dashboard; since 2026-01-01 the Shopify admin no longer creates them. Create one in the same organization as the store, with:

- **Admin API scopes:** `read_customers`, `write_customers` and `read_orders`.
- **`read_all_orders`** is a protected scope. The Dev Dashboard rejects it as invalid until Shopify approves a request for it in the app's settings. Without it, Shopify shows only the last 60 days of orders. The plugin then still grants tags from any visible paid order, and removes a tag only for a refund or cancellation it can see, so older members keep their tags. The same rule applies to a customer with more than 100 orders, or an order with more than 100 line items, because the plugin reads one page of each. Request the scope if refunds of purchases older than 60 days must revoke access automatically.
- **Redirect URL:** your site's home URL. The client credentials grant doesn't use it, but the form requires one.

Install it on the store, then set `WP2_SHOPIFY_ADMIN_CLIENT_ID` and `WP2_SHOPIFY_ADMIN_CLIENT_SECRET`. Shopify → Debug shows the scopes Shopify granted.

### Webhooks

Click **Register webhooks with Shopify** on Shopify → Webhooks. The plugin subscribes its endpoint, `https://your-site/wp-json/wp2-shopify/v1/webhooks/events`, to every topic below through the app, so deliveries are signed with the app's client secret. Running it again only adds what is missing. Don't also create webhooks in Settings → Notifications: those are signed with a different key and would fail verification.

The topics:

- `customers/create`, `customers/update`, `customers/delete`, `customers/enable`, `customers/disable` and `customers/merge`
- `customer.tags_added` and `customer.tags_removed`
- `customers_email_marketing_consent/update`, `customers_marketing_consent/update` and `customer_purchasing_summary/create`
- `orders/paid`, `orders/cancelled` and `refunds/create` (for memberships)
- `app/uninstalled`
- the compliance topics `customers/data_request`, `customers/redact` and `shop/redact`. Shopify doesn't allow these through the API; they come only to apps that declare them in their app configuration.

Since API 2025-01, `customers/update` no longer carries tags, so the two `customer.tags_*` topics are needed for tag changes to arrive quickly.

Each delivery is checked and stored, then processed:

- The HMAC is checked first.
- Each delivery is deduped by its webhook ID.
- Only the fields the plugin uses are stored.
- Processing runs in the background (Action Scheduler if present, otherwise WP-Cron).
- Failures retry, then are dead-lettered. Shopify → Webhooks lists every delivery and its outcome.

A twice-daily reconciliation also re-reads linked customers from the Admin API, so a missed webhook heals itself.

## How sign-in works

1. The **Shopify sign-in** block (`wp2-shopify/auth-action`) links to the plugin's login handler.
2. The plugin redirects to Shopify with PKCE. The verifier stays on the server.
3. Shopify returns to the callback. The plugin exchanges the code, checks the ID token claims (`iss`, `aud`, `exp` and `nonce`; the signature is not checked, as OIDC allows for tokens received directly from the token endpoint), and signs the visitor in.
4. A new customer gets a **shadow user** with the `shopify_customer` role:
   - no wp-admin, admin bar, password reset or author archive;
   - hidden from the user list;
   - mail goes to the Shopify email.
   Accounts are never matched by email at sign-in.
5. Tokens are stored encrypted inside the WordPress session, and refresh on demand. No endpoint returns a token.
6. **Sign out** goes through Shopify's end-session endpoint, so both sessions end. WordPress's own logout link ends only the WordPress session.

If Shopify revokes the session:

- a shadow user is signed out;
- a regular WordPress account stays signed in and loses only its Shopify features.

### Linking an existing WordPress account

A signed-in WordPress user can choose **Connect to Shopify**. After the Shopify sign-in:

- the plugin checks that Shopify marks the email as verified and that it matches the WordPress account;
- the user then confirms on the callback page.

Administrators can't be linked, and a linked account that later becomes an administrator can't sign in with Shopify. A mismatched or unverified email is refused with a plain message.

## Gating content

Add **Membership gate** (`wp2-shopify/membership-gate`). It always contains two areas:

- **Members only.** It is removed on the server before rendering when the visitor doesn't qualify. It never reaches page HTML, REST, feeds or search excerpts.
- **Public teaser.** Everyone sees it.

Gate settings:

- Required tags, matching any or all.
- What non-members see:
  - a message with a call to action and an optional sign-in link;
  - the teaser blocks;
  - a redirect.

Rules fail closed. If the tag data is older than the freshness window (Shopify → Overview, default 7 days), it is either still honored or treated as no access, as configured. Shopify → Debug has a dry run that shows the decision for any user.

### Plan cards and buy-now

Add **Shopify Plan Card** (`wp2-shopify/plan-card`) and give it a product handle. It shows the product's live Shopify price, cached for five minutes, and a button that goes straight to Shopify checkout. Put plan cards on the join page and in a gate's fallback.

- **Buy-now is a plain form.** It posts to `admin-post.php?action=wp2_shopify_buy_now`, so it works without JavaScript. The server creates a one-line cart, attaches the visitor's identity if they're signed in with Shopify, and redirects to a fresh checkout URL. If Shopify refuses the customer token, it retries as a guest checkout rather than block the purchase.
- **Cache-safe.** The card has no personal data and no nonce. Buy-now is rate-limited to 10 attempts per IP per minute.
- **Independent of Shopify's plugin.** It works the same whether that plugin is installed or not, and never touches its cart.

It needs `WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN`, and the product must be published to the Headless channel that issued the token.

### Memberships from purchases

Under Shopify → Overview, map products to tags, one per line:

```
1234567890: gold-member
gid://shopify/Product/987: silver-member
```

When an order is paid, cancelled or refunded, the plugin:

1. recomputes that customer's mapped tags from their orders;
2. adds or removes those tags in Shopify.

Only tags in the map are touched. After checkout, the next signed-in page views also re-sync, at most once a minute for 30 minutes, so access usually arrives before the webhook.

## Caching

Gated pages are decided on the server, and send `DONOTCACHEPAGE` when they show members-only content.

Personal pieces, such as the sign-in control, render as a neutral shell that any page cache can store. They fill themselves after load with one same-origin request to `admin-ajax.php?action=wp2_shopify_status`, which also returns fresh nonces.

Cache rules should bypass or vary on these cookies:

- `wordpress_logged_in_*`
- `wp2_shopify_oauth_state`
- `wp2_shopify_cart` (later phases)

## Shopify's WordPress plugin

Shopify publishes its own plugin for the Sell on WordPress channel. It is an anonymous storefront: product, collection and cart-toggle blocks, `/products/{handle}`, `/collections/{handle}` and `/cart`, all built on Storefront Web Components. It has no sign-in, webhooks or gating, so it and WP2 do different jobs on the same store.

WP2 works with or without it:

- **Detection.** Overview and Debug show one of four states: not installed, active but not connected, connected to the same store as `WP2_SHOPIFY_STORE_DOMAIN`, or connected to another store. The last one is an error, because purchases there can't unlock content here. Other code can read the state with `WP2_Shopify\Integrations\OfficialPlugin::state()` or change it with the `wp2_shopify_official_state` filter.
- **Credentials stay separate.** WP2 reads only the official plugin's store domain, to compare it. It never reads its Storefront token.
- **Routes.** When active, the official plugin always owns `/cart` and, by default, `/products/*`. WP2 claims neither. Debug shows which ones it currently owns.
- **Gating.** Official product and collection blocks can go inside a membership gate. The gate renders on the server, so non-members never receive the block's markup.

## REST API

The namespace is `wp2-shopify/v1`. Every write route needs the `wp_rest` nonce.

| Route | Purpose |
|---|---|
| `GET /auth/status` | The caller's own display-safe state |
| `GET /auth/login` | Redirects to Shopify |
| `POST /auth/logout` | Returns `{redirect}`, the Shopify end-session URL |
| `POST /auth/refresh` | Refreshes the Shopify session and returns status, never tokens |
| `POST /auth/disconnect` | Unlinks the Shopify customer from the current user |
| `POST /account/sync` | Re-syncs the current user's mirror (rate-limited) |
| `POST /webhooks/events` | Shopify webhooks (HMAC-verified) |

Non-JavaScript forms post to `admin-post.php` handlers (`wp2_shopify_login`, `_logout`, `_connect`, `_link_confirm`, `_sync`, `_disconnect`), which redirect back to a page.

## Hooks

- Actions:
  - `wp2_shopify_signed_in`, `wp2_shopify_linked`, `wp2_shopify_disconnected` and `wp2_shopify_before_logout`
  - `wp2_shopify_log`, which receives redacted log events
- Filters:
  - `wp2_shopify_auth_callback_slug`, `wp2_shopify_default_redirect` and `wp2_shopify_customer_admin_redirect`
  - `wp2_shopify_membership_map` and `wp2_shopify_setting_{key}`
  - `wp2_shopify_official_state`, which overrides the detected state of Shopify's own plugin
  - `wp2_shopify_reassign_user`, which picks who receives content when a Shopify redaction deletes a shadow user

## Upgrading from 1.x

The upgrade runs once on activation or load:

- Shadow users and their customer IDs are kept, and numeric IDs are backfilled.
- Stored refresh tokens were plaintext in 1.x, so they are discarded. **Every linked customer signs in once more.**
- Options that held full webhook payloads, and the old encryption key, are removed.
- **Re-register webhooks** to `/wp-json/wp2-shopify/v1/webhooks/events`. The old path is gone.
- The `[wp2_shopify_identity]` shortcode still works, but is deprecated. Use the block.

## Uninstall

By default, uninstalling keeps WordPress users and their content. It removes:

- plugin options and transients
- the webhook and app event tables
- Shopify tokens inside sessions

To also delete shadow users and every linked field, add `define( 'WP2_SHOPIFY_UNINSTALL_DELETE_DATA', true );` before uninstalling. Their content is reassigned to the first administrator, not deleted.

## Privacy

The plugin registers a personal data exporter and an eraser for the Shopify fields it mirrors. Shopify's `customers/data_request` creates a WordPress export request. `customers/redact` deletes or unlinks the account.
