# WP2 Shopify Identity

**Shopify acts as the Identity Provider while WordPress consumes the customer data.**

WP2 Shopify Identity treats Shopify Customer Accounts as the single source of truth for membership and community sites, letting your WordPress experience mirror the Shopify customer record in real time. The plugin relies on Shopify’s Customer Account API with OAuth2 PKCE so you can authenticate users at Shopify, then automatically log them into WordPress with synchronized customer metadata.

## 🚀 Feature overview

* **OAuth + session lifecycle:** The plugin manages the full PKCE handshake, validates state and nonce cookies, normalizes authentication tokens, and persists the session via strict, HTTP-only cookies. It also handles automatic token refreshes and caches customer payloads to ensure performance.
* **REST surface & webhook queue:** A secure REST API handles authentication status, refreshing, and logout, while a dedicated webhook endpoint validates incoming payloads using HMAC signatures. Events are queued and processed asynchronously to prevent performance bottlenecks.
* **Customer metadata sync:** Webhook handlers automatically populate WordPress user meta, capturing tags, marketing consent, purchasing summaries, default addresses, and verification flags. This data powers the admin interface and privacy export tools.
* **Admin & privacy helpers:** The plugin provides a settings page for configuration, encrypts sensitive secrets, and integrates directly with WordPress privacy tools. It also manages a custom user role for Shopify customers, ensuring they are properly restricted within the WordPress dashboard.
* **Frontend & shortcode UX:** A simple shortcode renders a status card that adapts to the user's state (guest, connected, or disconnected). Optimized JavaScript and CSS assets are only loaded when necessary, handling data synchronization and linking existing WordPress users to Shopify accounts.
* **Security & configuration:** The system enforces strict security practices by requiring authentication keys and managing configuration via environment constants. It also caches OpenID configuration and API endpoints to ensure resilience against temporary network issues.

## 🔐 Authentication (Log In with Shopify)

1. A guest clicks the identity button and is redirected to Shopify’s OAuth2 endpoint.
2. Shopify authenticates the user and returns them to your WordPress callback URL with an authorization code.
3. The plugin validates the security tokens, exchanges the code for an Access Token, and establishes a secure session cookie.
4. If the Shopify email matches an existing WP user, they are logged in; otherwise, a new **Subscriber** account is auto-created from the Shopify profile, linking the Shopify Global ID to a read-only "shadow" user in WordPress.
5. Frontend logic keeps the session alive, automatically refreshing tokens and managing the connection state.

## 🧭 Callback Page Template

The OAuth callback rewrites to a configurable slug (defaulting to `/auth-callback`), so you can create a published Page that matches that slug and style it using the block editor or your theme. The plugin automatically handles the rewrite rules, allowing your custom template to render with the standard site design.

If you need to host the callback under a different slug, you can override the filter in your theme and resave your permalinks:

```php
add_filter( 'wp2_shopify_auth_callback_slug', function () {
    return 'custom-login';
} );

```

## 🔄 Shopify → WordPress Customer Sync

Every login and webhook-driven update copies customer data into WordPress user meta so you can rely on Shopify data in your WP templates:

* **Shopify Global ID:** Establishes the link between the two platforms.
* **Tags:** Syncs VIP, wholesale, or other tags for content gating.
* **Marketing Consent:** Tracks Email and SMS opt-in status.
* **Purchasing Stats:** Stores lifetime spend and order counts for LTV metrics.
* **Profile Data:** Keeps names and contact details in sync.
* **Addresses:** Caches default and saved addresses for quick lookup.
* **Status & verification:** Mirrors account state and email verification status.

## 📡 Real-time Webhooks

The plugin exposes a secure endpoint so Shopify can notify WordPress about customer changes even when the user isn’t actively browsing your site. Signatures are validated using your shared secret, and events are queued to update specific metadata without slowing down the site.

**Supported topics:**

* Customer creation, updates, deletion, disabling, and enabling.
* Email and SMS marketing consent updates.
* Tag additions and removals.
* Customer group changes.
* Purchasing summary and account setting updates.

## 👤 Admin Interface

A **Shopify Identity** card appears on the WordPress user profile:

* Connection status and the linked Shopify Customer ID.
* A list of synchronized Shopify tags.
* Email and SMS marketing consent indicators.
* Lifetime value stats and sync timestamps.
* Controls to manually sync data or disconnect the account.

The plugin automatically hides these "shadow" users from the main user list, disables password resets (since Shopify manages credentials), and suppresses the admin bar for them on the frontend.

## 🧰 Identity Shortcode

Add `[wp2_shopify_identity]` to your frontend templates to render the identity card.

* **Guests** see a “Log In with Shopify” button.
* **Connected users** see their tags, consent status, and sync controls.
* **Logged-in but unlinked users** are prompted to connect their WordPress account to Shopify.

The markup uses clean classes, making it easy to style via your theme.

## ⚙️ Implementation opinions

* **Shopify as Source of Truth:** WordPress users are generated from Shopify data and kept read-only. Password resets are disabled, and these users are hidden from standard admin views to prevent management conflicts.
* **Asynchronous Processing:** Webhooks are handled via a queue system (preferring Action Scheduler) to ensure reliability and performance.
* **Configuration-as-Code:** Secrets and endpoints are best defined in `wp-config.php`. The plugin encrypts any secrets stored in the database and caches API discovery data to survive Shopify downtime.
* **Conditional Loading:** Frontend assets are only loaded when the shortcode is present or during the authentication callback, keeping your site fast.

## ⚙️ Configuration-as-Code

Sensitive Shopify credentials live in `wp-config.php`, not the database:

```php
// Required: The standard WP Auth Key (must be defined for encryption to work)
define( 'AUTH_KEY', 'put your unique phrase here' ); 

// Shopify Configuration
define( 'WP2_SHOPIFY_STORE_DOMAIN', 'your-shop.myshopify.com' );
define( 'WP2_SHOPIFY_CLIENT_ID', 'your_shopify_client_id' );
define( 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN', 'your_client_secret' ); // Used as Client Secret for PKCE

// Webhooks
define( 'WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET', 'your_webhook_signing_secret' );

// Optional Overrides
// define( 'WP2_SHOPIFY_OAUTH_AUTHORIZE_URL', '...' );
// define( 'WP2_SHOPIFY_OAUTH_TOKEN_URL', '...' );

```

After adding the constants, visit **Settings → Shopify Identity** in the WordPress Admin to verify connectivity.

## 📡 Webhooks Setup

Make sure Shopify can POST to your REST endpoint:

**Endpoint:** `https://your-wordpress-site.com/wp-json/wp2-shopify/v1/iwebhooks/events`

Deliver the same webhook signing secret that you set in `wp-config.php`, and include the topics listed above.

## 📋 Requirements

* WordPress 6.0+
* PHP 7.4+
* Composer
* Node.js & NPM (for building assets)
* A Shopify Store with Customer Accounts API access.

## 🛠 Installation

### 1. Clone & Build

```bash
git clone https://github.com/webmultipliers/wp2-shopify-id.git
cd wp2-shopify-id

# Install PHP Dependencies
composer install --no-dev --optimize-autoloader

# Install & Build JS/CSS Assets
npm install
npm run build

```

### 2. Activate

Upload the folder to your `wp-content/plugins` directory and activate **WP2 Shopify Identity** via the WordPress Admin.