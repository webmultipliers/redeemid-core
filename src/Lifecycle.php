<?php

namespace WP2_Shopify;

use WP2_Shopify\Access\Index;
use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Auth\ShadowUsers;
use WP2_Shopify\Support\EventLog;
use WP2_Shopify\Sync\Reconciler;
use WP2_Shopify\Webhooks\WpdbInboxStore;

final class Lifecycle {
	public static function activate(): void {
		WpdbInboxStore::install();
		EventLog::install();
		ShadowUsers::ensure_role();
		Flow::add_rewrite();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( Reconciler::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', Reconciler::HOOK );
		}

		if ( ! wp_next_scheduled( 'wp2_shopify_prune' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'wp2_shopify_prune' );
		}

		Migration::maybe_run();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Reconciler::HOOK );
		wp_clear_scheduled_hook( 'wp2_shopify_prune' );
		wp_clear_scheduled_hook( Index::REINDEX );
		wp_clear_scheduled_hook( Index::TIMED );
		flush_rewrite_rules();
	}
}
