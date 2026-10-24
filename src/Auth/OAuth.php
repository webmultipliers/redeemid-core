<?php

namespace WP2_Shopify\Auth;

use WP2_Shopify\Config;
use WP2_Shopify\Shopify\Discovery;
use WP2_Shopify\Shopify\Http;

/**
 * Customer Account API OAuth 2.0 + PKCE, entirely server-side.
 */
final class OAuth {
	public static function authorize_url( string $state, string $nonce, string $challenge ): string {
		return add_query_arg(
			rawurlencode_deep(
				[
					'client_id'             => Config::get( 'WP2_SHOPIFY_CLIENT_ID' ),
					'response_type'         => 'code',
					'redirect_uri'          => Config::callback_url(),
					'scope'                 => Config::SCOPES,
					'state'                 => $state,
					'nonce'                 => $nonce,
					'code_challenge'        => $challenge,
					'code_challenge_method' => 'S256',
				]
			),
			Discovery::endpoint( 'authorization_endpoint' )
		);
	}

	/**
	 * @return array{access_token: string, refresh_token: string, id_token: string, expires_at: int}|\WP_Error
	 */
	public static function exchange( string $code, string $verifier ): array|\WP_Error {
		return self::token_request(
			[
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => Config::callback_url(),
				'code'          => $code,
				'code_verifier' => $verifier,
			],
			'oauth.exchange'
		);
	}

	public static function refresh( string $refresh_token ): array|\WP_Error {
		return self::token_request(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			],
			'oauth.refresh'
		);
	}

	/**
	 * Shopify's end-session endpoint needs a browser redirect with id_token_hint,
	 * so logout returns a URL rather than calling Shopify.
	 */
	public static function logout_url( string $id_token, string $after ): string {
		$endpoint = Discovery::endpoint( 'end_session_endpoint' );

		if ( '' === $endpoint || '' === $id_token ) {
			return $after;
		}

		return add_query_arg(
			rawurlencode_deep(
				[
					'id_token_hint'            => $id_token,
					'post_logout_redirect_uri' => $after,
				]
			),
			$endpoint
		);
	}

	private static function token_request( array $params, string $label ): array|\WP_Error {
		$endpoint  = Discovery::endpoint( 'token_endpoint' );
		$client_id = Config::get( 'WP2_SHOPIFY_CLIENT_ID' );

		if ( '' === $endpoint || '' === $client_id ) {
			return new \WP_Error( 'wp2_shopify_unconfigured', 'Shopify sign-in is not configured.' );
		}

		// Shopify marks client_id required on every token request, confidential clients included.
		$params['client_id'] = $client_id;
		$headers             = [ 'Content-Type' => 'application/x-www-form-urlencoded' ];
		$secret              = Config::get( 'WP2_SHOPIFY_CLIENT_SECRET' );

		if ( '' !== $secret ) {
			// Confidential client.
			$headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $secret );
		} else {
			// Public client: Shopify checks the request origin against the app's JavaScript origins.
			$headers['Origin'] = untrailingslashit( home_url() );
		}

		$response = Http::request( 'POST', $endpoint, $headers, http_build_query( $params ), $label );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['access_token'] ) ) {
			return new \WP_Error( 'wp2_shopify_token', 'Shopify did not issue an access token.' );
		}

		return [
			'access_token'  => (string) $response['access_token'],
			'refresh_token' => (string) ( $response['refresh_token'] ?? ( $params['refresh_token'] ?? '' ) ),
			'id_token'      => (string) ( $response['id_token'] ?? '' ),
			'expires_at'    => time() + max( 60, (int) ( $response['expires_in'] ?? 3600 ) ),
		];
	}
}
