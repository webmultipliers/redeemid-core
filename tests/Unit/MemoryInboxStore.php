<?php

namespace RedeemID\Tests\Unit;

use RedeemID\Webhooks\InboxStore;

final class MemoryInboxStore implements InboxStore {
	public array $rows = [];
	private array $keys = [];

	public function insert_if_new( string $dedupe_key, array $row ): ?int {
		if ( isset( $this->keys[ $dedupe_key ] ) ) {
			return null;
		}

		$id                       = count( $this->rows ) + 1;
		$this->keys[ $dedupe_key ] = $id;
		$this->rows[ $id ]         = $row + [ 'id' => $id, 'attempts' => 0 ];

		return $id;
	}

	public function get( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}

	public function finish( int $id, string $status, string $error = '' ): void {
		$this->rows[ $id ]['status'] = $status;
		$this->rows[ $id ]['error']  = $error;
	}

	public function counts(): array {
		$counts = [];

		foreach ( $this->rows as $row ) {
			$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;
		}

		return $counts;
	}
}
