<?php

namespace WP2_Shopify\Access;

/**
 * The access rule saved on one item: its mode, its levels, its windows.
 * Stored as one post meta array so a rule is always read and written whole.
 */
final class ItemRule {
	public const META = '_wp2_access';

	public const MODES = [ Context::ITEM_INHERIT, Context::ITEM_PUBLIC, Context::ITEM_LEVEL, Context::ITEM_ADD ];

	/**
	 * Post types that can carry access rules. Public types except media by default.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );

		/**
		 * Filter which post types can be gated.
		 */
		return array_values( array_filter( (array) apply_filters( 'wp2_shopify_gated_post_types', $types ), 'is_string' ) );
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
	 * @return array{mode: string, levels: string[], locked_until: ?int, public_until: ?int, meter_exempt: bool}
	 */
	public static function sanitize( mixed $value ): array {
		$value  = is_array( $value ) ? $value : [];
		$mode   = in_array( $value['mode'] ?? '', self::MODES, true ) ? $value['mode'] : Context::ITEM_INHERIT;
		$levels = array_values( array_unique( array_filter( array_map( static fn( $s ) => Level::slug( $s ), (array) ( $value['levels'] ?? [] ) ) ) ) );
		$time   = static fn( $t ) => is_numeric( $t ) && (int) $t > 0 ? (int) $t : null;

		return [
			'mode'         => $mode,
			'levels'       => $levels,
			'locked_until' => $time( $value['locked_until'] ?? null ),
			'public_until' => $time( $value['public_until'] ?? null ),
			'meter_exempt' => ! empty( $value['meter_exempt'] ),
		];
	}

	public static function get( \WP_Post $post ): array {
		return self::sanitize( get_post_meta( $post->ID, self::META, true ) );
	}

	public static function save( int $post_id, array $rule ): void {
		$rule = self::sanitize( $rule );

		if ( Context::ITEM_INHERIT === $rule['mode'] && ! $rule['locked_until'] && ! $rule['public_until'] && ! $rule['meter_exempt'] ) {
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
		do_action( 'wp2_shopify_access_changed', [ $post_id ] );
	}

	/**
	 * The item's layers for the Resolver. Post type and term layers join in a later PR.
	 */
	public static function context( \WP_Post $post ): Context {
		if ( ! self::gated( $post ) ) {
			return new Context();
		}

		$rule = self::get( $post );

		return new Context(
			item_mode: $rule['mode'],
			item_levels: $rule['levels'],
			locked_until: $rule['locked_until'],
			public_until: $rule['public_until'],
			published: (int) get_post_time( 'U', true, $post ) ?: null,
			meter_exempt: $rule['meter_exempt'],
		);
	}
}
