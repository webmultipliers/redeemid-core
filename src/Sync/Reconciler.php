<?php

namespace WP2_Shopify\Sync;

use WP2_Shopify\Shopify\Admin;
use WP2_Shopify\Support\Logger;

/**
 * On-demand resync of one linked customer's tags and status from the Admin
 * API: a member's own refresh, a staff resync on Shopify → Customers, a tag
 * webhook, or the post-checkout check. There is no scheduled sweep; webhooks
 * keep the mirror current, and staff can start a paced bulk resync
 * (BulkResync) when they need everyone re-read.
 */
final class Reconciler {
	/** Retired scheduled sweep: its cron hook and options are cleared on upgrade. */
	public const RETIRED_HOOK    = 'wp2_shopify_reconcile';
	public const RETIRED_OPTIONS = [ 'wp2_shopify_reconcile_stats', 'wp2_shopify_reconcile_cursor' ];
	private const BATCH          = 50;

	/**
	 * Reconcile one user now ("Sync now", post-checkout sync).
	 */
	public static function sync_user( int $user_id, string $source ): true|\WP_Error {
		$result = self::sync_users( [ $user_id ], $source );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Reconcile a set of users now, in Admin API batches of 50.
	 *
	 * @param int[] $user_ids
	 * @return array{changed: int, missing: int}|\WP_Error
	 */
	public static function sync_users( array $user_ids, string $source ): array|\WP_Error {
		$result = [ 'changed' => 0, 'missing' => 0 ];

		foreach ( array_chunk( array_map( 'intval', $user_ids ), self::BATCH ) as $chunk ) {
			$batch = self::reconcile_users( $chunk, $source );

			if ( is_wp_error( $batch ) ) {
				foreach ( $chunk as $user_id ) {
					Mirror::mark_sync_failed( $user_id );
				}

				Logger::log( 'sync.failed', [ 'source' => $source, 'users' => count( $chunk ), 'error' => $batch->get_error_code() ] );

				return $batch;
			}

			$result['changed'] += $batch['changed'];
			$result['missing'] += $batch['missing'];
		}

		Logger::log( 'sync.done', [ 'source' => $source, 'users' => count( $user_ids ), 'changed' => $result['changed'], 'missing' => $result['missing'], 'status' => 'ok' ] + ( 1 === count( $user_ids ) ? [ 'user' => (int) reset( $user_ids ) ] : [] ) );

		return $result;
	}

	/**
	 * One Admin API batch of up to 50 users, for BulkResync. A failure leaves
	 * the users' sync state alone, because the batch is retried.
	 *
	 * @param int[] $user_ids
	 * @return array{changed: int, missing: int}|\WP_Error
	 */
	public static function batch( array $user_ids, string $source ): array|\WP_Error {
		return self::reconcile_users( array_slice( array_map( 'intval', $user_ids ), 0, self::BATCH ), $source );
	}

	/**
	 * @param int[] $user_ids
	 * @return array{changed: int, missing: int}|\WP_Error
	 */
	private static function reconcile_users( array $user_ids, string $source = 'reconciliation' ): array|\WP_Error {
		$ids = [];

		foreach ( $user_ids as $user_id ) {
			$id = Mirror::customer_id( $user_id );

			if ( $id && Mirror::is_fixture( $user_id ) ) {
				// Not a real customer: keep its seeded state, just mark it fresh.
				Mirror::stamp( $user_id, $source );
				continue;
			}

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
			Mirror::set_dates( $user_id, [ 'since' => $remote[ $id->numeric ]['since'] ?? null, 'renews' => $remote[ $id->numeric ]['renews'] ?? null ] );
			Mirror::stamp( $user_id, $source );
		}

		return [ 'changed' => $changed, 'missing' => $missing ];
	}
}
