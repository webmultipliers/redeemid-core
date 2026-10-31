<?php
/**
 * Plugin Name:       RedeemID
 * Plugin URI:        https://github.com/webmultipliers/redeemid-core
 * Description:       Shopify customer account sign-in, memberships from Shopify purchases, and server-side content gating for WordPress, built on Blockstudio 7.
 * Version:           0.0.5
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Vinny S. Green, LLC
 * License:           GPL-2.0-or-later
 * Text Domain:       redeemid
 * Domain Path:       /languages
 *
 * Shopify API version: 2026-10 (see RedeemID\Config::API_VERSION).
 */

defined( 'ABSPATH' ) || exit;

define( 'REDEEMID_VERSION', '0.0.5' );
// The developer API's version (RedeemID\Api\Contract::VERSION, docs/developer-api.md).
define( 'REDEEMID_API_VERSION', '1.0.0' );
define( 'REDEEMID_FILE', __FILE__ );
define( 'REDEEMID_DIR', plugin_dir_path( __FILE__ ) );
define( 'REDEEMID_URL', plugin_dir_url( __FILE__ ) );

// Platform floors are checked before anything that needs PHP 8.2 syntax is loaded.
require_once __DIR__ . '/src/Requirements.php';

$redeemid_problems = \RedeemID\Requirements::problems();

if ( $redeemid_problems ) {
	\RedeemID\Requirements::report( $redeemid_problems );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/functions.php';

register_activation_hook( __FILE__, [ \RedeemID\Lifecycle::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \RedeemID\Lifecycle::class, 'deactivate' ] );

\RedeemID\Plugin::boot();
