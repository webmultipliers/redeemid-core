<?php

namespace RedeemID;

use RedeemID\Access\Index;
use RedeemID\Auth\Flow;
use RedeemID\Auth\ShadowUsers;
use RedeemID\Support\EventLog;
use RedeemID\Sync\BulkResync;
use RedeemID\Sync\Reconciler;
use RedeemID\Webhooks\Monitor;
use RedeemID\Webhooks\WpdbInboxStore;

final class Lifecycle {
	public static function activate(): void {
		WpdbInboxStore::install();
		EventLog::install();
		ShadowUsers::ensure_role();
		Flow::add_rewrite();
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- activation hook only.

		if ( ! wp_next_scheduled( 'redeemid_prune' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'redeemid_prune' );
		}

		Monitor::ensure_scheduled();

		Migration::maybe_run();
	}

	/**
	 * Earlier versions re-read every linked customer twice a day. Syncs are now
	 * per customer and on demand, so drop that event and its bookkeeping.
	 */
	public static function retire_reconciliation(): void {
		if ( wp_next_scheduled( Reconciler::RETIRED_HOOK ) ) {
			wp_clear_scheduled_hook( Reconciler::RETIRED_HOOK );

			foreach ( Reconciler::RETIRED_OPTIONS as $option ) {
				delete_option( $option );
			}
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Reconciler::RETIRED_HOOK );
		wp_clear_scheduled_hook( 'redeemid_prune' );
		Monitor::unschedule();
		BulkResync::unschedule();
		wp_clear_scheduled_hook( Index::REINDEX );
		wp_clear_scheduled_hook( Index::TIMED );
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- deactivation hook only.
	}
}
