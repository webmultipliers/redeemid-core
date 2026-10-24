# Working with Shopify's WordPress plugin

Shopify publishes its own WordPress plugin for the Sell on WordPress sales channel (version 1.0.0 at the time of writing). This page explains how WP2 Shopify divides the work with it, and the trade-offs that come with markup WP2 doesn't control. The [README](../README.md#shopifys-wordpress-plugin) covers the settings and behaviour.

## Who does what

WP2 treats Shopify's plugin as an optional companion storefront, not a competitor and not a dependency.

- **Shopify's plugin owns the anonymous storefront** when installed: product and collection blocks, `/products/*`, `/collections/*` and `/cart`, built on Storefront Web Components.
- **WP2 owns identity and access**: sign-in, account linking, the membership mirror, gates, webhooks and the path from purchase to membership.
- **WP2 works the same without it.** Plan cards and buy-now are WP2's own purchase path and need no browser cart.

Because of this split, WP2 ships no product grid, collection or cart blocks. A site that wants a catalog installs Shopify's plugin. Instead of a cart of its own, WP2 signs Shopify's cart in for a signed-in customer (`Commerce\CartBridge`, `POST /cart/identity`), so checkout opens as that customer and the purchase unlocks content.

## Trade-offs where Shopify's plugin renders

Everything WP2 renders follows the rules below. They are relaxed only for markup Shopify's plugin produces, because WP2 can't change it.

| WP2's rule | Shopify's plugin | Why it is acceptable |
|---|---|---|
| Commerce markup renders on the server | Web Components render in the browser | WP2's own blocks (plan cards, gates) still render on the server, and a gate removes Shopify's blocks for non-members before the page is sent |
| Purchases work without JavaScript and by keyboard | The cart needs JavaScript; the 1.0.0 cart toggle can't be reached by keyboard | Buy-now on plan cards works without JavaScript. WP2 gives the toggle a button role and a tab stop (`Integrations\OfficialCartToggle`) |
| No cart IDs in browser storage | The cart ID lives in the browser's `localStorage` | The bridge sends it to the server only to attach the customer, and WP2 stores no cart IDs |

## Guarantees

- WP2 never reads the Storefront token of Shopify's plugin (`shopify_api_key`). It reads only `shopify_store_url`, to compare stores.
- The customer access token goes from WP2's server to Shopify and nowhere else.
- If Shopify's plugin is connected to a different store, Overview shows an error and the bridge stays off.
- If Shopify changes the undocumented `__shopify:cartId` key, the bridge stops attaching and the cart keeps working as a guest cart. Debug shows the last attach and the last failure.

## Known limits

- With Shopify's plugin 1.0.0, its `/cart` page falls back to a shortcode that doesn't exist. Add a theme template for `/cart` if your theme needs one.
- A visitor who adds to the cart and reaches checkout within a second or two may get there before the attach finishes, and check out as a guest.
