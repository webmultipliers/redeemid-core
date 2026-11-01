<?php

namespace RedeemID\Api;

use RedeemID\Plugin;
use RedeemID\Settings;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;

/**
 * Event webhooks: the event stream, sent as signed JSON to one URL the owner
 * sets on RedeemID → Settings, for a CRM, an email service, Zapier, Make or
 * the site's own endpoint. RedeemID names no vendor and has no per-vendor
 * code: any receiver that can check an HMAC can use it.
 *
 * Off until a URL is saved. Each chosen event goes on the queue (Action
 * Scheduler when present, WP-Cron otherwise) and is sent in the background,
 * so a page view never waits on the receiver. A delivery that fails (no
 * answer within five seconds, a redirect or anything but a 2xx) is tried
 * again after 1, 5 and 30 minutes, 2 and 12 hours, then dropped and logged.
 *
 * The body is the event's envelope plus an id (for the receiver's dedupe) and
 * the site URL. It never carries an email or a Shopify tag. With "Include the
 * Shopify customer ID" on, events about a linked member also carry their
 * Shopify customer GID, looked up when the event is sent, so a CRM that syncs
 * Shopify customers can match the person. WordPress stores nothing new: once
 * sent, an event is the receiver's.
 *
 * Signature: X-RedeemID-Signature is "t=<unix time>,v1=<hex>", where hex is
 * the HMAC-SHA256 of "<t>.<raw body>" keyed with the site's secret. The
 * secret is made by the plugin, stored encrypted, and shown to staff on the
 * Settings page.
 *
 * payload(), sign(), headers(), retry_delay(), sanitize_events() and
 * succeeded() are pure.
 */
final class Outbound {
	public const HOOK          = 'redeemid_outbound_send';
	public const SECRET_OPTION = 'redeemid_outbound_secret';
	public const TEST          = 'test';
	public const TIMEOUT       = 5;

	/** Seconds to wait before the next try, after each failed one. */
	public const RETRY_DELAYS = [ 60, 300, 1800, 7200, 43200 ];

	/** Chosen until the owner saves their own list: none of them fires per page view. */
	public const DEFAULT_EVENTS = [
		Events::METER_EXHAUSTED,
		Events::CHECKOUT_STARTED,
		Events::LEVEL_GAINED,
		Events::LEVEL_LOST,
		Events::PREFERENCES_SAVED,
	];

	public static function register(): void {
		add_action( Events::HOOK, [ self::class, 'queue' ], 30, 3 );
		add_action( self::HOOK, [ self::class, 'deliver' ], 10, 2 );
	}

	public static function url(): string {
		return (string) Settings::get( 'outbound_url' );
	}

	public static function enabled(): bool {
		return '' !== self::url();
	}

	/**
	 * @return string[]
	 */
	public static function events(): array {
		return self::sanitize_events( Settings::get( 'outbound_events' ) );
	}

	/**
	 * The chosen event names that exist in the catalog, in catalog order. Pure.
	 *
	 * @return string[]
	 */
	public static function sanitize_events( mixed $input ): array {
		$input = array_map( 'strval', is_array( $input ) ? $input : [] );

		return array_values( array_filter( array_keys( Events::CATALOG ), static fn( string $name ): bool => in_array( $name, $input, true ) ) );
	}

	/**
	 * Put one event on the queue, if it is one the owner chose.
	 *
	 * @param array<string, scalar|null> $props
	 * @param array                      $event The envelope.
	 */
	public static function queue( string $name, array $props, array $event ): void {
		if ( ! self::enabled() || ! in_array( $name, self::events(), true ) ) {
			return;
		}

		$payload = self::payload( $event, wp_generate_uuid4(), home_url( '/' ) );

		/**
		 * The body an event webhook will send, before the customer ID is added.
		 * Return null to skip this event.
		 *
		 * @since 0.0.8
		 *
		 * @param array|null $payload {id, site, name, version, time, props}.
		 */
		$payload = apply_filters( 'redeemid_outbound_payload', $payload );

		if ( is_array( $payload ) ) {
			self::schedule( $payload, 1, 0 );
		}
	}

	/**
	 * The body for one event. Pure.
	 *
	 * @param array{name: string, version: int, time: int, props: array<string, scalar|null>} $event
	 * @return array{id: string, site: string, name: string, version: int, time: int, props: array<string, scalar|null>}
	 */
	public static function payload( array $event, string $id, string $site ): array {
		return [
			'id'      => $id,
			'site'    => $site,
			'name'    => (string) $event['name'],
			'version' => (int) $event['version'],
			'time'    => (int) $event['time'],
			'props'   => (array) $event['props'],
		];
	}

	/**
	 * The signature header's value. Pure.
	 */
	public static function sign( string $body, string $secret, int $time ): string {
		return 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $secret );
	}

	/**
	 * @return array<string, string> Pure.
	 */
	public static function headers( string $body, string $secret, int $time, string $name, string $id, string $version ): array {
		return [
			'Content-Type'         => 'application/json',
			'User-Agent'           => 'RedeemID/' . $version,
			'X-RedeemID-Event'     => $name,
			'X-RedeemID-Delivery'  => $id,
			'X-RedeemID-Signature' => self::sign( $body, $secret, $time ),
		];
	}

	/**
	 * Seconds until the next try after $attempt failed, or null to give up. Pure.
	 */
	public static function retry_delay( int $attempt ): ?int {
		return self::RETRY_DELAYS[ $attempt - 1 ] ?? null;
	}

	/**
	 * Pure.
	 */
	public static function succeeded( int|\WP_Error $status ): bool {
		return is_int( $status ) && $status >= 200 && $status < 300;
	}

	/**
	 * Send one queued event; on failure, queue the next try or give up.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function deliver( array $payload, int $attempt = 1 ): void {
		if ( ! self::enabled() ) {
			return; // Turned off since it was queued.
		}

		$status = self::send( self::with_customer( $payload ) );

		if ( self::succeeded( $status ) ) {
			return;
		}

		$context = [
			'label'   => (string) ( $payload['name'] ?? '' ),
			'attempt' => $attempt,
			'status'  => is_int( $status ) ? 'http_' . $status : $status->get_error_code(),
		];
		$delay   = self::retry_delay( $attempt );

		if ( null === $delay ) {
			Logger::log( 'outbound.dropped', $context );
			return;
		}

		Logger::log( 'outbound.retry', $context + [ 'in' => $delay ] );
		self::schedule( $payload, $attempt + 1, $delay );
	}

	/**
	 * Send a "test" event now, for Settings → Send a test event.
	 */
	public static function test(): int|\WP_Error {
		$payload = self::payload(
			[
				'name'    => self::TEST,
				'version' => Events::VERSION,
				'time'    => time(),
				'props'   => [ 'user_id' => get_current_user_id() ],
			],
			wp_generate_uuid4(),
			home_url( '/' )
		);
		$status  = self::send( $payload );

		Logger::log( 'outbound.test', [ 'status' => is_int( $status ) ? 'http_' . $status : $status->get_error_code() ] );

		return $status;
	}

	/**
	 * The signing secret, made the first time it is needed. Empty when no
	 * encryption key can be built.
	 */
	public static function secret(): string {
		$stored = (string) get_option( self::SECRET_OPTION, '' );

		try {
			$crypto = Plugin::crypto();
		} catch ( \Throwable ) {
			return '';
		}

		$plain = '' === $stored ? null : $crypto->decrypt( $stored );

		return $plain ?? self::rotate();
	}

	/**
	 * Replace the secret. Receivers must be given the new one.
	 */
	public static function rotate(): string {
		try {
			$secret = 'whsec_' . bin2hex( random_bytes( 32 ) );
			update_option( self::SECRET_OPTION, Plugin::crypto()->encrypt( $secret ), false );
		} catch ( \Throwable ) {
			return '';
		}

		Logger::log( 'outbound.rotated' );

		return $secret;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function with_customer( array $payload ): array {
		$user_id = (int) ( $payload['props']['user_id'] ?? 0 );

		if ( $user_id > 0 && Settings::get( 'outbound_customer' ) ) {
			$id = Mirror::customer_id( $user_id );

			if ( $id ) {
				$payload['customer'] = $id->gid();
			}
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function send( array $payload ): int|\WP_Error {
		$secret = self::secret();

		if ( '' === $secret ) {
			return new \WP_Error( 'no_secret' );
		}

		$body     = (string) wp_json_encode( $payload );
		$response = wp_safe_remote_post(
			self::url(),
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => self::headers( $body, $secret, time(), (string) ( $payload['name'] ?? '' ), (string) ( $payload['id'] ?? '' ), REDEEMID_VERSION ),
				'body'        => $body,
			]
		);

		return is_wp_error( $response ) ? $response : (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function schedule( array $payload, int $attempt, int $delay ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK, [ $payload, $attempt ], 'redeemid' );
			return;
		}

		wp_schedule_single_event( time() + $delay, self::HOOK, [ $payload, $attempt ] );
	}
}
