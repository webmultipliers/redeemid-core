<?php

namespace WP2_Shopify\Entitlement;

/**
 * A gate rule as saved in block attributes (spec 7.1, 7.4). It stores a rule,
 * not a hard-coded tag check, so Phase 3 can add order and subscription
 * sources without changing saved content.
 */
final class Rule {
	public const MATCH_ANY = 'any';
	public const MATCH_ALL = 'all';

	/**
	 * @param string[] $required_tags
	 */
	public function __construct(
		public readonly array $required_tags = [],
		public readonly string $match_mode = self::MATCH_ANY,
		public readonly bool $require_login = true,
		public readonly string $source = 'tags',
	) {}

	/**
	 * Build a rule from block attribute values. Tags may arrive as a list, a
	 * comma separated string, or Blockstudio repeater rows ([['tag' => 'vip']]).
	 */
	public static function from_attributes( array $a ): self {
		$raw  = $a['requiredTags'] ?? [];
		$tags = [];

		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		foreach ( (array) $raw as $item ) {
			$tag = is_array( $item ) ? ( $item['tag'] ?? '' ) : $item;

			if ( is_string( $tag ) && '' !== trim( $tag ) ) {
				$tags[] = trim( $tag );
			}
		}

		$mode = $a['matchMode'] ?? self::MATCH_ANY;
		$mode = is_array( $mode ) ? ( $mode['value'] ?? self::MATCH_ANY ) : $mode;

		return new self(
			array_values( array_unique( $tags ) ),
			self::MATCH_ALL === $mode ? self::MATCH_ALL : self::MATCH_ANY,
			! array_key_exists( 'requireLogin', $a ) || false !== $a['requireLogin'],
		);
	}
}
