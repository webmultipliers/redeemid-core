<?php

namespace RedeemID\Features;

use RedeemID\Access\Level;

/**
 * A registered feature: a site behaviour a customer unlocks because of what
 * Shopify already decided. Registration is code (the redeemid_features
 * filter); who gets it is a condition, set in code or mapped on
 * RedeemID → Access → Features. A feature has no price and no checkout.
 *
 * Every feature declares how it is enforced and how pages that depend on it
 * are cached, so a security or cache review has something to check against.
 */
final class Feature {
	public const PHP       = 'php';
	public const BLOCK     = 'block';
	public const BODY      = 'body';
	public const REST      = 'rest';
	public const SHORTCODE = 'shortcode';

	public const ENFORCEMENT = [ self::PHP, self::BLOCK, self::BODY, self::REST, self::SHORTCODE ];

	/** Do not store a page that depends on it in a shared cache. */
	public const CACHE_PRIVATE = 'private';
	/** A cache layer varies on the granted-feature set (the redeemid_fset cookie, Features\Cache). */
	public const CACHE_VARY = 'vary';
	/** Only a fragment depends on it, rendered per visitor (an island). */
	public const CACHE_FRAGMENT = 'fragment';

	public const CACHES = [ self::CACHE_PRIVATE, self::CACHE_VARY, self::CACHE_FRAGMENT ];

	/** How many primary features a member menu lists. */
	public const MENU_CAP = 3;

	/**
	 * @param Route[]  $routes      Any one route grants the feature. None: nobody has it.
	 * @param string[] $enforcement Enforcement points the consumer uses.
	 * @param string[] $problems    Registration problems: no_cache (defaulted to private), bad_enforcement.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $label,
		public readonly string $description = '',
		public readonly array $routes = [],
		public readonly array $enforcement = [ self::PHP ],
		public readonly bool $sensitive = false,
		public readonly string $cache = self::CACHE_PRIVATE,
		public readonly bool $primary = false,
		public readonly bool $js = false,
		public readonly bool $managed = false,
		public readonly array $problems = [],
	) {}

	/**
	 * Build a feature from its registration and, unless the registration sets
	 * a condition itself, the condition mapped in admin.
	 *
	 * Registration keys: label, description, condition (routes, or route text
	 * as on the Features tab), enforcement, sensitive, cache, primary, js.
	 *
	 * @param array|null $mapped Stored routes for this slug, or null.
	 */
	public static function from_array( string $slug, array $a, ?array $mapped = null ): self {
		$problems = [];
		$managed  = array_key_exists( 'condition', $a );
		$routes   = [];
		$source   = $managed ? $a['condition'] : $mapped;

		if ( is_string( $source ) ) {
			$routes = Route::parse( $source );
		} elseif ( is_array( $source ) ) {
			foreach ( $source as $route ) {
				if ( $route instanceof Route ) {
					$routes[] = $route;
				} elseif ( is_array( $route ) ) {
					$routes[] = Route::from_array( $route );
				}
			}
		}

		$enforcement = array_values( array_intersect( array_map( 'strval', (array) ( $a['enforcement'] ?? [ self::PHP ] ) ), self::ENFORCEMENT ) );

		if ( ! $enforcement ) {
			$problems[]  = 'bad_enforcement';
			$enforcement = [ self::PHP ];
		}

		$cache = is_string( $a['cache'] ?? null ) ? $a['cache'] : '';

		if ( ! in_array( $cache, self::CACHES, true ) ) {
			// "We will handle caching later" is not a declaration: fall back to the safe option and say so.
			$problems[] = 'no_cache';
			$cache      = self::CACHE_PRIVATE;
		}

		return new self(
			$slug,
			trim( (string) ( $a['label'] ?? '' ) ) ?: $slug,
			trim( (string) ( $a['description'] ?? '' ) ),
			$routes,
			$enforcement,
			! empty( $a['sensitive'] ),
			$cache,
			! empty( $a['primary'] ),
			! empty( $a['js'] ),
			$managed,
			$problems,
		);
	}

	public function uses( string $point ): bool {
		return in_array( $point, $this->enforcement, true );
	}

	/**
	 * Lower-case slug of letters, digits and dashes; a third party may
	 * namespace it with a slash (acme/early-access).
	 */
	public static function slug( mixed $value ): string {
		$value = is_string( $value ) ? $value : '';

		return implode( '/', array_filter( array_map( [ Level::class, 'slug' ], explode( '/', $value ) ), 'strlen' ) );
	}

	/**
	 * The slug as a CSS class or array key part (slashes become double dashes).
	 */
	public static function css( string $slug ): string {
		return str_replace( '/', '--', $slug );
	}
}
