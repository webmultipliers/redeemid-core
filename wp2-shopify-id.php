<?php
/**
 * Plugin Name: WP2 Shopify Identity
 * Plugin URI:  https://github.com/webmultipliers/wp2-shopify-id
 * Description: Connects WordPress Users to Shopify Customer Accounts using the Customer Account API (PKCE).
 * Version:     1.0.0
 * Author:      Web Multipliers
 * Text Domain: wp2-shopify-id
 */

defined( 'ABSPATH' ) || exit;

// 1. Constants
define( 'WP2_SHOPIFY_ID_VERSION', '1.0.0' );
define( 'WP2_SHOPIFY_ID_PLUGIN_FILE', __FILE__ );
define( 'WP2_SHOPIFY_ID_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP2_SHOPIFY_ID_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// API & Security
if ( ! defined( 'WP2_SHOPIFY_API_VERSION' ) )
	define( 'WP2_SHOPIFY_API_VERSION', '2025-10' );

if ( ! defined( 'AUTH_KEY' ) || '' === AUTH_KEY ) {
	$auth_key_missing_message = __( 'WP2 Shopify Identity requires AUTH_KEY to be defined in wp-config.php. Please add the constant before activating or continuing to use the identity tools.', 'wp2-shopify-id' );

	add_action(
		'admin_notices',
		function () use ($auth_key_missing_message) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $auth_key_missing_message )
			);
		}
	);

	add_shortcode( 'wp2_shopify_identity', function () use ($auth_key_missing_message) {
		return sprintf(
			'<div class="wp2-shopify-error notice notice-error" role="alert"><p>%s</p></div>',
			esc_html( $auth_key_missing_message )
		);
	} );

	return;
}

// Environment Overrides (Optional)
if ( ! defined( 'WP2_SHOPIFY_CLIENT_ID' ) )
	define( 'WP2_SHOPIFY_CLIENT_ID', '' );
if ( ! defined( 'WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN' ) )
	define( 'WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN', '' );
if ( ! defined( 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN' ) )
	define( 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN', '' ); // Used as Client Secret
if ( ! defined( 'WP2_SHOPIFY_STORE_DOMAIN' ) )
	define( 'WP2_SHOPIFY_STORE_DOMAIN', '' );
if ( ! defined( 'WP2_SHOPIFY_OAUTH_AUTHORIZE_URL' ) )
	define( 'WP2_SHOPIFY_OAUTH_AUTHORIZE_URL', '' );
if ( ! defined( 'WP2_SHOPIFY_OAUTH_TOKEN_URL' ) )
	define( 'WP2_SHOPIFY_OAUTH_TOKEN_URL', '' );
if ( ! defined( 'WP2_SHOPIFY_OAUTH_LOGOUT_URL' ) )
	define( 'WP2_SHOPIFY_OAUTH_LOGOUT_URL', '' );
if ( ! defined( 'WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET' ) )
	define( 'WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET', '' );

if ( ! defined( 'WP2_SHOPIFY_FORCE_LOAD_ASSETS' ) ) {
	define( 'WP2_SHOPIFY_FORCE_LOAD_ASSETS', false );
}

// Cookie Domain Configuration
if ( ! defined( 'WP2_SHOPIFY_COOKIE_DOMAIN' ) ) {
	$cookie_domain = $_SERVER['HTTP_HOST'] ?? parse_url( home_url(), PHP_URL_HOST );
	define( 'WP2_SHOPIFY_COOKIE_DOMAIN', $cookie_domain );
}

// Encryption Key Configuration
if ( ! defined( 'WP2_SHOPIFY_KEY' ) ) {
	$stored_key = get_option( 'wp2_shopify_defuse_key' );

	if ( ! $stored_key ) {
		$new_key = base64_encode( random_bytes( 32 ) );
		update_option( 'wp2_shopify_defuse_key', $new_key );
		$stored_key = $new_key;
	}

	define( 'WP2_SHOPIFY_KEY', $stored_key );
}

// 2. Autoloader
$vendor_autoload = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $vendor_autoload ) ) {
	$message = __( 'WP2 Shopify Identity is missing its Composer dependencies. Run <code>composer install</code> from the plugin directory before activating.', 'wp2-shopify-id' );

	add_action(
		'admin_notices',
		function () use ($message) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
		}
	);

	return;
}

require_once $vendor_autoload;

spl_autoload_register( function ( $class ) {
	$prefix   = 'WP2_Shopify\\';
	$base_dir = __DIR__ . '/src/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, strlen( $prefix ) );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

// 3. Boot
add_action( 'plugins_loaded', [ \WP2_Shopify\Init::class, 'register' ] );

register_activation_hook( __FILE__, function () {
	if ( class_exists( \WP2_Shopify\Init::class) ) {
		\WP2_Shopify\Init::register_shopify_auth_callback();
	}

	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );


if ( class_exists( Blockstudio\Build::class) ) {
	add_action( 'init', function () {
		Blockstudio\Build::init( [
			'dir' => trailingslashit( WP2_SHOPIFY_ID_PLUGIN_DIR . 'blocks' ),
		] );
	} );
}