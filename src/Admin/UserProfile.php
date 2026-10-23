<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Auth\TokenVault;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Sync\Reconciler;

/**
 * The per-user Shopify card on the profile screen (spec 14.2). Shows both ID
 * forms and the mirror; never tokens.
 */
final class UserProfile {
	public static function register(): void {
		add_action( 'show_user_profile', [ self::class, 'render' ] );
		add_action( 'edit_user_profile', [ self::class, 'render' ] );
		add_action( 'admin_post_wp2_shopify_admin_user', [ self::class, 'action' ] );
	}

	public static function render( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) || ! current_user_can( 'list_users' ) ) {
			return;
		}

		$id      = Mirror::customer_id( $user->ID );
		$summary = get_user_meta( $user->ID, Mirror::SUMMARY, true );
		$last    = (int) get_user_meta( $user->ID, Mirror::LAST_SYNC, true );
		$rows    = [
			__( 'Status', 'wp2-shopify' )                => $id ? __( 'Linked', 'wp2-shopify' ) : __( 'Not linked', 'wp2-shopify' ),
			__( 'Customer GID', 'wp2-shopify' )          => $id ? $id->gid() : '—',
			__( 'Customer ID', 'wp2-shopify' )           => $id ? $id->numeric : '—',
			__( 'Account state', 'wp2-shopify' )         => (string) get_user_meta( $user->ID, Mirror::STATUS, true ) ?: '—',
			__( 'Tags', 'wp2-shopify' )                  => implode( ', ', Mirror::tags( $user->ID ) ) ?: '—',
			__( 'Email consent', 'wp2-shopify' )         => (string) get_user_meta( $user->ID, Mirror::EMAIL_OPTIN, true ) ?: '—',
			__( 'SMS consent', 'wp2-shopify' )           => (string) get_user_meta( $user->ID, Mirror::SMS_OPTIN, true ) ?: '—',
			__( 'Orders / spent', 'wp2-shopify' )        => is_array( $summary ) ? trim( ( $summary['orders_count'] ?? '—' ) . ' / ' . ( $summary['amount_spent'] ?? '—' ) . ' ' . ( $summary['currency'] ?? '' ) ) : '—',
			__( 'Last sync', 'wp2-shopify' )             => $last ? wp_date( 'Y-m-d H:i', $last ) . ' (' . get_user_meta( $user->ID, Mirror::SYNC_SOURCE, true ) . ')' : '—',
			__( 'Active Shopify session', 'wp2-shopify' ) => self::has_session( $user->ID ) ? __( 'Yes', 'wp2-shopify' ) : __( 'No', 'wp2-shopify' ),
		];
		?>
		<h2><?php esc_html_e( 'Shopify', 'wp2-shopify' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
			<?php endforeach; ?>
		</table>
		<?php if ( $id && current_user_can( 'manage_options' ) ) : ?>
			<p>
				<?php foreach ( [ 'sync' => __( 'Sync now', 'wp2-shopify' ), 'unlink' => __( 'Disconnect from Shopify', 'wp2-shopify' ) ] as $do => $label ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'wp2_shopify_admin_user', 'do' => $do, 'user' => $user->ID ], admin_url( 'admin-post.php' ) ), 'wp2_shopify_admin_user_' . $user->ID ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function action(): void {
		$user_id = (int) ( $_GET['user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'wp2_shopify_admin_user_' . $user_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wp2-shopify' ), 403 );
		}

		$do = sanitize_key( wp_unslash( $_GET['do'] ?? '' ) );

		if ( 'sync' === $do ) {
			Reconciler::sync_user( $user_id, 'admin' );
		} elseif ( 'unlink' === $do ) {
			TokenVault::forget_all( $user_id );
			Mirror::unlink( $user_id );
		}

		wp_safe_redirect( get_edit_user_link( $user_id ) );
		exit;
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
