<?php

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}

// 1. Delete User Meta (Identity Links)
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '_wp2_shopify_%'" );
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_wp2_last_sync'" );

// 2. Delete Transients (OpenID Config & Session data)
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp2_session_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp2_session_%'" );
delete_transient( 'wp2_shopify_openid_config' );
delete_transient( 'wp2_shopify_customer_api' );

// 3. Delete Plugin Options
delete_option( 'wp2_shopify_domain' );
delete_option( 'wp2_shopify_client_id' );
delete_option( 'wp2_shopify_app_secret' );
delete_option( 'wp2_shopify_redirect_page_id' );
delete_option( 'wp2_shopify_scope' );
delete_option( 'wp2_shopify_redirect_uri' );
delete_option( 'wp2_shopify_authorize_url' );
delete_option( 'wp2_shopify_token_url' );
delete_option( 'wp2_shopify_logout_url' );
delete_option( 'wp2_shopify_resource_url' );
delete_option( 'wp2_shopify_defuse_key' );

// Cleanup legacy options
delete_option( 'wp2_shopify_options' );
delete_option( 'wp2_shopify_api_version' );
delete_option( 'wp2_shopify_settings' );

// 4. DELETE SHADOW USERS (Crucial Update)
$per_page = 100;
$page = 1;

while ( true ) {
	$shadow_users = get_users(
		[
			'role'   => 'shopify_customer',
			'fields' => 'ID',
			'number' => $per_page,
			'paged'  => $page,
		]
	);

	if ( empty( $shadow_users ) ) {
		break;
	}

	foreach ( $shadow_users as $shadow_user_id ) {
		wp_delete_user( $shadow_user_id );
	}

	$page++;
}

delete_role( 'shopify_customer' );
