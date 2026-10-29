<?php

namespace RedeemID\Access;

use RedeemID\Settings;
use RedeemID\Sync\Mirror;

/**
 * The public read API behind redeemid_can_read() and redeemid_user_levels(),
 * for plugins that build on RedeemID (a course, a forum, a download manager)
 * instead of running their own membership layer.
 *
 * can() answers with every layer the gate uses: the site default, post type,
 * terms, the item's own rule and time windows, archive windows and drip. It
 * never uses the editor bypass, and it never says why or which tags: callers
 * that need the reason use Explain. No user means the current visitor, as the
 * page would decide for them (a staff "preview as" included); a user ID means
 * that user's synced record.
 */
final class Reader {
	public static function can( \WP_Post $post, int|\WP_User|null $user = null, ?int $now = null ): bool {
		$now   ??= time();
		$user_id = null === $user ? get_current_user_id() : ( $user instanceof \WP_User ? (int) $user->ID : $user );

		if ( ! self::visible( $post, $user_id ) ) {
			return false;
		}

		$rule = Gatekeeper::rule( $post );

		if ( $rule->open() ) {
			return true;
		}

		$preview = null === $user ? Viewer::preview_as() : null;
		$member  = null === $user ? Viewer::member() : Mirror::member( $user_id );
		$checker = new Checker( LevelStore::levels(), Settings::evaluator() );

		if ( ! $checker->check( $rule, $member, $now )->granted() ) {
			return false;
		}

		// Drip narrows for real members only, as on the page.
		return null !== $preview || Drip::opens_for( $post, $user_id ) <= $now;
	}

	/**
	 * Slugs of the paid levels the user holds, directly or through an
	 * included level, in the order levels are defined. Never tags.
	 *
	 * @return string[]
	 */
	public static function levels( int|\WP_User|null $user = null ): array {
		$member = null === $user ? Viewer::member() : Mirror::member( $user instanceof \WP_User ? (int) $user->ID : $user );

		return Membership::held( LevelStore::levels(), $member, time() );
	}

	/**
	 * WordPress's own visibility first: drafts, private items and password
	 * protected items stay with the people WordPress lets edit them.
	 */
	public static function visible( \WP_Post $post, int $user_id ): bool {
		$public = 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) && '' === $post->post_password;

		return $public || ( $user_id > 0 && user_can( $user_id, 'edit_post', $post->ID ) ) || ( 'private' === $post->post_status && $user_id > 0 && user_can( $user_id, 'read_post', $post->ID ) );
	}
}
