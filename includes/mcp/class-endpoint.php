<?php
/**
 * REST route of the MCP server leitvia.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Mcp;
use LeitviaStudio\Mcp\OAuth\Bearer;

defined( 'ABSPATH' ) || exit;

/**
 * A request signs in with an application password or with an OAuth bearer token (OAuth\Bearer); a token may call
 * only what its scopes allow. POST carries one JSON-RPC message and gets a JSON response, subscriptions/listen of 2026-07-28 an event stream
 * (Stream); a tools/call with _meta.progressToken that accepts an event stream gets its progress and response as
 * stream (Progress); DELETE ends a 2025-11-25 session; GET has no stream and gets 405. Errors of the protocol layer use the HTTP status of 2026-07-28; under 2025-11-25 errors of a method come
 * with 200, as clients of that revision expect.
 */
final class Endpoint {

	/**
	 * Registers the route. Runs on rest_api_init.
	 */
	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', array( self::class, 'let_parse_error_through' ), 10, 3 );
		register_rest_route(
			Mcp::ROUTE_NAMESPACE,
			'/' . Mcp::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'post' ),
					// Sign-in and origin are checked in post(), which answers 401 with WWW-Authenticate and 403 itself.
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'delete' ),
					'permission_callback' => array( Abilities::class, 'can_use' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => static fn(): \WP_REST_Response => new \WP_REST_Response( null, 405, array( 'Allow' => 'POST, DELETE' ) ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * WordPress rejects a body that is no JSON with rest_invalid_json before the callback runs. On this route post()
	 * answers it as JSON-RPC parse error instead.
	 *
	 * @param mixed            $response Null or the error WordPress found so far.
	 * @param mixed            $handler  Route handler.
	 * @param \WP_REST_Request $request  REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function let_parse_error_through( $response, $handler, \WP_REST_Request $request ) {
		unset( $handler );
		if ( is_wp_error( $response ) && 'rest_invalid_json' === $response->get_error_code() && '/' . Mcp::ROUTE_NAMESPACE . '/' . Mcp::ROUTE === untrailingslashit( $request->get_route() ) ) {
			return null;
		}
		return $response;
	}

	/**
	 * Answers one JSON-RPC message.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function post( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::origin_allowed( (string) $request->get_header( 'origin' ) ) ) {
			return new \WP_REST_Response( array( 'message' => 'Origin not allowed.' ), 403 );
		}
		if ( ! Abilities::can_use() ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Connect over OAuth, or sign in with the user name and application password of an agent.', 'leitvia-studio' ) ),
				401,
				array( 'WWW-Authenticate' => Bearer::challenge() )
			);
		}
		// The patterns may have changed since the last request; at most once a minute.
		Subscriptions::check();
		$message = json_decode( $request->get_body(), true );
		if ( ! is_array( $message ) ) {
			return self::error( null, new Protocol_Error( -32700, 'Parse error' ), 400 );
		}
		if ( array_is_list( $message ) ) {
			return self::error( null, new Protocol_Error( -32600, 'Batches are not supported.' ), 400 );
		}
		if ( ! array_key_exists( 'id', $message ) ) {
			// Notifications: the server acts on none of them yet.
			return new \WP_REST_Response( null, 202 );
		}
		$id = $message['id'];
		try {
			$context = Envelope::read( $message, $request );
		} catch ( Protocol_Error $error ) {
			return self::error( $id, $error, $error->status );
		}
		// Scopes and rate limit of an OAuth token; HTTP status in both revisions, OAuth clients read it.
		$denial = Bearer::denial( (string) $message['method'], is_array( $message['params'] ?? null ) ? $message['params'] : array() );
		if ( null !== $denial ) {
			$response = self::error( $id, $denial['error'], $denial['error']->status );
			foreach ( $denial['headers'] as $name => $value ) {
				$response->header( $name, $value );
			}
			return $response;
		}
		if ( $context->modern && 'subscriptions/listen' === $message['method'] ) {
			return self::listen( $id, is_array( $message['params'] ?? null ) ? $message['params'] : array(), $context );
		}
		$method = (string) $message['method'];
		$params = is_array( $message['params'] ?? null ) ? $message['params'] : array();
		$token  = 'tools/call' === $method && str_contains( (string) $request->get_header( 'accept' ), 'text/event-stream' ) ? Progress::token( $params ) : null;
		if ( null !== $token ) {
			// Errors that need no work answer as JSON with their status, as without a stream.
			try {
				Tools::precheck( $params, $context );
			} catch ( Protocol_Error $error ) {
				return self::error( $id, $error, $context->modern ? $error->status : 200 );
			}
			// The ability reports progress while it runs; the response is the last event of the stream.
			return new Stream(
				static function ( callable $write ) use ( $token, $id, $method, $params, $context ): void {
					// A client that left gets nothing more; the work runs to its end.
					$send = static function ( string $chunk ) use ( $write ): void {
						if ( connection_aborted() ) {
							return;
						}
						$write( $chunk );
					};
					Progress::during(
						$token,
						$send,
						static function () use ( $send, $id, $method, $params, $context ): void {
							$send( 'data: ' . wp_json_encode( self::answer( $id, $method, $params, $context )[0] ) . "\n\n" );
						}
					);
				},
				null
			);
		}
		[ $body, $status, $headers ] = self::answer( $id, $method, $params, $context );
		return new \WP_REST_Response( $body, $status, $headers );
	}

	/**
	 * JSON-RPC response to a request: body, HTTP status and headers. Errors of the protocol layer carry the HTTP status
	 * of 2026-07-28; under 2025-11-25 errors of a method come with 200.
	 *
	 * @param mixed                $id      ID of the request.
	 * @param string               $method  Method.
	 * @param array<string, mixed> $params  Params.
	 * @param Context              $context Protocol state.
	 * @return array{0: array<string, mixed>, 1: int, 2: array<string, string>}
	 */
	private static function answer( $id, string $method, array $params, Context $context ): array {
		try {
			$result = Dispatcher::handle( $method, $params, $context );
		} catch ( Protocol_Error $error ) {
			return array( self::fault( $id, $error ), $context->modern ? $error->status : 200, array() );
		} catch ( \Throwable $e ) {
			// No details of the failure leave the site.
			return array( self::fault( $id, new Protocol_Error( -32603, 'Internal error', 500 ) ), $context->modern ? 500 : 200, array() );
		}
		$headers = array();
		if ( $context->modern ) {
			$result         += array( 'resultType' => 'complete' );
			$result['_meta'] = array( 'io.modelcontextprotocol/serverInfo' => Server::info() ) + (array) ( $result['_meta'] ?? array() );
		} elseif ( 'initialize' === $method ) {
			$session = Sessions::start( $context->version, $context->capabilities );
			if ( '' !== $session ) {
				$headers['Mcp-Session-Id'] = $session;
			}
		}
		return array(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array() === $result ? new \stdClass() : $result,
			),
			200,
			$headers,
		);
	}

	/**
	 * Ends a session of revision 2025-11-25.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public static function delete( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (string) $request->get_header( 'mcp_session_id' );
		if ( '' === $id ) {
			return new \WP_REST_Response( null, 405, array( 'Allow' => 'POST' ) );
		}
		return new \WP_REST_Response( null, Sessions::end( $id ) ? 204 : 404 );
	}

	/**
	 * Opens the event stream of subscriptions/listen, or answers 429 while the user has Subscriptions::MAX_STREAMS
	 * open.
	 *
	 * @param mixed                $id      ID of the request.
	 * @param array<string, mixed> $params  Params of subscriptions/listen.
	 * @param Context              $context Protocol state.
	 */
	private static function listen( $id, array $params, Context $context ): \WP_REST_Response {
		if ( ! is_array( $params['notifications'] ?? null ) || array() === $params['notifications'] ) {
			return self::error( $id, new Protocol_Error( -32602, 'params.notifications is required.' ), 400 );
		}
		if ( array_key_exists( 'taskIds', $params['notifications'] ) && ! Tasks::offered( $context ) ) {
			return self::error( $id, new Protocol_Error( Server::MISSING_CAPABILITY, 'Missing required client capability', 400, array( 'requiredCapabilities' => array( 'extensions' => array( Tasks::EXTENSION => new \stdClass() ) ) ) ), 400 );
		}
		try {
			$slot = Subscriptions::acquire();
		} catch ( Protocol_Error $error ) {
			return self::error( $id, $error, $error->status );
		}
		// Frees the slot also when the process dies before the stream starts (the window's finally covers the rest).
		register_shutdown_function( array( Subscriptions::class, 'release' ), $slot );
		return new Stream( static fn( callable $send, callable $tick ) => Subscriptions::listen( $params, $id, $slot, $send, $tick ) );
	}

	/**
	 * A request without Origin comes from a server or a CLI; a browser may only call from the site itself: same
	 * scheme, host and port.
	 *
	 * @param string $origin Value of the header Origin.
	 */
	private static function origin_allowed( string $origin ): bool {
		return '' === $origin || self::origin_parts( $origin ) === self::origin_parts( home_url() );
	}

	/**
	 * Scheme, host and port of an address, the default port filled in.
	 *
	 * @param string $url Address.
	 * @return array{string, string, int}
	 */
	private static function origin_parts( string $url ): array {
		$parts  = (array) wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		return array( $scheme, strtolower( (string) ( $parts['host'] ?? '' ) ), (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) ) );
	}

	/**
	 * JSON-RPC error response.
	 *
	 * @param mixed          $id     ID of the request, null when unknown.
	 * @param Protocol_Error $error  Error.
	 * @param int            $status HTTP status.
	 */
	private static function error( $id, Protocol_Error $error, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( self::fault( $id, $error ), $status );
	}

	/**
	 * Body of a JSON-RPC error.
	 *
	 * @param mixed          $id    ID of the request, null when unknown.
	 * @param Protocol_Error $error Error.
	 * @return array<string, mixed>
	 */
	private static function fault( $id, Protocol_Error $error ): array {
		$body = array(
			'code'    => $error->rpc_code,
			'message' => $error->getMessage(),
		);
		if ( array() !== $error->data ) {
			$body['data'] = $error->data;
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $body,
		);
	}
}
