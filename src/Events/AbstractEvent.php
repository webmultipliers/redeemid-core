<?php

namespace WP2_Shopify\Events;

use WP_User;

abstract class AbstractEvent {
	protected string $topic;
	protected array $payload;

	public function __construct( string $topic, array $payload ) {
		$this->topic   = $topic;
		$this->payload = $payload;
	}

	final public function run(): bool {
		try {
			$this->process();
			return true;
		} catch ( \Throwable $exception ) {
			$this->log( sprintf( 'Webhook event "%s" failed: %s', $this->topic, $exception->getMessage() ) );
			return false;
		}
	}

	abstract protected function process(): void;

	protected function get_customer_user(): ?WP_User {
		$customer_id = $this->resolve_customer_id();
		$user        = $customer_id ? CustomerUpdater::find_user_by_customer_id( $customer_id ) : NULL;

		if ( $user ) {
			return $user;
		}

		return NULL;
	}

	protected function resolve_customer_id(): ?string {
		if ( ! empty( $this->payload['id'] ) ) {
			return (string) $this->payload['id'];
		}

		if ( ! empty( $this->payload['customer']['id'] ) ) {
			return (string) $this->payload['customer']['id'];
		}

		if ( ! empty( $this->payload['customer_id'] ) ) {
			return (string) $this->payload['customer_id'];
		}

		if ( ! empty( $this->payload['customerId'] ) ) {
			return (string) $this->payload['customerId'];
		}

		return NULL;
	}

	protected function resolve_tags(): array {
		$tags = [];

		if ( isset( $this->payload['tags'] ) && is_array( $this->payload['tags'] ) ) {
			$tags = $this->payload['tags'];
		} elseif ( isset( $this->payload['tags'] ) && is_string( $this->payload['tags'] ) ) {
			$tags = array_map( 'trim', explode( ',', $this->payload['tags'] ) );
		} elseif ( isset( $this->payload['customer']['tags'] ) && is_string( $this->payload['customer']['tags'] ) ) {
			$tags = array_map( 'trim', explode( ',', $this->payload['customer']['tags'] ) );
		}

		return array_filter( $tags );
	}

	protected function mark_event( ?WP_User $user = NULL ): void {
		if ( ! $user ) {
			return;
		}

		CustomerUpdater::save_meta(
			$user,
			'_wp2_shopify_last_webhook_event',
			sprintf( '%s at %s', $this->topic, current_time( 'mysql' ) )
		);
	}

	protected function record_global_event( string $option_key ): void {
		update_option(
			$option_key,
			[
				'topic'     => $this->topic,
				'timestamp' => current_time( 'mysql' ),
				'payload'   => $this->payload,
			]
		);
	}

	protected function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[wp2-shopify] ' . $message );
		}
	}
}
