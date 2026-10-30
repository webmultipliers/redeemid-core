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
		];
	}

	public static function evaluator(): Evaluator {
		return new Evaluator();
	}
}
