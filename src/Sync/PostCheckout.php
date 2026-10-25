<?php

namespace WP2_Shopify\Sync;

use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Shopify\Admin;

/**
 * Closes the gap between paying and access.
 *
 * Buy-now and the cart bridge call mark() for a signed-in, linked customer on
 * their way to checkout, with the page they came from. For the next 30
 * minutes their signed-in page views re-read their tags (at most once a
 * minute) while Shopify Flow or another app tags them and the webhook is in
 * flight, and a banner says what is happening: "finishing up", then "ready".
 *
 * Shopify's order status page and confirmation email can link back to
 * /?wp2s_return=1 (see docs/shopify-flow.md). A marked customer lands on the
 * page they bought from; a guest is asked to log in with Shopify using the
 * email from their order, which is what unlocks a membership bought as a guest.
 */
final class PostCheckout {
	public const META       = '_wp2_shopify_checkout_started';
	public const RETURN_ARG = 'wp2s_return';
	private const ATTEMPT   = '_wp2_shopify_post_checkout_attempt';
	private const BACK      = '_wp2_shopify_checkout_return';
	private const READY     = '_wp2_shopify_checkout_ready';
	private const BASE      = '_wp2_shopify_checkout_tags';
	private const WINDOW    = 30 * MINUTE_IN_SECONDS;
	private const INTERVAL  = MINUTE_IN_SECONDS;

	public static function register(): void {
		add_action( 'template_redirect', [ self::class, 'maybe_return' ], 0 );
		add_action( 'template_redirect', [ self::class, 'maybe_sync' ], 1 );
		add_action( 'wp_body_open', [ self::class, 'banner' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue(): void {
		wp_enqueue_style( 'wp2-shopify-front', WP2_SHOPIFY_URL . 'assets/front.css', [], WP2_SHOPIFY_VERSION );
	}

	/**
	 * A linked customer is heading to checkout from $from.
	 */
	public static function mark( int $user_id, string $from = '' ): void {
		if ( ! $user_id || ! Mirror::customer_id( $user_id ) ) {
			return;
		}

		update_user_meta( $user_id, self::META, time() );
		update_user_meta( $user_id, self::BASE, self::fingerprint( $user_id ) );
		delete_user_meta( $user_id, self::ATTEMPT );
		delete_user_meta( $user_id, self::READY );

		if ( '' !== $from ) {
			update_user_meta( $user_id, self::BACK, Flow::safe_redirect( $from ) );
		}
	}

	/**
	 * Whether the customer checked out in the last 30 minutes and access hasn't changed since.
	 */
	public static function active( int $user_id ): bool {
		$started = $user_id ? (int) get_user_meta( $user_id, self::META, true ) : 0;

		return $started > 0 && time() - $started <= self::WINDOW;
	}

	/**
	 * The link Shopify's order status page and emails point at.
	 */
	public static function return_url(): string {
		return add_query_arg( self::RETURN_ARG, '1', home_url( '/' ) );
	}

	public static function maybe_return(): void {
		if ( empty( $_GET[ self::RETURN_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a plain redirect.
			return;
		}

		nocache_headers();
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_safe_redirect( add_query_arg( 'wp2s_notice', 'checkout_guest', home_url( '/' ) ) );
			exit;
		}

		$back = (string) get_user_meta( $user_id, self::BACK, true );

		wp_safe_redirect( '' !== $back ? $back : home_url( '/' ) );
		exit;
	}

	public static function maybe_sync(): void {
		$user_id = get_current_user_id();
		$started = $user_id ? (int) get_user_meta( $user_id, self::META, true ) : 0;

		if ( ! $started ) {
			return;
		}

		if ( time() - $started > self::WINDOW ) {
			self::clear( $user_id );
			return;
		}

		// A webhook or the customer's own refresh may already have brought the new tags.
		if ( ! self::changed( $user_id ) && time() - (int) get_user_meta( $user_id, self::ATTEMPT, true ) >= self::INTERVAL ) {
			update_user_meta( $user_id, self::ATTEMPT, time() );

			if ( Mirror::customer_id( $user_id ) && Admin::available() ) {
				Reconciler::sync_user( $user_id, 'post_checkout' );
			}
		}

		if ( self::changed( $user_id ) ) {
			// Access changed: stop polling and say so once.
			self::clear( $user_id );
			update_user_meta( $user_id, self::READY, time() );
		}
	}

	private static function changed( int $user_id ): bool {
		return (string) get_user_meta( $user_id, self::BASE, true ) !== self::fingerprint( $user_id );
	}

	private static function fingerprint( int $user_id ): string {
		$tags = array_map( 'strtolower', Mirror::tags( $user_id ) );
		sort( $tags );

		return md5( implode( "\n", $tags ) );
	}

	private static function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::META );
		delete_user_meta( $user_id, self::ATTEMPT );
		delete_user_meta( $user_id, self::BASE );
	}

	/**
	 * "Finishing up" while the window is open, "ready" once, and the guest
	 * prompt after a guest checkout. Never in a cached page: the first two are
	 * for signed-in visitors only, the third depends on a query argument.
	 */
	public static function banner(): void {
		$user_id = get_current_user_id();
		$guest   = ! $user_id && 'checkout_guest' === sanitize_key( wp_unslash( $_GET['wp2s_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ready   = $user_id && (int) get_user_meta( $user_id, self::READY, true ) > time() - HOUR_IN_SECONDS;
		$active  = self::active( $user_id );

		/**
		 * Whether to print the post-checkout banner at wp_body_open. A theme
		 * that shows its own can return false.
		 */
		if ( ! ( $guest || $ready || $active ) || ! apply_filters( 'wp2_shopify_checkout_banner', true ) ) {
			return;
		}

		if ( $ready ) {
			delete_user_meta( $user_id, self::READY );
		}

		?>
		<div class="wp2-shopify-checkout-banner" role="status">
			<?php if ( $guest ) : ?>
				<p><?php esc_html_e( 'Thanks for your order. To use a membership you bought, log in with Shopify using the email address from your order.', 'wp2-shopify' ); ?></p>
				<a class="wp-element-button" href="<?php echo esc_url( Flow::login_url( home_url( '/' ) ) ); ?>" rel="nofollow"><?php esc_html_e( 'Log in with Shopify', 'wp2-shopify' ); ?></a>
			<?php elseif ( $ready ) : ?>
				<p><?php esc_html_e( 'Your membership is ready. Thanks for joining!', 'wp2-shopify' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Thanks for your order. We\'re finishing setting up your membership; this usually takes under a minute.', 'wp2-shopify' ); ?></p>
				<?php self::refresh_form( __( 'Check again', 'wp2-shopify' ) ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A no-JavaScript "refresh my membership" button for the signed-in customer.
	 */
	public static function refresh_form( string $label, string $class = 'wp-element-button' ): void {
		?>
		<form method="post" action="<?php echo esc_url( Flow::action_url() ); ?>" class="wp2-shopify-inline-form">
			<input type="hidden" name="action" value="wp2_shopify_sync">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( \WP2_Shopify\Blocks\Gate::current_url() ); ?>">
			<?php wp_nonce_field( 'wp2_shopify_sync' ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
