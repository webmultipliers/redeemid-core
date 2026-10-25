<?php

namespace RedeemID\Shopify;

use RedeemID\Support\Logger;

/**
 * Shared transport: version-pinned URLs are built by callers; this enforces
 * timeouts and logs outcomes without request or response bodies.
 */
final class Http {
	/**
	 * Shopify's cost report for the last GraphQL call, or null when it sent none.
	 *
	 * @var array{requested: int, actual: int, available: float, maximum: float, restore: float}|null
	 */
	private static ?array $cost = null;

	/**
	 * @return array{requested: int, actual: int, available: float, maximum: float, restore: float}|null
	 */
	public static function last_cost(): ?array {
		return self::$cost;
	}

	public static function graphql( string $url, array $headers, string $query, array $variables = [], string $label = 'graphql' ): array|\WP_Error {
		$body = [ 'query' => $query ];

		if ( $variables ) {
			$body['variables'] = $variables;
		}

		self::$cost = null;
		$response   = self::request( 'POST', $url, $headers + [ 'Content-Type' => 'application/json' ], wp_json_encode( $body ), $label );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		self::$cost = self::cost( $response['extensions']['cost'] ?? null );

		if ( ! empty( $response['errors'] ) ) {
			$first = is_array( $response['errors'] ) ? ( $response['errors'][0]['message'] ?? 'GraphQL error' ) : 'GraphQL error';
			$code  = is_array( $response['errors'] ) ? (string) ( $response['errors'][0]['extensions']['code'] ?? '' ) : '';
			Logger::log( 'shopify.graphql_error', [ 'label' => $label, 'message' => substr( is_string( $first ) ? $first : '', 0, 190 ) ] );

			// Over the cost budget: the call did nothing and is safe to repeat later.
			return new \WP_Error( 'THROTTLED' === $code ? 'shopify_throttled' : 'shopify_graphql', is_string( $first ) ? $first : 'GraphQL error' );
		}

		return is_array( $response['data'] ?? null ) ? $response['data'] : new \WP_Error( 'shopify_graphql', 'Empty GraphQL response.' );
	}

	/**
	 * extensions.cost from a GraphQL response, in the shape last_cost() returns.
	 */
	public static function cost( mixed $cost ): ?array {
		$throttle = is_array( $cost ) ? ( $cost['throttleStatus'] ?? null ) : null;

		if ( ! is_array( $throttle ) || ! isset( $throttle['currentlyAvailable'], $throttle['maximumAvailable'], $throttle['restoreRate'] ) ) {
			return null;
		}

		return [
			'requested' => (int) ( $cost['requestedQueryCost'] ?? 0 ),
			'actual'    => (int) ( $cost['actualQueryCost'] ?? 0 ),
			'available' => (float) $throttle['currentlyAvailable'],
			'maximum'   => (float) $throttle['maximumAvailable'],
			'restore'   => (float) $throttle['restoreRate'],
		];
	}

	/**
	 * @return array|\WP_Error Decoded JSON body.
	 */
	public static function request( string $method, string $url, array $headers, ?string $body, string $label, int $timeout = 15 ): array|\WP_Error {
		$endpoint = (string) wp_parse_url( $url, PHP_URL_HOST ) . (string) wp_parse_url( $url, PHP_URL_PATH );
		$started  = microtime( true );
		$response = wp_safe_remote_request(
			$url,
			[
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeout,
				'redirection' => 0,
			]
		);

		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			Logger::log( 'shopify.http_failed', [ 'label' => $label, 'method' => $method, 'endpoint' => $endpoint, 'ms' => $ms, 'error' => $response->get_error_code() ] );

			return new \WP_Error( 'shopify_unreachable', 'Shopify could not be reached.' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		Logger::log( 'shopify.http', [ 'label' => $label, 'method' => $method, 'endpoint' => $endpoint, 'status' => $status, 'ms' => $ms ] );

		if ( $status >= 400 ) {
			$error = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : 'http_' . $status;

			return new \WP_Error( 'shopify_http_' . $status, $error, [ 'status' => $status, 'oauth_error' => $error ] );
		}

		return is_array( $data ) ? $data : new \WP_Error( 'shopify_bad_json', 'Unexpected response from Shopify.' );
	}
}
