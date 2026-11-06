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
 * The setup checklist on RedeemID → Setup → Checklist: each thing a new site needs, in
 * the order to do it, with the button that does it where the plugin can.
 *
 * - Test connection asks each Shopify API once and keeps the answers. When
 *   sign-in answers it publishes the callback page if there is none, and when
 *   the Admin app answers it registers webhooks if they aren't in place.
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

	/** Shopify's CustomerAccountsVersion for legacy accounts, which can't use the Customer Account API. */
	public const CLASSIC_ACCOUNTS = 'CLASSIC';

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
			self::forget_shop_tags();
			$result = 'tags_checked';
		}

		wp_safe_redirect( Menu::url( Wizard::PAGE, [ 'tab' => 'checklist', 'result' => $result ] ) );
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

		$accounts = '';

		if ( AdminApi::available() ) {
			$ping             = AdminApi::query( 'query RedeemIDPing { shop { name customerAccountsV2 { customerAccountsVersion } } }', [], 'admin.ping' );
			$results['admin'] = ! is_wp_error( $ping );
			$errors['admin']  = is_wp_error( $ping ) ? $ping->get_error_code() : '';
			$accounts         = is_array( $ping ) ? (string) ( $ping['shop']['customerAccountsV2']['customerAccountsVersion'] ?? '' ) : '';
		}

		// Members sign in through the Customer Account API, which only new customer accounts have.
		if ( self::CLASSIC_ACCOUNTS === $accounts ) {
			$results['sign_in'] = false;
			$errors['sign_in']  = 'classic_accounts';
		}

		if ( Storefront::available() ) {
			$search                = Storefront::search_products( 'redeemid connection test ' . $started );
			$results['storefront'] = ! is_wp_error( $search );
			$errors['storefront']  = is_wp_error( $search ) ? $search->get_error_code() : '';
		}

		// A topic skipped for want of a scope is tried again, in case the app gained it.
		$hooks = get_option( Registrar::RESULT );
		$done  = self::follow_up(
			self::follow_ups(
				$results,
				get_page_by_path( Config::callback_slug() ) instanceof \WP_Post,
				self::webhooks_registered( $hooks ) && empty( $hooks['skipped'] )
			)
		);
		$test = [ 'at' => $started, 'results' => $results, 'errors' => array_filter( $errors ), 'accounts' => $accounts, 'auto' => $done ];
		update_option( self::TEST, $test, false );
		Logger::log( 'admin.connection_test', [ 'results' => $results, 'auto' => implode( ',', $done ) ] );

		return $test;
	}

	/**
	 * What a passing test lets the plugin do on its own, so nobody has to press
	 * another button: publish the callback page once sign-in answers (only when
	 * no page has that slug, so a page someone unpublished stays as it is), and
	 * register webhooks once the Admin app answers and they aren't in place. Pure.
	 *
	 * @param array<string, bool> $results    From the test.
	 * @param bool                $has_page   A page with the callback slug exists, in any status.
	 * @param bool                $registered The last webhook registration succeeded.
	 * @return string[] 'callback' and/or 'webhooks'.
	 */
	public static function follow_ups( array $results, bool $has_page, bool $registered ): array {
		$todo = [];

		if ( true === ( $results['sign_in'] ?? null ) && ! $has_page ) {
			$todo[] = 'callback';
		}

		if ( true === ( $results['admin'] ?? null ) && ! $registered ) {
			$todo[] = 'webhooks';
		}

		return $todo;
	}

	/**
	 * Whether a stored registration result (Registrar::RESULT) is a success. Pure.
	 */
	public static function webhooks_registered( mixed $result ): bool {
		return is_array( $result ) && empty( $result['error'] ) && empty( $result['failed'] );
	}

	/**
	 * Do the follow-ups; returns the ones that worked.
	 *
	 * @param string[] $todo From follow_ups().
	 * @return string[] The ones that changed something.
	 */
	private static function follow_up( array $todo ): array {
		$done = [];

		if ( in_array( 'callback', $todo, true ) && self::create_callback_page() ) {
			$done[] = 'callback';
		}

		if ( in_array( 'webhooks', $todo, true ) ) {
			$sync = Registrar::sync();

			// Reported only when something new was subscribed, so a retry of a skipped topic stays quiet.
			if ( self::webhooks_registered( $sync ) && $sync['created'] ) {
				$done[] = 'webhooks';
			}
		}

		return $done;
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
	 * Tags Shopify's customers have, up to 250, kept for an hour. Null when
	 * the list can't be read.
	 *
	 * @return string[]|null
	 */
	public static function shop_tags(): ?array {
		$shop = get_transient( self::TAGS );

		if ( ! is_array( $shop ) ) {
			$shop = AdminApi::available() ? AdminApi::customer_tags() : [];
			$shop = is_wp_error( $shop ) ? [ 'error' => true ] : $shop;
			set_transient( self::TAGS, $shop, HOUR_IN_SECONDS );
		}

		return isset( $shop['error'] ) ? null : $shop;
	}

	public static function forget_shop_tags(): void {
		delete_transient( self::TAGS );
	}

	/**
	 * Level tags no customer in Shopify has. Null when Shopify's list can't be
	 * read, or has 250 tags or more and so may be incomplete.
	 *
	 * @return string[]|null
	 */
	private static function unknown_tags(): ?array {
		$shop = self::shop_tags();

		if ( null === $shop || count( $shop ) >= 250 ) {
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
			'webhooks'    => self::webhooks_registered( $hooks ),
			'levels'      => count( $levels ),
			'unknown'     => $levels && AdminApi::available() ? self::unknown_tags() : null,
			'members'     => array_sum( array_column( $activity['levels'], 'linked' ) ),
			'linked'      => (int) ( $activity['linked_total'] ?? 0 ),
		];
	}

	/**
	 * The checklist for $facts. Pure.
	 *
	 * A site with no levels uses RedeemID for sign-in only: the levels and
	 * tagging steps are then optional, and setup is done without them.
	 *
	 * @param array<string, mixed> $facts From facts().
	 * @return array<int, array{key: string, done: bool, detail: string, optional: bool}>
	 */
	public static function steps( array $facts ): array {
		$test    = $facts['test'];
		$tested  = is_array( $test ) && $test['results'] && ! in_array( false, $test['results'], true );
		$unknown = $facts['unknown'];
		$sign_in = 0 === (int) $facts['levels'];

		return [
			[ 'key' => 'config', 'done' => (bool) $facts['config'], 'detail' => '', 'optional' => false ],
			[ 'key' => 'test', 'done' => $tested, 'detail' => is_array( $test ) ? implode( ', ', array_keys( array_filter( $test['results'], static fn( $ok ) => ! $ok ) ) ) : '', 'optional' => false ],
			[ 'key' => 'permalinks', 'done' => (bool) $facts['permalinks'], 'detail' => '', 'optional' => false ],
			[ 'key' => 'callback', 'done' => (bool) $facts['callback'], 'detail' => '', 'optional' => false ],
			[ 'key' => 'webhooks', 'done' => (bool) $facts['webhooks'], 'detail' => '', 'optional' => false ],
			// Tags can't always be checked (no Admin API, or a store with 250+ tags); then a level is enough.
			[ 'key' => 'levels', 'done' => $facts['levels'] > 0 && ! $unknown, 'detail' => $unknown ? implode( ', ', $unknown ) : '', 'optional' => $sign_in ],
			[ 'key' => 'flow', 'done' => $facts['members'] > 0, 'detail' => '', 'optional' => $sign_in ],
		];
	}

	/**
	 * Steps still to do, leaving out optional ones. Pure.
	 *
	 * @param array<int, array{done: bool, optional: bool}> $steps From steps().
	 */
	public static function left( array $steps ): int {
		return count( array_filter( $steps, static fn( $step ) => ! $step['done'] && ! $step['optional'] ) );
	}
}
