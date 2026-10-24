<?php

namespace WP2_Shopify\Support;

use WP2_Shopify\Blocks\Registration;
use WP2_Shopify\Commerce\CartBridge;
use WP2_Shopify\Config;
use WP2_Shopify\Integrations\OfficialPlugin;
use WP2_Shopify\Shopify\Discovery;

/**
 * A short yes/no checklist of what the plugin needs, for the Shopify Health
 * block (blocks/health). Shopify → Debug stays the full diagnostics screen;
 * this is the at-a-glance version a site or a theme can put on a page.
 *
 * Configuration rows come from Config::definitions(), so the checklist never
 * keeps its own copy of the config rules. No constant's value is ever shown.
 * Themes and add-ons append their own rows with wp2_shopify_health_checks.
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

		$add( __( 'WP2 Shopify plugin', 'wp2-shopify' ), self::OK, 'v' . WP2_SHOPIFY_VERSION );
		$add(
			__( 'Blockstudio', 'wp2-shopify' ),
			defined( 'BLOCKSTUDIO_VERSION' ) ? self::OK : self::FAIL,
			defined( 'BLOCKSTUDIO_VERSION' ) ? 'v' . BLOCKSTUDIO_VERSION : __( 'not loaded (the plugin bundles it)', 'wp2-shopify' )
		);

		$missing = self::missing_blocks();
		$add(
			__( 'Plugin blocks registered', 'wp2-shopify' ),
			$missing ? self::FAIL : self::OK,
			/* translators: %s: block names */
			$missing ? sprintf( __( 'missing: %s', 'wp2-shopify' ), implode( ', ', $missing ) ) : __( 'all registered', 'wp2-shopify' )
		);

		$structure = (string) get_option( 'permalink_structure' );
		$add( __( 'Pretty permalinks', 'wp2-shopify' ), '' !== $structure ? self::OK : self::FAIL, '' !== $structure ? $structure : __( 'required for the sign-in callback', 'wp2-shopify' ) );

		$slug     = Config::callback_slug();
		$callback = get_page_by_path( $slug );
		$live     = $callback instanceof \WP_Post && 'publish' === $callback->post_status;
		$add( __( 'Callback page', 'wp2-shopify' ), $live ? self::OK : self::WARN, $live ? '/' . $slug . '/' : __( 'not published; the plugin falls back to a bare result page', 'wp2-shopify' ) );

		$available = Discovery::available();
		$add( __( 'Shopify sign-in available', 'wp2-shopify' ), $available ? self::OK : self::FAIL, $available ? __( 'OIDC discovery answered', 'wp2-shopify' ) : __( 'no discovery: check the store domain and client ID', 'wp2-shopify' ) );

		foreach ( Config::definitions() as $name => $def ) {
			$set = Config::has( $name );
			$add(
				$name,
				$set ? self::OK : ( $def['required'] ? self::FAIL : self::INFO ),
				$set ? __( 'set', 'wp2-shopify' ) : ( $def['required'] ? __( 'missing (required)', 'wp2-shopify' ) : __( 'not set (optional)', 'wp2-shopify' ) )
			);
		}

		foreach ( array_keys( array_filter( Config::retired(), 'defined', ARRAY_FILTER_USE_KEY ) ) as $name ) {
			$add( $name, self::WARN, __( 'defined but ignored: remove it from wp-config.php', 'wp2-shopify' ) );
		}

		$official = OfficialPlugin::info();
		$states   = [
			OfficialPlugin::ABSENT      => self::INFO,
			OfficialPlugin::UNCONNECTED => self::WARN,
			OfficialPlugin::SAME_STORE  => self::OK,
			OfficialPlugin::OTHER_STORE => self::FAIL,
		];
		$add( __( 'Shopify’s WordPress plugin', 'wp2-shopify' ), $states[ $official['state'] ] ?? self::WARN, OfficialPlugin::summary() );

		if ( OfficialPlugin::ABSENT !== $official['state'] ) {
			$add( __( '/cart owner', 'wp2-shopify' ), self::INFO, $official['owns_cart'] ? __( 'Shopify’s plugin (its rewrite rule wins over pages)', 'wp2-shopify' ) : __( 'nobody yet: flush permalinks', 'wp2-shopify' ) );

			$bridge = CartBridge::enabled();
			$last   = CartBridge::last();
			$add(
				__( 'Cart sign-in bridge', 'wp2-shopify' ),
				$bridge ? self::OK : self::WARN,
				$bridge
					/* translators: %s: date and time, or "never" */
					? sprintf( __( 'active, last attached %s', 'wp2-shopify' ), $last['ok_at'] ? wp_date( 'Y-m-d H:i', (int) $last['ok_at'] ) : __( 'never', 'wp2-shopify' ) )
					: __( 'off: needs the same store and WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN', 'wp2-shopify' )
			);
		}

		/**
		 * Rows to add to the health checklist, such as a theme's own checks.
		 * Each row is [ 'label' => string, 'state' => ok|warn|fail|info, 'detail' => string ].
		 */
		$checks = apply_filters( 'wp2_shopify_health_checks', $checks );

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
			$meta = json_decode( (string) file_get_contents( $file ), true );

			// Components render through bs_block() and are never in the registry.
			if ( is_array( $meta ) && ! empty( $meta['name'] ) && empty( $meta['blockstudio']['component'] ) && ! $registry->is_registered( $meta['name'] ) ) {
				$missing[] = $meta['name'];
			}
		}

		return $missing;
	}
}
