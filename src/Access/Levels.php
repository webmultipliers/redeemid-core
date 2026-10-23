<?php

namespace WP2_Shopify\Access;

use WP2_Shopify\Entitlement\Evaluator;

/**
 * The set of access levels, with the inclusion arithmetic the Resolver needs.
 * Pure: no WordPress calls, so it is unit tested with the Resolver.
 *
 * Inclusion is transitive (Gold includes Silver includes Members) and is
 * expanded before rules are combined, so a story needing Silver and Gold
 * needs Gold once, not both.
 */
final class Levels {
	/** @var array<string, Level> */
	private array $levels = [];

	/** @var array<string, string[]> slug => every slug it opens, itself included. */
	private array $closure = [];

	/**
	 * @param Level[] $levels Custom levels; the built-ins are always present and can't be replaced.
	 */
	public function __construct( array $levels = [] ) {
		foreach ( $levels as $level ) {
			if ( $level instanceof Level && '' !== $level->slug && ! $level->builtin() ) {
				$this->levels[ $level->slug ] = $level;
			}
		}

		$this->levels[ Level::PUBLIC ]    = Level::public();
		$this->levels[ Level::SIGNED_IN ] = Level::signed_in();
	}

	/**
	 * @param array<string, array> $config slug => Level::from_array() input.
	 */
	public static function from_config( array $config ): self {
		$levels = [];

		foreach ( $config as $slug => $a ) {
			$slug = Level::slug( (string) $slug );

			if ( '' !== $slug && is_array( $a ) ) {
				$levels[] = Level::from_array( $slug, $a );
			}
		}

		return new self( $levels );
	}

	public function get( string $slug ): ?Level {
		return $this->levels[ $slug ] ?? null;
	}

	public function has( string $slug ): bool {
		return isset( $this->levels[ $slug ] );
	}

	/**
	 * @return array<string, Level>
	 */
	public function all(): array {
		return $this->levels;
	}

	/**
	 * Every level that holding $slug opens, itself included. Unknown includes
	 * are skipped and cycles stop, so a bad config can't loop.
	 *
	 * @return string[]
	 */
	public function closure( string $slug ): array {
		if ( isset( $this->closure[ $slug ] ) ) {
			return $this->closure[ $slug ];
		}

		$seen  = [];
		$queue = [ $slug ];

		while ( $queue ) {
			$next = array_shift( $queue );

			if ( isset( $seen[ $next ] ) || ! isset( $this->levels[ $next ] ) ) {
				continue;
			}

			$seen[ $next ] = true;
			array_push( $queue, ...$this->levels[ $next ]->includes );
		}

		return $this->closure[ $slug ] = array_keys( $seen );
	}

	/**
	 * Does holding $holder open content that needs $needed?
	 */
	public function covers( string $holder, string $needed ): bool {
		return in_array( $needed, $this->closure( $holder ), true );
	}

	/**
	 * Every level whose holders pass a requirement for $slug: itself, and
	 * every level that includes it.
	 *
	 * @return string[]
	 */
	public function grantors( string $slug ): array {
		return array_values( array_filter( array_keys( $this->levels ), fn( $s ) => $this->covers( $s, $slug ) ) );
	}

	/**
	 * Simplify a requirement in conjunctive form: every clause must pass, and a
	 * clause passes when the visitor holds any level in it.
	 *
	 * - Inside a clause, a level that covers another one in the same clause is
	 *   redundant: anyone holding it already passes the narrower one.
	 * - A clause is dropped when another clause already implies it: every level
	 *   in the other clause covers some level in this one (Gold implies Silver).
	 * - Public in a clause makes the clause always pass, so it is dropped.
	 *
	 * Unknown slugs are kept, so they fail closed in the Checker.
	 *
	 * @param string[][] $clauses
	 * @return string[][]
	 */
	public function reduce( array $clauses ): array {
		$out = [];

		foreach ( $clauses as $clause ) {
			$clause = array_values( array_unique( array_filter( (array) $clause, 'is_string' ) ) );

			if ( ! $clause || in_array( Level::PUBLIC, $clause, true ) ) {
				continue;
			}

			$clause = array_values(
				array_filter(
					$clause,
					function ( $a ) use ( $clause ) {
						foreach ( $clause as $b ) {
							if ( $a !== $b && $this->covers( $a, $b ) && ! $this->covers( $b, $a ) ) {
								return false;
							}
						}

						return true;
					}
				)
			);

			$out[] = $clause;
		}

		$keep = [];

		foreach ( $out as $i => $clause ) {
			foreach ( $out as $j => $other ) {
				if ( $i === $j || ! $this->implies( $other, $clause ) ) {
					continue;
				}

				// Two clauses that imply each other: keep the first one only.
				if ( $this->implies( $clause, $other ) && $i < $j ) {
					continue;
				}

				continue 2;
			}

			$keep[] = $clause;
		}

		return $keep;
	}

	/**
	 * Does passing $a guarantee passing $b?
	 */
	private function implies( array $a, array $b ): bool {
		foreach ( $a as $holder ) {
			$covered = false;

			foreach ( $b as $needed ) {
				if ( $this->covers( $holder, $needed ) ) {
					$covered = true;
					break;
				}
			}

			if ( ! $covered ) {
				return false;
			}
		}

		return (bool) $a;
	}

	/**
	 * Would a customer with these tags hold $slug? Tags only: no freshness or
	 * account checks. Used for the Levels screen's dry run, never for access.
	 *
	 * @param string[] $tags
	 */
	public function held_by_tags( string $slug, array $tags ): bool {
		$have = array_map( [ Evaluator::class, 'normalize_tag' ], $tags );

		foreach ( $this->grantors( $slug ) as $grantor ) {
			foreach ( $this->levels[ $grantor ]->groups as $group ) {
				if ( ! $group->required_tags ) {
					continue;
				}

				$need = array_map( [ Evaluator::class, 'normalize_tag' ], $group->required_tags );
				$hits = array_intersect( array_unique( $need ), $have );

				if ( 'all' === $group->match_mode ? count( array_unique( $hits ) ) === count( array_unique( $need ) ) : (bool) $hits ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Level health: the level's tags that no synced customer or recent webhook
	 * carries. A renamed or deleted Shopify tag shows up here instead of as a
	 * locked-out member.
	 *
	 * @param string[] $known_tags Tags seen in the mirror or recent webhooks.
	 * @return string[] Missing tags, as written on the level.
	 */
	public function missing_tags( string $slug, array $known_tags ): array {
		$level = $this->get( $slug );

		if ( ! $level ) {
			return [];
		}

		$known = array_flip( array_map( [ Evaluator::class, 'normalize_tag' ], $known_tags ) );

		return array_values( array_filter( $level->tags(), static fn( $tag ) => ! isset( $known[ Evaluator::normalize_tag( $tag ) ] ) ) );
	}

	/**
	 * Level problems that make it grant nobody or behave unexpectedly.
	 *
	 * @param string[] $known_tags
	 * @return string[] Problem codes: no_condition, missing_tags, unknown_include.
	 */
	public function health( string $slug, array $known_tags ): array {
		$level = $this->get( $slug );

		if ( ! $level || $level->builtin() ) {
			return [];
		}

		$problems = [];

		if ( ! $level->groups ) {
			$problems[] = 'no_condition';
		}

		if ( $this->missing_tags( $slug, $known_tags ) ) {
			$problems[] = 'missing_tags';
		}

		foreach ( $level->includes as $include ) {
			if ( ! $this->has( $include ) ) {
				$problems[] = 'unknown_include';
				break;
			}
		}

		return $problems;
	}
}
