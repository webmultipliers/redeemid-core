<?php

namespace RedeemID\Webhooks;

/**
 * What is wrong with the tag chain, from facts Monitor gathers. Pure, so the
 * rules are unit tested; Monitor words each problem and shows it.
 *
 * With Flow-first tagging, WordPress hears about every join and cancel only
 * through webhooks. Shopify removes a subscription after its deliveries fail
 * for a few hours, and queued deliveries wait on WP-Cron or Action Scheduler,
 * so either can stop access changing without anyone noticing.
 */
final class Watchdog {
	public const WARN = 'warn';
	public const FAIL = 'fail';

	/** Queued deliveries older than this mean the queue isn't being worked. */
	public const BACKLOG_AFTER = 600;

	/** A scheduled event this overdue means WP-Cron isn't running. */
	public const CRON_LATE_AFTER = 7200;

	/** Rejected deliveries this recent are reported. */
	public const RECENT = 86400;

	/**
	 * @param array{
	 *     now: int,
	 *     linked: int,
	 *     last_received: int,
	 *     quiet_days: int,
	 *     oldest_queued: int,
	 *     dead_recent: int,
	 *     rejected_at: int,
	 *     registration: array{created?: string[], failed?: array<string, string>, error?: string, existing?: int}|null,
	 *     cron_late: int,
	 *     uninstalled_at?: int,
	 * } $facts cron_late: seconds the plugin's daily event is overdue (0 when on time).
	 * @return array<string, array{state: string, n: int}> By problem code, worst first.
	 */
	public static function problems( array $facts ): array {
		$now      = (int) $facts['now'];
		$problems = [];
		$reg      = $facts['registration'] ?? null;

		if ( is_array( $reg ) && '' !== (string) ( $reg['error'] ?? '' ) ) {
			$problems['check_failed'] = [ 'state' => self::FAIL, 'n' => 0 ];
		}

		if ( is_array( $reg ) && ! empty( $reg['failed'] ) ) {
			$problems['subscribe_failed'] = [ 'state' => self::FAIL, 'n' => count( $reg['failed'] ) ];
		}

		if ( (int) $facts['rejected_at'] > $now - self::RECENT ) {
			$problems['rejected'] = [ 'state' => self::FAIL, 'n' => 0 ];
		}

		if ( (int) $facts['oldest_queued'] > 0 && (int) $facts['oldest_queued'] < $now - self::BACKLOG_AFTER ) {
			$problems['backlog'] = [ 'state' => self::FAIL, 'n' => (int) floor( ( $now - (int) $facts['oldest_queued'] ) / 60 ) ];
		}

		if ( (int) ( $facts['uninstalled_at'] ?? 0 ) > 0 ) {
			$problems['uninstalled'] = [ 'state' => self::FAIL, 'n' => 0 ];
		}

		if ( (int) $facts['cron_late'] > self::CRON_LATE_AFTER ) {
			$problems['cron'] = [ 'state' => self::FAIL, 'n' => (int) floor( (int) $facts['cron_late'] / 3600 ) ];
		}

		// Only re-created ones count: a first registration also "creates" every topic.
		if ( is_array( $reg ) && ! empty( $reg['created'] ) && (int) ( $reg['existing'] ?? 0 ) > 0 ) {
			$problems['resubscribed'] = [ 'state' => self::WARN, 'n' => count( $reg['created'] ) ];
		}

		if ( (int) $facts['dead_recent'] > 0 ) {
			$problems['dead'] = [ 'state' => self::WARN, 'n' => (int) $facts['dead_recent'] ];
		}

		$quiet = (int) $facts['quiet_days'];

		if ( $quiet > 0 && (int) $facts['linked'] > 0 && (int) $facts['last_received'] < $now - $quiet * 86400 ) {
			$problems['quiet'] = [ 'state' => self::WARN, 'n' => (int) $facts['last_received'] ? (int) floor( ( $now - (int) $facts['last_received'] ) / 86400 ) : 0 ];
		}

		return $problems;
	}

	/**
	 * The worst state among the problems: fail, warn, or '' when all is well.
	 */
	public static function worst( array $problems ): string {
		$states = array_column( $problems, 'state' );

		return in_array( self::FAIL, $states, true ) ? self::FAIL : ( $states ? self::WARN : '' );
	}
}
