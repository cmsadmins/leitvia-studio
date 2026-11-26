<?php
/**
 * Error of the MCP protocol layer, answered as JSON-RPC error.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * JSON-RPC code, HTTP status and data of a protocol error. Rejections of abilities are no protocol errors, they come
 * back as tool results with isError.
 */
final class Protocol_Error extends \Exception {

	/**
	 * Error with JSON-RPC code, status and data.
	 *
	 * @param int                  $rpc_code JSON-RPC error code.
	 * @param string               $message  Message for the client.
	 * @param int                  $status   HTTP status under 2026-07-28.
	 * @param array<string, mixed> $data     Data of the error.
	 */
	public function __construct( public readonly int $rpc_code, string $message, public readonly int $status = 400, public readonly array $data = array() ) {
		parent::__construct( $message );
	}
}
