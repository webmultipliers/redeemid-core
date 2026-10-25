<?php

namespace RedeemID\Access;

/**
 * Turns the rule layers on one item into one effective rule (design doc,
 * "How the effective rule is computed"). Pure: no WordPress calls, so the
 * whole precedence table is unit tested like the Evaluator.
 *
 * It decides *which* rule applies; the Checker then asks the Evaluator
 * whether a visitor passes it. Staff bypass is not decided here: it applies
 * only to a singular preview, which only the request knows about.
 *
 * Time is an input. Callers that cache the result must cache the Context, or
 * re-run this at request time: windows and Opens expiry are never baked into
 * a stored grant.
 */
final class Resolver {
	public function __construct( private readonly Levels $levels ) {}

	public function resolve( Context $c, int $now ): EffectiveRule {
		// 2. An item opened on purpose is open, whatever its type or terms say.
		if ( Context::ITEM_PUBLIC === $c->item_mode ) {
			return new EffectiveRule( [], 'Item: Public', [ 'Item: Public' ] );
		}

		$rule = $this->layers( $c, $now );

		// 7. Add to inherited: keep the inherited rule and require the item's level as well.
		if ( Context::ITEM_ADD === $c->item_mode && $c->item_levels ) {
			$clauses = $this->levels->reduce( array_merge( $rule->clauses, [ $c->item_levels ] ) );
			$source  = $rule->open() ? 'Item' : $rule->source . ' + Item';
			$rule    = new EffectiveRule( $clauses, $source, array_merge( $rule->contributing, [ 'Item' ] ) );
		}

		// 8. Windows last, and only ever to open on their date.
		$rule = $this->windows( $rule, $c, $now );

		return new EffectiveRule( $rule->clauses, $rule->source, $rule->contributing, ! $rule->open() && ! $c->meter_exempt );
	}

	/**
	 * Steps 3 to 6 and 9: the inherited rule.
	 */
	private function layers( Context $c, int $now ): EffectiveRule {
		// 3. The item's own levels replace everything inherited.
		if ( Context::ITEM_LEVEL === $c->item_mode ) {
			return $c->item_levels
				? new EffectiveRule( $this->levels->reduce( [ $c->item_levels ] ), 'Item', [ 'Item' ] )
				// A Level mode with no level saved fails closed to "signed in" rather than open.
				: new EffectiveRule( [ [ Level::SIGNED_IN ] ], 'Item', [ 'Item' ] );
		}

		$labels = array_map( static fn( TermRule $t ) => $t->label(), $c->terms );

		// 4. An unexpired Opens term gives the item away. Expired or undated ones are ignored.
		foreach ( $c->terms as $term ) {
			if ( $term->opens_at( $now ) ) {
				return new EffectiveRule( [], $term->label(), $labels );
			}
		}

		// 5. Requires terms, combined by the site's conflict mode after inclusion is expanded.
		$requires = array_values( array_filter( $c->terms, static fn( TermRule $t ) => TermRule::REQUIRES === $t->kind && $t->levels ) );

		if ( $requires ) {
			return $this->combine( $requires, $c->conflict, $labels );
		}

		// 6. Then the post type, then the site default.
		if ( $c->type_levels ) {
			return new EffectiveRule( $this->levels->reduce( [ $c->type_levels ] ), $c->type_label, [ $c->type_label ] );
		}

		if ( $c->site_levels ) {
			return new EffectiveRule( $this->levels->reduce( [ $c->site_levels ] ), 'Site default', [ 'Site default' ] );
		}

		// 9. Nothing applies.
		return new EffectiveRule( [], 'No rule', [] );
	}

	/**
	 * @param TermRule[] $terms  Requires rules with at least one level.
	 * @param string[]   $labels Every term that took part, for provenance.
	 */
	private function combine( array $terms, string $mode, array $labels ): EffectiveRule {
		if ( Context::CONFLICT_PRIORITY === $mode ) {
			$top = $terms[0];

			foreach ( $terms as $term ) {
				if ( $term->priority > $top->priority ) {
					$top = $term;
				}
			}

			return new EffectiveRule( $this->levels->reduce( [ $top->levels ] ), $top->label(), $labels );
		}

		if ( Context::CONFLICT_LEAST === $mode ) {
			$any = array_merge( ...array_map( static fn( TermRule $t ) => $t->levels, $terms ) );

			return new EffectiveRule( $this->levels->reduce( [ $any ] ), $this->join( $terms ), $labels );
		}

		// Most restrictive, the default and the fallback for an unknown mode: every term's rule must pass.
		$clauses = $this->levels->reduce( array_map( static fn( TermRule $t ) => $t->levels, $terms ) );
		$winners = array_values(
			array_filter(
				$terms,
				fn( TermRule $t ) => in_array( $this->levels->reduce( [ $t->levels ] )[0] ?? [], $clauses, true )
			)
		);

		return new EffectiveRule( $clauses, $this->join( $winners ?: $terms ), $labels );
	}

	private function join( array $terms ): string {
		return implode( ' + ', array_unique( array_map( static fn( TermRule $t ) => $t->label(), $terms ) ) );
	}

	private function windows( EffectiveRule $rule, Context $c, int $now ): EffectiveRule {
		if ( $rule->open() ) {
			return $rule;
		}

		$opened = match ( true ) {
			null !== $c->locked_until && $now >= $c->locked_until => 'Item: embargo ended',
			null !== $c->public_until && $now < $c->public_until => 'Item: free until a date',
			null !== $c->type_public_after && null !== $c->published && $now - $c->published >= $c->type_public_after => $c->type_label . ': archive window',
			default => null,
		};

		return null === $opened ? $rule : new EffectiveRule( [], $opened, array_merge( $rule->contributing, [ $opened ] ) );
	}
}
