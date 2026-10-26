<?php

namespace RedeemID\Sync;

use RedeemID\Entitlement\Member;
use RedeemID\Support\CustomerId;
use RedeemID\Support\Logger;

/**
 * The WordPress-side mirror of a Shopify customer. Shopify stays the
 * record; this is display and gating data, stamped with when and how it synced.
 */
final class Mirror {
	public const GID         = '_redeemid_customer_id';
	public const NUMERIC     = '_redeemid_customer_numeric_id';
	public const TAGS        = '_redeemid_tags';
	public const STATUS      = '_redeemid_customer_status';
	public const EMAIL       = '_redeemid_email_address';
	public const FIRST_NAME  = '_redeemid_first_name';
	public const LAST_NAME   = '_redeemid_last_name';
	public const VERIFIED    = '_redeemid_verified_email';
	public const EMAIL_OPTIN = '_redeemid_email_marketing_consent';
	public const SMS_OPTIN   = '_redeemid_sms_marketing_consent';
	public const SUMMARY     = '_redeemid_purchasing_summary';
	public const LAST_SYNC   = '_redeemid_last_sync';
	public const SYNC_SOURCE = '_redeemid_sync_source';
	public const SYNC_FAILED = '_redeemid_sync_failed';
	public const LAST_EVENT  = '_redeemid_last_webhook_event';
	public const LINKED_AT   = '_redeemid_linked_at';
	public const SINCE       = '_redeemid_member_since';
	public const RENEWS      = '_redeemid_renews_on';
	public const HISTORY     = '_redeemid_level_history';

	/** Every key this class writes, for privacy export, erasure and disconnect. */
	public const ALL_KEYS = [
		self::GID, self::NUMERIC, self::TAGS, self::STATUS, self::EMAIL, self::FIRST_NAME, self::LAST_NAME,
		self::VERIFIED, self::EMAIL_OPTIN, self::SMS_OPTIN, self::SUMMARY, self::LAST_SYNC, self::SYNC_SOURCE,
		self::SYNC_FAILED, self::LAST_EVENT, self::LINKED_AT, self::SINCE, self::RENEWS, self::HISTORY,
	];

	public static function find_user( CustomerId $id ): ?\WP_User {
		$users = get_users(
			[
				'meta_key'    => self::NUMERIC, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $id->numeric, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'      => 2,
				'count_total' => false,
			]
		);

		if ( count( $users ) > 1 ) {
			Logger::log( 'mirror.duplicate_link', [ 'customer' => $id->numeric ] );
		}

		return $users[0] ?? null;
	}

	/**
	 * A linked user whose customer does not exist in Shopify on purpose, such as
	 * a test theme's seeded accounts. Reconciliation leaves its tags alone and
	 * the admin offers no Shopify links for it.
	 */
	public static function is_fixture( int $user_id ): bool {
		return (bool) apply_filters( 'redeemid_customer_is_fixture', false, $user_id );
	}

	public static function customer_id( int $user_id ): ?CustomerId {
		return CustomerId::parse( (string) get_user_meta( $user_id, self::NUMERIC, true ) )
			?? CustomerId::parse( (string) get_user_meta( $user_id, self::GID, true ) );
	}

	public static function link( int $user_id, CustomerId $id ): void {
		update_user_meta( $user_id, self::GID, $id->gid() );
		update_user_meta( $user_id, self::NUMERIC, $id->numeric );
		update_user_meta( $user_id, self::LINKED_AT, time() );
	}

	public static function unlink( int $user_id ): void {
		foreach ( self::ALL_KEYS as $key ) {
			delete_user_meta( $user_id, $key );
		}
	}

	/**
	 * @param string[] $tags
	 */
	public static function set_tags( int $user_id, array $tags ): void {
		update_user_meta( $user_id, self::TAGS, array_values( array_unique( array_map( 'strval', $tags ) ) ) );
	}

	/**
	 * @return string[]
	 */
	public static function tags( int $user_id ): array {
		$tags = get_user_meta( $user_id, self::TAGS, true );

		return is_array( $tags ) ? array_values( array_filter( $tags, 'is_string' ) ) : [];
	}

	public static function set_profile( int $user_id, array $fields ): void {
		$map = [
			'first_name'     => self::FIRST_NAME,
			'last_name'      => self::LAST_NAME,
			'email'          => self::EMAIL,
			'verified_email' => self::VERIFIED,
		];

		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				$value = is_bool( $fields[ $field ] ) ? ( $fields[ $field ] ? '1' : '0' ) : sanitize_text_field( (string) $fields[ $field ] );
				update_user_meta( $user_id, $key, $value );
			}
		}

		if ( isset( $fields['state'] ) ) {
			// Shopify customer state: ENABLED / DISABLED / INVITED / DECLINED.
			self::set_status( $user_id, 'disabled' === strtolower( (string) $fields['state'] ) ? 'disabled' : 'enabled' );
		}
	}

	/**
	 * Membership dates a shop keeps in customer metafields (membership.member_since,
	 * membership.renews_on), written by Shopify Flow or a subscription app.
	 * Display only: they never grant or remove access.
	 *
	 * @param array{since?: string|null, renews?: string|null} $dates
	 */
	public static function set_dates( int $user_id, array $dates ): void {
		foreach ( [ 'since' => self::SINCE, 'renews' => self::RENEWS ] as $field => $key ) {
			if ( ! array_key_exists( $field, $dates ) ) {
				continue;
			}

			$date = self::date( $dates[ $field ] );

			if ( '' === $date ) {
				delete_user_meta( $user_id, $key );
			} else {
				update_user_meta( $user_id, $key, $date );
			}
		}
	}

	/**
	 * A metafield date or date-time as Y-m-d, or '' when it is not one.
	 */
	public static function date( mixed $value ): string {
		return is_string( $value ) && preg_match( '/^(\d{4}-\d{2}-\d{2})/', trim( $value ), $m ) && checkdate( (int) substr( $m[1], 5, 2 ), (int) substr( $m[1], 8, 2 ), (int) substr( $m[1], 0, 4 ) ) ? $m[1] : '';
	}

	public static function set_status( int $user_id, string $status ): void {
		update_user_meta( $user_id, self::STATUS, 'disabled' === $status ? 'disabled' : 'enabled' );
	}

	public static function stamp( int $user_id, string $source ): void {
		update_user_meta( $user_id, self::LAST_SYNC, time() );
		update_user_meta( $user_id, self::SYNC_SOURCE, sanitize_key( $source ) );
		delete_user_meta( $user_id, self::SYNC_FAILED );
	}

	public static function mark_sync_failed( int $user_id ): void {
		update_user_meta( $user_id, self::SYNC_FAILED, time() );
	}

	public static function member( int $user_id ): Member {
		if ( $user_id <= 0 ) {
			return Member::guest();
		}

		$linked    = null !== self::customer_id( $user_id );
		$last_sync = (int) get_user_meta( $user_id, self::LAST_SYNC, true );

		return new Member(
			signed_in: true,
			linked: $linked,
			tags: self::tags( $user_id ),
			status: 'disabled' === get_user_meta( $user_id, self::STATUS, true ) ? 'disabled' : 'enabled',
			last_sync: $last_sync > 0 ? $last_sync : null,
			last_sync_failed: (bool) get_user_meta( $user_id, self::SYNC_FAILED, true ),
		);
	}
}
