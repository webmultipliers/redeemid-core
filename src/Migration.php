<?php

namespace WP2_Shopify;

use WP2_Shopify\Support\CustomerId;
use WP2_Shopify\Support\Logger;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Webhooks\WpdbInboxStore;

/**
 * One-time upgrade from the proof of concept (spec 16.2).
 *
 * - Keeps shadow users and their GID meta; backfills the numeric ID.
 * - Discards stored refresh tokens (plaintext in the POC) instead of carrying
 *   them forward. Every POC user signs in again once; the upgrade notice says so.
 * - Copies _wp2_last_sync into the new integer stamp without deleting the old key.
 * - Removes POC options that stored full webhook payloads or a broken key.
 */
final class Migration {
	public const VERSION_OPTION = 'wp2_shopify_migrated';
	public const VERSION        = '2.0.0';

	public static function maybe_run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		if ( get_option( 'wp2_shopify_db_version' ) !== WpdbInboxStore::DB_VERSION ) {
			WpdbInboxStore::install();
		}

		global $wpdb;

		$backfilled = 0;
		$rows       = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", Mirror::GID ) );

		foreach ( $rows as $row ) {
			$id = CustomerId::parse( (string) $row->meta_value );

			if ( $id && ! get_user_meta( (int) $row->user_id, Mirror::NUMERIC, true ) ) {
				update_user_meta( (int) $row->user_id, Mirror::NUMERIC, $id->numeric );
				++$backfilled;
			}

			$legacy_sync = get_user_meta( (int) $row->user_id, '_wp2_last_sync', true );

			if ( $legacy_sync && ! get_user_meta( (int) $row->user_id, Mirror::LAST_SYNC, true ) ) {
				$stamp = is_numeric( $legacy_sync ) ? (int) $legacy_sync : (int) strtotime( (string) $legacy_sync );
				update_user_meta( (int) $row->user_id, Mirror::LAST_SYNC, $stamp );
				update_user_meta( (int) $row->user_id, Mirror::SYNC_SOURCE, 'migration' );
			}
		}

		$discarded = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", '_wp2_shopify_refresh_token' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", '_wp2_shopify_customer_payload' ) );

		foreach ( [ 'wp2_shopify_defuse_key', 'wp2_shopify_app_secret', 'wp2_shopify_customer_group_created', 'wp2_shopify_customer_group_updated', 'wp2_shopify_customer_group_deleted', 'wp2_shopify_openid_config_backup' ] as $option ) {
			delete_option( $option );
		}

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wp2\\_session\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wp2\\_session\\_%'" );

		update_option( self::VERSION_OPTION, self::VERSION, false );

		if ( $rows ) {
			update_option( 'wp2_shopify_upgrade_notice', [ 'backfilled' => $backfilled, 'discarded_sessions' => $discarded ], false );
		}

		Logger::log( 'migration.done', [ 'backfilled' => $backfilled, 'discarded_sessions' => $discarded ] );
	}
}
