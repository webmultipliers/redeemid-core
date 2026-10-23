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

These WP-CLI steps run separately. The first three are needed only once per site:

```sh
wp plugin activate <plugin-folder>
wp rewrite structure '/%postname%/'
wp rewrite flush
```

The plugin runs its own Blockstudio instance from `blocks/`. It does not use the `blockstudio/path` filter, because that filter replaces a theme's Blockstudio block folder. So it coexists with Blockstudio themes and works in any other theme.

## Configure

Credentials are constants in `wp-config.php`, never options. Settings → Shopify shows which constants are present, never their values.

| Constant | Required | Purpose |
|---|---|---|
| `WP2_SHOPIFY_STORE_DOMAIN` | yes | `your-store.myshopify.com` |
| `WP2_SHOPIFY_CLIENT_ID` | yes | Customer Account API client ID |
| `WP2_SHOPIFY_CLIENT_SECRET` | confidential clients | Customer Account API client secret. Leave unset for a public client. |
| `WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET` | yes | Verifies webhook HMACs |
| `WP2_SHOPIFY_ADMIN_ACCESS_TOKEN` | for memberships | Admin API token with `read_customers`, `write_customers` and `read_orders`. Add `read_all_orders` if memberships last longer than 60 days. Powers purchase-to-tag and reconciliation. |
| `WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN` | later phases | Storefront public token (catalog) |
| `WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN` | later phases | Storefront private token, for server-side cart calls |
| `WP2_SHOPIFY_ENCRYPTION_KEY` | no | Base64 32-byte key for token encryption. By default a key is derived from the WordPress salts. Set this if you rotate salts. |
| `WP2_SHOPIFY_API_VERSION` | no | Defaults to `2026-10` for every Shopify API |
| `WP2_SHOPIFY_COOKIE_DOMAIN` | no | Cookie domain. It is never taken from the request host. |

`WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN` from 1.x still works as an alias for the client secret, and Diagnostics shows a deprecation notice. Rename it to `WP2_SHOPIFY_CLIENT_SECRET`.

### Shopify app settings

Settings → Shopify prints the exact values for your site:

- **Callback URI:** `https://your-site/auth-callback/`. Change the slug with the `wp2_shopify_auth_callback_slug` filter.
- **JavaScript origin:** `https://your-site`
- **Logout URI:** `https://your-site/`

### Webhooks

Point these topics at `https://your-site/wp-json/wp2-shopify/v1/webhooks/events`:

- `customers/create`, `customers/update`, `customers/delete`, `customers/enable`, `customers/disable` and `customers/merge`
- `customer.tags_added` and `customer.tags_removed`
- `customers_email_marketing_consent/update`, `customers_marketing_consent/update` and `customer_purchasing_summary/create`
- `orders/paid`, `orders/cancelled` and `refunds/create` (for memberships)
- the compliance topics: `customers/data_request`, `customers/redact`, `shop/redact`, plus `app/uninstalled`

Since API 2025-01, `customers/update` no longer carries tags, so the two `customer.tags_*` topics are needed for tag changes to arrive quickly.

Each delivery is checked and stored, then processed:

- The HMAC is checked first.
- Each delivery is deduped by its webhook ID.
- Only the fields the plugin uses are stored.
- Processing runs in the background (Action Scheduler if present, otherwise WP-Cron).
- Failures retry, then are dead-lettered. Diagnostics shows counts and the last delivery.

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

Administrators can't be linked. A mismatched or unverified email is refused with a plain message.

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

Rules fail closed. If the tag data is older than the freshness window (Settings → Shopify, default 7 days), it is either still honored or treated as no access, as configured. Settings → Shopify has a dry run that shows the decision for any user.

### Memberships from purchases

Under Settings → Shopify, map products to tags, one per line:

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
- the webhook table
- Shopify tokens inside sessions

To also delete shadow users and every linked field, add `define( 'WP2_SHOPIFY_UNINSTALL_DELETE_DATA', true );` before uninstalling. Their content is reassigned to the first administrator, not deleted.

## Privacy

The plugin registers a personal data exporter and an eraser for the Shopify fields it mirrors. Shopify's `customers/data_request` creates a WordPress export request. `customers/redact` deletes or unlinks the account.
