<?php

namespace WP2_Shopify\Shopify;

use WP2_Shopify\Config;

/**
 * Cached OpenID and Customer Account API discovery for the configured store.
 * A last-good copy survives Shopify outages; explicit endpoint constants are
 * configuration overrides, not a substitute for discovery.
 */
final class Discovery {
	private const CACHE      = 'wp2_shopify_discovery';
	private const LAST_GOOD  = 'wp2_shopify_discovery_last_good';
	private const LAST_FETCH = 'wp2_shopify_discovery_fetched_at';

	public static function openid(): array {
		$cached = get_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$domain = Config::store_domain();

		if ( '' === $domain ) {
			return [];
		}

		$openid = Http::request( 'GET', 'https://' . $domain . '/.well-known/openid-configuration', [], null, 'discovery.openid', 10 );
		$api    = Http::request( 'GET', 'https://' . $domain . '/.well-known/customer-account-api', [], null, 'discovery.customer_api', 10 );

		if ( is_wp_error( $openid ) || empty( $openid['authorization_endpoint'] ) ) {
			$last = get_option( self::LAST_GOOD );

			// Short negative cache so an outage does not turn every page view into a remote call.
			set_transient( self::CACHE, is_array( $last ) ? $last : [], 5 * MINUTE_IN_SECONDS );

			return is_array( $last ) ? $last : [];
		}

		$config = [
			'issuer'                 => (string) ( $openid['issuer'] ?? '' ),
			'authorization_endpoint' => (string) $openid['authorization_endpoint'],
			'token_endpoint'         => (string) ( $openid['token_endpoint'] ?? '' ),
			'end_session_endpoint'   => (string) ( $openid['end_session_endpoint'] ?? '' ),
			'graphql_api'            => is_array( $api ) ? (string) ( $api['graphql_api'] ?? '' ) : '',
		];

		set_transient( self::CACHE, $config, HOUR_IN_SECONDS );
		update_option( self::LAST_GOOD, $config, false );
		update_option( self::LAST_FETCH, time(), false );

		return $config;
	}

	public static function endpoint( string $key ): string {
		$overrides = [
			'authorization_endpoint' => 'WP2_SHOPIFY_OAUTH_AUTHORIZE_URL',
			'token_endpoint'         => 'WP2_SHOPIFY_OAUTH_TOKEN_URL',
			'end_session_endpoint'   => 'WP2_SHOPIFY_OAUTH_LOGOUT_URL',
		];

		if ( isset( $overrides[ $key ] ) && Config::has( $overrides[ $key ] ) ) {
			return esc_url_raw( Config::get( $overrides[ $key ] ) );
		}

		$value = self::openid()[ $key ] ?? '';

		if ( 'graphql_api' === $key ) {
			$value = '' === $value && '' !== Config::store_domain()
				// Documented default shape; versioned with the pinned API version.
				? 'https://' . Config::store_domain() . '/customer/api/' . Config::api_version() . '/graphql'
				: self::pin_version( (string) $value, Config::api_version() );
		}

		return esc_url_raw( (string) $value );
	}

	/**
	 * Discovery returns the Customer Account API URL with Shopify's latest
	 * stable version in it, which moves every quarter. Shopify's docs say to
	 * build the versioned URL from the discovered one when a specific version
	 * is needed, so queries keep the version they were written against.
	 */
	public static function pin_version( string $url, string $version ): string {
		return (string) preg_replace( '#/customer/api/\d{4}-\d{2}/graphql$#', '/customer/api/' . $version . '/graphql', $url );
	}

	public static function available(): bool {
		return '' !== self::endpoint( 'authorization_endpoint' ) && '' !== self::endpoint( 'token_endpoint' );
	}

	public static function last_fetched(): int {
		return (int) get_option( self::LAST_FETCH, 0 );
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
	}
}
