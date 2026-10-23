<?php

namespace WP2_Shopify\Webhooks;

/**
 * Idempotent webhook ingest (spec 6.1). The dedupe key is Shopify's webhook ID
 * plus topic, so a replayed delivery is accepted and changes nothing.
 */
final class Inbox {
	public function __construct( private readonly InboxStore $store ) {}

	public static function dedupe_key( string $topic, string $webhook_id, string $event_id, string $raw_body ): string {
		$topic = strtolower( trim( $topic ) );

		if ( '' !== $webhook_id ) {
			return hash( 'sha256', 'webhook|' . $webhook_id . '|' . $topic );
		}

		if ( '' !== $event_id ) {
			return hash( 'sha256', 'event|' . $event_id . '|' . $topic );
		}

		// No identifier at all: fall back to the body so exact replays still dedupe.
		return hash( 'sha256', 'body|' . $topic . '|' . $raw_body );
	}

	/**
	 * Record an inbound delivery.
	 *
	 * @return array{status: string, id: int|null} status is 'queued', 'duplicate', 'ignored' or 'dead'.
	 */
	public function accept( string $topic, string $webhook_id, string $event_id, string $raw_body, array $payload, int $now ): array {
		$kind = Topics::kind( $topic );
		$key  = self::dedupe_key( $topic, $webhook_id, $event_id, $raw_body );

		$status = match ( true ) {
			null === $kind           => InboxStore::DEAD,
			Topics::IGNORED === $kind => InboxStore::IGNORED,
			default                  => InboxStore::QUEUED,
		};

		$data = null === $kind || Topics::IGNORED === $kind ? [] : Extractor::extract( $kind, $topic, $payload );

		$id = $this->store->insert_if_new(
			$key,
			[
				'topic'       => strtolower( trim( $topic ) ),
				'webhook_id'  => $webhook_id,
				'customer'    => $data['customer'] ?? '',
				'status'      => $status,
				'data'        => $data,
				'error'       => null === $kind ? 'unknown_topic' : '',
				'received_at' => $now,
			]
		);

		if ( null === $id ) {
			return [ 'status' => 'duplicate', 'id' => null ];
		}

		return [ 'status' => $status, 'id' => $id ];
	}
}
