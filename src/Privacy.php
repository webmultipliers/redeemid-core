<?php

namespace RedeemID;

use RedeemID\Access\Meter;
use RedeemID\Auth\Flow;
use RedeemID\Auth\Linking;
use RedeemID\Auth\ShadowUsers;
use RedeemID\Auth\TokenVault;
use RedeemID\Sync\Mirror;
use RedeemID\Sync\PostCheckout;

/**
 * WordPress privacy export and erasure for the Shopify mirror.
 */
final class Privacy {
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'erasers' ] );
	}

	public static function exporters( array $exporters ): array {
		$exporters['redeemid'] = [
			'exporter_friendly_name' => __( 'Shopify link and synced data', 'redeemid' ),
			'callback'               => [ self::class, 'export' ],
		];

		return $exporters;
	}

	public static function erasers( array $erasers ): array {
		$erasers['redeemid'] = [
			'eraser_friendly_name' => __( 'Shopify link and synced data', 'redeemid' ),
			'callback'             => [ self::class, 'erase' ],
		];

		return $erasers;
	}

	private static function user_for( string $email ): ?\WP_User {
		$user = get_user_by( 'email', $email );

		if ( $user ) {
			return $user;
		}

		$users = get_users( [ 'meta_key' => Mirror::EMAIL, 'meta_value' => $email, 'number' => 1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery

		return $users[0] ?? null;
	}

	/**
	 * Every user meta key the exporter reports, in this order. Covers all of
	 * Mirror::ALL_KEYS (a unit test holds it to that), the meter and the
	 * post-checkout record.
	 */
	public const EXPORTED = [
		Mirror::GID, Mirror::NUMERIC, Mirror::EMAIL, Mirror::FIRST_NAME, Mirror::LAST_NAME, Mirror::VERIFIED,
		Mirror::TAGS, Mirror::STATUS, Mirror::STATUS_FROM, Mirror::EMAIL_OPTIN, Mirror::SMS_OPTIN, Mirror::SUMMARY,
		Mirror::SINCE, Mirror::RENEWS, Mirror::HISTORY, Mirror::LINKED_AT, Mirror::LAST_SYNC, Mirror::SYNC_SOURCE,
		Mirror::SYNC_FAILED, Mirror::LAST_EVENT, Mirror::PROFILE_AT, Mirror::STATUS_AT,
	];

	/**
	 * Keys erased beside Mirror::ALL_KEYS: request bookkeeping that names no
	 * one but belongs to the person.
	 */
	public const ALSO_ERASED = [ Meter::META, Flow::SYNC_REQUEST, Linking::META ];

	private static function label( string $key ): string {
		return match ( $key ) {
			Mirror::GID         => __( 'Shopify customer ID', 'redeemid' ),
			Mirror::NUMERIC     => __( 'Shopify customer number', 'redeemid' ),
			Mirror::EMAIL       => __( 'Shopify email', 'redeemid' ),
			Mirror::FIRST_NAME  => __( 'First name (Shopify)', 'redeemid' ),
			Mirror::LAST_NAME   => __( 'Last name (Shopify)', 'redeemid' ),
			Mirror::VERIFIED    => __( 'Email verified by Shopify', 'redeemid' ),
			Mirror::TAGS        => __( 'Shopify tags', 'redeemid' ),
			Mirror::STATUS      => __( 'Account status', 'redeemid' ),
			Mirror::STATUS_FROM => __( 'Account status came from', 'redeemid' ),
			Mirror::EMAIL_OPTIN => __( 'Email marketing consent', 'redeemid' ),
			Mirror::SMS_OPTIN   => __( 'SMS marketing consent', 'redeemid' ),
			Mirror::SUMMARY     => __( 'Purchasing summary', 'redeemid' ),
			Mirror::SINCE       => __( 'Member since', 'redeemid' ),
			Mirror::RENEWS      => __( 'Renews on', 'redeemid' ),
			Mirror::HISTORY     => __( 'Membership level history', 'redeemid' ),
			Mirror::LINKED_AT   => __( 'Linked to Shopify', 'redeemid' ),
			Mirror::LAST_SYNC   => __( 'Last synced', 'redeemid' ),
			Mirror::SYNC_SOURCE => __( 'Last synced by', 'redeemid' ),
			Mirror::SYNC_FAILED => __( 'Last failed sync', 'redeemid' ),
			Mirror::LAST_EVENT  => __( 'Last Shopify event', 'redeemid' ),
			Mirror::PROFILE_AT  => __( 'Profile last updated in Shopify', 'redeemid' ),
			Mirror::STATUS_AT   => __( 'Status last changed in Shopify', 'redeemid' ),
			default             => $key,
		};
	}

	/**
	 * A stored value as text: timestamps as dates, level history as one line per level.
	 */
	private static function value( string $key, mixed $value ): string {
		if ( in_array( $key, [ Mirror::LINKED_AT, Mirror::LAST_SYNC, Mirror::SYNC_FAILED, Mirror::PROFILE_AT, Mirror::STATUS_AT ], true ) && is_numeric( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value ) . ' UTC';
		}

		if ( Mirror::HISTORY === $key && is_array( $value ) ) {
			$lines = [];

			foreach ( $value as $slug => $entry ) {
				$since   = (int) ( $entry['since'] ?? 0 );
				$until   = (int) ( $entry['until'] ?? 0 );
				$lines[] = $slug . ': ' . ( $since ? gmdate( 'Y-m-d', $since ) : '?' ) . ( $until ? ' to ' . gmdate( 'Y-m-d', $until ) : '' );
			}

			return implode( '; ', $lines );
		}

		if ( is_array( $value ) ) {
			$parts = [];

			foreach ( $value as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$parts[] = is_int( $k ) ? (string) $v : $k . ': ' . $v;
				}
			}

			return implode( ', ', $parts );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	public static function export( string $email ): array {
		$user = self::user_for( $email );
		$data = [];

		if ( $user ) {
			foreach ( self::EXPORTED as $key ) {
				$value = get_user_meta( $user->ID, $key, true );
				$text  = '' === $value || [] === $value || null === $value ? '' : self::value( $key, $value );

				if ( '' !== $text ) {
					$data[] = [ 'name' => self::label( $key ), 'value' => $text ];
				}
			}

			$meter = get_user_meta( $user->ID, Meter::META, true );

			if ( is_array( $meter ) && ! empty( $meter['items'] ) ) {
				$data[] = [
					'name'  => __( 'Free stories read this period (post IDs)', 'redeemid' ),
					'value' => implode( ', ', array_map( 'intval', (array) $meter['items'] ) ),
				];
			}

			$started = (int) get_user_meta( $user->ID, PostCheckout::META, true );

			if ( $started > 0 ) {
				$data[] = [ 'name' => __( 'Started a checkout from this site', 'redeemid' ), 'value' => gmdate( 'Y-m-d H:i:s', $started ) . ' UTC' ];
			}
		}

		return [
			'data' => $data ? [ [ 'group_id' => 'redeemid', 'group_label' => __( 'Shopify', 'redeemid' ), 'item_id' => 'shopify-' . $user->ID, 'data' => $data ] ] : [],
			'done' => true,
		];
	}

	/**
	 * Removes the Shopify link and everything synced or recorded for it. A
	 * Shopify-only account keeps its WordPress user, whose name came from
	 * Shopify; that is reported as retained, not erased.
	 */
	public static function erase( string $email ): array {
		$user   = self::user_for( $email );
		$shadow = $user && ShadowUsers::is_shadow( $user->ID );

		if ( $user ) {
			TokenVault::forget_all( $user->ID );
			Mirror::unlink( $user->ID );

			foreach ( array_merge( self::ALSO_ERASED, PostCheckout::KEYS ) as $key ) {
				delete_user_meta( $user->ID, $key );
			}
		}

		$messages = $user ? [ __( 'The Shopify link and synced data were removed. The Shopify customer record itself is managed in Shopify.', 'redeemid' ) ] : [];

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
}
