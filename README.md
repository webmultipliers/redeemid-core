# WP2 Shopify

Sign in with Shopify customer accounts on WordPress, and gate content by Shopify customer tags. Built on [Blockstudio 7](https://blockstudio.dev).

> **Working on this plugin? Check Blockstudio first.** Before writing code for a block, see whether [Blockstudio](https://blockstudio.dev/docs) already does it: conditional fields, populated pickers, islands, components, variations and more. See [Building on Blockstudio](#building-on-blockstudio) for what we use and the few places we deliberately don't.

> **Status: 2.0.0-alpha.1 (Phase 0).** Identity, linking, webhooks, membership tagging, gates, access rules, features, plan cards and the bridge to Shopify's own cart are in place. The account card comes in a later phase; catalog blocks are cut in favour of Shopify's plugin (see [docs/official-plugin.md](docs/official-plugin.md)). See [docs/phase-0-report.md](docs/phase-0-report.md) for what was verified and what is still open.

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

Credentials are constants in `wp-config.php`, never options. These eight are the whole configuration surface; Shopify → Debug shows which are present, never their values.

| Constant | Required | Purpose |
|---|---|---|
| `WP2_SHOPIFY_STORE_DOMAIN` | yes | `your-store.myshopify.com` |
| `WP2_SHOPIFY_CLIENT_ID` | yes | Customer Account API client ID. Powers sign-in. |
| `WP2_SHOPIFY_CLIENT_SECRET` | confidential clients | Customer Account API client secret. Leave unset for a public client. |
| `WP2_SHOPIFY_ADMIN_CLIENT_ID` | yes | Client ID of the Dev Dashboard app installed on the store. Powers purchase-to-tag, reconciliation, resync, tag editing and webhook registration. |
| `WP2_SHOPIFY_ADMIN_CLIENT_SECRET` | yes | That app's client secret. The plugin uses it to request 24-hour Admin tokens (client credentials grant) and to verify the app's webhooks. |
| `WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN` | for plan cards | Storefront API public token from the Headless channel. Powers plan cards and buy-now. Public by design. |
| `WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN` | recommended | Storefront API private token from the same Headless channel. When set it replaces the public token for every Storefront call, and buy-now and cart calls send the buyer's IP (`Shopify-Storefront-Buyer-IP`), as Shopify asks of server-side callers. Without it every visitor looks like your server's one IP, so Shopify may throttle checkout creation. |
| `WP2_SHOPIFY_ENCRYPTION_KEY` | no | Base64 32-byte key for token encryption. By default a key is derived from the WordPress salts. Set this if you rotate salts. |

`WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN` from 1.x still works as an alias for the client secret, with an admin notice. Rename it to `WP2_SHOPIFY_CLIENT_SECRET`.

These constants are no longer read. If one is still defined, every admin screen shows a notice and Debug says what replaced it:

- `WP2_SHOPIFY_ADMIN_ACCESS_TOKEN` (legacy permanent Admin token): use the Dev Dashboard app constants above.
- `WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET`: the plugin registers its own webhooks, signed with the app's client secret.
- `WP2_SHOPIFY_API_VERSION`: the API version is pinned to `2026-10`.
- `WP2_SHOPIFY_COOKIE_DOMAIN`: WordPress' own `COOKIE_DOMAIN` is used.

## Admin screens

Everything lives under the top-level **Shopify** menu (Settings → Shopify redirects there):

- **Overview:** status cards for configuration, sign-in, the Admin API, webhooks, linked customers and failures in the last 24 hours, plus the settings (freshness window, stale behaviour, membership products).
- **Customers:** every WordPress user linked to a Shopify customer, with search, filters for disabled customers and failed syncs, and bulk resync or disconnect. Row actions open the customer in the Shopify admin, edit the WordPress user, resync, or switch to the user when the User Switching plugin is active. Each customer's page shows the synced data, edits tags (written to Shopify with `tagsAdd`/`tagsRemove`, then read back), and lists that customer's recent events and webhooks.
- **Webhooks:** the endpoint, whether the signing secret is set, the registration result, a **Register webhooks with Shopify** button, rejected deliveries, and every delivery received with its outcome. Dead-lettered deliveries can be retried.
- **App events:** a log of every call the plugin made to Shopify (label, endpoint, HTTP status, duration) and what it did: resyncs, reconciliation, purchase-to-tag, sign-ins, linking, webhook outcomes and admin actions, with who triggered each and from where (cron, REST, admin, site). No request or response bodies, tokens or secrets are recorded. Kept 30 days, capped at 20,000 rows.
- **Access:** the **Levels** tab holds named access levels (customer-tag conditions that rules point at), with a health check and a count of matching customers. The **Content rules** tab holds the site default, how term rules combine, and the controls for each post type and taxonomy. The **Features** tab lists the registered [features](docs/features.md) and maps who gets each one (levels, tags or custom tests), with a preview for a tag set or user. The **Test** tab takes an item and a viewer (a guest, a level, a set of tags or a real user) and shows every rule layer, the effective rule and its source, what that viewer gets, which blocks they see, and every feature that viewer holds with the route that grants it. See [Access rules](#access-rules).
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

1. The **Shopify sign-in** block (`wp2-shopify/auth-action`) links to the plugin's login handler. The inserter also offers it as **Shopify Log In**, **Shopify Log Out** and **Connect Shopify Account**. Left empty, "After log in, go to" returns the visitor to the page they were on.
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

Gate settings (the sidebar only shows the ones that apply):

- Who gets in: required tags, matching any or all; or **an access level** picked from the levels under Shopify → Access (visitors who hold it, or a level that includes it); or this item's rule.
- What non-members see:
  - a message with a call to action and an optional sign-in link;
  - the teaser blocks;
  - a redirect.

Rules fail closed. If the tag data is older than the freshness window (Shopify → Overview, default 7 days), it is either still honored or treated as no access, as configured. Shopify → Debug has a dry run that shows the decision for any user.

A gate can also follow **This item's rule** instead of its own tags (below). A gate can only narrow an item's rule, never widen it. The inserter offers both as variations: **Membership Gate: access level** and **Membership Gate: this item's rule**.

The panel locked visitors see, for a gate and for a locked item's wall, is the `wp2-shopify/locked-panel` Blockstudio component. Restyle it through `.wp2-shopify-gate__panel`, or replace its template from a theme with a [Blockstudio override](https://blockstudio.dev/docs/blocks/overrides).

### Access rules

Whole items can be gated without a block, by rules on four layers. The strongest layer that has a rule wins:

1. **The item** (the Access box in the editor, or quick and bulk edit on the post list): inherit, Public, these levels instead, or the inherited rule plus these levels. Optional dates: locked until (embargo) and free until.
2. **Its terms** (each term's edit screen): *Requires* one of some levels, or *free to everyone until* a date. A free-until rule must have an end date and beats every Requires term until then. In a hierarchical taxonomy a child term with no rule takes its nearest ancestor's.
3. **Its post type** (Access → Content rules): the levels every item needs, the teaser locked visitors see, and an optional number of days after publishing when items open.
4. **The site default** (Access → Content rules).

When several terms require different levels, the site combines them **most restrictively** (every term must pass, after level inclusion is counted), **least restrictively**, or **by priority**. Dates are checked on every request, so an embargo ends or a free window closes with no edit.

A locked item shows its teaser and a wall everywhere it renders: the page, query loops, REST, feeds, oEmbed, excerpts and WPGraphQL. The teaser ends at a **Paywall break** block if the item has one, otherwise follows the post type's setting. Locked items carry `isAccessibleForFree: false` markup and get teaser-only descriptions in Yoast SEO and Rank Math. Search crawlers see what a locked visitor sees. Files linked from a gated item, such as PDFs, keep public URLs.

- **Search** matches locked items, and items holding a membership gate, on their public text only (title, excerpt and teaser), for every visitor. A locked phrase never finds its item, and result counts stay exact. The public text is indexed in post meta and rebuilt in the background after a rule change; until then each result is checked again in PHP.
- **Comments** on a locked item are shown only to visitors who can read it, by default: others see no comments, no count and no form, in templates, REST and comment feeds. A post type can show them to everyone instead.
- **Search engines:** locked items stay indexed and in sitemaps by default. A post type can keep them out: they then get `noindex` and leave the WordPress, Yoast and Rank Math sitemaps together.
- **Editors** (anyone with `wp2_bypass_gates`, by default anyone who can edit others' posts) read a gated item in full on its own page only, never in lists, feeds, search, REST or GraphQL. The toolbar's **Access** menu switches that page to **View as a locked visitor** or **View as** any level for one request, with nothing stored.
- **Newsletters:** tools that build emails from the feed, REST or excerpts get the teaser. A tool that reads `post_content` directly bypasses WordPress's filters and is not covered: The Newsletter Plugin's "full post" layout is one, so use its excerpt layouts for gated posts.

### Block controls

Every block has an **Access** panel in its sidebar. A block can only narrow what the item allows:

- **Everyone** (the default): no condition of its own.
- **Readers:** visitors who can read the item.
- **Readers who hold one of these levels:** for a Gold box inside a Members story.
- **Locked visitors only:** for upgrade prompts. Put it above the paywall break, or locked visitors never reach it; the editor warns when it is below.
- **Visitors without these levels:** for "Upgrade to Gold" shown to everyone below Gold, readers included.

The decision is made on the server, so a hidden block is never sent. In a query loop each block is decided for the loop's post. Outside an item (a template part or a widget), "readers" means signed-in visitors. Blocks for readers never feed search, excerpts or SEO descriptions; the other two count as public text.

To show access on cards and pages:

- **Post classes:** gated items get `wp2-gated`, `requires-{level}` for each level, and `is-locked` or `is-unlocked` for the current visitor, in themes and in the Query Loop.
- **Access Badge** block (`wp2-shopify/access-badge`): the level an item needs, in the level's badge colour and always with its label, with a lock for visitors it locks out. In a query loop it describes each post. It reads the item's access rule or, for an item without one, the first Membership Gate in its content that is set to an access level (custom-tag gates have no level to name). Set **Label for free items** (for example `Free`) to mark items anyone can read too, so a list shows free and paid stories side by side; leave it empty to show nothing on them. Items gated inline also get the `wp2-gated`, `requires-{level}` and `is-locked`/`is-unlocked` post classes, and the `wp2-shopify/access` binding names their gate's level instead of calling them free.
- **Block bindings:** bind a paragraph, heading or button to the `wp2-shopify/access` source with a `key` of `level`, `levels`, `state` (Free to read, Locked, Included with your membership), `message`, `cta_label` or `cta_url`.

From the command line, `wp wp2-shopify access explain <post> --as=<guest|signed-in|level|tags|user> [--tags=a,b] [--user=<id>] [--format=json]` prints the same trace as the Test tab, and `wp wp2-shopify access recompute` rebuilds every item's cached rule and search text.

Each item's rule layers are cached in `_wp2_access_effective`, stamped with a rules version. Editing a term rule, a term's parent, or the content rules bumps the version; editing an item or its terms clears that item.

### Features

Features are site behaviours a customer unlocks through what Shopify already granted, such as ad-free reading or downloads. Code registers them with the `wp2_shopify_features` filter, and Shopify → Access → Features maps each one to levels, add-on tags or custom tests. Consumers check them with `wp2_shopify_has_feature( $slug )`, the block **Access → Feature** setting (including "does not hold it" for upgrade prompts), the `wp2-feature-<slug>` body class, `GET /features`, or the `[wp2_feature]` shortcode. Grants come only from the mirror, so they are revoked the same way gates close. See [docs/features.md](docs/features.md).

### Plan cards and buy-now

Add **Shopify Plan Card** (`wp2-shopify/plan-card`) and pick a product: the sidebar searches your Shopify catalog by title. It shows the product's live Shopify price, cached for five minutes, and a button that goes straight to Shopify checkout. Put plan cards on the join page and in a gate's fallback.

- **Buy-now is a plain form.** It posts to `admin-post.php?action=wp2_shopify_buy_now`, so it works without JavaScript. The server creates a one-line cart, attaches the visitor's identity if they're signed in with Shopify, and redirects to a fresh checkout URL. If Shopify refuses the customer token, it retries as a guest checkout rather than block the purchase.
- **Cache-safe.** The card has no personal data and no nonce. Buy-now is rate-limited to 10 attempts per IP per minute.
- **Independent of Shopify's plugin.** It works the same whether that plugin is installed or not, and never touches its cart.

It needs `WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN` (recommended) or `WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN`, and the product must be published to the Headless channel that issued the token. Behind a proxy or CDN, the buyer IP the plugin sends is the proxy's unless the `wp2_shopify_buyer_ip` filter returns the visitor's from a header your proxy sets.

### Account blocks

- **Refresh Membership** (`wp2-shopify/membership-refresh`): a button for signed-in, linked customers that re-syncs their membership from Shopify through `POST /account/sync` and reloads the page, so gates open as soon as a new tag lands. Guests and unlinked accounts see nothing. Put it in a paywall's teaser or the header.
- **Shopify Account Status** (`wp2-shopify/account-status`): tells the visitor whether they are signed in, linked to a Shopify customer, when their membership last synced, and whether Shopify sign-in is available. "Show the raw status response" adds the `GET /auth/status` payload, nonces redacted, for troubleshooting.
- **Membership Features** (`wp2-shopify/member-features`): the visitor's granted features and where each comes from ("Download access, from Add-on purchase"), for the account page. A menu mode shows only the features registered as primary, at most three, for a header.
- **Shopify Health** (`wp2-shopify/health`): the at-a-glance version of Shopify → Debug (Blockstudio, the plugin's blocks, permalinks, the callback page, sign-in, which constants are set but never their values, Shopify's own plugin). Administrators only; everyone else sees a one-line note. Add rows with the `wp2_shopify_health_checks` filter.

All four are dynamic islands (see [Caching](#caching)), so they never put personal data or nonces in a cached page.

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

Gated pages are decided on the server, and send `DONOTCACHEPAGE` when they show members-only content. Singular items gated by access rules also send `X-WP2-Access: open|locked|granted|bypass`, and granted, bypass and preview responses are `Cache-Control: private`. The `wp2_shopify_access_changed` action passes the IDs of items whose rule changed (an empty list means any item may have), for purging.

Personal pieces, such as the sign-in control, are [Blockstudio dynamic islands](https://blockstudio.dev/docs/blocks/islands). The page carries a neutral placeholder that any page cache can store (the guest "Log in" link, so sign-in works without JavaScript). After load, Blockstudio renders every island on the page for the visitor in one same-origin request to `POST /wp-json/blockstudio/v1/island/render` and swaps the results in. That response is never cached, so it can carry the visitor's own nonces; Blockstudio only restores the signed-in user for same-origin requests, so another site cannot read it.

`admin-ajax.php?action=wp2_shopify_status` still returns the visitor's display-safe state and fresh nonces for scripts that are not blocks, such as the cart bridge.

Cache rules should bypass or vary on these cookies:

- `wordpress_logged_in_*`
- `wp2_shopify_oauth_state`
- `wp2_shopify_cart` (later phases)
- `wp2_shopify_fset`, only when a [feature](docs/features.md#caching) declares the `vary` caching option: an opaque hash of the visitor's granted set

## Shopify's WordPress plugin

Shopify publishes its own plugin for the Sell on WordPress channel. It is an anonymous storefront: product, collection and cart-toggle blocks, `/products/{handle}`, `/collections/{handle}` and `/cart`, all built on Storefront Web Components. It has no sign-in, webhooks or gating, so it and WP2 do different jobs on the same store.

WP2 works with or without it:

- **Detection.** Overview and Debug show one of four states: not installed, active but not connected, connected to the same store as `WP2_SHOPIFY_STORE_DOMAIN`, or connected to another store. The last one is an error, because purchases there can't unlock content here. Other code can read the state with `WP2_Shopify\Integrations\OfficialPlugin::state()` or change it with the `wp2_shopify_official_state` filter.
- **Credentials stay separate.** WP2 reads only the official plugin's store domain, to compare it. It never reads its Storefront token.
- **Routes.** When active, the official plugin always owns `/cart` and, by default, `/products/*`. WP2 claims neither. Debug shows which ones it currently owns.
- **Gating.** Official product and collection blocks can go inside a membership gate. The gate renders on the server, so non-members never receive the block's markup.
- **Signed-in checkout from its cart.** When it serves the same store and a Storefront token is set, WP2 adds a small script to front-end pages. If a linked customer is signed in and the official cart exists, the script sends the cart ID to `POST /cart/identity`, and WP2 attaches the customer on the server with `cartBuyerIdentityUpdate`. Checkout then opens signed in, so purchases unlock content. The customer token never reaches the browser. Guests with no cart make no request.
- **Keyboard access to its cart toggle.** Its 1.0.0 cart toggle is a link with no `href`, which a keyboard can't reach. WP2 gives it a button role and a tab stop, and opens the cart on Enter or Space (`Integrations\OfficialCartToggle`).
- **Shared devices.** After sign-out, the script drops a cart it signed in, so the next person can't check out as the previous customer. Turn on **Clear the cart on sign-out** to drop every cart a signed-in visitor used.
- **Limits.** The bridge reads `localStorage['__shopify:cartId']`, which the official cart component uses but doesn't document. If Shopify changes it, the bridge stops attaching and the cart keeps working as a guest cart. Debug shows the last attach and the last failure. A visitor who adds to the cart and reaches checkout within a second or two may get there before the attach finishes.

See [docs/official-plugin.md](docs/official-plugin.md) for the decision record.

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
| `POST /cart/identity` | Attaches the signed-in, linked customer to the official plugin's cart, `{cartId}` → `{attached}` (rate-limited; only when that plugin serves the same store) |
| `POST /webhooks/events` | Shopify webhooks (HMAC-verified) |
| `GET /features` | The caller's own granted feature slugs, for features that declare the REST surface; guests get Public ones only. `/wp/v2/users/me` carries the same list as `wp2_features` |
| `GET /editor/products?search=` | Plan Card product picker: `[{handle, title}]` from the Storefront API. Editors only (same-origin, `edit_posts`), because Blockstudio's picker calls it without a nonce |

Non-JavaScript forms post to `admin-post.php` handlers (`wp2_shopify_login`, `_logout`, `_connect`, `_link_confirm`, `_sync`, `_disconnect`), which redirect back to a page.

## Building on Blockstudio

The plugin is built on [Blockstudio](https://blockstudio.dev) (7.7.7, bundled through Composer). **Before writing any code for a block, check whether Blockstudio already does it.** Hand-made versions of features Blockstudio ships have to be maintained, tested and secured by us, and they drift from how the rest of the blocks behave. The Blockstudio docs are in `vendor/blockstudio/blockstudio/docs` after `composer install`, and at [blockstudio.dev/docs](https://blockstudio.dev/docs).

What the plugin uses, and where:

| Need | Blockstudio feature | Used in |
|---|---|---|
| Show a sidebar field only when another field has a value | [Conditional logic](https://blockstudio.dev/docs/blocks/attributes/conditional-logic) (`conditions`) | Membership Gate, Shopify Sign-in |
| Fill a select from site data or an API | [Populate options](https://blockstudio.dev/docs/blocks/attributes/populating-options): `function` for site data, `fetch` for a search endpoint | Gate's level picker (`wp2_shopify_level_options()`), Plan Card's product picker |
| Personal output on a cacheable page | [Dynamic islands](https://blockstudio.dev/docs/blocks/islands) | Shopify Sign-in, Refresh Membership, Shopify Account Status, Shopify Health, Membership Features |
| Shared markup the plugin renders, that themes can restyle or replace | [Components](https://blockstudio.dev/docs/blocks/components) and [overrides](https://blockstudio.dev/docs/blocks/overrides) | `locked-panel`, rendered by `Gate::fallback_panel()` |
| Several inserter entries for one block | [Variations](https://blockstudio.dev/docs/blocks/variations) | Shopify Sign-in, Membership Gate |
| URL fields with a page picker | [`link` field](https://blockstudio.dev/docs/blocks/attributes/field-types) | Gate CTA and redirect, sign-in redirects |
| Editor preview that reads post meta | [`refreshOn: ["save"]`](https://blockstudio.dev/docs/blocks/post-meta) | Access Badge, Membership Gate |
| Inserter hover previews | [`example`](https://blockstudio.dev/docs/blocks/preview) and `$isPreview` | Every inserter block |
| Read a stored block's values outside rendering | `bs_block_values()` | `Gate::values()` |
| Styles and scripts per block | `style.scss`, `script.view.js` beside `block.json` | All blocks |

Rules that follow from it:

- Use the field type that fits (select, link, toggle, repeater) and read the value as Blockstudio returns it. Selects return `{value, label}` and links `{url, ...}`: read them with `Gate::option()` and `Gate::url()`.
- Changing a field's type changes how it is stored. Add the field to `Blocks\Upgrade::FIELDS` so content saved before keeps rendering.
- In a block template, `return;` means "this block renders nothing" to Blockstudio, which then also skips the block's stylesheet and scripts, even if markup was printed before the `return`. Use early returns only for branches that print nothing (or editor-only branches); branches that print markup end with `if`/`else`, as in Plan Card and Shopify Sign-in.
- Blockstudio's `populate` function type only accepts global functions. Put a thin wrapper in `src/functions.php` and keep the logic in a class.
- Register blocks with `Blockstudio\Build::init()` (see `Blocks\Registration`), never the `blockstudio/path` filter, which would hide the theme's blocks.
- Where Blockstudio doesn't fit, write it ourselves and say why in a comment. Current cases:
  - **The per-block Access panel** (`assets/block-access.js`), rather than an [extension](https://blockstudio.dev/docs/extensions). An extension can't warn when a block sits after the paywall break, can't skip the gate's own areas, and would move the saved setting out of `wp2Access`.
  - **REST routes and `admin-post.php` forms**, rather than [RPC](https://blockstudio.dev/docs/blocks/block-api/rpc) or `db.php`. Blockstudio's client prints its nonce and token into the page HTML, which a page cache would serve stale.
  - **WordPress cron and our own tables**, rather than `cron.php` or `db.php`, because they belong to the plugin's lifecycle, not to a block.
- Leave Blockstudio's static prerendering off on gated sites, and never set `serveLoggedIn`.

## Hooks

- Actions:
  - `wp2_shopify_signed_in`, `wp2_shopify_linked`, `wp2_shopify_disconnected` and `wp2_shopify_before_logout`
  - `wp2_shopify_log`, which receives redacted log events
  - `wp2_shopify_access_changed` (`int[] $post_ids`), `wp2_shopify_access_levels_changed` and `wp2_shopify_access_locked_changed` (`int $post_id, bool $locked`)
  - `wp2_shopify_features_changed` (`int $user_id, string[] $added, string[] $removed`) and `wp2_shopify_features_mapping_changed` (`string[] $slugs`); see [docs/features.md](docs/features.md#hooks)
- Filters:
  - `wp2_shopify_auth_callback_slug`, `wp2_shopify_default_redirect` and `wp2_shopify_customer_admin_redirect`
  - `wp2_shopify_membership_map` and `wp2_shopify_setting_{key}`
  - `wp2_shopify_official_state`, which overrides the detected state of Shopify's own plugin
  - `wp2_shopify_reassign_user`, which picks who receives content when a Shopify redaction deletes a shadow user
  - `wp2_shopify_access_levels` and `wp2_shopify_access_rules`, which set levels and content rules from code (the admin screens then show them read-only)
  - `wp2_shopify_gated_post_types`, `wp2_shopify_access_context`, `wp2_shopify_effective_rule`, `wp2_shopify_teaser_settings` and `wp2_shopify_paywall_schema`
  - `wp2_shopify_pre_sync_now` (`null|string $result, int $user_id`), which answers a "sync now" without asking Shopify: return `synced`, `sync_wait` or `sync_failed`. For customers Shopify doesn't know, such as a test site's simulated accounts
  - `wp2_shopify_features`, `wp2_shopify_feature_tests` and `wp2_shopify_feature_decision`, which register features and custom tests and can alter one decision (see [docs/features.md](docs/features.md))
  - `wp2_shopify_buyer_ip` (`string $ip`), the visitor IP sent to Shopify with buy-now and cart calls when the private Storefront token is set. Return the real IP from a header your proxy or CDN sets; forwarded headers are not read by default because visitors can forge them
  - `wp2_shopify_health_checks`, which adds rows (`label`, `state` of `ok|warn|fail|info`, `detail`) to the Shopify Health block

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

The plugin registers a personal data exporter and an eraser for the Shopify fields it mirrors. With Shopify's plugin active, the cart bridge stores two flags in the visitor's `localStorage` (`wp2-shopify:attachedCart` and `wp2-shopify:signedInCart`) and keeps no cart IDs on the server. Shopify's `customers/data_request` creates a WordPress export request. `customers/redact` deletes or unlinks the account.
