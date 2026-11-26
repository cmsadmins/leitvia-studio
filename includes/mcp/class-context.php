<?php
/**
 * Protocol state of one MCP request.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Revision of a request, whether it is stateless (2026-07-28), the capabilities the client declared (under 2025-11-25
 * the extensions its session keeps) and, under 2025-11-25, its session.
 */
final class Context {

	/**
	 * Request state.
	 *
	 * @param string               $version      Protocol revision.
	 * @param bool                 $modern       True under 2026-07-28.
	 * @param array<string, mixed> $capabilities Client capabilities: from _meta under 2026-07-28, from initialize under
	 *                                           2025-11-25, where later requests of the session get its extensions.
	 * @param string               $session      Session ID under 2025-11-25.
	 */
	public function __construct( public readonly string $version, public readonly bool $modern, public readonly array $capabilities = array(), public readonly string $session = '' ) {}
}
