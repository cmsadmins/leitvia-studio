<?php
/**
 * Protocol revision and headers of an MCP request.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go out as JSON-RPC, never as HTML.

/**
 * Decides per request which revision applies. A request whose _meta carries io.modelcontextprotocol/protocolVersion runs
 * stateless under 2026-07-28, and its headers MCP-Protocol-Version, Mcp-Method and Mcp-Name must repeat the body.
 * Arguments a tool marks with x-mcp-header repeat as Mcp-Param-<name>.
 * initialize negotiates 2025-11-25 or older; every later request of that client needs its own Mcp-Session-Id.
 */
final class Envelope {

	/**
	 * Methods whose name, URI or task ID is repeated in the header Mcp-Name.
	 */
	private const NAMED = array( 'tools/call', 'resources/read', 'prompts/get', 'tasks/get', 'tasks/update', 'tasks/cancel' );

	/**
	 * Context of a JSON-RPC request.
	 *
	 * @param array<string, mixed> $message JSON-RPC request with id, from json_decode( ..., true ).
	 * @param \WP_REST_Request     $request REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @throws Protocol_Error When revision, headers or session do not fit.
	 */
	public static function read( array $message, \WP_REST_Request $request ): Context {
		if ( '2.0' !== ( $message['jsonrpc'] ?? null ) || ! is_string( $message['method'] ?? null ) ) {
			throw new Protocol_Error( -32600, 'Invalid Request' );
		}
		$params = is_array( $message['params'] ?? null ) ? $message['params'] : array();
		$meta   = is_array( $params['_meta'] ?? null ) ? $params['_meta'] : array();
		if ( isset( $meta['io.modelcontextprotocol/protocolVersion'] ) ) {
			if ( ! is_string( $meta['io.modelcontextprotocol/protocolVersion'] ) ) {
				throw new Protocol_Error( -32600, 'io.modelcontextprotocol/protocolVersion must be a string.' );
			}
			return self::modern( $meta['io.modelcontextprotocol/protocolVersion'], $message['method'], $params, $meta, $request );
		}
		if ( 'initialize' === $message['method'] ) {
			$asked        = is_string( $params['protocolVersion'] ?? null ) ? $params['protocolVersion'] : '';
			$capabilities = is_array( $params['capabilities'] ?? null ) ? $params['capabilities'] : array();
			return new Context( Sessions::negotiate( $asked ), false, $capabilities );
		}
		return self::legacy( $request );
	}

	/**
	 * Stateless request under 2026-07-28.
	 *
	 * @param string               $version Revision from _meta.
	 * @param string               $method  Method.
	 * @param array<string, mixed> $params  Params.
	 * @param array<string, mixed> $meta    _meta of the params.
	 * @param \WP_REST_Request     $request REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @throws Protocol_Error When the revision is unknown, a header differs or capabilities are missing.
	 */
	private static function modern( string $version, string $method, array $params, array $meta, \WP_REST_Request $request ): Context {
		if ( Server::LATEST !== $version ) {
			throw new Protocol_Error(
				Server::UNSUPPORTED_VERSION,
				'Unsupported protocol version',
				400,
				array(
					'supported' => Server::versions(),
					'requested' => $version,
				)
			);
		}
		self::same( 'MCP-Protocol-Version', $version, $request );
		self::same( 'Mcp-Method', $method, $request );
		if ( in_array( $method, self::NAMED, true ) ) {
			$name = $params['name'] ?? $params['uri'] ?? $params['taskId'] ?? '';
			if ( ! is_string( $name ) ) {
				throw new Protocol_Error( -32602, 'name, uri or taskId must be a string.' );
			}
			self::same( 'Mcp-Name', $name, $request );
			if ( 'tools/call' === $method ) {
				$tool = Tools::abilities()[ $name ] ?? null;
				self::params( null === $tool ? array() : Tools::param_headers( $tool->get_input_schema() ), is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array(), $request );
			}
		}
		$capabilities = $meta['io.modelcontextprotocol/clientCapabilities'] ?? null;
		if ( ! is_array( $capabilities ) ) {
			throw new Protocol_Error( -32602, 'Missing io.modelcontextprotocol/clientCapabilities in _meta.' );
		}
		return new Context( $version, true, $capabilities );
	}

	/**
	 * Request of a client that started a session with initialize.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @throws Protocol_Error Without a session of the current user, or with another revision in the header.
	 */
	private static function legacy( \WP_REST_Request $request ): Context {
		$id = (string) $request->get_header( 'mcp_session_id' );
		if ( '' === $id ) {
			throw new Protocol_Error( -32600, 'Send initialize first, or io.modelcontextprotocol/protocolVersion in _meta.', 400, array( 'supported' => Server::versions() ) );
		}
		$session = Sessions::get( $id );
		if ( null === $session ) {
			throw new Protocol_Error( -32600, 'Session not found.', 404 );
		}
		$header = (string) $request->get_header( 'mcp_protocol_version' );
		if ( '' !== $header && $header !== $session['version'] ) {
			throw new Protocol_Error( Server::HEADER_MISMATCH, 'MCP-Protocol-Version does not match the session.', 400, array( 'header' => 'MCP-Protocol-Version' ) );
		}
		return new Context( $session['version'], false, $session['capabilities'], $id );
	}

	/**
	 * A header must repeat the body exactly. Names are case-insensitive, values are not.
	 *
	 * @param string           $header   Header name.
	 * @param string           $expected Value from the body.
	 * @param \WP_REST_Request $request  REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @throws Protocol_Error When it is missing or differs.
	 */
	private static function same( string $header, string $expected, \WP_REST_Request $request ): void {
		if ( (string) $request->get_header( $header ) !== $expected ) {
			throw new Protocol_Error( Server::HEADER_MISMATCH, sprintf( '%s does not match the request body.', $header ), 400, array( 'header' => $header ) );
		}
	}

	/**
	 * Arguments a tool marks with x-mcp-header come as header Mcp-Param-<name> too, with the same value: plain, or as
	 * =?base64?<value>?= for values a header cannot carry. A header without its argument differs as well.
	 *
	 * @param array<string, string> $map       Header suffix => argument name, from Tools::param_headers().
	 * @param array<string, mixed>  $arguments Arguments of tools/call.
	 * @param \WP_REST_Request      $request   REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @throws Protocol_Error When a header is missing, left over or differs.
	 */
	public static function params( array $map, array $arguments, \WP_REST_Request $request ): void {
		foreach ( $map as $header => $argument ) {
			$name  = 'Mcp-Param-' . $header;
			$raw   = $request->get_header( $name );
			$given = array_key_exists( $argument, $arguments ) && null !== $arguments[ $argument ];
			if ( null === $raw && ! $given ) {
				continue;
			}
			$value = $given ? self::header_text( $arguments[ $argument ] ) : null;
			if ( null === $raw || null === $value || self::decode( $raw ) !== $value ) {
				throw new Protocol_Error( Server::HEADER_MISMATCH, sprintf( '%s does not match the request body.', $name ), 400, array( 'header' => $name ) );
			}
		}
	}

	/**
	 * Value of an Mcp-Param header: =?base64?…?= decoded, anything else as sent; null for invalid base64.
	 *
	 * @param string $raw Header value.
	 */
	public static function decode( string $raw ): ?string {
		if ( ! preg_match( '/^=\?base64\?([A-Za-z0-9+\/]*={0,2})\?=$/D', $raw, $match ) ) {
			return $raw;
		}
		$value = base64_decode( $match[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Header encoding of MCP 2026-07-28.
		return false === $value ? null : $value;
	}

	/**
	 * An argument as header text: strings as they are, booleans as true or false, numbers as JSON; null for others.
	 *
	 * @param mixed $value Argument.
	 */
	private static function header_text( $value ): ?string {
		return match ( true ) {
			is_string( $value )                    => $value,
			is_bool( $value )                      => $value ? 'true' : 'false',
			is_int( $value ) || is_float( $value ) => (string) wp_json_encode( $value ),
			default                                => null,
		};
	}
}
