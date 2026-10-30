<?php

namespace RedeemID\Privacy;

use RedeemID\Admin\Menu;
use RedeemID\Privacy;
use RedeemID\Shopify\Admin;
use RedeemID\Support\CustomerId;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;

/**
 * The staff-only "Also request erasure in Shopify" control on an erase
 * request's row. WordPress never erases Shopify data itself: it asks
 * Shopify (customerRequestDataErasure), Shopify queues the erasure and can
 * still cancel it, then sends customers/redact, which removes what WordPress
 * holds. Never automatic: a person decides on each request.
 */
final class ShopifyErasure {
	public const ACTION = 'redeemid_shopify_erasure';

	public const REQUESTED = 'requested';
	public const CANCELLED = 'cancelled';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
	}

	/**
	 * @return array{state: string, at: int, by: int, error: string}|null
	 */
	public static function state( int $request_id ): ?array {
		$state = get_post_meta( $request_id, Requests::ERASURE, true );

		return is_array( $state ) && isset( $state['state'] ) ? $state + [ 'at' => 0, 'by' => 0, 'error' => '' ] : null;
	}

	public static function controls( \WP_User_Request $item ): void {
		$customer = Requests::customer( $item );

		// Shopify already erased a redacted customer; there is nothing left to ask.
		if ( '' === $customer || get_post_meta( $item->ID, Requests::FIXTURE, true ) || get_post_meta( $item->ID, Requests::REDACTED, true ) ) {
			return;
		}

		$scope = Admin::erasure_scope();

		$state = self::state( $item->ID );

		if ( $state && self::REQUESTED === $state['state'] ) {
			echo '<br><span class="redeemid-status is-ok">' . esc_html(
				sprintf(
					/* translators: %s: date */
					__( 'Erasure requested in Shopify on %s', 'redeemid' ),
					wp_date( (string) get_option( 'date_format' ), (int) $state['at'] )
				)
			) . '</span><br><span class="description">' . esc_html__( 'Shopify finishes it after its waiting period, then tells WordPress.', 'redeemid' ) . '</span>';

			if ( false !== $scope ) {
				self::button( $item->ID, 'cancel', __( 'Cancel the Shopify erasure', 'redeemid' ), '' );
			}

			return;
		}

		if ( $state && '' !== $state['error'] ) {
			/* translators: %s: Shopify's error message */
			echo '<br><span class="redeemid-status is-error">' . esc_html( sprintf( __( 'Shopify refused: %s', 'redeemid' ), $state['error'] ) ) . '</span>';
		}

		if ( false === $scope ) {
			echo '<br><span class="description">' . esc_html(
				sprintf(
					/* translators: %s: Admin API scope */
					__( 'To ask Shopify to erase from here, add the %s scope.', 'redeemid' ),
					Admin::ERASURE_SCOPE
				)
			) . '</span>';

			return;
		}

		self::button(
			$item->ID,
			'request',
			__( 'Also request erasure in Shopify', 'redeemid' ),
			__( 'Ask Shopify to erase this customer\'s personal data, orders included, for the whole store? Shopify waits before it erases, and you can cancel until then.', 'redeemid' )
		);
	}

	/**
	 * A nonced link, not a form: the row sits inside the list table's own
	 * bulk-action form, and a nested form would submit that one instead.
	 */
	private static function button( int $request_id, string $do, string $label, string $confirm ): void {
		$url = wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'do' => $do, 'request' => $request_id ], admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $request_id );

		printf(
			'<br><a class="button button-small" href="%s"%s>%s</a>',
			esc_url( $url ),
			'' !== $confirm ? ' onclick="return confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ')"' : '',
			esc_html( $label )
		);
	}

	public static function handle(): never {
		$request_id = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- checked by Menu::guard next.
		Menu::guard( self::ACTION . '_' . $request_id );

		$do      = sanitize_key( wp_unslash( $_GET['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- Menu::guard checked it.
		$request = wp_get_user_request( $request_id );
		$back    = Requests::screen_url( Requests::ERASE );

		if ( ! $request || Requests::ERASE !== $request->action_name || ! in_array( $do, [ 'request', 'cancel' ], true ) ) {
			self::back( $back, 'shopify_erasure_invalid' );
		}

		$customer = CustomerId::parse( Requests::customer( $request ) );

		if ( ! $customer ) {
			self::back( $back, 'shopify_erasure_no_customer' );
		}

		if ( get_post_meta( $request_id, Requests::FIXTURE, true ) || self::is_fixture( $request ) ) {
			self::back( $back, 'shopify_erasure_fixture' );
		}

		if ( ! Admin::can_request_erasure() ) {
			self::back( $back, 'shopify_erasure_scope' );
		}

		$result = 'request' === $do ? Admin::request_erasure( $customer ) : Admin::cancel_erasure( $customer );
		$state  = [
			'state' => 'request' === $do ? self::REQUESTED : self::CANCELLED,
			'at'    => time(),
			'by'    => get_current_user_id(),
			'error' => '',
		];

		if ( is_wp_error( $result ) ) {
			$state = [ 'state' => (string) ( self::state( $request_id )['state'] ?? '' ), 'at' => time(), 'by' => get_current_user_id(), 'error' => $result->get_error_message() ];
		}

		update_post_meta( $request_id, Requests::ERASURE, $state );
		update_post_meta( $request_id, Requests::CUSTOMER, $customer->numeric );

		Logger::log(
			'request' === $do ? 'privacy.erasure_relayed' : 'privacy.erasure_cancelled',
			[ 'request' => $request_id, 'status' => is_wp_error( $result ) ? $result->get_error_code() : 'ok' ]
		);

		if ( ! is_wp_error( $result ) && 'request' === $do ) {
			/**
			 * Fires after staff asked Shopify to erase a customer from an erase request.
			 *
			 * @param int    $request_id   The user_request post.
			 * @param string $customer_gid The Shopify customer GID.
			 */
			do_action( 'redeemid_shopify_erasure_requested', $request_id, $customer->gid() );
		}

		self::back( $back, is_wp_error( $result ) ? 'shopify_erasure_failed' : ( 'request' === $do ? 'shopify_erasure_requested' : 'shopify_erasure_cancelled' ) );
	}

	private static function is_fixture( \WP_User_Request $request ): bool {
		$user = Privacy::user_for( $request->email );

		return $user && Mirror::is_fixture( $user->ID );
	}

	private static function back( string $url, string $code ): never {
		wp_safe_redirect( add_query_arg( 'redeemid_privacy', $code, $url ) );
		exit;
	}

	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'erase-personal-data' !== $screen->id ) {
			return;
		}

		$code     = sanitize_key( wp_unslash( $_GET['redeemid_privacy'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$messages = [
			'shopify_erasure_requested'   => [ 'success', __( 'Shopify has the erasure request. It erases the customer after its waiting period, then RedeemID removes what is left here.', 'redeemid' ) ],
			'shopify_erasure_cancelled'   => [ 'success', __( 'The Shopify erasure was cancelled.', 'redeemid' ) ],
			'shopify_erasure_failed'      => [ 'error', __( 'Shopify refused the request. The reason is on the request\'s row.', 'redeemid' ) ],
			'shopify_erasure_scope'       => [ 'error', sprintf( /* translators: %s: Admin API scope */ __( 'The Admin app needs the %s scope.', 'redeemid' ), Admin::ERASURE_SCOPE ) ],
			'shopify_erasure_fixture'     => [ 'warning', __( 'That customer is a test fixture and does not exist in Shopify.', 'redeemid' ) ],
			'shopify_erasure_no_customer' => [ 'error', __( 'That request is not linked to a Shopify customer.', 'redeemid' ) ],
			'shopify_erasure_invalid'     => [ 'error', __( 'That request could not be found.', 'redeemid' ) ],
		];

		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
		}
	}
}
