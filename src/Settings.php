<?php

namespace WP2_Shopify;

use WP2_Shopify\Entitlement\Evaluator;

/**
 * Non-secret settings. Each can also be forced from code with the
 * matching filter, so a site can keep them under version control.
 */
final class Settings {
	public const OPTION = 'wp2_shopify_settings';

	private const DEFAULTS = [
		'freshness_days'       => 7,
		'stale_behavior'       => Evaluator::STALE_HONOR,
		'clear_cart_on_logout' => false,
		'cache_ttl_minutes'    => 10,
	];

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );

		return array_merge( self::DEFAULTS, is_array( $saved ) ? array_intersect_key( $saved, self::DEFAULTS ) : [] );
	}

	public static function get( string $key ): mixed {
		return apply_filters( 'wp2_shopify_setting_' . $key, self::all()[ $key ] ?? null );
	}

	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];

		return [
			'freshness_days'       => max( 0, min( 365, (int) ( $input['freshness_days'] ?? self::DEFAULTS['freshness_days'] ) ) ),
			'stale_behavior'       => Evaluator::STALE_FAIL_CLOSED === ( $input['stale_behavior'] ?? '' ) ? Evaluator::STALE_FAIL_CLOSED : Evaluator::STALE_HONOR,
			'clear_cart_on_logout' => ! empty( $input['clear_cart_on_logout'] ),
			'cache_ttl_minutes'    => max( 1, min( 1440, (int) ( $input['cache_ttl_minutes'] ?? self::DEFAULTS['cache_ttl_minutes'] ) ) ),
		];
	}

	public static function evaluator(): Evaluator {
		return new Evaluator( (int) self::get( 'freshness_days' ) * DAY_IN_SECONDS, (string) self::get( 'stale_behavior' ) );
	}
}
