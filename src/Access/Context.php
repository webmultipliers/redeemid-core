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
}
