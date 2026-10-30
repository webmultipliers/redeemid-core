<?php

namespace RedeemID\Privacy;

use RedeemID\Config;
use RedeemID\Privacy;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;

/**
 * Privacy requests in WordPress' own list (Tools → Export / Erase Personal
 * Data), with a Shopify column on both screens, and the requests Shopify's
 * compliance webhooks start or record there.
 *
 * What RedeemID adds to a request is post meta on its user_request post.
 */
final class Requests {
	public const EXPORT = 'export_personal_data';
	public const ERASE  = 'remove_personal_data';

	/** Post meta: who started the request here: shopify or member (absent: WordPress staff or the core form). */
	public const SOURCE = '_redeemid_source';
	/** Post meta: Shopify's data_request ID. */
	public const SHOPIFY_REQUEST = '_redeemid_shopify_request';
	/** Post meta: the customer number, kept once the link is erased. */
	public const CUSTOMER = '_redeemid_customer';
	/** Post meta: the customer is a test fixture that doesn't exist in Shopify. */
	public const FIXTURE = '_redeemid_fixture';
	/** Post meta: when Shopify's customers/redact removed the Shopify data here. */
	public const REDACTED = '_redeemid_redacted_at';
	/** Post meta: the erasure asked of Shopify, see ShopifyErasure. */
	public const ERASURE = '_redeemid_shopify_erasure';

	/** Every post meta key above, for uninstall. */
	public const META_KEYS = [ self::SOURCE, self::SHOPIFY_REQUEST, self::CUSTOMER, self::FIXTURE, self::REDACTED, self::ERASURE ];

	private const COLUMN = 'redeemid_shopify';

	public static function register(): void {
		foreach ( [ 'export-personal-data', 'erase-personal-data' ] as $screen ) {
			add_filter( "manage_{$screen}_columns", [ self::class, 'columns' ] );
			add_action( "manage_{$screen}_custom_column", [ self::class, 'column' ], 10, 2 );
		}

		add_filter( 'wp_privacy_personal_data_erasure_page', [ self::class, 'after_erase' ], 10, 6 );
	}

	public static function columns( array $columns ): array {
		$out = [];

		foreach ( $columns as $key => $label ) {
			if ( 'next_steps' === $key ) {
				$out[ self::COLUMN ] = __( 'Shopify', 'redeemid' );
			}

			$out[ $key ] = $label;
		}

		return isset( $out[ self::COLUMN ] ) ? $out : $out + [ self::COLUMN => __( 'Shopify', 'redeemid' ) ];
	}

	/**
	 * @param \WP_User_Request $item
	 */
	public static function column( string $column, $item ): void {
		if ( self::COLUMN !== $column || ! $item instanceof \WP_User_Request ) {
			return;
		}

		echo wp_kses_post( implode( '<br>', self::lines( $item ) ) );

		if ( self::ERASE === $item->action_name ) {
			ShopifyErasure::controls( $item );
		}
	}

	/**
	 * The column's lines, as HTML.
	 *
	 * @return string[]
	 */
	private static function lines( \WP_User_Request $item ): array {
		$lines    = [];
		$customer = self::customer( $item );
		$source   = (string) get_post_meta( $item->ID, self::SOURCE, true );
		$redacted = (int) get_post_meta( $item->ID, self::REDACTED, true );

		if ( 'shopify' === $source && self::EXPORT === $item->action_name ) {
			$lines[] = '<strong>' . esc_html(
				sprintf(
					/* translators: %s: Shopify's request ID */
					__( 'Shopify data request %s: send the download to the store owner.', 'redeemid' ),
					'#' . get_post_meta( $item->ID, self::SHOPIFY_REQUEST, true )
				)
			) . '</strong>';
		}

		if ( 'member' === $source ) {
			$lines[] = esc_html__( 'Asked for from the Member Account block.', 'redeemid' );
		}

		if ( $redacted ) {
			$lines[] = '<strong>' . esc_html(
				sprintf(
					/* translators: %s: date */
					__( 'Redacted by Shopify on %s.', 'redeemid' ),
					wp_date( (string) get_option( 'date_format' ), $redacted )
				)
			) . '</strong>';

			if ( 'request-completed' !== $item->status ) {
				$lines[] = esc_html__( 'Shopify data is gone. Erase to remove the rest, or mark complete.', 'redeemid' );
			}
		}

		if ( '' === $customer ) {
			if ( ! $redacted ) {
				$lines[] = esc_html__( 'Not linked to a Shopify customer here.', 'redeemid' );
			}

			return $lines;
		}

		/* translators: %s: Shopify customer number */
		$label = esc_html( sprintf( __( 'Customer %s', 'redeemid' ), $customer ) );

		if ( get_post_meta( $item->ID, self::FIXTURE, true ) || self::live_fixture( $item ) ) {
			$lines[] = $label . ' ' . esc_html__( '(test fixture, not in Shopify)', 'redeemid' );

			return $lines;
		}

		$admin   = Config::admin_url( 'customers/' . $customer );
		$lines[] = '' !== $admin ? sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $admin ), $label ) : $label;

		if ( self::EXPORT === $item->action_name ) {
			$lines[] = esc_html__( 'Orders and addresses: Request customer data in Shopify.', 'redeemid' );
		}

		return $lines;
	}

	/**
	 * The request's customer number: kept on the request once erased, else
	 * the live link.
	 */
	public static function customer( \WP_User_Request $item ): string {
		$kept = (string) get_post_meta( $item->ID, self::CUSTOMER, true );

		if ( '' !== $kept ) {
			return $kept;
		}

		$user = Privacy::user_for( $item->email );

		return $user ? (string) get_user_meta( $user->ID, Mirror::NUMERIC, true ) : '';
	}

	private static function live_fixture( \WP_User_Request $item ): bool {
		$user = Privacy::user_for( $item->email );

		return $user && Mirror::is_fixture( $user->ID );
	}

	/**
	 * After RedeemID's eraser runs for a request, keep who it unlinked on the
	 * request, so its row can still point to the customer in Shopify.
	 */
	public static function after_erase( mixed $response, int $eraser_index, string $email, int $page, int $request_id, string $eraser_key = '' ): mixed {
		$erased = Privacy::KEY === $eraser_key ? Eraser::erased( $email ) : null;

		if ( $erased && '' !== $erased['customer'] ) {
			update_post_meta( $request_id, self::CUSTOMER, $erased['customer'] );

			if ( $erased['fixture'] ) {
				update_post_meta( $request_id, self::FIXTURE, 1 );
			}
		}

		return $response;
	}

	// ------------------------------------------------- Shopify-started requests

	/**
	 * customers/data_request: a confirmed export request (Shopify already
	 * verified the person with the store owner), marked as Shopify's. When
	 * the person already has an open export request, that one is marked.
	 */
	public static function from_shopify_data_request( string $email, string $customer, string $shopify_request ): int {
		$id = wp_create_user_request( $email, self::EXPORT, [], 'confirmed' );

		if ( is_wp_error( $id ) ) {
			$id = self::open( $email, self::EXPORT );
		}

		if ( ! $id ) {
			Logger::log( 'privacy.request_failed', [ 'source' => 'shopify', 'label' => self::EXPORT ] );

			return 0;
		}

		update_post_meta( $id, self::SOURCE, 'shopify' );
		update_post_meta( $id, self::SHOPIFY_REQUEST, '' !== $shopify_request ? $shopify_request : '?' );
		update_post_meta( $id, self::CUSTOMER, $customer );

		Logger::log( 'privacy.request_created', [ 'source' => 'shopify', 'label' => self::EXPORT, 'request' => $id ] );

		return (int) $id;
	}

	/**
	 * customers/redact, after RedeemID removed its data: a record in Tools →
	 * Erase Personal Data. When the WordPress account stays, the request is
	 * left confirmed, so staff decide whether to erase the rest of that
	 * person's WordPress data; otherwise it is recorded as completed.
	 */
	public static function record_redaction( string $email, string $customer, bool $account_kept ): int {
		/**
		 * Whether a Shopify redaction of a customer whose WordPress account
		 * stays opens an erase request for staff to finish. False records it
		 * as completed instead.
		 */
		$open = $account_kept && (bool) apply_filters( 'redeemid_redact_opens_wp_request', true, $email );

		$id = '' !== $email ? wp_create_user_request( $email, self::ERASE, [], 'confirmed' ) : 0;

		if ( is_wp_error( $id ) ) {
			$id   = self::open( $email, self::ERASE );
			$open = true; // Someone already asked; leave their request for staff.
		}

		if ( ! $id ) {
			return 0;
		}

		update_post_meta( $id, self::SOURCE, 'shopify' );
		update_post_meta( $id, self::REDACTED, time() );

		if ( '' !== $customer ) {
			update_post_meta( $id, self::CUSTOMER, $customer );
		}

		if ( ! $open ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'request-completed' ] );
			update_post_meta( $id, '_wp_user_request_completed_timestamp', time() );
		}

		Logger::log( 'privacy.redacted', [ 'request' => $id, 'status' => $open ? 'confirmed' : 'completed' ] );

		return (int) $id;
	}

	/**
	 * The person's open request of a kind, if any.
	 */
	public static function open( string $email, string $action ): int {
		$ids = get_posts(
			[
				'post_type'      => 'user_request',
				'post_name__in'  => [ $action ],
				'title'          => $email,
				'post_status'    => [ 'request-pending', 'request-confirmed' ],
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			]
		);

		return (int) ( $ids[0] ?? 0 );
	}

	/**
	 * Requests for any of these emails, newest first, for the customer screen.
	 *
	 * @param string[] $emails
	 * @return \WP_User_Request[]
	 */
	public static function for_emails( array $emails ): array {
		$emails = array_values( array_unique( array_filter( array_map( 'strtolower', $emails ) ) ) );
		$out    = [];

		foreach ( $emails as $email ) {
			$ids = get_posts(
				[
					'post_type'      => 'user_request',
					'title'          => $email,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 20,
				]
			);

			foreach ( $ids as $id ) {
				$request = wp_get_user_request( (int) $id );

				if ( $request ) {
					$out[ $request->ID ] = $request;
				}
			}
		}

		krsort( $out );

		return array_values( $out );
	}

	public static function screen_url( string $action ): string {
		return admin_url( self::EXPORT === $action ? 'export-personal-data.php' : 'erase-personal-data.php' );
	}
}
