<?php

namespace RedeemID;

use RedeemID\Abilities\Abilities;
use RedeemID\Access\Badges;
use RedeemID\Access\BlockAccess;
use RedeemID\Access\Cli;
use RedeemID\Access\Gatekeeper;
use RedeemID\Access\Index;
use RedeemID\Access\ItemRule;
use RedeemID\Access\Layers;
use RedeemID\Access\Surfaces;
use RedeemID\Access\TermRules;
use RedeemID\Access\Viewer;
use RedeemID\Features\Registry as FeatureRegistry;
use RedeemID\Features\Surfaces as FeatureSurfaces;
use RedeemID\Admin\AccessColumn;
use RedeemID\Admin\AccessMetaBox;
use RedeemID\Admin\AccessTermFields;
use RedeemID\Admin\FileFields;
use RedeemID\Admin\Menu;
use RedeemID\Admin\UserProfile;
use RedeemID\Auth\Flow;
use RedeemID\Auth\ShadowUsers;
use RedeemID\Auth\SignInLinks;
use RedeemID\Blocks\Registration;
use RedeemID\Commerce\BuyNow;
use RedeemID\Commerce\CartBridge;
use RedeemID\Files\ProtectedFiles;
use RedeemID\Integrations\OfficialCartToggle;
use RedeemID\Integrations\SeoPlugins;
use RedeemID\Rest\Routes;
use RedeemID\Rest\Status;
use RedeemID\Support\Crypto;
use RedeemID\Support\EventLog;
use RedeemID\Support\Stats;
use RedeemID\Support\TransferCli;
use RedeemID\Sync\BulkResync;
use RedeemID\Sync\Cli as SyncCli;
use RedeemID\Sync\LevelHistory;
use RedeemID\Sync\PostCheckout;
use RedeemID\Webhooks\Endpoint;
use RedeemID\Webhooks\Monitor;
use RedeemID\Webhooks\Processor;
use RedeemID\Webhooks\WpdbInboxStore;

final class Plugin {
	private static ?Crypto $crypto = null;

	public static function boot(): void {
		ShadowUsers::register();
		Flow::register();
		SignInLinks::register();
		Status::register();
		PostCheckout::register();
		BulkResync::register();
		LevelHistory::register();
		Monitor::register();
		Registration::register();
		Gatekeeper::register();
		ItemRule::register();
		TermRules::register();
		Layers::register();
		Index::register();
		Viewer::register();
		BlockAccess::register();
		FeatureRegistry::register();
		FeatureSurfaces::register();
		Badges::register();
		Cli::register();
		SyncCli::register();
		TransferCli::register();
		Surfaces::register();
		SeoPlugins::register();
		OfficialCartToggle::register();
		BuyNow::register();
		CartBridge::register();
		ProtectedFiles::register();
		Privacy::register();
		EventLog::register();
		Stats::register();
		Abilities::register();

		add_action( 'init', [ ShadowUsers::class, 'ensure_role' ] );
		// Translations installed in wp-content/languages win; the bundled .pot is the template.
		add_action( 'init', static fn() => load_plugin_textdomain( 'redeemid', false, dirname( plugin_basename( REDEEMID_FILE ) ) . '/languages' ) );
		add_action( 'rest_api_init', [ Routes::class, 'register' ] );
		add_action( Endpoint::HOOK, [ Processor::class, 'process' ] );
		add_action( 'redeemid_prune', [ WpdbInboxStore::class, 'prune' ] );
		add_action( 'redeemid_prune', [ EventLog::class, 'prune' ] );
		add_action( 'admin_init', [ EventLog::class, 'maybe_install' ] );

		if ( is_admin() ) {
			Menu::register();
			AccessMetaBox::register();
			AccessColumn::register();
			AccessTermFields::register();
			UserProfile::register();
			FileFields::register();
		}
	}

	/**
	 * Token encryption, keyed from REDEEMID_ENCRYPTION_KEY or the WordPress salts.
	 */
	public static function crypto(): Crypto {
		if ( ! self::$crypto ) {
			$salts = '';

			foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT' ] as $name ) {
				$value = defined( $name ) ? (string) constant( $name ) : '';
				// The wp-config-sample placeholder is not a secret.
				$salts .= 'put your unique phrase here' === $value ? '' : $value;
			}

			self::$crypto = Crypto::from_config( Config::get( 'REDEEMID_ENCRYPTION_KEY' ), $salts );
		}

		return self::$crypto;
	}
}
