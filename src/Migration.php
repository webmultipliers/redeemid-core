<?php

namespace RedeemID;

use RedeemID\Support\CustomerId;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;
use RedeemID\Webhooks\WpdbInboxStore;

/**
 * One-time upgrade from earlier builds.
 *
 * First Legacy::import() moves data stored under the plugin's old name (WP2
 * Shopify) to the RedeemID names. Then, on a site that never ran it, the
 * upgrade from the proof of concept:
 *
 * - Keeps shadow users and their GID meta; backfills the numeric ID.
 * - Discards stored refresh tokens (plaintext in the POC) instead of carrying
 *   them forward. Every POC user signs in again once; the upgrade notice says so.
 * - Copies the POC's last-sync stamp into the new integer stamp without deleting the old key.
 * - Removes POC options that stored full webhook payloads or a broken key.
 */
final class Migration {
	public const VERSION_OPTION = 'redeemid_migrated';
	public const VERSION        = '2.0.0-redeemid';

	/** The marker a WP2 Shopify 2.0 site carries once it ran the proof-of-concept upgrade. */
	private const POC_DONE = '2.0.0';

	public static function maybe_run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		Legacy::import();

		if ( get_option( self::VERSION_OPTION ) !== self::POC_DONE ) {
			self::from_proof_of_concept();
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	private static function from_proof_of_concept(): void {
		if ( get_option( 'redeemid_db_version' ) !== WpdbInboxStore::DB_VERSION ) {
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

			$legacy_sync = get_user_meta( (int) $row->user_id, Legacy::POC_LAST_SYNC, true );

			if ( $legacy_sync && ! get_user_meta( (int) $row->user_id, Mirror::LAST_SYNC, true ) ) {
				$stamp = is_numeric( $legacy_sync ) ? (int) $legacy_sync : (int) strtotime( (string) $legacy_sync );
				update_user_meta( (int) $row->user_id, Mirror::LAST_SYNC, $stamp );
				update_user_meta( (int) $row->user_id, Mirror::SYNC_SOURCE, 'migration' );
			}
		}

		$discarded = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", '_redeemid_refresh_token' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", '_redeemid_customer_payload' ) );

		foreach ( [ 'redeemid_defuse_key', 'redeemid_app_secret', 'redeemid_customer_group_created', 'redeemid_customer_group_updated', 'redeemid_customer_group_deleted', 'redeemid_openid_config_backup' ] as $option ) {
			delete_option( $option );
		}

		// The POC's session transients went with the other old-name transients in Legacy::import().

		if ( $rows ) {
			update_option( 'redeemid_upgrade_notice', [ 'backfilled' => $backfilled, 'discarded_sessions' => $discarded ], false );
		}

		Logger::log( 'migration.done', [ 'backfilled' => $backfilled, 'discarded_sessions' => $discarded ] );
	}
}
