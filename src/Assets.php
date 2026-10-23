<?php

namespace WP2_Shopify;

class Assets {
	protected static $manifest;
	private static $force_enqueue_once = false;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_admin_assets' ] );
		add_filter( 'script_loader_tag', [ self::class, 'add_module_attribute' ], 10, 3 );
	}

	public static function enqueue_assets(): void {
		if ( ! self::$force_enqueue_once && ! self::should_enqueue_frontend_assets() ) {
			return;
		}

		self::$force_enqueue_once = false;

		// Frontend Logic (Auth Session Check & Event Bus)
		self::enqueue_script( 'wp2-frontend', 'assets/scripts/main.js', [], true );
		self::enqueue_style( 'wp2-frontend', 'assets/styles/main.scss' );

		wp_localize_script( 'wp2-frontend', 'wp2Config', Init::build_frontend_config() );
	}

	public static function force_enqueue_once(): void {
		self::$force_enqueue_once = true;
	}

	private static function should_enqueue_frontend_assets(): bool {
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}

		if ( self::should_force_frontend_assets() ) {
			return true;
		}

		if ( self::is_auth_callback_page() ) {
			return true;
		}

		global $post;

		if ( isset( $post ) && has_shortcode( $post->post_content ?? '', 'wp2_shopify_identity' ) ) {
			return true;
		}

		return false;
	}

	private static function is_auth_callback_page(): bool {
		return (bool) get_query_var( 'shopify_auth_callback', false );
	}

	private static function should_force_frontend_assets(): bool {
		$default = defined( 'WP2_SHOPIFY_FORCE_LOAD_ASSETS' ) && WP2_SHOPIFY_FORCE_LOAD_ASSETS;

		return (bool) apply_filters( 'wp2_shopify_force_enqueue_assets', $default );
	}

	public static function enqueue_admin_assets( $hook ): void {
		if ( 'toplevel_page_wp2-shopify-id' !== $hook ) {
			return;
		}

		self::enqueue_script( 'wp2-admin', 'assets/scripts/admin.js', [], true );
		self::enqueue_style( 'wp2-admin', 'assets/styles/admin.scss' );

		wp_localize_script( 'wp2-admin', 'wp2Config', [
			'rest_nonce' => wp_create_nonce( 'wp_rest' ),
		] );
	}

	protected static function get_manifest_entry( string $entry ): ?array {
		$manifest = self::get_manifest();

		if ( empty( $manifest[ $entry ] ) ) {
			return NULL;
		}

		return $manifest[ $entry ];
	}

	protected static function get_manifest(): array {
		if ( is_array( self::$manifest ) ) {
			return self::$manifest;
		}

		$path = WP2_SHOPIFY_ID_PLUGIN_DIR . 'dist/.vite/manifest.json';

		if ( ! file_exists( $path ) ) {
			self::$manifest = [];
			return [];
		}

		self::$manifest = json_decode( file_get_contents( $path ), true ) ?: [];

		return self::$manifest;
	}

	protected static function dist_url( string $file ): string {
		return WP2_SHOPIFY_ID_PLUGIN_URL . 'dist/' . ltrim( $file, '/' );
	}

	protected static function version( string $file ): string {
		$path = WP2_SHOPIFY_ID_PLUGIN_DIR . 'dist/' . ltrim( $file, '/' );

		return file_exists( $path ) ? (string) filemtime( $path ) : WP2_SHOPIFY_ID_VERSION;
	}

	public static function enqueue_script( string $handle, string $entry, array $deps = [], bool $in_footer = true ): bool {
		$asset = self::get_manifest_entry( $entry );

		if ( ! $asset || empty( $asset['file'] ) ) {
			return false;
		}

		wp_enqueue_script( $handle, self::dist_url( $asset['file'] ), $deps, self::version( $asset['file'] ), $in_footer );
		wp_script_add_data( $handle, 'type', 'module' );

		return true;
	}

	public static function enqueue_style( string $handle, string $entry ): bool {
		$asset = self::get_manifest_entry( $entry );

		if ( ! $asset || empty( $asset['file'] ) ) {
			return false;
		}

		wp_enqueue_style( $handle, self::dist_url( $asset['file'] ), [], self::version( $asset['file'] ) );

		return true;
	}

	public static function add_module_attribute( $tag, $handle, $src ) {
		if ( in_array( $handle, [ 'wp2-frontend', 'wp2-admin' ], true ) ) {
			$tag = preg_replace( '/type=["\"][^"\"]+["\"]/', '', $tag );
			$tag = str_replace( '<script ', '<script type="module" ', $tag );
		}

		return $tag;
	}
}
