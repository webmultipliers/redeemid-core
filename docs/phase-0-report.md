# Phase 0 gate report

Date: 2026-10-23. Scope: spec Section 17.1, Phase 0, with the fixes from the spec review folded in.

## Verdict

**Conditional go.** Every Phase 0 item passes on a real WordPress install. The gate closes after one session against a live Shopify development store, which checks the items in "Verify against a live store" below. Those items need real Shopify responses; everything else here was observed.

## What was tested

- Real WordPress 6.9 (SQLite) running on PHP's built-in server, with pretty permalinks.
- A local stand-in for Shopify that serves the token, GraphQL, authorize and end-session endpoints. It proves the plugin's side of each exchange. It does not prove Shopify's response shapes.
- 59 unit tests (`composer test`): customer ID parsing, HMAC, webhook dedupe and dead-lettering, the entitlement evaluator, tag planning, crypto and PKCE. Fixtures in `tests/fixtures/` are synthetic and shaped from Shopify's docs.

## Gate items

### 1. Blockstudio 7 coexistence

**Result: pass, in both theme types.**

- Blockstudio 7.7.7 (latest, Packagist `blockstudio/blockstudio`) installs with Composer. No license key, no private package.
- **Finding:** the `blockstudio/path` filter *replaces* the theme's block directory instead of adding to it (`Build::get_build_dir` checks `has_filter`). The POC's approach would hide a Blockstudio theme's own blocks. The plugin instead runs its own `Blockstudio\Build::init(['dir' => …/blocks])` instance on `init`, priority 9.
- With Twenty Twenty-Five, all four plugin blocks registered.
- With a Blockstudio child theme that has its own `blockstudio/hero` block, the plugin's four blocks and the theme's block all registered.
- Diagnostics records the registration result on every load, so a broken registration shows up there.
- **Finding:** an attribute with id `style` is reserved by WordPress, and Blockstudio drops it with a warning. The spec's auth-action `style` attribute is now `controlStyle`.

### 2. Pins and names

**Result: pass.** Each item was checked on shopify.dev.

- **API version 2026-10** is the latest stable, used for every surface. The POC's 2025-10 is no longer supported. Override with `WP2_SHOPIFY_API_VERSION`.
- **Webhook topics** in `src/Webhooks/Topics.php`:
  - `customers/create`, `customers/update`, `customers/delete`, `customers/enable`, `customers/disable` and `customers/merge`
  - `customer.tags_added` and `customer.tags_removed`
  - `customers_email_marketing_consent/update`, `customers_marketing_consent/update` and `customer_purchasing_summary/create`
  - `orders/paid`, `orders/cancelled` and `refunds/create`
  - `customers/data_request`, `customers/redact`, `shop/redact` and `app/uninstalled`
- **Correction to the spec:** `customer.tags_added` and `customer.tags_removed` are valid topics. Since 2025-01, `customers/update` payloads no longer carry tags, so these two topics are now the only push channel for tag changes.
- `customers/add_tags` is not a topic. It is dead-lettered, and Diagnostics shows it.
- **Mutations:** `tagsAdd` and `tagsRemove` (Admin). Reads use `nodes(ids:)` for reconciliation and the customer `orders` connection for membership recompute.

### 3. Identity core (spec Sections 5 and 6)

**Result: pass on the plugin side.**

| Check | Observed |
|---|---|
| PKCE on the server | The authorize URL carries an S256 challenge. The verifier stays in a transient keyed by `state`, never in the browser. |
| Callback | Single-use. A replayed callback returns `state_invalid`. |
| Token exchange | Sends the `code_verifier`. Uses Basic auth when a client secret is configured, and the public-client form when it isn't. |
| ID token | Checks `iss`, `aud`, `exp` and `nonce`. It does not check the signature. OIDC Core 3.1.3.7 allows this, because the token arrives straight from the token endpoint over TLS. The docs say so plainly. |
| Token storage | Tokens are encrypted with sodium inside the WordPress session record. No plaintext anywhere in `usermeta`. No endpoint returns a token. |
| Refresh | Single-flight under a lock. Refresh-token rotation was observed. The REST refresh needs a nonce. |
| Revoked refresh (review point 8) | Shopify tokens are dropped. A shadow user is signed out of WordPress. A regular WordPress account stays signed in and loses only its Shopify features. |
| Logout | Redirects to Shopify's end-session endpoint with `id_token_hint`, which ends both sessions. |
| Shadow users | `shopify_customer` role. No wp-admin, admin bar, password reset, author archive or user-list entry. Mail goes to the mirrored Shopify email. |
| Linking | An admin is refused. An email mismatch is refused. An unverified email is refused. A verified match goes through an explicit confirm form and links once. Sync and disconnect both worked. |
| Webhooks | A valid HMAC returns 200; a wrong secret returns 401. A replay is deduped. An unknown topic is dead-lettered. A disabled customer loses access. A tag delta is applied. An unknown customer is counted as unmatched. |
| ID mapping | GID and numeric ID are stored side by side and looked up numeric-first, so webhook payloads match login data. |
| Gating | For guests and non-members, the Members-only area is absent from page HTML, REST `content.rendered`, RSS and search excerpts. Members see it. Gated pages send `DONOTCACHEPAGE`. |

**Fixed during the run:**

- Shadow users were bounced from `admin-post.php`, which broke logout and connect.
- Status hydration reported linked users as unlinked.
- The membership map misparsed `gid://` product IDs.
- When no page existed at the callback slug, the fallback page stripped the confirm form.

### 4. Repository hygiene

**Result: pass.** The repository has none of the following:

- `.env`, `dist/` or zips
- a stub block
- credentials or a vendored copy of Blockstudio

`.gitignore` blocks all of the above. The release is `composer release` (`--no-dev`, authoritative classmap). `composer.lock` is committed so builds can be reproduced.

## Owner decisions applied

- **Purchase to tag (review point 1): the plugin owns it.**
  - An admin maps membership products to tags under Settings → Shopify.
  - `orders/paid`, `orders/cancelled` and `refunds/create` recompute the customer's managed tags from their orders through the Admin API, then apply `tagsAdd`/`tagsRemove`. Only tags in the map are touched.
  - After checkout, a rate-limited sync runs on the first signed-in page views.
  - This needs `WP2_SHOPIFY_ADMIN_ACCESS_TOKEN`.
- **Personal pieces (review point 2): gates are decided on the server, and the chrome hydrates.**
  - Gate decisions happen only on the server.
  - The auth control renders a neutral, cacheable shell, then fills itself with one same-origin status call. That call returns fresh nonces, so no per-user nonce lands in cached HTML.
  - The cookie names that cache rules need are listed in the README.
- **Two content areas (review point 4): parent and child blocks.**
  - `membership-gate` holds a locked template of `membership-gate-content` (Members only) and `membership-gate-fallback` (Public teaser).
  - This fixes the shape of saved content before Phase 1.
- **Section 18 defaults taken.** These are the spec's recommendations; say if any should change.
  - Tags first.
  - Distributed as a plugin.
  - Single store.

## Verify against a live store

These need real Shopify responses, so they close the gate:

1. The Customer Account API `customer { tags }` field and the `email_verified` claim in the ID token, for the linking email check.
2. The `customer.tags_added` and `customer.tags_removed` payload shapes. The extractor accepts `tags` as either an array or a comma string.
3. The `customers/merge` payload field names for the kept and removed customer IDs.
4. Public or confidential client for the headless channel's Customer Account API app. Both paths are implemented.
5. Admin token scopes:
   - `read_customers`, `write_customers` and `read_orders`;
   - `read_all_orders` if memberships outlast 60 days of order history.
6. The full test matrix (spec 17.2) against the dev store, with Diagnostics green.

### Found while setting up the live store (2026-10-23)

- **No more permanent Admin tokens for new apps.** Since 2026-01-01, new custom apps come from the Dev Dashboard. Their Admin tokens come from the client credentials grant and last 24 hours. The plugin now accepts `WP2_SHOPIFY_ADMIN_CLIENT_ID` and `WP2_SHOPIFY_ADMIN_CLIENT_SECRET`, fetches tokens itself, caches them encrypted until five minutes before expiry, and retries once on a 401. A legacy `WP2_SHOPIFY_ADMIN_ACCESS_TOKEN` still works.
- **Webhooks are registered by the plugin.** Webhooks created in Settings → Notifications are signed with a different key than app webhooks, and that page lacks topics the plugin needs. The plugin now registers its own subscriptions through the app (`webhookSubscriptionCreate` with `uri`, which replaced `callbackUrl` in 2025-10). It verifies them with the app's client secret, so `WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET` is now optional.
- **Compliance topics** (`customers/data_request`, `customers/redact`, `shop/redact`) can't be subscribed through the API. A single-store install doesn't get them; a distributed app declares them in its app configuration.
- **Still to confirm live:** the purchasing summary topic. `Topics` maps `customers/purchasing_summary`, but the README listed `customer_purchasing_summary/create`. The registration result in Settings → Shopify will show which one Shopify accepts.

## Left for Phase 1

- **Search:** a gated post still appears in site search results. No secret text appears, but the post's existence does. Decide whether to exclude gated posts from search.
- **Raw `post_content`:** test the readers that bypass rendered gating:
  - SEO plugin meta and schema
  - newsletter tools
  - WPGraphQL
- **Account card:** the server-rendered account card, which also gives a no-JS path for logout, sync and disconnect. Those actions already exist as `admin-post` handlers and REST routes, but no block exposes them yet.
- **Paywall:** the paywall block and `isAccessibleForFree: false` on teasers.
- **Teaser warning:** an editor warning when the teaser repeats Members-only text.
- **Product routes:** real 404s, a sitemap, canonical URLs and SEO-plugin integration.
- **Guest cart:** nonces for the guest cart (review point 3). Status hydration already returns fresh nonces; the cart will use the same pattern.
- **Core logout:** signing out with WordPress's own logout ends the WordPress session only. The plugin's logout ends both.
- **Pretty permalinks:** the callback route needs them. Diagnostics should flag plain permalinks.

## Review traceability

| # | Review point | Where it stands |
|---|---|---|
| 1 | Purchase to tag | Built: `Membership\TagPlan`, `Membership\MembershipSync`, `Sync\PostCheckout` |
| 2 | Personal chrome and caching | Built: `Rest\Status`, the `auth-action` shell and hydration, cookie list in README |
| 3 | Guest nonces | Pattern built in `Rest\Status`. Cart is Phase 2 |
| 4 | One InnerBlocks area | Built: gate parent with two child blocks |
| 5 | No-JS forms vs REST | Built: `admin-post.php` handlers for login, logout, connect, confirm, sync and disconnect. REST is the JS path |
| 6 | Token and verifier storage, logout | Built: `Auth\StateStore`, `Auth\TokenVault`, `OAuth::logout_url` |
| 7 | Private token name collision | Built: `WP2_SHOPIFY_CLIENT_SECRET`, deprecated alias with a Diagnostics notice, `WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN` |
| 8 | WordPress vs Shopify session | Built: `TokenVault::on_revoked` (see table above) |
| 9 | `.env` claim | No `.env` in the repo or release. The shipped value was a variable reference, so no rotation is needed |
| 10 | Untraced POC findings | Defuse key bug: replaced by sodium with an HKDF-derived key. `HTTP_HOST` cookie domain: `WP2_SHOPIFY_COOKIE_DOMAIN`, never derived from the request. Guest-nonce loop: no nonces in cacheable HTML |
| 11 | Shopify's official plugin | Open for Phase 1 scoping: whether to build catalog blocks or use Storefront Web Components |
| 12 | Product route SEO | Phase 1 (above) |
| 13 | Raw `post_content` and paywall markup | Phase 1 (above) |
| 14 | Automated tests | Built: 59 unit tests with fixtures |
| 15 | Metering vs caching | Phase 2 decision, unchanged |
