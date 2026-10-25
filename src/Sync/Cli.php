<?php

namespace RedeemID\Sync;

/**
 * Resyncs from the command line. Like the admin, nothing here runs by itself.
 *
 *     wp redeemid resync user <id>...
 *     wp redeemid resync all [--foreground]
 *     wp redeemid resync status|pause|resume|cancel
 */
final class Cli {
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( \WP_CLI::class ) ) {
			\WP_CLI::add_command( 'redeemid resync', self::class );
		}
	}

	/**
	 * Re-read some linked customers from Shopify now.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : WordPress user IDs.
	 *
	 * @param string[] $args
	 */
	public function user( array $args ): void {
		$result = Reconciler::sync_users( array_map( 'intval', $args ), 'cli' );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( '%d re-read, %d changed, %d no longer in Shopify.', count( $args ), $result['changed'], $result['missing'] ) );
	}

	/**
	 * Start a paced resync of every linked customer.
	 *
	 * Runs in the background (WP-Cron or Action Scheduler) unless
	 * --foreground is given, in which case this command waits it out,
	 * at the same pace, and can be stopped with Ctrl-C and resumed later.
	 *
	 * ## OPTIONS
	 *
	 * [--foreground]
	 * : Do the work in this process.
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function all( array $args, array $assoc ): void {
		$job = BulkResync::job();

		if ( ! $job || ! BulkJob::is_open( $job ) ) {
			$started = BulkResync::start( 0 );

			if ( is_wp_error( $started ) ) {
				\WP_CLI::error( $started->get_error_message() );
			}
		} elseif ( BulkJob::PAUSED === $job['status'] ) {
			BulkResync::resume();
		}

		if ( empty( $assoc['foreground'] ) ) {
			\WP_CLI::success( 'Resync started in the background. Follow it with: wp redeemid resync status' );
			return;
		}

		BulkResync::unschedule();

		while ( true ) {
			$job = BulkResync::job();

			if ( ! $job || BulkJob::RUNNING !== $job['status'] ) {
				break;
			}

			$wait = (int) $job['next_at'] - time();

			if ( $wait > 0 ) {
				sleep( $wait );
			}

			BulkResync::run( 60 );
			BulkResync::unschedule();
			$this->status();
			// Another process may hold the lock; don't spin.
			sleep( 1 );
		}

		$this->status();
	}

	/**
	 * Show the current or last bulk resync.
	 */
	public function status(): void {
		$job = BulkResync::job();

		if ( ! $job ) {
			\WP_CLI::line( 'No resync has run.' );
			return;
		}

		\WP_CLI::line( sprintf( '%s: %d/%d (%d%%), %d changed, %d missing%s', $job['status'], $job['done'], $job['total'], BulkJob::percent( $job ), $job['changed'], $job['missing'], '' !== $job['error'] ? ', last error: ' . $job['error'] : '' ) );
	}

	/**
	 * Pause the bulk resync after the batch in progress.
	 */
	public function pause(): void {
		BulkResync::pause();
		$this->status();
	}

	/**
	 * Resume a paused or stopped bulk resync from where it was.
	 */
	public function resume(): void {
		BulkResync::resume();
		$this->status();
	}

	/**
	 * Cancel the bulk resync. Customers already re-read keep their data.
	 */
	public function cancel(): void {
		BulkResync::cancel();
		$this->status();
	}
}
