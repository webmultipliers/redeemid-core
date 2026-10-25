<?php
/**
 * Plugin Name:       WP2 Shopify
 * Plugin URI:        https://github.com/webmultipliers/shopify-for-wp
 * Description:       Shopify customer account sign-in, memberships from Shopify purchases, and server-side content gating for WordPress, built on Blockstudio 7.
 * Version:           2.0.0-alpha.1
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Web Multipliers
 * License:           GPL-2.0-or-later
 * Text Domain:       wp2-shopify
 * Domain Path:       /languages
 *
 * Shopify API version: 2026-10 (see WP2_Shopify\Config::API_VERSION).
 */

defined( 'ABSPATH' ) || exit;

define( 'WP2_SHOPIFY_VERSION', '2.0.0-alpha.1' );
define( 'WP2_SHOPIFY_FILE', __FILE__ );
define( 'WP2_SHOPIFY_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP2_SHOPIFY_URL', plugin_dir_url( __FILE__ ) );

// Platform floors are checked before anything that needs PHP 8.2 syntax is loaded.
require_once __DIR__ . '/src/Requirements.php';

$wp2_shopify_problems = \WP2_Shopify\Requirements::problems();

if ( $wp2_shopify_problems ) {
	\WP2_Shopify\Requirements::report( $wp2_shopify_problems );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';

register_activation_hook( __FILE__, [ \WP2_Shopify\Lifecycle::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \WP2_Shopify\Lifecycle::class, 'deactivate' ] );

\WP2_Shopify\Plugin::boot();
