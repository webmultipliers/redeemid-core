<?php

namespace RedeemID\Privacy;

use RedeemID\Access\Meter;
use RedeemID\Commerce\Cart;
use RedeemID\Privacy;
use RedeemID\Support\EventLog;
use RedeemID\Sync\Mirror;
use RedeemID\Sync\PostCheckout;
use RedeemID\Webhooks\WpdbInboxStore;

/**
 * The personal data exporter: the Shopify mirror on the user, the meter, the
 * checkout record and the cart on page 1, then the activity log and webhook
 * deliveries about the person, PER_PAGE rows of each per page.
 */
final class Exporter {
	public const PER_PAGE = 500;

	private static function label( string $key ): string {
		return match ( $key ) {
			Mirror::GID         => __( 'Shopify customer ID', 'redeemid' ),
			Mirror::NUMERIC     => __( 'Shopify customer number', 'redeemid' ),
			Mirror::EMAIL       => __( 'Shopify email', 'redeemid' ),
			Mirror::FIRST_NAME  => __( 'First name (Shopify)', 'redeemid' ),
			Mirror::LAST_NAME   => __( 'Last name (Shopify)', 'redeemid' ),
			Mirror::VERIFIED    => __( 'Email verified by Shopify', 'redeemid' ),
			Mirror::TAGS        => __( 'Shopify tags', 'redeemid' ),
			Mirror::STATUS      => __( 'Account status', 'redeemid' ),
			Mirror::STATUS_FROM => __( 'Account status came from', 'redeemid' ),
			Mirror::EMAIL_OPTIN => __( 'Email marketing consent', 'redeemid' ),
			Mirror::SMS_OPTIN   => __( 'SMS marketing consent', 'redeemid' ),
			Mirror::SUMMARY     => __( 'Purchasing summary', 'redeemid' ),
			Mirror::SINCE       => __( 'Member since', 'redeemid' ),
			Mirror::RENEWS      => __( 'Renews on', 'redeemid' ),
			Mirror::HISTORY     => __( 'Membership level history', 'redeemid' ),
			Mirror::LINKED_AT   => __( 'Linked to Shopify', 'redeemid' ),
			Mirror::LAST_SYNC   => __( 'Last synced', 'redeemid' ),
			Mirror::SYNC_SOURCE => __( 'Last synced by', 'redeemid' ),
			Mirror::SYNC_FAILED => __( 'Last failed sync', 'redeemid' ),
			Mirror::LAST_EVENT  => __( 'Last Shopify event', 'redeemid' ),
			Mirror::PROFILE_AT  => __( 'Profile last updated in Shopify', 'redeemid' ),
			Mirror::STATUS_AT   => __( 'Status last changed in Shopify', 'redeemid' ),
			default             => $key,
		};
	}

	/**
	 * A stored value as text: timestamps as dates, level history as one line per level.
	 */
	private static function value( string $key, mixed $value ): string {
		if ( in_array( $key, [ Mirror::LINKED_AT, Mirror::LAST_SYNC, Mirror::SYNC_FAILED, Mirror::PROFILE_AT, Mirror::STATUS_AT ], true ) && is_numeric( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value ) . ' UTC';
		}

		if ( Mirror::HISTORY === $key && is_array( $value ) ) {
			$lines = [];

			foreach ( $value as $slug => $entry ) {
				$since   = (int) ( $entry['since'] ?? 0 );
				$until   = (int) ( $entry['until'] ?? 0 );
				$lines[] = $slug . ': ' . ( $since ? gmdate( 'Y-m-d', $since ) : '?' ) . ( $until ? ' to ' . gmdate( 'Y-m-d', $until ) : '' );
			}

			return implode( '; ', $lines );
		}

		if ( is_array( $value ) ) {
			$parts = [];

			foreach ( $value as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$parts[] = is_int( $k ) ? (string) $v : $k . ': ' . $v;
				}
			}

			return implode( ', ', $parts );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	public static function export( string $email, int $page = 1 ): array {
		$user  = Privacy::user_for( $email );
		$page  = max( 1, $page );
		$items = [];

		if ( ! $user ) {
			return [ 'data' => [], 'done' => true ];
		}

		$customer = (string) get_user_meta( $user->ID, Mirror::NUMERIC, true );

		if ( 1 === $page ) {
			$profile = self::profile( $user->ID );

			if ( $profile ) {
				$items[] = [ 'group_id' => 'redeemid', 'group_label' => __( 'Shopify', 'redeemid' ), 'item_id' => 'shopify-' . $user->ID, 'data' => $profile ];
			}
		}

		$events = EventLog::for_person( $user->ID, $customer, self::PER_PAGE, $page );

		foreach ( $events as $row ) {
			$items[] = [
				'group_id'    => 'redeemid-events',
				'group_label' => __( 'RedeemID activity log', 'redeemid' ),
				'item_id'     => 'redeemid-event-' . (int) $row['id'],
				'data'        => [
					[ 'name' => __( 'When', 'redeemid' ), 'value' => gmdate( 'Y-m-d H:i:s', (int) $row['created_at'] ) . ' UTC' ],
					[ 'name' => __( 'Event', 'redeemid' ), 'value' => (string) $row['event'] ],
					[ 'name' => __( 'Detail', 'redeemid' ), 'value' => trim( $row['label'] . ' ' . $row['status'] ) ],
				],
			];
		}

		$hooks = ( new WpdbInboxStore() )->for_customer( $customer, self::PER_PAGE, $page );

		foreach ( $hooks as $row ) {
			$items[] = [
				'group_id'    => 'redeemid-webhooks',
				'group_label' => __( 'Updates received from Shopify', 'redeemid' ),
				'item_id'     => 'redeemid-webhook-' . (int) $row['id'],
				'data'        => [
					[ 'name' => __( 'Received', 'redeemid' ), 'value' => gmdate( 'Y-m-d H:i:s', (int) $row['received_at'] ) . ' UTC' ],
					[ 'name' => __( 'Topic', 'redeemid' ), 'value' => (string) $row['topic'] ],
					[ 'name' => __( 'Outcome', 'redeemid' ), 'value' => (string) $row['status'] ],
				],
			];
		}

		return [
			'data' => $items,
			'done' => self::done( count( $events ), count( $hooks ) ),
		];
	}

	/**
	 * The export carries on while either log filled a whole page. Pure.
	 */
	public static function done( int $events, int $hooks ): bool {
		return $events < self::PER_PAGE && $hooks < self::PER_PAGE;
	}

	/**
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function profile( int $user_id ): array {
		$data = [];

		foreach ( Privacy::EXPORTED as $key ) {
			$value = get_user_meta( $user_id, $key, true );
			$text  = '' === $value || [] === $value || null === $value ? '' : self::value( $key, $value );

			if ( '' !== $text ) {
				$data[] = [ 'name' => self::label( $key ), 'value' => $text ];
			}
		}

		$meter = get_user_meta( $user_id, Meter::META, true );

		if ( is_array( $meter ) && ! empty( $meter['items'] ) ) {
			$data[] = [
				'name'  => __( 'Free stories read this period (post IDs)', 'redeemid' ),
				'value' => implode( ', ', array_map( 'intval', (array) $meter['items'] ) ),
			];
		}

		$started = (int) get_user_meta( $user_id, PostCheckout::META, true );

		if ( $started > 0 ) {
			$data[] = [ 'name' => __( 'Started a checkout from this site', 'redeemid' ), 'value' => gmdate( 'Y-m-d H:i:s', $started ) . ' UTC' ];
		}

		$cart = Cart::for_user( $user_id );

		if ( $cart ) {
			$data[] = [ 'name' => __( 'Shopify cart in progress', 'redeemid' ), 'value' => $cart['id'] ];
		}

		if ( $data && '' !== (string) get_user_meta( $user_id, Mirror::NUMERIC, true ) ) {
			$data[] = [
				'name'  => __( 'Held in Shopify', 'redeemid' ),
				'value' => __( 'Shopify holds the full customer record, with orders and addresses. Ask the store for Shopify\'s own customer data report.', 'redeemid' ),
			];
		}

		return $data;
	}
}
