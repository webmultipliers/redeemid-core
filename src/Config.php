<?php

namespace WP2_Shopify;

use WP2_Shopify\Support\ConfigValues;
use WP2_Shopify\Support\Crypto;

/**
 * The plugin's configuration. Each value comes from a
 * wp-config.php constant when one is defined, and that always wins. Otherwise
 * it comes from Shopify → Settings, saved in the options table, with secrets
 * encrypted at rest (Support\Crypto, keyed from WP2_SHOPIFY_ENCRYPTION_KEY or
 * the WordPress salts). Secret values are never shown back in the admin.
 */
final class Config {
	/** Values saved from Shopify → Settings. Secrets are stored as ciphertext. */
	public const OPTION = 'wp2_shopify_config';

	private static ?ConfigValues $values = null;

	/** Pinned Shopify API version for every surface (Storefront, Customer Account, Admin, webhooks). */
	public const API_VERSION = '2026-10';

	/** Customer Account API scopes requested at sign-in. */
	public const SCOPES = 'openid email customer-account-api:full';

	/**
	 * Every value the plugin reads, for Settings and Debug. This is the whole
	 * configuration surface: anything not listed here is not read. Each can be
	 * a wp-config.php constant of that name or, unless `editable` is false, a
	 * value saved in Shopify → Settings.
	 *
	 * @return array<string, array{label: string, secret: bool, required: bool, help: string, editable?: bool}>
	 */
	public static function definitions(): array {
		return [
			'WP2_SHOPIFY_STORE_DOMAIN'        => [
				'label'    => 'Store domain',
				'secret'   => false,
				'required' => true,
				'help'     => 'The store\'s *.myshopify.com domain.',
			],
			'WP2_SHOPIFY_CLIENT_ID'           => [
				'label'    => 'Customer Account API client ID',
				'secret'   => false,
				'required' => true,
				'help'     => 'From the Headless (or custom) sales channel\'s Customer Account API settings. Powers sign-in.',
			],
			'WP2_SHOPIFY_CLIENT_SECRET'       => [
				'label'    => 'Customer Account API client secret',
				'secret'   => true,
				'required' => false,
				'help'     => 'Only for confidential clients; leave unset for a public client.',
			],
			'WP2_SHOPIFY_ADMIN_CLIENT_ID'     => [
				'label'    => 'Admin app client ID',
				'secret'   => false,
				'required' => true,
				'help'     => 'The Dev Dashboard app installed on the store. The plugin requests 24-hour Admin tokens with the client credentials grant and renews them itself. Scopes: read_customers, write_customers. Powers reconciliation, resync, tag editing and webhook registration.',
			],
			'WP2_SHOPIFY_ADMIN_CLIENT_SECRET' => [
				'label'    => 'Admin app client secret',
				'secret'   => true,
				'required' => true,
				'help'     => 'The Dev Dashboard app\'s client secret. Also verifies the webhooks the plugin registers.',
			],
			'WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN' => [
				'label'    => 'Storefront public access token',
				'secret'   => false,
				'required' => false,
				'help'     => 'From the Headless sales channel\'s Storefront API settings. Powers plan cards and buy-now: live prices and checkout. Public by design; WP2 never borrows the token Shopify\'s own plugin stores.',
			],
			'WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN' => [
				'label'    => 'Storefront private access token',
				'secret'   => true,
				'required' => false,
				'help'     => 'Recommended. From the same Headless channel. The plugin calls the Storefront API from the server, which Shopify wants done with the private token plus the buyer\'s IP, so checkout creation is not throttled as if every visitor were one IP. When set it replaces the public token for every server call.',
			],
			'WP2_SHOPIFY_ENCRYPTION_KEY'      => [
				'label'    => 'Token encryption key',
				'secret'   => true,
				'required' => false,
				'editable' => false,
				'help'     => 'Optional 32-byte key, base64 encoded. Encrypts stored tokens and the secrets saved in Settings. When missing, a key is derived from the WordPress salts; set it if the salts get rotated. Changing either means re-entering the secrets saved in Settings. Only a wp-config.php constant: a key kept in the database next to what it encrypts would protect nothing.',
			],
		];
	}

	/**
	 * Constants earlier builds read and this one ignores, with what replaced them.
	 * The Debug page warns when one is still defined.
	 *
	 * @return array<string, string>
	 */
	public static function retired(): array {
		return [
			'WP2_SHOPIFY_ADMIN_ACCESS_TOKEN'       => 'Legacy permanent Admin tokens are no longer used. Set WP2_SHOPIFY_ADMIN_CLIENT_ID and WP2_SHOPIFY_ADMIN_CLIENT_SECRET for a Dev Dashboard app.',
			'WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET'   => 'Webhooks are registered by the plugin through the app and verified with WP2_SHOPIFY_ADMIN_CLIENT_SECRET. Delete webhooks created in Settings → Notifications.',
			'WP2_SHOPIFY_API_VERSION'              => 'The API version is pinned to ' . self::API_VERSION . ' by the plugin.',
			'WP2_SHOPIFY_COOKIE_DOMAIN'            => 'The plugin uses WordPress\' COOKIE_DOMAIN.',
		];
	}

	public static function get( string $name ): string {
		return self::values()->resolve( $name )['value'];
	}

	/**
	 * Where a value comes from: ConfigValues::CONSTANT, SETTINGS,
	 * UNREADABLE (saved, but the encryption key changed) or NONE.
	 */
	public static function source( string $name ): string {
		return self::values()->resolve( $name )['source'];
	}

	/**
	 * A wp-config.php constant sets this value, so Settings cannot edit it.
	 */
	public static function locked( string $name ): bool {
		return self::values()->locked( $name );
	}

	public static function values(): ConfigValues {
		if ( ! self::$values ) {
			$stored       = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
			self::$values = new ConfigValues(
				self::definitions(),
				self::constant( ... ),
				is_array( $stored ) ? array_map( 'strval', array_filter( $stored, 'is_scalar' ) ) : [],
				static function (): ?Crypto {
					try {
						return Plugin::crypto();
					} catch ( \InvalidArgumentException ) {
						return null;
					}
				}
			);
		}

		return self::$values;
	}

	/**
	 * Read the option again, after Settings saved it.
	 */
	public static function reset(): void {
		self::$values = null;
	}

	/**
	 * The constant that sets a value, if one is defined (including the 1.x alias).
	 */
	public static function constant_name( string $name ): ?string {
		if ( defined( $name ) ) {
			return $name;
		}

		if ( 'WP2_SHOPIFY_CLIENT_SECRET' === $name && defined( 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN' ) ) {
			return 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN';
		}

		return null;
	}

	private static function constant( string $name ): ?string {
		$constant = self::constant_name( $name );

		return null === $constant ? null : (string) constant( $constant );
	}

	/**
	 * The key Shopify signs webhooks with: webhooks registered by a Dev Dashboard
	 * app are signed with that app's client secret.
	 */
	public static function webhook_secret(): string {
		return self::get( 'WP2_SHOPIFY_ADMIN_CLIENT_SECRET' );
	}

	public static function has( string $name ): bool {
		return '' !== self::get( $name );
	}

	public static function uses_deprecated_secret_name(): bool {
		return ! defined( 'WP2_SHOPIFY_CLIENT_SECRET' ) && defined( 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN' );
	}

	public static function api_version(): string {
		return self::API_VERSION;
	}

	/**
	 * The store's myshopify host, normalised (no scheme, no path, lower case).
	 */
	public static function store_domain(): string {
		$domain = strtolower( self::get( 'WP2_SHOPIFY_STORE_DOMAIN' ) );
		$domain = preg_replace( '#^https?://#', '', $domain );

		return rtrim( (string) $domain, '/' );
	}

	/**
	 * Cookie domain for the plugin's own cookies. Never derived from the
	 * request's Host header (POC defect: HTTP_HOST is client-controlled).
	 */
	public static function cookie_domain(): string {
		return defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '';
	}

	/**
	 * A page in the store's Shopify admin, e.g. admin_url( 'customers/123' ).
	 */
	public static function admin_url( string $path = '' ): string {
		$handle = preg_replace( '/\.myshopify\.com$/', '', self::store_domain() );

		return '' === $handle ? '' : 'https://admin.shopify.com/store/' . rawurlencode( (string) $handle ) . '/' . ltrim( $path, '/' );
	}

	public static function callback_slug(): string {
		$slug = trim( (string) apply_filters( 'wp2_shopify_auth_callback_slug', 'auth-callback' ), '/' );

		return '' === $slug ? 'auth-callback' : $slug;
	}

	public static function callback_url(): string {
		return home_url( '/' . self::callback_slug() . '/' );
	}
}
