# RedeemID

Use your Shopify store as the membership system for a WordPress site. Customers sign in to WordPress with their Shopify customer account, the same one they use in your store, and what that account holds in Shopify decides what they can read and use on WordPress. Built on [Blockstudio 7](https://blockstudio.dev).

**[Website](https://www.redeemid.com/) · [Docs](https://www.redeemid.com/docs/) · [Guides](https://www.redeemid.com/guides/) · [Live demo](http://demo.redeemid.com/) · [Download](https://github.com/webmultipliers/redeemid-core/releases/latest) · [Developer API](docs/developer-api.md) · [Shopify Flow recipes](docs/shopify-flow.md) · [Protected files](docs/protected-files.md) · [Abilities and MCP](docs/abilities.md)**

Shopify stays the single source of truth. RedeemID links each Shopify customer account to a WordPress user and mirrors it there: name, email, account status, and the tags Shopify Flow or a subscription or membership app sets. Every access decision is made on the server from that mirror. A customer disabled in Shopify loses members-only access, and WordPress never grants access that Shopify doesn't know about.

- **Sign in with Shopify.** OAuth with PKCE against the Customer Account API, shadow users for new customers, and optional linking of existing WordPress accounts.
- **Memberships from Shopify tags.** Shopify Flow or your subscription app tags customers and keeps that state; renewals, cancellations and fixed terms stay in Shopify. See [docs/shopify-flow.md](docs/shopify-flow.md).
- **Gate content.** A Membership Gate block, access rules per item, term, post type and site, per-block visibility, a paywall break, and teasers that stay out of feeds, REST, search and SEO descriptions.
- **Features.** Site behaviours such as ad-free reading or downloads, unlocked by the same tags.
- **Sell memberships.** Plan cards with live Shopify prices and a no-JavaScript buy-now button that opens a Shopify checkout.
- **Built for the block editor.** Sign-in, gates, the paywall break, account blocks, plan cards, products, collections and the cart are all blocks, and every block gets an **Access** panel. They render on the server and work without JavaScript. See [Blocks for the block editor](https://www.redeemid.com/docs/blocks/).
- **Cache-friendly.** Personal pieces are Blockstudio dynamic islands, so pages stay cacheable.
- **Works beside Shopify's own WordPress plugin**, and signs its cart in so checkout knows the customer.

> **Status: 0.0.7, early development.** Sign-in, linking, webhooks, gates, access rules, a signed-in meter, protected files, features, plan cards, product, collection and cart blocks with storefront pages, the member account card, settings export and import, and the bridge to Shopify's own cart are in place. RedeemID now has its own storefront (product, collection and cart blocks and pages), so a site can run without Shopify's plugin (see [docs/official-plugin.md](docs/official-plugin.md)). Expect breaking changes before 1.0.0.

## Requirements

- PHP 8.2+ with `sodium`
- WordPress 6.7+ (6.9+ for [abilities](docs/abilities.md))
- **Pretty permalinks**, for sign-in only. Shopify sends customers back to one exact callback address that you register in the app, and RedeemID builds it as `https://your-site/auth-callback/`, a rewrite rule that WordPress only honours with pretty permalinks. REST routes and webhooks work either way.
- A Shopify store with a headless or custom app that has Customer Account API access

If PHP, WordPress or `sodium` is missing, activation stops with a notice. It does not fatal. Permalinks aren't checked at activation. On a site that isn't connected yet, activation opens **RedeemID → Setup**, a step-by-step wizard that checks HTTPS and permalinks first; the Overview checklist, Debug and the Health block flag them too.

## Install

Download `redeemid-core.zip` from the [latest release](https://github.com/webmultipliers/redeemid-core/releases/latest) and upload it in Plugins → Add New → Upload Plugin. The zip already includes `vendor/`. It installs to `wp-content/plugins/redeemid-core/`.

To build from source instead:

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

These eight values are the whole configuration surface. Each can be entered in **RedeemID → Settings** or defined as a constant of the same name in `wp-config.php`:

- **Settings** is the easy start. Values are saved in the options table (`redeemid_config`). Secrets are encrypted before they are stored (libsodium, keyed from `REDEEMID_ENCRYPTION_KEY` or the WordPress salts), are write-only (the field is always blank; leave it blank to keep the saved value) and are never shown again.
- **A constant always wins.** Its field in Settings is locked and says which constant sets it. This is the more secure choice: the value never touches the database, so a database dump or a compromised admin account can't reach it. Move a value to `wp-config.php` whenever you are ready; the saved copy is then ignored.

Changing the WordPress salts or `REDEEMID_ENCRYPTION_KEY` makes secrets saved in Settings unreadable. Settings, Debug and the Health block then say so, and the secret has to be entered again. `REDEEMID_ENCRYPTION_KEY` itself is constant-only. RedeemID → Debug shows where each value comes from, never the value.

| Constant | Required | Purpose |
|---|---|---|
| `REDEEMID_STORE_DOMAIN` | yes | `your-store.myshopify.com` |
| `REDEEMID_CLIENT_ID` | yes | Customer Account API client ID. Powers sign-in. |
| `REDEEMID_CLIENT_SECRET` | confidential clients | Customer Account API client secret. Leave unset for a public client. |
| `REDEEMID_ADMIN_CLIENT_ID` | yes | Client ID of the Dev Dashboard app installed on the store. Powers resync, tag editing and webhook registration. |
| `REDEEMID_ADMIN_CLIENT_SECRET` | yes | That app's client secret. The plugin uses it to request 24-hour Admin tokens (client credentials grant) and to verify the app's webhooks. |
| `REDEEMID_PUBLIC_ACCESS_TOKEN` | for plan cards | Storefront API public token from the Headless channel. Powers plan cards and buy-now. Public by design. |
| `REDEEMID_STOREFRONT_PRIVATE_TOKEN` | recommended | Storefront API private token from the same Headless channel. When set it replaces the public token for every Storefront call, and buy-now and cart calls send the buyer's IP (`Shopify-Storefront-Buyer-IP`), as Shopify asks of server-side callers. Without it every visitor looks like your server's one IP, so Shopify may throttle checkout creation. |
| `REDEEMID_ENCRYPTION_KEY` | no | Base64 32-byte key for token encryption. By default a key is derived from the WordPress salts. Set this if you rotate salts. |

## Guides

The site docs at [redeemid.com/docs](https://www.redeemid.com/docs/) cover setup step by step. Three are written for people who don't read code:

- [Editor's guide](https://www.redeemid.com/docs/editors/): who can read a post and why, for writers and editors.
- [How changes reach WordPress](https://www.redeemid.com/docs/sync/): the path from a Shopify change to a page, and what slows it.
- [Works with](https://www.redeemid.com/docs/compatibility/): page caches, SEO, ads, newsletters and analytics alongside RedeemID.

## Admin screens

Everything lives under the top-level **RedeemID** menu:

- **Overview:** a setup checklist (connect the store, **Test connection**, permalinks, **Create the page** for the sign-in callback, register webhooks, define levels, tag members with Flow), folded away once everything is done, then status cards for configuration, sign-in, the Admin API, webhook delivery health, linked customers and failures in the last 24 hours. The levels step compares each level's tags with the tags Shopify's customers actually have and lists any no customer has, which is usually a typo.
- **Setup:** the same checklist as a wizard, one step at a time, with copy buttons for the three values Shopify's Customer Account API needs (callback URI, JavaScript origin, logout URI), save-and-test for each credential group, an Admin scope check, a first level picked from your customers' real tags, an optional first lock (offered only once sign-in, the Admin app and webhooks pass), and a sign-in link for trying it in a private window. It stores no progress: each step reads the site as it is. It never grants a level or tags a customer.
- **Settings:** the Shopify connection (every value above; fields set in `wp-config.php` are locked), and the membership settings: sign-in links, subscription management link, the return link for Shopify's order pages, shared devices, an optional cap on signed-in browsers per member, and webhook alerts (the silence warning and the alert email).
- **Customers:** every WordPress user linked to a Shopify customer, with their levels, search (WordPress name and email, the Shopify email and name, or a customer ID), filters for level, disabled customers and failed syncs, **Export CSV** of the filtered list, and bulk disconnect. The **Activation** panel compares, per level, the customers Shopify tags for it with those who have signed in here (Shopify's `customersCount`, cached for an hour). **Resync all customers** starts a paced background resync of everyone (see [Resyncing customers](#resyncing-customers)). Row actions open the customer in the Shopify admin, edit the WordPress user, resync, or switch to the user when the User Switching plugin is active. Each customer's page shows the synced data, edits tags (written to Shopify with `tagsAdd`/`tagsRemove`, then read back), and lists that customer's recent events and webhooks.
- **Webhooks:** the **Delivery health** panel (see [Delivery health](#delivery-health)) with a **Check now** button, the endpoint, whether the signing secret is set, the registration result, a **Register webhooks with Shopify** button, rejected deliveries, and every delivery received with its outcome. Dead-lettered deliveries can be retried.
- **App events:** a log of every call the plugin made to Shopify (label, endpoint, HTTP status, duration) and what it did: resyncs, sign-ins, linking, webhook outcomes and admin actions, with who triggered each and from where (cron, REST, admin, site). No request or response bodies, tokens or secrets are recorded. Kept 30 days, capped at 20,000 rows.
- **Reports:** levels joined and left, purchases that unlocked access, plan card checkouts and sign-ins for the last 30 days; joined and left by week for 12 weeks; per level, members now and joined and left over 30 days and 12 months; and per feature, customers who gained and lost it over the same periods. The plugin counts these by day from when it is installed and keeps 400 days; no user IDs are kept with the counts.
- **Access:** the **Levels** tab holds named access levels (customer-tag conditions that rules point at), with a health check, a count of matching customers and a list of tags customers carry that no level or feature uses (usually a Flow typo). The **Content rules** tab holds the site default, how term rules combine, and the controls for each post type and taxonomy. The **Features** tab lists the registered [features](docs/features.md) and maps who gets each one (levels, tags or custom tests), with a preview for a tag set or user. The **Test** tab takes an item and a viewer (a guest, a level, a set of tags or a real user) and shows every rule layer, the effective rule and its source, what that viewer gets, which blocks they see, and every feature that viewer holds with the route that grants it. The **Export / import** tab downloads levels, content rules, term rules, feature mappings and membership settings as one JSON file, and loads a file from another site: it shows what each section would add, change and remove, and imports only the sections you tick. Files never hold customers, item settings, Shopify credentials or tokens, and addresses on the exporting site are rewritten to this one. See [Access rules](#access-rules).
- **Debug:** the values above and where each comes from (`wp-config.php`, Settings, or unreadable), platform checks (PHP, WordPress, Blockstudio, plugin blocks, pretty permalinks), the Shopify connection and granted scopes, buttons to refresh discovery, request a fresh Admin token, and the dry-run entitlement check.

Other code can add pages under the menu: `add_submenu_page( REDEEMID_ADMIN_MENU, ... )` on `admin_menu` after priority 10. Two filters help test setups:

- `redeemid_customer_badges` (`array $badges, int $user_id`): labels shown next to a customer's name.
- `redeemid_customer_is_fixture` (`bool $is_fixture, int $user_id`): return true for seeded users whose customer does not exist in Shopify. Resync keeps their tags and only mark them fresh, tag edits stay in WordPress, and no Shopify admin link is shown.

Signed-in customers can resync themselves from the front end with `POST /wp-json/redeemid/v1/account/sync` (REST nonce, once a minute), and read their own membership (the Member Account block's data: levels, dates, features, account links) from `GET /wp-json/redeemid/v1/account`.

### Shopify app settings

RedeemID → Debug prints the exact values for your site:

- **Callback URI:** `https://your-site/auth-callback/`. Change the slug with the `redeemid_auth_callback_slug` filter.
- **JavaScript origin:** `https://your-site`
- **Logout URI:** `https://your-site/`

### Admin app

New custom apps are made in Shopify's Dev Dashboard; since 2026-01-01 the Shopify admin no longer creates them. Create one in the same organization as the store, with:

- **Admin API scopes:** `read_customers`, `write_customers` and `read_customer_merge`. The last is needed only for `customers/merge`; without it the plugin skips that topic. Optionally add `write_customer_data_erasure`, so staff can ask Shopify to erase a customer from Tools → Erase Personal Data (see [Privacy](#privacy)).
- **Redirect URL:** your site's home URL. The client credentials grant doesn't use it, but the form requires one.

Install it on the store, then enter its client ID and secret in RedeemID → Settings (or define `REDEEMID_ADMIN_CLIENT_ID` and `REDEEMID_ADMIN_CLIENT_SECRET`). RedeemID → Debug shows the scopes Shopify granted.

### Webhooks

Click **Register webhooks with Shopify** on RedeemID → Webhooks. The plugin subscribes its endpoint, `https://your-site/wp-json/redeemid/v1/webhooks/events`, to every topic below through the app, so deliveries are signed with the app's client secret. Running it again only adds what is missing. Don't also create webhooks in Settings → Notifications: those are signed with a different key and would fail verification.

The topics:

- `customers/create`, `customers/update`, `customers/delete`, `customers/enable`, `customers/disable` and `customers/merge`
- `customer.tags_added` and `customer.tags_removed`
- `customers_email_marketing_consent/update`, `customers_marketing_consent/update` and `customers/purchasing_summary`
- `app/uninstalled`
- the compliance topics `customers/data_request`, `customers/redact` and `shop/redact`. Shopify doesn't allow these through the API; they come only to apps that declare them in their app configuration. The setup wizard's Webhooks step shows the lines to add, with this site's URL.

Since API 2025-01, `customers/update` no longer carries tags, so the two `customer.tags_*` topics are needed for tag changes to arrive quickly.

Shopify doesn't promise delivery order. Profile and status webhooks carry the customer's `updated_at`, and one older than the last applied of the same kind is ignored, so a late `customers/disable` can't undo a newer `customers/enable`.

Each delivery is checked and stored, then processed:

- The HMAC is checked first.
- Each delivery is deduped by its webhook ID.
- Only the fields the plugin uses are stored.
- Processing runs in the background (Action Scheduler if present, otherwise WP-Cron).
- Failures retry, then are dead-lettered. RedeemID → Webhooks lists every delivery and its outcome.

A customer's record is refreshed by these webhooks, at sign-in (tags from the sign-in itself; with the Admin API, also the membership dates), by the member's own **Refresh Membership**, or by a staff resync on RedeemID → Customers. Until then the last tags Shopify sent stay in force.

### Delivery health

Because access changes only when a webhook lands, the plugin watches the chain:

- **Once a day** it lists the app's webhook subscriptions in Shopify (one Admin API call, no customer data) and re-creates any that are missing. Shopify removes a subscription after its deliveries fail for several hours, so a re-created one is reported.
- **On every admin screen** it checks, without calling Shopify: deliveries rejected in the last day (wrong signing secret), deliveries waiting more than 10 minutes (WP-Cron or Action Scheduler not running), dead-lettered deliveries, WP-Cron running late, and no delivery at all for the number of days set in Settings (7 by default, 0 to turn off) while customers are linked.

Problems appear in an admin notice (failures on the Dashboard and Plugins screens too), in Tools → Site Health, in the Shopify Health block and on RedeemID → Webhooks. With **Alerts** ticked in Settings, the daily check (and **Check now**) emails the site admin when it finds a new problem, then at most once a day while it lasts.

### Resyncing customers

There is no scheduled bulk sync. To re-read everyone (after webhooks were down, say), staff press **Resync all customers** on RedeemID → Customers:

- It runs in the background, 50 customers per Admin API call, in user ID order, and can be paused, resumed or cancelled. Progress and an estimate show on the page.
- It reads Shopify's query cost report after every call and only spends the spare half of the store's API budget, so sign-ins, member refreshes and webhook syncs are never starved. When Shopify throttles anyway it waits as long as the cost report says.
- If Shopify is unreachable or erroring, it retries with a growing wait (30 seconds up to 15 minutes) and stops after 6 failures in a row; a missing or revoked app stops it at once. **Resume** carries on from where it stopped.
- WP-Cron or Action Scheduler carries it. While the Customers page is open it also moves it along when WP-Cron is late.

From the command line: `wp redeemid resync user <id>...`, `wp redeemid resync all [--foreground]`, and `wp redeemid resync status|pause|resume|cancel`.

## How sign-in works

1. The **Shopify sign-in** block (`redeemid/auth-action`) links to the plugin's login handler. The inserter also offers it as **Shopify Log In**, **Shopify Log Out** and **Connect Shopify Account**. Left empty, "After log in, go to" returns the visitor to the page they were on.
2. The plugin redirects to Shopify with PKCE. The verifier stays on the server.
3. Shopify returns to the callback. The plugin exchanges the code, checks the ID token claims (`iss`, `aud`, `exp` and `nonce`; the signature is not checked, as OIDC allows for tokens received directly from the token endpoint), and signs the visitor in.
4. A new customer gets a **shadow user** with the `shopify_customer` role:
   - no wp-admin, admin bar, password reset or author archive;
   - hidden from the user list;
   - mail goes to the Shopify email.
   If Shopify's verified email belongs to an existing, unlinked WordPress account that isn't an administrator, the visitor is asked first: **Sign in to my account** (they sign in to WordPress, and the accounts are linked) or **No thanks, use a separate account**. Nothing is linked on email alone. Return false from `redeemid_offer_existing_account` to skip the question.
5. Tokens are stored encrypted inside the WordPress session, and refresh on demand. No endpoint returns a token.
6. **Sign out** goes through Shopify's end-session endpoint, so both sessions end. WordPress's own logout link ends only the WordPress session.

Signing in with Shopify, or linking an account, refreshes the member's record: the tags come with the sign-in, and when the Admin API is configured the plugin also reads the `member_since` and `renews_on` dates.

Account status comes only from Shopify's `customers/disable` and `customers/enable` webhooks, plus a resync that can't find the customer any more. The plugin does not read the customer's `state` field: Shopify documents it as meaningful only under classic customer accounts, and sign-in needs new customer accounts, where `state` can say `DISABLED` for a customer in good standing. A resync lifts any disable that didn't come from the disable webhook. (To confirm on a dev store: sign in a customer, then check that RedeemID → Customers doesn't show them as disabled.) A visitor who comes back while still signed in to WordPress is not synced again; their record changes when Shopify sends a webhook, when they press **Refresh Membership**, or when staff resync them.

If Shopify revokes the session:

- a shadow user is signed out;
- a regular WordPress account stays signed in and loses only its Shopify features.

### Linking an existing WordPress account

A signed-in WordPress user can choose **Connect to Shopify**. After the Shopify sign-in:

- the plugin checks that Shopify marks the email as verified and that it matches the WordPress account;
- the user then confirms on the callback page.

Staff accounts (anyone who can edit posts, so contributors and up) can't be linked, and a linked account that later gains that capability can't sign in with Shopify: a Shopify sign-in is an emailed code, which would skip the WordPress password and any two-factor plugin. A mismatched or unverified email is refused with a plain message.

### Sign-in links

- The WordPress login page shows a **Log in with Shopify** button above the form (RedeemID → Settings → Sign-in links). Another setting points WordPress's own login links at Shopify sign-in; wp-admin and reauthentication keep the WordPress form.
- Appearance → Menus has **Log in with Shopify** and **Log out** items, which show only to guests and signed-in visitors respectively.
- The `[redeemid_login]` shortcode renders the sign-in block for classic themes and page builders. Attributes: `show` (`auto`, `login`, `logout`), `login_label`, `logout_label` and `redirect`.

## Gating content

Add **Membership gate** (`redeemid/membership-gate`). It always contains two areas:

- **Members only.** It is removed on the server before rendering when the visitor doesn't qualify. It never reaches page HTML, REST, feeds or search excerpts.
- **Public teaser.** Everyone sees it.

Gate settings (the sidebar only shows the ones that apply):

- Who gets in: required tags, matching any or all; or **an access level** picked from the levels under RedeemID → Access (visitors who hold it, or a level that includes it); or this item's rule.
- What non-members see:
  - a message with a call to action and an optional sign-in link;
  - the teaser blocks;
  - a redirect.

Rules fail closed for guests, unlinked accounts, customers disabled in Shopify and customers never synced. A customer disabled in Shopify is also kept out of content that only needs a signed-in visitor, and when the disable webhook arrives a Shopify-only account is signed out of WordPress; a WordPress account that was never linked is not affected. Synced tags never expire on their own: they apply until Shopify changes them. RedeemID → Debug has a dry run that shows the decision for any user.

A gate can also follow **This item's rule** instead of its own tags (below). A gate can only narrow an item's rule, never widen it. The inserter offers both as variations: **Membership Gate: access level** and **Membership Gate: this item's rule**.

The panel locked visitors see, for a gate and for a locked item's wall, is the `redeemid/locked-panel` Blockstudio component. Restyle it through `.redeemid-gate__panel`, or replace its template from a theme with a [Blockstudio override](https://blockstudio.dev/docs/blocks/overrides).

### Access rules

Whole items can be gated without a block, by rules on four layers. The strongest layer that has a rule wins:

1. **The item** (the **Who can read this** box in the editor, or quick and bulk edit on the post list): follow the site rules, free to everyone, only the ticked levels, or the site rules and also a ticked level. Optional dates: locked until, then free (an embargo), and free until, then locked.
2. **Its terms** (each term's edit screen): *Requires* one of some levels, or *free to everyone until* a date. A free-until rule must have an end date and beats every Requires term until then. In a hierarchical taxonomy a child term with no rule takes its nearest ancestor's.
3. **Its post type** (Access → Content rules): the levels every item needs, the teaser locked visitors see, and an optional number of days after publishing when items open.
4. **The site default** (Access → Content rules).

When several terms require different levels, the site combines them **most restrictively** (every term must pass, after level inclusion is counted), **least restrictively**, or **by priority**. Dates are checked on every request, so an embargo ends or a free window closes with no edit.

A locked item shows its teaser and a wall everywhere it renders: the page, query loops, REST, feeds, oEmbed, excerpts and WPGraphQL. The teaser ends at a **Paywall break** block if the item has one, otherwise follows the post type's setting. Locked items carry `isAccessibleForFree: false` markup and get teaser-only descriptions in Yoast SEO and Rank Math. Search crawlers see what a locked visitor sees. Files linked from a gated item keep public URLs unless you protect them (see [Protected files](#protected-files)).

- **Search** matches locked items, and items holding a membership gate, on their public text only (title, excerpt and teaser), for every visitor. A locked phrase never finds its item, and result counts stay exact. The public text is indexed in post meta and rebuilt in the background after a rule change; until then each result is checked again in PHP.
- **Comments** on a locked item are shown only to visitors who can read it, by default: others see no comments, no count and no form, in templates, REST and comment feeds. A post type can show them to everyone instead.
- **Search engines:** locked items stay indexed and in sitemaps by default. A post type can keep them out: they then get `noindex` and leave the WordPress, Yoast and Rank Math sitemaps together.
- **Editors** (anyone with `redeemid_bypass_gates`, by default anyone who can edit others' posts) read a gated item in full on its own page only, never in lists, feeds, search, REST or GraphQL. The toolbar's **Access** menu switches that page to **View as a locked visitor** or **View as** any level for one request, with nothing stored.
- **Meter** (Access → Content rules, off by default): signed-in readers without access may open a set number of locked items a month or a week. An item counts once, when its own page is opened, never from lists, feeds, search or REST, and it then reads again for free that period. A metered item opens with "Free story 2 of 3 this month" and the level's call to action; once the meter is used up the wall says so. Guests are asked to sign in instead: a cookie meter is trivial to reset and would split the page cache, so there isn't one. Items set to **Never count toward a meter** stay locked. The meter is a site policy like the archive window: it holds no level, grants no feature and tells Shopify nothing. `redeemid_can_read()` answers for membership and ignores the meter. The count is in the reader's user meta, included in WordPress's personal data export and erasure.
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

- **Post classes:** gated items get `redeemid-gated`, `requires-{level}` for each level, and `is-locked` or `is-unlocked` for the current visitor, in themes and in the Query Loop.
- **Access Badge** block (`redeemid/access-badge`): the level an item needs, in the level's badge colour and always with its label, with a lock for visitors it locks out. In a query loop it describes each post. It reads the item's access rule or, for an item without one, the first Membership Gate in its content that is set to an access level (custom-tag gates have no level to name). Set **Label for free items** (for example `Free`) to mark items anyone can read too, so a list shows free and paid stories side by side; leave it empty to show nothing on them. Items gated inline also get the `redeemid-gated`, `requires-{level}` and `is-locked`/`is-unlocked` post classes, and the `redeemid/access` binding names their gate's level instead of calling them free.
- **Block bindings:** bind a paragraph, heading or button to the `redeemid/access` source with a `key` of `level`, `levels`, `state` (Free to read, Locked, Included with your membership), `message`, `cta_label` or `cta_url`.

From the command line, `wp redeemid access explain <post> --as=<guest|signed-in|level|tags|user> [--tags=a,b] [--user=<id>] [--format=json]` prints the same trace as the Test tab, and `wp redeemid access recompute` rebuilds every item's cached rule and search text. `wp redeemid access changes [--since=<date>] [--level=<slug>] [--format=table|csv|json]` lists who joined or left a level (default: the last 7 days), and `wp redeemid access export [--post_type=<type>] [--format=csv|json|table]` writes every gated item's effective rule for an audit. `wp redeemid settings export [--file=<path>]` and `wp redeemid settings import <file> [--sections=levels,rules,term_rules,features,settings] [--dry-run]` move settings between sites, as the **Export / import** tab does.

Each item's rule layers are cached in `_redeemid_access_effective`, stamped with a rules version. Editing a term rule, a term's parent, or the content rules bumps the version; editing an item or its terms clears that item.

### Protected files

Set **Who can download** on a media item to protect it: whoever can read the item it's attached to, members holding a level, or members with a feature. The file moves to `uploads/redeemid-protected/`, and every address WordPress builds for it checks access on every download. The web server must refuse that folder: Apache is handled by an `.htaccess`, nginx needs a three-line rule, and Site Health checks it. See [docs/protected-files.md](docs/protected-files.md).

### Features

Features are site behaviours a customer unlocks through what Shopify already granted, such as ad-free reading or downloads. Code registers them with the `redeemid_features` filter, and RedeemID → Access → Features maps each one to levels, add-on tags or custom tests. Consumers check them with `redeemid_has_feature( $slug )`, the block **Access → Feature** setting (including "does not hold it" for upgrade prompts), the `redeemid-feature-<slug>` body class, `GET /features`, or the `[redeemid_feature]` shortcode. Grants come only from the mirror, so they are revoked the same way gates close. See [docs/features.md](docs/features.md).

### Plan cards and buy-now

Add **Shopify Plan Card** (`redeemid/plan-card`) and pick a product: the sidebar searches your Shopify catalog by title. It shows the product's live Shopify price, cached for five minutes, and a button that goes straight to Shopify checkout. Put plan cards on the join page and in a gate's fallback.

- **Buy-now is a plain form.** It posts to `admin-post.php?action=redeemid_buy_now`, so it works without JavaScript. The server creates a one-line cart, attaches the visitor's identity if they're signed in with Shopify, and redirects to a fresh checkout URL. If Shopify refuses the customer token, it retries as a guest checkout rather than block the purchase.
- **Cache-safe.** For guests the card has no personal data and no nonce. Buy-now is rate-limited to 10 attempts per buyer IP per minute. Behind a proxy or CDN that is the proxy's address, shared by every visitor, unless the `redeemid_buyer_ip` filter returns the visitor's own (the same IP goes to Shopify).
- **Changes for members.** List a level's Shopify product handles under RedeemID → Access → Levels (**Shopify products**). A card selling one of them then tells a signed-in member where they stand: "This is your plan" (with a **Manage membership** link when the subscription link is set in Settings), "Included in your Gold membership", an **Upgrade to Gold** button for a Silver member, or an **Add Puzzles** button for a member of an unrelated level. Labels are editable per card, and **Change for members** turns it off. The mapping only changes the wording: Shopify still decides who holds a level. A card that says something personal keeps that page out of shared caches; guests always get the plain card.
- **Subscriptions.** A product with selling plans (Shopify Subscriptions, Recharge, Appstle and others) shows the plan's price and name, such as "Billed yearly". Put a selling plan ID in the block to sell on that plan; the editor lists the variant's plans and IDs. A subscription-only product sells on its first plan.
- **Prices by country.** Return an ISO country code from the `redeemid_context_country` filter (for example from your CDN's country header) and plan cards and checkout use that country's price and currency through Shopify Markets. Pages with plan cards then differ by country, so the page cache must vary on that header.
- **Independent of Shopify's plugin.** It works the same whether that plugin is installed or not, and never touches its cart.

### Products, collections and the cart

**Shopify Product** (`redeemid/product`) shows one product with its live price, an **Option** picker when it has variants (each option names its price, and sold-out ones are disabled), a quantity field, **Add to cart** and **Buy now**. **Shopify Collection** (`redeemid/collection`) shows one page of a collection as a grid: price (or "From" the lowest), compare-at price, sold-out state and **Add to cart** on products with nothing to choose. A product with options or a required selling plan links to its product page instead. **Next** and **Previous** are plain links carrying Shopify's cursor (`?redeemid_list=<handle>&redeemid_after=…`), so paging works without JavaScript and each page has its own URL. Both render on the server from the same five-minute Storefront cache as plan cards and carry no personal data.

**Shopify Cart** (`redeemid/cart`) shows the visitor's cart with quantities, **Remove**, a discount code and **Check out**. **Shopify Cart Link** (`redeemid/cart-link`) goes in a header: a link to the cart with the item count.

- **The cart lives on the server.** It is a Shopify Storefront cart. The browser holds a random key in the HttpOnly `redeemid_cart` cookie, and the cart ID stays in a transient under that key (14 days). A readable `redeemid_cart_count` cookie holds only the item count, which the cart link shows on cached pages without a request.
- **No JavaScript.** Every change is a form POST to `admin-post.php?action=redeemid_cart`. Like buy-now there is no nonce, since product forms sit in cached pages: requests a browser marks as cross-site are refused, and changes are limited to 30 a minute per buyer IP.
- **Signed-in checkout.** At **Check out**, a linked customer is attached to the cart on the server, so checkout opens signed in and the purchase can unlock content. A cart signed in that way is dropped at sign-out; with **Clear the cart on sign-out**, every cart is.

**Storefront pages** (RedeemID → Settings, on by default) serve a product page, a collection page and the cart page at `/products/{handle}/`, `/collections/{handle}/` and `/cart/`. Each path can be changed, for example to `/shop/p/` or `/basket/`, or with the `redeemid_storefront_path` filter. On block themes the pages are templates you can edit under Appearance → Editor → Templates (Shopify product, Shopify collection, Shopify cart); classic themes can override them with `redeemid-product.php`, `redeemid-collection.php` or `redeemid-cart.php`. Collection cards and cart lines link to the product page. A path a published WordPress page already uses is left alone, and a handle Shopify doesn't have is a 404.

The pages have their own title, meta description, canonical link, Open Graph and Twitter tags, and Product structured data with one offer per variant. With Yoast SEO or Rank Math active, RedeemID gives them the title, description and canonical link and lets them print the tags. The cart page is `noindex` and never cached.

Staff who can manage RedeemID get a **Shopify** menu in the admin bar: the store's products, collections, orders and customers in the Shopify admin, and on a product or collection page, a link that edits it there. The links open in a new tab.

Next to Shopify's own plugin, see [Shopify's WordPress plugin](#shopifys-wordpress-plugin) for who answers shared paths.

After checkout, the plugin finishes the job for a signed-in customer: for 30 minutes it re-reads their tags at most once a minute while Shopify Flow tags them, shows a "finishing up" banner, then "Your membership is ready" once. Link Shopify's order status page and confirmation email to the return link shown under RedeemID → Settings (`/?redeemid_return=1`): it takes the customer back to the page they bought from, or asks a guest to log in with the email from their order. Turn the banner off with the `redeemid_checkout_banner` filter.

It needs `REDEEMID_STOREFRONT_PRIVATE_TOKEN` (recommended) or `REDEEMID_PUBLIC_ACCESS_TOKEN`, and the product must be published to the Headless channel that issued the token. Behind a proxy or CDN, the buyer IP the plugin sends is the proxy's unless the `redeemid_buyer_ip` filter returns the visitor's from a header your proxy sets.

### Account blocks

- **Member Account** (`redeemid/member-account`): the "my membership" card. It shows the member's levels and when each started, the renewal date, past levels, their features, when it last synced, links to their Shopify account and subscription management (RedeemID → Settings → Subscription management), and **Download my data** and **Delete my data**, which create WordPress privacy requests the member confirms by email (turn them off with "Offer Download my data and Delete my data"). Guests get a sign-in button, unlinked accounts a connect button, and a lapsed member a join link. Start and renewal dates come from the `membership.member_since` and `membership.renews_on` customer metafields when Flow or an app sets them, otherwise from the level history the plugin keeps. A gift membership's end date shows from `membership.gift_until` until it passes.
- **Refresh Membership** (`redeemid/membership-refresh`): a button for signed-in, linked customers that re-syncs their membership from Shopify through `POST /account/sync` and reloads the page, so gates open as soon as a new tag lands. Guests and unlinked accounts see nothing. Put it in a paywall's teaser or the header.
- **Shopify Account Status** (`redeemid/account-status`): tells the visitor whether they are signed in, linked to a Shopify customer, when their membership last synced, and whether Shopify sign-in is available. "Show the raw status response" adds the `GET /auth/status` payload, nonces redacted, for troubleshooting.
- **Membership Features** (`redeemid/member-features`): the visitor's granted features and where each comes from ("Download access, from Add-on purchase"), for the account page. A menu mode shows only the features registered as primary, at most three, for a header.
- **Shopify Health** (`redeemid/health`): the at-a-glance version of RedeemID → Debug (Blockstudio, the plugin's blocks, permalinks, the callback page, sign-in, which configuration values are set but never the values themselves, Shopify's own plugin). Administrators only; everyone else sees a one-line note. Add rows with the `redeemid_health_checks` filter.

All five are dynamic islands (see [Caching](#caching)), so they never put personal data or nonces in a cached page.

### Membership tags

The plugin doesn't tag customers. Shopify Flow, or a subscription or membership app, adds a tag when a membership starts and removes it when it ends; levels under RedeemID → Access point at those tags. [docs/shopify-flow.md](docs/shopify-flow.md) has workflows for one-time purchases, time passes, fixed terms, subscriptions, gifts, team seats (Shopify B2B) and complimentary access. Membership emails (welcome, renewal, cancellation) belong in Flow or Shopify Email too; [docs/member-emails.md](docs/member-emails.md) covers the WordPress-side ones, built on `redeemid_levels_changed`. [docs/seats-and-gifts.md](docs/seats-and-gifts.md) explains why seats and gifts stay in Shopify.

Access arrives when Shopify's tag webhook does, usually within seconds. A customer who is already signed in can pull it sooner with the **Refresh Membership** block.

The locked panel a gate shows reads the visitor's situation: sign in, connect a Shopify account, "finishing up" after checkout, renew a level that lapsed, upgrade from a lower level, or join. Each has its own wording and button, which the `redeemid_locked_panel` filter can change.

**Drip.** The **Who can read this** box on an item has "Members can read it after N days of membership". A member who passes the item's rule but joined more recently sees when it opens for them instead. A member's start is the `membership.member_since` metafield when Shopify keeps one, otherwise when they first held a level they hold now (as WordPress saw it), otherwise when they linked. Search listings don't apply drip.

**Members' home.** A level can name a members' home page (Access → Levels). A member who logs in with Shopify from the home page or a plain login link lands there; logging in from an article returns them to the article. The `redeemid_sign_in_redirect` filter can change either.

The plugin records when each level started and ended for each member (`level.changed` in the event log, and the `redeemid_levels_changed` action). That history is for display; access always comes from the current tags.

## Caching

Gated pages are decided on the server. Any response that shows members-only or personal content (a granted item, a Membership Gate's members area, a block with its own Access setting, a member-aware plan card, a locked panel with a form) sets `DONOTCACHEPAGE` for page-cache plugins and, while headers can still be sent, `Cache-Control: private, no-store` for CDNs and proxies that never see that constant. Block themes render before headers go out, so the header is always sent there; a classic theme that prints content after headers are sent gets the constant only. Singular items gated by access rules also send `X-RedeemID-Access: open|locked|granted|bypass`, and granted, bypass and preview responses are `Cache-Control: private`. The `redeemid_access_changed` action passes the IDs of items whose rule changed (an empty list means any item may have), for purging.

Personal pieces, such as the sign-in control, are [Blockstudio dynamic islands](https://blockstudio.dev/docs/blocks/islands). The page carries a neutral placeholder that any page cache can store (the guest "Log in" link, so sign-in works without JavaScript). After load, Blockstudio renders every island on the page for the visitor in one same-origin request to `POST /wp-json/blockstudio/v1/island/render` and swaps the results in. That response is never cached, so it can carry the visitor's own nonces; Blockstudio only restores the signed-in user for same-origin requests, so another site cannot read it. The sign-in control and the member account panel send you back to the page you were on: the fragment reads it from the same-origin Referer, and when a `Referrer-Policy` strips that, from the island's signed page context, so posts and pages still return to their permalink.

`admin-ajax.php?action=redeemid_status` still returns the visitor's display-safe state and fresh nonces for scripts that are not blocks, such as the cart bridge.

Cache rules should bypass or vary on these cookies:

- `wordpress_logged_in_*`
- `redeemid_oauth_state`
- `redeemid_pending`, set for ten minutes when sign-in needs one more answer (connect an existing account, or confirm a link): it ties that step to the browser that started it
- `redeemid_fset`, only when a [feature](docs/features.md#caching) declares the `vary` caching option: an opaque hash of the visitor's granted set
- `redeemid_cart`, for the cart page only (it already sends `Cache-Control: private`). Pages with the cart link need no bypass: the count comes from `redeemid_cart_count` in the browser

## Shopify's WordPress plugin

Shopify publishes its own plugin for the Sell on WordPress channel. It is an anonymous storefront: product, collection and cart-toggle blocks, `/products/{handle}`, `/collections/{handle}` and `/cart`, all built on Storefront Web Components. It has no sign-in, webhooks or gating, so it and RedeemID do different jobs on the same store.

RedeemID works with or without it:

- **Detection.** Overview and Debug show one of four states: not installed, active but not connected, connected to the same store as `REDEEMID_STORE_DOMAIN`, or connected to another store. The last one is an error, because purchases there can't unlock content here. Other code can read the state with `RedeemID\Integrations\OfficialPlugin::state()` or change it with the `redeemid_official_state` filter.
- **Credentials stay separate.** RedeemID reads only the official plugin's store domain, to compare it. It never reads its Storefront token.
- **Routes.** When active, the official plugin always claims `/cart` and, by default, `/products/*` and `/collections/*`. By default RedeemID stands down on a path it has claimed: its cart and cart link switch off and it keeps signing that plugin's cart in. Give a storefront page a different path in Settings and the two never meet. Turn on **RedeemID takes precedence** and RedeemID's rules go after that plugin's, so RedeemID answers the shared paths, runs the cart, and stops the cart bridge. Debug shows who answers each path.
- **Replacing it.** RedeemID's product, collection, cart and cart link blocks and its storefront pages cover what it does, without web components or JavaScript. Version 1.0.0 is named in a published security advisory (CVE-2026-57748) with no fixed release, so Overview and Debug warn while it is active. See [docs/official-plugin.md](docs/official-plugin.md) for what is covered.
- **Gating.** Official product and collection blocks can go inside a membership gate. The gate renders on the server, so non-members never receive the block's markup.
- **Signed-in checkout from its cart.** When it serves the same store and a Storefront token is set, RedeemID adds a small script to front-end pages. If a linked customer is signed in and the official cart exists, the script sends the cart ID to `POST /cart/identity`, and RedeemID attaches the customer on the server with `cartBuyerIdentityUpdate`. Checkout then opens signed in, so purchases unlock content. The customer token never reaches the browser. Guests with no cart make no request.
- **Keyboard access to its cart toggle.** Its 1.0.0 cart toggle is a link with no `href`, which a keyboard can't reach. RedeemID gives it a button role and a tab stop, and opens the cart on Enter or Space (`Integrations\OfficialCartToggle`).
- **Shared devices.** After sign-out, the script drops a cart it signed in, so the next person can't check out as the previous customer. Turn on **Clear the cart on sign-out** to drop every cart a signed-in visitor used.
- **Limits.** The bridge reads `localStorage['__shopify:cartId']`, which the official cart component uses but doesn't document. If Shopify changes it, the bridge stops attaching and the cart keeps working as a guest cart. Debug shows the last attach and the last failure. A visitor who adds to the cart and reaches checkout within a second or two may get there before the attach finishes.

See [docs/official-plugin.md](docs/official-plugin.md) for the replacement plan and the rules for running both.

## PHP API

The full developer API (functions, the event stream, actions, filters, REST routes and the `window.redeemid` browser client), with what is stable and example adapters for analytics tools and other plugins, is in [docs/developer-api.md](docs/developer-api.md). Its version is `REDEEMID_API_VERSION`.

For plugins that build on RedeemID (a course, a forum, a download manager) instead of running their own membership layer:

- `redeemid_can_read( int|WP_Post $post, int|WP_User|null $user = null ): bool`: can this user read the item in full? It goes through every layer the gate uses (site default, post type, terms, the item's own rule, time and archive windows, drip) after WordPress's own status checks (drafts, private and password-protected items only for people who can edit them). It never uses the editor bypass. With no user it answers for the current visitor, including a staff "preview as".
- `redeemid_user_levels( int|WP_User|null $user = null ): string[]`: slugs of the paid levels the user holds, including levels held through another (Gold includes Silver).
- `redeemid_has_feature( string $slug, int|WP_User|null $user = null ): bool`, `redeemid_granted_features()` and `redeemid_menu_features()`: see [Features](#features).
- `redeemid_meter( int|WP_User|null $user = null ): array`: `{on, used, limit, period}`, the meter as this user sees it this period.

None of them return Shopify tags or the customer record. Each reads the synced mirror, so it costs no Shopify call. Pages that show member-only output from these should not be served from a shared page cache (see [Caching](#caching)).

## Abilities and MCP

On WordPress 6.9+, RedeemID registers abilities with the Abilities API, so AI agents can use them through the [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin. Staff abilities (`manage_options`) explain access to an item for any viewer, explain features, list levels and rules, read one member's synced record, report health and list level changes. Member abilities (`redeemid/my-membership`, `redeemid/can-i-read`) answer only for the signed-in caller. Three staff actions (`redeemid/sync-member`, `redeemid/start-bulk-resync`, `redeemid/edit-member-tags`) re-read Shopify or change tags in Shopify, and are kept off MCP unless a site opts in. None of them grants access in WordPress. See [docs/abilities.md](docs/abilities.md).

## REST API

The namespace is `redeemid/v1`. Every write route needs the `wp_rest` nonce.

| Route | Purpose |
|---|---|
| `GET /state` | The caller's own state for scripts: `{apiVersion, signedIn, linked, levels, features, meter, nonce}` (the browser client reads the same through admin-ajax, because a cached page has no REST nonce) |
| `GET /auth/status` | The caller's own display-safe state |
| `GET /auth/login` | Redirects to Shopify |
| `POST /auth/logout` | Returns `{redirect}`, the Shopify end-session URL |
| `POST /auth/refresh` | Refreshes the Shopify session and returns status, never tokens |
| `POST /auth/disconnect` | Unlinks the Shopify customer from the current user |
| `GET /account` | The caller's own membership, as the Member Account block shows it (levels, dates, features, account links); guests get the guest shape |
| `POST /account/sync` | Re-syncs the current user's mirror (rate-limited) |
| `POST /cart/identity` | Attaches the signed-in, linked customer to the official plugin's cart, `{cartId}` → `{attached}` (rate-limited; only when that plugin serves the same store) |
| `POST /webhooks/events` | Shopify webhooks (HMAC-verified) |
| `GET /features` | The caller's own granted feature slugs, for features that declare the REST surface; guests get Public ones only. `/wp/v2/users/me` carries the same list as `redeemid_features` |
| `GET /editor/products?search=` | Plan Card and Product block picker: `[{handle, title}]` from the Storefront API. Editors only (same-origin, `edit_posts`), because Blockstudio's picker calls it without a nonce |
| `GET /editor/collections?search=` | Collection block picker: `[{handle, title}]` from the Storefront API. Same access as the product picker |

Non-JavaScript forms post to `admin-post.php` handlers (`redeemid_login`, `_logout`, `_connect`, `_link_confirm`, `_sync`, `_disconnect`), which redirect back to a page.

## Building on Blockstudio

The plugin is built on [Blockstudio](https://blockstudio.dev) (7.7.10, bundled through Composer). **Before writing any code for a block, check whether Blockstudio already does it.** Hand-made versions of features Blockstudio ships have to be maintained, tested and secured by us, and they drift from how the rest of the blocks behave. The Blockstudio docs are in `vendor/blockstudio/blockstudio/docs` after `composer install`, and at [blockstudio.dev/docs](https://blockstudio.dev/docs).

What the plugin uses, and where:

| Need | Blockstudio feature | Used in |
|---|---|---|
| Show a sidebar field only when another field has a value | [Conditional logic](https://blockstudio.dev/docs/blocks/attributes/conditional-logic) (`conditions`) | Membership Gate, Shopify Sign-in |
| Fill a select from site data or an API | [Populate options](https://blockstudio.dev/docs/blocks/attributes/populating-options): `function` for site data, `fetch` for a search endpoint | Gate's level picker (`redeemid_level_options()`), Plan Card's product picker |
| Personal output on a cacheable page | [Dynamic islands](https://blockstudio.dev/docs/blocks/islands) | Shopify Sign-in, Member Account, Refresh Membership, Shopify Account Status, Shopify Health, Membership Features |
| Shared markup the plugin renders, that themes can restyle or replace | [Components](https://blockstudio.dev/docs/blocks/components) and [overrides](https://blockstudio.dev/docs/blocks/overrides) | `locked-panel`, rendered by `Gate::fallback_panel()` |
| Several inserter entries for one block | [Variations](https://blockstudio.dev/docs/blocks/variations) | Shopify Sign-in, Membership Gate |
| URL fields with a page picker | [`link` field](https://blockstudio.dev/docs/blocks/attributes/field-types) | Gate CTA and redirect, sign-in redirects |
| Editor preview that reads post meta | [`refreshOn: ["save"]`](https://blockstudio.dev/docs/blocks/post-meta) | Access Badge, Membership Gate |
| Inserter hover previews | [`example`](https://blockstudio.dev/docs/blocks/preview) and `$isPreview` | Every inserter block |
| Read a stored block's values outside rendering | `bs_block_values()` | `Gate::values()` |
| Styles and scripts per block | `style.scss`, `script.view.js` beside `block.json` | All blocks |

Rules that follow from it:

- Use the field type that fits (select, link, toggle, repeater) and read the value as Blockstudio returns it. Selects return `{value, label}` and links `{url, ...}`: read them with `Gate::option()` and `Gate::url()`.
- Changing a field's type changes how it is stored, and content saved in the old shape stops carrying the value. Don't change a field's type once a block has shipped; add a new field instead.
- In a block template, `return;` means "this block renders nothing" to Blockstudio, which then also skips the block's stylesheet and scripts, even if markup was printed before the `return`. Use early returns only for branches that print nothing (or editor-only branches); branches that print markup end with `if`/`else`, as in Plan Card and Shopify Sign-in.
- Blockstudio's `populate` function type only accepts global functions. Put a thin wrapper in `src/functions.php` and keep the logic in a class.
- Register blocks with `Blockstudio\Build::init()` (see `Blocks\Registration`), never the `blockstudio/path` filter, which would hide the theme's blocks.
- Where Blockstudio doesn't fit, write it ourselves and say why in a comment. Current cases:
  - **The per-block Access panel** (`assets/block-access.js`), rather than an [extension](https://blockstudio.dev/docs/extensions). An extension can't warn when a block sits after the paywall break, can't skip the gate's own areas, and would move the saved setting out of `redeemidAccess`.
  - **REST routes and `admin-post.php` forms**, rather than [RPC](https://blockstudio.dev/docs/blocks/block-api/rpc) or `db.php`. Blockstudio's client prints its nonce and token into the page HTML, which a page cache would serve stale.
  - **WordPress cron and our own tables**, rather than `cron.php` or `db.php`, because they belong to the plugin's lifecycle, not to a block.
- Leave Blockstudio's static prerendering off on gated sites, and never set `serveLoggedIn`.

## Hooks

Which of these are stable is listed in [docs/developer-api.md](docs/developer-api.md).

- Actions:
  - `redeemid_event` (`string $name, array $props, array $event`): every event in one vendor-neutral shape, for analytics and CRMs: `wall_shown`, `meter_used`, `meter_exhausted`, `checkout_started`, `level_gained` and `level_lost`, each also as its own shortcut action (`redeemid_wall_shown` and so on)
  - `redeemid_signed_in`, `redeemid_linked`, `redeemid_disconnected` and `redeemid_before_logout`
  - `redeemid_log`, which receives redacted log events
  - `redeemid_access_changed` (`int[] $post_ids`), `redeemid_access_levels_changed` and `redeemid_access_locked_changed` (`int $post_id, bool $locked`)
  - `redeemid_levels_changed` (`int $user_id, string[] $added, string[] $removed`), level slugs, after a sync
  - `redeemid_features_changed` (`int $user_id, string[] $added, string[] $removed`) and `redeemid_features_mapping_changed` (`string[] $slugs`); see [docs/features.md](docs/features.md#hooks)
  - `redeemid_shopify_erasure_requested` (`int $request_id, string $customer_gid`), after staff asked Shopify to erase a customer from an erase request
- Filters:
  - `redeemid_auth_callback_slug`, `redeemid_default_redirect` and `redeemid_customer_admin_redirect`
  - `redeemid_setting_{key}`
  - `redeemid_official_state`, which overrides the detected state of Shopify's own plugin
  - `redeemid_reassign_user`, which picks who receives content when a Shopify redaction deletes a shadow user
  - `redeemid_redact_opens_wp_request` (`bool $open, string $email`): when Shopify redacts a customer whose WordPress account stays, RedeemID leaves a confirmed erase request for staff to finish; return false to record it as completed instead
  - `redeemid_access_levels` and `redeemid_access_rules`, which set levels and content rules from code (the admin screens then show them read-only)
  - `redeemid_gated_post_types`, `redeemid_access_context`, `redeemid_effective_rule`, `redeemid_teaser_settings` and `redeemid_paywall_schema`
  - `redeemid_pre_sync_now` (`null|string $result, int $user_id`), which answers a "sync now" without asking Shopify: return `synced`, `sync_wait` or `sync_failed`. For customers Shopify doesn't know, such as a test site's simulated accounts
  - `redeemid_features`, `redeemid_feature_tests` and `redeemid_feature_decision`, which register features and custom tests and can alter one decision (see [docs/features.md](docs/features.md))
  - `redeemid_sign_in_redirect` (`string $target, int $user_id, string $redirect_to`) and `redeemid_drip_days` (`int $days, WP_Post $post`)
  - `redeemid_context_country` (`string $country`), an ISO code that prices plan cards, product and collection blocks and checkout in that country's currency
  - `redeemid_product_url` (`string $url, string $handle`), where a collection card or cart line links: RedeemID's product page when it serves one, else Shopify's online store page, '' for none
  - `redeemid_storefront_path` (`string $path, string $kind`), the path of the `product`, `collection` or `cart` page; `redeemid_cart_enabled` (`bool`) turns RedeemID's cart on or off
  - `redeemid_buyer_ip` (`string $ip`), the visitor IP sent to Shopify with buy-now and cart calls when the private Storefront token is set. Return the real IP from a header your proxy or CDN sets; forwarded headers are not read by default because visitors can forge them
  - `redeemid_locked_panel` (`array $data, Decision $decision`), the locked panel's message, button and form; `redeemid_checkout_banner` (`bool`); `redeemid_offer_existing_account` (`bool`)
  - `redeemid_file_sendfile` (`string $mode, string $path`): hand protected downloads to the web server with `x-accel-redirect` (nginx) or `x-sendfile` (Apache, LiteSpeed); see [docs/protected-files.md](docs/protected-files.md)
  - `redeemid_client_config` (experimental), the browser client's config printed into cacheable pages
  - `redeemid_health_checks`, which adds rows (`label`, `state` of `ok|warn|fail|info`, `detail`) to the Shopify Health block

## Uninstall

By default, uninstalling keeps WordPress users and their content. It removes:

- plugin options and transients
- the webhook and app event tables
- Shopify tokens inside sessions
- the cached rule and search text on each item, each reader's meter count and cart pointer
- what RedeemID added to privacy requests (the requests themselves are WordPress's and stay)
- the plugin's scheduled jobs

Access rules editors set on items and terms stay. Protected files stay in `uploads/redeemid-protected/`; uninstalling doesn't move them back.

To also delete shadow users and every linked field, add `define( 'REDEEMID_UNINSTALL_DELETE_DATA', true );` before uninstalling. Their content is reassigned to the first administrator, not deleted.

## Privacy

WordPress's own privacy tools, **Tools → Export Personal Data** and **Tools → Erase Personal Data**, are where every privacy request for a member is handled, whether it started in WordPress, in the Member Account block or in Shopify. See [docs/privacy-requests.md](docs/privacy-requests.md) for the staff walkthrough.

- **Export** ("Shopify link and synced data") adds the synced Shopify fields, the meter, the checkout record and any cart in progress, then the person's rows in the activity log and the webhook deliveries about their customer number.
- **Erase** removes the Shopify link, synced fields, tokens, meter and cart, and strips the person from both logs (the rows stay, so counts stay right). The WordPress account stays.
- **A Shopify column** on both screens shows the linked customer with a link to Shopify admin. On an erase request, staff can press **Also request erasure in Shopify** (needs the optional `write_customer_data_erasure` scope; never automatic, refused for test fixtures). Shopify queues it and can still cancel it, then sends `customers/redact`, which removes what is left here. Shopify has no API to start its own customer data report, so export requests point to Shopify admin's Request customer data.
- **Shopify's compliance webhooks** land in the same list: `customers/data_request` creates a confirmed export request marked with Shopify's request number, for staff to send to the store owner. `customers/redact` erases RedeemID's data at once, deletes a shadow user, and records the redaction as an erase request (left confirmed when the WordPress account stays, so staff decide on the rest). `shop/redact` unlinks every account and clears customer numbers from both logs.
- **Suggested policy text** for Settings → Privacy → Policy Guide covers what is mirrored, the 30-day logs and the cookies: `redeemid_oauth_state`, `redeemid_pending`, `redeemid_cart`, `redeemid_cart_count` and `redeemid_fset`. With Shopify's plugin active, the cart bridge also stores two flags in the visitor's `localStorage` (`redeemid:attachedCart` and `redeemid:signedInCart`).
- **RedeemID → Customers** shows each person's privacy requests, and RedeemID → Debug has rows for the erasure scope and the last Shopify privacy webhook.

## Contributing

Issues and pull requests are welcome. Work branches from `main`, and pull requests go back into it.

```sh
composer install
composer lint   # php -l on every PHP file
composer phpcs  # WordPress VIP coding standards (phpcs.xml.dist)
composer phpstan # Blockstudio checks: templates vs block.json, block.json shapes (phpstan.neon.dist)
composer test   # PHPUnit; no WordPress install needed
```

`readme.txt` is the WordPress.org-format readme that ships in the zip. Keep its `Stable tag` equal to `Version:` when you release.

GitHub Actions runs the lint and tests on PHP 8.2, 8.3 and 8.4 for every pull request, and annotates coding-standard findings, and fails on any Blockstudio PHPStan finding. To release, bump `Version:` in `redeemid.php` and `REDEEMID_VERSION` together; when that reaches `main`, GitHub Actions builds `redeemid-core.zip` and publishes release `v<version>`. A version with a suffix such as `0.1.0-beta.1` is published as a pre-release. Files listed in `.distignore` stay out of the zip. The translation template is `languages/redeemid.pot`; regenerate it with `wp i18n make-pot . languages/redeemid.pot --exclude=vendor,tests` when strings change.

- **Check Blockstudio first.** Before writing code for a block, see whether [Blockstudio](https://blockstudio.dev/docs) already does it: conditional fields, populated pickers, islands, components, variations and more. [Building on Blockstudio](#building-on-blockstudio) lists what the plugin uses and the few places it deliberately doesn't.
- **Shopify is the source of truth for access.** A change must never let WordPress grant an entitlement that Shopify doesn't hold. Tools that make access easier to manage from WordPress write to Shopify (for example, customer tags) and read the result back.
- **Fail closed.** When data is missing or unreadable, the visitor gets less access, not more.
- **Never log or return secrets or tokens**, and keep personal data and nonces out of cacheable HTML.

The demo theme in [webmultipliers/redeemid-demo-theme](https://github.com/webmultipliers/redeemid-demo-theme) seeds a demo news site, test pages and test accounts that exercise every feature, which makes it a good companion for manual testing.

### Reporting a security issue

Please don't open a public issue for a vulnerability. Report it privately from the repository's **Security** tab (**Report a vulnerability**).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). Blockstudio, bundled through Composer, is also GPL-2.0-or-later.
