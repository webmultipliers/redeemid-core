<?php

namespace RedeemID\Access;

/**
 * The access rule saved on one item: its mode, its levels, its windows.
 * Stored as one post meta array so a rule is always read and written whole.
 */
final class ItemRule {
	public const META = '_redeemid_access';

	public const MODES = [ Context::ITEM_INHERIT, Context::ITEM_PUBLIC, Context::ITEM_LEVEL, Context::ITEM_ADD ];

	/**
	 * Post types that can carry access rules: public types except media, less
	 * any switched off in the rule settings.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$rules = RuleStore::rules();
		$types = array_values( array_filter( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ), static fn( $t ) => $rules->type( $t )['enabled'] ) );

		/**
		 * Filter which post types can be gated.
		 */
		return array_values( array_filter( (array) apply_filters( 'redeemid_gated_post_types', $types ), 'is_string' ) );
	}

	public static function gated( \WP_Post $post ): bool {
		return in_array( $post->post_type, self::post_types(), true );
	}

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_meta' ], 20 );
	}

	public static function register_meta(): void {
		foreach ( self::post_types() as $type ) {
			register_post_meta(
				$type,
				self::META,
				[
					'type'              => 'object',
					'single'            => true,
					'sanitize_callback' => [ self::class, 'sanitize' ],
					// Only people who can edit the item read or write its rule; never public REST.
					'auth_callback'     => static fn( $allowed, $key, $post_id ) => current_user_can( 'edit_post', (int) $post_id ),
					'show_in_rest'      => false,
				]
			);
		}
	}

	/**
	 * unlock_tag sells the item on its own: a customer who holds that Shopify
	 * tag can read it whatever its levels say. Shopify Flow (or any app) adds
	 * the tag when the order is paid, so the grant lives in Shopify like every
	 * other. unlock_product is the Shopify product handle the locked panel
	 * offers to buy it.
	 *
	 * @return array{mode: string, levels: string[], locked_until: ?int, public_until: ?int, meter_exempt: bool, drip_days: int, unlock_tag: string, unlock_product: string}
	 */
	public static function sanitize( mixed $value ): array {
		$value  = is_array( $value ) ? $value : [];
		$mode   = in_array( $value['mode'] ?? '', self::MODES, true ) ? $value['mode'] : Context::ITEM_INHERIT;
		$levels = array_values( array_unique( array_filter( array_map( static fn( $s ) => Level::slug( $s ), (array) ( $value['levels'] ?? [] ) ) ) ) );
		$time   = static fn( $t ) => is_numeric( $t ) && (int) $t > 0 ? (int) $t : null;

		return [
			'mode'           => $mode,
			'levels'         => $levels,
			'locked_until'   => $time( $value['locked_until'] ?? null ),
			'public_until'   => $time( $value['public_until'] ?? null ),
			'meter_exempt'   => ! empty( $value['meter_exempt'] ),
			'drip_days'      => max( 0, min( 3650, (int) ( $value['drip_days'] ?? 0 ) ) ),
			'unlock_tag'     => self::tag( $value['unlock_tag'] ?? '' ),
			'unlock_product' => self::handle( $value['unlock_product'] ?? '' ),
		];
	}

	/**
	 * A Shopify customer tag as Shopify stores it: one line, no commas (Shopify
	 * splits tags on them), at most 255 characters. Pure.
	 */
	public static function tag( mixed $raw ): string {
		$tag = is_string( $raw ) ? trim( (string) preg_replace( '/[\x00-\x1F\x7F,<>]+|\s+/u', ' ', $raw ) ) : '';

		return trim( mb_substr( (string) preg_replace( '/ {2,}/', ' ', $tag ), 0, 255 ) );
	}

	/**
	 * A Shopify product handle, or '' when it can't be one. A pasted product
	 * URL keeps its handle. Pure.
	 */
	public static function handle( mixed $raw ): string {
		$raw = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';

		if ( preg_match( '#/products/([^/?\#]+)#', $raw, $m ) ) {
			$raw = $m[1];
		}

		return preg_match( '/^[\p{Ll}\p{N}][\p{Ll}\p{N}_-]*$/u', $raw ) ? mb_substr( $raw, 0, 255 ) : '';
	}

	public static function get( \WP_Post $post ): array {
		return self::sanitize( get_post_meta( $post->ID, self::META, true ) );
	}

	public static function save( int $post_id, array $rule ): void {
		$rule = self::sanitize( $rule );

		if ( Context::ITEM_INHERIT === $rule['mode'] && ! $rule['locked_until'] && ! $rule['public_until'] && ! $rule['meter_exempt'] && ! $rule['drip_days'] && '' === $rule['unlock_tag'] ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $rule );
		}

		Gatekeeper::flush();

		/**
		 * Access rules changed for these items. Cache plugins can purge their URLs.
		 *
		 * @param int[] $post_ids Affected items; an empty list means "possibly all".
		 */
		do_action( 'redeemid_access_changed', [ $post_id ] );
	}
}
