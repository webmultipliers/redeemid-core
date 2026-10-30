<?php

namespace RedeemID\Support;

use RedeemID\Blocks\Registration;
use RedeemID\Commerce\CartBridge;
use RedeemID\Commerce\Pages;
use RedeemID\Config;
use RedeemID\Integrations\OfficialPlugin;
use RedeemID\Shopify\Discovery;

/**
 * A short yes/no checklist of what the plugin needs, for the Shopify Health
 * block (blocks/health). RedeemID → Debug stays the full diagnostics screen;
 * this is the at-a-glance version a site or a theme can put on a page.
 *
 * Configuration rows come from Config::definitions(), so the checklist never
 * keeps its own copy of the config rules. Each says whether the value comes
 * from wp-config.php or Settings; no value is ever shown.
 * Themes and add-ons append their own rows with redeemid_health_checks.
 */
final class Health {
	public const OK   = 'ok';
	public const WARN = 'warn';
	public const FAIL = 'fail';
	public const INFO = 'info';

	/**
	 * @return array<int, array{label: string, state: string, detail: string}>
	 */
	public static function checks(): array {
		$checks = [];
		$add    = static function ( string $label, string $state, string $detail = '' ) use ( &$checks ): void {
			$checks[] = [ 'label' => $label, 'state' => $state, 'detail' => $detail ];
		};

		$add( __( 'RedeemID plugin', 'redeemid' ), self::OK, 'v' . REDEEMID_VERSION );
		$add(
			__( 'Blockstudio', 'redeemid' ),
			defined( 'BLOCKSTUDIO_VERSION' ) ? self::OK : self::FAIL,
			defined( 'BLOCKSTUDIO_VERSION' ) ? 'v' . BLOCKSTUDIO_VERSION : __( 'not loaded (the plugin bundles it)', 'redeemid' )
		);

		$missing = self::missing_blocks();
		$add(
			__( 'Plugin blocks registered', 'redeemid' ),
			$missing ? self::FAIL : self::OK,
			/* translators: %s: block names */
			$missing ? sprintf( __( 'missing: %s', 'redeemid' ), implode( ', ', $missing ) ) : __( 'all registered', 'redeemid' )
		);

		$structure = (string) get_option( 'permalink_structure' );
		$add( __( 'Pretty permalinks', 'redeemid' ), '' !== $structure ? self::OK : self::FAIL, '' !== $structure ? $structure : __( 'required for the sign-in callback', 'redeemid' ) );

		$slug     = Config::callback_slug();
		$callback = get_page_by_path( $slug );
		$live     = $callback instanceof \WP_Post && 'publish' === $callback->post_status;
		$add( __( 'Callback page', 'redeemid' ), $live ? self::OK : self::WARN, $live ? '/' . $slug . '/' : __( 'not published; the plugin falls back to a bare result page', 'redeemid' ) );

		$available = Discovery::available();
		$add( __( 'Shopify sign-in available', 'redeemid' ), $available ? self::OK : self::FAIL, $available ? __( 'OIDC discovery answered', 'redeemid' ) : __( 'no discovery: check the store domain and client ID', 'redeemid' ) );

		foreach ( Config::definitions() as $name => $def ) {
			$source = Config::source( $name );
			$set    = Config::has( $name );

			if ( ConfigValues::UNREADABLE === $source ) {
				$add( $name, $def['required'] ? self::FAIL : self::WARN, __( 'saved in Settings but unreadable: the encryption key changed', 'redeemid' ) );
				continue;
			}

			$add(
				$name,
				$set ? self::OK : ( $def['required'] ? self::FAIL : self::INFO ),
				$set ? ( ConfigValues::CONSTANT === $source ? __( 'set in wp-config.php', 'redeemid' ) : __( 'set in Settings', 'redeemid' ) ) : ( $def['required'] ? __( 'missing (required)', 'redeemid' ) : __( 'not set (optional)', 'redeemid' ) )
			);
		}

		foreach ( [ Pages::PRODUCT => __( 'Product pages', 'redeemid' ), Pages::COLLECTION => __( 'Collection pages', 'redeemid' ), Pages::CART => __( 'Cart page', 'redeemid' ) ] as $kind => $label ) {
			$owner = Pages::owner( $kind );
			$path  = '/' . Pages::path( $kind ) . ( Pages::CART === $kind ? '' : '/…' );

			$add(
				$label,
				'redeemid' === $owner || ! Pages::enabled() ? self::INFO : self::WARN,
				match ( true ) {
					! Pages::enabled()     => __( 'off in Settings', 'redeemid' ),
					'redeemid' === $owner => sprintf( /* translators: %s: path */ __( 'RedeemID, at %s', 'redeemid' ), $path ),
					'shopify' === $owner  => sprintf( /* translators: %s: path */ __( '%s is Shopify’s plugin’s; RedeemID stands down (Settings → RedeemID takes precedence)', 'redeemid' ), $path ),
					default               => sprintf( /* translators: %s: path */ __( 'not served at %s: a WordPress page uses it, or permalinks need saving', 'redeemid' ), $path ),
				}
			);
		}

		$official = OfficialPlugin::info();
		$states   = [
			OfficialPlugin::ABSENT      => self::INFO,
			OfficialPlugin::UNCONNECTED => self::WARN,
			OfficialPlugin::SAME_STORE  => self::OK,
			OfficialPlugin::OTHER_STORE => self::FAIL,
		];
		$add( __( 'Shopify’s WordPress plugin', 'redeemid' ), $states[ $official['state'] ] ?? self::WARN, OfficialPlugin::summary() );

		if ( OfficialPlugin::ABSENT !== $official['state'] ) {
			$advisories = OfficialPlugin::advisories( $official['version'] );

			if ( $advisories ) {
				$add(
					__( 'Shopify’s plugin security', 'redeemid' ),
					self::WARN,
					sprintf(
						/* translators: 1: version 2: advisory IDs */
						__( 'Version %1$s is named in %2$s with no fixed release. RedeemID’s Shopify Product and Shopify Collection blocks replace its catalog blocks, so you can deactivate it once your pages use them.', 'redeemid' ),
						$official['version'],
						implode( ', ', array_column( $advisories, 'id' ) )
					)
				);
			}


			$bridge = CartBridge::enabled();
			$last   = CartBridge::last();
			$add(
				__( 'Cart sign-in bridge', 'redeemid' ),
				$bridge ? self::OK : self::WARN,
				$bridge
					/* translators: %s: date and time, or "never" */
					? sprintf( __( 'active, last attached %s', 'redeemid' ), $last['ok_at'] ? wp_date( 'Y-m-d H:i', (int) $last['ok_at'] ) : __( 'never', 'redeemid' ) )
					: __( 'off: needs the same store and a Storefront token', 'redeemid' )
			);
		}

		/**
		 * Rows to add to the health checklist, such as a theme's own checks.
		 * Each row is [ 'label' => string, 'state' => ok|warn|fail|info, 'detail' => string ].
		 */
		$checks = apply_filters( 'redeemid_health_checks', $checks );

		return array_values( array_filter( is_array( $checks ) ? $checks : [], [ self::class, 'valid' ] ) );
	}

	/**
	 * A row a filter added is shown only if it has a label and a known state.
	 */
	public static function valid( mixed $row ): bool {
		return is_array( $row ) && '' !== (string) ( $row['label'] ?? '' ) && in_array( $row['state'] ?? '', [ self::OK, self::WARN, self::FAIL, self::INFO ], true );
	}

	/**
	 * @return string[] Names of the plugin's blocks that did not register.
	 */
	private static function missing_blocks(): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		$missing  = [];

		foreach ( glob( Registration::directory() . '/*/block.json' ) ?: [] as $file ) {
			$meta = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData -- local block.json.

			// Components render through bs_block() and are never in the registry.
			if ( is_array( $meta ) && ! empty( $meta['name'] ) && empty( $meta['blockstudio']['component'] ) && ! $registry->is_registered( $meta['name'] ) ) {
				$missing[] = $meta['name'];
			}
		}

		return $missing;
	}
}
