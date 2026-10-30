<?php

namespace RedeemID\Auth;

use RedeemID\Access\LevelStore;
use RedeemID\Access\Membership;
use RedeemID\Config;
use RedeemID\Plugin;
use RedeemID\Shopify\Admin;
use RedeemID\Shopify\CustomerAccount;
use RedeemID\Shopify\Discovery;
use RedeemID\Support\CustomerId;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;
use RedeemID\Sync\Reconciler;

/**
 * Sign-in, linking, logout, disconnect and sync-now as server-side flows.
 *
 * Every user-facing action is a real link or form post that works without
 * JavaScript: forms post to admin-post.php and redirect, so
 * the visitor always lands on a page, never on raw JSON.
 */
final class Flow {
	public const QUERY_VAR = 'redeemid_callback';

	/** When the member last asked for a sync now (rate limit). */
	public const SYNC_REQUEST = '_redeemid_last_sync_request';

	public static function register(): void {
		add_action( 'init', [ self::class, 'add_rewrite' ] );
		add_filter( 'query_vars', static fn( $vars ) => array_merge( $vars, [ self::QUERY_VAR ] ) );
		add_action( 'template_redirect', [ self::class, 'maybe_handle_callback' ], 0 );
		add_filter( 'the_content', [ self::class, 'callback_notice' ], 5 );
		add_action( 'set_auth_cookie', [ TokenVault::class, 'capture_session_token' ], 10, 6 );

		foreach ( [ 'login', 'logout', 'new_account' ] as $action ) {
			add_action( 'admin_post_redeemid_' . $action, [ self::class, 'handle_' . $action ] );
			add_action( 'admin_post_nopriv_redeemid_' . $action, [ self::class, 'handle_' . $action ] );
		}

		foreach ( [ 'connect', 'link_confirm', 'disconnect', 'sync' ] as $action ) {
			add_action( 'admin_post_redeemid_' . $action, [ self::class, 'handle_' . $action ] );
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
				'action'      => 'redeemid_login',
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
		if ( ! Config::has( 'REDEEMID_CLIENT_ID' ) || ! Discovery::available() ) {
			return new \WP_Error( 'unavailable', self::message( 'unavailable' ) );
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
			wp_die( self::notice_html() ?: '<p>' . esc_html__( 'Nothing to do here.', 'redeemid' ) . '</p>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html__( 'Sign in', 'redeemid' ), [ 'response' => 200 ] );
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
		$problem = Pkce::check_id_token_claims( $claims, Discovery::openid()['issuer'] ?? '', Config::get( 'REDEEMID_CLIENT_ID' ), (string) $data['nonce'], time() );

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

		$user = Mirror::find_user( $id );

		if ( ! $user ) {
			$match = self::existing_account( $claims );

			if ( $match ) {
				// Never link by email on its own: ask, so their history isn't split into two accounts.
				Logger::log( 'auth.existing_account', [ 'user' => $match->ID, 'customer' => $id->numeric ] );

				return add_query_arg(
					'redeemid_existing',
					rawurlencode(
						Linking::hold(
							[
								'kind'        => 'existing',
								'user_id'     => $match->ID,
								'customer'    => $id->numeric,
								'email'       => (string) ( $claims['email'] ?? '' ),
								'identity'    => $identity,
								'tokens'      => Plugin::crypto()->encrypt( (string) wp_json_encode( $tokens ) ),
								'redirect_to' => (string) $data['redirect_to'],
							]
						)
					),
					Config::callback_url()
				);
			}

			$user = ShadowUsers::create( $id, $identity );
		}

		if ( is_wp_error( $user ) ) {
			Logger::log( 'auth.user_create_failed', [ 'customer' => $id->numeric ] );
			return self::notice_url( 'profile_failed' );
		}

		return self::sign_in( $user, $id, $identity, $tokens, (string) $data['redirect_to'] );
	}

	/**
	 * An existing WordPress account, not linked to Shopify and not staff, whose
	 * email Shopify has verified as this customer's. Null when there is none.
	 */
	private static function existing_account( array $claims ): ?\WP_User {
		$email = strtolower( trim( (string) ( $claims['email'] ?? '' ) ) );

		if ( true !== ( $claims['email_verified'] ?? null ) || '' === $email || ! apply_filters( 'redeemid_offer_existing_account', true ) ) {
			return null;
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user || ShadowUsers::is_shadow( $user->ID ) || Mirror::customer_id( $user->ID ) || Linking::privileged( $user ) ) {
			return null;
		}

		return $user;
	}

	/**
	 * "Use a separate account" from the existing-account prompt: create the
	 * shadow account after all and sign in.
	 */
	public static function handle_new_account(): void {
		$pending = Linking::take( sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- the single-use ref is the secret; the visitor is a guest.

		if ( ! $pending || 'existing' !== ( $pending['kind'] ?? '' ) ) {
			self::redirect( self::notice_url( 'existing_expired' ) );
		}

		$id     = CustomerId::parse( (string) $pending['customer'] );
		$tokens = json_decode( (string) Plugin::crypto()->decrypt( (string) $pending['tokens'] ), true );

		if ( ! $id || ! is_array( $tokens ) ) {
			self::redirect( self::notice_url( 'existing_expired' ) );
		}

		$user = Mirror::find_user( $id ) ?? ShadowUsers::create( $id, (array) $pending['identity'] );

		if ( is_wp_error( $user ) ) {
			self::redirect( self::notice_url( 'profile_failed' ) );
		}

		Logger::log( 'auth.separate_account', [ 'user' => $user->ID, 'customer' => $id->numeric ] );
		self::redirect( self::sign_in( $user, $id, (array) $pending['identity'], $tokens, (string) $pending['redirect_to'] ) );
	}

	/**
	 * Sign $user in as Shopify customer $id. Returns where to go next.
	 */
	private static function sign_in( \WP_User $user, CustomerId $id, array $identity, array $tokens, string $redirect_to ): string {
		// A linked account can gain a privileged role after linking; the link-time check alone would let it in.
		if ( Linking::privileged( $user ) ) {
			Logger::log( 'auth.sign_in_refused', [ 'user' => $user->ID, 'customer' => $id->numeric ] );
			return self::notice_url( 'admin_sign_in_refused' );
		}

		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		TokenVault::store( $user->ID, $tokens );
		self::apply_identity( $user->ID, $identity, 'login' );

		self::full_sync( $user->ID, 'login' );

		Logger::log( 'auth.signed_in', [ 'user' => $user->ID, 'customer' => $id->numeric ] );
		do_action( 'redeemid_signed_in', $user->ID, $id );

		return self::landing( $user->ID, $redirect_to );
	}

	/**
	 * Where a member goes after signing in: back to the page they started
	 * from, unless that was the home page (or nowhere in particular) and one of
	 * their levels has a members' home.
	 */
	private static function landing( int $user_id, string $redirect_to ): string {
		$home   = untrailingslashit( self::safe_redirect( '' ) );
		$target = $redirect_to;

		if ( '' === $redirect_to || untrailingslashit( $redirect_to ) === $home ) {
			$levels = LevelStore::levels();
			$level  = Membership::home( $levels, Membership::top( $levels, Membership::held( $levels, Mirror::member( $user_id ), time() ) ) );
			$target = '' !== $level ? self::safe_redirect( $level ) : $redirect_to;
		}

		/**
		 * Where a customer lands after Shopify sign-in.
		 *
		 * @param string $target      The page chosen.
		 * @param int    $user_id
		 * @param string $redirect_to Where sign-in started.
		 */
		return self::safe_redirect( (string) apply_filters( 'redeemid_sign_in_redirect', $target, $user_id, $redirect_to ) );
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

		return add_query_arg( 'redeemid_confirm', rawurlencode( $ref ), Config::callback_url() );
	}

	public static function handle_link_confirm(): void {
		check_admin_referer( 'redeemid_link_confirm' );

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
		self::full_sync( $user_id, 'link' );

		$tokens = json_decode( (string) Plugin::crypto()->decrypt( (string) $pending['tokens'] ), true );

		if ( is_array( $tokens ) ) {
			TokenVault::store( $user_id, $tokens );
		}

		Logger::log( 'link.linked', [ 'user' => $user_id, 'customer' => $id->numeric ] );
		do_action( 'redeemid_linked', $user_id, $id );

		self::redirect( add_query_arg( 'redeemid_notice', 'linked', (string) $pending['redirect_to'] ) );
	}

	public static function handle_connect(): void {
		check_admin_referer( 'redeemid_connect' );

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

		do_action( 'redeemid_before_logout', $user_id );

		if ( $user_id ) {
			wp_logout();
		}

		return OAuth::logout_url( (string) ( $tokens['id_token'] ?? '' ), $redirect );
	}

	public static function handle_logout(): void {
		if ( is_user_logged_in() ) {
			check_admin_referer( 'redeemid_logout' );
		}

		self::redirect( self::logout( self::posted_redirect() ), true );
	}

	public static function disconnect( int $user_id ): string|\WP_Error {
		if ( ShadowUsers::is_shadow( $user_id ) ) {
			return new \WP_Error( 'already_shadow', self::message( 'already_shadow' ) );
		}

		$tokens = TokenVault::get( $user_id );

		TokenVault::forget_all( $user_id );
		Mirror::unlink( $user_id );
		Logger::log( 'link.disconnected', [ 'user' => $user_id ] );
		do_action( 'redeemid_disconnected', $user_id );

		// The WordPress account stays signed in; the Shopify session is ended.
		return OAuth::logout_url( (string) ( $tokens['id_token'] ?? '' ), self::notice_url( 'disconnected' ) );
	}

	public static function handle_disconnect(): void {
		check_admin_referer( 'redeemid_disconnect' );

		$url = self::disconnect( get_current_user_id() );

		self::redirect( is_wp_error( $url ) ? self::notice_url( $url->get_error_code() ) : $url, true );
	}

	/**
	 * "Sync now" for the signed-in customer only. Rate limited.
	 */
	public static function sync_now( int $user_id ): string {
		$id = Mirror::customer_id( $user_id );

		if ( ! $id ) {
			return 'sync_failed';
		}

		/**
		 * Answer a "sync now" without asking Shopify: return synced, sync_wait or
		 * sync_failed to short-circuit, or null to sync as usual. For customers
		 * Shopify does not know, such as a test site's simulated fixtures (see
		 * redeemid_customer_is_fixture). Runs before the rate limit.
		 */
		$pre = apply_filters( 'redeemid_pre_sync_now', null, $user_id );

		if ( in_array( $pre, [ 'synced', 'sync_wait', 'sync_failed' ], true ) ) {
			return $pre;
		}

		$last =(int) get_user_meta( $user_id, self::SYNC_REQUEST, true );

		if ( time() - $last < MINUTE_IN_SECONDS ) {
			return 'sync_wait';
		}

		update_user_meta( $user_id, self::SYNC_REQUEST, time() );

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
		check_admin_referer( 'redeemid_sync' );

		self::redirect( add_query_arg( 'redeemid_notice', self::sync_now( get_current_user_id() ), self::posted_redirect() ) );
	}

	// ------------------------------------------------------------- Helpers

	/**
	 * The identity from sign-in carries tags only. With the Admin API, signing
	 * in or linking is a full sync: account status and the membership dates
	 * too. A failure is recorded and never blocks the sign-in. A visitor who
	 * comes back to a WordPress session that is still signed in doesn't sync.
	 */
	private static function full_sync( int $user_id, string $source ): void {
		if ( Admin::available() ) {
			Reconciler::sync_user( $user_id, $source );
		}
	}

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
		return match ( $code ) {
			'state_invalid'                                                                                  => __( 'Your sign-in link expired or was opened in a different browser. Please try again.', 'redeemid' ),
			'shopify_denied'                                                                                 => __( 'Sign-in was cancelled at Shopify.', 'redeemid' ),
			'exchange_failed'                                                                                => __( 'Shopify sign-in could not be completed. Please try again.', 'redeemid' ),
			'profile_failed'                                                                                 => __( 'Your Shopify profile could not be loaded. Please try again.', 'redeemid' ),
			'unavailable'                                                                                    => __( 'Sign-in is temporarily unavailable.', 'redeemid' ),
			'id_token_missing', 'id_token_issuer', 'id_token_audience', 'id_token_expired', 'id_token_nonce' => __( 'Shopify sign-in could not be verified. Please try again.', 'redeemid' ),
			'link_token_missing', 'link_token_mismatch'                                                      => __( 'That connect request is no longer valid. Start again from your account.', 'redeemid' ),
			'link_token_expired'                                                                             => __( 'That connect request expired. Start again from your account.', 'redeemid' ),
			'link_session_mismatch'                                                                          => __( 'That connect request belongs to a different session. Start again from your account.', 'redeemid' ),
			'link_user_mismatch'                                                                             => __( 'Please sign in to the WordPress account you want to connect, then try again.', 'redeemid' ),
			'admin_refused'                                                                                  => __( 'Administrator accounts cannot be connected to a Shopify customer.', 'redeemid' ),
			'admin_sign_in_refused'                                                                          => __( 'Administrator accounts cannot sign in with Shopify. Use the WordPress login instead.', 'redeemid' ),
			'already_shadow'                                                                                 => __( 'This account already signs in with Shopify.', 'redeemid' ),
			'email_unverified'                                                                               => __( 'Shopify did not confirm that email address, so the accounts were not connected.', 'redeemid' ),
			'email_mismatch'                                                                                 => __( 'Your Shopify email does not match this account\'s email, so the accounts were not connected.', 'redeemid' ),
			'customer_already_linked'                                                                        => __( 'That Shopify customer is already connected to another account.', 'redeemid' ),
			'link_cancelled'                                                                                 => __( 'The accounts were not connected.', 'redeemid' ),
			'linked'                                                                                         => __( 'Your Shopify account is now connected.', 'redeemid' ),
			'disconnected'                                                                                   => __( 'Your Shopify account was disconnected.', 'redeemid' ),
			'synced'                                                                                         => __( 'Your membership details were refreshed.', 'redeemid' ),
			'sync_failed'                                                                                    => __( 'Your details could not be refreshed right now. Please try again later.', 'redeemid' ),
			'sync_wait'                                                                                      => __( 'Your details were refreshed a moment ago.', 'redeemid' ),
			'existing_expired'                                                                               => __( 'That sign-in expired. Please log in with Shopify again.', 'redeemid' ),
			default                                                                                          => '',
		};
	}

	public static function notice_url( string $code ): string {
		return add_query_arg( 'redeemid_notice', rawurlencode( $code ), Config::callback_url() );
	}

	/**
	 * The callback page shows the outcome of a flow, or the explicit confirm
	 * step for linking.
	 */
	public static function callback_notice( string $content ): string {
		if ( ! get_query_var( self::QUERY_VAR ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return self::notice_html() . $content;
	}

	private static function notice_html(): string {
		// phpcs:disable WordPress.Security.NonceVerification -- display only.
		$notice   = sanitize_key( wp_unslash( $_GET['redeemid_notice'] ?? '' ) );
		$ref      = sanitize_text_field( wp_unslash( $_GET['redeemid_confirm'] ?? '' ) );
		$existing = sanitize_text_field( wp_unslash( $_GET['redeemid_existing'] ?? '' ) );
		$connect  = ! empty( $_GET['redeemid_connect'] );
		$back     = self::safe_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ?? '' ) ) );
		// phpcs:enable

		if ( '' !== $existing ) {
			return self::existing_html( $existing );
		}

		if ( $connect && is_user_logged_in() && ! Mirror::customer_id( get_current_user_id() ) && ! Linking::refusal( get_current_user_id() ) ) {
			ob_start();
			?>
			<form class="redeemid-notice redeemid-link-confirm" method="post" action="<?php echo esc_url( self::action_url() ); ?>">
				<p><?php esc_html_e( 'You\'re signed in. Connect your Shopify account to this one so your membership works here.', 'redeemid' ); ?></p>
				<input type="hidden" name="action" value="redeemid_connect">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $back ); ?>">
				<?php wp_nonce_field( 'redeemid_connect' ); ?>
				<button type="submit" class="wp-element-button"><?php esc_html_e( 'Connect your Shopify account', 'redeemid' ); ?></button>
			</form>
			<?php
			return (string) ob_get_clean();
		}

		if ( '' !== $ref ) {
			$pending = Linking::peek( $ref );

			if ( ! $pending || (int) $pending['user_id'] !== get_current_user_id() ) {
				return '<div class="redeemid-notice" role="alert"><p>' . esc_html( self::message( 'link_token_missing' ) ) . '</p></div>';
			}

			$user = wp_get_current_user();

			ob_start();
			?>
			<form class="redeemid-notice redeemid-link-confirm" method="post" action="<?php echo esc_url( self::action_url() ); ?>">
				<p>
					<?php
					printf(
						/* translators: 1: Shopify email, 2: WordPress username */
						esc_html__( 'Connect the Shopify customer %1$s to your account %2$s?', 'redeemid' ),
						'<strong>' . esc_html( (string) $pending['email'] ) . '</strong>',
						'<strong>' . esc_html( $user->user_login ) . '</strong>'
					);
					?>
				</p>
				<input type="hidden" name="action" value="redeemid_link_confirm">
				<input type="hidden" name="ref" value="<?php echo esc_attr( $ref ); ?>">
				<?php wp_nonce_field( 'redeemid_link_confirm' ); ?>
				<button type="submit" name="confirm" value="1" class="wp-element-button"><?php esc_html_e( 'Connect accounts', 'redeemid' ); ?></button>
				<button type="submit" name="cancel" value="1" class="wp-element-button is-style-outline"><?php esc_html_e( 'Cancel', 'redeemid' ); ?></button>
			</form>
			<?php
			return (string) ob_get_clean();
		}

		$message = self::message( $notice );

		return '' === $message ? '' : '<div class="redeemid-notice" role="status"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Shopify verified an email that an existing account here uses. Offer to
	 * sign in to that account and connect it, or to carry on separately.
	 */
	private static function existing_html( string $ref ): string {
		$pending = Linking::peek( $ref );

		if ( ! $pending || 'existing' !== ( $pending['kind'] ?? '' ) ) {
			return '<div class="redeemid-notice" role="alert"><p>' . esc_html( self::message( 'existing_expired' ) ) . '</p></div>';
		}

		$after = add_query_arg(
			[
				'redeemid_connect' => 1,
				'redirect_to'  => rawurlencode( (string) $pending['redirect_to'] ),
			],
			Config::callback_url()
		);

		ob_start();
		?>
		<div class="redeemid-notice redeemid-existing">
			<p>
				<?php
				printf(
					/* translators: %s: email address */
					esc_html__( 'You already have an account on this site with %s. Sign in to it and connect Shopify, so your comments, history and membership stay in one place.', 'redeemid' ),
					'<strong>' . esc_html( (string) $pending['email'] ) . '</strong>'
				);
				?>
			</p>
			<p>
				<a class="wp-element-button" href="<?php echo esc_url( wp_login_url( $after ) ); ?>"><?php esc_html_e( 'Sign in to my account', 'redeemid' ); ?></a>
			</p>
			<form method="post" action="<?php echo esc_url( self::action_url() ); ?>">
				<input type="hidden" name="action" value="redeemid_new_account">
				<input type="hidden" name="ref" value="<?php echo esc_attr( $ref ); ?>">
				<button type="submit" class="redeemid-link-button"><?php esc_html_e( 'No thanks, use a separate account', 'redeemid' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function posted_redirect(): string {
		return isset( $_POST['redirect_to'] ) ? self::safe_redirect( sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) ) : self::safe_redirect( (string) wp_get_referer() ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Redirect targets are allow-listed to this site.
	 */
	public static function safe_redirect( string $url ): string {
		$fallback = (string) apply_filters( 'redeemid_default_redirect', home_url( '/' ) );

		return wp_validate_redirect( '' !== $url ? $url : $fallback, $fallback );
	}

	/**
	 * @param bool $external True for Shopify-hosted targets (authorize, end-session).
	 */
	private static function redirect( string $url, bool $external = false ): never {
		nocache_headers();

		if ( $external ) {
			wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect, WordPressVIPMinimum.Security.ExitAfterRedirect -- Shopify endpoints from discovery; exits below.
		} else {
			wp_safe_redirect( $url ); // phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect -- exits below.
		}

		exit;
	}
}
