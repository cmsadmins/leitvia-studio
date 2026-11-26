<?php
/**
 * MCP server leitvia of Studio, without the MCP Adapter.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

use LeitviaStudio\Gateway\Mcp;
use LeitviaStudio\Gateway\Packages;

defined( 'ABSPATH' ) || exit;

/**
 * Revisions, error codes and identity of the server. Requests with _meta run stateless under 2026-07-28; initialize
 * starts a session under 2025-11-25, which also serves clients asking for 2025-06-18 or 2025-03-26.
 */
final class Server {

	public const LATEST = '2026-07-28';

	public const LEGACY = '2025-11-25';

	/**
	 * Revisions an initialize may ask for; the server answers with the one asked for, else with LEGACY.
	 */
	public const LEGACY_ACCEPTED = array( '2025-11-25', '2025-06-18', '2025-03-26' );

	public const HEADER_MISMATCH = -32020;

	public const UNSUPPORTED_VERSION = -32022;

	/**
	 * Resource not found under 2025-11-25; 2026-07-28 answers -32602 instead.
	 */
	public const RESOURCE_NOT_FOUND = -32002;

	/**
	 * A capability the client did not declare is needed, data.requiredCapabilities names it.
	 */
	public const MISSING_CAPABILITY = -32021;

	/**
	 * Route of the server on rest_api_init, the event stream on rest_pre_serve_request, the event source of the
	 * subscriptions, the runner of tasks, the prompts and the hooks of OAuth.
	 */
	public static function boot(): void {
		add_action( 'rest_api_init', array( Endpoint::class, 'register' ) );
		add_filter( 'rest_pre_serve_request', array( Stream::class, 'serve' ), 10, 2 );
		Subscriptions::boot();
		Tasks::boot();
		Prompts::boot();
		OAuth\OAuth::boot();
	}

	/**
	 * Revisions the server speaks, the newest first.
	 *
	 * @return list<string>
	 */
	public static function versions(): array {
		return array( self::LATEST, self::LEGACY );
	}

	/**
	 * Implementation object of the server: serverInfo.
	 *
	 * @return array{name: string, title: string, version: string}
	 */
	public static function info(): array {
		return array(
			'name'    => Mcp::SERVER,
			'title'   => leitvia_studio_brand_short(),
			'version' => LEITVIA_STUDIO_VERSION,
		);
	}

	/**
	 * How an agent starts, for initialize and server/discover, by the state of the site: without a valid contract the
	 * setup first, with open change packages the Inbox first, else the contract and the outline before any write.
	 * Texts of Studio only, never data of the site.
	 */
	public static function instructions(): string {
		if ( ! leitvia_studio_contract()->is_valid() ) {
			return __( 'The active theme has no valid Leitvia design contract yet. Set the site up first with the skill leitvia-setup: it activates the Leitvia theme or repairs its contract file. Until then leitvia-get-contract answers leitvia_no_contract.', 'leitvia-studio' );
		}
		/* translators: %s: short product name */
		$start = sprintf( __( 'Read the design contract with leitvia-get-contract and the outline of a page with leitvia-get-page-outline before you change it. Every write runs through the %s pipeline with autonomy levels, checks and rollback; what may not go live waits as a package in the Inbox.', 'leitvia-studio' ), leitvia_studio_brand_short() );
		$open  = Packages::count_open( current_user_can( 'manage_options' ) ? 0 : get_current_user_id() );
		if ( 0 === $open ) {
			return $start;
		}
		/* translators: %d: number of open change packages */
		$inbox = sprintf( _n( '%d change package waits in the Inbox. Read it with leitvia-list-packages and leitvia-get-package (resource leitvia://inbox) before you start new changes, so the same change is not built twice.', '%d change packages wait in the Inbox. Read them with leitvia-list-packages and leitvia-get-package (resource leitvia://inbox) before you start new changes, so the same change is not built twice.', $open, 'leitvia-studio' ), $open );
		return $inbox . ' ' . $start;
	}
}
