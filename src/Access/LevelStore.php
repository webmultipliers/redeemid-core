<?php

namespace WP2_Shopify\Access;

use WP2_Shopify\Sync\Mirror;

/**
 * Where access levels live: one option, overridable from code with the
 * wp2_shopify_access_levels filter so a site can keep them in version control.
 * When the filter changes the result, the Access screen shows the levels as
 * managed in code and read-only, so an edit never silently does nothing.
 */
final class LevelStore {
	public const OPTION     = 'wp2_shopify_access_levels';
	private const KNOWN_KEY = 'wp2_shopify_known_tags';

	private static ?Levels $levels = null;

	/**
	 * @return array<string, array> slug => level config.
	 */
	public static function saved(): array {
		$saved = get_option( self::OPTION, [] );

		return is_array( $saved ) ? $saved : [];
	}

	/**
	 * @return array<string, array>
	 */
	public static function config(): array {
		/**
		 * Filter the access levels. Keys are slugs; values take label, groups
		 * ([ [ 'tags' => [...], 'match' => 'any'|'all' ] ]), includes, badge,
		 * message, cta_label and cta_url.
		 */
		$config = apply_filters( 'wp2_shopify_access_levels', self::saved() );

		return is_array( $config ) ? $config : [];
	}

	public static function managed_in_code(): bool {
		return has_filter( 'wp2_shopify_access_levels' ) && self::config() !== self::saved();
	}

	public static function levels(): Levels {
		return self::$levels ??= Levels::from_config( self::config() );
	}

	public static function save( string $slug, array $level ): void {
		$slug = Level::slug( $slug );

		if ( '' === $slug || in_array( $slug, [ Level::PUBLIC, Level::SIGNED_IN ], true ) ) {
			return;
		}

		$saved          = self::saved();
		$saved[ $slug ] = Level::from_array( $slug, $level )->to_array();
		ksort( $saved );
		self::write( $saved );
	}

	public static function delete( string $slug ): void {
		$saved = self::saved();
		unset( $saved[ $slug ] );
		self::write( $saved );
	}

	private static function write( array $saved ): void {
		update_option( self::OPTION, $saved, true );
		self::$levels = null;

		/**
		 * Levels changed. Later PRs bump the rules version here so cached
		 * effective rules resolve fresh.
		 */
		do_action( 'wp2_shopify_access_levels_changed', $saved );
	}

	/**
	 * Every tag carried by a synced customer, cached briefly. The mirror is
	 * fed by sign-in, webhooks and reconciliation, so a tag renamed or deleted
	 * in Shopify drops out of this list once customers resync.
	 *
	 * @return string[]
	 */
	public static function known_tags( bool $fresh = false ): array {
		$cached = $fresh ? false : get_transient( self::KNOWN_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$tags = [];

		foreach ( self::tag_lists() as $list ) {
			foreach ( $list as $tag ) {
				$tags[ strtolower( trim( $tag ) ) ] = $tag;
			}
		}

		$tags = array_values( $tags );
		set_transient( self::KNOWN_KEY, $tags, 10 * MINUTE_IN_SECONDS );

		return $tags;
	}

	/**
	 * How many linked customers would hold the level today, by tags alone.
	 *
	 * @return array<string, int> slug => count, for every level.
	 */
	public static function dry_run(): array {
		$levels = self::levels();
		$counts = array_fill_keys( array_keys( $levels->all() ), 0 );

		foreach ( self::tag_lists() as $list ) {
			foreach ( array_keys( $counts ) as $slug ) {
				if ( $levels->held_by_tags( $slug, $list ) ) {
					++$counts[ $slug ];
				}
			}
		}

		return $counts;
	}

	/**
	 * @return iterable<string[]> One tag list per linked customer.
	 */
	private static function tag_lists(): iterable {
		global $wpdb;

		$offset = 0;

		do {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY umeta_id LIMIT 500 OFFSET %d", Mirror::TAGS, $offset ) );

			foreach ( $rows as $raw ) {
				$list = maybe_unserialize( $raw );

				if ( is_array( $list ) ) {
					yield array_values( array_filter( $list, 'is_string' ) );
				}
			}

			$offset += 500;
		} while ( count( $rows ) === 500 );
	}
}
