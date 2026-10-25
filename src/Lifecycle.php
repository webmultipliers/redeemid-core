<?php

namespace WP2_Shopify;

use WP2_Shopify\Access\Index;
use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Auth\ShadowUsers;
use WP2_Shopify\Support\EventLog;
use WP2_Shopify\Sync\BulkResync;
use WP2_Shopify\Sync\Reconciler;
use WP2_Shopify\Webhooks\Monitor;
use WP2_Shopify\Webhooks\WpdbInboxStore;

final class Lifecycle {
	public static function activate(): void {
		WpdbInboxStore::install();
		EventLog::install();
		ShadowUsers::ensure_role();
		Flow::add_rewrite();
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- activation hook only.

		if ( ! wp_next_scheduled( 'wp2_shopify_prune' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'wp2_shopify_prune' );
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
		wp_clear_scheduled_hook( 'wp2_shopify_prune' );
		Monitor::unschedule();
		BulkResync::unschedule();
		wp_clear_scheduled_hook( Index::REINDEX );
		wp_clear_scheduled_hook( Index::TIMED );
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- deactivation hook only.
	}
}
