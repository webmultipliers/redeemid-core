<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Sync\Mirror;

/**
 * The per-user Shopify card on the profile screen (spec 14.2). Shows both ID
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
		<h2><?php esc_html_e( 'Shopify', 'wp2-shopify' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
			<?php endforeach; ?>
		</table>
		<?php if ( $id && current_user_can( Menu::CAP ) ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( Menu::url( Customers::PAGE, [ 'user' => $user->ID ] ) ); ?>"><?php esc_html_e( 'Manage in Shopify → Customers', 'wp2-shopify' ); ?></a>
				<a class="button" href="<?php echo esc_url( Customers::action_url( 'sync', $user->ID, true ) ); ?>"><?php esc_html_e( 'Sync now', 'wp2-shopify' ); ?></a>
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
			__( 'Status', 'wp2-shopify' )                 => $id ? __( 'Linked', 'wp2-shopify' ) : __( 'Not linked', 'wp2-shopify' ),
			__( 'Customer GID', 'wp2-shopify' )           => $id ? $id->gid() : '—',
			__( 'Customer ID', 'wp2-shopify' )            => $id ? $id->numeric : '—',
			__( 'Shopify email', 'wp2-shopify' )          => (string) get_user_meta( $user->ID, Mirror::EMAIL, true ) ?: '—',
			__( 'Account state', 'wp2-shopify' )          => (string) get_user_meta( $user->ID, Mirror::STATUS, true ) ?: '—',
			__( 'Tags', 'wp2-shopify' )                   => implode( ', ', Mirror::tags( $user->ID ) ) ?: '—',
			__( 'Email consent', 'wp2-shopify' )          => (string) get_user_meta( $user->ID, Mirror::EMAIL_OPTIN, true ) ?: '—',
			__( 'SMS consent', 'wp2-shopify' )            => (string) get_user_meta( $user->ID, Mirror::SMS_OPTIN, true ) ?: '—',
			__( 'Orders / spent', 'wp2-shopify' )         => is_array( $summary ) ? trim( ( $summary['orders_count'] ?? '—' ) . ' / ' . ( $summary['amount_spent'] ?? '—' ) . ' ' . ( $summary['currency'] ?? '' ) ) : '—',
			__( 'Last sync', 'wp2-shopify' )              => $last ? wp_date( 'Y-m-d H:i', $last ) . ' (' . get_user_meta( $user->ID, Mirror::SYNC_SOURCE, true ) . ')' : '—',
			__( 'Last webhook', 'wp2-shopify' )           => (string) get_user_meta( $user->ID, Mirror::LAST_EVENT, true ) ?: '—',
			__( 'Active Shopify session', 'wp2-shopify' ) => self::has_session( $user->ID ) ? __( 'Yes', 'wp2-shopify' ) : __( 'No', 'wp2-shopify' ),
		];
	}

	private static function has_session( int $user_id ): bool {
		foreach ( (array) get_user_meta( $user_id, 'session_tokens', true ) as $session ) {
			if ( is_array( $session ) && ! empty( $session['wp2_shopify'] ) && ( $session['expiration'] ?? 0 ) > time() ) {
				return true;
			}
		}

		return false;
	}
}
