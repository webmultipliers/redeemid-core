<?php

namespace WP2_Shopify\Access;

/**
 * Gathers every rule layer on one item into a Context: the item's own rule,
 * its terms (ancestors walked), its post type and the site default.
 *
 * Gathering terms is the costly part, so the Context is cached on the item
 * in _wp2_access_effective, stamped with the rules version. Only layers are
 * cached; the Resolver runs on them at request time, so embargoes, free
 * windows and Opens expiry take effect on their date with no edit and no
 * cache flush.
 *
 * What invalidates the cache, in full:
 *
 * - The item's own rule saved, or its _wp2_access meta written any other way: that item.
 * - The item saved (post type, publish date, status): that item.
 * - The item's terms set or removed: that item.
 * - A term rule written: every item, by version bump.
 * - A term edited (name, parent), deleted or split, in a taxonomy where any
 *   term has a rule: every item, by version bump.
 * - The rule settings saved: every item, by version bump.
 * - The rule settings filter, gated post types or their taxonomies changed in
 *   code: every item, because the version hashes them.
 *
 * Levels are not in the cache: they are applied by the Resolver and Checker
 * on every request, so editing a level needs no invalidation here.
 */
final class Layers {
	public const CACHE = '_wp2_access_effective';

	public static function register(): void {
		add_action( 'save_post', [ self::class, 'forget' ] );
		add_action( 'set_object_terms', [ self::class, 'terms_set' ] );
		add_action( 'deleted_term_relationships', [ self::class, 'terms_set' ] );

		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'meta_changed' ], 10, 3 );
		}

		foreach ( [ 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'term_meta_changed' ], 10, 3 );
		}

		add_action( 'edited_term', [ self::class, 'term_changed' ], 10, 3 );
		add_action( 'delete_term', [ self::class, 'term_changed' ], 10, 3 );
		add_action( 'split_shared_term', [ RuleStore::class, 'bump' ] );
	}

	public static function context( \WP_Post $post ): Context {
		if ( ! ItemRule::gated( $post ) ) {
			return new Context();
		}

		$version = RuleStore::version();
		$cached  = get_post_meta( $post->ID, self::CACHE, true );

		if ( is_array( $cached ) && ( $cached['v'] ?? '' ) === $version && is_array( $cached['ctx'] ?? null ) ) {
			return Context::from_array( $cached['ctx'] );
		}

		$context = self::build( $post );

		// Revisions and unsaved drafts are never served, so they are not worth a write.
		if ( $post->ID > 0 && 'auto-draft' !== $post->post_status && ! wp_is_post_revision( $post ) ) {
			update_post_meta( $post->ID, self::CACHE, [ 'v' => $version, 'ctx' => $context->to_array() ] );
		}

		return $context;
	}

	public static function build( \WP_Post $post ): Context {
		$rules = RuleStore::rules();
		$item  = ItemRule::get( $post );
		$type  = $rules->type( $post->post_type );
		$label = get_post_type_object( $post->post_type )->labels->singular_name ?? $post->post_type;

		return new Context(
			item_mode: $item['mode'],
			item_levels: $item['levels'],
			terms: TermRules::for_post( $post ),
			type_levels: $type['levels'],
			type_label: 'Post type: ' . $label,
			site_levels: $rules->site_levels,
			conflict: $rules->conflict,
			locked_until: $item['locked_until'],
			public_until: $item['public_until'],
			published: (int) get_post_time( 'U', true, $post ) ?: null,
			type_public_after: $type['public_after_days'] ? $type['public_after_days'] * DAY_IN_SECONDS : null,
			meter_exempt: $item['meter_exempt'],
		);
	}

	public static function forget( mixed $post_id ): void {
		$post_id = (int) $post_id;

		if ( $post_id > 0 ) {
			// Straight to the metadata API, so meta_changed below does not see its own delete.
			delete_metadata( 'post', $post_id, self::CACHE );
			Gatekeeper::flush();
		}
	}

	public static function terms_set( mixed $object_id ): void {
		self::forget( $object_id );

		/** This action is documented in src/Access/ItemRule.php */
		do_action( 'wp2_shopify_access_changed', [ (int) $object_id ] );
	}

	public static function meta_changed( mixed $meta_id, mixed $post_id, mixed $key ): void {
		if ( ItemRule::META === $key ) {
			self::forget( $post_id );
		}
	}

	public static function term_meta_changed( mixed $meta_id, mixed $term_id, mixed $key ): void {
		// One term can cover thousands of items, so every cached item goes stale at once.
		if ( TermRules::META === $key ) {
			RuleStore::bump();
		}
	}

	public static function term_changed( mixed $term_id, mixed $tt_id, mixed $taxonomy ): void {
		// A parent or name change only matters where some term carries a rule.
		if ( is_string( $taxonomy ) && isset( TermRules::all_taxonomies()[ $taxonomy ] ) && TermRules::in_use( $taxonomy ) ) {
			RuleStore::bump();
		}
	}
}
