<?php

namespace WP2_Shopify\Access;

/**
 * Everything the Resolver reads about one item, gathered by WordPress code
 * and handed over as plain data so the precedence table is unit tested.
 */
final class Context {
	public const ITEM_INHERIT = 'inherit';
	public const ITEM_PUBLIC  = 'public';
	public const ITEM_LEVEL   = 'level';
	public const ITEM_ADD     = 'add';

	public const CONFLICT_MOST     = 'most';
	public const CONFLICT_LEAST    = 'least';
	public const CONFLICT_PRIORITY = 'priority';

	/**
	 * @param string     $item_mode        One of the ITEM_* modes.
	 * @param string[]   $item_levels      Levels for ITEM_LEVEL and ITEM_ADD (any one passes).
	 * @param TermRule[] $terms            Rules from the item's terms in participating taxonomies.
	 * @param string[]   $type_levels      The post type's rule (any one passes); empty for none.
	 * @param string     $type_label       For provenance, such as "Post type: Lesson".
	 * @param string[]   $site_levels      The site default; empty for none.
	 * @param string     $conflict         How several Requires terms combine.
	 * @param ?int       $locked_until     Item embargo: the rule applies until this time, then the item is open.
	 * @param ?int       $public_until     Item free window: open until this time, then the rule applies.
	 * @param ?int       $published        Publish time, for the post type's age window.
	 * @param ?int       $type_public_after Post type window: items older than this many seconds are open.
	 * @param bool       $meter_exempt     The item never counts toward a future meter.
	 */
	public function __construct(
		public readonly string $item_mode = self::ITEM_INHERIT,
		public readonly array $item_levels = [],
		public readonly array $terms = [],
		public readonly array $type_levels = [],
		public readonly string $type_label = 'Post type',
		public readonly array $site_levels = [],
		public readonly string $conflict = self::CONFLICT_MOST,
		public readonly ?int $locked_until = null,
		public readonly ?int $public_until = null,
		public readonly ?int $published = null,
		public readonly ?int $type_public_after = null,
		public readonly bool $meter_exempt = false,
	) {}

	/**
	 * For the per-item cache. Only the layers are stored; the Resolver runs on
	 * them at request time, so windows and Opens expiry are never baked in.
	 */
	public function to_array(): array {
		$a          = get_object_vars( $this );
		$a['terms'] = array_map( static fn( TermRule $t ) => $t->to_array(), $this->terms );

		return $a;
	}

	public static function from_array( array $a ): self {
		$int = static fn( $v ) => is_numeric( $v ) ? (int) $v : null;

		return new self(
			item_mode: (string) ( $a['item_mode'] ?? self::ITEM_INHERIT ),
			item_levels: Rules::levels( $a['item_levels'] ?? [] ),
			terms: array_map( static fn( $t ) => TermRule::from_array( (array) $t ), array_values( (array) ( $a['terms'] ?? [] ) ) ),
			type_levels: Rules::levels( $a['type_levels'] ?? [] ),
			type_label: (string) ( $a['type_label'] ?? 'Post type' ),
			site_levels: Rules::levels( $a['site_levels'] ?? [] ),
			conflict: (string) ( $a['conflict'] ?? self::CONFLICT_MOST ),
			locked_until: $int( $a['locked_until'] ?? null ),
			public_until: $int( $a['public_until'] ?? null ),
			published: $int( $a['published'] ?? null ),
			type_public_after: $int( $a['type_public_after'] ?? null ),
			meter_exempt: ! empty( $a['meter_exempt'] ),
		);
	}
}
