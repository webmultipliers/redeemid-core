<?php

namespace RedeemID\Features;

use RedeemID\Rest\Routes;

/**
 * Where features reach templates and front ends, all decided on the server
 * by Registry::has() for the current visitor in the same request:
 *
 * - (a) PHP: redeemid_has_feature() / redeemid_granted_features();
 * - (b) block visibility: Access\BlockAccess (a feature setting on every block);
 * - (c) body signal: a redeemid-feature-<slug> class for granted features that
 *   declare "body". It names the feature, never the tag that granted it;
 * - (d) REST: GET redeemid/v1/features and the redeemid_features field on
 *   /wp/v2/users/me, current user only, features that declare "rest";
 * - (e) shortcode: [redeemid_feature slug="..."]...[/redeemid_feature], with not="1"
 *   for "only when not held";
 * - JS signal: window.redeemidFeatures, slug => boolean, only for
 *   features registered with js => true. Presentation only: it can be
 *   flipped in dev tools, so it never unlocks anything.
 */
final class Surfaces {
	public const SHORTCODE = 'redeemid_feature';
	public const FIELD     = 'redeemid_features';

	public static function register(): void {
		add_action( 'template_redirect', [ self::class, 'prepare' ], 2 );
		add_filter( 'body_class', [ self::class, 'body_class' ] );
		add_action( 'wp_head', [ self::class, 'js_signal' ], 1 );
		add_action( 'rest_api_init', [ self::class, 'rest' ] );
		add_shortcode( self::SHORTCODE, [ self::class, 'shortcode' ] );
	}

	/**
	 * Before any output: resolve the visitor's set if the page will carry a
	 * body or JS signal, so the cache rule is applied while headers can still
	 * be sent.
	 */
	public static function prepare(): void {
		Cache::vary_cookie();

		foreach ( Registry::features() as $slug => $feature ) {
			if ( $feature->uses( Feature::BODY ) || $feature->js ) {
				Registry::has( $slug );
			}

			// The JS object is printed into the page, so a signed-in visitor's page is never shared, whatever the feature declares.
			if ( $feature->js && is_user_logged_in() ) {
				Cache::private_page();
			}
		}
	}

	/**
	 * Classes for granted features that declare the body signal. Pure.
	 *
	 * @param array<string, Feature> $features
	 * @param string[]               $granted
	 * @return string[]
	 */
	public static function classes( array $features, array $granted ): array {
		$classes = [];

		foreach ( $features as $slug => $feature ) {
			if ( $feature->uses( Feature::BODY ) && in_array( $slug, $granted, true ) ) {
				$classes[] = 'redeemid-feature-' . Feature::css( $slug );
			}
		}

		return $classes;
	}

	public static function body_class( mixed $classes ): mixed {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}

		$granted = array_keys( array_filter( Registry::features(), static fn( Feature $f ) => $f->uses( Feature::BODY ) && Registry::has( $f->slug ) ) );

		return array_merge( $classes, self::classes( Registry::features(), $granted ) );
	}

	/**
	 * slug => bool for features registered with js => true. Pure.
	 *
	 * @param array<string, Feature> $features
	 * @param callable(string): bool $has
	 * @return array<string, bool>
	 */
	public static function js_values( array $features, callable $has ): array {
		$out = [];

		foreach ( $features as $slug => $feature ) {
			if ( $feature->js ) {
				$out[ $slug ] = $has( $slug );
			}
		}

		return $out;
	}

	public static function js_signal(): void {
		$values = self::js_values( Registry::features(), [ Registry::class, 'has' ] );

		if ( $values ) {
			wp_print_inline_script_tag( 'window.redeemidFeatures = Object.freeze(' . wp_json_encode( (object) $values ) . ');' );
		}
	}

	/**
	 * Granted features that declare the REST surface, for the current user.
	 * Guests get the Public ones, if any.
	 *
	 * @return string[]
	 */
	public static function rest_slugs(): array {
		return array_values( array_filter( array_keys( Registry::granted() ), static fn( $slug ) => (bool) Registry::get( $slug )?->uses( Feature::REST ) ) );
	}

	public static function rest(): void {
		register_rest_route(
			Routes::NS,
			'/features',
			[
				'methods'             => 'GET',
				'callback'            => static function () {
					$response = new \WP_REST_Response( [ 'features' => self::rest_slugs() ] );
					$response->header( 'Cache-Control', 'private, no-store, max-age=0' );

					return $response;
				},
				'permission_callback' => '__return_true', // The caller's own features only.
			]
		);

		register_rest_field(
			'user',
			self::FIELD,
			[
				// Only ever the current user's own set; null on any other user.
				'get_callback' => static fn( $user ) => (int) ( $user['id'] ?? 0 ) === get_current_user_id() && get_current_user_id() > 0 ? self::rest_slugs() : null,
				'schema'       => [
					'description' => __( 'Feature slugs the current user holds. Present on your own user only.', 'redeemid' ),
					'type'        => [ 'array', 'null' ],
					'items'       => [ 'type' => 'string' ],
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			]
		);
	}

	/**
	 * [redeemid_feature slug="early-access"]Shown to holders[/redeemid_feature]
	 * [redeemid_feature slug="early-access" not="1"]Shown to everyone else[/redeemid_feature]
	 *
	 * Takes a registered slug only, never a condition.
	 */
	public static function shortcode( mixed $atts, ?string $content = null ): string {
		$atts = shortcode_atts( [ 'slug' => '', 'not' => '' ], is_array( $atts ) ? $atts : [], self::SHORTCODE );
		$not  = in_array( strtolower( (string) $atts['not'] ), [ '1', 'true', 'yes' ], true );
		$has  = Registry::has( (string) $atts['slug'] );

		return $has !== $not ? do_shortcode( (string) $content ) : '';
	}
}
