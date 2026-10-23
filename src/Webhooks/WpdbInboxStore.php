<?php

namespace WP2_Shopify\Webhooks;

/**
 * Inbox records in a dedicated table with a retention cap (spec 4.2, 15.2).
 * The data column holds only extracted fields and is cleared once processed.
 */
final class WpdbInboxStore implements InboxStore {
	public const DB_VERSION = '1';
	public const RETENTION  = 30 * DAY_IN_SECONDS;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wp2_shopify_webhooks';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				dedupe_key char(64) NOT NULL,
				topic varchar(100) NOT NULL,
				webhook_id varchar(100) NOT NULL DEFAULT '',
				customer varchar(32) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL,
				data longtext NULL,
				error varchar(190) NOT NULL DEFAULT '',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				received_at bigint(20) unsigned NOT NULL,
				processed_at bigint(20) unsigned NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe_key (dedupe_key),
				KEY status (status),
				KEY received_at (received_at)
			) {$charset};"
		);

		update_option( 'wp2_shopify_db_version', self::DB_VERSION, false );
	}

	public function insert_if_new( string $dedupe_key, array $row ): ?int {
		global $wpdb;

		// INSERT IGNORE on the unique key makes concurrent duplicate deliveries race-safe.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . ' (dedupe_key, topic, webhook_id, customer, status, data, error, received_at, processed_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %d, NULLIF(%d, 0))', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$dedupe_key,
				substr( (string) $row['topic'], 0, 100 ),
				substr( (string) $row['webhook_id'], 0, 100 ),
				substr( (string) $row['customer'], 0, 32 ),
				$row['status'],
				wp_json_encode( $row['data'] ),
				substr( (string) $row['error'], 0, 190 ),
				(int) $row['received_at'],
				self::QUEUED === $row['status'] ? 0 : (int) $row['received_at']
			)
		);

		return $inserted ? (int) $wpdb->insert_id : null;
	}

	public function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return null;
		}

		$row['data'] = json_decode( (string) $row['data'], true ) ?: [];

		return $row;
	}

	public function finish( int $id, string $status, string $error = '' ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET status = %s, error = %s, attempts = attempts + 1, processed_at = %d, data = IF(%s IN (\'processed\', \'ignored\', \'unmatched\'), NULL, data) WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$status,
				substr( $error, 0, 190 ),
				time(),
				$status,
				$id
			)
		);
	}

	public function counts(): array {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$counts = [];

		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}

		return $counts;
	}

	public function last_received(): ?array {
		global $wpdb;

		return $wpdb->get_row( 'SELECT topic, received_at, status FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function prune(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE received_at < %d', time() - self::RETENTION ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
