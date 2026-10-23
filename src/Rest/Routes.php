<?php

namespace WP2_Shopify\Rest;

use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Auth\TokenVault;
use WP2_Shopify\Commerce\CartBridge;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Webhooks\Endpoint;

/**
 * REST surface, namespace wp2-shopify/v1 (spec 13). No endpoint returns an
 * access token, refresh token, client secret, webhook secret, or a Shopify
 * payload. Mutations require a logged-in user plus the wp_rest nonce; the
 * JavaScript path gets that nonce from the status call, never from cached HTML.
 *
 * Endpoint paths are the contract: add new ones here and in the README's REST API table.
 */
final class Routes {
	public const NS = 'wp2-shopify/v1';

	public static function register(): void {
		register_rest_route(
			self::NS,
			'/auth/status',
			[
				'methods'             => 'GET',
				'callback'            => static fn() => self::no_cache( Status::for_current_user() ),
				'permission_callback' => '__return_true', // Returns the caller's own state only; guests get the guest shape.
			]
		);

		register_rest_route(
			self::NS,
			'/auth/login',
			[
				'methods'             => [ 'GET', 'POST' ],
				'callback'            => [ self::class, 'login' ],
				'permission_callback' => '__return_true', // Public start of the PKCE flow.
				'args'                => [ 'redirect_to' => [ 'type' => 'string', 'default' => '' ] ],
			]
		);

		register_rest_route(
			self::NS,
			'/auth/logout',
			[
				'methods'             => 'POST',
				'callback'            => static fn( \WP_REST_Request $r ) => self::no_cache( [ 'redirect' => Flow::logout( (string) $r->get_param( 'redirect_to' ) ) ] ),
				'permission_callback' => 'is_user_logged_in', // Plus the REST nonce, checked by core cookie auth.
			]
		);

		register_rest_route(
			self::NS,
			'/auth/refresh',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'refresh' ],
				'permission_callback' => 'is_user_logged_in',
			]
		);

		register_rest_route(
			self::NS,
			'/auth/disconnect',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'disconnect' ],
				'permission_callback' => 'is_user_logged_in',
			]
		);

		register_rest_route(
			self::NS,
			'/account/sync',
			[
				'methods'             => 'POST',
				'callback'            => static fn() => self::no_cache( [ 'result' => Flow::sync_now( get_current_user_id() ) ] ),
				'permission_callback' => static fn() => is_user_logged_in() && null !== Mirror::customer_id( get_current_user_id() ),
			]
		);

		register_rest_route(
			self::NS,
			CartBridge::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ CartBridge::class, 'attach' ],
				'permission_callback' => [ CartBridge::class, 'can_attach' ], // Linked customers only, plus the REST nonce.
				'args'                => [ 'cartId' => [ 'type' => 'string', 'required' => true ] ],
			]
		);

		Endpoint::register_routes();
	}

	public static function login( \WP_REST_Request $request ): \WP_REST_Response {
		$url = Flow::start( (string) $request->get_param( 'redirect_to' ) );

		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', is_wp_error( $url ) ? Flow::notice_url( 'unavailable' ) : $url );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	public static function refresh(): \WP_REST_Response|\WP_Error {
		$result = TokenVault::refresh( get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 409 ] );
		}

		// Status only, never token values.
		return self::no_cache( Status::for_current_user() );
	}

	public static function disconnect(): \WP_REST_Response|\WP_Error {
		$url = Flow::disconnect( get_current_user_id() );

		return is_wp_error( $url )
			? new \WP_Error( $url->get_error_code(), $url->get_error_message(), [ 'status' => 400 ] )
			: self::no_cache( [ 'redirect' => $url ] );
	}

	private static function no_cache( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
