<?php

namespace WP2_Shopify\Shopify;

use WP2_Shopify\Support\Logger;

/**
 * Shared transport: version-pinned URLs are built by callers; this enforces
 * timeouts and logs outcomes without request or response bodies (spec 4.3).
 */
final class Http {
	public static function graphql( string $url, array $headers, string $query, array $variables = [], string $label = 'graphql' ): array|\WP_Error {
		$body = [ 'query' => $query ];

		if ( $variables ) {
			$body['variables'] = $variables;
		}

		$response = self::request( 'POST', $url, $headers + [ 'Content-Type' => 'application/json' ], wp_json_encode( $body ), $label );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['errors'] ) ) {
			$first = is_array( $response['errors'] ) ? ( $response['errors'][0]['message'] ?? 'GraphQL error' ) : 'GraphQL error';
			Logger::log( 'shopify.graphql_error', [ 'label' => $label, 'message' => substr( is_string( $first ) ? $first : '', 0, 190 ) ] );

			return new \WP_Error( 'shopify_graphql', is_string( $first ) ? $first : 'GraphQL error' );
		}

		return is_array( $response['data'] ?? null ) ? $response['data'] : new \WP_Error( 'shopify_graphql', 'Empty GraphQL response.' );
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
