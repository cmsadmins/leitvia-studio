<?php
/**
 * REST routes of OAuth: metadata, token, revocation and registration.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp\OAuth;

use LeitviaStudio\Gateway\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Below /wp-json/leitvia-studio/v1/oauth/. Every route is open, as OAuth needs: the token endpoint checks code or
 * refresh token, client and PKCE itself. Answers carry Cache-Control no-store, errors the OAuth form {error,
 * error_description}. Without OAuth no route exists; registration exists only with DCR switched on. Registration and
 * token endpoint are anonymous, so both count requests per shortened IP address (THROTTLE) and answer 429 beyond it.
 */
final class Routes {

	/**
	 * Requests per shortened IP address and window in seconds, by route.
	 */
	public const THROTTLE = array(
		'register' => array( 10, HOUR_IN_SECONDS ),
		'token'    => array( 60, MINUTE_IN_SECONDS ),
	);

	/**
	 * Registers the routes. Runs on rest_api_init.
	 */
	public static function register(): void {
		if ( ! OAuth::enabled() ) {
			return;
		}
		$routes = array(
			'protected-resource'   => array( 'GET', static fn(): \WP_REST_Response => self::answer( OAuth::protected_resource() ) ),
			'authorization-server' => array( 'GET', static fn(): \WP_REST_Response => self::answer( OAuth::authorization_server() ) ),
			'token'                => array( 'POST', array( self::class, 'token' ) ),
			'revoke'               => array( 'POST', array( self::class, 'revoke' ) ),
		);
		if ( OAuth::dcr_enabled() ) {
			$routes['register'] = array( 'POST', array( self::class, 'register_client' ) );
		}
		foreach ( $routes as $name => [ $method, $callback ] ) {
			register_rest_route(
				Mcp::ROUTE_NAMESPACE,
				'/oauth/' . $name,
				array(
					array(
						'methods'             => $method,
						'callback'            => $callback,
						// OAuth endpoints are public; each callback checks what it is given.
						'permission_callback' => '__return_true',
					),
					// The answers are defined by the RFCs (8414, 9728, 7591, 6749); OPTIONS names them.
					'schema' => static fn(): array => array(
						'$schema' => 'http://json-schema.org/draft-04/schema#',
						'title'   => 'leitvia-studio-oauth-' . $name,
						'type'    => 'object',
					),
				)
			);
		}
	}

	/**
	 * Token endpoint: grants authorization_code and refresh_token.
	 *
	 * @param \WP_REST_Request $request Form request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function token( \WP_REST_Request $request ): \WP_REST_Response {
		$busy = self::throttle( 'token' );
		if ( $busy ) {
			return $busy;
		}
		$param  = self::body( $request );
		$result = match ( $param( 'grant_type' ) ) {
			'authorization_code' => Tokens::exchange( $param( 'code' ), $param( 'client_id' ), $param( 'redirect_uri' ), $param( 'code_verifier' ), $param( 'resource' ) ),
			'refresh_token'      => Tokens::refresh( $param( 'refresh_token' ), $param( 'client_id' ), $param( 'scope' ), $param( 'resource' ) ),
			default              => new \WP_Error( 'unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.', array( 'status' => 400 ) ),
		};
		return self::answer( $result );
	}

	/**
	 * Revocation endpoint (RFC 7009): always 200.
	 *
	 * @param \WP_REST_Request $request Form request with token and client_id.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$param = self::body( $request );
		Tokens::revoke_token( $param( 'token' ), $param( 'client_id' ) );
		return self::answer( new \stdClass() );
	}

	/**
	 * Registration endpoint (RFC 7591).
	 *
	 * @param \WP_REST_Request $request JSON request with client metadata.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function register_client( \WP_REST_Request $request ): \WP_REST_Response {
		$busy = self::throttle( 'register' );
		if ( $busy ) {
			return $busy;
		}
		return self::answer( Clients::register( (array) $request->get_json_params() ), 201 );
	}

	/**
	 * Reader of the form body: codes, tokens and verifiers never count from the query, where logs and referrers keep
	 * them (OAuth 2.1, section 3.2).
	 *
	 * @param \WP_REST_Request $request Form request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \Closure(string): string
	 */
	private static function body( \WP_REST_Request $request ): \Closure {
		$body = $request->get_body_params();
		return static fn( string $key ): string => is_string( $body[ $key ] ?? null ) ? $body[ $key ] : '';
	}

	/**
	 * Counts a request of the shortened IP address on a route; the 429 answer with Retry-After once the limit of the
	 * window is reached, null below it.
	 *
	 * @param string $route register or token.
	 */
	private static function throttle( string $route ): ?\WP_REST_Response {
		[ $limit, $window ] = self::THROTTLE[ $route ];
		/**
		 * Filters how many requests a shortened IP address may send to an anonymous OAuth route per window; 0 switches
		 * the limit off, for example behind a proxy that hides the addresses.
		 *
		 * @param int    $limit Requests per window: 10 an hour for register, 60 a minute for token.
		 * @param string $route register or token.
		 */
		$limit = (int) apply_filters( 'leitvia_studio_oauth_throttle', $limit, $route );
		if ( $limit <= 0 ) {
			return null;
		}
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_privacy_anonymize_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		$key  = 'leitvia_oauth_throttle_' . md5( $route . '|' . $ip );
		$now  = time();
		$seen = get_transient( $key );
		// ponytail: fixed window in a transient, not atomic; an object cache counter when bursts must count exactly.
		$seen = is_array( $seen ) && (int) ( $seen['until'] ?? 0 ) > $now ? $seen : array(
			'count' => 0,
			'until' => $now + $window,
		);
		if ( (int) $seen['count'] >= $limit ) {
			$response = self::answer( new \WP_Error( 'temporarily_unavailable', 'Too many requests from this address. Try again later.', array( 'status' => 429 ) ) );
			$response->header( 'Retry-After', (string) max( 1, (int) $seen['until'] - $now ) );
			return $response;
		}
		$seen['count'] = (int) $seen['count'] + 1;
		set_transient( $key, $seen, max( 1, (int) $seen['until'] - $now ) );
		return null;
	}

	/**
	 * OAuth answer: the result, or the error as {error, error_description}; never cached.
	 *
	 * @param mixed $result Result or WP_Error.
	 * @param int   $status Status of a result.
	 */
	private static function answer( $result, int $status = 200 ): \WP_REST_Response {
		if ( is_wp_error( $result ) ) {
			$data     = $result->get_error_data();
			$response = new \WP_REST_Response(
				array(
					'error'             => $result->get_error_code(),
					'error_description' => $result->get_error_message(),
				),
				is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400
			);
		} else {
			$response = new \WP_REST_Response( $result, $status );
		}
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}
}
