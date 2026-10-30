<?php

namespace RedeemID\Privacy;

use RedeemID\Auth\ShadowUsers;
use RedeemID\Auth\TokenVault;
use RedeemID\Commerce\Cart;
use RedeemID\Privacy;
use RedeemID\Support\EventLog;
use RedeemID\Sync\Mirror;
use RedeemID\Sync\PostCheckout;
use RedeemID\Webhooks\WpdbInboxStore;

/**
 * The personal data eraser. Removes the Shopify link and everything synced
 * or recorded for it here, and strips the person from the activity log and
 * webhook deliveries. The Shopify customer itself is untouched: staff can ask
 * Shopify to erase it from the request's row (ShopifyErasure).
 */
final class Eraser {
	/**
	 * Who each erase in this request removed, by email, so the request row can
	 * keep the customer number after the link is gone.
	 *
	 * @var array<string, array{user: int, customer: string, fixture: bool}>
	 */
	private static array $erased = [];

	public static function erase( string $email, int $page = 1 ): array {
		$user   = Privacy::user_for( $email );
		$shadow = $user && ShadowUsers::is_shadow( $user->ID );

		if ( $user ) {
			self::$erased[ strtolower( $email ) ] = self::local( $user );
		}

		$messages = $user ? [ __( 'The Shopify link, synced data, cart and activity records were removed. The Shopify customer record itself is managed in Shopify.', 'redeemid' ) ] : [];

		if ( $shadow ) {
			$messages[] = __( 'This person signs in only with Shopify. Their WordPress account, with the name Shopify gave it, was kept; delete the user to remove it too.', 'redeemid' );
		}

		return [
			'items_removed'  => (bool) $user,
			'items_retained' => $shadow,
			'messages'       => $messages,
			'done'           => true,
		];
	}

	/**
	 * Everything RedeemID holds about one person, except their WordPress
	 * account. Shared with customers/redact.
	 *
	 * @return array{user: int, customer: string, fixture: bool}
	 */
	public static function local( \WP_User $user ): array {
		$customer = (string) get_user_meta( $user->ID, Mirror::NUMERIC, true );
		$fixture  = '' !== $customer && Mirror::is_fixture( $user->ID );

		TokenVault::forget_all( $user->ID );
		Mirror::unlink( $user->ID );
		Cart::erase_for_user( $user->ID );

		foreach ( array_merge( Privacy::ALSO_ERASED, PostCheckout::KEYS ) as $key ) {
			delete_user_meta( $user->ID, $key );
		}

		self::forget_logs( $user->ID, $customer );

		return [ 'user' => $user->ID, 'customer' => $customer, 'fixture' => $fixture ];
	}

	/**
	 * Rows stay, so counts and delivery history stay right; nothing in them
	 * points to the person any more.
	 */
	public static function forget_logs( int $user_id, string $customer ): void {
		EventLog::anonymise( $user_id, $customer );

		if ( '' !== $customer ) {
			( new WpdbInboxStore() )->anonymise( $customer );
		}
	}

	/**
	 * @return array{user: int, customer: string, fixture: bool}|null
	 */
	public static function erased( string $email ): ?array {
		return self::$erased[ strtolower( $email ) ] ?? null;
	}
}
