<?php

namespace WP2_Shopify\Rest;

use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Auth\ShadowUsers;
use WP2_Shopify\Shopify\Discovery;
use WP2_Shopify\Sync\Mirror;

/**
 * The visitor's own display-safe state, for GET /auth/status and for
 * scripts that are not blocks (the cart bridge). Blocks with personal output
 * are Blockstudio dynamic islands instead.
 */
final class Status {
	public const AJAX_ACTION = 'wp2_shopify_status';

	public static function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ self::class, 'ajax' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ self::class, 'ajax' ] );
	}

	public static function for_current_user(): array {
		$user_id = get_current_user_id();

		$status = [
			'signedIn'  => $user_id > 0,
			'linked'    => false,
			'available' => Discovery::available(),
			'loginUrl'  => Flow::login_url(),
		];

		if ( ! $user_id ) {
			return $status;
		}

		$user   = wp_get_current_user();
		$linked = null !== Mirror::customer_id( $user_id );

		return array_merge(
			$status,
			[
				'linked'      => $linked,
				'shadow'      => ShadowUsers::is_shadow( $user_id ),
				'displayName' => $user->display_name,
				'lastSync'    => (int) get_user_meta( $user_id, Mirror::LAST_SYNC, true ) ?: null,
				'actionUrl'   => Flow::action_url(),
				// Fresh per-user nonces for the hydrated forms; never baked into cacheable HTML.
				'nonces'      => [
					'logout'  => wp_create_nonce( 'wp2_shopify_logout' ),
					'connect' => wp_create_nonce( 'wp2_shopify_connect' ),
					'rest'    => wp_create_nonce( 'wp_rest' ),
				],
			]
		);
	}

	/**
	 * admin-ajax is used for hydration because it authenticates by cookie
	 * without a pre-issued nonce and, unlike the REST API, sends no CORS
	 * headers, so other origins cannot read the response.
	 */
	public static function ajax(): void {
		nocache_headers();

		$site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';

		if ( '' !== $site && 'same-origin' !== $site ) {
			wp_send_json( [ 'signedIn' => false ], 403 );
		}

		wp_send_json( self::for_current_user() );
	}
}
