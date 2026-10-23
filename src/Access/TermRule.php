<?php

namespace WP2_Shopify\Access;

/**
 * A rule saved on one taxonomy term, already walked up from a parent term
 * when the taxonomy inherits. Plain data for the Resolver.
 */
final class TermRule {
	public const REQUIRES = 'requires';
	public const OPENS    = 'opens';

	/**
	 * @param string[] $levels      Any one of these levels passes (Requires only).
	 * @param ?int     $opens_until Expiry of an Opens rule. An Opens rule without one never applies.
	 * @param int      $priority    Taxonomy priority, then term priority, for the priority conflict mode.
	 */
	public function __construct(
		public readonly string $taxonomy,
		public readonly string $term,
		public readonly string $kind,
		public readonly array $levels = [],
		public readonly ?int $opens_until = null,
		public readonly int $priority = 0,
	) {}

	public function label(): string {
		return $this->taxonomy . ': ' . $this->term;
	}

	/**
	 * An Opens rule applies only before its expiry, and every Opens must have
	 * one: an undated free pass is how a promotion becomes a permanent leak.
	 */
	public function opens_at( int $now ): bool {
		return self::OPENS === $this->kind && null !== $this->opens_until && $now < $this->opens_until;
	}
}
