<?php

namespace RedeemID\Support;

/**
 * Operational log lines: IDs, topics, timings and outcomes only.
 * Never pass tokens, secrets, authorization codes, checkout URLs or payloads.
 */
final class Logger {
	private const REDACT = [ 'token', 'secret', 'code', 'verifier', 'password', 'authorization', 'checkout', 'id_token' ];

	public static function log( string $event, array $context = [] ): void {
		foreach ( $context as $key => $value ) {
			foreach ( self::REDACT as $needle ) {
				if ( str_contains( strtolower( (string) $key ), $needle ) ) {
					$context[ $key ] = '[redacted]';
				}
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				$context[ $key ] = '[structure omitted]';
			}
		}

		/**
		 * Lets a site route plugin events to its own logger.
		 */
		do_action( 'redeemid_log', $event, $context );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[redeemid] ' . $event . ( $context ? ' ' . wp_json_encode( $context ) : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
