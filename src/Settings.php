<?php

namespace RedeemID;

use RedeemID\Entitlement\Evaluator;

/**
 * Non-secret settings. Each can also be forced from code with the
 * matching filter, so a site can keep them under version control.
 */
final class Settings {
	public const OPTION = 'redeemid_settings';

	private const DEFAULTS = [
		'clear_cart_on_logout' => false,
		'quiet_days'           => 7,
		'alert_email'          => false,
		'login_button'         => true,
		'shopify_login_links'  => false,
		'subscription_url'     => '',
		'storefront_pages'     => true,
		'product_path'         => 'products',
		'collection_path'      => 'collections',
		'cart_path'            => 'cart',
		'storefront_first'     => false,
		'session_limit'        => 0,
		'member_fields'        => [],
	];

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );

		return array_merge( self::DEFAULTS, is_array( $saved ) ? array_intersect_key( $saved, self::DEFAULTS ) : [] );
	}

	public static function get( string $key ): mixed {
		return apply_filters( 'redeemid_setting_' . $key, self::all()[ $key ] ?? null );
	}

	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];

		return [
			'clear_cart_on_logout' => ! empty( $input['clear_cart_on_logout'] ),
			'quiet_days'           => max( 0, min( 90, (int) ( $input['quiet_days'] ?? self::DEFAULTS['quiet_days'] ) ) ),
			'alert_email'          => ! empty( $input['alert_email'] ),
			'login_button'         => ! empty( $input['login_button'] ),
			'shopify_login_links'  => ! empty( $input['shopify_login_links'] ),
			'subscription_url'     => esc_url_raw( trim( (string) ( $input['subscription_url'] ?? '' ) ), [ 'https', 'http' ] ),
			'storefront_pages'     => ! empty( $input['storefront_pages'] ),
			'product_path'         => \RedeemID\Commerce\Pages::clean_path( (string) ( $input['product_path'] ?? '' ) ) ?: self::DEFAULTS['product_path'],
			'collection_path'      => \RedeemID\Commerce\Pages::clean_path( (string) ( $input['collection_path'] ?? '' ) ) ?: self::DEFAULTS['collection_path'],
			'cart_path'            => \RedeemID\Commerce\Pages::clean_path( (string) ( $input['cart_path'] ?? '' ) ) ?: self::DEFAULTS['cart_path'],
			'storefront_first'     => ! empty( $input['storefront_first'] ),
			'session_limit'        => max( 0, min( \RedeemID\Auth\SessionCap::MAX, (int) ( $input['session_limit'] ?? 0 ) ) ),
			'member_fields'        => \RedeemID\Preferences\Preferences::clean_modes( $input['member_fields'] ?? [] ),
		];
	}

	public static function evaluator(): Evaluator {
		return new Evaluator();
	}
}
