<?php

namespace RedeemID;

use RedeemID\Support\Crypto;

/**
 * The names this plugin used before it was called RedeemID ("WP2 Shopify").
 *
 * Every old name is kept here and nowhere else:
 *
 * - import() moves stored data to the new names once (Migration runs it):
 *   options, user, post and term meta, the two tables, block names saved in
 *   content and widgets, sign-in menu items, the gate-bypass capability and
 *   the Shopify token inside WordPress sessions. It never overwrites a value
 *   that already has the new name.
 * - constant_name() lets an old wp-config.php constant keep working until it
 *   is renamed; the admin notice lists the ones still in use.
 * - Crypto opens values sealed under the old key label, and Compat keeps the
 *   old shortcodes rendering, since builders store them outside post content.
 *
 * Hooks, REST routes, block names in code, CSS classes and cookies were
 * renamed without aliases. Webhooks registered in Shopify point at the old
 * REST route, so they have to be registered again (the notice says so).
 */
final class Legacy {
	public const CONSTANT_PREFIX = 'WP2_SHOPIFY_';

	/** Old shortcode => the shortcode it now renders as. */
	public const SHORTCODES = [
		'wp2_shopify_login'    => 'redeemid_login',
		'wp2_shopify_identity' => 'redeemid_login',
		'wp2_feature'          => 'redeemid_feature',
	];

	/** The proof of concept's last-sync stamp, kept under its own name so it cannot clash with Mirror::LAST_SYNC. */
	public const POC_LAST_SYNC = '_redeemid_poc_last_sync';

	/** Set when import() moved anything, for the one-time admin notice. */
	public const NOTICE_OPTION = 'redeemid_rename_notice';

	private const TABLES = [ 'wp2_shopify_webhooks' => 'redeemid_webhooks', 'wp2_shopify_events' => 'redeemid_events' ];

	private const MENU_ITEMS = [ '#wp2s-login' => '#redeemid-login', '#wp2s-logout' => '#redeemid-logout' ];

	/**
	 * The old name of a REDEEMID_ constant, or null for any other name.
	 */
	public static function constant_name( string $name ): ?string {
		return str_starts_with( $name, 'REDEEMID_' ) ? self::CONSTANT_PREFIX . substr( $name, strlen( 'REDEEMID_' ) ) : null;
	}

	/**
	 * The new name of a stored option or meta key. Pure.
	 *
	 * wp2_shopify_x and wp2s_x become redeemid_x, _wp2_x becomes _redeemid_x,
	 * and the proof of concept's _wp2_last_sync becomes POC_LAST_SYNC.
	 */
	public static function key( string $old ): string {
		if ( '_wp2_last_sync' === $old ) {
			return self::POC_LAST_SYNC;
		}

		return (string) preg_replace( '/^(_?)(?:wp2_shopify_|wp2s_|wp2_)/', '$1redeemid_', $old );
	}

	/**
	 * Old block names, attributes and CSS hooks in saved markup, renamed. Pure.
	 */
	public static function content( string $html ): string {
		return strtr(
			$html,
			[
				'<!-- wp:wp2-shopify/'  => '<!-- wp:redeemid/',
				'<!-- /wp:wp2-shopify/' => '<!-- /wp:redeemid/',
				'"wp2Access"'           => '"redeemidAccess"',
			]
		);
	}

	/**
	 * Move everything stored under the old names. Safe to run again.
	 *
	 * @return bool Whether anything was found under an old name.
	 */
	public static function import(): bool {
		global $wpdb;

		$found = false;

		// Options. Transients and locks are short-lived, so they are dropped, not moved.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wp2%' OR option_name LIKE '\\_transient\\_timeout\\_wp2%' OR option_name LIKE 'wp2\\_shopify\\_lock\\_%'" );

		foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wp2\\_shopify\\_%'" ) as $old ) {
			$found = true;
			$new   = self::key( $old );

			if ( false === get_option( $new ) ) {
				$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $old ) );
				add_option( $new, get_option( $old ), '', ! in_array( $autoload, [ 'no', 'off', 'auto-off' ], true ) );
			}

			delete_option( $old );
		}

		// Meta. Old keys move only where the object has no value under the new key yet.
		foreach ( [ $wpdb->usermeta => 'user_id', $wpdb->postmeta => 'post_id', $wpdb->termmeta => 'term_id' ] as $table => $id ) {
			foreach ( $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$table} WHERE meta_key LIKE '\\_wp2\\_%' OR meta_key LIKE 'wp2\\_shopify\\_%'" ) as $old ) {
				$found = true;
				$new   = self::key( $old );

				$wpdb->query( $wpdb->prepare( "UPDATE {$table} AS o LEFT JOIN {$table} AS n ON n.{$id} = o.{$id} AND n.meta_key = %s SET o.meta_key = %s WHERE o.meta_key = %s AND n.{$id} IS NULL", $new, $new, $old ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE meta_key = %s", $old ) );
			}
		}

		// Tables. Activation may already have created empty ones under the new names.
		foreach ( self::TABLES as $old => $new ) {
			$old = $wpdb->prefix . $old;
			$new = $wpdb->prefix . $new;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ) !== $old ) {
				continue;
			}

			$found = true;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new ) ) ) === $new ) {
				if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new}" ) > 0 ) {
					continue;
				}

				$wpdb->query( "DROP TABLE {$new}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			}

			$wpdb->query( "RENAME TABLE {$old} TO {$new}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		// Block names and the per-block access attribute in saved content, templates and patterns.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%wp:wp2-shopify/%' OR post_content LIKE '%\"wp2Access\"%'" );

		foreach ( $ids as $post_id ) {
			$found   = true;
			$content = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
			$wpdb->update( $wpdb->posts, [ 'post_content' => self::content( $content ) ], [ 'ID' => (int) $post_id ] );
			clean_post_cache( (int) $post_id );
		}

		$widgets = get_option( 'widget_block' );

		if ( is_array( $widgets ) ) {
			$renamed = map_deep( $widgets, static fn ( $value ) => is_string( $value ) ? self::content( $value ) : $value );

			if ( $renamed !== $widgets ) {
				$found = true;
				update_option( 'widget_block', $renamed );
			}
		}

		// Log in / Log out menu items store their placeholder URL.
		foreach ( self::MENU_ITEMS as $old => $new ) {
			$found = $wpdb->update( $wpdb->postmeta, [ 'meta_value' => $new ], [ 'meta_key' => '_menu_item_url', 'meta_value' => $old ] ) || $found; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		// The gate-bypass capability, where a role editor granted it explicitly.
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( 'wp2_bypass_gates' ) ) {
				$found = true;
				$role->add_cap( 'redeemid_bypass_gates' );
				$role->remove_cap( 'wp2_bypass_gates' );
			}
		}

		// The encrypted Shopify token inside each WordPress session.
		$users = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens' AND meta_value LIKE '%\"wp2_shopify\"%'" ) );

		foreach ( $users as $user_id ) {
			$sessions = get_user_meta( $user_id, 'session_tokens', true );

			if ( ! is_array( $sessions ) ) {
				continue;
			}

			foreach ( $sessions as $verifier => $session ) {
				if ( is_array( $session ) && isset( $session['wp2_shopify'] ) ) {
					$sessions[ $verifier ]['redeemid'] ??= $session['wp2_shopify'];
					unset( $sessions[ $verifier ]['wp2_shopify'] );
				}
			}

			$found = true;
			update_user_meta( $user_id, 'session_tokens', $sessions );
		}

		self::import_config();

		// Scheduled events under old hook names. Activation and boot schedule the new ones.
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( str_starts_with( (string) $hook, 'wp2_shopify_' ) ) {
					wp_unschedule_hook( (string) $hook );
				}
			}
		}

		if ( $found ) {
			// Meta and posts were changed in SQL, under the object cache.
			wp_cache_flush();
			update_option( self::NOTICE_OPTION, time(), false );
		}

		return $found;
	}

	/**
	 * Values saved in Settings are keyed by constant name, and secrets are sealed
	 * under the old key label: rename the keys and seal them again.
	 */
	private static function import_config(): void {
		$stored = get_option( Config::OPTION );

		if ( ! is_array( $stored ) ) {
			return;
		}

		$crypto = null;

		try {
			$crypto = Plugin::crypto();
		} catch ( \InvalidArgumentException ) {
			// No key can be built: keep the ciphertext; Crypto still reads the old label later.
		}

		$renamed = [];

		foreach ( $stored as $name => $value ) {
			$name = str_starts_with( (string) $name, self::CONSTANT_PREFIX ) ? 'REDEEMID_' . substr( (string) $name, strlen( self::CONSTANT_PREFIX ) ) : (string) $name;

			if ( $crypto && is_string( $value ) && Crypto::is_legacy( $value ) ) {
				$plain = $crypto->decrypt( $value );
				$value = null === $plain ? $value : $crypto->encrypt( $plain );
			}

			$renamed[ $name ] = $value;
		}

		if ( $renamed !== $stored ) {
			update_option( Config::OPTION, $renamed );
			Config::reset();
		}
	}

	/**
	 * Old wp-config.php constants still in use because their new name is not defined.
	 *
	 * @param string[] $names New constant names to check.
	 * @return array<string, string> Old name => new name.
	 */
	public static function constants_in_use( array $names ): array {
		$in_use = [];

		foreach ( $names as $name ) {
			$old = self::constant_name( $name );

			if ( null !== $old && ! defined( $name ) && defined( $old ) ) {
				$in_use[ $old ] = $name;
			}
		}

		return $in_use;
	}
}
