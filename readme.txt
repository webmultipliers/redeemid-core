=== RedeemID – Shopify Customer Login & Memberships ===
Contributors: vinnysgreen
Donate link: https://github.com/sponsors/vinnysgreen
Tags: shopify, membership, paywall, restrict content, login
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.0.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Customers sign in to WordPress with their Shopify customer account, and what that account holds in Shopify opens members-only content.

== Description ==

RedeemID uses your Shopify store as the membership system for a WordPress site. Customers sign in to WordPress with the Shopify customer account they already use in your store. What that account holds in Shopify, such as the tags Shopify Flow or a subscription app sets, decides what they can read and use on WordPress.

Shopify stays the single source of truth. RedeemID links each Shopify customer account to a WordPress user and mirrors it there: name, email, account status and tags. Every access decision is made on the server from that mirror. A customer disabled in Shopify loses members-only access, and WordPress never grants access that Shopify doesn't know about.

RedeemID is free and open source. There is no paid version.

= Sign in with Shopify =

* A **Log in with Shopify** block: OAuth with PKCE against Shopify's Customer Account API.
* New customers get a WordPress user tied to their Shopify account. Existing WordPress accounts can be linked, after a verified-email check. Staff can link a member whose emails differ, and the account is flagged until they clear it.
* An optional setting makes linked members sign in with Shopify only, with no WordPress password.
* Sign-out ends both sessions.
* Use it for sign-in alone, with no levels and nothing gated.

= Memberships from Shopify =

* Shopify Flow, or your subscription or membership app, tags customers. Renewals, cancellations and fixed terms stay in Shopify.
* Shopify's webhooks bring tag changes to WordPress within seconds.
* Named access levels point at tags, so a change of plan is one edit.

= Gate content =

* A **Membership Gate** block, a **Paywall Break** block, and an **Access** panel on every block.
* Access rules for a post, a category or tag, a whole post type, or the site.
* Locked content is removed on the server, so it never reaches the HTML, feeds, the REST API, search excerpts or the descriptions Yoast SEO, Rank Math and The SEO Framework print.
* Sell one post on its own: a customer tag set by Shopify Flow opens it, and locked readers see a button to buy it.
* A signed-in meter, protected file downloads, and site features such as ad-free reading.

= Member accounts =

* A **Member Account** card with levels, dates, features and links to the Shopify account.
* A **Member Preferences** form for the customer metafields you choose, which members see or change and which are saved on the Shopify customer. Guests can answer first and confirm after they sign in.
* Download my data and Delete my data, through WordPress's own privacy tools.

= Built for the block editor =

Every piece a visitor sees is a block: Shopify Sign-in, Membership Gate, Paywall Break, Access Badge, Member Account, Member Preferences, Membership Features, Refresh Membership, Shopify Account Status, Shopify Plan Card, Shopify Product, Shopify Collection, Shopify Cart and Shopify Cart Link. All of them render on the server and work without JavaScript. Personal pieces are dynamic islands, so pages stay cacheable behind a page cache or CDN.

= In your own words =

* **RedeemID → Text** lists every string the plugin shows, to visitors, members or staff, and lets you replace any of them: "Your account" can read "Your members area".
* Swap a word everywhere at once: "account" to "portal" changes every string that has it, with the capital letters kept.
* Replacements sit on top of translation, and can be set for every language or for one.

= Sell from WordPress =

* Plan cards, products and collections with live Shopify prices.
* A server-held cart that checks out on Shopify, signed in.
* Every cart records the page the purchase started on, so the Shopify order shows where the sale came from.
* Works beside Shopify's own WordPress plugin, or without it.

= For developers =

A PHP API, REST routes, WP-CLI commands, an event stream with a vendor-neutral browser client, signed event webhooks for CRMs and automation tools, and WordPress Abilities for AI agents through the MCP Adapter. Built on [Blockstudio](https://blockstudio.dev), tested with PHPUnit and leak tests on a running WordPress, and checked on every change against the WordPress VIP coding standards, axe-core accessibility (WCAG 2.2 AA), Lighthouse, Psalm taint analysis and CodeQL.

= Documentation =

* [Documentation](https://www.redeemid.com/docs/)
* [Guides](https://www.redeemid.com/guides/)
* [Live demo](https://demo.redeemid.com/)
* [Source code](https://github.com/webmultipliers/redeemid-core)

RedeemID is in early development. Expect breaking changes before 1.0.0.

== Installation ==

1. Download `redeemid-core.zip` from the [latest release](https://github.com/webmultipliers/redeemid-core/releases/latest), then upload it in **Plugins → Add New → Upload Plugin** and activate it.
2. Turn on pretty permalinks (**Settings → Permalinks**). Shopify sign-in returns to `/auth-callback/`, which needs them.
3. **RedeemID → Setup** opens on activation and walks through connecting your Shopify store, with a link to each Shopify screen it needs. When the connection test passes, it publishes the sign-in callback page and registers Shopify's webhooks for you.

You need PHP 8.2 or later with `sodium`, WordPress 6.7 or later, and a Shopify store on the new customer accounts, with a headless or custom app that has Customer Account API access. On multisite, each site connects its own Shopify store. See [Install](https://www.redeemid.com/docs/install/) and [Connect Shopify](https://www.redeemid.com/docs/connect/).

== Frequently Asked Questions ==

= Is RedeemID free? =

Yes. It is GPL software with no paid version.

= Does RedeemID tag customers or sell memberships by itself? =

No. Shopify Flow, a subscription app or a person in the Shopify admin tags customers, and RedeemID mirrors those tags. Checkout always happens in Shopify.

= Do I need WooCommerce? =

No. Shopify runs the store. RedeemID shows Shopify products on WordPress and sends customers to Shopify checkout.

= Does it work with page caches? =

Yes. Personal pieces load after the page in one uncached request, so the page itself stays cacheable. See [Works with](https://www.redeemid.com/docs/compatibility/).

= Does it work with SEO plugins? =

Yes. With Yoast SEO, Rank Math or The SEO Framework, locked posts get teaser-only descriptions and paywall structured data, and locked posts you keep out of search are left out of their sitemaps. See [Works with](https://www.redeemid.com/docs/compatibility/).

= Can I change the wording? =

Yes. RedeemID → Text replaces any string the plugin shows, or swaps a word in all of them, without a translation file. See [Replacing text](https://www.redeemid.com/docs/replace-text/).

= Where is my customers' data? =

In Shopify. WordPress keeps a mirror of each linked customer's name, email, status and tags. WordPress's own Export and Erase Personal Data tools cover it, and Shopify's privacy webhooks are handled. See [Privacy](https://www.redeemid.com/docs/privacy/).

== Changelog ==

The full changelog is at [redeemid.com/changelog](https://www.redeemid.com/changelog/), and every release is on [GitHub](https://github.com/webmultipliers/redeemid-core/releases).
