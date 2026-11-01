<?php

namespace RedeemID;

use RedeemID\Access\Meter;
use RedeemID\Auth\Flow;
use RedeemID\Auth\Linking;
use RedeemID\Privacy\Eraser;
use RedeemID\Privacy\Exporter;
use RedeemID\Privacy\PolicyText;
use RedeemID\Privacy\Requests;
use RedeemID\Privacy\SelfService;
use RedeemID\Privacy\ShopifyErasure;
use RedeemID\Sync\Mirror;

/**
 * WordPress' privacy tools (Tools → Export / Erase Personal Data) as the one
 * place a member's privacy requests are handled, whether they started in
 * WordPress or in Shopify. Shopify stays the record: WordPress only ever asks
 * it to erase, and its customers/redact finishes the job on both sides.
 */
final class Privacy {
	public const KEY = 'redeemid';

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'erasers' ] );

		Requests::register();
		ShopifyErasure::register();
		PolicyText::register();
		SelfService::register();
	}

	public static function exporters( array $exporters ): array {
		$exporters[ self::KEY ] = [
			'exporter_friendly_name' => __( 'Shopify link and synced data', 'redeemid' ),
			'callback'               => [ Exporter::class, 'export' ],
		];

		return $exporters;
	}

	public static function erasers( array $erasers ): array {
		$erasers[ self::KEY ] = [
			'eraser_friendly_name' => __( 'Shopify link and synced data', 'redeemid' ),
			'callback'             => [ Eraser::class, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * The WordPress user a request's email belongs to: their account email,
	 * or the email Shopify gave the linked customer.
	 */
	public static function user_for( string $email ): ?\WP_User {
		if ( '' === $email ) {
			return null;
		}

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
		Mirror::SINCE, Mirror::RENEWS, Mirror::GIFT_UNTIL, Mirror::HISTORY, Mirror::LINKED_AT, Mirror::LAST_SYNC, Mirror::SYNC_SOURCE,
		Mirror::SYNC_FAILED, Mirror::LAST_EVENT, Mirror::PROFILE_AT, Mirror::STATUS_AT,
	];

	/**
	 * Keys erased beside Mirror::ALL_KEYS: request bookkeeping that names no
	 * one but belongs to the person.
	 */
	public const ALSO_ERASED = [ Meter::META, Flow::SYNC_REQUEST, Linking::META ];
}
