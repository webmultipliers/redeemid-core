<?php

namespace WP2_Shopify;

use WP2_Shopify\Access\Badges;
use WP2_Shopify\Access\BlockAccess;
use WP2_Shopify\Access\Cli;
use WP2_Shopify\Access\Gatekeeper;
use WP2_Shopify\Access\Index;
use WP2_Shopify\Access\ItemRule;
use WP2_Shopify\Access\Layers;
use WP2_Shopify\Access\Surfaces;
use WP2_Shopify\Access\TermRules;
use WP2_Shopify\Access\Viewer;
use WP2_Shopify\Admin\AccessColumn;
use WP2_Shopify\Admin\AccessMetaBox;
use WP2_Shopify\Admin\AccessTermFields;
use WP2_Shopify\Admin\Menu;
use WP2_Shopify\Admin\UserProfile;
use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Auth\ShadowUsers;
use WP2_Shopify\Blocks\Registration;
use WP2_Shopify\Commerce\BuyNow;
use WP2_Shopify\Commerce\CartBridge;
use WP2_Shopify\Integrations\SeoPlugins;
use WP2_Shopify\Rest\Routes;
use WP2_Shopify\Rest\Status;
use WP2_Shopify\Support\Crypto;
use WP2_Shopify\Support\EventLog;
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
		Gatekeeper::register();
		ItemRule::register();
		TermRules::register();
		Layers::register();
		Index::register();
		Viewer::register();
		BlockAccess::register();
		Badges::register();
		Cli::register();
		Surfaces::register();
		SeoPlugins::register();
		BuyNow::register();
		CartBridge::register();
		Privacy::register();
		Compat::register();
		EventLog::register();

		add_action( 'init', [ ShadowUsers::class, 'ensure_role' ] );
		add_action( 'rest_api_init', [ Routes::class, 'register' ] );
		add_action( Endpoint::HOOK, [ Processor::class, 'process' ] );
		add_action( Reconciler::HOOK, [ Reconciler::class, 'run' ] );
		add_action( 'wp2_shopify_prune', [ WpdbInboxStore::class, 'prune' ] );
		add_action( 'wp2_shopify_prune', [ EventLog::class, 'prune' ] );
		add_action( 'admin_init', [ EventLog::class, 'maybe_install' ] );
		add_action( 'admin_init', [ Migration::class, 'maybe_run' ] );

		if ( is_admin() ) {
			Menu::register();
			AccessMetaBox::register();
			AccessColumn::register();
			AccessTermFields::register();
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
