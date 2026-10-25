<?php

namespace RedeemID\Access;

/**
 * Access rules saved on taxonomy terms, and the walk from an item's terms to
 * the TermRule list the Resolver reads.
 *
 * A term rule is one of:
 *
 * - Requires: items in the term need one of these levels.
 * - Opens: items in the term are open to everyone until a date. The date is
 *   required; an Opens rule without one is never saved and never applies.
 *
 * In a taxonomy that inherits, a term with no rule of its own takes its
 * nearest ancestor's. A term's own rule always beats an ancestor's.
 */
final class TermRules {
	public const META = '_redeemid_access';

	public const NONE = '';

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_meta' ], 20 );
	}

	public static function register_meta(): void {
		register_meta(
			'term',
			self::META,
			[
				'type'              => 'object',
				'single'            => true,
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'auth_callback'     => static fn( $allowed, $key, $term_id ) => current_user_can( 'edit_term', (int) $term_id ),
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * @return array{kind: string, levels: string[], opens_until: ?int, priority: int}
	 */
	public static function sanitize( mixed $value ): array {
		$value = is_array( $value ) ? $value : [];
		$kind  = in_array( $value['kind'] ?? '', [ TermRule::REQUIRES, TermRule::OPENS ], true ) ? $value['kind'] : self::NONE;
		$until = $value['opens_until'] ?? null;

		return [
			'kind'        => $kind,
			'levels'      => TermRule::REQUIRES === $kind ? Rules::levels( $value['levels'] ?? [] ) : [],
			'opens_until' => TermRule::OPENS === $kind && is_numeric( $until ) && (int) $until > 0 ? (int) $until : null,
			'priority'    => max( -100, min( 100, (int) ( $value['priority'] ?? 0 ) ) ),
		];
	}

	/**
	 * Why a rule cannot be saved as it is, or null when it can.
	 */
	public static function problem( array $rule, int $now ): ?string {
		$rule = self::sanitize( $rule );

		return match ( true ) {
			TermRule::REQUIRES === $rule['kind'] && ! $rule['levels'] => 'requires_needs_level',
			TermRule::OPENS === $rule['kind'] && null === $rule['opens_until'] => 'opens_needs_expiry',
			TermRule::OPENS === $rule['kind'] && $rule['opens_until'] <= $now => 'opens_expiry_past',
			default => null,
		};
	}

	/**
	 * The nearest term, starting with $term_id and walking up through
	 * $parent_of, that has a rule. Pure, and safe against parent loops.
	 *
	 * @param array<int, array> $rules     term ID => sanitized rule, for terms that have one.
	 * @param array<int, int>   $parent_of term ID => parent term ID (0 for none).
	 * @return ?int The term whose rule applies, or null.
	 */
	public static function nearest( int $term_id, array $rules, array $parent_of, bool $inherit ): ?int {
		$seen = [];

		while ( $term_id > 0 && ! isset( $seen[ $term_id ] ) ) {
			if ( isset( $rules[ $term_id ] ) && self::NONE !== $rules[ $term_id ]['kind'] ) {
				return $term_id;
			}

			if ( ! $inherit ) {
				return null;
			}

			$seen[ $term_id ] = true;
			$term_id          = (int) ( $parent_of[ $term_id ] ?? 0 );
		}

		return null;
	}

	public static function get( int $term_id ): array {
		return self::sanitize( get_term_meta( $term_id, self::META, true ) );
	}

	public static function save( int $term_id, array $rule ): void {
		$rule = self::sanitize( $rule );

		if ( self::get( $term_id ) === $rule ) {
			return;
		}

		if ( self::NONE === $rule['kind'] ) {
			delete_term_meta( $term_id, self::META );
		} else {
			update_term_meta( $term_id, self::META, $rule );
		}
		// Layers bumps the rules version when the meta is written.
	}

	/**
	 * Does any term in the taxonomy carry a rule?
	 */
	public static function in_use( string $taxonomy ): bool {
		$found = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);

		return is_array( $found ) && [] !== $found;
	}

	/**
	 * Taxonomies whose term rules count for a post type: shown in the admin,
	 * not post formats, and not switched off in the rule settings.
	 *
	 * @return array<string, \WP_Taxonomy>
	 */
	public static function taxonomies( string $post_type ): array {
		$rules = RuleStore::rules();
		$out   = [];

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $name => $taxonomy ) {
			if ( 'post_format' !== $name && $taxonomy->show_ui && $rules->taxonomy( $name, $taxonomy->hierarchical )['enabled'] ) {
				$out[ $name ] = $taxonomy;
			}
		}

		return $out;
	}

	/**
	 * Every taxonomy that can carry term rules, for the term screens.
	 *
	 * @return array<string, \WP_Taxonomy>
	 */
	public static function all_taxonomies(): array {
		$out = [];

		foreach ( ItemRule::post_types() as $type ) {
			$out += self::taxonomies( $type );
		}

		return $out;
	}

	/**
	 * The term layer for one item, with ancestors already walked.
	 *
	 * @return TermRule[]
	 */
	public static function for_post( \WP_Post $post ): array {
		$rules = RuleStore::rules();
		$out   = [];

		foreach ( self::taxonomies( $post->post_type ) as $name => $taxonomy ) {
			$terms = get_the_terms( $post, $name );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			$row    = $rules->taxonomy( $name, $taxonomy->hierarchical );
			$label  = (string) ( $taxonomy->labels->singular_name ?? $name );
			$cache  = [];
			$parent = [];

			foreach ( $terms as $term ) {
				$chain = $row['inherit'] ? array_merge( [ $term->term_id ], get_ancestors( $term->term_id, $name, 'taxonomy' ) ) : [ $term->term_id ];

				foreach ( $chain as $i => $id ) {
					$cache[ $id ] ??= self::get( $id );

					if ( isset( $chain[ $i + 1 ] ) ) {
						$parent[ $id ] = $chain[ $i + 1 ];
					}
				}

				$from = self::nearest( $term->term_id, $cache, $parent, $row['inherit'] );

				if ( null === $from ) {
					continue;
				}

				$rule  = $cache[ $from ];
				$shown = $from === $term->term_id ? $term->name : sprintf( '%s (from %s)', $term->name, get_term( $from )->name ?? '' );

				$out[] = new TermRule(
					$label,
					$shown,
					$rule['kind'],
					$rule['levels'],
					$rule['opens_until'],
					$row['priority'] * 1000 + $rule['priority'],
				);
			}
		}

		return $out;
	}
}
