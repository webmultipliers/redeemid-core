# Usage & Integration Guide

This guide covers how to utilize the WP2 Shopify Identity plugin for frontend implementation and how to configure the necessary Shopify App settings.

## 1. Shopify App Configuration

Before the plugin can function, you must configure a Customer Account API app within Shopify.

1.  Log in to your **Shopify Partners Dashboard** or Store Admin.
2.  Create a custom app or sales channel.
3.  **API Scopes:** Ensure the app has the following scopes:
    * `openid`
    * `email`
    * `customer-account-api:full`
4.  **Callback URIs:** In the Customer Account API settings, add your WordPress auth callback URL:
    * `https://your-site.com/auth-callback`
    * *Note: You can verify the exact URL expected by the plugin in **Settings > Shopify Identity**.*
5.  **Javascript Origins:** Add your WordPress site URL:
    * `https://your-site.com`
6.  **Logout URI:** Add the logout endpoint:
    * `https://your-site.com/wp-login.php?action=logout`

## 2. Frontend Implementation

### The Identity Shortcode
The easiest way to expose functionality to users is via the built-in shortcode.

```html
[wp2_shopify_identity]
```

**Behavior:**

  * **Guest User:** Displays a "Log In with Shopify" button. Clicking this initiates the PKCE flow.
  * **Logged In User (Connected):** Displays an "Identity Card" showing the Shopify Customer ID, Tags, and buttons to Sync Data or Disconnect.
  * **Logged In User (Not Connected):** Displays a "Connect Shopify Account" button to link the existing WP User to a Shopify Customer.

### CSS Customization

The plugin uses BEM-style CSS classes. You can override styles in your theme's CSS.

  * Wrapper: `.wp2-identity-card`
  * Header: `.wp2-id-header`
  * Buttons: `.wp2-btn-connect`, `.wp2-btn-disconnect`, `.wp2-btn-sync`

## 3. The Authentication Flow (PKCE)

1.  **Initiation:** When a user clicks "Log In", the `Auth` module (`assets/scripts/modules/auth.js`) generates a cryptographic **Verifier** and **Challenge**.
2.  **Redirect:** The user is redirected to Shopify to log in.
3.  **Callback:** Shopify redirects back to `/auth-callback` with a `code`.
4.  **Exchange:** The plugin handles the callback, verifies the state/nonce, and exchanges the code for an **Access Token** via the backend (`src/Auth/REST.php`).
5.  **Session:** * If the email matches an existing WP user, they are logged in.
      * If no user exists, a new subscriber is created.
      * A secure HTTP-only cookie `wp2_shopify_token` is set.

### Callback Page Template

The callback redirect now rewrites to the slug defined by `wp2_shopify_auth_callback_slug` (defaults to `/auth-callback`). Create a published Page using the same slug so you can control layout via the block editor, and call the rewrite-friendly filter if you prefer a different path:

```php
add_filter( 'wp2_shopify_auth_callback_slug', function () {
	return 'custom-login';
} );
```

After changing the slug, resave **Settings → Permalinks** so WordPress regenerates the rewrite rule.

## 4. Admin Features

### User Profile Stats

Navigate to **Users > All Users** and click "Edit" on a user. Scroll down to find the **Shopify Identity** section.

**Available Data:**

  * **Connection Status:** Linked / Not Connected.
  * **Tags:** Real-time tags synced from Shopify.
  * **Marketing Consent:** Email and SMS consent states.
  * **Lifetime Value:** Total spent and total order count.
  * **Last Synced:** Timestamp of the last data refresh.

### Verifying Configuration

Go to **Settings > Shopify Identity**.
This page acts as a diagnostic tool. It checks:

1.  Presence of `wp-config.php` constants.
2.  Connectivity to the Shopify OpenID configuration endpoint.
3.  Validity of Client credentials.

## 5. Troubleshooting

**"Session expired" or 403 Errors**

  * Ensure `WP2_SHOPIFY_STORE_DOMAIN` allows the Javascript Origin of your WP site.
  * Check that your server clock is synced (NTP), as JWT validation relies on timestamps.

**Webhook Failures**

  * Verify `WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET` matches the secret provided in the Shopify Admin > Notifications section.
  * Ensure your site is accessible publicly (not behind a password or localhost) so Shopify can reach the endpoint.

**Styling Issues**

  * The plugin compiles SCSS using Vite. If styles are missing, ensure `wp_footer()` is present in your theme, as the scripts inject styles dynamically in some contexts.
