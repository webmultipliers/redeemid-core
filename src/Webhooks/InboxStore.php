<?php

namespace RedeemID\Webhooks;

/**
 * Storage for webhook dedupe records and the minimal data a queued job needs.
 */
interface InboxStore {
	public const QUEUED    = 'queued';
	public const PROCESSED = 'processed';
	public const IGNORED   = 'ignored';
	public const DEAD      = 'dead';
	public const UNMATCHED = 'unmatched';

	/**
	 * Insert a record unless one with the same dedupe key exists.
	 *
	 * @return int|null The new row ID, or null when the key was already seen.
	 * @throws \RuntimeException When the record could not be written, so the caller can ask Shopify to retry.
	 */
	public function insert_if_new( string $dedupe_key, array $row ): ?int;

	public function get( int $id ): ?array;

	public function finish( int $id, string $status, string $error = '' ): void;

	/**
	 * @return array<string, int> Count per status.
	 */
	public function counts(): array;
}
