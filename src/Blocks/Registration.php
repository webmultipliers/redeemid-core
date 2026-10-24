<?php

namespace WP2_Shopify\Blocks;

/**
 * Registers the plugin's Blockstudio blocks next to any theme blocks (spec 3.1).
 *
 * Verified against Blockstudio 7.7.7: the blockstudio/path filter REPLACES the
 * theme's block directory (Build::get_build_dir() returns the filter value
 * whenever the filter exists), so using it from a plugin would hide a
 * Blockstudio theme's own blocks. A separate Build::init() instance adds a
 * second discovery root and leaves the theme root alone.
 */
final class Registration {
	public const STATUS_OPTION = 'wp2_shopify_block_registration';

	public static function register(): void {
		add_filter( 'blockstudio/blocks/attributes', [ self::class, 'product_picker_url' ], 10, 2 );
		add_action( 'init', [ self::class, 'init_blocks' ], 9 );
		add_action( 'init', [ Gate::class, 'register' ] );
		add_filter( 'render_block_data', [ Upgrade::class, 'block' ], 5, 1 );
	}

	/**
	 * Point the Plan Card's product picker (a Blockstudio "fetch" populate) at
	 * this site's REST route. block.json can only hold a fixed URL, and the
	 * REST root differs per site.
	 */
	public static function product_picker_url( mixed $attribute, mixed $block ): mixed {
		if ( is_array( $attribute ) && 'productHandle' === ( $attribute['id'] ?? '' ) && 'fetch' === ( $attribute['populate']['type'] ?? '' ) ) {
			$url = rest_url( \WP2_Shopify\Rest\Routes::NS . '/editor/products' );

			// Blockstudio appends the search term to this URL as is.
			$attribute['populate']['arguments']['urlSearch'] = $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'search=';
		}

		return $attribute;
	}

	public static function directory(): string {
		return WP2_SHOPIFY_DIR . 'blocks';
	}

	public static function init_blocks(): void {
		if ( ! class_exists( \Blockstudio\Build::class ) ) {
			self::record( 'missing_blockstudio' );
			return;
		}

		\Blockstudio\Build::init( [ 'dir' => self::directory() ] );

		add_action( 'wp_loaded', [ self::class, 'verify' ] );
	}

	/**
	 * Record which of our blocks actually registered, and whether the theme's
	 * Blockstudio directory is still registered too. Shown on diagnostics.
	 */
	public static function verify(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$expected = [];

		foreach ( glob( self::directory() . '/*/block.json' ) ?: [] as $file ) {
			$meta = json_decode( (string) file_get_contents( $file ), true );

			// Blockstudio components (blockstudio.component) render through bs_block() and never enter the WordPress registry.
			if ( is_array( $meta ) && ! empty( $meta['name'] ) && empty( $meta['blockstudio']['component'] ) ) {
				$expected[] = $meta['name'];
			}
		}

		$missing    = array_values( array_filter( $expected, static fn( $name ) => ! $registry->is_registered( $name ) ) );
		$theme_dir  = get_stylesheet_directory() . '/blockstudio';
		$theme_has  = is_dir( $theme_dir );
		$theme_kept = ! has_filter( 'blockstudio/path' );

		self::record(
			$missing ? 'missing_blocks' : 'ok',
			[
				'version'          => defined( 'BLOCKSTUDIO_VERSION' ) ? BLOCKSTUDIO_VERSION : '',
				'expected'         => count( $expected ),
				'missing'          => $missing,
				'theme_blockstudio'=> $theme_has,
				'theme_path_intact'=> $theme_kept,
			]
		);
	}

	private static function record( string $status, array $details = [] ): void {
		$value = [ 'status' => $status, 'checked' => time() ] + $details;
		$prev  = get_option( self::STATUS_OPTION );

		if ( ! is_array( $prev ) || $prev['status'] !== $status || ( $prev['missing'] ?? [] ) !== ( $details['missing'] ?? [] ) || time() - (int) $prev['checked'] > HOUR_IN_SECONDS ) {
			update_option( self::STATUS_OPTION, $value, false );
		}
	}
}
