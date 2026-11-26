<?php
/**
 * Progress of abilities as notifications/progress on the event stream of a request.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities report progress with do_action( 'leitvia_studio_progress', $done, $total, $message ): $total 0 when
 * unknown, $message short and in English. A tools/call whose _meta carries progressToken and that accepts
 * text/event-stream gets its response as stream (Endpoint): each report as notifications/progress with that token,
 * the response as last event. Both revisions; tasks show progress as statusMessage instead (Tasks::progress()).
 */
final class Progress {

	public const ACTION = 'leitvia_studio_progress';

	/**
	 * The progress token of a request, null without one.
	 *
	 * @param array<string, mixed> $params Params.
	 * @return string|int|float|null
	 */
	public static function token( array $params ) {
		$token = is_array( $params['_meta'] ?? null ) ? ( $params['_meta']['progressToken'] ?? null ) : null;
		return is_string( $token ) || is_int( $token ) || is_float( $token ) ? $token : null;
	}

	/**
	 * Runs the work and writes each progress report of it as notification.
	 *
	 * @param string|int|float $token Progress token.
	 * @param callable         $send  Writes a chunk of the stream.
	 * @param callable         $work  The work; writes the response itself.
	 */
	public static function during( $token, callable $send, callable $work ): void {
		$last     = null;
		$listener = static function ( $done, $total = 0, $message = '' ) use ( $token, $send, &$last ): void {
			$progress = is_numeric( $done ) ? 0 + $done : 0;
			// Progress only grows; an ability that counts from the start again is not passed on.
			if ( null !== $last && $progress <= $last ) {
				return;
			}
			$last   = $progress;
			$params = array(
				'progressToken' => $token,
				'progress'      => $progress,
			);
			if ( is_numeric( $total ) && $total > 0 ) {
				$params['total'] = 0 + $total;
			}
			if ( is_string( $message ) && '' !== $message ) {
				$params['message'] = $message;
			}
			$send(
				'data: ' . wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'method'  => 'notifications/progress',
						'params'  => $params,
					)
				) . "\n\n"
			);
		};
		add_action( self::ACTION, $listener, 10, 3 );
		try {
			$work();
		} finally {
			remove_action( self::ACTION, $listener, 10 );
		}
	}
}
