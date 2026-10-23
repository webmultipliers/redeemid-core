<?php

namespace WP2_Shopify\Sync;

use WP2_Shopify\Shopify\Admin;
use WP2_Shopify\Support\Logger;

/**
 * Scheduled reconciliation (spec 6.4). Webhooks are not a reliable entitlement
 * source on their own, so linked customers' tags and status are re-read from
 * Shopify on a schedule, on demand, and after checkout.
 *
 * This needs the Admin API: the Customer Account API can only read the
 * customer whose session token we hold, which rules out a background sweep.
 */
final class Reconciler {
	public const HOOK      = 'wp2_shopify_reconcile';
	public const STATS     = 'wp2_shopify_reconcile_stats';
	private const BATCH    = 50;
	private const CURSOR   = 'wp2_shopify_reconcile_cursor';
	private const PER_RUN  = 500;

	/**
	 * One scheduled pass. Walks linked users in ID order across runs.
	 */
	public static function run(): array {
		$stats = [ 'started' => time(), 'checked' => 0, 'changed' => 0, 'missing' => 0, 'error' => '' ];

		if ( ! Admin::available() ) {
			$stats['error'] = 'admin_token_missing';
			update_option( self::STATS, $stats + [ 'finished' => time() ], false );

			return $stats;
		}

		$after = (int) get_option( self::CURSOR, 0 );

		while ( $stats['checked'] < self::PER_RUN ) {
			$users = self::linked_users_after( $after, self::BATCH );

			if ( ! $users ) {
				$after = 0;
				break;
			}

			$result = self::reconcile_users( $users );

			if ( is_wp_error( $result ) ) {
				$stats['error'] = $result->get_error_code();
				foreach ( $users as $user_id ) {
					Mirror::mark_sync_failed( $user_id );
				}
				break;
			}

			$stats['checked'] += count( $users );
			$stats['changed'] += $result['changed'];
			$stats['missing'] += $result['missing'];
			$after             = (int) end( $users );
		}

		update_option( self::CURSOR, $after, false );
		update_option( self::STATS, $stats + [ 'finished' => time() ], false );
		Logger::log( 'reconcile.run', $stats );

		return $stats;
	}

	/**
	 * Reconcile one user now ("Sync now", post-checkout sync).
	 */
	public static function sync_user( int $user_id, string $source ): true|\WP_Error {
		$result = self::reconcile_users( [ $user_id ], $source );

		if ( is_wp_error( $result ) ) {
			Mirror::mark_sync_failed( $user_id );

			return $result;
		}

		return true;
	}

	/**
	 * @param int[] $user_ids
	 * @return array{changed: int, missing: int}|\WP_Error
	 */
	private static function reconcile_users( array $user_ids, string $source = 'reconciliation' ): array|\WP_Error {
		$ids = [];

		foreach ( $user_ids as $user_id ) {
			$id = Mirror::customer_id( $user_id );

			if ( $id ) {
				$ids[ $user_id ] = $id;
			}
		}

		if ( ! $ids ) {
			return [ 'changed' => 0, 'missing' => 0 ];
		}

		$remote = Admin::customers( array_values( $ids ) );

		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		$changed = 0;
		$missing = 0;

		foreach ( $ids as $user_id => $id ) {
			if ( ! isset( $remote[ $id->numeric ] ) ) {
				// Deleted in Shopify, or never existed: fail closed by disabling the mirror.
				++$missing;
				Mirror::set_status( $user_id, 'disabled' );
				Logger::log( 'reconcile.customer_missing', [ 'customer' => $id->numeric, 'user' => $user_id ] );
				continue;
			}

			$before = Mirror::tags( $user_id );
			$after  = $remote[ $id->numeric ]['tags'];
			$status = 'DISABLED' === strtoupper( $remote[ $id->numeric ]['state'] ) ? 'disabled' : 'enabled';

			sort( $before );
			sort( $after );

			if ( $before !== $after || $status !== get_user_meta( $user_id, Mirror::STATUS, true ) ) {
				++$changed;
			}

			Mirror::set_tags( $user_id, $after );
			Mirror::set_status( $user_id, $status );
			Mirror::stamp( $user_id, $source );
		}

		return [ 'changed' => $changed, 'missing' => $missing ];
	}

	/**
	 * @return int[]
	 */
	private static function linked_users_after( int $after, int $limit ): array {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC LIMIT %d",
					Mirror::NUMERIC,
					$after,
					$limit
				)
			)
		);
	}
}
