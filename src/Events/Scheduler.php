<?php

namespace WP2_Shopify\Events;

class Scheduler {
	public static function schedule( string $topic, array $payload ): bool {
		$args = [ $topic, $payload ];

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$result = as_enqueue_async_action( 'wp2_shopify_handle_webhook_event', $args );
			return false !== $result;
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			return (bool) wp_schedule_single_event( time() + 5, 'wp2_shopify_handle_webhook_event', $args );
		}

		return false;
	}
}
