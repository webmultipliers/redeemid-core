<?php
/**
 * Global functions: the public feature API, and wrappers for Blockstudio
 * features that call functions by name.
 *
 * Blockstudio's "populate": { "type": "function" } only accepts a global
 * function name (it checks function_exists()), so each one here is a thin
 * wrapper around a namespaced method. Keep the logic in the method.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Options for the Membership Gate's Level picker.
 *
 * @return array<int, array{value: string, label: string}>
 */
function redeemid_level_options(): array {
	return \RedeemID\Blocks\Gate::level_options();
}

/**
 * The canonical feature check: does this visitor (the current one by
 * default) hold the feature? Server-side, from the synced mirror, resolved
 * once per request. Unknown slug: false.
 *
 * @param string            $slug A registered feature slug.
 * @param int|WP_User|null  $user
 */
function redeemid_has_feature( string $slug, int|\WP_User|null $user = null ): bool {
	return \RedeemID\Features\Registry::has( $slug, $user );
}

/**
 * The visitor's granted features: slug => label, provenance (the first
 * granting route, "Gold") and every granting route. No tags, no customer
 * record.
 *
 * @param int|WP_User|null $user
 * @return array<string, array{label: string, provenance: string, routes: string[], primary: bool}>
 */
function redeemid_granted_features( int|\WP_User|null $user = null ): array {
	return \RedeemID\Features\Registry::granted( $user );
}

/**
 * Granted features flagged primary, for a member menu: at most three.
 *
 * @param int|WP_User|null $user
 * @return array<string, array{label: string, provenance: string, routes: string[], primary: bool}>
 */
function redeemid_menu_features( int|\WP_User|null $user = null ): array {
	return \RedeemID\Features\Registry::menu( $user );
}
