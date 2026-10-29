<?php

namespace RedeemID;

use RedeemID\Access\Meter;
use RedeemID\Auth\TokenVault;
use RedeemID\Sync\Mirror;

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

	public static function export( string $email ): array {
		$user = self::user_for( $email );
		$data = [];

		if ( $user ) {
			$labels = [
				Mirror::GID         => __( 'Shopify customer ID', 'redeemid' ),
				Mirror::NUMERIC     => __( 'Shopify customer number', 'redeemid' ),
				Mirror::EMAIL       => __( 'Shopify email', 'redeemid' ),
				Mirror::FIRST_NAME  => __( 'First name (Shopify)', 'redeemid' ),
				Mirror::LAST_NAME   => __( 'Last name (Shopify)', 'redeemid' ),
				Mirror::TAGS        => __( 'Shopify tags', 'redeemid' ),
				Mirror::STATUS      => __( 'Account status', 'redeemid' ),
				Mirror::EMAIL_OPTIN => __( 'Email marketing consent', 'redeemid' ),
				Mirror::SMS_OPTIN   => __( 'SMS marketing consent', 'redeemid' ),
				Mirror::SUMMARY     => __( 'Purchasing summary', 'redeemid' ),
				Mirror::LAST_SYNC   => __( 'Last synced', 'redeemid' ),
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

		$meter = $user ? get_user_meta( $user->ID, Meter::META, true ) : null;

		if ( is_array( $meter ) && ! empty( $meter['items'] ) ) {
			$data[] = [
				'name'  => __( 'Free stories read this period (post IDs)', 'redeemid' ),
				'value' => implode( ', ', array_map( 'intval', (array) $meter['items'] ) ),
			];
		}

		return [
			'data' => $data ? [ [ 'group_id' => 'redeemid', 'group_label' => __( 'Shopify', 'redeemid' ), 'item_id' => 'shopify-' . $user->ID, 'data' => $data ] ] : [],
			'done' => true,
		];
	}

	public static function erase( string $email ): array {
		$user = self::user_for( $email );

		if ( $user ) {
			TokenVault::forget_all( $user->ID );
			Mirror::unlink( $user->ID );
			delete_user_meta( $user->ID, Meter::META );
		}

		return [
			'items_removed'  => (bool) $user,
			'items_retained' => false,
			'messages'       => $user ? [ __( 'The Shopify link and synced data were removed. The Shopify customer record itself is managed in Shopify.', 'redeemid' ) ] : [],
			'done'           => true,
		];
	}
}
