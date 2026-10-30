<?php

namespace RedeemID\Support;

use RedeemID\Access\Level;
use RedeemID\Access\LevelStore;
use RedeemID\Access\Rules;
use RedeemID\Access\RuleStore;
use RedeemID\Access\TermRules;
use RedeemID\Features\Feature;
use RedeemID\Features\Registry;
use RedeemID\Features\Route;
use RedeemID\Settings;

/**
 * Settings export and import, for moving a set-up site from staging to
 * production or keeping a copy before a big change.
 *
 * A file holds configuration only: levels, the content rule settings, term
 * rules (by taxonomy and term slug), feature mappings and the membership
 * settings. Never customers, the mirror, item rules, Shopify credentials or
 * tokens. Importing replaces each chosen section whole, so the target ends
 * up matching the file; diff() is the dry run that says what that changes.
 *
 * Absolute URLs that start with the exporting site's address are rebased
 * to the importing site's, so a members' home on staging lands on the same
 * path in production.
 *
 * normalize(), validate(), rebase() and diff() are pure.
 */
final class Transfer {
	public const FORMAT  = 'redeemid-settings';
	public const VERSION = 1;

	public const LEVELS     = 'levels';
	public const RULES      = 'rules';
	public const TERM_RULES = 'term_rules';
	public const FEATURES   = 'features';
	public const SETTINGS   = 'settings';

	public const SECTIONS = [ self::LEVELS, self::RULES, self::TERM_RULES, self::FEATURES, self::SETTINGS ];

	/**
	 * Everything this site would export, with the file header.
	 */
	public static function export(): array {
		return [
			'format'      => self::FORMAT,
			'version'     => self::VERSION,
			'plugin'      => defined( 'REDEEMID_VERSION' ) ? REDEEMID_VERSION : '',
			'site'        => home_url( '/' ),
			'exported_at' => gmdate( 'c' ),
		] + self::current();
	}

	/**
	 * This site's sections, as stored (not as code filters them).
	 *
	 * @return array<string, array>
	 */
	public static function current(): array {
		return self::normalize(
			[
				self::LEVELS     => LevelStore::saved(),
				self::RULES      => RuleStore::saved(),
				self::TERM_RULES => self::term_rules(),
				self::FEATURES   => Registry::stored(),
				self::SETTINGS   => (array) get_option( Settings::OPTION, [] ),
			]
		);
	}

	/**
	 * Why a decoded file can't be imported. Empty when it can.
	 *
	 * @return string[] Problem codes.
	 */
	public static function validate( mixed $data ): array {
		if ( ! is_array( $data ) ) {
			return [ 'not_json' ];
		}

		if ( self::FORMAT !== ( $data['format'] ?? null ) ) {
			return [ 'wrong_format' ];
		}

		$problems = [];

		if ( ! is_int( $data['version'] ?? null ) || $data['version'] < 1 || $data['version'] > self::VERSION ) {
			$problems[] = 'newer_version';
		}

		foreach ( self::SECTIONS as $section ) {
			if ( array_key_exists( $section, $data ) && ! is_array( $data[ $section ] ) ) {
				$problems[] = 'bad_' . $section;
			}
		}

		if ( ! array_intersect( self::SECTIONS, array_keys( $data ) ) ) {
			$problems[] = 'empty';
		}

		return $problems;
	}

	/**
	 * Clean every section present in $data the way each store would on
	 * save. Sections missing from $data are left out, so they are never
	 * touched on import.
	 *
	 * @return array<string, array>
	 */
	public static function normalize( array $data ): array {
		$out = [];

		if ( is_array( $data[ self::LEVELS ] ?? null ) ) {
			$out[ self::LEVELS ] = [];

			foreach ( $data[ self::LEVELS ] as $slug => $level ) {
				$slug = Level::slug( (string) $slug );

				if ( '' !== $slug && ! in_array( $slug, [ Level::PUBLIC, Level::SIGNED_IN ], true ) && is_array( $level ) ) {
					$out[ self::LEVELS ][ $slug ] = Level::from_array( $slug, $level )->to_array();
				}
			}

			ksort( $out[ self::LEVELS ] );
		}

		if ( is_array( $data[ self::RULES ] ?? null ) ) {
			$out[ self::RULES ] = $data[ self::RULES ] ? Rules::from_array( $data[ self::RULES ] )->to_array() : [];
		}

		if ( is_array( $data[ self::TERM_RULES ] ?? null ) ) {
			$out[ self::TERM_RULES ] = [];

			foreach ( $data[ self::TERM_RULES ] as $taxonomy => $terms ) {
				foreach ( is_string( $taxonomy ) && is_array( $terms ) ? $terms : [] as $slug => $rule ) {
					$rule = TermRules::sanitize( $rule );

					if ( is_string( $slug ) && '' !== $slug && TermRules::NONE !== $rule['kind'] ) {
						$out[ self::TERM_RULES ][ $taxonomy ][ $slug ] = $rule;
					}
				}
			}

			ksort( $out[ self::TERM_RULES ] );
		}

		if ( is_array( $data[ self::FEATURES ] ?? null ) ) {
			$out[ self::FEATURES ] = [];

			foreach ( $data[ self::FEATURES ] as $slug => $row ) {
				$slug = Feature::slug( (string) $slug );

				if ( '' !== $slug && is_array( $row['routes'] ?? null ) ) {
					$out[ self::FEATURES ][ $slug ] = [ 'routes' => array_values( array_map( static fn( $r ) => Route::from_array( is_array( $r ) ? $r : [] )->to_array(), $row['routes'] ) ) ];
				}
			}

			ksort( $out[ self::FEATURES ] );
		}

		if ( is_array( $data[ self::SETTINGS ] ?? null ) ) {
			// Settings::sanitize() needs WordPress; apply() runs it. Here, only known keys pass.
			$out[ self::SETTINGS ] = array_intersect_key( $data[ self::SETTINGS ], array_flip( self::SETTING_KEYS ) );
			ksort( $out[ self::SETTINGS ] );
		}

		return $out;
	}

	private const SETTING_KEYS = [ 'clear_cart_on_logout', 'quiet_days', 'alert_email', 'login_button', 'shopify_login_links', 'subscription_url', 'storefront_pages', 'product_path', 'collection_path', 'cart_path', 'storefront_first' ];

	/**
	 * Rebase absolute URLs on the exporting site to this one: a level's
	 * call to action and members' home, and the subscription link.
	 */
	public static function rebase( array $sections, string $from, string $to ): array {
		$from = rtrim( $from, '/' );
		$to   = rtrim( $to, '/' );

		if ( '' === $from || '' === $to || $from === $to ) {
			return $sections;
		}

		$move = static fn( string $url ): string => ( $url === $from || str_starts_with( $url, $from . '/' ) || str_starts_with( $url, $from . '?' ) ) ? $to . substr( $url, strlen( $from ) ) : $url;

		foreach ( $sections[ self::LEVELS ] ?? [] as $slug => $level ) {
			foreach ( [ 'cta_url', 'home_url' ] as $key ) {
				if ( is_string( $level[ $key ] ?? null ) && '' !== $level[ $key ] ) {
					$sections[ self::LEVELS ][ $slug ][ $key ] = $move( $level[ $key ] );
				}
			}
		}

		if ( is_string( $sections[ self::SETTINGS ]['subscription_url'] ?? null ) && '' !== $sections[ self::SETTINGS ]['subscription_url'] ) {
			$sections[ self::SETTINGS ]['subscription_url'] = $move( $sections[ self::SETTINGS ]['subscription_url'] );
		}

		return $sections;
	}

	/**
	 * What importing $incoming over $current would change, per section
	 * present in $incoming: keys added, changed and removed. Term rules are
	 * keyed "taxonomy/slug"; the rules and settings sections by their
	 * top-level setting.
	 *
	 * @return array<string, array{added: string[], changed: string[], removed: string[]}>
	 */
	public static function diff( array $current, array $incoming ): array {
		$out = [];

		foreach ( self::SECTIONS as $section ) {
			if ( ! array_key_exists( $section, $incoming ) ) {
				continue;
			}

			$old = self::flat( $section, (array) ( $current[ $section ] ?? [] ) );
			$new = self::flat( $section, (array) $incoming[ $section ] );

			$out[ $section ] = [
				'added'   => array_values( array_map( 'strval', array_keys( array_diff_key( $new, $old ) ) ) ),
				'changed' => array_values( array_map( 'strval', array_keys( array_filter( array_intersect_key( $new, $old ), static fn( $v, $k ) => $v !== $old[ $k ], ARRAY_FILTER_USE_BOTH ) ) ) ),
				'removed' => array_values( array_map( 'strval', array_keys( array_diff_key( $old, $new ) ) ) ),
			];
		}

		return $out;
	}

	private static function flat( string $section, array $rows ): array {
		if ( self::TERM_RULES !== $section ) {
			return $rows;
		}

		$flat = [];

		foreach ( $rows as $taxonomy => $terms ) {
			foreach ( (array) $terms as $slug => $rule ) {
				$flat[ $taxonomy . '/' . $slug ] = $rule;
			}
		}

		return $flat;
	}

	/**
	 * Write the chosen sections. Returns what happened: term rules whose
	 * term doesn't exist here are skipped and listed, and sections a code
	 * filter manages are written but flagged, since the filter still wins.
	 *
	 * @param string[] $only Sections to write; the rest of $sections is ignored.
	 * @return array{written: string[], skipped_terms: string[], managed: string[]}
	 */
	public static function apply( array $sections, array $only ): array {
		$report = [ 'written' => [], 'skipped_terms' => [], 'managed' => [] ];
		$only   = array_values( array_intersect( self::SECTIONS, $only, array_keys( $sections ) ) );

		foreach ( $only as $section ) {
			$data = (array) $sections[ $section ];

			switch ( $section ) {
				case self::LEVELS:
					LevelStore::replace( $data );
					LevelStore::managed_in_code() && $report['managed'][] = $section;
					break;
				case self::RULES:
					RuleStore::save( Rules::from_array( $data ) );
					RuleStore::managed_in_code() && $report['managed'][] = $section;
					break;
				case self::TERM_RULES:
					$report['skipped_terms'] = self::write_term_rules( $data );
					break;
				case self::FEATURES:
					Registry::replace( $data );
					array_filter( Registry::features(), static fn( Feature $f ) => $f->managed && isset( $data[ $f->slug ] ) ) && $report['managed'][] = $section;
					break;
				case self::SETTINGS:
					// A setting the file doesn't hold (an older export) keeps its current value.
					update_option( Settings::OPTION, Settings::sanitize( $data + Settings::all() ), true );
					break;
			}

			$report['written'][] = $section;
		}

		Logger::log( 'admin.settings_imported', [ 'user' => get_current_user_id(), 'sections' => implode( ',', $report['written'] ) ] );

		return $report;
	}

	/**
	 * Term rules on this site: taxonomy => term slug => rule.
	 *
	 * @return array<string, array<string, array>>
	 */
	public static function term_rules(): array {
		$out   = [];
		$terms = get_terms(
			[
				'hide_empty' => false,
				'meta_key'   => TermRules::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- admin export, not a page view.
			]
		);

		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( $term instanceof \WP_Term ) {
				$out[ $term->taxonomy ][ $term->slug ] = get_term_meta( $term->term_id, TermRules::META, true );
			}
		}

		return $out;
	}

	/**
	 * Replace every term rule with $rules. Each write bumps the rules
	 * version through Layers, so items pick up the change on their next view.
	 *
	 * @return string[] "taxonomy/slug" of rules whose term doesn't exist here.
	 */
	private static function write_term_rules( array $rules ): array {
		$skipped = [];
		$keep    = [];

		foreach ( $rules as $taxonomy => $terms ) {
			foreach ( (array) $terms as $slug => $rule ) {
				$term = taxonomy_exists( (string) $taxonomy ) ? get_term_by( 'slug', (string) $slug, (string) $taxonomy ) : false;

				if ( ! $term instanceof \WP_Term ) {
					$skipped[] = $taxonomy . '/' . $slug;
					continue;
				}

				update_term_meta( $term->term_id, TermRules::META, TermRules::sanitize( $rule ) );
				$keep[ $term->term_id ] = true;
			}
		}

		foreach ( self::term_rules() as $taxonomy => $terms ) {
			foreach ( array_keys( $terms ) as $slug ) {
				$term = get_term_by( 'slug', (string) $slug, (string) $taxonomy );

				if ( $term instanceof \WP_Term && ! isset( $keep[ $term->term_id ] ) ) {
					delete_term_meta( $term->term_id, TermRules::META );
				}
			}
		}

		return $skipped;
	}
}
