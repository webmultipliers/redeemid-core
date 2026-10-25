<?php
/**
 * Uninstall.
 *
 * Non-destructive by default: WordPress users and their content stay. Plugin
 * options, transients, the webhook table and Shopify tokens inside sessions
 * are removed. Deleting shadow users and all linked meta requires an explicit
 * opt-in, set before uninstalling:
 *
 *     define( 'WP2_SHOPIFY_UNINSTALL_DELETE_DATA', true );
 *
 * Content authored by deleted shadow users is reassigned to the first
 * administrator, not deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wp2_options = [
	'wp2_shopify_settings', 'wp2_shopify_config', 'wp2_shopify_db_version', 'wp2_shopify_migrated', 'wp2_shopify_upgrade_notice',
	'wp2_shopify_discovery_last_good', 'wp2_shopify_discovery_fetched_at', 'wp2_shopify_reconcile_stats',
	'wp2_shopify_reconcile_cursor', 'wp2_shopify_block_registration', 'wp2_shopify_webhook_rejected',
	'wp2_shopify_app_uninstalled', 'wp2_shopify_admin_token_error', 'wp2_shopify_admin_token_scopes', 'wp2_shopify_webhook_registration',
	'wp2_shopify_events_db_version', 'wp2_shopify_cart_bridge', 'wp2_shopify_access_levels',
	'wp2_shopify_access_rules', 'wp2_shopify_access_rules_version',
	'wp2_shopify_access_reindex_cursor', 'wp2_shopify_access_index_version', 'wp2_shopify_feature_conditions',
	'wp2_shopify_bulk_resync', 'wp2_shopify_monitor', 'wp2_shopify_stats', 'wp2_shopify_connection_test',
];

foreach ( $wp2_options as $wp2_option ) {
	delete_option( $wp2_option );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp2\\_shopify\\_lock\\_%' OR option_name LIKE '\\_transient\\_wp2s\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wp2s\\_%' OR option_name LIKE '\\_transient\\_wp2\\_shopify\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wp2\\_shopify\\_%'" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp2_shopify_webhooks" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp2_shopify_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
// The per-item rule cache and access index are derived data. The access rules editors set on items and terms stay.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_wp2_access_effective', '_wp2_access_search', '_wp2_access_locked', '_wp2_access_next' )" );

wp_clear_scheduled_hook( 'wp2_shopify_reconcile' );
wp_clear_scheduled_hook( 'wp2_shopify_prune' );
wp_clear_scheduled_hook( 'wp2_shopify_monitor' );
wp_clear_scheduled_hook( 'wp2_shopify_bulk_resync' );
wp_clear_scheduled_hook( 'wp2_shopify_access_reindex' );
wp_clear_scheduled_hook( 'wp2_shopify_access_timed' );
// Queued webhook processing carries a row ID per event, so clear every instance.
wp_unschedule_hook( 'wp2_shopify_process_webhook' );

// Collect a stable list of user IDs first, then act on it. (The POC paged
// through users while deleting them, which skipped every other page.)
$wp2_session_users = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens' AND meta_value LIKE '%wp2_shopify%'" ) );

foreach ( $wp2_session_users as $wp2_user_id ) {
	$wp2_sessions = get_user_meta( $wp2_user_id, 'session_tokens', true );

	if ( is_array( $wp2_sessions ) ) {
		foreach ( $wp2_sessions as $wp2_key => $wp2_session ) {
			unset( $wp2_sessions[ $wp2_key ]['wp2_shopify'] );
		}
		update_user_meta( $wp2_user_id, 'session_tokens', $wp2_sessions );
	}
}

if ( ! defined( 'WP2_SHOPIFY_UNINSTALL_DELETE_DATA' ) || true !== WP2_SHOPIFY_UNINSTALL_DELETE_DATA ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$wp2_admins   = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ] );
$wp2_reassign = $wp2_admins ? (int) $wp2_admins[0] : null;
$wp2_shadows  = array_map( 'intval', get_users( [ 'role' => 'shopify_customer', 'fields' => 'ID', 'number' => -1 ] ) );

foreach ( $wp2_shadows as $wp2_user_id ) {
	wp_delete_user( $wp2_user_id, $wp2_reassign );
}

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_wp2\\_shopify\\_%' OR meta_key = '_wp2_last_sync'" );

remove_role( 'shopify_customer' );
