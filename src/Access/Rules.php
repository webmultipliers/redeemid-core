<?php

namespace RedeemID\Access;

/**
 * The site-wide rule settings: the site default, how several Requires terms
 * combine, and one row per post type and per taxonomy. Plain data with no
 * WordPress calls, so normalising a saved or filtered config is unit tested.
 *
 * A post type row: whether its items can be gated at all, the levels its
 * items need (any one passes; none means no post type rule), its teaser
 * setting, an archive window after which old items open, who sees comments
 * on locked items, and whether locked items stay out of search engines.
 *
 * A taxonomy row: whether its term rules count, whether a term with no rule
 * of its own takes its nearest ancestor's, and a priority for the priority
 * conflict mode.
 *
 * The meter: how many locked items a signed-in reader without access may
 * open each month or week (0: no meter). See Meter.
 */
final class Rules {
	public const CONFLICTS = [ Context::CONFLICT_MOST, Context::CONFLICT_LEAST, Context::CONFLICT_PRIORITY ];

	/** Comments on a locked item: shown only to visitors who can read it, or to everyone. */
	public const COMMENTS_MEMBERS  = 'members';
	public const COMMENTS_EVERYONE = 'everyone';

	public const METER_MONTH = 'month';
	public const METER_WEEK  = 'week';

	/**
	 * @param string[]                                                                                      $site_levels
	 * @param array<string, array{enabled: bool, levels: string[], teaser: array{mode: string, count: int}, public_after_days: int, comments: string, noindex: bool}> $types
	 * @param array<string, array{enabled: bool, inherit: bool, priority: int}>                             $taxonomies
	 */
	public function __construct(
		public readonly array $site_levels = [],
		public readonly string $conflict = Context::CONFLICT_MOST,
		public readonly array $types = [],
		public readonly array $taxonomies = [],
		public readonly array $meter = [ 'limit' => 0, 'period' => self::METER_MONTH ],
	) {}

	public static function from_array( array $a ): self {
		$types      = [];
		$taxonomies = [];

		foreach ( (array) ( $a['types'] ?? [] ) as $type => $row ) {
			if ( is_string( $type ) && '' !== $type ) {
				$types[ $type ] = self::type_row( is_array( $row ) ? $row : [] );
			}
		}

		foreach ( (array) ( $a['taxonomies'] ?? [] ) as $taxonomy => $row ) {
			if ( is_string( $taxonomy ) && '' !== $taxonomy ) {
				$taxonomies[ $taxonomy ] = self::taxonomy_row( is_array( $row ) ? $row : [] );
			}
		}

		$conflict = (string) ( $a['conflict'] ?? '' );

		return new self(
			self::levels( $a['site_levels'] ?? [] ),
			in_array( $conflict, self::CONFLICTS, true ) ? $conflict : Context::CONFLICT_MOST,
			$types,
			$taxonomies,
			self::meter_row( is_array( $a['meter'] ?? null ) ? $a['meter'] : [] ),
		);
	}

	public function to_array(): array {
		return [
			'site_levels' => $this->site_levels,
			'conflict'    => $this->conflict,
			'types'       => $this->types,
			'taxonomies'  => $this->taxonomies,
			'meter'       => $this->meter,
		];
	}

	/**
	 * A post type with no saved row: gated, no rule of its own, two teaser
	 * blocks, comments on locked items for readers only, indexed.
	 */
	public function type( string $type ): array {
		return $this->types[ $type ] ?? self::type_row( [] );
	}

	/**
	 * A taxonomy with no saved row takes part, and inherits when it is hierarchical.
	 */
	public function taxonomy( string $taxonomy, bool $hierarchical = false ): array {
		return $this->taxonomies[ $taxonomy ] ?? self::taxonomy_row( [ 'inherit' => $hierarchical ] );
	}

	public static function type_row( array $row ): array {
		return [
			'enabled'           => (bool) ( $row['enabled'] ?? true ),
			'levels'            => self::levels( $row['levels'] ?? [] ),
			'teaser'            => ( Teaser::from_array( is_array( $row['teaser'] ?? null ) ? $row['teaser'] : [] ) )->to_array(),
			'public_after_days' => max( 0, min( 36500, (int) ( $row['public_after_days'] ?? 0 ) ) ),
			'comments'          => self::COMMENTS_EVERYONE === ( $row['comments'] ?? '' ) ? self::COMMENTS_EVERYONE : self::COMMENTS_MEMBERS,
			'noindex'           => ! empty( $row['noindex'] ),
		];
	}

	/**
	 * @return array{limit: int, period: string}
	 */
	public static function meter_row( array $row ): array {
		return [
			'limit'  => max( 0, min( 100, (int) ( $row['limit'] ?? 0 ) ) ),
			'period' => self::METER_WEEK === ( $row['period'] ?? '' ) ? self::METER_WEEK : self::METER_MONTH,
		];
	}

	public static function taxonomy_row( array $row ): array {
		return [
			'enabled'  => (bool) ( $row['enabled'] ?? true ),
			'inherit'  => (bool) ( $row['inherit'] ?? false ),
			'priority' => max( -100, min( 100, (int) ( $row['priority'] ?? 0 ) ) ),
		];
	}

	/**
	 * @return string[] Clean, unique level slugs.
	 */
	public static function levels( mixed $levels ): array {
		return array_values( array_unique( array_filter( array_map( static fn( $s ) => Level::slug( $s ), (array) $levels ) ) ) );
	}
}
