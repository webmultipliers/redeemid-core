<?php

namespace WP2_Shopify\Events;

class Dispatcher {
	public static function register(): void {
		add_action( 'wp2_shopify_handle_webhook_event', [ self::class, 'handle' ], 10, 2 );
	}

	public static function handle( string $topic, array $payload ): void {
		$event_class = Registry::resolve( $topic );

		if ( ! $event_class ) {
			return;
		}

		/** @var AbstractEvent $event */
		$event = new $event_class( $topic, $payload );
		$event->run();
	}
}
