<?php
namespace WP2_Shopify\Auth;

use League\OAuth2\Client\Provider\GenericProvider;
use WP_Error;
use WP2_Shopify\Auth\Service;

class PKCE {

	private static $provider;

	/**
	 * Initializes the OAuth2 provider with client details, using admin settings as fallback.
	 */
	public static function init_provider(): void {
		if ( ! self::$provider ) {
			$client_id     = defined( 'WP2_SHOPIFY_CLIENT_ID' ) ? WP2_SHOPIFY_CLIENT_ID : get_option( 'wp2_shopify_client_id' );
			$client_secret = '';

			if ( defined( 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN' ) ) {
				$client_secret = WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN;
			} else {
				$encrypted_secret =
					get_option( 'wp2_shopify_app_secret' ) ?: get_option( 'wp2_shopify_private_access_token' );
				$client_secret    = \WP2_Shopify\Core\Settings::decrypt_secret( $encrypted_secret );
			}
			$redirect_uri = Service::get_redirect_uri(); // Use dynamic redirect URI from Service class
			$authorization_endpoint = Service::get_authorization_endpoint();
			$token_endpoint = Service::get_token_endpoint();

			if ( empty( $client_id ) || empty( $client_secret ) || empty( $redirect_uri ) ) {
				throw new \RuntimeException( 'Shopify OAuth2 credentials are not configured.' );
			}

			if ( '' === $authorization_endpoint || '' === $token_endpoint ) {
				throw new \RuntimeException( 'Shopify OAuth2 endpoints are not configured.' );
			}

			self::$provider = new GenericProvider( [
				'clientId'                => $client_id,
				'clientSecret'            => $client_secret,
				'redirectUri'             => $redirect_uri,
				'urlAuthorize'            => $authorization_endpoint,
				'urlAccessToken'          => $token_endpoint,
				'urlResourceOwnerDetails' => defined( 'WP2_SHOPIFY_STORE_DOMAIN' ) ? WP2_SHOPIFY_STORE_DOMAIN . '/resource' : get_option( 'wp2_shopify_resource_url' ),
			] );
		}
	}

	/**
	 * Generates a high-entropy, URL-safe random string (nonce).
	 */
	public static function generate_nonce( int $length = 32 ): string {
		if ( $length < 16 ) {
			throw new \InvalidArgumentException( 'Nonce length must be at least 16 characters.' );
		}
		try {
			// Generate a random string and encode it to be URL-safe (same as verifier/challenge).
			$string = bin2hex( random_bytes( $length / 2 ) );
			return self::base64_url_encode( pack( 'H*', $string ) );
		} catch (\Exception $e) {
			throw new \RuntimeException( 'Failed to generate a secure random string for nonce.', 0, $e );
		}
	}

	/**
	 * Base64 URL-safe encoding (strips + / =).
	 */
	private static function base64_url_encode( string $string ): string {
		return rtrim( strtr( base64_encode( $string ), '+/', '-_' ), '=' );
	}

	/**
	 * Exchanges the authorization code for an access token using the League provider.
	 *
	 * @param string $code The authorization code.
	 * @param string $code_verifier The PKCE verifier string.
	 * @return array|WP_Error
	 */
	public static function exchange_token_with_provider( string $code, string $code_verifier ) {
		try {
			self::init_provider();

			$access_token = self::$provider->getAccessToken( 'authorization_code', [
				'code'          => $code,
				'code_verifier' => $code_verifier,
			] );

			return [
				'access_token'  => $access_token->getToken(),
				'refresh_token' => $access_token->getRefreshToken(),
				'expires_in'    => $access_token->getExpires() - time(),
			];

		} catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
			error_log( '[WP2 Shopify] Provider Token Exchange Error: ' . $e->getMessage() );
			return new WP_Error( 'shopify_auth_error', 'OAuth provider error: ' . $e->getMessage() );
		} catch (\Exception $e) {
			error_log( '[WP2 Shopify] Token Exchange Failure: ' . $e->getMessage() );
			return new WP_Error( 'auth_exchange_failed', 'Internal error during token exchange: ' . $e->getMessage() );
		}
	}

	/**
	 * Refreshes an expired token using the League provider.
	 *
	 * @param string $refresh_token The refresh token string.
	 * @return array|WP_Error
	 */
	public static function refresh_token_with_provider( string $refresh_token ) {
		try {
			self::init_provider();

			$access_token = self::$provider->getAccessToken( 'refresh_token', [
				'refresh_token' => $refresh_token,
			] );

			return [
				'access_token'  => $access_token->getToken(),
				'refresh_token' => $access_token->getRefreshToken(),
				'expires_in'    => $access_token->getExpires() - time(),
			];
		} catch (\Exception $e) {
			error_log( '[WP2 Shopify] Token Refresh Failure: ' . $e->getMessage() );
			return new WP_Error( 'auth_refresh_failed', 'Internal error during token refresh: ' . $e->getMessage() );
		}
	}
}
