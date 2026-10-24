# Shopify's WordPress plugin: decision record

Date: 2026-10-23. Covers Shopify's official plugin 1.0.0 (Sell on WordPress channel). Audit and spike notes are in the project review doc.

## Decision

WP2 treats Shopify's plugin as an optional companion storefront, not a competitor and not a dependency.

- **Official owns the anonymous storefront** when installed: product and collection blocks, `/products/*`, `/collections/*` and `/cart`.
- **WP2 owns identity and access**: sign-in, linking, the membership mirror, gates, webhooks and the purchase-to-membership path.
- **WP2 works the same without it.** Plan cards and buy-now are WP2's own purchase path, and need no browser cart.

## What changes in the plan

- **Phase 1 catalog blocks are cut.** WP2 ships no product grid, collection or cart blocks. A site that wants a catalog installs Shopify's plugin. Phase 1 keeps the plan card and server buy-now (PR #6).
- **Phase 2 cart becomes a bridge.** Instead of building its own cart, WP2 signs the official cart in (`Commerce\CartBridge`, `POST /cart/identity`).

## Spec rules relaxed, only where official renders

These rules stay in force for everything WP2 renders. They are relaxed only for markup that Shopify's plugin produces, because WP2 can't change it.

| Spec rule | Official plugin | Accepted because |
|---|---|---|
| 9: server-rendered commerce markup | Web Components render in the browser | WP2's own blocks (plan card, gates) still render on the server, and gates hide official blocks from non-members on the server |
| 10.3 / 15.3: no-JS cart and keyboard access | Cart needs JavaScript; the 1.0.0 cart toggle is not keyboard-reachable | Buy-now on plan cards works without JavaScript. WP2 gives the toggle a button role and a tab stop (`Integrations\OfficialCartToggle`) |
| 4.2 / 12: cart ID held on the server | Cart ID lives in the browser's `localStorage` | The bridge sends it to the server only to attach identity, and WP2 stores no cart IDs |

## Guarantees WP2 keeps

- WP2 never reads the official plugin's Storefront token (`shopify_api_key`). It reads only `shopify_store_url` to compare stores.
- The customer access token goes from WP2's server to Shopify only.
- If the official plugin is connected to a different store, Overview shows an error and the bridge stays off.
- If Shopify changes the undocumented `__shopify:cartId` key, the bridge stops attaching and the cart keeps working as a guest cart. Debug shows the last attach and the last failure.

## Still open

- Live check of the bridge against a cart with products in it. On the sandbox store, no products are published to the Sell on WordPress channel yet.
- Whether the official plugin's `/cart` page should be replaced by a theme template. It currently falls back to a shortcode that doesn't exist.
