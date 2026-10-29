<?php

namespace RedeemID\Access;

/**
 * What the Resolver decided for one item, before looking at any visitor.
 *
 * The requirement is in conjunctive form: every clause must pass, and a
 * clause passes when the visitor holds any one level in it. No clauses means
 * the item is open.
 */
final class EffectiveRule {
	/**
	 * @param string[][] $clauses
	 * @param string     $source        The layer that decided, such as "Category: Investigations".
	 * @param string[]   $contributing  Every source that took part, the winner included, for provenance.
	 * @param bool       $meter_eligible A future meter may grant an exception to this item.
	 */
	public function __construct(
		public readonly array $clauses,
		public readonly string $source,
		public readonly array $contributing = [],
		public readonly bool $meter_eligible = false,
	) {}

	public function open(): bool {
		return ! $this->clauses;
	}

	/**
	 * @return string[] Every level named anywhere in the requirement.
	 */
	public function levels(): array {
		return array_values( array_unique( array_merge( [], ...$this->clauses ) ) );
	}

	/**
	 * Provenance in words, for the list column, Access → Test and WP-CLI:
	 * "gold, from Category: Investigations (also Category: Culture)".
	 */
	public function explain( ?Levels $levels = null ): string {
		if ( $this->open() ) {
			return 'Open, from ' . $this->source;
		}

		$others = array_values( array_diff( $this->contributing, explode( ' + ', $this->source ) ) );
		$suffix = $others ? ' (also ' . implode( ', ', $others ) . ')' : '';

		return $this->readers( $levels ) . ', from ' . $this->source . $suffix;
	}

	/**
	 * Just the levels a reader needs, by label, for editors: "Gold or Silver".
	 * Empty when the item is open.
	 */
	public function readers( ?Levels $levels = null ): string {
		$label = static fn( string $slug ) => $levels?->get( $slug )?->label ?? $slug;

		return implode( ' and ', array_map( static fn( array $c ) => implode( ' or ', array_map( $label, $c ) ), $this->clauses ) );
	}
}
