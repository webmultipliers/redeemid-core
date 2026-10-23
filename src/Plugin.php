<?php

namespace WP2_Shopify;

use WP2_Shopify\Admin\Diagnostics;
use WP2_Shopify\Admin\UserProfile;
use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Auth\ShadowUsers;
use WP2_Shopify\Blocks\Registration;
use WP2_Shopify\Rest\Routes;
use WP2_Shopify\Rest\Status;
use WP2_Shopify\Support\Crypto;
use WP2_Shopify\Sync\PostCheckout;
use WP2_Shopify\Sync\Reconciler;
use WP2_Shopify\Webhooks\Endpoint;
use WP2_Shopify\Webhooks\Processor;
use WP2_Shopify\Webhooks\WpdbInboxStore;

final class Plugin {
	private static ?Crypto $crypto = null;

	public static function boot(): void {
		ShadowUsers::register();
		Flow::register();
		Status::register();
		PostCheckout::register();
		Registration::register();
		Privacy::register();
		Compat::register();

		add_action( 'init', [ ShadowUsers::class, 'ensure_role' ] );
		add_action( 'rest_api_init', [ Routes::class, 'register' ] );
		add_action( Endpoint::HOOK, [ Processor::class, 'process' ] );
		add_action( Reconciler::HOOK, [ Reconciler::class, 'run' ] );
		add_action( 'wp2_shopify_prune', [ WpdbInboxStore::class, 'prune' ] );
		add_action( 'admin_init', [ Migration::class, 'maybe_run' ] );

		if ( is_admin() ) {
			Diagnostics::register();
			UserProfile::register();
		}
	}

	/**
	 * Token encryption, keyed from WP2_SHOPIFY_ENCRYPTION_KEY or the WordPress salts.
	 */
	public static function crypto(): Crypto {
		if ( ! self::$crypto ) {
			$salts = '';

			foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT' ] as $name ) {
				$value = defined( $name ) ? (string) constant( $name ) : '';
				// The wp-config-sample placeholder is not a secret.
				$salts .= 'put your unique phrase here' === $value ? '' : $value;
			}

			self::$crypto = Crypto::from_config( Config::get( 'WP2_SHOPIFY_ENCRYPTION_KEY' ), $salts );
		}

		return self::$crypto;
	}
}
