<?php
namespace WP2_Shopify\Auth;

use WP_Error;
use WP_REST_Request;
use WP2_Shopify\Events\CustomerUpdater;

class Service {
	public const TOKEN_COOKIE           = 'wp2_shopify_token';
	public const SHADOW_EMAIL_DOMAIN    = '@shadow.shopify-identity.local';
	private const SESSION_PREFIX         = 'wp2_session_';
	private const OPENID_TRANSIENT       = 'wp2_shopify_openid_config';
	private const OPENID_BACKUP_OPTION   = 'wp2_shopify_openid_config_backup';
	private const OPENID_FETCH_LOCK      = 'wp2_shopify_openid_fetch_lock';
	private const CUSTOMER_API_TRANSIENT = 'wp2_shopify_customer_api';
	private const CUSTOMER_API_OPTION    = 'wp2_shopify_customer_api_endpoint';
	private const CUSTOMER_API_LOCK      = 'wp2_shopify_customer_api_fetch_lock';

	public const LINK_USER_COOKIE = 'wp2_shopify_link_user';

	public static function get_cookie_domain(): string {
		if ( defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ) {
			return COOKIE_DOMAIN;
		}

		if ( defined( 'WP2_SHOPIFY_COOKIE_DOMAIN' ) && '' !== WP2_SHOPIFY_COOKIE_DOMAIN ) {
			return WP2_SHOPIFY_COOKIE_DOMAIN;
		}

		return self::derive_cookie_domain_from_host();
	}

	private static function derive_cookie_domain_from_host(): string {
		$host = $_SERVER['HTTP_HOST'] ?? parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === ( $host = trim( strtolower( $host ) ) ) ) {
			return '';
		}

		if ( in_array( $host, [ 'localhost', '127.0.0.1' ], true ) ) {
			return '';
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		if ( strpos( $host, ':' ) !== false ) {
			return '';
		}

		$segments = explode( '.', $host );

		if ( count( $segments ) <= 2 ) {
			return $host;
		}

		$last = $segments[ count( $segments ) - 1 ];
		$second_last = $segments[ count( $segments ) - 2 ];

		if ( strlen( $last ) === 2 && strlen( $second_last ) === 2 && count( $segments ) >= 3 ) {
			return implode( '.', array_slice( $segments, -3 ) );
		}

		return implode( '.', array_slice( $segments, -2 ) );
	}

	/**
	 * Exchange an authorization code + verifier for tokens.
	 */
	public static function exchange_token( string $code, string $code_verifier ) {
		// --- Use the League OAuth2 Library via PKCE class ---
		$token_data = PKCE::exchange_token_with_provider( $code, $code_verifier );

		if ( is_wp_error( $token_data ) ) {
			return $token_data;
		}

		// The library returns the standardized array, so we check for generic errors.
		if ( isset( $token_data['error'] ) ) {
			error_log( '[WP2 Shopify] Token exchange error: ' . ( $token_data['error_description'] ?? 'Unknown error' ) );
			return new WP_Error( 'shopify_auth_error', $token_data['error_description'] ?? 'Unknown error' );
		}

		return $token_data;
	}

	/**
	 * Refresh an expired access token using the refresh token.
	 */
	public static function refresh_token( string $refresh_token ) {
		// --- Use the League OAuth2 Library via PKCE class ---
		$tokens = PKCE::refresh_token_with_provider( $refresh_token );

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		// The library returns the standardized array, so we check for generic errors.
		if ( isset( $tokens['error'] ) ) {
			error_log( '[WP2 Shopify] Token refresh error: ' . ( $tokens['error_description'] ?? 'Unknown error' ) );
			return new WP_Error( 'shopify_auth_error', $tokens['error_description'] ?? 'Unknown error' );
		}

		return $tokens;
	}

	/**
	 * Fetch the Shopify customer profile (tags, email, etc).
	 */
	public static function fetch_customer( string $access_token ) {
		$access_token = self::normalize_customer_token( sanitize_text_field( trim( (string) $access_token ) ) );

		if ( empty( $access_token ) ) {
			return new WP_Error( 'missing_token', 'Access token is required to retrieve Shopify customer data.' );
		}

		$query = <<<'GRAPHQL'
query CustomerIdentity {
    customer {
        id
        firstName
        lastName
        emailAddress {
            emailAddress
        }
        tags
        defaultAddress {
            country
            province
        }
    }
}
GRAPHQL;

		$response = self::execute_customer_account_graphql( $access_token, $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$customer = $response['data']['customer'] ?? NULL;

		if ( empty( $customer ) || ! is_array( $customer ) ) {
			return new WP_Error( 'missing_customer_data', 'Shopify Customer Account API did not return customer data.' );
		}

		return $customer;
	}

	/**
	 * Persist session markers AND Link/Login WP User.
	 */
	public static function establish_session( array $customer, array $tokens ) {
		if ( empty( $tokens['access_token'] ) ) {
			return new WP_Error( 'missing_access_token', 'Access token missing from Shopify response.' );
		}

		$customer_id = trim( (string) ( $customer['id'] ?? '' ) );

		if ( '' === $customer_id ) {
			return new WP_Error( 'missing_customer_id', 'Shopify customer payload did not contain an identifier.' );
		}

		$first_name = sanitize_text_field( $customer['firstName'] ?? '' );
		$last_name  = sanitize_text_field( $customer['lastName'] ?? '' );
		$real_email = sanitize_email( $customer['emailAddress']['emailAddress'] ?? '' );

		$user = self::get_or_create_shadow_user( $customer_id, $first_name, $last_name );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$current_user_id = $user->ID;

		wp_set_current_user( $current_user_id );
		wp_set_auth_cookie( $current_user_id );

		$display_updates = [ 'ID' => $current_user_id ];

		if ( $first_name && $first_name !== $user->first_name ) {
			$display_updates['first_name'] = $first_name;
		}

		if ( $last_name && $last_name !== $user->last_name ) {
			$display_updates['last_name'] = $last_name;
		}

		if ( count( $display_updates ) > 1 ) {
			wp_update_user( $display_updates );
		}

		update_user_meta( $current_user_id, '_wp2_shopify_customer_id', $customer_id );
		update_user_meta( $current_user_id, '_wp2_shopify_tags', $customer['tags'] ?? [] );
		update_user_meta( $current_user_id, '_wp2_last_sync', time() );

		if ( '' === $real_email ) {
			delete_user_meta( $current_user_id, '_wp2_shopify_real_email' );
		} else {
			update_user_meta( $current_user_id, '_wp2_shopify_real_email', $real_email );
		}

		if ( isset( $_COOKIE[ self::LINK_USER_COOKIE ] ) ) {
			setcookie( self::LINK_USER_COOKIE, '', time() - 3600, '/' );
		}

		// 5. Set Shopify Token Cookie (Standard Auth Flow)
		$expires_in   = isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : HOUR_IN_SECONDS;
		$access_token = self::normalize_customer_token( $tokens['access_token'] );

		setcookie(
			self::TOKEN_COOKIE,
			$access_token,
			[
				'expires'  => time() + $expires_in,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => self::get_cookie_domain(),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);

		// Store Refresh Token in user meta
		if ( ! empty( $tokens['refresh_token'] ) && $current_user_id > 0 ) {
			update_user_meta( $current_user_id, '_wp2_shopify_refresh_token', $tokens['refresh_token'] );
		}

		$session_key = self::SESSION_PREFIX . md5( $access_token );
		set_transient( $session_key, $customer, $expires_in );

		return $session_key;
	}

	private static function get_or_create_shadow_user( string $customer_id, string $first_name, string $last_name ) {
		$user = CustomerUpdater::find_user_by_customer_id( $customer_id );

		if ( $user ) {
			return $user;
		}

		$login   = self::generate_shadow_login( $customer_id );
		$user_id = wp_insert_user( [
			'user_login'   => $login,
			'user_email'   => self::generate_shadow_email( $customer_id ),
			'user_pass'    => wp_generate_password( 64 ),
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( "{$first_name} {$last_name}" ),
			'role'         => 'shopify_customer',
		] );

		if ( is_wp_error( $user_id ) ) {
			return self::reconcile_existing_shadow_user( $user_id, $customer_id, $login );
		}

		$user = get_user_by( 'ID', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'user_creation_failed', 'Unable to confirm Shopify customer user creation.' );
		}

		return $user;
	}

	private static function generate_shadow_email( string $customer_id ): string {
		return sprintf( 'gid_%s%s', md5( $customer_id ), self::SHADOW_EMAIL_DOMAIN );
	}

	private static function generate_shadow_login( string $customer_id ): string {
		return 'shopify_' . substr( md5( $customer_id ), 0, 32 );
	}

	/**
	 * Try to link to an existing shadow user when WP reports a login/email conflict.
	 *
	 * @return WP_Error|\WP_User
	 */
	private static function reconcile_existing_shadow_user(
		WP_Error $error,
		string $customer_id,
		string $login
	) {
		$error_code = $error->get_error_code();

		if ( ! in_array( $error_code, [ 'existing_user_login', 'existing_user_email' ], true ) ) {
			return $error;
		}

		$attempts = 3;
		$user = null;

		while ( $attempts > 0 ) {
			$user = get_user_by( 'login', $login );

			if ( $user ) {
				break;
			}

			$attempts--;

			if ( $attempts > 0 ) {
				usleep( 250000 );
			}
		}

		if ( ! $user ) {
			return new WP_Error(
				'shadow_user_conflict',
				'Shadow login already exists but the corresponding user record could not be resolved.'
			);
		}

		update_user_meta( $user->ID, '_wp2_shopify_customer_id', $customer_id );
		error_log(
			sprintf(
				'[WP2 Shopify] Linking Shopify customer %s to existing WP user %s (ID %d) after login conflict.',
				$customer_id,
				$user->user_login,
				$user->ID
			)
		);

		return $user;
	}

	public static function get_cached_customer( string $token ) {
		$session_key = self::SESSION_PREFIX . md5( self::normalize_customer_token( $token ) );
		$customer    = get_transient( $session_key );

		return $customer ?: NULL;
	}

	public static function summarize_customer( array $customer ): array {
		return [
			'id'        => $customer['id'] ?? NULL,
			'email'     => $customer['emailAddress']['emailAddress'] ?? NULL,
			'firstName' => $customer['firstName'] ?? NULL,
			'lastName'  => $customer['lastName'] ?? NULL,
			'tags'      => $customer['tags'] ?? [],
		];
	}

	public static function normalize_customer_token( string $token ): string {
		$token = trim( (string) $token );

		if ( $token === '' ) {
			return '';
		}

		if ( str_starts_with( $token, 'shcat_' ) ) {
			return $token;
		}

		return 'shcat_' . $token;
	}

	public static function get_client_id(): string {
		if ( defined( 'WP2_SHOPIFY_CLIENT_ID' ) ) {
			return sanitize_text_field( WP2_SHOPIFY_CLIENT_ID );
		}

		return sanitize_text_field( (string) get_option( 'wp2_shopify_client_id', '' ) );
	}

	public static function get_shop_domain(): string {
		if ( defined( 'WP2_SHOPIFY_STORE_DOMAIN' ) ) {
			return sanitize_text_field( WP2_SHOPIFY_STORE_DOMAIN );
		}

		if ( defined( 'WP2_SHOPIFY_DOMAIN' ) ) {
			return sanitize_text_field( WP2_SHOPIFY_DOMAIN );
		}

		return sanitize_text_field( (string) get_option( 'wp2_shopify_domain', '' ) );
	}

	public static function get_redirect_uri(): string {
		$page_id = (int) get_option( 'wp2_shopify_redirect_page_id', 0 );

		if ( $page_id ) {
			$status = get_post_status( $page_id );

			if ( 'publish' === $status ) {
				$permalink = get_permalink( $page_id );

				if ( $permalink ) {
					return esc_url_raw( $permalink );
				}
			}
		}

		$stored_redirect = (string) get_option( 'wp2_shopify_redirect_uri', '' );

		if ( $stored_redirect ) {
			return esc_url_raw( $stored_redirect );
		}

		return esc_url_raw( home_url( '/auth-callback' ) );
	}

	public static function get_scopes(): string {
		return (string) get_option( 'wp2_shopify_scope', 'openid email customer-account-api:full' );
	}

	public static function get_authorization_endpoint(): string {
		$override = self::get_custom_endpoint( 'WP2_SHOPIFY_OAUTH_AUTHORIZE_URL', 'wp2_shopify_authorize_url' );

		if ( $override ) {
			return $override;
		}

		$config = self::get_openid_configuration();

		if ( is_wp_error( $config ) ) {
			return '';
		}

		return esc_url_raw( $config['authorization_endpoint'] ?? '' );
	}

	public static function get_token_endpoint(): string {
		$override = self::get_custom_endpoint( 'WP2_SHOPIFY_OAUTH_TOKEN_URL', 'wp2_shopify_token_url' );

		if ( $override ) {
			return $override;
		}

		$config = self::get_openid_configuration();

		if ( is_wp_error( $config ) ) {
			return '';
		}

		return esc_url_raw( $config['token_endpoint'] ?? '' );
	}

	public static function get_logout_endpoint(): string {
		$override = self::get_custom_endpoint( 'WP2_SHOPIFY_OAUTH_LOGOUT_URL', 'wp2_shopify_logout_url' );

		if ( $override ) {
			return $override;
		}

		$config = self::get_openid_configuration();

		if ( is_wp_error( $config ) ) {
			return '';
		}

		return esc_url_raw( $config['end_session_endpoint'] ?? '' );
	}

	private static function get_custom_endpoint( string $constant_name, string $option_name ): string {
		if ( defined( $constant_name ) ) {
			$constant_value = (string) constant( $constant_name );

			if ( '' !== trim( $constant_value ) ) {
				return esc_url_raw( $constant_value );
			}
		}

		$option_value = (string) get_option( $option_name, '' );

		if ( '' !== trim( $option_value ) ) {
			return esc_url_raw( $option_value );
		}

		return '';
	}

	public static function get_openid_configuration(): array {
		$config = get_transient( self::OPENID_TRANSIENT );

		if ( false === $config ) {
			$config = self::fetch_openid_configuration();

			if ( is_wp_error( $config ) ) {
				$backup = (string) get_option( self::OPENID_BACKUP_OPTION );

				if ( $backup ) {
					$decoded_backup = json_decode( $backup, true );
					if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_backup ) ) {
						return $decoded_backup;
					}
				}

				// Always return an array, even on error
				return [];
			}

			if ( ! is_array( $config ) ) {
				// Always return an array, even on error
				return [];
			}

			set_transient( self::OPENID_TRANSIENT, $config, HOUR_IN_SECONDS );
			update_option( self::OPENID_BACKUP_OPTION, wp_json_encode( $config ) );
		}

		return is_array( $config ) ? $config : [];
	}

	private static function fetch_openid_configuration() {
		if ( get_transient( self::OPENID_FETCH_LOCK ) ) {
			return new WP_Error( 'openid_fetch_locked', 'Fetch is currently locked.' );
		}

		set_transient( self::OPENID_FETCH_LOCK, true, MINUTE_IN_SECONDS );

		$response = wp_safe_remote_get( 'https://accounts.shopify.com/.well-known/openid-configuration' );

		delete_transient( self::OPENID_FETCH_LOCK );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body   = wp_remote_retrieve_body( $response );
		$config = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'openid_json_error',
				'Unable to parse Shopify OpenID configuration response: ' . json_last_error_msg()
			);
		}

		if ( ! is_array( $config ) ) {
			return new WP_Error( 'openid_invalid_response', 'Shopify OpenID configuration response was invalid.' );
		}

		return $config;
	}

	private static function get_customer_api_endpoint(): string {
		$cached      = get_transient( self::CUSTOMER_API_TRANSIENT );
		$lock_key    = self::CUSTOMER_API_LOCK;
		$shop_domain = self::get_shop_domain();

		if ( false !== $cached && is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		if ( empty( $shop_domain ) ) {
			return self::get_customer_api_backup_or_default( $shop_domain );
		}

		if ( false !== get_transient( $lock_key ) ) {
			return self::get_customer_api_backup_or_default( $shop_domain );
		}

		set_transient( $lock_key, true, MINUTE_IN_SECONDS );

		try {
			$url      = esc_url_raw( sprintf( 'https://%s/.well-known/customer-account-api', $shop_domain ) );
			$response = wp_safe_remote_get( $url, [ 'timeout' => 15 ] );

			if ( ! is_wp_error( $response ) ) {
				$body    = json_decode( wp_remote_retrieve_body( $response ), true );
				$graphql = '';

				if ( isset( $body['graphql_api'] ) && is_string( $body['graphql_api'] ) ) {
					$graphql = $body['graphql_api'];
				} elseif ( isset( $body['graphql'] ) && is_string( $body['graphql'] ) ) {
					$graphql = $body['graphql'];
				}

				if ( $graphql ) {
					set_transient( self::CUSTOMER_API_TRANSIENT, $graphql, DAY_IN_SECONDS );
					update_option( self::CUSTOMER_API_OPTION, $graphql );
					return $graphql;
				}
			}
		} finally {
			delete_transient( $lock_key );
		}

		return self::store_customer_api_default( $shop_domain );
	}

	private static function get_customer_api_backup_or_default( string $shop_domain ): string {
		$backup = (string) get_option( self::CUSTOMER_API_OPTION, '' );

		if ( $backup ) {
			return esc_url_raw( $backup );
		}

		return self::store_customer_api_default( $shop_domain );
	}

	private static function store_customer_api_default( string $shop_domain ): string {
		if ( empty( $shop_domain ) ) {
			return '';
		}

		$default = esc_url_raw( sprintf( 'https://%s/customer/api/%s/graphql', $shop_domain, WP2_SHOPIFY_API_VERSION ) );

		set_transient( self::CUSTOMER_API_TRANSIENT, $default, DAY_IN_SECONDS );
		update_option( self::CUSTOMER_API_OPTION, $default );

		return $default;
	}

	private static function execute_customer_account_graphql( string $access_token, string $query, array $variables = [] ) {
		$access_token = self::normalize_customer_token( sanitize_text_field( trim( (string) $access_token ) ) );

		if ( empty( $access_token ) ) {
			return new WP_Error( 'missing_token', 'No access token provided for GraphQL request.' );
		}

		$endpoint = esc_url_raw( self::get_customer_api_endpoint() );

		if ( empty( $endpoint ) ) {
			return new WP_Error( 'missing_customer_api', 'Unable to resolve Shopify Customer Account API endpoint.' );
		}

		$body = [
			'query' => $query,
		];

		if ( ! empty( $variables ) ) {
			$body['variables'] = $variables;
		}

		$args = [
			'method'  => 'POST',
			'headers' => [
				'Content-Type'                    => 'application/json',
				'Authorization'                   => $access_token,
				'X-Shopify-Customer-Access-Token' => $access_token,
			],
			'body'    => wp_json_encode( $body ),
		];

		$response = wp_safe_remote_post(
			$endpoint,
			array_merge(
				[
					'timeout' => 20,
				],
				$args
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $response_code < 200 || $response_code >= 300 ) {
			return new WP_Error(
				'shopify_graphql_http_error',
				sprintf(
					'Shopify Customer API returned HTTP %d.',
					$response_code
				),
				[
					'body' => wp_remote_retrieve_body( $response ),
				]
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'shopify_graphql_json_error',
				'Invalid JSON from Shopify Customer API: ' . json_last_error_msg()
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'shopify_graphql_invalid_response', 'Shopify Customer API returned an unexpected payload.' );
		}

		if ( isset( $data['errors'] ) ) {
			return new WP_Error( 'shopify_graphql_error', json_encode( $data['errors'] ) );
		}

		return $data;
	}

	public static function revoke_session() {
		// Clear the token cookie
		setcookie(
			self::TOKEN_COOKIE,
			'',
			[
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => self::get_cookie_domain(),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			]
		);

		// Remove the refresh token from user meta
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, '_wp2_shopify_refresh_token' );
		}

		// Clear the session transient
		if ( isset( $_COOKIE[ self::TOKEN_COOKIE ] ) ) {
			$session_key = self::SESSION_PREFIX . md5(
				self::normalize_customer_token(
					sanitize_text_field( wp_unslash( $_COOKIE[ self::TOKEN_COOKIE ] ) )
				)
			);
			delete_transient( $session_key );
		}
	}

	public static function unlink_user() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'You must be logged in to disconnect.' );
		}

		$user_id = get_current_user_id();
		delete_user_meta( $user_id, '_wp2_shopify_customer_id' );
		delete_user_meta( $user_id, '_wp2_shopify_tags' );
		delete_user_meta( $user_id, '_wp2_last_sync' );
		delete_user_meta( $user_id, '_wp2_shopify_real_email' );
		delete_user_meta( $user_id, '_wp2_shopify_refresh_token' );

		self::revoke_session();

		// Log the user out so localized config reflects a guest session.
		wp_logout();

		return true;
	}

	public static function register_routes() {
		register_rest_route(
			'wp2-shopify/v1',
			'/logout',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_logout' ],
				'permission_callback' => [ self::class, 'verify_session_request' ],
			]
		);

		register_rest_route(
			'wp2-shopify/v1',
			'/auth/refresh',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_refresh' ],
				'permission_callback' => [ self::class, 'verify_rest_nonce' ],
			]
		);
	}

	public static function handle_logout() {
		self::revoke_session();
		return rest_ensure_response( [ 'success' => true ] );
	}

	public static function handle_refresh() {
		$session = self::refresh_session();

		if ( is_wp_error( $session ) ) {
			return rest_ensure_response( [
				'success' => false,
				'code'    => $session->get_error_code() ?: 'refresh_failed',
				'message' => $session->get_error_message(),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'message' => 'Session refreshed successfully.',
		] );
	}

	public static function verify_rest_nonce( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce ) {
			$nonce = (string) $request->get_param( 'nonce' );
		}

		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	private static function verify_session_request( WP_REST_Request $request ): bool {
		return is_user_logged_in() && self::verify_rest_nonce( $request );
	}

	public static function refresh_session() {
		$current_user_id = get_current_user_id();

		if ( $current_user_id <= 0 ) {
			return new WP_Error( 'unauthorized', 'User not logged in.' );
		}

		$refresh_token = get_user_meta( $current_user_id, '_wp2_shopify_refresh_token', true );

		if ( empty( $refresh_token ) ) {
			return new WP_Error( 'missing_refresh_token', 'Session expired. Please log in again.' );
		}

		// Retrieve the old access token from the cookie
		$old_access_token = isset( $_COOKIE[ self::TOKEN_COOKIE ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::TOKEN_COOKIE ] ) )
			: '';

		$customer = self::get_cached_customer( $old_access_token );

		// Pass the actual refresh token to the provider
		$tokens = self::refresh_token( $refresh_token );

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		if ( empty( $customer ) ) {
			$customer = self::fetch_customer( $tokens['access_token'] ?? '' );

			if ( is_wp_error( $customer ) ) {
				return $customer;
			}
		}

		return self::establish_session( $customer, $tokens );
	}
}
