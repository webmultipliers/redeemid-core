<?php

namespace WP2_Shopify\Sync;

use WP2_Shopify\Entitlement\Member;
use WP2_Shopify\Support\CustomerId;
use WP2_Shopify\Support\Logger;

/**
 * The WordPress-side mirror of a Shopify customer. Shopify stays the
 * record; this is display and gating data, stamped with when and how it synced.
 *
 * Meta keys keep the POC names where they already existed.
 */
final class Mirror {
	public const GID         = '_wp2_shopify_customer_id';
	public const NUMERIC     = '_wp2_shopify_customer_numeric_id';
	public const TAGS        = '_wp2_shopify_tags';
	public const STATUS      = '_wp2_shopify_customer_status';
	public const EMAIL       = '_wp2_shopify_email_address';
	public const FIRST_NAME  = '_wp2_shopify_first_name';
	public const LAST_NAME   = '_wp2_shopify_last_name';
	public const VERIFIED    = '_wp2_shopify_verified_email';
	public const EMAIL_OPTIN = '_wp2_shopify_email_marketing_consent';
	public const SMS_OPTIN   = '_wp2_shopify_sms_marketing_consent';
	public const SUMMARY     = '_wp2_shopify_purchasing_summary';
	public const LAST_SYNC   = '_wp2_shopify_last_sync';
	public const SYNC_SOURCE = '_wp2_shopify_sync_source';
	public const SYNC_FAILED = '_wp2_shopify_sync_failed';
	public const LAST_EVENT  = '_wp2_shopify_last_webhook_event';
	public const LINKED_AT   = '_wp2_shopify_linked_at';

	/** Every key this class writes, for privacy export, erasure and disconnect. */
	public const ALL_KEYS = [
		self::GID, self::NUMERIC, self::TAGS, self::STATUS, self::EMAIL, self::FIRST_NAME, self::LAST_NAME,
		self::VERIFIED, self::EMAIL_OPTIN, self::SMS_OPTIN, self::SUMMARY, self::LAST_SYNC, self::SYNC_SOURCE,
		self::SYNC_FAILED, self::LAST_EVENT, self::LINKED_AT,
		// Legacy POC keys removed on disconnect / erasure.
		'_wp2_last_sync', '_wp2_shopify_real_email', '_wp2_shopify_refresh_token', '_wp2_shopify_customer_payload',
		'_wp2_shopify_default_address', '_wp2_shopify_addresses', '_wp2_shopify_phone_number', '_wp2_shopify_deleted_at',
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

		if ( $users ) {
			return $users[0];
		}

		// Legacy users that have only the GID (backfilled by the migration, but be tolerant).
		$users = get_users(
			[
				'meta_key'    => self::GID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $id->gid(), // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'      => 1,
				'count_total' => false,
			]
		);

		if ( $users ) {
			update_user_meta( $users[0]->ID, self::NUMERIC, $id->numeric );
			return $users[0];
		}

		return null;
	}

	/**
	 * A linked user whose customer does not exist in Shopify on purpose, such as
	 * a test theme's seeded accounts. Reconciliation leaves its tags alone and
	 * the admin offers no Shopify links for it.
	 */
	public static function is_fixture( int $user_id ): bool {
		return (bool) apply_filters( 'wp2_shopify_customer_is_fixture', false, $user_id );
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
