<?php

namespace WP2_Shopify;

use WP2_Shopify\Auth\TokenVault;
use WP2_Shopify\Sync\Mirror;

/**
 * WordPress privacy export and erasure for the Shopify mirror (spec 15.2).
 */
final class Privacy {
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'erasers' ] );
	}

	public static function exporters( array $exporters ): array {
		$exporters['wp2-shopify'] = [
			'exporter_friendly_name' => __( 'Shopify link and synced data', 'wp2-shopify' ),
			'callback'               => [ self::class, 'export' ],
		];

		return $exporters;
	}

	public static function erasers( array $erasers ): array {
		$erasers['wp2-shopify'] = [
			'eraser_friendly_name' => __( 'Shopify link and synced data', 'wp2-shopify' ),
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

	public static function export( string $email ): array {
		$user = self::user_for( $email );
		$data = [];

		if ( $user ) {
			$labels = [
				Mirror::GID         => __( 'Shopify customer ID', 'wp2-shopify' ),
				Mirror::NUMERIC     => __( 'Shopify customer number', 'wp2-shopify' ),
				Mirror::EMAIL       => __( 'Shopify email', 'wp2-shopify' ),
				Mirror::FIRST_NAME  => __( 'First name (Shopify)', 'wp2-shopify' ),
				Mirror::LAST_NAME   => __( 'Last name (Shopify)', 'wp2-shopify' ),
				Mirror::TAGS        => __( 'Shopify tags', 'wp2-shopify' ),
				Mirror::STATUS      => __( 'Account status', 'wp2-shopify' ),
				Mirror::EMAIL_OPTIN => __( 'Email marketing consent', 'wp2-shopify' ),
				Mirror::SMS_OPTIN   => __( 'SMS marketing consent', 'wp2-shopify' ),
				Mirror::SUMMARY     => __( 'Purchasing summary', 'wp2-shopify' ),
				Mirror::LAST_SYNC   => __( 'Last synced', 'wp2-shopify' ),
			];

			foreach ( $labels as $key => $label ) {
				$value = get_user_meta( $user->ID, $key, true );

				if ( '' === $value || [] === $value || null === $value ) {
					continue;
				}

				$data[] = [
					'name'  => $label,
					'value' => is_array( $value ) ? implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : (string) $value,
				];
			}
		}

		return [
			'data' => $data ? [ [ 'group_id' => 'wp2-shopify', 'group_label' => __( 'Shopify', 'wp2-shopify' ), 'item_id' => 'shopify-' . $user->ID, 'data' => $data ] ] : [],
			'done' => true,
		];
	}

	public static function erase( string $email ): array {
		$user = self::user_for( $email );

		if ( $user ) {
			TokenVault::forget_all( $user->ID );
			Mirror::unlink( $user->ID );
		}

		return [
			'items_removed'  => (bool) $user,
			'items_retained' => false,
			'messages'       => $user ? [ __( 'The Shopify link and synced data were removed. The Shopify customer record itself is managed in Shopify.', 'wp2-shopify' ) ] : [],
			'done'           => true,
		];
	}
}
