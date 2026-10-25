<?php

namespace RedeemID\Admin;

use RedeemID\Access\LevelStore;
use RedeemID\Config;
use RedeemID\Entitlement\Evaluator;
use RedeemID\Shopify\Admin as AdminApi;
use RedeemID\Shopify\Discovery;
use RedeemID\Shopify\Storefront;
use RedeemID\Support\Logger;
use RedeemID\Webhooks\Registrar;

/**
 * The setup checklist on RedeemID → Overview: each thing a new site needs, in
 * the order to do it, with the button that does it where the plugin can.
 *
 * - Test connection asks each Shopify API once and keeps the answers.
 * - Create the callback page publishes the page sign-in returns to.
 * - Level tags are checked against the tags Shopify's customers actually
 *   have, which catches a typo before a member finds it.
 *
 * steps() is pure; facts() gathers what it needs.
 */
final class Setup {
	public const ACTION = 'redeemid_setup';
	private const TEST  = 'redeemid_connection_test';
	private const TAGS  = 'redeemid_shop_tags';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );
		$do     = sanitize_key( wp_unslash( $_REQUEST['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by Menu::guard().
		$result = '';

		if ( 'test' === $do ) {
			$test   = self::test();
			$result = in_array( false, $test['results'], true ) ? 'test_failed' : 'test_ok';
		} elseif ( 'callback' === $do ) {
			$result = self::create_callback_page() ? 'callback_created' : 'callback_failed';
		} elseif ( 'tags' === $do ) {
			delete_transient( self::TAGS );
			$result = 'tags_checked';
		}

		wp_safe_redirect( Menu::url( Menu::SLUG, [ 'result' => $result ] ) . '#setup' );
		exit;
	}

	/**
	 * Ask each configured Shopify API once.
	 *
	 * @return array{at: int, results: array<string, bool>, errors: array<string, string>}
	 */
	public static function test(): array {
		$results = [];
		$errors  = [];
		$started = time();

		Discovery::flush();
		Discovery::openid();
		$results['sign_in'] = Discovery::last_fetched() >= $started;

		if ( ! $results['sign_in'] ) {
			$errors['sign_in'] = 'discovery';
		}

		if ( AdminApi::available() ) {
			$ping               = AdminApi::query( 'query RedeemIDPing { shop { name } }', [], 'admin.ping' );
			$results['admin']   = ! is_wp_error( $ping );
			$errors['admin']    = is_wp_error( $ping ) ? $ping->get_error_code() : '';
		}

		if ( Storefront::available() ) {
			$search                = Storefront::search_products( 'redeemid connection test ' . $started );
			$results['storefront'] = ! is_wp_error( $search );
			$errors['storefront']  = is_wp_error( $search ) ? $search->get_error_code() : '';
		}

		$test = [ 'at' => $started, 'results' => $results, 'errors' => array_filter( $errors ) ];
		update_option( self::TEST, $test, false );
		Logger::log( 'admin.connection_test', [ 'results' => $results ] );

		return $test;
	}

	/**
	 * Publish the page Shopify sign-in returns to, or republish the one there.
	 */
	public static function create_callback_page(): bool {
		$slug = Config::callback_slug();
		$page = get_page_by_path( $slug );

		if ( $page instanceof \WP_Post ) {
			return 'publish' === $page->post_status || ! is_wp_error( wp_update_post( [ 'ID' => $page->ID, 'post_status' => 'publish' ], true ) );
		}

		$id = wp_insert_post(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => __( 'Signing in', 'redeemid' ),
				'post_content' => '<!-- wp:paragraph --><p>' . esc_html__( 'You can close this page or carry on browsing.', 'redeemid' ) . '</p><!-- /wp:paragraph -->',
			],
			true
		);

		if ( is_wp_error( $id ) ) {
			return false;
		}

		Logger::log( 'admin.callback_page_created', [ 'page' => $id ] );

		return true;
	}

	/**
	 * Level tags no customer in Shopify has. Null when Shopify's list can't be
	 * read, or has 250 tags or more and so may be incomplete.
	 *
	 * @return string[]|null
	 */
	private static function unknown_tags(): ?array {
		$shop = get_transient( self::TAGS );

		if ( ! is_array( $shop ) ) {
			$shop = AdminApi::available() ? AdminApi::customer_tags() : [];
			$shop = is_wp_error( $shop ) ? [ 'error' => true ] : $shop;
			set_transient( self::TAGS, $shop, HOUR_IN_SECONDS );
		}

		if ( isset( $shop['error'] ) || count( $shop ) >= 250 ) {
			return null;
		}

		$tags = [];

		foreach ( LevelStore::levels()->all() as $level ) {
			array_push( $tags, ...$level->tags() );
		}

		return self::missing_tags( $tags, $shop );
	}

	/**
	 * @param string[] $wanted Tags levels use.
	 * @param string[] $known  Tags Shopify's customers have.
	 * @return string[] $wanted tags not in $known, compared as Shopify does. Pure.
	 */
	public static function missing_tags( array $wanted, array $known ): array {
		$known = array_map( [ Evaluator::class, 'normalize_tag' ], $known );
		$out   = [];

		foreach ( $wanted as $tag ) {
			if ( ! in_array( Evaluator::normalize_tag( $tag ), $known, true ) ) {
				$out[ Evaluator::normalize_tag( $tag ) ] ??= $tag;
			}
		}

		return array_values( $out );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function facts(): array {
		$slug     = Config::callback_slug();
		$page     = get_page_by_path( $slug );
		$hooks    = get_option( Registrar::RESULT );
		$levels   = array_filter( LevelStore::levels()->all(), static fn( $level ) => ! $level->builtin() );
		$missing  = array_keys( array_filter( Config::definitions(), static fn( $def, $name ) => $def['required'] && ! Config::has( $name ), ARRAY_FILTER_USE_BOTH ) );
		$test     = get_option( self::TEST );
		$activity = Activation::stats();

		return [
			'config'      => Config::has( 'REDEEMID_STORE_DOMAIN' ) && ! $missing,
			'test'        => is_array( $test ) ? $test : null,
			'permalinks'  => '' !== (string) get_option( 'permalink_structure' ),
			'callback'    => $page instanceof \WP_Post && 'publish' === $page->post_status,
			'webhooks'    => is_array( $hooks ) && ! $hooks['error'] && ! $hooks['failed'],
			'levels'      => count( $levels ),
			'unknown'     => $levels && AdminApi::available() ? self::unknown_tags() : null,
			'members'     => array_sum( array_column( $activity['levels'], 'linked' ) ),
		];
	}

	/**
	 * The checklist for $facts. Pure.
	 *
	 * @param array<string, mixed> $facts From facts().
	 * @return array<int, array{key: string, done: bool, detail: string}>
	 */
	public static function steps( array $facts ): array {
		$test    = $facts['test'];
		$tested  = is_array( $test ) && $test['results'] && ! in_array( false, $test['results'], true );
		$unknown = $facts['unknown'];

		return [
			[ 'key' => 'config', 'done' => (bool) $facts['config'], 'detail' => '' ],
			[ 'key' => 'test', 'done' => $tested, 'detail' => is_array( $test ) ? implode( ', ', array_keys( array_filter( $test['results'], static fn( $ok ) => ! $ok ) ) ) : '' ],
			[ 'key' => 'permalinks', 'done' => (bool) $facts['permalinks'], 'detail' => '' ],
			[ 'key' => 'callback', 'done' => (bool) $facts['callback'], 'detail' => '' ],
			[ 'key' => 'webhooks', 'done' => (bool) $facts['webhooks'], 'detail' => '' ],
			// Tags can't always be checked (no Admin API, or a store with 250+ tags); then a level is enough.
			[ 'key' => 'levels', 'done' => $facts['levels'] > 0 && ! $unknown, 'detail' => $unknown ? implode( ', ', $unknown ) : '' ],
			[ 'key' => 'flow', 'done' => $facts['members'] > 0, 'detail' => '' ],
		];
	}
}
