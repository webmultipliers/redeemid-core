# Replacing Shopify's WordPress plugin

Shopify publishes its own WordPress plugin for the Sell on WordPress sales channel. Version 1.0.0 (built 2026-03-20) is the only release, it is not on wordpress.org, and it is named in a published security advisory (CVE-2026-57748, local file inclusion, versions up to and including 1.0.0) with no fixed release. RedeemID covers everything it does, so a site can remove it and lose nothing, and runs beside it without conflict until then. The [README](../README.md#shopifys-wordpress-plugin) covers the settings and behaviour.

## What it does, and what covers it

| Shopify's plugin | RedeemID | Status |
|---|---|---|
| Connect with an install code (store URL and public Storefront token) | Settings and the setup wizard: store domain, Storefront private or public token, Admin app, sign-in client | Covered |
| Product block | **Shopify Product** (`redeemid/product`): live price, options, quantity, buy-now, no JavaScript | Covered |
| Collection block with pagination | **Shopify Collection** (`redeemid/collection`): server-rendered grid, link-based pages | Covered |
| Membership or plan purchase | **Shopify Plan Card** (`redeemid/plan-card`), member-aware, selling plans | Covered (RedeemID only) |
| Cart toggle block, cart drawer, discount code field | **Shopify Cart** (`redeemid/cart`) and **Shopify Cart Link** (`redeemid/cart-link`): a server-held cart with quantities, discount code and signed-in checkout, no JavaScript | Covered |
| `/cart`, `/products/{handle}`, `/collections/{handle}` pages | The same paths by default, or any paths the site sets, as editable templates | Covered |
| SEO title, description and social tags on those pages | The same, plus canonical links and Product structured data, deferring to Yoast SEO or Rank Math | Covered |
| Admin bar links to Shopify products and collections | One staff-only Shopify admin node | Planned |
| Pageview and install analytics sent to Shopify | Not replicated; RedeemID keeps its own local stats | Not planned |

## Running both

Until a site removes Shopify's plugin, RedeemID follows these rules so the two never fight:

- **Names.** RedeemID's blocks are `redeemid/*`, its options `redeemid_*`, its CSS `.redeemid-*` and its query vars `redeemid_*`. Shopify's plugin uses `shopify-product/*`, `shopify-collection/*`, `create-block/*`, `shopify_*` options and `shopify_*` query vars.
- **No web components.** RedeemID renders on the server and prints no `<shopify-store>`, `<shopify-cart>` or other Storefront Web Components, so nothing collides with the ones Shopify's plugin prints on every page.
- **URLs.** Shopify's plugin prepends its rewrite rules for `/cart` (always) and `/products/*` and `/collections/*` (unless turned off). RedeemID uses the same paths by default, so links survive the switch. By default it stands down on any path Shopify's plugin has claimed. A site can give RedeemID other paths (Settings → Storefront pages), and then the two never meet, or turn on **RedeemID takes precedence**: RedeemID then adds its rules after Shopify's plugin has added its own, so RedeemID's win, through every permalink flush. Health shows who answers each path.
- **One cart.** While Shopify's plugin answers the cart path, RedeemID's cart and cart link switch off and RedeemID keeps signing that plugin's cart in for linked customers (`Commerce\CartBridge`, `POST /cart/identity`). When RedeemID answers it, the bridge switches off. The toggle keyboard fix switches off when the plugin is gone.
- **Credentials.** RedeemID never reads its Storefront token (`shopify_api_key`). It reads only `shopify_store_url`, to check both plugins use the same store; a different store is an error on Overview.

## Moving off it

1. Publish the products and collections your pages show to the sales channel of RedeemID's Storefront token (usually Headless). Shopify's plugin reads the Sell on WordPress channel, so a product published only there shows as unavailable in RedeemID's blocks.
2. Replace its product, collection and cart toggle blocks with Shopify Product, Shopify Collection and Shopify Cart Link.
3. Deactivate Shopify's plugin, then save Settings → Permalinks once so its rewrite rules are dropped. RedeemID's pages take over the same paths. To switch before removing it, turn on **RedeemID takes precedence**.

## Trade-offs while it is active

Everything RedeemID renders follows the rules below. They are relaxed only for markup Shopify's plugin produces, because RedeemID can't change it.

| RedeemID's rule | Shopify's plugin | Why it is acceptable |
|---|---|---|
| Commerce markup renders on the server | Web Components render in the browser | RedeemID's own blocks still render on the server, and a gate removes Shopify's blocks for non-members before the page is sent |
| Purchases work without JavaScript and by keyboard | The cart needs JavaScript; the 1.0.0 cart toggle can't be reached by keyboard | Buy-now works without JavaScript. RedeemID gives the toggle a button role and a tab stop (`Integrations\OfficialCartToggle`) |
| No cart IDs in browser storage | The cart ID lives in the browser's `localStorage` | The bridge sends it to the server only to attach the customer, and RedeemID stores no cart IDs |

## Known limits

- With Shopify's plugin 1.0.0, its `/cart` page falls back to a shortcode that doesn't exist. Add a theme template for `/cart` if your theme needs one.
- A visitor who adds to its cart and reaches checkout within a second or two may get there before the attach finishes, and check out as a guest.
- If Shopify changes the undocumented `__shopify:cartId` key, the bridge stops attaching and the cart keeps working as a guest cart. Debug shows the last attach and the last failure.
