<?php

namespace RedeemID\Privacy;

use RedeemID\Auth\Flow;
use RedeemID\Support\Logger;

/**
 * "Download my data" and "Delete my data" on the Member Account block. Each
 * creates a standard WordPress request for the signed-in account's email,
 * which the person confirms from the email WordPress sends; staff then
 * handle it in Tools like any other request.
 */
final class SelfService {
	public const ACTION = 'redeemid_privacy_request';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * The WordPress request type for a form's type field, or null. Pure.
	 */
	public static function action_for( string $type ): ?string {
		return match ( $type ) {
			'export' => Requests::EXPORT,
			'erase'  => Requests::ERASE,
			default  => null,
		};
	}

	public static function handle(): never {
		check_admin_referer( self::ACTION );

		$user   = wp_get_current_user();
		$action = self::action_for( sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ) );
		$back   = isset( $_POST['redirect_to'] ) ? Flow::safe_redirect( sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) ) : Flow::safe_redirect( (string) wp_get_referer() );

		self::back( $back, $user->exists() && $action ? self::create( $user, $action ) : 'privacy_failed' );
	}

	private static function create( \WP_User $user, string $action ): string {
		$id = wp_create_user_request( $user->user_email, $action );

		if ( is_wp_error( $id ) ) {
			return 'duplicate_request' === $id->get_error_code() ? 'privacy_pending' : 'privacy_failed';
		}

		update_post_meta( $id, Requests::SOURCE, 'member' );

		$sent = wp_send_user_request( $id );

		Logger::log( 'privacy.request_created', [ 'source' => 'member', 'label' => $action, 'request' => $id, 'status' => is_wp_error( $sent ) ? $sent->get_error_code() : 'ok' ] );

		if ( is_wp_error( $sent ) ) {
			return 'privacy_received'; // The request stands; staff can resend the confirmation from Tools.
		}

		return Requests::EXPORT === $action ? 'privacy_export_sent' : 'privacy_erase_sent';
	}

	private static function back( string $url, string $code ): never {
		wp_safe_redirect( add_query_arg( 'redeemid_notice', $code, $url ) );
		exit;
	}
}
