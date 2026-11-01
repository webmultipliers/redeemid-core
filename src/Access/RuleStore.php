<?php

namespace RedeemID\Access;

/**
 * Where the site-wide rule settings live: one option, overridable from code
 * with the redeemid_access_rules filter, like the levels.
 *
 * It also keeps the rules version. Every item caches its rule layers stamped
 * with the version (Layers), so anything that can change many items at once
 * (a term rule, a term's parent, these settings) bumps the version instead of
 * finding and clearing every affected item.
 */
final class RuleStore {
	public const OPTION  = 'redeemid_access_rules';
	public const VERSION = 'redeemid_access_rules_version';

	private static ?Rules $rules = null;

	private static ?string $version = null;

	public static function saved(): array {
		$saved = get_option( self::OPTION, [] );

		return is_array( $saved ) ? $saved : [];
	}

	public static function config(): array {
		/**
		 * Filter the site-wide rule settings: site_levels, conflict (most,
		 * least, priority), types (post type => enabled, levels, teaser,
		 * public_after_days) and taxonomies (taxonomy => enabled, inherit,
		 * priority).
		 */
		$config = apply_filters( 'redeemid_access_rules', self::saved() );

		return is_array( $config ) ? $config : [];
	}

	public static function managed_in_code(): bool {
		return has_filter( 'redeemid_access_rules' ) && self::config() !== self::saved();
	}

	public static function rules(): Rules {
		return self::$rules ??= Rules::from_array( self::config() );
	}

	public static function save( Rules $rules ): void {
		update_option( self::OPTION, $rules->to_array(), true );
		self::$rules = null;
		self::bump();
	}

	/**
	 * The stamp every cached item is checked against. It joins the stored
	 * counter with a hash of everything code can change without saving
	 * anything: the filtered settings, the gated post types and their
	 * taxonomies. Editing a filter in code invalidates the caches on the next
	 * request, with no bump.
	 */
	public static function version(): string {
		if ( null === self::$version ) {
			$shape = [ self::rules()->to_array() ];

			foreach ( ItemRule::post_types() as $type ) {
				$shape[ $type ] = array_keys( TermRules::taxonomies( $type ) );
			}

			self::$version = (int) get_option( self::VERSION, 0 ) . ':' . substr( md5( (string) wp_json_encode( $shape ) ), 0, 12 );
		}

		return self::$version;
	}

	/**
	 * Something changed that may affect any item. Cached layers go stale and
	 * rebuild when next read.
	 */
	public static function bump(): void {
		update_option( self::VERSION, (int) get_option( self::VERSION, 0 ) + 1, true );
		self::$rules   = null;
		self::$version = null;
		Gatekeeper::flush();

		/** This action is documented in src/Access/ItemRule.php */
		do_action( 'redeemid_access_changed', [] );
	}
}
