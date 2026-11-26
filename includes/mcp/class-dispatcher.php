<?php
/**
 * MCP methods of the server leitvia.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go out as JSON-RPC, never as HTML.

/**
 * Hands a method to its handler. 2026-07-28 knows server/discover and no initialize or ping; 2025-11-25 the other way
 * round. Both read resources and completions; lists and reads under 2026-07-28 are cacheable per user (cacheScope
 * private). Prompts in both revisions; tasks/get, tasks/update and tasks/cancel of the tasks extension under
 * 2026-07-28 only, no tasks/list. subscriptions/listen streams, Endpoint answers it before the dispatcher.
 */
final class Dispatcher {

	/**
	 * Milliseconds a client may keep a list, a read or the discover result.
	 */
	private const TTL_MS = 60000;

	/**
	 * Result of a method.
	 *
	 * @param string               $method  Method.
	 * @param array<string, mixed> $params  Params.
	 * @param Context              $context Protocol state.
	 * @return array<string, mixed>
	 * @throws Protocol_Error For an unknown method or a failed call.
	 */
	public static function handle( string $method, array $params, Context $context ): array {
		if ( $context->modern ) {
			return match ( $method ) {
				'server/discover'          => self::discover( $context ),
				'tools/list'               => Tools::list( $context ) + self::cache(),
				'tools/call'               => Tools::call( $params, $context ),
				'resources/list'           => Resources::list( $context ) + self::cache(),
				'resources/templates/list' => Resources::templates() + self::cache(),
				'resources/read'           => Resources::read( $params, $context ) + self::cache(),
				'completion/complete'      => Completion::complete( $params ),
				'prompts/list'             => Prompts::list() + self::cache(),
				'prompts/get'              => Prompts::get( $params ),
				'tasks/get'                => Tasks::get( $params, $context ),
				'tasks/update'             => Tasks::update( $params, $context ),
				'tasks/cancel'             => Tasks::cancel( $params, $context ),
				default                    => throw self::unknown( $method ),
			};
		}
		return match ( $method ) {
			'initialize'               => self::initialize( $context ),
			'ping'                     => array(),
			'tools/list'               => Tools::list( $context ),
			'tools/call'               => Tools::call( $params, $context ),
			'resources/list'           => Resources::list( $context ),
			'resources/templates/list' => Resources::templates(),
			'resources/read'           => Resources::read( $params, $context ),
			'completion/complete'      => Completion::complete( $params ),
			'prompts/list'             => Prompts::list(),
			'prompts/get'              => Prompts::get( $params ),
			default                    => throw self::unknown( $method ),
		};
	}

	/**
	 * Result of server/discover.
	 *
	 * @param Context $context Protocol state.
	 * @return array<string, mixed>
	 */
	private static function discover( Context $context ): array {
		return array(
			'supportedVersions' => Server::versions(),
			'capabilities'      => self::capabilities( $context ),
			'instructions'      => Server::instructions(),
		) + self::cache();
	}

	/**
	 * Result of initialize.
	 *
	 * @param Context $context Protocol state with the negotiated revision.
	 * @return array<string, mixed>
	 */
	private static function initialize( Context $context ): array {
		return array(
			'protocolVersion' => $context->version,
			'capabilities'    => self::capabilities( $context ),
			'serverInfo'      => Server::info(),
			'instructions'    => Server::instructions(),
		);
	}

	/**
	 * Capabilities of the server. Subscriptions, list changes and the tasks extension only under 2026-07-28, see
	 * Subscriptions and Tasks. The extension io.modelcontextprotocol/ui in both revisions, only for a client that
	 * declared it while a view is registered (Apps).
	 *
	 * @param Context $context Protocol state.
	 * @return array<string, mixed>
	 */
	private static function capabilities( Context $context ): array {
		$capabilities = array(
			'tools'       => new \stdClass(),
			'resources'   => array(
				'subscribe'   => $context->modern,
				'listChanged' => $context->modern,
			),
			'prompts'     => array( 'listChanged' => $context->modern ),
			'completions' => new \stdClass(),
		);
		if ( $context->modern ) {
			$capabilities['extensions'] = array( Tasks::EXTENSION => new \stdClass() );
		}
		if ( Apps::offered( $context ) ) {
			$capabilities['extensions'][ Apps::EXTENSION ] = new \stdClass();
		}
		return $capabilities;
	}

	/**
	 * Cache fields of a cacheable result.
	 *
	 * @return array{ttlMs: int, cacheScope: string}
	 */
	private static function cache(): array {
		return array(
			'ttlMs'      => self::TTL_MS,
			'cacheScope' => 'private',
		);
	}

	/**
	 * Error for a method the server does not offer.
	 *
	 * @param string $method Method.
	 */
	private static function unknown( string $method ): Protocol_Error {
		return new Protocol_Error( -32601, sprintf( 'Method not found: %s', $method ), 404 );
	}
}
