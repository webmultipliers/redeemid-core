<?php

namespace WP2_Shopify\Auth;

use WP2_Shopify\Config;
use WP2_Shopify\Plugin;
use WP2_Shopify\Shopify\Admin;
use WP2_Shopify\Shopify\CustomerAccount;
use WP2_Shopify\Shopify\Discovery;
use WP2_Shopify\Support\CustomerId;
use WP2_Shopify\Support\Logger;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Sync\Reconciler;

/**
 * Sign-in, linking, logout, disconnect and sync-now as server-side flows.
 *
 * Every user-facing action is a real link or form post that works without
 * JavaScript (review point 5): forms post to admin-post.php and redirect, so
 * the visitor always lands on a page, never on raw JSON.
 */
final class Flow {
	public const QUERY_VAR = 'wp2_shopify_callback';

	private const MESSAGES = [
		'state_invalid'           => 'Your sign-in link expired or was opened in a different browser. Please try again.',
		'shopify_denied'          => 'Sign-in was cancelled at Shopify.',
		'exchange_failed'         => 'Shopify sign-in could not be completed. Please try again.',
		'profile_failed'          => 'Your Shopify profile could not be loaded. Please try again.',
		'unavailable'             => 'Sign-in is temporarily unavailable.',
		'id_token_missing'        => 'Shopify sign-in could not be verified. Please try again.',
		'id_token_issuer'         => 'Shopify sign-in could not be verified. Please try again.',
		'id_token_audience'       => 'Shopify sign-in could not be verified. Please try again.',
		'id_token_expired'        => 'Shopify sign-in could not be verified. Please try again.',
		'id_token_nonce'          => 'Shopify sign-in could not be verified. Please try again.',
		'link_token_missing'      => 'That connect request is no longer valid. Start again from your account.',
		'link_token_expired'      => 'That connect request expired. Start again from your account.',
		'link_token_mismatch'     => 'That connect request is no longer valid. Start again from your account.',
		'link_session_mismatch'   => 'That connect request belongs to a different session. Start again from your account.',
		'link_user_mismatch'      => 'Please sign in to the WordPress account you want to connect, then try again.',
		'admin_refused'           => 'Administrator accounts cannot be connected to a Shopify customer.',
		'already_shadow'          => 'This account already signs in with Shopify.',
		'email_unverified'        => 'Shopify did not confirm that email address, so the accounts were not connected.',
		'email_mismatch'          => 'Your Shopify email does not match this account\'s email, so the accounts were not connected.',
		'customer_already_linked' => 'That Shopify customer is already connected to another account.',
		'link_cancelled'          => 'The accounts were not connected.',
		'linked'                  => 'Your Shopify account is now connected.',
		'disconnected'            => 'Your Shopify account was disconnected.',
		'synced'                  => 'Your membership details were refreshed.',
		'sync_failed'             => 'Your details could not be refreshed right now. Please try again later.',
		'sync_wait'               => 'Your details were refreshed a moment ago.',
	];

	public static function register(): void {
		add_action( 'init', [ self::class, 'add_rewrite' ] );
		add_filter( 'query_vars', static fn( $vars ) => array_merge( $vars, [ self::QUERY_VAR ] ) );
		add_action( 'template_redirect', [ self::class, 'maybe_handle_callback' ], 0 );
		add_filter( 'the_content', [ self::class, 'callback_notice' ], 5 );
		add_action( 'set_auth_cookie', [ TokenVault::class, 'capture_session_token' ], 10, 6 );

		foreach ( [ 'login', 'logout' ] as $action ) {
			add_action( 'admin_post_wp2_shopify_' . $action, [ self::class, 'handle_' . $action ] );
			add_action( 'admin_post_nopriv_wp2_shopify_' . $action, [ self::class, 'handle_' . $action ] );
		}

		foreach ( [ 'connect', 'link_confirm', 'disconnect', 'sync' ] as $action ) {
			add_action( 'admin_post_wp2_shopify_' . $action, [ self::class, 'handle_' . $action ] );
		}
	}

	public static function add_rewrite(): void {
		$slug = Config::callback_slug();
		add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/?$', 'index.php?pagename=' . $slug . '&' . self::QUERY_VAR . '=1', 'top' );
	}

	// ---------------------------------------------------------------- URLs

	public static function login_url( string $redirect_to = '' ): string {
		return add_query_arg(
			[
				'action'      => 'wp2_shopify_login',
				'redirect_to' => rawurlencode( $redirect_to ?: home_url( '/' ) ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	public static function action_url(): string {
		return admin_url( 'admin-post.php' );
	}

	// ------------------------------------------------------------- Sign-in

	/**
	 * Begin the PKCE flow. Returns the Shopify authorize URL.
	 */
	public static function start( string $redirect_to, ?int $link_user = null ): string|\WP_Error {
		if ( ! Config::has( 'WP2_SHOPIFY_CLIENT_ID' ) || ! Discovery::available() ) {
			return new \WP_Error( 'unavailable', self::MESSAGES['unavailable'] );
		}

		$verifier = Pkce::verifier();
		$nonce    = Pkce::random( 24 );
		$data     = [
			'verifier'    => $verifier,
			'nonce'       => $nonce,
			'redirect_to' => self::safe_redirect( $redirect_to ),
		];

		if ( $link_user ) {
			$data['link_user']  = $link_user;
			$data['link_token'] = Linking::issue( $link_user );
		}

		$state = StateStore::create( $data );

		return OAuth::authorize_url( $state, $nonce, Pkce::challenge( $verifier ) );
	}

	public static function handle_login(): void {
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$url      = self::start( $redirect );

		self::redirect( is_wp_error( $url ) ? self::notice_url( 'unavailable' ) : $url, ! is_wp_error( $url ) );
	}

	public static function maybe_handle_callback(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		nocache_headers();

		// phpcs:disable WordPress.Security.NonceVerification -- OAuth callback; protected by the state cookie.
		if ( isset( $_GET['state'] ) || isset( $_GET['code'] ) || isset( $_GET['error'] ) ) {
			$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
			$code  = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
			$error = isset( $_GET['error'] );
			// phpcs:enable

			self::redirect( self::complete( $state, $code, $error ) );
		}

		if ( is_404() ) {
			// No page published at the callback slug: still show a calm result page.
			status_header( 200 );
			// notice_html() escapes every value it prints; wp_kses_post() would strip the confirm form.
			wp_die( self::notice_html() ?: '<p>' . esc_html__( 'Nothing to do here.', 'wp2-shopify' ) . '</p>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html__( 'Sign in', 'wp2-shopify' ), [ 'response' => 200 ] );
		}
	}

	/**
	 * Finish the flow. Returns where to send the browser next.
	 */
	private static function complete( string $state, string $code, bool $denied ): string {
		$data = StateStore::consume( $state );

		if ( ! $data ) {
			return self::notice_url( 'state_invalid' );
		}

		if ( $denied || '' === $code ) {
			return self::notice_url( 'shopify_denied' );
		}

		$tokens = OAuth::exchange( $code, (string) $data['verifier'] );

		if ( is_wp_error( $tokens ) ) {
			return self::notice_url( 'exchange_failed' );
		}

		if ( '' === $tokens['id_token'] ) {
			return self::notice_url( 'id_token_missing' );
		}

		$claims  = Pkce::jwt_claims( $tokens['id_token'] ) ?? [];
		$problem = Pkce::check_id_token_claims( $claims, Discovery::openid()['issuer'] ?? '', Config::get( 'WP2_SHOPIFY_CLIENT_ID' ), (string) $data['nonce'], time() );

		if ( $problem ) {
			Logger::log( 'auth.id_token_rejected', [ 'reason' => $problem ] );
			return self::notice_url( $problem );
		}

		$identity = CustomerAccount::identity( $tokens['access_token'] );
		$id       = is_wp_error( $identity ) ? null : CustomerId::parse( $identity['id'] );

		if ( ! $id ) {
			return self::notice_url( 'profile_failed' );
		}

		if ( ! empty( $data['link_user'] ) ) {
			return self::complete_link( (int) $data['link_user'], (string) $data['link_token'], $id, $identity, $claims, $tokens, (string) $data['redirect_to'] );
		}

		$user = Mirror::find_user( $id ) ?? ShadowUsers::create( $id, $identity );

		if ( is_wp_error( $user ) ) {
			Logger::log( 'auth.user_create_failed', [ 'customer' => $id->numeric ] );
			return self::notice_url( 'profile_failed' );
		}

		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		TokenVault::store( $user->ID, $tokens );
		self::apply_identity( $user->ID, $identity, 'login' );

		Logger::log( 'auth.signed_in', [ 'user' => $user->ID, 'customer' => $id->numeric ] );
		do_action( 'wp2_shopify_signed_in', $user->ID, $id );

		return (string) $data['redirect_to'];
	}

	private static function complete_link( int $user_id, string $link_token, CustomerId $id, array $identity, array $claims, array $tokens, string $redirect_to ): string {
		if ( get_current_user_id() !== $user_id ) {
			Linking::log_refusal( $user_id, 'link_user_mismatch' );
			return self::notice_url( 'link_user_mismatch' );
		}

		$problem = Linking::redeem( $user_id, $link_token )
			?? Linking::refusal( $user_id )
			?? Linking::email_check( $user_id, $claims, (string) $identity['email'] );

		$existing = Mirror::find_user( $id );

		if ( ! $problem && $existing && $existing->ID !== $user_id ) {
			$problem = 'customer_already_linked';
		}

		if ( $problem ) {
			Linking::log_refusal( $user_id, $problem );
			return self::notice_url( $problem );
		}

		$ref = Linking::hold(
			[
				'user_id'     => $user_id,
				'customer'    => $id->numeric,
				'email'       => (string) $identity['email'],
				'identity'    => $identity,
				'tokens'      => Plugin::crypto()->encrypt( (string) wp_json_encode( $tokens ) ),
				'redirect_to' => $redirect_to,
			]
		);

		return add_query_arg( 'wp2s_confirm', rawurlencode( $ref ), Config::callback_url() );
	}

	public static function handle_link_confirm(): void {
		check_admin_referer( 'wp2_shopify_link_confirm' );

		$ref     = sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) );
		$pending = Linking::take( $ref );
		$user_id = get_current_user_id();

		if ( ! $pending || (int) $pending['user_id'] !== $user_id ) {
			self::redirect( self::notice_url( 'link_token_missing' ) );
		}

		if ( empty( $_POST['confirm'] ) ) {
			self::redirect( self::notice_url( 'link_cancelled' ) );
		}

		$id       = CustomerId::parse( $pending['customer'] );
		$existing = $id ? Mirror::find_user( $id ) : null;

		if ( ! $id || ( $existing && $existing->ID !== $user_id ) || Linking::refusal( $user_id ) ) {
			self::redirect( self::notice_url( 'customer_already_linked' ) );
		}

		Mirror::link( $user_id, $id );
		self::apply_identity( $user_id, (array) $pending['identity'], 'link' );

		$tokens = json_decode( (string) Plugin::crypto()->decrypt( (string) $pending['tokens'] ), true );

		if ( is_array( $tokens ) ) {
			TokenVault::store( $user_id, $tokens );
		}

		Logger::log( 'link.linked', [ 'user' => $user_id, 'customer' => $id->numeric ] );
		do_action( 'wp2_shopify_linked', $user_id, $id );

		self::redirect( add_query_arg( 'wp2s_notice', 'linked', (string) $pending['redirect_to'] ) );
	}

	public static function handle_connect(): void {
		check_admin_referer( 'wp2_shopify_connect' );

		$user_id = get_current_user_id();
		$problem = Linking::refusal( $user_id ) ?? ( Mirror::customer_id( $user_id ) ? 'already_shadow' : null );

		if ( $problem ) {
			Linking::log_refusal( $user_id, $problem );
			self::redirect( self::notice_url( $problem ) );
		}

		$url = self::start( self::posted_redirect(), $user_id );

		self::redirect( is_wp_error( $url ) ? self::notice_url( 'unavailable' ) : $url, ! is_wp_error( $url ) );
	}

	// ----------------------------------------------------- Session actions

	/**
	 * End the WordPress session and the Shopify session. Returns the browser
	 * redirect, which goes through Shopify's end-session endpoint when there
	 * is an ID token to hint with.
	 */
	public static function logout( string $redirect_to ): string {
		$user_id  = get_current_user_id();
		$tokens   = TokenVault::get( $user_id );
		$redirect = self::safe_redirect( $redirect_to );

		do_action( 'wp2_shopify_before_logout', $user_id );

		if ( $user_id ) {
			wp_logout();
		}

		return OAuth::logout_url( (string) ( $tokens['id_token'] ?? '' ), $redirect );
	}

	public static function handle_logout(): void {
		if ( is_user_logged_in() ) {
			check_admin_referer( 'wp2_shopify_logout' );
		}

		self::redirect( self::logout( self::posted_redirect() ), true );
	}

	public static function disconnect( int $user_id ): string|\WP_Error {
		if ( ShadowUsers::is_shadow( $user_id ) ) {
			return new \WP_Error( 'already_shadow', self::MESSAGES['already_shadow'] );
		}

		$tokens = TokenVault::get( $user_id );

		TokenVault::forget_all( $user_id );
		Mirror::unlink( $user_id );
		Logger::log( 'link.disconnected', [ 'user' => $user_id ] );
		do_action( 'wp2_shopify_disconnected', $user_id );

		// The WordPress account stays signed in; the Shopify session is ended.
		return OAuth::logout_url( (string) ( $tokens['id_token'] ?? '' ), self::notice_url( 'disconnected' ) );
	}

	public static function handle_disconnect(): void {
		check_admin_referer( 'wp2_shopify_disconnect' );

		$url = self::disconnect( get_current_user_id() );

		self::redirect( is_wp_error( $url ) ? self::notice_url( $url->get_error_code() ) : $url, true );
	}

	/**
	 * "Sync now" for the signed-in customer only (spec 6.4). Rate limited.
	 */
	public static function sync_now( int $user_id ): string {
		$id = Mirror::customer_id( $user_id );

		if ( ! $id ) {
			return 'sync_failed';
		}

		$last = (int) get_user_meta( $user_id, '_wp2_shopify_last_sync_request', true );

		if ( time() - $last < MINUTE_IN_SECONDS ) {
			return 'sync_wait';
		}

		update_user_meta( $user_id, '_wp2_shopify_last_sync_request', time() );

		if ( Admin::available() ) {
			return is_wp_error( Reconciler::sync_user( $user_id, 'sync_now' ) ) ? 'sync_failed' : 'synced';
		}

		$token    = TokenVault::access_token( $user_id );
		$identity = $token ? CustomerAccount::identity( $token ) : null;

		if ( ! is_array( $identity ) ) {
			Mirror::mark_sync_failed( $user_id );
			return 'sync_failed';
		}

		self::apply_identity( $user_id, $identity, 'sync_now' );

		return 'synced';
	}

	public static function handle_sync(): void {
		check_admin_referer( 'wp2_shopify_sync' );

		self::redirect( add_query_arg( 'wp2s_notice', self::sync_now( get_current_user_id() ), self::posted_redirect() ) );
	}

	// ------------------------------------------------------------- Helpers

	public static function apply_identity( int $user_id, array $identity, string $source ): void {
		Mirror::set_profile(
			$user_id,
			[
				'first_name' => $identity['first_name'] ?? '',
				'last_name'  => $identity['last_name'] ?? '',
				'email'      => $identity['email'] ?? '',
			]
		);

		if ( isset( $identity['tags'] ) && is_array( $identity['tags'] ) ) {
			Mirror::set_tags( $user_id, $identity['tags'] );
		}

		Mirror::stamp( $user_id, $source );
	}

	public static function message( string $code ): string {
		return isset( self::MESSAGES[ $code ] ) ? __( self::MESSAGES[ $code ], 'wp2-shopify' ) : ''; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}

	public static function notice_url( string $code ): string {
		return add_query_arg( 'wp2s_notice', rawurlencode( $code ), Config::callback_url() );
	}

	/**
	 * The callback page shows the outcome of a flow, or the explicit confirm
	 * step for linking (spec 5.2 step 2).
	 */
	public static function callback_notice( string $content ): string {
		if ( ! get_query_var( self::QUERY_VAR ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return self::notice_html() . $content;
	}

	private static function notice_html(): string {
		// phpcs:disable WordPress.Security.NonceVerification -- display only.
		$notice = sanitize_key( wp_unslash( $_GET['wp2s_notice'] ?? '' ) );
		$ref    = sanitize_text_field( wp_unslash( $_GET['wp2s_confirm'] ?? '' ) );
		// phpcs:enable

		if ( '' !== $ref ) {
			$pending = Linking::peek( $ref );

			if ( ! $pending || (int) $pending['user_id'] !== get_current_user_id() ) {
				return '<div class="wp2-shopify-notice" role="alert"><p>' . esc_html( self::message( 'link_token_missing' ) ) . '</p></div>';
			}

			$user = wp_get_current_user();

			ob_start();
			?>
			<form class="wp2-shopify-notice wp2-shopify-link-confirm" method="post" action="<?php echo esc_url( self::action_url() ); ?>">
				<p>
					<?php
					printf(
						/* translators: 1: Shopify email, 2: WordPress username */
						esc_html__( 'Connect the Shopify customer %1$s to your account %2$s?', 'wp2-shopify' ),
						'<strong>' . esc_html( (string) $pending['email'] ) . '</strong>',
						'<strong>' . esc_html( $user->user_login ) . '</strong>'
					);
					?>
				</p>
				<input type="hidden" name="action" value="wp2_shopify_link_confirm">
				<input type="hidden" name="ref" value="<?php echo esc_attr( $ref ); ?>">
				<?php wp_nonce_field( 'wp2_shopify_link_confirm' ); ?>
				<button type="submit" name="confirm" value="1" class="wp-element-button"><?php esc_html_e( 'Connect accounts', 'wp2-shopify' ); ?></button>
				<button type="submit" name="cancel" value="1" class="wp-element-button is-style-outline"><?php esc_html_e( 'Cancel', 'wp2-shopify' ); ?></button>
			</form>
			<?php
			return (string) ob_get_clean();
		}

		$message = self::message( $notice );

		return '' === $message ? '' : '<div class="wp2-shopify-notice" role="status"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function posted_redirect(): string {
		return isset( $_POST['redirect_to'] ) ? self::safe_redirect( sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) ) : self::safe_redirect( (string) wp_get_referer() ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Redirect targets are allow-listed to this site (spec 13).
	 */
	public static function safe_redirect( string $url ): string {
		$fallback = (string) apply_filters( 'wp2_shopify_default_redirect', home_url( '/' ) );

		return wp_validate_redirect( '' !== $url ? $url : $fallback, $fallback );
	}

	/**
	 * @param bool $external True for Shopify-hosted targets (authorize, end-session).
	 */
	private static function redirect( string $url, bool $external = false ): never {
		nocache_headers();

		if ( $external ) {
			wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- Shopify endpoints from discovery.
		} else {
			wp_safe_redirect( $url );
		}

		exit;
	}
}
