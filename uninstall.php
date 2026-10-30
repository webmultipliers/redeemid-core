<?php
/**
 * Uninstall.
 *
 * Non-destructive by default: WordPress users and their content stay. Plugin
 * options, transients, the webhook table and Shopify tokens inside sessions
 * are removed. Deleting shadow users and all linked meta requires an explicit
 * opt-in, set before uninstalling:
 *
 *     define( 'REDEEMID_UNINSTALL_DELETE_DATA', true );
 *
 * Content authored by deleted shadow users is reassigned to the first
 * administrator, not deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$redeemid_options = [
	'redeemid_settings', 'redeemid_config', 'redeemid_db_version',
	'redeemid_discovery_last_good', 'redeemid_discovery_fetched_at', 'redeemid_block_registration', 'redeemid_webhook_rejected', 'redeemid_webhook_store_failed',
	'redeemid_app_uninstalled', 'redeemid_admin_token_error', 'redeemid_admin_token_scopes', 'redeemid_webhook_registration',
	'redeemid_events_db_version', 'redeemid_cart_bridge', 'redeemid_access_levels',
	'redeemid_access_rules', 'redeemid_access_rules_version',
	'redeemid_access_reindex_cursor', 'redeemid_access_index_version', 'redeemid_feature_conditions',
	'redeemid_bulk_resync', 'redeemid_monitor', 'redeemid_stats', 'redeemid_connection_test', 'redeemid_files_probe', 'redeemid_storefront_rules',
];

foreach ( $redeemid_options as $redeemid_option ) {
	delete_option( $redeemid_option );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'redeemid\\_lock\\_%' OR option_name LIKE '\\_transient\\_redeemid\\_%' OR option_name LIKE '\\_transient\\_timeout\\_redeemid\\_%'" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}redeemid_webhooks" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}redeemid_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
// The per-item rule cache and access index are derived data. The access rules editors set on items and terms stay.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_redeemid_access_effective', '_redeemid_access_search', '_redeemid_access_locked', '_redeemid_access_next' )" );
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ( '_redeemid_meter', '_redeemid_cart_key' )" );
// What RedeemID added to privacy requests (source, customer number, Shopify erasure state). The requests themselves are WordPress's and stay.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_redeemid_source', '_redeemid_shopify_request', '_redeemid_customer', '_redeemid_fixture', '_redeemid_redacted_at', '_redeemid_shopify_erasure' )" );

wp_clear_scheduled_hook( 'redeemid_prune' );
wp_clear_scheduled_hook( 'redeemid_monitor' );
wp_clear_scheduled_hook( 'redeemid_bulk_resync' );
wp_clear_scheduled_hook( 'redeemid_access_reindex' );
wp_clear_scheduled_hook( 'redeemid_access_timed' );
// Queued webhook processing carries a row ID per event, so clear every instance.
wp_unschedule_hook( 'redeemid_process_webhook' );

// Collect a stable list of user IDs first, then act on it: paging through
// users while deleting them would skip every other page.
$redeemid_session_users = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens' AND meta_value LIKE '%redeemid%'" ) );

foreach ( $redeemid_session_users as $redeemid_user_id ) {
	$redeemid_sessions = get_user_meta( $redeemid_user_id, 'session_tokens', true );

	if ( is_array( $redeemid_sessions ) ) {
		foreach ( $redeemid_sessions as $redeemid_key => $redeemid_session ) {
			unset( $redeemid_sessions[ $redeemid_key ]['redeemid'] );
		}
		update_user_meta( $redeemid_user_id, 'session_tokens', $redeemid_sessions );
	}
}

$redeemid_delete_data = defined( 'REDEEMID_UNINSTALL_DELETE_DATA' ) && REDEEMID_UNINSTALL_DELETE_DATA;

if ( true !== $redeemid_delete_data ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/user.php';

$redeemid_admins   = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ] );
$redeemid_reassign = $redeemid_admins ? (int) $redeemid_admins[0] : null;
$redeemid_shadows  = array_map( 'intval', get_users( [ 'role' => 'shopify_customer', 'fields' => 'ID', 'number' => -1 ] ) );

foreach ( $redeemid_shadows as $redeemid_user_id ) {
	wp_delete_user( $redeemid_user_id, $redeemid_reassign );
}

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_redeemid\\_%'" );

remove_role( 'shopify_customer' );
