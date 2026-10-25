# WP2 Shopify

Use your Shopify store as the membership system for a WordPress site. Customers sign in to WordPress with their Shopify customer account, and what they bought in Shopify decides what they can read and use on WordPress. Built on [Blockstudio 7](https://blockstudio.dev).

Shopify stays the single source of truth. A purchase adds a tag to the customer in Shopify (through Shopify Flow or a subscription or membership app), WordPress mirrors those tags, and every access decision is made on the server from that mirror. WordPress never grants access that Shopify doesn't know about.

- **Sign in with Shopify.** OAuth with PKCE against the Customer Account API, shadow users for new customers, and optional linking of existing WordPress accounts.
- **Memberships from Shopify tags.** Shopify Flow or your subscription app tags customers and keeps that state; renewals, cancellations and fixed terms stay in Shopify. See [docs/shopify-flow.md](docs/shopify-flow.md).
- **Gate content.** A Membership Gate block, access rules per item, term, post type and site, per-block visibility, a paywall break, and teasers that stay out of feeds, REST, search and SEO descriptions.
- **Features.** Site behaviours such as ad-free reading or downloads, unlocked by the same tags.
- **Sell memberships.** Plan cards with live Shopify prices and a no-JavaScript buy-now button that opens a Shopify checkout.
- **Cache-friendly.** Personal pieces are Blockstudio dynamic islands, so pages stay cacheable.
- **Works beside Shopify's own WordPress plugin**, and signs its cart in so checkout knows the customer.

> **Status: 2.0.0-alpha.1.** Sign-in, linking, webhooks, membership tagging, gates, access rules, features, plan cards and the bridge to Shopify's own cart are in place. A server-rendered account card is planned. Catalog blocks (product grids, collections, a cart) are deliberately left to Shopify's plugin; see [docs/official-plugin.md](docs/official-plugin.md). Expect breaking changes before 2.0.0.

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

### Deploying from a Git repository

If your host syncs the plugin folder from Git, have it run `composer build-prod` in the plugin folder after each sync. That is the only build step; Blockstudio compiles block styles on first load, so `blocks/*/_dist/` must be writable.

Run these WP-CLI steps separately, once per site:

```sh
wp plugin activate <plugin-folder>
wp rewrite structure '/%postname%/'
wp rewrite flush
```

The plugin runs its own Blockstudio instance from `blocks/`. It does not use the `blockstudio/path` filter, because that filter replaces a theme's Blockstudio block folder. So it coexists with Blockstudio themes and works in any other theme.

## Configure

These eight values are the whole configuration surface. Each can be entered in **Shopify → Settings** or defined as a constant of the same name in `wp-config.php`:

- **Settings** is the easy start. Values are saved in the options table (`wp2_shopify_config`). Secrets are encrypted before they are stored (libsodium, keyed from `WP2_SHOPIFY_ENCRYPTION_KEY` or the WordPress salts), are write-only (the field is always blank; leave it blank to keep the saved value) and are never shown again.
- **A constant always wins.** Its field in Settings is locked and says which constant sets it. This is the more secure choice: the value never touches the database, so a database dump or a compromised admin account can't reach it. Move a value to `wp-config.php` whenever you are ready; the saved copy is then ignored.

Changing the WordPress salts or `WP2_SHOPIFY_ENCRYPTION_KEY` makes secrets saved in Settings unreadable. Settings, Debug and the Health block then say so, and the secret has to be entered again. `WP2_SHOPIFY_ENCRYPTION_KEY` itself is constant-only. Shopify → Debug shows where each value comes from, never the value.

| Constant | Required | Purpose |
|---|---|---|
| `WP2_SHOPIFY_STORE_DOMAIN` | yes | `your-store.myshopify.com` |
| `WP2_SHOPIFY_CLIENT_ID` | yes | Customer Account API client ID. Powers sign-in. |
| `WP2_SHOPIFY_CLIENT_SECRET` | confidential clients | Customer Account API client secret. Leave unset for a public client. |
| `WP2_SHOPIFY_ADMIN_CLIENT_ID` | yes | Client ID of the Dev Dashboard app installed on the store. Powers resync, tag editing and webhook registration. |
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

- **Overview:** a setup checklist (connect the store, **Test connection**, permalinks, **Create the page** for the sign-in callback, register webhooks, define levels, tag members with Flow), folded away once everything is done, then status cards for configuration, sign-in, the Admin API, webhook delivery health, linked customers and failures in the last 24 hours. The levels step compares each level's tags with the tags Shopify's customers actually have and lists any no customer has, which is usually a typo.
- **Settings:** the Shopify connection (every value above; fields set in `wp-config.php` are locked), the membership settings (shared devices) and webhook alerts (the silence warning and the alert email).
- **Customers:** every WordPress user linked to a Shopify customer, with their levels, search (WordPress name and email, the Shopify email and name, or a customer ID), filters for level, disabled customers and failed syncs, **Export CSV** of the filtered list, and bulk disconnect. The **Activation** panel compares, per level, the customers Shopify tags for it with those who have signed in here (Shopify's `customersCount`, cached for an hour). **Resync all customers** starts a paced background resync of everyone (see [Resyncing customers](#resyncing-customers)). Row actions open the customer in the Shopify admin, edit the WordPress user, resync, or switch to the user when the User Switching plugin is active. Each customer's page shows the synced data, edits tags (written to Shopify with `tagsAdd`/`tagsRemove`, then read back), and lists that customer's recent events and webhooks.
- **Webhooks:** the **Delivery health** panel (see [Delivery health](#delivery-health)) with a **Check now** button, the endpoint, whether the signing secret is set, the registration result, a **Register webhooks with Shopify** button, rejected deliveries, and every delivery received with its outcome. Dead-lettered deliveries can be retried.
- **App events:** a log of every call the plugin made to Shopify (label, endpoint, HTTP status, duration) and what it did: resyncs, sign-ins, linking, webhook outcomes and admin actions, with who triggered each and from where (cron, REST, admin, site). No request or response bodies, tokens or secrets are recorded. Kept 30 days, capped at 20,000 rows.
- **Reports:** levels joined and left, purchases that unlocked access, plan card checkouts and sign-ins for the last 30 days; joined and left by week for 12 weeks; and per level, members now and joined and left over 30 days and 12 months. The plugin counts these by day from when this version is installed and keeps 400 days; no user IDs are kept with the counts.
- **Access:** the **Levels** tab holds named access levels (customer-tag conditions that rules point at), with a health check and a count of matching customers. The **Content rules** tab holds the site default, how term rules combine, and the controls for each post type and taxonomy. The **Features** tab lists the registered [features](docs/features.md) and maps who gets each one (levels, tags or custom tests), with a preview for a tag set or user. The **Test** tab takes an item and a viewer (a guest, a level, a set of tags or a real user) and shows every rule layer, the effective rule and its source, what that viewer gets, which blocks they see, and every feature that viewer holds with the route that grants it. See [Access rules](#access-rules).
- **Debug:** the values above and where each comes from (`wp-config.php`, Settings, or unreadable), platform checks (PHP, WordPress, Blockstudio, plugin blocks, pretty permalinks), the Shopify connection and granted scopes, buttons to refresh discovery, request a fresh Admin token, and the dry-run entitlement check.

Other code can add pages under the menu: `add_submenu_page( WP2_SHOPIFY_ADMIN_MENU, ... )` on `admin_menu` after priority 10. Two filters help test setups:

- `wp2_shopify_customer_badges` (`array $badges, int $user_id`): labels shown next to a customer's name.
- `wp2_shopify_customer_is_fixture` (`bool $is_fixture, int $user_id`): return true for seeded users whose customer does not exist in Shopify. Resync keeps their tags and only mark them fresh, tag edits stay in WordPress, and no Shopify admin link is shown.

Signed-in customers can resync themselves from the front end with `POST /wp-json/wp2-shopify/v1/account/sync` (REST nonce, once a minute), and read their own membership (the Member Account block's data: levels, dates, features, account links) from `GET /wp-json/wp2-shopify/v1/account`.

### Shopify app settings

Shopify → Debug prints the exact values for your site:

- **Callback URI:** `https://your-site/auth-callback/`. Change the slug with the `wp2_shopify_auth_callback_slug` filter.
- **JavaScript origin:** `https://your-site`
- **Logout URI:** `https://your-site/`

### Admin app

New custom apps are made in Shopify's Dev Dashboard; since 2026-01-01 the Shopify admin no longer creates them. Create one in the same organization as the store, with:

- **Admin API scopes:** `read_customers` and `write_customers`.
- **Redirect URL:** your site's home URL. The client credentials grant doesn't use it, but the form requires one.

Install it on the store, then enter its client ID and secret in Shopify → Settings (or define `WP2_SHOPIFY_ADMIN_CLIENT_ID` and `WP2_SHOPIFY_ADMIN_CLIENT_SECRET`). Shopify → Debug shows the scopes Shopify granted.

### Webhooks

Click **Register webhooks with Shopify** on Shopify → Webhooks. The plugin subscribes its endpoint, `https://your-site/wp-json/wp2-shopify/v1/webhooks/events`, to every topic below through the app, so deliveries are signed with the app's client secret. Running it again only adds what is missing. Don't also create webhooks in Settings → Notifications: those are signed with a different key and would fail verification.

The topics:

- `customers/create`, `customers/update`, `customers/delete`, `customers/enable`, `customers/disable` and `customers/merge`
- `customer.tags_added` and `customer.tags_removed`
- `customers_email_marketing_consent/update`, `customers_marketing_consent/update` and `customer_purchasing_summary/create`
- `app/uninstalled`
- the compliance topics `customers/data_request`, `customers/redact` and `shop/redact`. Shopify doesn't allow these through the API; they come only to apps that declare them in their app configuration.

Since API 2025-01, `customers/update` no longer carries tags, so the two `customer.tags_*` topics are needed for tag changes to arrive quickly.

Each delivery is checked and stored, then processed:

- The HMAC is checked first.
- Each delivery is deduped by its webhook ID.
- Only the fields the plugin uses are stored.
- Processing runs in the background (Action Scheduler if present, otherwise WP-Cron).
- Failures retry, then are dead-lettered. Shopify → Webhooks lists every delivery and its outcome.

A customer's record is refreshed by these webhooks, at sign-in, by the member's own **Refresh Membership**, or by a staff resync on Shopify → Customers. Until then the last tags Shopify sent stay in force.

### Delivery health

Because access changes only when a webhook lands, the plugin watches the chain:

- **Once a day** it lists the app's webhook subscriptions in Shopify (one Admin API call, no customer data) and re-creates any that are missing. Shopify removes a subscription after its deliveries fail for several hours, so a re-created one is reported.
- **On every admin screen** it checks, without calling Shopify: deliveries rejected in the last day (wrong signing secret), deliveries waiting more than 10 minutes (WP-Cron or Action Scheduler not running), dead-lettered deliveries, WP-Cron running late, and no delivery at all for the number of days set in Settings (7 by default, 0 to turn off) while customers are linked.

Problems appear in an admin notice (failures on the Dashboard and Plugins screens too), in Tools → Site Health, in the Shopify Health block and on Shopify → Webhooks. With **Alerts** ticked in Settings, the site admin gets an email: at once when something new breaks, then at most daily while it lasts.

### Resyncing customers

There is no scheduled bulk sync. To re-read everyone (after webhooks were down, say), staff press **Resync all customers** on Shopify → Customers:

- It runs in the background, 50 customers per Admin API call, in user ID order, and can be paused, resumed or cancelled. Progress and an estimate show on the page.
- It reads Shopify's query cost report after every call and only spends the spare half of the store's API budget, so sign-ins, member refreshes and webhook syncs are never starved. When Shopify throttles anyway it waits as long as the cost report says.
- If Shopify is unreachable or erroring, it retries with a growing wait (30 seconds up to 15 minutes) and stops after 6 failures in a row; a missing or revoked app stops it at once. **Resume** carries on from where it stopped.
- WP-Cron or Action Scheduler carries it. While the Customers page is open it also moves it along when WP-Cron is late.

From the command line: `wp wp2-shopify resync user <id>...`, `wp wp2-shopify resync all [--foreground]`, and `wp wp2-shopify resync status|pause|resume|cancel`.

## How sign-in works

1. The **Shopify sign-in** block (`wp2-shopify/auth-action`) links to the plugin's login handler. The inserter also offers it as **Shopify Log In**, **Shopify Log Out** and **Connect Shopify Account**. Left empty, "After log in, go to" returns the visitor to the page they were on.
2. The plugin redirects to Shopify with PKCE. The verifier stays on the server.
3. Shopify returns to the callback. The plugin exchanges the code, checks the ID token claims (`iss`, `aud`, `exp` and `nonce`; the signature is not checked, as OIDC allows for tokens received directly from the token endpoint), and signs the visitor in.
4. A new customer gets a **shadow user** with the `shopify_customer` role:
   - no wp-admin, admin bar, password reset or author archive;
   - hidden from the user list;
   - mail goes to the Shopify email.
   If Shopify's verified email belongs to an existing, unlinked WordPress account that isn't an administrator, the visitor is asked first: **Sign in to my account** (they sign in to WordPress, and the accounts are linked) or **No thanks, use a separate account**. Nothing is linked on email alone. Return false from `wp2_shopify_offer_existing_account` to skip the question.
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

### Sign-in links

- The WordPress login page shows a **Log in with Shopify** button above the form (Shopify → Settings → Sign-in links). Another setting points WordPress's own login links at Shopify sign-in; wp-admin and reauthentication keep the WordPress form.
- Appearance → Menus has **Log in with Shopify** and **Log out** items, which show only to guests and signed-in visitors respectively.
- The `[wp2_shopify_login]` shortcode renders the sign-in block for classic themes and page builders. Attributes: `show` (`auto`, `login`, `logout`), `login_label`, `logout_label` and `redirect`.

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

Rules fail closed for guests, unlinked accounts, customers disabled in Shopify and customers never synced. A customer disabled in Shopify is also kept out of content that only needs a signed-in visitor; a WordPress account that was never linked is not affected. Synced tags never expire on their own: they apply until Shopify changes them. Shopify → Debug has a dry run that shows the decision for any user.

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
- **Subscriptions.** A product with selling plans (Shopify Subscriptions, Recharge, Appstle and others) shows the plan's price and name, such as "Billed yearly". Put a selling plan ID in the block to sell on that plan; the editor lists the variant's plans and IDs. A subscription-only product sells on its first plan.
- **Prices by country.** Return an ISO country code from the `wp2_shopify_context_country` filter (for example from your CDN's country header) and plan cards and checkout use that country's price and currency through Shopify Markets. Pages with plan cards then differ by country, so the page cache must vary on that header.
- **Independent of Shopify's plugin.** It works the same whether that plugin is installed or not, and never touches its cart.

After checkout, the plugin finishes the job for a signed-in customer: for 30 minutes it re-reads their tags at most once a minute while Shopify Flow tags them, shows a "finishing up" banner, then "Your membership is ready" once. Link Shopify's order status page and confirmation email to the return link shown under Shopify → Settings (`/?wp2s_return=1`): it takes the customer back to the page they bought from, or asks a guest to log in with the email from their order. Turn the banner off with the `wp2_shopify_checkout_banner` filter.

It needs `WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN` (recommended) or `WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN`, and the product must be published to the Headless channel that issued the token. Behind a proxy or CDN, the buyer IP the plugin sends is the proxy's unless the `wp2_shopify_buyer_ip` filter returns the visitor's from a header your proxy sets.

### Account blocks

- **Member Account** (`wp2-shopify/member-account`): the "my membership" card. It shows the member's levels and when each started, the renewal date, past levels, their features, when it last synced, and links to their Shopify account and subscription management (Shopify → Settings → Subscription management). Guests get a sign-in button, unlinked accounts a connect button, and a lapsed member a join link. Start and renewal dates come from the `membership.member_since` and `membership.renews_on` customer metafields when Flow or an app sets them, otherwise from the level history the plugin keeps.
- **Refresh Membership** (`wp2-shopify/membership-refresh`): a button for signed-in, linked customers that re-syncs their membership from Shopify through `POST /account/sync` and reloads the page, so gates open as soon as a new tag lands. Guests and unlinked accounts see nothing. Put it in a paywall's teaser or the header.
- **Shopify Account Status** (`wp2-shopify/account-status`): tells the visitor whether they are signed in, linked to a Shopify customer, when their membership last synced, and whether Shopify sign-in is available. "Show the raw status response" adds the `GET /auth/status` payload, nonces redacted, for troubleshooting.
- **Membership Features** (`wp2-shopify/member-features`): the visitor's granted features and where each comes from ("Download access, from Add-on purchase"), for the account page. A menu mode shows only the features registered as primary, at most three, for a header.
- **Shopify Health** (`wp2-shopify/health`): the at-a-glance version of Shopify → Debug (Blockstudio, the plugin's blocks, permalinks, the callback page, sign-in, which configuration values are set but never the values themselves, Shopify's own plugin). Administrators only; everyone else sees a one-line note. Add rows with the `wp2_shopify_health_checks` filter.

All five are dynamic islands (see [Caching](#caching)), so they never put personal data or nonces in a cached page.

### Membership tags

The plugin doesn't tag customers. Shopify Flow, or a subscription or membership app, adds a tag when a membership starts and removes it when it ends; levels under Shopify → Access point at those tags. [docs/shopify-flow.md](docs/shopify-flow.md) has workflows for one-time purchases, fixed terms, subscriptions and complimentary access.

Access arrives when Shopify's tag webhook does, usually within seconds. A customer who is already signed in can pull it sooner with the **Refresh Membership** block.

The locked panel a gate shows reads the visitor's situation: sign in, connect a Shopify account, "finishing up" after checkout, renew a level that lapsed, upgrade from a lower level, or join. Each has its own wording and button, which the `wp2_shopify_locked_panel` filter can change.

**Drip.** The Access box on an item has "Drip: members can read it after N days of membership". A member who passes the item's rule but joined more recently sees when it opens for them instead. A member's start is the `membership.member_since` metafield when Shopify keeps one, otherwise when they first held a level they hold now (as WordPress saw it), otherwise when they linked. Search listings don't apply drip.

**Members' home.** A level can name a members' home page (Access → Levels). A member who logs in with Shopify from the home page or a plain login link lands there; logging in from an article returns them to the article. The `wp2_shopify_sign_in_redirect` filter can change either.

The plugin records when each level started and ended for each member (`level.changed` in the event log, and the `wp2_shopify_levels_changed` action). That history is for display; access always comes from the current tags.

## Caching

Gated pages are decided on the server, and send `DONOTCACHEPAGE` when they show members-only content. Singular items gated by access rules also send `X-WP2-Access: open|locked|granted|bypass`, and granted, bypass and preview responses are `Cache-Control: private`. The `wp2_shopify_access_changed` action passes the IDs of items whose rule changed (an empty list means any item may have), for purging.

Personal pieces, such as the sign-in control, are [Blockstudio dynamic islands](https://blockstudio.dev/docs/blocks/islands). The page carries a neutral placeholder that any page cache can store (the guest "Log in" link, so sign-in works without JavaScript). After load, Blockstudio renders every island on the page for the visitor in one same-origin request to `POST /wp-json/blockstudio/v1/island/render` and swaps the results in. That response is never cached, so it can carry the visitor's own nonces; Blockstudio only restores the signed-in user for same-origin requests, so another site cannot read it.

`admin-ajax.php?action=wp2_shopify_status` still returns the visitor's display-safe state and fresh nonces for scripts that are not blocks, such as the cart bridge.

Cache rules should bypass or vary on these cookies:

- `wordpress_logged_in_*`
- `wp2_shopify_oauth_state`
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
  - `wp2_shopify_levels_changed` (`int $user_id, string[] $added, string[] $removed`), level slugs, after a sync
  - `wp2_shopify_features_changed` (`int $user_id, string[] $added, string[] $removed`) and `wp2_shopify_features_mapping_changed` (`string[] $slugs`); see [docs/features.md](docs/features.md#hooks)
- Filters:
  - `wp2_shopify_auth_callback_slug`, `wp2_shopify_default_redirect` and `wp2_shopify_customer_admin_redirect`
  - `wp2_shopify_setting_{key}`
  - `wp2_shopify_official_state`, which overrides the detected state of Shopify's own plugin
  - `wp2_shopify_reassign_user`, which picks who receives content when a Shopify redaction deletes a shadow user
  - `wp2_shopify_access_levels` and `wp2_shopify_access_rules`, which set levels and content rules from code (the admin screens then show them read-only)
  - `wp2_shopify_gated_post_types`, `wp2_shopify_access_context`, `wp2_shopify_effective_rule`, `wp2_shopify_teaser_settings` and `wp2_shopify_paywall_schema`
  - `wp2_shopify_pre_sync_now` (`null|string $result, int $user_id`), which answers a "sync now" without asking Shopify: return `synced`, `sync_wait` or `sync_failed`. For customers Shopify doesn't know, such as a test site's simulated accounts
  - `wp2_shopify_features`, `wp2_shopify_feature_tests` and `wp2_shopify_feature_decision`, which register features and custom tests and can alter one decision (see [docs/features.md](docs/features.md))
  - `wp2_shopify_sign_in_redirect` (`string $target, int $user_id, string $redirect_to`) and `wp2_shopify_drip_days` (`int $days, WP_Post $post`)
  - `wp2_shopify_context_country` (`string $country`), an ISO code that prices plan cards and checkout in that country's currency
  - `wp2_shopify_buyer_ip` (`string $ip`), the visitor IP sent to Shopify with buy-now and cart calls when the private Storefront token is set. Return the real IP from a header your proxy or CDN sets; forwarded headers are not read by default because visitors can forge them
  - `wp2_shopify_locked_panel` (`array $data, Decision $decision`), the locked panel's message, button and form; `wp2_shopify_checkout_banner` (`bool`); `wp2_shopify_offer_existing_account` (`bool`)
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

## Contributing

Issues and pull requests are welcome. Work branches from `development`, and pull requests go back into it.

```sh
composer install
composer lint   # php -l on every PHP file
composer phpcs  # WordPress VIP coding standards (phpcs.xml.dist)
composer test   # PHPUnit; no WordPress install needed
```

GitHub Actions runs the lint and tests on PHP 8.2, 8.3 and 8.4 for every pull request, and annotates coding-standard findings. The translation template is `languages/wp2-shopify.pot`; regenerate it with `wp i18n make-pot . languages/wp2-shopify.pot --exclude=vendor,tests` when strings change.

- **Check Blockstudio first.** Before writing code for a block, see whether [Blockstudio](https://blockstudio.dev/docs) already does it: conditional fields, populated pickers, islands, components, variations and more. [Building on Blockstudio](#building-on-blockstudio) lists what the plugin uses and the few places it deliberately doesn't.
- **Shopify is the source of truth for access.** A change must never let WordPress grant an entitlement that Shopify doesn't hold. Tools that make access easier to manage from WordPress write to Shopify (for example, customer tags) and read the result back.
- **Fail closed.** When data is missing or unreadable, the visitor gets less access, not more.
- **Never log or return secrets or tokens**, and keep personal data and nonces out of cacheable HTML.

The test theme in [webmultipliers/shopify-for-wp-theme](https://github.com/webmultipliers/shopify-for-wp-theme) seeds a demo news site, test pages and test accounts that exercise every feature, which makes it a good companion for manual testing.

### Reporting a security issue

Please don't open a public issue for a vulnerability. Report it privately from the repository's **Security** tab (**Report a vulnerability**).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). Blockstudio, bundled through Composer, is also GPL-2.0-or-later.
