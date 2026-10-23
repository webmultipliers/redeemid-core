<?php
namespace WP2_Shopify\Core;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use WP2_Shopify\Auth\Service;

class Settings {
	private static $encryption_key;


	public function render_admin_page() {
		$context = self::get_connection_context();
		extract( $context );
		include WP2_SHOPIFY_ID_PLUGIN_DIR . 'src/views/admin/settings-page.php';
	}

	public static function get_connection_context(): array {
		return [
			'env_constants'    => self::collect_env_constants(),
			'app_requirements' => self::get_shopify_app_requirements(),
		];
	}

	public function register_settings() {
		register_setting( 'wp2_shopify_options', 'wp2_shopify_domain' );
		register_setting( 'wp2_shopify_options', 'wp2_shopify_client_id' );
		register_setting( 'wp2_shopify_options', 'wp2_shopify_redirect_page_id' );

		register_setting( 'wp2_shopify_options', 'wp2_shopify_app_secret', [
			'sanitize_callback' => function ( $value ) {
				$value = (string) $value;

				if ( '' === trim( $value ) ) {
					return (string) get_option( 'wp2_shopify_app_secret', '' );
				}

				self::init_encryption_key();
				return Crypto::encrypt( $value, self::$encryption_key );
			},
			'type'              => 'string',
		] );
	}

	private static function init_encryption_key(): void {
		if ( ! self::$encryption_key ) {
			self::$encryption_key = Key::loadFromAsciiSafeString( WP2_SHOPIFY_KEY );
		}
	}

	public static function decrypt_secret( string $encrypted_value ): string {
		self::init_encryption_key();
		try {
			return Crypto::decrypt( $encrypted_value, self::$encryption_key );
		} catch (\Exception $e) {
			return '';
		}
	}

	private static function collect_env_constants(): array {
		$definitions = [
			'WP2_SHOPIFY_STORE_DOMAIN'           => [
				'label'        => 'Store Domain',
				'description'  => 'The Shopify store host used for OpenID discovery.',
				'missing_help' => 'Define WP2_SHOPIFY_STORE_DOMAIN in wp-config.php.',
				'sensitive'    => false,
			],
			'WP2_SHOPIFY_CLIENT_ID'              => [
				'label'        => 'Client ID',
				'description'  => 'The public OAuth client identifier created in Shopify.',
				'missing_help' => 'Define WP2_SHOPIFY_CLIENT_ID in wp-config.php.',
				'sensitive'    => false,
			],
			'WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN'    => [
				'label'        => 'Public Access Token',
				'description'  => 'The Storefront API token referenced by gating or pricing logic.',
				'missing_help' => 'Define WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN in wp-config.php.',
				'sensitive'    => false,
			],
			'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN'   => [
				'label'        => 'Client Secret',
				'description'  => 'Used as the OAuth client secret for PKCE. The value is hidden here for security.',
				'missing_help' => 'Define WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN in wp-config.php.',
				'sensitive'    => true,
			],
			'WP2_SHOPIFY_OAUTH_AUTHORIZE_URL'    => [
				'label'        => 'OAuth Authorize URL',
				'description'  => 'Optional override for Shopify’s authorization endpoint.',
				'missing_help' => 'Define WP2_SHOPIFY_OAUTH_AUTHORIZE_URL if you override the default endpoint.',
				'sensitive'    => false,
			],
			'WP2_SHOPIFY_OAUTH_TOKEN_URL'        => [
				'label'        => 'OAuth Token URL',
				'description'  => 'Optional override for Shopify’s token exchange endpoint.',
				'missing_help' => 'Define WP2_SHOPIFY_OAUTH_TOKEN_URL if you override the default endpoint.',
				'sensitive'    => false,
			],
			'WP2_SHOPIFY_OAUTH_LOGOUT_URL'       => [
				'label'        => 'OAuth Logout URL',
				'description'  => 'Optional endpoint used after users log out of Shopify.',
				'missing_help' => 'Define WP2_SHOPIFY_OAUTH_LOGOUT_URL if you need a custom logout.',
				'sensitive'    => false,
			],
			'WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET' => [
				'label'        => 'Webhook Signing Secret',
				'description'  => 'Used to verify Shopify webhook payloads (falls back to SHOPIFY_SHARED_SECRET).',
				'missing_help' => 'Define WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET in wp-config.php.',
				'sensitive'    => true,
			],
		];

		$constants = [];

		foreach ( $definitions as $constant_name => $meta ) {
			$defined       = defined( $constant_name );
			$raw_value     = $defined ? (string) constant( $constant_name ) : '';
			$value_present = $defined && '' !== trim( $raw_value );

			$display_value = $value_present
				? ( $meta['sensitive'] ? '********' : sanitize_text_field( $raw_value ) )
				: 'Not defined';

			$constants[] = [
				'label'         => $meta['label'],
				'constant'      => $constant_name,
				'value'         => $display_value,
				'value_present' => $value_present,
				'status_label'  => $value_present ? 'Configured in wp-config.php' : 'Not defined',
				'status_class'  => $value_present ? 'defined' : 'missing',
				'description'   => $meta['description'],
				'missing_help'  => $meta['missing_help'],
				'sensitive'     => $meta['sensitive'],
			];
		}

		return $constants;
	}

	private static function get_shopify_app_requirements(): array {
		return [
			[
				'label'       => 'Callback URI(s) (required)',
				'description' => 'Shopify redirects users here after the OAuth flow completes. Add every URI you intend to use.',
				'values'      => self::get_callback_uris(),
				'empty_text'  => 'Configure a redirect page (via wp2_shopify_redirect_page_id) or use the default /auth-callback path so Shopify can finish the OAuth handshake.',
			],
			[
				'label'       => 'Javascript origin(s)',
				'description' => 'Shopify needs these origins for frontend JS assets, so include them under Allowed JavaScript origins.',
				'values'      => self::get_javascript_origins(),
			],
			[
				'label'       => 'Logout URI',
				'description' => 'Shopify calls this URI after a customer logs out; include it so sessions can be revoked cleanly.',
				'values'      => self::get_logout_uris(),
				'empty_text'  => 'Verify the connection to fetch the end_session_endpoint from Shopify’s OpenID configuration.',
			],
		];
	}

	private static function get_callback_uris(): array {
		$uris = [
			Service::get_redirect_uri(),
			esc_url_raw( home_url( '/auth-callback' ) ),
		];

		return array_values( array_unique( array_filter( $uris ) ) );
	}

	private static function get_javascript_origins(): array {
		$origins = [
			untrailingslashit( esc_url_raw( home_url() ) ),
			untrailingslashit( esc_url_raw( site_url() ) ),
		];

		return array_values( array_unique( array_filter( $origins ) ) );
	}

	private static function get_logout_uris(): array {
		$wp_logout_uri = home_url( '/wp-login.php?action=logout' );
		if ( empty( $wp_logout_uri ) ) {
			return [];
		}
		return [ $wp_logout_uri ];
	}

}
