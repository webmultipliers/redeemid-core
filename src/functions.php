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

/**
 * Can this user read this item in full? Goes through every layer the gate
 * uses (site default, post type, terms, the item's own rule, time windows,
 * archive windows and drip) and WordPress's own status checks. Never uses
 * the editor bypass and never exposes tags. No user: the current visitor.
 *
 * @param int|WP_Post      $post
 * @param int|WP_User|null $user
 */
function redeemid_can_read( int|\WP_Post $post, int|\WP_User|null $user = null ): bool {
	$post = get_post( $post );

	return $post instanceof \WP_Post && \RedeemID\Access\Reader::can( $post, $user );
}

/**
 * Slugs of the paid levels a user holds (the current visitor by default),
 * including levels held through another ("gold" includes "silver"). Level
 * slugs only, never Shopify tags.
 *
 * @param int|WP_User|null $user
 * @return string[]
 */
function redeemid_user_levels( int|\WP_User|null $user = null ): array {
	return \RedeemID\Access\Reader::levels( $user );
}

/**
 * The meter as a user sees it (the current visitor by default): whether a
 * meter is on, how many locked items they have opened this period, the
 * limit, and the period ("month" or "week"). Guests and staff read 0 used.
 *
 * @param int|WP_User|null $user
 * @return array{on: bool, used: int, limit: int, period: string}
 */
function redeemid_meter( int|\WP_User|null $user = null ): array {
	$user_id = $user instanceof \WP_User ? $user->ID : ( null === $user ? get_current_user_id() : (int) $user );

	return \RedeemID\Access\Meter::state( $user_id );
}

/**
 * A member's preferences: the customer metafields the site owner lets
 * members see or change (RedeemID → Settings → Member preferences), with
 * their current values read from Shopify. Each field carries key
 * (namespace.key), name, description, type, choices, min, max, editable
 * and value. No user: the current visitor. A visitor who isn't a linked
 * Shopify customer gets a WP_Error; a site with no fields chosen, [].
 *
 * @param int|WP_User|null $user
 * @return array|WP_Error
 */
function redeemid_preferences( int|\WP_User|null $user = null ): array|\WP_Error {
	$user_id = $user instanceof \WP_User ? $user->ID : ( null === $user ? get_current_user_id() : (int) $user );

	return \RedeemID\Preferences\Preferences::read( $user_id );
}

/**
 * Save a member's own preferences to their Shopify customer. $values maps
 * namespace.key to a value: a string, a list of strings, a bool, a number or
 * a YYYY-MM-DD date, as the field's type needs. An empty value clears the
 * field. Only fields set to "Members can change" are accepted, and every
 * value is checked against the field's Shopify definition first. Returns the
 * fields as redeemid_preferences() does, or a WP_Error whose data lists the
 * refused fields. No user: the current visitor.
 *
 * Call it from a forms plugin's submit hook to save answers to Shopify.
 *
 * @param array<string, mixed> $values
 * @param int|WP_User|null     $user
 * @return array|WP_Error
 */
function redeemid_set_preferences( array $values, int|\WP_User|null $user = null ): array|\WP_Error {
	$user_id = $user instanceof \WP_User ? $user->ID : ( null === $user ? get_current_user_id() : (int) $user );

	return \RedeemID\Preferences\Preferences::write( $user_id, $values );
}
