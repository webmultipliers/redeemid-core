<?php

namespace RedeemID\Admin;

use RedeemID\Auth\Linking;
use RedeemID\Shopify\Admin as AdminApi;
use RedeemID\Support\CustomerId;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;
use RedeemID\Sync\Reconciler;

/**
 * Staff link a WordPress user to a Shopify customer by hand, for the member
 * whose WordPress and Shopify emails differ and who can't link by signing in
 * (Linking::email_check refuses that).
 *
 * The link is the same as one made at sign-in: access still comes only from
 * the tags Shopify holds. When the emails differ the account is flagged
 * (Mirror::FLAG): the flag records who linked it, when, and both emails, and
 * shows on Customers until staff clear it. Unlinking removes it.
 *
 * Only staff who may edit the user (edit_user) can link them: on multisite
 * that is a super admin, since a site admin can't edit network users.
 */
final class StaffLink {
	public const DO_LINK  = 'staff_link';
	public const DO_CLEAR = 'clear_flag';

	/**
	 * Whether staff may link this pair, and whether the link is flagged. Pure.
	 *
	 * @param int|null $holder   The user already linked to this customer, if any.
	 * @param bool     $may_edit Whether the staff member may edit this user's account at all.
	 * @return array{error: string, flag: bool}
	 */
	public static function check( int $user_id, bool $privileged, bool $already_linked, ?int $holder, string $wp_email, string $shopify_email, bool $may_edit = true ): array {
		$error = match ( true ) {
			$user_id <= 0          => 'staff_link_no_user',
			$privileged            => 'staff_link_privileged',
			! $may_edit            => 'staff_link_not_allowed',
			$already_linked        => 'staff_link_already',
			null !== $holder && $holder !== $user_id => 'staff_link_taken',
			'' === $shopify_email  => 'staff_link_no_customer',
			default                => '',
		};

		return [
			'error' => $error,
			'flag'  => '' === $error && strtolower( trim( $wp_email ) ) !== strtolower( trim( $shopify_email ) ),
		];
	}

	/**
	 * Link from the Customers screen's form. Returns a result code for the notice.
	 */
	public static function link( string $who, string $customer ): string {
		$user = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
		$id   = CustomerId::parse( $customer );

		if ( ! $id ) {
			return 'staff_link_no_customer';
		}

		if ( ! AdminApi::available() ) {
			return 'no_admin';
		}

		$email = $user ? AdminApi::customer_email( $id ) : '';

		if ( is_wp_error( $email ) ) {
			return 'sync_failed';
		}

		$holder = Mirror::find_user( $id );
		$check  = self::check(
			$user ? $user->ID : 0,
			$user && Linking::privileged( $user ),
			$user && null !== Mirror::customer_id( $user->ID ),
			$holder ? $holder->ID : null,
			$user ? $user->user_email : '',
			(string) $email,
			// A link lets whoever holds the Shopify customer sign in as this
			// user, so only staff who could edit the account anyway may make it.
			// On multisite that leaves out site admins (users are network-wide).
			$user && current_user_can( 'edit_user', $user->ID )
		);

		if ( '' !== $check['error'] ) {
			return $check['error'];
		}

		Mirror::link( $user->ID, $id );

		if ( $check['flag'] ) {
			update_user_meta(
				$user->ID,
				Mirror::FLAG,
				[
					'reason'        => 'email_mismatch',
					'by'            => get_current_user_id(),
					'at'            => time(),
					'wp_email'      => $user->user_email,
					'shopify_email' => (string) $email,
				]
			);
		}

		Logger::log( 'admin.staff_linked', [ 'user' => $user->ID, 'customer' => $id->numeric, 'flagged' => $check['flag'] ? 'yes' : 'no' ] );
		Reconciler::sync_user( $user->ID, 'staff_link' );

		return $check['flag'] ? 'staff_linked_flagged' : 'staff_linked';
	}

	public static function clear( int $user_id ): string {
		if ( ! get_user_meta( $user_id, Mirror::FLAG, true ) ) {
			return 'none';
		}

		delete_user_meta( $user_id, Mirror::FLAG );
		Logger::log( 'admin.flag_cleared', [ 'user' => $user_id ] );

		return 'flag_cleared';
	}

	/**
	 * @return array{reason: string, by: int, at: int, wp_email: string, shopify_email: string}|null
	 */
	public static function flag( int $user_id ): ?array {
		$flag = get_user_meta( $user_id, Mirror::FLAG, true );

		return is_array( $flag ) ? $flag + [ 'reason' => '', 'by' => 0, 'at' => 0, 'wp_email' => '', 'shopify_email' => '' ] : null;
	}

	/**
	 * The panel on the Customers list.
	 */
	public static function panel(): void {
		Menu::panel_start( __( 'Link a user by hand', 'redeemid' ), __( 'For a member whose WordPress and Shopify emails differ, so signing in can\'t link them. The account is flagged until you clear it. Staff accounts can\'t be linked.', 'redeemid' ) );
		?>
		<form class="redeemid-panel__body" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Customers::ACTION ); ?>">
			<input type="hidden" name="do" value="<?php echo esc_attr( self::DO_LINK ); ?>">
			<?php wp_nonce_field( Customers::ACTION ); ?>
			<p>
				<label for="redeemid-link-user"><?php esc_html_e( 'WordPress username or email', 'redeemid' ); ?></label><br>
				<input type="text" id="redeemid-link-user" name="link_user" class="regular-text" required>
			</p>
			<p>
				<label for="redeemid-link-customer"><?php esc_html_e( 'Shopify customer ID', 'redeemid' ); ?></label><br>
				<input type="text" id="redeemid-link-customer" name="link_customer" class="regular-text code" placeholder="1234567890" required>
				<span class="description"><?php esc_html_e( 'The number at the end of the customer\'s page in the Shopify admin.', 'redeemid' ); ?></span>
			</p>
			<?php submit_button( __( 'Link', 'redeemid' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		Menu::panel_end();
	}
}
