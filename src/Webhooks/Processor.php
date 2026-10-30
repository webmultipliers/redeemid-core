<?php

namespace RedeemID\Webhooks;

use RedeemID\Auth\ShadowUsers;
use RedeemID\Auth\TokenVault;
use RedeemID\Privacy\Eraser;
use RedeemID\Privacy\Requests;
use RedeemID\Shopify\Admin;
use RedeemID\Support\CustomerId;
use RedeemID\Support\EventLog;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;
use RedeemID\Sync\Reconciler;

/**
 * Applies a queued inbox row. Handlers are idempotent; transient failures are
 * retried with backoff, then dead-lettered.
 */
final class Processor {
	private const MAX_ATTEMPTS = 5;

	public static function process( int $row_id ): void {
		$store = new WpdbInboxStore();
		$row   = $store->get( $row_id );

		if ( ! $row || InboxStore::QUEUED !== $row['status'] ) {
			return;
		}

		$kind = Topics::kind( (string) $row['topic'] );

		try {
			$outcome = null === $kind ? new \WP_Error( 'unknown_topic' ) : self::apply( $kind, (string) $row['topic'], (array) $row['data'] );
		} catch ( \Throwable $e ) {
			$outcome = new \WP_Error( 'exception', get_class( $e ) );
		}

		if ( is_wp_error( $outcome ) ) {
			$attempts = (int) $row['attempts'] + 1;
			$retry    = $attempts < self::MAX_ATTEMPTS && 'unknown_topic' !== $outcome->get_error_code();

			$store->finish( $row_id, $retry ? InboxStore::QUEUED : InboxStore::DEAD, $outcome->get_error_code() );

			if ( $retry ) {
				Endpoint::schedule( $row_id, 60 * ( 2 ** $attempts ) );
			}

			Logger::log( 'webhook.failed', [ 'row' => $row_id, 'topic' => $row['topic'], 'error' => $outcome->get_error_code(), 'retry' => $retry ] );

			return;
		}

		$store->finish( $row_id, $outcome );

		// A redaction has just removed this customer's number from the logs: don't write it back.
		$named = ! in_array( (string) $row['topic'], [ 'customers/redact', 'customers/delete', 'shop/redact' ], true );

		Logger::log( 'webhook.processed', [ 'row' => $row_id, 'topic' => $row['topic'], 'status' => $outcome ] + ( $named ? [ 'customer' => (string) $row['customer'] ] : [] ) );
	}

	/**
	 * @return string|\WP_Error Final inbox status.
	 */
	private static function apply( string $kind, string $topic, array $data ): string|\WP_Error {
		$id   = CustomerId::parse( $data['customer'] ?? null );
		$user = $id ? Mirror::find_user( $id ) : null;

		switch ( $kind ) {
			case Topics::COMPLIANCE:
				return self::compliance( $topic, $user, $id, (string) ( $data['request'] ?? '' ) );

			case Topics::UNINSTALL:
				update_option( 'redeemid_app_uninstalled', time(), false );
				return InboxStore::PROCESSED;

			case Topics::MERGE:
				return self::merge( $data );
		}

		if ( ! $user ) {
			// A countable outcome, not a silent early return.
			Logger::log( 'webhook.unmatched', [ 'topic' => $topic, 'customer' => $id?->numeric ] );

			return InboxStore::UNMATCHED;
		}

		if ( in_array( $kind, [ Topics::PROFILE, Topics::STATUS ], true ) ) {
			// Ordered per kind: a profile edit must not hide an earlier disable.
			$key     = Topics::STATUS === $kind ? Mirror::STATUS_AT : Mirror::PROFILE_AT;
			$updated = (int) ( $data['updated_at'] ?? 0 );

			if ( Mirror::is_older( $updated, (int) get_user_meta( $user->ID, $key, true ) ) ) {
				return InboxStore::IGNORED;
			}

			if ( $updated > 0 ) {
				update_user_meta( $user->ID, $key, $updated );
			}
		}

		switch ( $kind ) {
			case Topics::PROFILE:
				Mirror::set_profile( $user->ID, $data );

				if ( isset( $data['tags'] ) ) {
					Mirror::set_tags( $user->ID, (array) $data['tags'] );
				}
				break;

			case Topics::STATUS:
				Mirror::set_status( $user->ID, (string) $data['status'] );

				if ( 'disabled' === $data['status'] ) {
					TokenVault::forget_all( $user->ID );

					// A Shopify-only account has no other way in: end its WordPress sessions too.
					if ( ShadowUsers::is_shadow( $user->ID ) ) {
						\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
					}
				}
				break;

			case Topics::TAGS:
				if ( Admin::available() ) {
					// Payload shape varies by version; re-read the authoritative tag list.
					$result = Reconciler::sync_user( $user->ID, 'webhook' );
					return is_wp_error( $result ) ? $result : InboxStore::PROCESSED;
				}

				$current = Mirror::tags( $user->ID );
				$change  = (array) ( $data['tags'] ?? [] );
				Mirror::set_tags(
					$user->ID,
					'added' === $data['change'] ? array_merge( $current, $change ) : array_udiff( $current, $change, 'strcasecmp' )
				);
				break;

			case Topics::CONSENT:
				if ( isset( $data['state'] ) ) {
					update_user_meta( $user->ID, 'email' === $data['channel'] ? Mirror::EMAIL_OPTIN : Mirror::SMS_OPTIN, sanitize_key( (string) $data['state'] ) );
				}
				break;

			case Topics::SUMMARY:
				update_user_meta(
					$user->ID,
					Mirror::SUMMARY,
					[
						'orders_count' => isset( $data['orders_count'] ) ? (int) $data['orders_count'] : null,
						'amount_spent' => isset( $data['amount_spent'] ) ? sanitize_text_field( (string) $data['amount_spent'] ) : null,
						'currency'     => isset( $data['currency'] ) ? sanitize_text_field( (string) $data['currency'] ) : null,
					]
				);
				break;

			case Topics::DELETE:
				self::erase( $user );
				return InboxStore::PROCESSED;
		}

		update_user_meta( $user->ID, Mirror::LAST_EVENT, $topic );
		Mirror::stamp( $user->ID, 'webhook' );

		return InboxStore::PROCESSED;
	}

	/**
	 * customers/delete and customers/redact actually remove data.
	 * Shadow users are deleted, with their content reassigned, not deleted.
	 * Linked regular accounts keep the WordPress account and lose
	 * every Shopify-linked field. The logs keep their rows without the person.
	 *
	 * @return bool Whether the WordPress account was kept.
	 */
	public static function erase( \WP_User $user ): bool {
		$shadow = ShadowUsers::is_shadow( $user->ID );

		Eraser::local( $user );

		if ( $shadow ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user->ID, self::reassign_target() );
		}

		Logger::log( 'customer.erased', [ 'user' => 0, 'status' => $shadow ? 'deleted' : 'kept' ] );

		return ! $shadow;
	}

	public static function reassign_target(): ?int {
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ] );

		/**
		 * Which user receives content authored by a deleted shadow user. Return null to delete the content.
		 */
		$target = apply_filters( 'redeemid_reassign_user', $admins ? (int) $admins[0] : null );

		return null === $target ? null : (int) $target;
	}

	/**
	 * Shopify's compliance topics. Each leaves a request in Tools → Export /
	 * Erase Personal Data, so staff handle every privacy request in one place.
	 */
	private static function compliance( string $topic, ?\WP_User $user, ?CustomerId $id, string $request ): string {
		switch ( $topic ) {
			case 'customers/redact':
				if ( $id ) {
					// Deliveries and events that name the customer, even one never linked here.
					Eraser::forget_logs( 0, $id->numeric );
				}

				if ( ! $user ) {
					return InboxStore::UNMATCHED;
				}

				$email = $user->user_email ?: (string) get_user_meta( $user->ID, Mirror::EMAIL, true );
				$kept  = self::erase( $user );
				Requests::record_redaction( $email, (string) $id?->numeric, $kept );

				return InboxStore::PROCESSED;

			case 'customers/data_request':
				if ( $user ) {
					$email = (string) get_user_meta( $user->ID, Mirror::EMAIL, true ) ?: $user->user_email;
					Requests::from_shopify_data_request( $email, (string) $id?->numeric, $request );
				}
				return $user ? InboxStore::PROCESSED : InboxStore::UNMATCHED;

			case 'shop/redact':
				foreach ( get_users( [ 'meta_key' => Mirror::NUMERIC, 'fields' => 'ID' ] ) as $user_id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
					TokenVault::forget_all( (int) $user_id );
					Mirror::unlink( (int) $user_id );
				}

				EventLog::anonymise_all_customers();
				( new WpdbInboxStore() )->anonymise( '' );

				return InboxStore::PROCESSED;
		}

		return InboxStore::IGNORED;
	}

	/**
	 * customers/merge: re-point the surviving customer, never create a duplicate shadow user.
	 */
	private static function merge( array $data ): string {
		$survivor = CustomerId::parse( $data['survivor'] ?? null );
		$merged   = CustomerId::parse( $data['merged'] ?? null );

		if ( ! $survivor || ! $merged ) {
			return InboxStore::UNMATCHED;
		}

		$merged_user   = Mirror::find_user( $merged );
		$survivor_user = Mirror::find_user( $survivor );

		if ( ! $merged_user ) {
			return InboxStore::UNMATCHED;
		}

		if ( $survivor_user ) {
			// Both had WordPress users: retire the merged-away link, keep the account.
			TokenVault::forget_all( $merged_user->ID );
			Mirror::unlink( $merged_user->ID );
			Logger::log( 'customer.merge_retired', [ 'user' => $merged_user->ID, 'survivor_user' => $survivor_user->ID ] );
		} else {
			Mirror::link( $merged_user->ID, $survivor );

			// The survivor's tags are not in the merge payload: read them, rather than mark the old ones fresh.
			if ( Admin::available() ) {
				Reconciler::sync_user( $merged_user->ID, 'webhook' );
			} else {
				Mirror::stamp( $merged_user->ID, 'webhook' );
			}
		}

		return InboxStore::PROCESSED;
	}
}
