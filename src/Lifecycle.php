<?php

namespace RedeemID;

use RedeemID\Access\Index;
use RedeemID\Auth\Flow;
use RedeemID\Auth\ShadowUsers;
use RedeemID\Support\EventLog;
use RedeemID\Sync\BulkResync;
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
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'redeemid_prune' );
		Monitor::unschedule();
		BulkResync::unschedule();
		wp_clear_scheduled_hook( Index::REINDEX );
		wp_clear_scheduled_hook( Index::TIMED );
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- deactivation hook only.
	}
}
