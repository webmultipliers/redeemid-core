<?php

namespace RedeemID\Api;

/**
 * The developer API, as a list: what other code may rely on.
 *
 * - STABLE: changes only with a changelog entry and a VERSION bump. Before
 *   1.0.0 a breaking change can still happen, but never silently.
 * - EXPERIMENTAL: public and documented, but its shape may change in any
 *   release while it settles.
 * - Anything not listed here is internal, even when it's a public method.
 *
 * docs/developer-api.md is the readable version of this list, and
 * tests/Unit/ApiContractTest.php fails when the code, this list and that
 * page disagree: a listed hook that no longer fires, a new hook nobody
 * listed, or a name the page forgot.
 *
 * VERSION is semantic: the minor part bumps when something is added, the
 * major part when something stable changes or goes. REDEEMID_API_VERSION
 * carries it at runtime.
 */
final class Contract {
	public const VERSION = '1.2.0';

	/** Global functions that exist only for Blockstudio's "populate", not for other code. */
	public const INTERNAL_FUNCTIONS = [ 'redeemid_level_options' ];

	/** Global PHP functions in src/functions.php. */
	public const FUNCTIONS = [
		'redeemid_can_read',
		'redeemid_user_levels',
		'redeemid_has_feature',
		'redeemid_granted_features',
		'redeemid_menu_features',
		'redeemid_meter',
		'redeemid_preferences',
		'redeemid_set_preferences',
	];

	public const ACTIONS = [
		'redeemid_event',
		'redeemid_wall_shown',
		'redeemid_meter_used',
		'redeemid_meter_exhausted',
		'redeemid_checkout_started',
		'redeemid_level_gained',
		'redeemid_level_lost',
		'redeemid_preferences_saved',
		'redeemid_signed_in',
		'redeemid_linked',
		'redeemid_disconnected',
		'redeemid_before_logout',
		'redeemid_levels_changed',
		'redeemid_features_changed',
		'redeemid_features_mapping_changed',
		'redeemid_access_changed',
		'redeemid_access_levels_changed',
		'redeemid_access_locked_changed',
		'redeemid_shopify_erasure_requested',
		'redeemid_log',
	];

	public const FILTERS = [
		'redeemid_auth_callback_slug',
		'redeemid_default_redirect',
		'redeemid_customer_admin_redirect',
		'redeemid_sign_in_redirect',
		'redeemid_offer_existing_account',
		'redeemid_setting_{key}',
		'redeemid_access_levels',
		'redeemid_access_rules',
		'redeemid_gated_post_types',
		'redeemid_access_context',
		'redeemid_effective_rule',
		'redeemid_teaser_settings',
		'redeemid_paywall_schema',
		'redeemid_drip_days',
		'redeemid_features',
		'redeemid_feature_tests',
		'redeemid_feature_decision',
		'redeemid_context_country',
		'redeemid_buyer_ip',
		'redeemid_cart_attribution',
		'redeemid_checkout_banner',
		'redeemid_storefront_path',
		'redeemid_product_url',
		'redeemid_cart_enabled',
		'redeemid_locked_panel',
		'redeemid_health_checks',
		'redeemid_file_sendfile',
		'redeemid_customer_badges',
		'redeemid_reassign_user',
		'redeemid_redact_opens_wp_request',
	];

	public const EXPERIMENTAL_FILTERS = [
		'redeemid_customer_is_fixture',
		'redeemid_official_state',
		'redeemid_pre_sync_now',
		'redeemid_client_config',
	];

	/** REST routes under redeemid/v1, as "METHOD /path". */
	public const ROUTES = [
		'GET /state',
		'GET /auth/status',
		'GET /auth/login',
		'POST /auth/logout',
		'POST /auth/refresh',
		'POST /auth/disconnect',
		'GET /account',
		'POST /account/sync',
		'GET /account/preferences',
		'POST /account/preferences',
		'GET /features',
	];

	/** Routes for RedeemID's own blocks and Shopify: public, not for other code. */
	public const INTERNAL_ROUTES = [
		'POST /cart/identity',
		'GET /editor/products',
		'GET /editor/collections',
		'POST /webhooks/events',
	];

	/** The browser client: the script handle, window.redeemid's methods, and its events. */
	public const CLIENT_HANDLE = 'redeemid-client';

	public const CLIENT_METHODS = [ 'state', 'refresh', 'signIn', 'signOut', 'subscribe', 'emit' ];

	/** Browser events. emit() also accepts a site's own names, prefixed "site_". */
	public const CLIENT_EVENTS = [ 'ready', 'state', 'wall_shown', 'metered_view', 'sign_in_click', 'checkout_click' ];

	/**
	 * Every redeemid_* action name, stable or not. Pure.
	 *
	 * @return string[]
	 */
	public static function actions(): array {
		return array_values( array_unique( [ ...self::ACTIONS, ...array_column( Events::CATALOG, 'action' ) ] ) );
	}

	/**
	 * Every listed filter, with a dynamic suffix written as its prefix. Pure.
	 *
	 * @return string[]
	 */
	public static function filters(): array {
		return array_map( static fn( string $f ): string => str_replace( '{key}', '', $f ), [ ...self::FILTERS, ...self::EXPERIMENTAL_FILTERS ] );
	}
}
