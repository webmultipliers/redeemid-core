<?php

namespace RedeemID\Sync;

use RedeemID\Shopify\Admin;
use RedeemID\Shopify\Http;
use RedeemID\Support\Lock;
use RedeemID\Support\Logger;

/**
 * Re-read every linked customer from Shopify, when staff ask for it. Nothing
 * starts this on a schedule.
 *
 * It runs in the background, 50 customers per Admin API call, in user ID
 * order, so it can pause, resume and survive a failed request. Pacer keeps it
 * to the spare half of the store's API budget, waits out Shopify being busy
 * with a growing backoff, and stops after repeated failures. WP-Cron (or
 * Action Scheduler) carries it; while staff have RedeemID → Customers open, the
 * page also nudges it along, so it finishes even where WP-Cron is unreliable.
 */
final class BulkResync {
	public const OPTION = 'redeemid_bulk_resync';
	public const HOOK   = 'redeemid_bulk_resync';
	public const SOURCE = 'bulk';

	private const LOCK  = 'bulk_resync';
	private const BATCH = 50;

	/** Most a cron run spends before handing over to the next one, in seconds. */
	private const BUDGET = 20;

	/** A nudge from the admin page runs only when the next run is this overdue. */
	private const OVERDUE = 15;

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	/**
	 * @return array<string, mixed>|null The current or last job.
	 */
	public static function job(): ?array {
		$job = get_option( self::OPTION );

		return is_array( $job ) && isset( $job['status'] ) ? $job : null;
	}

	private static function save( array $job ): void {
		update_option( self::OPTION, $job, false );
	}

	public static function start( int $by ): true|\WP_Error {
		$job = self::job();

		if ( $job && BulkJob::is_open( $job ) ) {
			return new \WP_Error( 'bulk_running', 'A resync is already in progress.' );
		}

		if ( ! Admin::available() ) {
			return new \WP_Error( 'shopify_admin_unconfigured', 'No Admin API credentials are defined.' );
		}

		$job = BulkJob::start( self::linked_count(), $by, time() );
		self::save( $job );
		Logger::log( 'bulk_resync.started', [ 'users' => $job['total'], 'user' => $by ] );
		self::schedule( 0 );

		return true;
	}

	public static function pause(): void {
		self::change( 'pause' );
	}

	public static function resume(): void {
		if ( self::change( 'resume' ) ) {
			self::schedule( 0 );
		}
	}

	public static function cancel(): void {
		if ( self::change( 'cancel' ) ) {
			self::unschedule();
		}
	}

	private static function change( string $to ): bool {
		$job = self::job();

		if ( ! $job ) {
			return false;
		}

		$after = BulkJob::$to( $job, time() );

		if ( $after['status'] === $job['status'] ) {
			return false;
		}

		self::save( $after );
		Logger::log( 'bulk_resync.' . [ 'pause' => 'paused', 'resume' => 'resumed', 'cancel' => 'cancelled' ][ $to ], [ 'done' => $after['done'], 'total' => $after['total'] ] );

		return true;
	}

	/**
	 * The cron run: work through batches until the budget is spent, then
	 * schedule the next run at whatever pace Shopify allows.
	 */
	public static function run( int $budget = self::BUDGET ): void {
		if ( ! Lock::acquire( self::LOCK, $budget + 30 ) ) {
			return;
		}

		try {
			self::work( $budget );
		} finally {
			Lock::release( self::LOCK );
		}
	}

	/**
	 * The admin page's poll: report progress, and run a short batch if the
	 * next cron run is overdue.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function nudge(): ?array {
		$job = self::job();

		if ( $job && BulkJob::RUNNING === $job['status'] && (int) $job['next_at'] < time() - self::OVERDUE ) {
			self::run( 8 );
			$job = self::job();
		}

		return $job;
	}

	private static function work( int $budget ): void {
		$started = microtime( true );
		$mark    = $started;

		while ( true ) {
			// Re-read every time, so a pause or cancel from the admin takes effect at the next batch.
			wp_cache_delete( self::OPTION, 'options' );
			$job = self::job();

			if ( ! $job || BulkJob::RUNNING !== $job['status'] ) {
				return;
			}

			if ( (int) $job['next_at'] > time() ) {
				self::schedule( (int) $job['next_at'] - time() );
				return;
			}

			$users = self::next_users( (int) $job['cursor'] );

			if ( ! $users ) {
				$job = BulkJob::finish( $job, time() );
				self::save( $job );
				Logger::log( 'sync.done', [ 'source' => self::SOURCE, 'users' => $job['done'], 'changed' => $job['changed'], 'missing' => $job['missing'], 'status' => 'ok' ] );
				Logger::log( 'bulk_resync.done', [ 'done' => $job['done'], 'changed' => $job['changed'], 'missing' => $job['missing'] ] );
				return;
			}

			$result = Reconciler::batch( $users, self::SOURCE );
			$cost   = Http::last_cost();

			if ( is_wp_error( $result ) ) {
				// Throttled calls come with a cost report saying how long the bucket needs.
				$retry = 'shopify_throttled' === $result->get_error_code() && $cost ? (int) ceil( Pacer::wait( $cost ) ) : null;
				$job   = BulkJob::fail( $job, $result->get_error_code(), $result->get_error_message(), time(), $retry );
				self::save( $job );
				Logger::log( 'bulk_resync.batch_failed', [ 'error' => $result->get_error_code(), 'failures' => $job['failures'], 'status' => $job['status'] ] );

				if ( BulkJob::RUNNING === $job['status'] ) {
					self::schedule( (int) $job['next_at'] - time() );
				}

				return;
			}

			$now  = microtime( true );
			$job  = BulkJob::advance( $job, $users, $result, time(), $now - $mark );
			$mark = $now;
			self::save( $job );

			$wait = Pacer::wait( $cost );

			if ( $now - $started + $wait >= $budget ) {
				self::schedule( (int) ceil( max( 1.0, $wait ) ) );
				return;
			}

			if ( $wait > 0 ) {
				usleep( (int) ( $wait * 1000000 ) );
			}
		}
	}

	/**
	 * @return int[] The next linked users after $cursor, in ID order.
	 */
	private static function next_users( int $cursor ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyset paging over one meta key; WP_User_Query cannot page by ID.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC LIMIT %d", Mirror::NUMERIC, $cursor, self::BATCH ) ) );
	}

	public static function linked_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s", Mirror::NUMERIC ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin-only count.
	}

	private static function schedule( int $delay ): void {
		self::unschedule();
		$at = time() + max( 0, $delay );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $at, self::HOOK, [], 'redeemid' );
			return;
		}

		wp_schedule_single_event( $at, self::HOOK );
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], 'redeemid' );
		}

		wp_clear_scheduled_hook( self::HOOK );
	}
}
