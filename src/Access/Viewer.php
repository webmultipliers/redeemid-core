<?php

namespace RedeemID\Access;

use RedeemID\Entitlement\Member;
use RedeemID\Sync\Mirror;

/**
 * Who the gates decide for on this request.
 *
 * Normally the signed-in visitor's synced mirror. Two exceptions, both for
 * people with the redeemid_bypass_gates capability (administrators and editors
 * unless a role says otherwise):
 *
 * - Bypass: on an item's own page, and only there, they read the whole item.
 *   Never in loops, feeds, search, REST, embeds or GraphQL, so what they see
 *   anywhere else is what visitors see.
 * - Preview as: ?redeemid_preview_as=locked or =<level> makes this one request
 *   decide as a locked visitor or as a member holding that level, with no
 *   bypass. Nothing is stored: no cookie, no option, no user meta. The admin
 *   bar shows which view is on, with links to switch and to leave it.
 */
final class Viewer {
	public const CAP    = 'redeemid_bypass_gates';
	public const PARAM  = 'redeemid_preview_as';
	public const LOCKED = 'locked';

	public static function register(): void {
		add_filter( 'user_has_cap', [ self::class, 'grant_cap' ], 10, 1 );
		add_action( 'admin_bar_menu', [ self::class, 'admin_bar' ], 90 );
	}

	/**
	 * Editors and administrators bypass by default. A role or user that sets
	 * the capability itself, either way, keeps its own setting.
	 */
	public static function grant_cap( mixed $allcaps ): mixed {
		if ( is_array( $allcaps ) && ! array_key_exists( self::CAP, $allcaps ) && ! empty( $allcaps['edit_others_posts'] ) ) {
			$allcaps[ self::CAP ] = true;
		}

		return $allcaps;
	}

	public static function can_bypass(): bool {
		return is_user_logged_in() && current_user_can( self::CAP );
	}

	/**
	 * The view requested with ?redeemid_preview_as=, or null. Only people who could
	 * bypass may ask for one; for anyone else the parameter means nothing.
	 */
	public static function preview_as(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only and only ever narrows what this request shows.
		$asked = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : '';

		if ( '' === $asked || ! self::can_bypass() ) {
			return null;
		}

		return self::LOCKED === $asked || LevelStore::levels()->has( $asked ) ? $asked : self::LOCKED;
	}

	/**
	 * Does this person read $post in full, because it is the item this page is about?
	 */
	public static function bypassing( \WP_Post $post ): bool {
		if ( null !== self::preview_as() || ! self::can_bypass() ) {
			return false;
		}

		if ( wp_is_serving_rest_request() || is_feed() || is_embed() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'GRAPHQL_REQUEST' ) && GRAPHQL_REQUEST ) ) {
			return false;
		}

		return ( is_singular() || is_preview() ) && (int) get_queried_object_id() === (int) $post->ID;
	}

	/**
	 * The member the gates decide for.
	 */
	public static function member(): Member {
		$as = self::preview_as();

		if ( null === $as ) {
			return Mirror::member( get_current_user_id() );
		}

		$level = LevelStore::levels()->get( $as );

		return $level ? self::member_for( $level, time() ) : Member::guest();
	}

	/**
	 * A synced, fresh member holding exactly the tags that grant $level. Pure.
	 */
	public static function member_for( Level $level, int $now ): Member {
		if ( Level::PUBLIC === $level->slug ) {
			return Member::guest();
		}

		return new Member( signed_in: true, linked: true, tags: $level->tags(), last_sync: $now );
	}

	/**
	 * A key for caching decisions: the user, and the preview view if any.
	 */
	public static function key(): string {
		return get_current_user_id() . ':' . ( self::preview_as() ?? '' );
	}

	public static function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! self::can_bypass() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || ! Gatekeeper::protected( $post ) ) {
			return;
		}

		$as     = self::preview_as();
		$levels = LevelStore::levels();
		$base   = remove_query_arg( self::PARAM );
		$title  = null === $as
			? __( 'Access: full view (bypass)', 'redeemid' )
			/* translators: %s: a level label, or "a locked visitor" */
			: sprintf( __( 'Access: viewing as %s', 'redeemid' ), self::LOCKED === $as ? __( 'a locked visitor', 'redeemid' ) : ( $levels->get( $as )?->label ?? $as ) );

		$bar->add_node( [ 'id' => 'redeemid-access', 'title' => esc_html( $title ), 'href' => null === $as ? add_query_arg( self::PARAM, self::LOCKED, $base ) : $base ] );

		if ( null !== $as ) {
			$bar->add_node( [ 'parent' => 'redeemid-access', 'id' => 'redeemid-access-exit', 'title' => esc_html__( 'Exit preview', 'redeemid' ), 'href' => $base ] );
		}

		$bar->add_node( [ 'parent' => 'redeemid-access', 'id' => 'redeemid-access-locked', 'title' => esc_html__( 'View as a locked visitor', 'redeemid' ), 'href' => add_query_arg( self::PARAM, self::LOCKED, $base ) ] );

		foreach ( $levels->all() as $slug => $level ) {
			if ( ! $level->builtin() ) {
				/* translators: %s: level label */
				$bar->add_node( [ 'parent' => 'redeemid-access', 'id' => 'redeemid-access-' . $slug, 'title' => esc_html( sprintf( __( 'View as %s', 'redeemid' ), $level->label ) ), 'href' => add_query_arg( self::PARAM, $slug, $base ) ] );
			}
		}
	}
}
