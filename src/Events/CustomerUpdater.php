<?php

namespace WP2_Shopify\Events;

use WP_User;

class CustomerUpdater {
	/**
	 * Locate a WordPress user by the Shopify customer ID stored in user meta.
	 */
	public static function find_user_by_customer_id( string $customer_id ): ?WP_User {
		if ( '' === trim( $customer_id ) ) {
			return NULL;
		}

		$query = new \WP_User_Query( [
			'meta_key'   => '_wp2_shopify_customer_id',
			'meta_value' => trim( $customer_id ),
			'number'     => 1,
		] );

		$users = $query->get_results();

		return $users ? $users[0] : NULL;
	}

	/**
	 * Locate a WordPress user by email address.
	 */
	public static function find_user_by_email( string $email ): ?WP_User {
		if ( '' === trim( $email ) ) {
			return NULL;
		}

		$user = get_user_by( 'email', sanitize_email( $email ) );

		return $user ?: NULL;
	}

	/**
	 * Convenience for updating tags coming from Shopify payloads.
	 */
	public static function update_tags( WP_User $user, array $tags ): void {
		update_user_meta( $user->ID, '_wp2_shopify_tags', $tags );
	}

	/**
	 * Persist the last sync timestamp.
	 */
	public static function set_last_sync( WP_User $user, ?string $timestamp = NULL ): void {
		update_user_meta( $user->ID, '_wp2_last_sync', $timestamp ?? current_time( 'mysql' ) );
	}

	/**
	 * Generic meta update helper.
	 */
	public static function save_meta( WP_User $user, string $key, $value ): void {
		update_user_meta( $user->ID, $key, $value );
	}

	/**
	 * Snapshot a customer payload for quick admin lookups.
	 */
	public static function sync_profile_snapshot( WP_User $user, array $payload ): void {
		self::update_optional_meta(
			$user,
			'_wp2_shopify_first_name',
			$payload,
			[ 'first_name', 'firstName', 'customer.first_name', 'customer.firstName' ],
			static function ( $value ) {
				return sanitize_text_field( (string) $value );
			}
		);

		self::update_optional_meta(
			$user,
			'_wp2_shopify_last_name',
			$payload,
			[ 'last_name', 'lastName', 'customer.last_name', 'customer.lastName' ],
			static function ( $value ) {
				return sanitize_text_field( (string) $value );
			}
		);

		self::update_optional_meta(
			$user,
			'_wp2_shopify_email_address',
			$payload,
			[ 'email', 'email_address', 'emailAddress.emailAddress', 'customer.email' ],
			static function ( $value ) {
				return sanitize_email( (string) $value );
			}
		);

		self::update_optional_meta(
			$user,
			'_wp2_shopify_phone_number',
			$payload,
			[ 'phone', 'customer.phone', 'default_address.phone' ],
			static function ( $value ) {
				return sanitize_text_field( (string) $value );
			}
		);

		self::update_optional_meta(
			$user,
			'_wp2_shopify_customer_state',
			$payload,
			[ 'state', 'customer.state' ],
			static function ( $value ) {
				return sanitize_text_field( (string) $value );
			}
		);

		self::update_optional_meta(
			$user,
			'_wp2_shopify_currency',
			$payload,
			[ 'currency' ],
			static function ( $value ) {
				return sanitize_text_field( (string) $value );
			}
		);

		self::update_optional_meta(
			$user,
			'_wp2_shopify_verified_email',
			$payload,
			[ 'verified_email', 'customer.verified_email' ],
			static function ( $value ) {
				return self::normalize_boolean( $value );
			}
		);

		self::update_array_meta(
			$user,
			'_wp2_shopify_default_address',
			$payload,
			[ 'default_address', 'customer.default_address' ]
		);

		self::update_array_meta(
			$user,
			'_wp2_shopify_addresses',
			$payload,
			[ 'addresses', 'customer.addresses' ]
		);
	}

	private static function update_optional_meta(
		WP_User $user,
		string $meta_key,
		array $payload,
		array $paths,
		callable $formatter
	): void {
		foreach ( $paths as $path ) {
			[ $found, $value ] = self::get_payload_path( $payload, $path );

			if ( ! $found ) {
				continue;
			}

			$formatted = (string) $formatter( $value );

			if ( '' === $formatted ) {
				delete_user_meta( $user->ID, $meta_key );
				return;
			}

			update_user_meta( $user->ID, $meta_key, $formatted );
			return;
		}
	}

	private static function update_array_meta( WP_User $user, string $meta_key, array $payload, array $paths ): void {
		foreach ( $paths as $path ) {
			[ $found, $value ] = self::get_payload_path( $payload, $path );

			if ( ! $found ) {
				continue;
			}

			if ( ! is_array( $value ) || empty( $value ) ) {
				delete_user_meta( $user->ID, $meta_key );
				return;
			}

			update_user_meta( $user->ID, $meta_key, wp_json_encode( $value ) );
			return;
		}
	}

	/**
	 * Resolve dot-notated payload paths and report whether the key existed.
	 *
	 * @return array{0: bool, 1: mixed}
	 */
	private static function get_payload_path( array $payload, string $path ): array {
		$value    = $payload;
		$segments = explode( '.', $path );

		foreach ( $segments as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
				continue;
			}

			return [ false, null ];
		}

		return [ true, $value ];
	}

	private static function normalize_boolean( $value ): string {
		$normalized = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		if ( null === $normalized ) {
			return '';
		}

		return $normalized ? '1' : '0';
	}
}
