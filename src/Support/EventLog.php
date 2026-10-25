<?php

namespace RedeemID\Support;

/**
 * Keeps every Logger event in a table so admins can read it on the App events
 * page: calls the plugin makes to Shopify, syncs, sign-ins, rejected webhooks.
 * The context is what Logger already passes on (IDs, labels, timings and
 * outcomes, secrets redacted), never request or response bodies.
 */
final class EventLog {
	public const DB_VERSION = '1';
	public const RETENTION  = 30 * DAY_IN_SECONDS;
	public const MAX_ROWS   = 20000;

	private const VERSION_OPTION = 'redeemid_events_db_version';

	public static function register(): void {
		add_action( 'redeemid_log', [ self::class, 'record' ], 10, 2 );
	}

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'redeemid_events';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at bigint(20) unsigned NOT NULL,
				event varchar(64) NOT NULL,
				label varchar(100) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT '',
				ms int(10) unsigned NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				customer varchar(32) NOT NULL DEFAULT '',
				origin varchar(10) NOT NULL DEFAULT '',
				context text NULL,
				PRIMARY KEY  (id),
				KEY event (event),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::DB_VERSION, true ); // Autoloaded: record() checks it on every event.
	}

	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * @param array<string, scalar|null> $context Already redacted by Logger.
	 */
	public static function record( string $event, array $context ): void {
		global $wpdb;

		if ( get_option( self::VERSION_OPTION ) !== self::DB_VERSION ) {
			return;
		}

		$label    = (string) ( $context['label'] ?? $context['topic'] ?? $context['source'] ?? '' );
		$status   = (string) ( $context['status'] ?? $context['result'] ?? $context['error'] ?? $context['reason'] ?? '' );
		$customer = (string) ( $context['customer'] ?? '' );
		$user_id  = (int) ( $context['user'] ?? get_current_user_id() );

		$suppress = $wpdb->suppress_errors();
		$wpdb->insert(
			self::table(),
			[
				'created_at' => time(),
				'event'      => substr( $event, 0, 64 ),
				'label'      => substr( $label, 0, 100 ),
				'status'     => substr( $status, 0, 32 ),
				'ms'         => isset( $context['ms'] ) ? (int) $context['ms'] : null,
				'user_id'    => max( 0, $user_id ),
				'customer'   => substr( $customer, 0, 32 ),
				'origin'     => self::origin(),
				'context'    => $context ? substr( (string) wp_json_encode( $context ), 0, 2000 ) : null,
			],
			[ '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
		);
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Where the event came from: a cron run, the REST API, an admin screen, and so on.
	 */
	public static function origin(): string {
		return match ( true ) {
			defined( 'WP_CLI' ) && WP_CLI          => 'cli',
			wp_doing_cron()                        => 'cron',
			defined( 'REST_REQUEST' ) && REST_REQUEST => 'rest',
			wp_doing_ajax()                        => 'ajax',
			is_admin()                             => 'admin',
			default                                => 'site',
		};
	}

	/**
	 * @param array{event?: string, prefix?: string, user?: int, search?: string} $filters
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $filters, int $per_page, int $page ): array {
		global $wpdb;

		[ $where, $args ] = self::where( $filters );
		$table            = self::table();
		$offset           = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where is built from placeholders only.
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$args ) : "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", ...[ ...$args, $per_page, $offset ] ), ARRAY_A );
		// phpcs:enable

		return [ 'rows' => (array) $rows, 'total' => $total ];
	}

	/**
	 * @return string[] Distinct event names, for the filter menu.
	 */
	public static function events(): array {
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( 'SELECT DISTINCT event FROM ' . self::table() . ' ORDER BY event' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count_since( string $event, int $since ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE event = %s AND created_at >= %d', $event, $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function clear(): void {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function prune(): void {
		global $wpdb;

		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %d", time() - self::RETENTION ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function where( array $filters ): array {
		global $wpdb;

		$where = [ '1=1' ];
		$args  = [];

		if ( ! empty( $filters['event'] ) ) {
			$where[] = 'event = %s';
			$args[]  = (string) $filters['event'];
		}

		if ( ! empty( $filters['prefix'] ) ) {
			$where[] = 'event LIKE %s';
			$args[]  = $wpdb->esc_like( (string) $filters['prefix'] ) . '%';
		}

		if ( ! empty( $filters['user'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = (int) $filters['user'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = '(label LIKE %s OR status LIKE %s OR customer LIKE %s OR context LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}

		return [ implode( ' AND ', $where ), $args ];
	}
}
