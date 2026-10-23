<?php
/**
 * Activation floors (spec 3.1). Kept free of PHP 8 syntax so it can run, and
 * explain itself, on an unsupported host instead of fataling.
 */

namespace WP2_Shopify;

class Requirements {
	const MIN_PHP = '8.2';
	const MIN_WP  = '6.7';

	/**
	 * @return string[] Human-readable problems; empty when the host is supported.
	 */
	public static function problems() {
		global $wp_version;

		$problems = array();

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			$problems[] = sprintf( 'PHP %s or newer is required (this site runs %s).', self::MIN_PHP, PHP_VERSION );
		}

		if ( isset( $wp_version ) && version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			$problems[] = sprintf( 'WordPress %s or newer is required (this site runs %s).', self::MIN_WP, $wp_version );
		}

		if ( ! extension_loaded( 'sodium' ) ) {
			$problems[] = 'The PHP sodium extension is required for token encryption.';
		}

		if ( ! file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
			$problems[] = 'Composer dependencies are missing. Run "composer install --no-dev" in the plugin folder, or install a release zip.';
		}

		return $problems;
	}

	/**
	 * Show the problems as an admin notice and, during activation, refuse to activate.
	 *
	 * @param string[] $problems
	 */
	public static function report( array $problems ) {
		$message = '<strong>WP2 Shopify is not running.</strong> ' . implode( ' ', array_map( 'esc_html', $problems ) );

		if ( defined( 'WP_SANDBOX_SCRAPING' ) || ( isset( $_GET['action'] ) && 'activate' === $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			deactivate_plugins( plugin_basename( WP2_SHOPIFY_FILE ) );
			wp_die( wp_kses_post( $message ), 'WP2 Shopify', array( 'back_link' => true ) );
		}

		add_action(
			'admin_notices',
			function () use ( $message ) {
				echo '<div class="notice notice-error"><p>' . wp_kses_post( $message ) . '</p></div>';
			}
		);
	}
}
