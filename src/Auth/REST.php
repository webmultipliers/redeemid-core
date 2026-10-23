<?php
namespace WP2_Shopify\Auth;

use WP_Error;
use WP_REST_Request;

class REST {
	public static function register_routes() {
		register_rest_route(
			'wp2-shopify/v1',
			'/shopify/auth',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_auth' ],
				'permission_callback' => [ self::class, 'verify_rest_nonce' ],
			]
		);

		register_rest_route(
			'wp2-shopify/v1',
			'/auth/status',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle_auth_status' ],
				'permission_callback' => [ self::class, 'verify_rest_nonce' ],
			]
		);

		register_rest_route(
			'wp2-shopify/v1',
			'/identity/unlink',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_unlink' ],
				'permission_callback' => function ( WP_REST_Request $request ) {
					return is_user_logged_in() && self::verify_rest_nonce( $request );
				},
			]
		);
	}

	public static function handle_auth( WP_REST_Request $request ) {
		$code          = $request->get_param( 'code' );
		$code_verifier = $request->get_param( 'code_verifier' );

		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$nonce = sanitize_text_field( (string) $request->get_param( 'nonce' ) );

		if ( ! self::validate_pkce_state( $state ) ) {
			return new WP_Error( 'state_mismatch', 'Invalid state parameter provided.', [ 'status' => 400 ] );
		}

		if ( ! self::validate_pkce_nonce( $nonce ) ) {
			return new WP_Error( 'nonce_mismatch', 'Invalid nonce parameter provided.', [ 'status' => 400 ] );
		}

		if ( empty( $code ) || empty( $code_verifier ) ) {
			return new WP_Error( 'missing_params', 'Code and Verifier required.', [ 'status' => 400 ] );
		}

		// Forward code and code_verifier as plain strings to Shopify's token endpoint.
		$token_data = Service::exchange_token( $code, $code_verifier );

		if ( is_wp_error( $token_data ) ) {
			return $token_data;
		}

		if ( empty( $token_data['access_token'] ) ) {
			return new WP_Error( 'missing_access_token', 'Shopify response missing access token.', [ 'status' => 500 ] );
		}

		$customer = Service::fetch_customer( $token_data['access_token'] );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$nonce_validation = self::validate_id_token_nonce( $token_data, $nonce );

		if ( is_wp_error( $nonce_validation ) ) {
			return $nonce_validation;
		}

		// Persist the session by calling establish_session
		$session_result = Service::establish_session( $customer, $token_data );

		if ( is_wp_error( $session_result ) ) {
			return $session_result;
		}

		self::clear_pkce_cookies();

		// Ensure the REST response includes 'token' for frontend compatibility
		$normalized_token = Service::normalize_customer_token( $token_data['access_token'] );

		return rest_ensure_response( [
			'customer'     => Service::summarize_customer( $customer ),
			'token'        => $normalized_token,
			'access_token' => $normalized_token,
		] );
	}

	public static function handle_auth_status( WP_REST_Request $request ) {
		if ( ! isset( $_COOKIE[ Service::TOKEN_COOKIE ] ) ) {
			return rest_ensure_response( [ 'logged_in' => false ] );
		}

		$token    = sanitize_text_field( wp_unslash( $_COOKIE[ Service::TOKEN_COOKIE ] ) );
		$customer = Service::get_cached_customer( $token );

		if ( ! $customer ) {
			$customer = Service::fetch_customer( $token );

			if ( is_wp_error( $customer ) || empty( $customer ) ) {
				Service::revoke_session();
				return rest_ensure_response( [ 'logged_in' => false ] );
			}

			$session_result = Service::establish_session( $customer, [ 'access_token' => $token ] );

			if ( is_wp_error( $session_result ) ) {
				return rest_ensure_response( [ 'logged_in' => false ] );
			}
		}

		return rest_ensure_response(
			[
				'logged_in'    => true,
				'customer'     => Service::summarize_customer( $customer ),
				'token'        => Service::normalize_customer_token( $token ),
				'access_token' => Service::normalize_customer_token( $token ),
			]
		);
	}

	public static function handle_unlink() {
		$result = Service::unlink_user();

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				[
					'success' => false,
					'message' => $result->get_error_message(),
				]
			);
		}

		return rest_ensure_response( [ 'success' => true ] );
	}
	public static function verify_rest_nonce( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce ) {
			$nonce = (string) $request->get_param( 'nonce' );
		}

		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	private static function validate_pkce_state( string $state ): bool {
		$cookie = isset( $_COOKIE['wp2_pkce_state'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wp2_pkce_state'] ) ) : '';

		if ( empty( $state ) || empty( $cookie ) ) {
			return false;
		}

		return hash_equals( $cookie, $state );
	}

	private static function validate_pkce_nonce( string $nonce ): bool {
		$cookie = isset( $_COOKIE['wp2_pkce_nonce'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wp2_pkce_nonce'] ) ) : '';

		if ( empty( $nonce ) || empty( $cookie ) ) {
			return false;
		}

		return hash_equals( $cookie, $nonce );
	}

	private static function validate_id_token_nonce( array $token_data, string $expected_nonce ) {
		if ( empty( $expected_nonce ) || empty( $token_data['id_token'] ) ) {
			return true;
		}

		$payload = self::decode_jwt_payload( $token_data['id_token'] );

		if ( ! $payload || empty( $payload['nonce'] ) ) {
			return new WP_Error( 'id_token_invalid', 'Unable to read nonce from the ID token.', [ 'status' => 400 ] );
		}

		if ( ! hash_equals( $payload['nonce'], $expected_nonce ) ) {
			return new WP_Error( 'nonce_mismatch', 'Nonce validation failed.', [ 'status' => 400 ] );
		}

		return true;
	}

	private static function decode_jwt_payload( string $jwt ): ?array {
		$parts = explode( '.', $jwt );

		if ( count( $parts ) < 2 ) {
			return NULL;
		}

		$payload  = strtr( $parts[1], '-_', '+/' );
		$payload .= str_repeat( '=', ( 4 - strlen( $payload ) % 4 ) % 4 );

		$decoded = base64_decode( $payload );

		if ( false === $decoded ) {
			return NULL;
		}

		$data = json_decode( $decoded, true );

		return is_array( $data ) ? $data : NULL;
	}

	private static function clear_pkce_cookies(): void {
		$keys = [ 'wp2_pkce_state', 'wp2_pkce_nonce' ];

		foreach ( $keys as $key ) {
			if ( empty( $_COOKIE[ $key ] ) ) {
				continue;
			}

			setcookie(
				$key,
				'',
				[
					'expires'  => time() - DAY_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
					'domain'   => Service::get_cookie_domain(),
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Strict',
				]
			);

			unset( $_COOKIE[ $key ] );
		}
	}
}
