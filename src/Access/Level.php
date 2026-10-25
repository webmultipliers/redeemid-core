<?php

namespace WP2_Shopify\Access;

use WP2_Shopify\Entitlement\Rule;

/**
 * A named access level: a reusable condition plus how the wall presents it.
 *
 * The condition is a list of groups joined by OR; each group is an
 * Entitlement\Rule, so the Evaluator's fail-closed checks apply unchanged.
 * Content stores the level's slug, never its tags, so renaming a tag or a
 * label never touches posts.
 */
final class Level {
	public const PUBLIC    = 'public';
	public const SIGNED_IN = 'signed-in';

	/**
	 * @param Rule[]   $groups   Any one group passing grants the level.
	 * @param string[] $includes Slugs of levels this one also opens (Gold includes Silver).
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $label,
		public readonly array $groups = [],
		public readonly array $includes = [],
		public readonly string $badge = '',
		public readonly string $message = '',
		public readonly string $cta_label = '',
		public readonly string $cta_url = '',
		public readonly string $home_url = '',
	) {}

	public static function public(): self {
		return new self( self::PUBLIC, 'Public', [ new Rule( [], Rule::MATCH_ANY, false ) ] );
	}

	public static function signed_in(): self {
		return new self( self::SIGNED_IN, 'Signed in', [ new Rule( [], Rule::MATCH_ANY, true ) ] );
	}

	public function builtin(): bool {
		return in_array( $this->slug, [ self::PUBLIC, self::SIGNED_IN ], true );
	}

	/**
	 * @return string[] Every tag the level's condition mentions, as written.
	 */
	public function tags(): array {
		$tags = [];

		foreach ( $this->groups as $group ) {
			array_push( $tags, ...$group->required_tags );
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Build a level from stored or filtered config. Unknown keys are ignored and
	 * a level with no usable group gets none, so it grants nobody (fail closed).
	 *
	 * Groups: [ [ 'tags' => [ 'gold' ], 'match' => 'all' ], ... ].
	 */
	public static function from_array( string $slug, array $a ): self {
		$groups = [];

		foreach ( (array) ( $a['groups'] ?? [] ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$rule = Rule::from_attributes(
				[
					'requiredTags' => $group['tags'] ?? [],
					'matchMode'    => $group['match'] ?? Rule::MATCH_ANY,
				]
			);

			// A group with no tags would grant every signed-in visitor; that is the built-in Signed in level, not a typo to honour.
			if ( $rule->required_tags ) {
				$groups[] = $rule;
			}
		}

		$includes = array_values(
			array_unique(
				array_filter(
					array_map( [ self::class, 'slug' ], (array) ( $a['includes'] ?? [] ) ),
					static fn( $s ) => '' !== $s && $s !== $slug
				)
			)
		);

		return new self(
			$slug,
			trim( (string) ( $a['label'] ?? '' ) ) ?: $slug,
			$groups,
			$includes,
			trim( (string) ( $a['badge'] ?? '' ) ),
			trim( (string) ( $a['message'] ?? '' ) ),
			trim( (string) ( $a['cta_label'] ?? '' ) ),
			trim( (string) ( $a['cta_url'] ?? '' ) ),
			trim( (string) ( $a['home_url'] ?? '' ) ),
		);
	}

	public function to_array(): array {
		return [
			'label'     => $this->label,
			'groups'    => array_map( static fn( Rule $r ) => [ 'tags' => $r->required_tags, 'match' => $r->match_mode ], $this->groups ),
			'includes'  => $this->includes,
			'badge'     => $this->badge,
			'message'   => $this->message,
			'cta_label' => $this->cta_label,
			'cta_url'   => $this->cta_url,
			'home_url'  => $this->home_url,
		];
	}

	/**
	 * Lower-case slug of letters, digits and dashes, without WordPress.
	 */
	public static function slug( mixed $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $value ), '-' );
	}
}
