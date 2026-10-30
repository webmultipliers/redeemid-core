<?php

namespace RedeemID;

use RedeemID\Access\Index;
use RedeemID\Admin\Wizard;
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

		// A site that isn't connected yet opens the setup wizard on the next admin page.
		if ( ! Config::has( 'REDEEMID_STORE_DOMAIN' ) ) {
			set_transient( Wizard::REDIRECT, 1, MINUTE_IN_SECONDS );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'redeemid_prune' );
		Monitor::unschedule();
		BulkResync::unschedule();
		wp_clear_scheduled_hook( Index::REINDEX );
		wp_clear_scheduled_hook( Index::TIMED );
		// Drop the storefront pages' rules with the plugin.
		remove_filter( 'rewrite_rules_array', [ \RedeemID\Commerce\Pages::class, 'rules' ], 5 );
		remove_filter( 'rewrite_rules_array', [ \RedeemID\Commerce\Pages::class, 'rules' ], 20 );
		delete_option( 'redeemid_storefront_rules' );
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- deactivation hook only.
	}
}
