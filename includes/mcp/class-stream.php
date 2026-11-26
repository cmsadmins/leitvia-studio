<?php
/**
 * Event stream answer of the MCP server.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * A REST response whose body is an event stream. WP_REST_Server sends its status and headers (Content-Type
 * text/event-stream, no cache, X-Accel-Buffering no for nginx), then rest_pre_serve_request reaches serve(), which
 * writes the events and tells WordPress the response is served. Tests call run() with their own callbacks.
 */
final class Stream extends \WP_REST_Response {

	/**
	 * Body: function ( callable $send, callable $tick ): void.
	 *
	 * @var \Closure
	 */
	private \Closure $body;

	/**
	 * Seconds the request may run once the stream started, null to keep the limit of the server.
	 *
	 * @var int|null
	 */
	public readonly ?int $time_limit;

	/**
	 * Stream with a body.
	 *
	 * @param callable $body       Writes the stream through $send and waits through $tick.
	 * @param int|null $time_limit Seconds for the request, null to keep the limit; the listen window by default.
	 */
	public function __construct( callable $body, ?int $time_limit = Subscriptions::WINDOW + 15 ) {
		parent::__construct(
			null,
			200,
			array(
				'Content-Type'      => 'text/event-stream; charset=utf-8',
				'Cache-Control'     => 'no-cache, no-transform',
				'X-Accel-Buffering' => 'no',
			)
		);
		$this->body       = \Closure::fromCallable( $body );
		$this->time_limit = $time_limit;
	}

	/**
	 * Runs the body.
	 *
	 * @param callable $send Writes a chunk.
	 * @param callable $tick Waits a second, false when the client is gone.
	 */
	public function run( callable $send, callable $tick ): void {
		( $this->body )( $send, $tick );
	}

	/**
	 * Filter rest_pre_serve_request: writes a Stream and marks it served; other responses pass.
	 *
	 * @param bool  $served Whether another filter served the response.
	 * @param mixed $result Response.
	 * @return bool
	 */
	public static function serve( $served, $result ) {
		if ( $served || ! $result instanceof self ) {
			return $served;
		}
		// No compression layer may hold chunks back.
		ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Event stream of subscriptions/listen, compression would hold the events back.
		while ( ob_get_level() && ( ob_get_status()['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) ) {
			ob_end_flush();
		}
		// A listen window ends on its own and frees its slot in finally; a tool call finishes its writes after the
		// client left.
		ignore_user_abort( true );
		if ( null !== $result->time_limit && function_exists( 'set_time_limit' ) ) {
			set_time_limit( $result->time_limit ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A listen window or long tool call needs its own bounded time, set by the caller.
		}
		$result->run(
			static function ( string $chunk ): void {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Server-sent events with JSON, never HTML.
				flush();
			},
			static function (): bool {
				sleep( 1 );
				return ! connection_aborted();
			}
		);
		return true;
	}
}
