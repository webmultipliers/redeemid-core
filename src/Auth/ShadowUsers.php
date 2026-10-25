<?php

namespace RedeemID\Auth;

use RedeemID\Support\CustomerId;
use RedeemID\Sync\Mirror;

/**
 * Shadow WordPress users for Shopify customers: keyed by the
 * customer ID, role shopify_customer, no password reset, no admin bar, no
 * wp-admin, hidden from the standard user list. No email matching happens at
 * sign-in; linking an existing account is the explicit flow in Linking.
 */
final class ShadowUsers {
	public const ROLE          = 'shopify_customer';
	public const EMAIL_DOMAIN  = 'shadow.redeemid.invalid';
	private const LEGACY_EMAIL = '@shadow.shopify-identity.local';

	public static function register(): void {
		add_filter( 'allow_password_reset', [ self::class, 'block_password_reset' ], 10, 2 );
		add_action( 'admin_init', [ self::class, 'keep_out_of_admin' ] );
		add_action( 'after_setup_theme', [ self::class, 'hide_admin_bar' ] );
		add_action( 'pre_get_users', [ self::class, 'hide_from_user_list' ] );
		add_action( 'template_redirect', [ self::class, 'no_author_archives' ] );
		add_filter( 'wp_mail', [ self::class, 'route_mail' ] );
	}

	public static function ensure_role(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Shopify Customer', 'redeemid' ), [ 'read' => true ] );
		}
	}

	public static function is_shadow( int $user_id ): bool {
		$user = get_userdata( $user_id );

		return $user && in_array( self::ROLE, (array) $user->roles, true );
	}

	public static function create( CustomerId $id, array $identity ): \WP_User|\WP_Error {
		$hash    = substr( hash( 'sha256', $id->gid() ), 0, 32 );
		$first   = sanitize_text_field( $identity['first_name'] ?? '' );
		$last    = sanitize_text_field( $identity['last_name'] ?? '' );
		$user_id = wp_insert_user(
			[
				'user_login'   => 'shopify_' . $hash,
				'user_email'   => 'c' . $hash . '@' . self::EMAIL_DOMAIN,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ) ?: __( 'Shopify customer', 'redeemid' ),
				'role'         => self::ROLE,
			]
		);

		if ( is_wp_error( $user_id ) ) {
			// A concurrent callback may have created it a moment ago.
			$existing = Mirror::find_user( $id ) ?? get_user_by( 'login', 'shopify_' . $hash );

			return $existing ?: $user_id;
		}

		Mirror::link( $user_id, $id );

		return get_user_by( 'id', $user_id );
	}

	public static function block_password_reset( $allow, $user_id ) {
		return self::is_shadow( (int) $user_id )
			? new \WP_Error( 'redeemid_no_reset', __( 'This account signs in with Shopify. Manage your sign-in from your Shopify account.', 'redeemid' ) )
			: $allow;
	}

	public static function keep_out_of_admin(): void {
		global $pagenow;

		// admin-ajax.php and admin-post.php carry front-end actions (status, logout, sync) and must stay reachable.
		if ( wp_doing_ajax() || 'admin-post.php' === $pagenow || ! self::is_shadow( get_current_user_id() ) || current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_safe_redirect( (string) apply_filters( 'redeemid_customer_admin_redirect', home_url( '/' ) ) );
		exit;
	}

	public static function hide_admin_bar(): void {
		if ( self::is_shadow( get_current_user_id() ) && ! current_user_can( 'edit_posts' ) ) {
			show_admin_bar( false ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval -- shadow customers only, never editors or admins.
		}
	}

	public static function hide_from_user_list( \WP_User_Query $query ): void {
		global $pagenow;

		if ( ! is_admin() || 'users.php' !== $pagenow || isset( $_GET['role'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$excluded   = (array) $query->get( 'role__not_in' );
		$excluded[] = self::ROLE;
		$query->set( 'role__not_in', array_values( array_unique( array_filter( $excluded ) ) ) );
	}

	public static function no_author_archives(): void {
		$author = is_author() ? get_queried_object() : null;

		if ( $author instanceof \WP_User && in_array( self::ROLE, (array) $author->roles, true ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
		}
	}

	/**
	 * Shadow addresses are undeliverable by design; send to the Shopify email
	 * from the mirror instead, or drop the recipient.
	 */
	public static function route_mail( array $args ): array {
		$to  = is_array( $args['to'] ) ? $args['to'] : explode( ',', (string) $args['to'] );
		$out = [];

		foreach ( $to as $address ) {
			$address = trim( (string) $address );

			if ( ! str_ends_with( $address, '@' . self::EMAIL_DOMAIN ) && ! str_ends_with( $address, self::LEGACY_EMAIL ) ) {
				$out[] = $address;
				continue;
			}

			$user  = get_user_by( 'email', $address );
			$email = $user ? (string) get_user_meta( $user->ID, Mirror::EMAIL, true ) : '';

			if ( is_email( $email ) ) {
				$out[] = $email;
			}
		}

		$args['to'] = $out;

		return $args;
	}
}
