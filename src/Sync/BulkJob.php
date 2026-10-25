<?php

namespace RedeemID\Sync;

/**
 * The state of a bulk resync, as plain arrays. Pure, so every transition is
 * unit tested; BulkResync stores it and runs the batches.
 *
 * Statuses: running, paused, done, cancelled, failed. A job walks linked users
 * in user ID order, so the cursor (the last ID done) is all it needs to resume.
 */
final class BulkJob {
	public const RUNNING   = 'running';
	public const PAUSED    = 'paused';
	public const DONE      = 'done';
	public const CANCELLED = 'cancelled';
	public const FAILED    = 'failed';

	/**
	 * @return array<string, mixed>
	 */
	public static function start( int $total, int $by, int $now ): array {
		return [
			'status'      => self::RUNNING,
			'by'          => $by,
			'started_at'  => $now,
			'updated_at'  => $now,
			'finished_at' => 0,
			'next_at'     => $now,
			'total'       => max( 0, $total ),
			'done'        => 0,
			'changed'     => 0,
			'missing'     => 0,
			'cursor'      => 0,
			'failures'    => 0,
			'error'       => '',
			'active'      => 0,
		];
	}

	public static function is_open( array $job ): bool {
		return in_array( $job['status'] ?? '', [ self::RUNNING, self::PAUSED ], true );
	}

	/**
	 * A batch went through.
	 *
	 * @param int[]                              $user_ids The batch, in ID order.
	 * @param array{changed: int, missing: int} $result
	 * @param float                              $active   Seconds spent on it, for the estimate.
	 */
	public static function advance( array $job, array $user_ids, array $result, int $now, float $active = 0.0 ): array {
		$job['done']      += count( $user_ids );
		$job['changed']   += (int) $result['changed'];
		$job['missing']   += (int) $result['missing'];
		$job['cursor']     = $user_ids ? max( $job['cursor'], max( $user_ids ) ) : $job['cursor'];
		$job['failures']   = 0;
		$job['error']      = '';
		$job['updated_at'] = $now;
		$job['active']     = (float) ( $job['active'] ?? 0 ) + $active;
		// Customers linked since the start are picked up too.
		$job['total'] = max( $job['total'], $job['done'] );

		return $job;
	}

	/**
	 * A batch failed. Shopify being busy or unreachable is retried with a
	 * growing wait; anything else, or too many failures in a row, stops the job.
	 *
	 * @param int|null $retry_in Seconds Shopify said to wait (its cost report on a throttled call), instead of the backoff.
	 */
	public static function fail( array $job, string $code, string $message, int $now, ?int $retry_in = null ): array {
		$job['failures']   = (int) $job['failures'] + 1;
		$job['error']      = $message;
		$job['updated_at'] = $now;

		if ( ! Pacer::transient( $code ) || $job['failures'] >= Pacer::MAX_FAILURES ) {
			$job['status']      = self::FAILED;
			$job['finished_at'] = $now;

			return $job;
		}

		$job['next_at'] = $now + ( null !== $retry_in ? max( 1, $retry_in ) : Pacer::backoff( $job['failures'] ) );

		return $job;
	}

	public static function finish( array $job, int $now ): array {
		$job['status']      = self::DONE;
		$job['finished_at'] = $now;
		$job['updated_at']  = $now;
		$job['total']       = $job['done'];

		return $job;
	}

	public static function pause( array $job, int $now ): array {
		if ( self::RUNNING === $job['status'] ) {
			$job['status']     = self::PAUSED;
			$job['updated_at'] = $now;
		}

		return $job;
	}

	/**
	 * Carry on from the cursor: after a pause, or after a failure once the cause is fixed.
	 */
	public static function resume( array $job, int $now ): array {
		if ( in_array( $job['status'], [ self::PAUSED, self::FAILED ], true ) ) {
			$job['status']      = self::RUNNING;
			$job['failures']    = 0;
			$job['error']       = '';
			$job['next_at']     = $now;
			$job['finished_at'] = 0;
			$job['updated_at']  = $now;
		}

		return $job;
	}

	public static function cancel( array $job, int $now ): array {
		if ( self::is_open( $job ) || self::FAILED === $job['status'] ) {
			$job['status']      = self::CANCELLED;
			$job['finished_at'] = $now;
			$job['updated_at']  = $now;
		}

		return $job;
	}

	/**
	 * Seconds left at the pace so far, or null before there is a pace.
	 */
	public static function eta( array $job ): ?int {
		$done   = (int) $job['done'];
		$active = (float) ( $job['active'] ?? 0 );

		if ( $done < 1 || $active <= 0 || self::RUNNING !== $job['status'] ) {
			return null;
		}

		return (int) ceil( max( 0, (int) $job['total'] - $done ) * $active / $done );
	}

	public static function percent( array $job ): int {
		$total = (int) $job['total'];

		return $total > 0 ? (int) min( 100, floor( 100 * (int) $job['done'] / $total ) ) : ( self::DONE === $job['status'] ? 100 : 0 );
	}
}
