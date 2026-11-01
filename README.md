# RedeemID

Customers sign in to WordPress with their Shopify customer account, the same one they use in your store, and what that account holds in Shopify decides what they can read and use on WordPress. RedeemID is free and open source, and built on [Blockstudio](https://blockstudio.dev).

**[Website](https://www.redeemid.com/) · [Docs](https://www.redeemid.com/docs/) · [Guides](https://www.redeemid.com/guides/) · [Live demo](https://demo.redeemid.com/) · [Download](https://github.com/webmultipliers/redeemid-core/releases/latest) · [Developer API](docs/developer-api.md)**

Shopify stays the single source of truth. RedeemID links each Shopify customer account to a WordPress user and mirrors it there: name, email, account status, and the tags Shopify Flow or a subscription or membership app sets. Every access decision is made on the server from that mirror. A customer disabled in Shopify loses members-only access, and WordPress never grants access that Shopify doesn't know about.

- **Sign in with Shopify.** OAuth with PKCE against the Customer Account API. New customers get a WordPress user; existing WordPress accounts can be linked after a verified-email check.
- **Memberships from Shopify.** Shopify Flow or your subscription app tags customers, and named access levels point at those tags. Renewals, cancellations and fixed terms stay in Shopify, and webhooks bring changes over within seconds.
- **Gate content.** A Membership Gate block, a Paywall Break, an Access panel on every block, and access rules per item, term, post type and site. Locked content is removed on the server, so it never reaches the HTML, feeds, REST or search. A signed-in meter, protected file downloads and member features (such as ad-free reading) use the same rules.
- **Member accounts and preferences.** A member account card, a membership refresh button, and member preferences: customer metafields the owner chooses, read and written on the Shopify customer.
- **Sell from WordPress.** Plan cards, products and collections with live Shopify prices, and a server-held cart that checks out on Shopify, signed in. Every cart records the page the purchase started on, so the order in Shopify shows where the sale came from.
- **Built for the block editor and page caches.** Every piece a visitor sees is a block that renders on the server and works without JavaScript. Personal pieces are Blockstudio dynamic islands, so pages stay cacheable.
- **For developers.** A PHP API, REST routes, WP-CLI, a vendor-neutral event stream with signed outbound event webhooks, and WordPress Abilities for AI agents. Works beside Shopify's own WordPress plugin, or replaces it.

> **Status: 0.0.7, early development.** Expect breaking changes before 1.0.0. See the [changelog](https://www.redeemid.com/changelog/) and [releases](https://github.com/webmultipliers/redeemid-core/releases).

## Requirements

- PHP 8.2+ with `sodium`
- WordPress 6.7+ (6.9+ for [abilities](docs/abilities.md))
- Pretty permalinks, for sign-in only: Shopify returns customers to `https://your-site/auth-callback/`, a rewrite rule
- A Shopify store with a headless or custom app that has Customer Account API access, and a Dev Dashboard app for the Admin API

If PHP, WordPress or `sodium` is missing, activation stops with a notice instead of a fatal error.

## Install

Download `redeemid-core.zip` from the [latest release](https://github.com/webmultipliers/redeemid-core/releases/latest) and upload it in **Plugins → Add New → Upload Plugin**. The zip includes `vendor/`. On a site that isn't connected yet, activation opens **RedeemID → Setup**, a step-by-step wizard that connects the store, checks HTTPS and permalinks, registers webhooks and creates a first level.

From source, or when your host syncs the plugin folder from Git, run `composer build-prod` in the plugin folder after each sync. That is the only build step. Blockstudio compiles block styles on first load, so `blocks/*/_dist/` must be writable.

The plugin runs its own Blockstudio instance from `blocks/` (Blockstudio 7.7.10, bundled through Composer). It doesn't use the `blockstudio/path` filter, so it coexists with Blockstudio themes and works in any other theme.

Full steps: [Install](https://www.redeemid.com/docs/install/) and [Connect Shopify](https://www.redeemid.com/docs/connect/).

## Configure

These eight values are the whole connection. Enter each in **RedeemID → Settings**, or define a constant of the same name in `wp-config.php`. A constant always wins and locks its field; it is the more secure choice because the value never touches the database. Secrets saved in Settings are encrypted (libsodium) and write-only. RedeemID → Debug shows where each value comes from, never the value.

| Constant | Required | Purpose |
|---|---|---|
| `REDEEMID_STORE_DOMAIN` | yes | `your-store.myshopify.com` |
| `REDEEMID_CLIENT_ID` | yes | Customer Account API client ID, for sign-in |
| `REDEEMID_CLIENT_SECRET` | confidential clients | Customer Account API client secret. Leave unset for a public client |
| `REDEEMID_ADMIN_CLIENT_ID` | yes | Client ID of the Dev Dashboard app installed on the store, for resync, tag editing and webhooks |
| `REDEEMID_ADMIN_CLIENT_SECRET` | yes | That app's client secret, for Admin tokens and webhook signatures |
| `REDEEMID_PUBLIC_ACCESS_TOKEN` | for selling | Storefront API public token from the Headless channel |
| `REDEEMID_STOREFRONT_PRIVATE_TOKEN` | recommended | Storefront API private token from the same channel. Replaces the public token and sends each buyer's IP, so Shopify doesn't throttle checkout |
| `REDEEMID_ENCRYPTION_KEY` | no | Base64 32-byte key for encrypting secrets. Defaults to one derived from the WordPress salts; set it if you rotate salts |

Everything else (levels, content rules, features, sign-in links, storefront pages, preferences, event webhooks, alerts) is set in the admin. See [Configuration](https://www.redeemid.com/docs/configuration/) and [Admin screens](https://www.redeemid.com/docs/admin/).

## Documentation

The docs live at [redeemid.com/docs](https://www.redeemid.com/docs/):

- **Get started:** [Install](https://www.redeemid.com/docs/install/), [Connect Shopify](https://www.redeemid.com/docs/connect/), [Configuration](https://www.redeemid.com/docs/configuration/), [Add Shopify customer login](https://www.redeemid.com/docs/shopify-login/)
- **Access and members:** [One customer account](https://www.redeemid.com/docs/identity/), [Access levels](https://www.redeemid.com/docs/levels/), [Paywall rules](https://www.redeemid.com/docs/paywall-rules/), [Editor's guide](https://www.redeemid.com/docs/editors/), [Member accounts](https://www.redeemid.com/docs/account/), [Member preferences](https://www.redeemid.com/docs/member-preferences/), [Member features](https://www.redeemid.com/docs/features/), [Protected files](https://www.redeemid.com/docs/protected-files/), [Selling memberships](https://www.redeemid.com/docs/commerce/), [Products, collections and the cart](https://www.redeemid.com/docs/storefront/), [Member emails](https://www.redeemid.com/docs/member-emails/)
- **Tags and sync:** [Tagging customers with Shopify Flow](https://www.redeemid.com/docs/shopify-flow/), [How changes reach WordPress](https://www.redeemid.com/docs/sync/)
- **Other plugins:** [Works with](https://www.redeemid.com/docs/compatibility/) (page caches, SEO, newsletters), [Moving off Shopify's WordPress plugin](https://www.redeemid.com/docs/official-plugin/), [From WooCommerce](https://www.redeemid.com/docs/woocommerce/), [From a WordPress membership plugin](https://www.redeemid.com/docs/membership-plugins/)
- **Reference:** [Blocks](https://www.redeemid.com/docs/blocks/), [Admin screens](https://www.redeemid.com/docs/admin/), [Developer API](https://www.redeemid.com/docs/developer-api/), [Hooks, REST and WP-CLI](https://www.redeemid.com/docs/hooks/), [Event webhooks](https://www.redeemid.com/docs/event-webhooks/), [Abilities and AI agents](https://www.redeemid.com/docs/abilities/), [Privacy](https://www.redeemid.com/docs/privacy/), [Troubleshooting](https://www.redeemid.com/docs/troubleshoot/)

The developer pages are also in [`docs/`](docs/) in this repository, versioned with the code.

## For developers

Plugins that build on RedeemID (a course, a forum, a download manager) can ask it instead of running their own membership layer:

- `redeemid_can_read( $post, $user = null )`: can this user read the item in full, through every rule layer?
- `redeemid_user_levels( $user = null )`: the level slugs the user holds.
- `redeemid_has_feature( $slug, $user = null )`: does the user hold a [feature](docs/features.md)?
- `redeemid_preferences()` and `redeemid_set_preferences()`: the member's [preferences](docs/member-preferences.md), read from and saved to Shopify.

They read the synced mirror, so they cost no Shopify call. The `redeemid_event` action and the `window.redeemid` browser client carry every event (walls, the meter, checkouts, level changes) in one vendor-neutral shape, and [event webhooks](docs/event-webhooks.md) send them to a CRM or automation tool without code. The REST namespace is `redeemid/v1`, and WP-CLI commands live under `wp redeemid` (resync, access, settings). What is stable is listed in [docs/developer-api.md](docs/developer-api.md); its version is `REDEEMID_API_VERSION` (currently 1.4.0).

## Contributing

Issues and pull requests are welcome. Work branches from `main`, and pull requests go back into it.

```sh
composer install
composer lint     # php -l on every PHP file
composer phpcs    # WordPress VIP coding standards (phpcs.xml.dist)
composer phpstan  # Blockstudio checks: templates against block.json (phpstan.neon.dist)
composer test     # PHPUnit; no WordPress install needed
```

GitHub Actions runs these on PHP 8.2, 8.3 and 8.4 for every pull request. To release, bump `Version:` in `redeemid.php`, `REDEEMID_VERSION` and the `Stable tag` in `readme.txt` together. When that reaches `main`, GitHub Actions builds `redeemid-core.zip` (without the files in `.distignore`) and publishes release `v<version>`; a version with a suffix such as `0.1.0-beta.1` is a pre-release. Regenerate `languages/redeemid.pot` with `wp i18n make-pot . languages/redeemid.pot --exclude=vendor,tests` when strings change.

The rules every change follows:

- **Shopify is the source of truth for access.** WordPress never grants an entitlement Shopify doesn't hold. Tools that make access easier to manage from WordPress write to Shopify (for example, customer tags) and read the result back.
- **Fail closed.** When data is missing or unreadable, the visitor gets less access, not more.
- **Never log or return secrets or tokens**, and keep personal data and nonces out of cacheable HTML.
- **Check Blockstudio first.** Before writing code for a block, see whether [Blockstudio](https://blockstudio.dev/docs) already does it (conditional fields, populated pickers, islands, components, variations, overrides). Its docs are also in `vendor/blockstudio/blockstudio/docs` after `composer install`.

Blockstudio details that have bitten before:

- Selects return `{value, label}` and links `{url, ...}`; read them with `Gate::option()` and `Gate::url()`. Don't change a shipped field's type, because saved content stops carrying the value; add a new field instead.
- In a block template, `return;` tells Blockstudio the block rendered nothing, so it also skips the block's styles and scripts. Branches that print markup end with `if`/`else`.
- `populate` functions must be global: put a thin wrapper in `src/functions.php` and keep the logic in a class.
- Register blocks with `Blockstudio\Build::init()` (see `Blocks\Registration`), never the `blockstudio/path` filter.
- Leave Blockstudio's static prerendering off on gated sites, and never set `serveLoggedIn`.
- Deliberate exceptions, each explained in a code comment: the per-block Access panel (`assets/block-access.js`) instead of an extension; REST routes and `admin-post.php` forms instead of RPC, because RPC prints a nonce into cacheable HTML; WordPress cron and the plugin's own tables instead of `cron.php` or `db.php`.

The [demo theme](https://github.com/webmultipliers/redeemid-demo-theme) seeds a demo news site, test pages and test accounts that exercise every feature, which makes it a good companion for manual testing.

### Reporting a security issue

Please don't open a public issue for a vulnerability. Report it privately from the repository's **Security** tab (**Report a vulnerability**).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). Copyright Vinny S. Green, LLC. Blockstudio, bundled through Composer, is also GPL-2.0-or-later.
