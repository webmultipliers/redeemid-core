<?php

namespace RedeemID\Webhooks;

use RedeemID\Admin\Menu;
use RedeemID\Settings;
use RedeemID\Shopify\Admin;
use RedeemID\Support\Health;
use RedeemID\Support\Logger;
use RedeemID\Sync\BulkResync;

/**
 * Keeps the tag chain from failing quietly.
 *
 * Once a day it asks Shopify for the app's webhook subscriptions (one Admin
 * API call, no customer data) and re-creates any that Shopify removed. Then,
 * and whenever an admin screen asks, it checks the local side: deliveries
 * rejected, stuck in the queue or dead-lettered, WP-Cron stalled, or no
 * delivery at all for a while. Problems show in an admin notice, Site Health,
 * the Shopify Health block and RedeemID → Webhooks, and can be emailed.
 */
final class Monitor {
	public const HOOK   = 'redeemid_monitor';
	public const OPTION = 'redeemid_monitor';
	public const ACTION = 'redeemid_monitor_check';

	/** At most one alert email per this many seconds, unless something new breaks. */
	private const ALERT_EVERY = DAY_IN_SECONDS;

	/** @var array<string, array{state: string, n: int}>|null This request's problems. */
	private static ?array $problems = null;

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'admin_init', [ self::class, 'ensure_scheduled' ] );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'check_now' ] );
		add_filter( 'site_status_tests', [ self::class, 'site_health' ] );
		add_filter( 'redeemid_health_checks', [ self::class, 'health_row' ] );
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The daily check: subscriptions first, then the local checks, then alert.
	 */
	public static function run(): void {
		if ( Admin::available() ) {
			$result = Registrar::sync();

			if ( $result['created'] && $result['existing'] > 0 ) {
				// Shopify had removed these, usually after deliveries kept failing.
				Logger::log( 'webhooks.resubscribed', [ 'topics' => implode( ',', $result['created'] ), 'status' => 'warn' ] );
			}
		}

		self::$problems = null;
		$problems       = self::problems();
		$state    = self::state();

		$state['checked_at'] = time();
		update_option( self::OPTION, $state, false );

		self::alert( $problems );
	}

	/**
	 * "Check now" on RedeemID → Webhooks.
	 */
	public static function check_now(): void {
		Menu::guard( self::ACTION );
		self::run();

		wp_safe_redirect( Menu::url( \RedeemID\Admin\Webhooks::PAGE, [ 'result' => self::problems() ? 'checked_problems' : 'checked_ok' ] ) );
		exit;
	}

	/**
	 * @return array{checked_at: int, alerted_at: int, alerted: string[]}
	 */
	public static function state(): array {
		$state = get_option( self::OPTION );

		return array_merge( [ 'checked_at' => 0, 'alerted_at' => 0, 'alerted' => [] ], is_array( $state ) ? $state : [] );
	}

	/**
	 * Current problems, from stored facts and a few cheap queries. No Shopify call.
	 *
	 * @return array<string, array{state: string, n: int}>
	 */
	public static function problems(): array {
		if ( null !== self::$problems ) {
			return self::$problems;
		}

		$inbox = new WpdbInboxStore();
		$last  = $inbox->last_received();
		$prune = wp_next_scheduled( 'redeemid_prune' );
		$reg   = get_option( Registrar::RESULT );

		self::$problems = Watchdog::problems(
			[
				'now'           => time(),
				'linked'        => BulkResync::linked_count(),
				'last_received' => $last ? (int) $last['received_at'] : 0,
				'quiet_days'    => (int) Settings::get( 'quiet_days' ),
				'oldest_queued' => $inbox->oldest_queued(),
				'dead_recent'   => $inbox->count_since( InboxStore::DEAD, time() - DAY_IN_SECONDS ),
				'rejected_at'   => (int) get_option( 'redeemid_webhook_rejected', 0 ),
				'registration'  => is_array( $reg ) ? $reg : null,
				'cron_late'     => $prune ? max( 0, time() - (int) $prune ) : 0,
			]
		);

		return self::$problems;
	}

	/**
	 * One problem in words.
	 */
	public static function message( string $code, int $n ): string {
		return match ( $code ) {
			'check_failed'     => __( 'The daily check could not list webhook subscriptions in Shopify. See App events for the failed call.', 'redeemid' ),
			/* translators: %d: number of topics */
			'subscribe_failed' => sprintf( _n( '%d webhook subscription could not be created in Shopify.', '%d webhook subscriptions could not be created in Shopify.', $n, 'redeemid' ), $n ),
			'rejected'         => __( 'Webhook deliveries were rejected in the last day because their signature did not match. Check the Admin app client secret in Settings.', 'redeemid' ),
			/* translators: %d: minutes */
			'backlog'          => sprintf( __( 'Webhooks have waited %d minutes to be processed. WP-Cron or Action Scheduler is not running, so tag changes are not reaching WordPress.', 'redeemid' ), $n ),
			/* translators: %d: hours */
			'cron'             => sprintf( __( 'WP-Cron looks stopped: a daily task is %d hours late. Queued webhooks and checks depend on it; set up a real cron job for wp-cron.php.', 'redeemid' ), $n ),
			/* translators: %d: number of topics */
			'resubscribed'     => sprintf( _n( 'Shopify had removed %d webhook subscription, so it was created again. Shopify does this after deliveries fail for several hours: check that nothing blocks the webhook endpoint, then consider a bulk resync on Customers.', 'Shopify had removed %d webhook subscriptions, so they were created again. Shopify does this after deliveries fail for several hours: check that nothing blocks the webhook endpoint, then consider a bulk resync on Customers.', $n, 'redeemid' ), $n ),
			/* translators: %d: number of deliveries */
			'dead'             => sprintf( _n( '%d webhook failed in the last day and was set aside. Retry it from RedeemID → Webhooks.', '%d webhooks failed in the last day and were set aside. Retry them from RedeemID → Webhooks.', $n, 'redeemid' ), $n ),
			'quiet'            => $n
				/* translators: %d: days */
				? sprintf( __( 'No webhook has arrived for %d days although customers are linked. If tags changed in Shopify since, deliveries are not getting through.', 'redeemid' ), $n )
				: __( 'No webhook has arrived yet although customers are linked. If webhooks are registered, check that Shopify can reach the endpoint.', 'redeemid' ),
			default            => $code,
		};
	}

	/**
	 * @return string[] Every current problem in words, worst first.
	 */
	public static function messages(): array {
		$out = [];

		foreach ( self::problems() as $code => $problem ) {
			$out[] = self::message( $code, $problem['n'] );
		}

		return $out;
	}

	public static function notice(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}

		$problems = self::problems();
		$worst    = Watchdog::worst( $problems );
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page     = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ours     = in_array( $page, Menu::pages(), true );
		$wide     = $screen && in_array( $screen->id, [ 'dashboard', 'plugins' ], true );

		// Failures everywhere staff land first; warnings only on the plugin's own pages.
		// RedeemID → Webhooks lists them in its Delivery health panel instead.
		if ( ! $worst || \RedeemID\Admin\Webhooks::PAGE === $page || ( Watchdog::WARN === $worst && ! $ours ) || ( Watchdog::FAIL === $worst && ! $ours && ! $wide ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s"><p><strong>%s</strong></p><ul class="ul-disc">%s</ul><p><a href="%s">%s</a></p></div>',
			Watchdog::FAIL === $worst ? 'error' : 'warning',
			esc_html__( 'Shopify tag updates may not be reaching WordPress', 'redeemid' ),
			implode( '', array_map( static fn( $m ) => '<li>' . esc_html( $m ) . '</li>', self::messages() ) ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped per item.
			esc_url( Menu::url( \RedeemID\Admin\Webhooks::PAGE ) ),
			esc_html__( 'Open RedeemID → Webhooks', 'redeemid' )
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $tests
	 */
	public static function site_health( array $tests ): array {
		$tests['direct']['redeemid_webhooks'] = [
			'label' => __( 'Shopify webhooks are arriving', 'redeemid' ),
			'test'  => [ self::class, 'site_health_result' ],
		];

		return $tests;
	}

	public static function site_health_result(): array {
		$worst = Watchdog::worst( self::problems() );

		return [
			'label'       => $worst ? __( 'Shopify tag updates may not be reaching WordPress', 'redeemid' ) : __( 'Shopify webhooks are arriving', 'redeemid' ),
			'status'      => Watchdog::FAIL === $worst ? 'critical' : ( $worst ? 'recommended' : 'good' ),
			'badge'       => [ 'label' => __( 'RedeemID', 'redeemid' ), 'color' => 'blue' ],
			'description' => $worst
				? '<ul>' . implode( '', array_map( static fn( $m ) => '<li>' . esc_html( $m ) . '</li>', self::messages() ) ) . '</ul>'
				: '<p>' . esc_html__( 'Members gain and lose access when Shopify sends tag changes. Subscriptions, the processing queue and WP-Cron all look healthy.', 'redeemid' ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( Menu::url( \RedeemID\Admin\Webhooks::PAGE ) ), esc_html__( 'Open RedeemID → Webhooks', 'redeemid' ) ),
			'test'        => 'redeemid_webhooks',
		];
	}

	/**
	 * @param array<int, array<string, string>> $checks
	 */
	public static function health_row( mixed $checks ): array {
		$checks   = is_array( $checks ) ? $checks : [];
		$worst    = Watchdog::worst( self::problems() );
		$messages = self::messages();

		$checks[] = [
			'label'  => __( 'Webhook delivery', 'redeemid' ),
			'state'  => Watchdog::FAIL === $worst ? Health::FAIL : ( $worst ? Health::WARN : Health::OK ),
			'detail' => $messages ? $messages[0] : __( 'subscriptions, queue and cron healthy', 'redeemid' ),
		];

		return $checks;
	}

	/**
	 * Email the site admin when something is wrong, if Settings ask for it:
	 * at once when a new problem appears, otherwise at most once a day.
	 *
	 * @param array<string, array{state: string, n: int}> $problems
	 */
	private static function alert( array $problems ): void {
		$state = self::state();
		$codes = array_keys( $problems );
		$new   = array_diff( $codes, $state['alerted'] );

		if ( ! $codes ) {
			if ( $state['alerted'] ) {
				$state['alerted'] = [];
				update_option( self::OPTION, $state, false );
			}

			return;
		}

		if ( ! Settings::get( 'alert_email' ) || ( ! $new && $state['alerted_at'] > time() - self::ALERT_EVERY ) ) {
			return;
		}

		$lines = array_map( static fn( $code ) => '- ' . self::message( $code, $problems[ $code ]['n'] ), $codes );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- one admin alert, at most daily.
		$sent  = wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: site name */
			sprintf( __( '[%s] Shopify tag updates may not be reaching WordPress', 'redeemid' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ),
			implode( "\n", $lines ) . "\n\n" . Menu::url( \RedeemID\Admin\Webhooks::PAGE )
		);

		$state['alerted_at'] = time();
		$state['alerted']    = $codes;
		update_option( self::OPTION, $state, false );
		Logger::log( 'webhooks.alert_sent', [ 'problems' => implode( ',', $codes ), 'status' => $sent ? 'ok' : 'mail_failed' ] );
	}
}
