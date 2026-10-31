<?php

namespace RedeemID\Api;

use RedeemID\Access\Meter;
use RedeemID\Auth\Flow;
use RedeemID\Commerce\BuyNow;
use RedeemID\Rest\Routes;
use RedeemID\Sync\Mirror;

/**
 * The browser client, window.redeemid (assets/client.js), and the reader
 * state it reads.
 *
 * The script is registered on every front-end page and enqueued by nobody:
 * it loads only when a theme or plugin lists the "redeemid-client" handle as
 * a dependency, or enqueues it. Its config holds no personal data and no
 * nonce, so pages that carry it stay cacheable; the reader's state comes
 * from admin-ajax on demand (same-origin only, never cached), or from
 * GET redeemid/v1/state for callers that already hold a REST nonce.
 *
 * shape() is pure.
 */
final class Client {
	public const AJAX_ACTION = 'redeemid_state';

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'register_script' ], 1 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ self::class, 'ajax' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ self::class, 'ajax' ] );
		add_action( 'rest_api_init', [ self::class, 'rest' ] );
	}

	public static function register_script(): void {
		wp_register_script( Contract::CLIENT_HANDLE, REDEEMID_URL . 'assets/client.js', [], REDEEMID_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );

		/**
		 * Filter the browser client's config. It is printed into cacheable
		 * pages: never add personal data or a nonce.
		 *
		 * @since 0.0.4
		 *
		 * @param array $config
		 */
		$config = apply_filters(
			'redeemid_client_config',
			[
				'version'      => Contract::VERSION,
				'eventVersion' => Events::VERSION,
				'stateUrl'     => add_query_arg( 'action', self::AJAX_ACTION, admin_url( 'admin-ajax.php' ) ),
				'restUrl'      => rest_url( Routes::NS . '/' ),
				'loginUrl'     => Flow::login_url(),
				'buyAction'    => BuyNow::ACTION,
			]
		);

		wp_add_inline_script( Contract::CLIENT_HANDLE, 'window.redeemidClientConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function rest(): void {
		register_rest_route(
			Routes::NS,
			'/state',
			[
				'methods'             => 'GET',
				'callback'            => static function () {
					$response = new \WP_REST_Response( self::current() );
					$response->header( 'Cache-Control', 'private, no-store, max-age=0' );

					return $response;
				},
				'permission_callback' => '__return_true', // The caller's own state only; guests get the guest shape.
			]
		);
	}

	/**
	 * Same-origin only, like Status::ajax(): other origins can't read a
	 * reader's levels.
	 */
	public static function ajax(): void {
		nocache_headers();

		$site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';

		if ( '' !== $site && 'same-origin' !== $site ) {
			wp_send_json( self::shape( false, false, [], [], null, '' ), 403 );
		}

		wp_send_json( self::current() );
	}

	/**
	 * The current visitor's state.
	 *
	 * @return array<string, mixed>
	 */
	public static function current(): array {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return self::shape( false, false, [], [], Meter::on() ? Meter::state( 0 ) : null, '' );
		}

		return self::shape(
			true,
			null !== Mirror::customer_id( $user_id ),
			redeemid_user_levels( $user_id ),
			array_keys( redeemid_granted_features( $user_id ) ),
			Meter::on() ? Meter::state( $user_id ) : null,
			wp_create_nonce( 'wp_rest' )
		);
	}

	/**
	 * The state as the client gets it. No tags, no email, no customer ID.
	 *
	 * @param string[]                                                 $levels
	 * @param string[]                                                 $features
	 * @param array{on: bool, used: int, limit: int, period: string}|null $meter
	 * @return array{apiVersion: string, signedIn: bool, linked: bool, levels: string[], features: string[], meter: array{used: int, limit: int, period: string}|null, nonce: string}
	 */
	public static function shape( bool $signed_in, bool $linked, array $levels, array $features, ?array $meter, string $nonce ): array {
		return [
			'apiVersion' => Contract::VERSION,
			'signedIn'   => $signed_in,
			'linked'     => $signed_in && $linked,
			'levels'     => $signed_in ? array_values( array_map( 'strval', $levels ) ) : [],
			'features'   => $signed_in ? array_values( array_map( 'strval', $features ) ) : [],
			'meter'      => $meter ? [ 'used' => $signed_in ? (int) $meter['used'] : 0, 'limit' => (int) $meter['limit'], 'period' => (string) $meter['period'] ] : null,
			'nonce'      => $signed_in ? $nonce : '',
		];
	}
}
