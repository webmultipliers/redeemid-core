<?php
/**
 * Global functions, for Blockstudio features that call functions by name.
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
function wp2_shopify_level_options(): array {
	return \WP2_Shopify\Blocks\Gate::level_options();
}
