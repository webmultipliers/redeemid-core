<?php

namespace RedeemID\Access;

/**
 * What the Resolver decided for one item, before looking at any visitor.
 *
 * The requirement is in conjunctive form: every clause must pass, and a
 * clause passes when the visitor holds any one level in it. No clauses means
 * the item is open.
 *
 * Unlock tags are a way in beside the clauses: a visitor holding any one of
 * them reads the item whatever the clauses say (the item sold on its own).
 * They are only kept on a rule that is not open.
 */
final class EffectiveRule {
	/**
	 * @param string[][] $clauses
	 * @param string     $source        The layer that decided, such as "Category: Investigations".
	 * @param string[]   $contributing  Every source that took part, the winner included, for provenance.
	 * @param bool       $meter_eligible A future meter may grant an exception to this item.
	 * @param string[]   $unlock_tags    Shopify tags that also open the item, any one passing.
	 */
	public function __construct(
		public readonly array $clauses,
		public readonly string $source,
		public readonly array $contributing = [],
		public readonly bool $meter_eligible = false,
		public readonly array $unlock_tags = [],
	) {}

	/**
	 * Whether the item can also be bought on its own.
	 */
	public function sold(): bool {
		return ! $this->open() && (bool) $this->unlock_tags;
	}

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
	 * Just the levels a reader needs, by label, for editors: "Gold or Silver",
	 * and "Gold or Silver, or buyers of this item" when it is sold on its own.
	 * Empty when the item is open.
	 */
	public function readers( ?Levels $levels = null ): string {
		$label = static fn( string $slug ) => $levels?->get( $slug )?->label ?? $slug;
		$needs = implode( ' and ', array_map( static fn( array $c ) => implode( ' or ', array_map( $label, $c ) ), $this->clauses ) );

		return $this->sold() ? $needs . ', or buyers of this item' : $needs;
	}
}
