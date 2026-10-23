<?php

namespace WP2_Shopify;

use WP_REST_Request;
use WP2_Shopify\Auth\REST as AuthREST;
use WP2_Shopify\Auth\Service as AuthService;
use WP2_Shopify\Core\API as CoreAPI;
use WP2_Shopify\Core\Settings as CoreSettings;
use WP2_Shopify\Events\Dispatcher as EventsDispatcher;
use WP2_Shopify\Shortcodes;
use WP2_Shopify\Auth\PKCE;

class Init {
	public static function register(): void {
		// Assets & Admin
		Assets::init();
		self::register_admin_menus();

		EventsDispatcher::register();

		Shortcodes::init();

		add_action( 'init', [ self::class, 'ensure_shopify_customer_role' ] );
		add_action( 'admin_init', [ self::class, 'redirect_shopify_customers_from_admin' ] );
		add_filter( 'wp_mail', [ self::class, 'filter_shadow_email_addresses' ] );
		add_filter( 'allow_password_reset', [ self::class, 'prevent_shadow_password_reset' ], 10, 2 );
		add_filter( 'pre_get_avatar_data', [ self::class, 'use_real_email_for_avatars' ], 10, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_privacy_exporter' ] );
		add_action( 'after_setup_theme', [ self::class, 'hide_admin_bar_for_shopify_customers' ] );

		add_action( 'pre_get_users', [ self::class, 'hide_shadow_users_from_list' ] );
		add_action( 'template_redirect', [ self::class, 'disable_shadow_author_archives' ] );

		// Profile hooks for Shopify preview
		add_action( 'show_user_profile', [ self::class, 'render_user_profile_shopify_stats' ] );
		add_action( 'edit_user_profile', [ self::class, 'render_user_profile_shopify_stats' ] );

		add_action( 'admin_init', function () {
			( new CoreSettings() )->register_settings();
		} );

		// Auth Callback Rewrite Rule
		self::register_shopify_auth_callback();

		// REST Endpoints
		add_action( 'rest_api_init', function () {
			CoreAPI::register_routes();
			AuthREST::register_routes();
			AuthService::register_routes();

			register_rest_route(
				'wp2-shopify/v1',
				'/identity/card',
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'handle_identity_card_request' ],
					'permission_callback' => [ self::class, 'verify_identity_card_request' ],
				]
			);
		} );
		}

	public static function register_shopify_auth_callback(): void {
		add_action( 'init', function () {
			$slug = self::get_auth_callback_slug();

			if ( '' === $slug ) {
				return;
			}

			$pattern = sprintf( '^%s/?$', preg_quote( $slug, '/' ) );
			$target  = sprintf( 'index.php?pagename=%s&shopify_auth_callback=1', $slug );

			add_rewrite_rule( $pattern, $target, 'top' );
		} );

		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'shopify_auth_callback';
			return $vars;
		} );
	}

	private static function get_auth_callback_slug(): string {
		$slug = (string) apply_filters( 'wp2_shopify_auth_callback_slug', 'auth-callback' );
		$slug = trim( $slug, '/' );

		return '' === $slug ? 'auth-callback' : $slug;
	}

	public static function register_admin_menus(): void {
		add_action( 'admin_menu', function () {
			add_menu_page(
				'Shopify Identity',
				'Shopify Identity',
				'manage_options',
				'wp2-shopify-id',
				[ self::class, 'render_settings_page' ],
				'dashicons-id'
			);
		} );
	}

	public static function render_settings_page(): void {
		( new CoreSettings() )->render_admin_page();
	}

	/**
	 * Renders the Shopify Stats on the WP Admin Profile Screen.
	 */
	public static function render_user_profile_shopify_stats( $user ) {
		$user_id = $user->ID;

		$customer_id     = get_user_meta( $user_id, '_wp2_shopify_customer_id', true );
		$tags            = (array) get_user_meta( $user_id, '_wp2_shopify_tags', true );
		$shop_status     = get_user_meta( $user_id, '_wp2_shopify_customer_status', true );
		$email_consent   = get_user_meta( $user_id, '_wp2_shopify_email_marketing_consent', true );
		$sms_consent     = get_user_meta( $user_id, '_wp2_shopify_sms_marketing_consent', true );
		$shopify_email   = get_user_meta( $user_id, '_wp2_shopify_email_address', true );
		$shopify_phone   = get_user_meta( $user_id, '_wp2_shopify_phone_number', true );
		$shopify_first_name = get_user_meta( $user_id, '_wp2_shopify_first_name', true );
		$shopify_last_name  = get_user_meta( $user_id, '_wp2_shopify_last_name', true );
		$last_sync       = get_user_meta( $user_id, '_wp2_last_sync', true );

		$summary_raw = get_user_meta( $user_id, '_wp2_shopify_purchasing_summary', true );
		$summary     = $summary_raw ? json_decode( $summary_raw, true ) : [];

		$default_address = [];
		$default_address_raw = get_user_meta( $user_id, '_wp2_shopify_default_address', true );
		if ( $default_address_raw ) {
			$decoded = json_decode( $default_address_raw, true );
			if ( is_array( $decoded ) ) {
				$default_address = $decoded;
			}
		}

		$badge_style = 'display:inline-block; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 11px;';
		$green_badge = $badge_style . 'background: #d1fae5; color: #065f46;';
		$gray_badge  = $badge_style . 'background: #f3f4f6; color: #374151;';
		$red_badge   = $badge_style . 'background: #fee2e2; color: #991b1b;';
		$view = WP2_SHOPIFY_ID_PLUGIN_DIR . 'src/views/admin/user-profile.php';

		if ( file_exists( $view ) ) {
			include $view;
		}
	}

	public static function ensure_shopify_customer_role(): void {
		if ( get_role( 'shopify_customer' ) ) {
			return;
		}

		add_role(
			'shopify_customer',
			'Shopify Customer',
			[
				'read'    => true,
				'level_0' => true,
			]
		);
	}

	public static function redirect_shopify_customers_from_admin(): void {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( current_user_can( 'shopify_customer' ) ) {
			$redirect_target = apply_filters( 'wp2_shopify_customer_admin_redirect', home_url() );

			wp_safe_redirect( $redirect_target );
			exit;
		}
	}

	public static function filter_shadow_email_addresses( array $args ): array {
		$shadow_domain = AuthService::SHADOW_EMAIL_DOMAIN;

		if ( empty( $args['to'] ) || '' === $shadow_domain ) {
			return $args;
		}

		$was_string = is_string( $args['to'] );
		$recipients = $was_string
			? array_filter( array_map( 'trim', explode( ',', $args['to'] ) ) )
			: (array) $args['to'];

		$converted      = [];
		$domain_length = strlen( $shadow_domain );

		foreach ( $recipients as $recipient ) {
			$sanitized = sanitize_email( $recipient );

			if ( '' === $sanitized ) {
				continue;
			}

			if (
				$domain_length > 0
				&& strlen( $sanitized ) >= $domain_length
				&& substr_compare( $sanitized, $shadow_domain, -$domain_length, $domain_length ) === 0
			) {
				$user = get_user_by( 'email', $sanitized );

				if ( $user ) {
					$real_email = get_user_meta( $user->ID, '_wp2_shopify_real_email', true );

					if ( empty( $real_email ) ) {
						$backup_email = get_user_meta( $user->ID, '_wp2_shopify_email_address', true );

						if ( ! empty( $backup_email ) && is_email( $backup_email ) ) {
							update_user_meta( $user->ID, '_wp2_shopify_real_email', $backup_email );
							$real_email = $backup_email;
						}
					}

					if ( ! empty( $real_email ) && is_email( $real_email ) ) {
						$converted[] = sanitize_email( $real_email );
						continue;
					}

					error_log(
						sprintf(
							'[WP2 Shopify] Unable to resolve real email for %s; skipping notification to avoid bouncing.',
							$sanitized
						)
					);
				}

				continue;
			}

			$converted[] = $sanitized;
		}

		$args['to'] = $was_string ? implode( ',', $converted ) : $converted;

		return $args;
	}

	/**
	 * Prevent shadow users from using WordPress password reset flows.
	 */
	public static function prevent_shadow_password_reset( $allow, $user_id ) {
		$user = get_userdata( $user_id );

		if ( $user && in_array( 'shopify_customer', (array) $user->roles, true ) ) {
			return new \WP_Error( 'no_password_reset', 'Please manage your password via your Shopify account.' );
		}

		return $allow;
	}

	/**
	 * Use the real email hash when rendering avatars for shadow users.
	 */
	public static function use_real_email_for_avatars( $args, $id_or_email ) {
		$user = null;

		if ( $id_or_email instanceof \WP_User ) {
			$user = $id_or_email;
		} elseif ( is_numeric( $id_or_email ) ) {
			$user = get_user_by( 'id', (int) $id_or_email );
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
		}

		if ( $user && in_array( 'shopify_customer', (array) $user->roles, true ) ) {
			$real_email = get_user_meta( $user->ID, '_wp2_shopify_real_email', true );

			if ( $real_email && is_email( $real_email ) ) {
				$args['found_avatar'] = true;
				$args['url']           = sprintf(
					'https://www.gravatar.com/avatar/%s',
					md5( strtolower( trim( $real_email ) ) )
				);
			}
		}

		return $args;
	}

	/**
	 * Hide the admin bar when shadow users reach the front-end.
	 */
	public static function hide_admin_bar_for_shopify_customers(): void {
		if ( ! current_user_can( 'shopify_customer' ) || current_user_can( 'manage_options' ) ) {
			return;
		}

		show_admin_bar( false );
	}

	public static function register_privacy_exporter( array $exporters ): array {
		$exporters['wp2-shopify-identity'] = [
			'exporter_friendly_name' => 'Shopify Identity Data',
			'callback'               => [ self::class, 'privacy_exporter_callback' ],
		];

		return $exporters;
	}

	public static function privacy_exporter_callback( $email_address, $page = 1 ) {
		$email_address = sanitize_email( (string) $email_address );

		if ( '' === $email_address ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			$users = get_users( [
				'meta_key'   => '_wp2_shopify_real_email',
				'meta_value' => $email_address,
				'number'     => 1,
			] );

			$user = $users ? $users[0] : null;
		}

		$data_to_export = [];

		if ( $user ) {
			$meta_keys = [
				'Customer ID'       => '_wp2_shopify_customer_id',
				'Tags'              => '_wp2_shopify_tags',
				'Marketing (Email)' => '_wp2_shopify_email_marketing_consent',
				'Marketing (SMS)'   => '_wp2_shopify_sms_marketing_consent',
				'Spending Summary'  => '_wp2_shopify_purchasing_summary',
			];

			foreach ( $meta_keys as $label => $key ) {
				$value = get_user_meta( $user->ID, $key, true );

				if ( '' === $value || empty( $value ) ) {
					continue;
				}

				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}

				$data_to_export[] = [
					'name'  => $label,
					'value' => $value,
				];
			}
		}

		return [
			'data' => $data_to_export,
			'done' => true,
		];
	}

	/**
	 * Hide shadow users from the default Admin Users view.
	 */
	public static function hide_shadow_users_from_list( $query ): void {
		if ( ! is_admin() || ! ( $query instanceof \WP_User_Query ) ) {
			return;
		}

		global $pagenow;

		if ( 'users.php' !== $pagenow ) {
			return;
		}

		if ( isset( $_GET['role'] ) ) {
			return;
		}

		$excluded = $query->get( 'role__not_in' );

		if ( empty( $excluded ) ) {
			$excluded = [];
		}

		if ( ! is_array( $excluded ) ) {
			$excluded = [ $excluded ];
		}

		if ( in_array( 'shopify_customer', $excluded, true ) ) {
			return;
		}

		$excluded[] = 'shopify_customer';
		$query->set( 'role__not_in', $excluded );
	}

	/**
	 * Force a 404 if a shadow user's author archive is requested.
	 */
	public static function disable_shadow_author_archives(): void {
		if ( ! is_author() ) {
			return;
		}

		$author = get_queried_object();

		if ( ! $author instanceof \WP_User || empty( $author->roles ) ) {
			return;
		}

		if ( ! in_array( 'shopify_customer', (array) $author->roles, true ) ) {
			return;
		}

		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Builds configuration object for the frontend JS.
	 */
	public static function build_frontend_config(): array {
		return [
			'auth'       => [
				'clientId'              => AuthService::get_client_id(),
				'authorizationEndpoint' => AuthService::get_authorization_endpoint(),
				'redirectUri'           => AuthService::get_redirect_uri(),
				'scope'                 => AuthService::get_scopes(),
				'tokenRoute'            => rest_url( 'wp2-shopify/v1/shopify/auth' ),
				'logoutEndpoint'        => AuthService::get_logout_endpoint(),
				'nonce'                 => PKCE::generate_nonce(),
			],
			'rest_nonce' => wp_create_nonce( 'wp_rest' ),
			'isAuthCallbackPage' => (bool) get_query_var( 'shopify_auth_callback', false ),
			'userId'     => get_current_user_id(),
			'cookieDomain' => AuthService::get_cookie_domain(),
		];
	}

	public static function handle_identity_card_request() {
		$html = Shortcodes::render_identity_card( [] );

		return rest_ensure_response( [ 'html' => $html ] );
	}

	public static function verify_identity_card_request( WP_REST_Request $request ): bool {
		return AuthREST::verify_rest_nonce( $request );
	}
}
