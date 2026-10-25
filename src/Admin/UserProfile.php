<?php

namespace RedeemID\Admin;

use RedeemID\Sync\Mirror;

/**
 * The per-user Shopify card on the profile screen. Shows both ID
 * forms and the mirror; never tokens.
 */
final class UserProfile {
	public static function register(): void {
		add_action( 'show_user_profile', [ self::class, 'render' ] );
		add_action( 'edit_user_profile', [ self::class, 'render' ] );
	}

	public static function render( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) || ! current_user_can( 'list_users' ) ) {
			return;
		}

		$id   = Mirror::customer_id( $user->ID );
		$rows = self::rows( $user );
		?>
		<h2><?php esc_html_e( 'Shopify', 'redeemid' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
			<?php endforeach; ?>
		</table>
		<?php if ( $id && current_user_can( Menu::CAP ) ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( Menu::url( Customers::PAGE, [ 'user' => $user->ID ] ) ); ?>"><?php esc_html_e( 'Manage in RedeemID → Customers', 'redeemid' ); ?></a>
				<a class="button" href="<?php echo esc_url( Customers::action_url( 'sync', $user->ID, true ) ); ?>"><?php esc_html_e( 'Sync now', 'redeemid' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * The mirror as label => value pairs; never tokens.
	 *
	 * @return array<string, string>
	 */
	public static function rows( \WP_User $user ): array {
		$id      = Mirror::customer_id( $user->ID );
		$summary = get_user_meta( $user->ID, Mirror::SUMMARY, true );
		$last    = (int) get_user_meta( $user->ID, Mirror::LAST_SYNC, true );

		return [
			__( 'Status', 'redeemid' )                 => $id ? __( 'Linked', 'redeemid' ) : __( 'Not linked', 'redeemid' ),
			__( 'Customer GID', 'redeemid' )           => $id ? $id->gid() : '—',
			__( 'Customer ID', 'redeemid' )            => $id ? $id->numeric : '—',
			__( 'Shopify email', 'redeemid' )          => (string) get_user_meta( $user->ID, Mirror::EMAIL, true ) ?: '—',
			__( 'Account state', 'redeemid' )          => (string) get_user_meta( $user->ID, Mirror::STATUS, true ) ?: '—',
			__( 'Tags', 'redeemid' )                   => implode( ', ', Mirror::tags( $user->ID ) ) ?: '—',
			__( 'Email consent', 'redeemid' )          => (string) get_user_meta( $user->ID, Mirror::EMAIL_OPTIN, true ) ?: '—',
			__( 'SMS consent', 'redeemid' )            => (string) get_user_meta( $user->ID, Mirror::SMS_OPTIN, true ) ?: '—',
			__( 'Orders / spent', 'redeemid' )         => is_array( $summary ) ? trim( ( $summary['orders_count'] ?? '—' ) . ' / ' . ( $summary['amount_spent'] ?? '—' ) . ' ' . ( $summary['currency'] ?? '' ) ) : '—',
			__( 'Last sync', 'redeemid' )              => $last ? wp_date( 'Y-m-d H:i', $last ) . ' (' . get_user_meta( $user->ID, Mirror::SYNC_SOURCE, true ) . ')' : '—',
			__( 'Last webhook', 'redeemid' )           => (string) get_user_meta( $user->ID, Mirror::LAST_EVENT, true ) ?: '—',
			__( 'Active Shopify session', 'redeemid' ) => self::has_session( $user->ID ) ? __( 'Yes', 'redeemid' ) : __( 'No', 'redeemid' ),
		];
	}

	private static function has_session( int $user_id ): bool {
		foreach ( (array) get_user_meta( $user_id, 'session_tokens', true ) as $session ) {
			if ( is_array( $session ) && ! empty( $session['redeemid'] ) && ( $session['expiration'] ?? 0 ) > time() ) {
				return true;
			}
		}

		return false;
	}
}
