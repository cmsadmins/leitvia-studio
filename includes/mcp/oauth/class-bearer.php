<?php
/**
 * Bearer tokens on the route of the MCP server.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp\OAuth;

use LeitviaStudio\Gateway\Mcp;
use LeitviaStudio\Mcp\Protocol_Error;
use LeitviaStudio\Mcp\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Signs in the user of an access token, only on the route of the MCP server through index.php and only when no other
 * way (cookie, application password) found a user; rest_pre_dispatch (gate()) and the hooks of admin, comments and
 * templates (outside()) take a sign-in anywhere else back. The guards of the agent role stay as they are. The scopes of the token decide
 * what it may call: leitvia:read the tools that only read and every method without a tool, leitvia:write the other
 * tools, leitvia:approve the decisions of a person in the tools an add-on names (approve_tools(), none without one).
 * Write and approve include read.
 * Each token family has its own rate limit per tool, besides the limits of the gateway per user.
 */
final class Bearer {

	public const SCOPE_ERROR = -32001;

	public const RATE_ERROR = -32000;

	public const READ_LIMIT = 120;

	public const WRITE_LIMIT = 30;

	/**
	 * Tools a person decides with under leitvia:approve: none, unless an add-on names some. Decisions in the chat
	 * belong in a view (MCP App) where the person decides, never in a tool the model calls with the token of a person;
	 * an add-on that offers such a view names its own tool for views here. Without one, a token with leitvia:approve
	 * only reads.
	 *
	 * @return list<string>
	 */
	public static function approve_tools(): array {
		/**
		 * Filters the tools a person may call with the scope leitvia:approve.
		 *
		 * @param list<string> $tools Tool names, empty by default.
		 */
		return array_values( array_filter( (array) apply_filters( 'leitvia_studio_mcp_approve_tools', array() ), 'is_string' ) );
	}

	/**
	 * Row of the access token of this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $token = null;

	/**
	 * Whether this request sent a bearer token that is not valid.
	 *
	 * @var bool
	 */
	private static bool $invalid = false;

	/**
	 * Filter determine_current_user: the user of a valid access token on the MCP route.
	 *
	 * @param int|false $user_id User found so far.
	 * @return int|false
	 */
	public static function determine( $user_id ) {
		if ( $user_id || ! OAuth::enabled() || ! self::on_mcp_route() ) {
			return $user_id;
		}
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', self::header(), $match ) ) {
			return $user_id;
		}
		$row = Tokens::access( $match[1] );
		if ( null === $row ) {
			self::$invalid = true;
			return $user_id;
		}
		self::$token = $row;
		Tokens::touch( $row );
		return (int) $row['user_id'];
	}

	/**
	 * Row of the access token of this request, null for other ways of signing in.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function token(): ?array {
		return self::$token;
	}

	/**
	 * Forgets the token of the request (tests, a second request in one process).
	 */
	public static function reset(): void {
		self::$token   = null;
		self::$invalid = false;
	}

	/**
	 * Scopes of the token, read added under write or approve.
	 *
	 * @return list<string>
	 */
	public static function scopes(): array {
		$scopes = null === self::$token ? array() : explode( ' ', (string) self::$token['scope'] );
		if ( array_intersect( array( OAuth::WRITE, OAuth::APPROVE ), $scopes ) ) {
			$scopes[] = OAuth::READ;
		}
		return array_values( array_unique( $scopes ) );
	}

	/**
	 * WWW-Authenticate of a 401: Bearer with metadata and scopes (RFC 9728, RFC 6750), Basic for application passwords;
	 * only Basic without OAuth.
	 */
	public static function challenge(): string {
		$basic = 'Basic realm="' . Mcp::SERVER . '"';
		if ( ! OAuth::enabled() ) {
			return $basic;
		}
		return 'Bearer ' . ( self::$invalid ? 'error="invalid_token", ' : '' ) . 'resource_metadata="' . OAuth::metadata_url() . '", scope="' . implode( ' ', OAuth::BASIC_SCOPES ) . '", ' . $basic;
	}

	/**
	 * Why a request with a bearer token may not run, with its headers: 403 when a scope is missing, 429 over the
	 * limit; null when it may run or came without token.
	 *
	 * @param string               $method JSON-RPC method.
	 * @param array<string, mixed> $params Params.
	 * @return array{error: Protocol_Error, headers: array<string, string>}|null
	 */
	public static function denial( string $method, array $params ): ?array {
		if ( null === self::$token ) {
			return null;
		}
		$tool  = 'tools/call' === $method && is_string( $params['name'] ?? null ) ? $params['name'] : '';
		$reads = '' === $tool || self::reads( $tool );
		$need  = OAuth::WRITE;
		if ( $reads ) {
			$need = OAuth::READ;
		} elseif ( in_array( $tool, self::approve_tools(), true ) && in_array( OAuth::APPROVE, self::scopes(), true ) ) {
			$need = OAuth::APPROVE;
		}
		if ( ! in_array( $need, self::scopes(), true ) ) {
			return array(
				'error'   => new Protocol_Error( self::SCOPE_ERROR, sprintf( 'The token lacks the scope %s.', $need ), 403, array( 'scope' => $need ) ),
				'headers' => array( 'WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="' . $need . '", resource_metadata="' . OAuth::metadata_url() . '"' ),
			);
		}
		$wait = '' === $tool ? 0 : self::wait( $tool, $reads );
		if ( 0 === $wait ) {
			return null;
		}
		return array(
			'error'   => new Protocol_Error( self::RATE_ERROR, sprintf( 'Too many calls of %1$s. Try again in %2$d seconds.', $tool, $wait ), 429, array( 'retryAfter' => $wait ) ),
			'headers' => array( 'Retry-After' => (string) $wait ),
		);
	}

	/**
	 * Whether the request goes to the route of the MCP server, read from the raw request as core's rest_api_loaded()
	 * does: rest_route first, else the path below the REST prefix (also /index.php/wp-json/). Only through index.php
	 * and never with rest_route in the body, which core would prefer and which could lead to any other route; the
	 * route is compared as sent, without sanitizing that could make another value equal.
	 */
	public static function on_mcp_route(): bool {
		$route = '/' . Mcp::ROUTE_NAMESPACE . '/' . Mcp::ROUTE;
		if ( isset( $_POST['rest_route'] ) || 'index.php' !== basename( isset( $_SERVER['SCRIPT_FILENAME'] ) ? (string) wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only checks that the body names no route and which script runs.
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only names the route, as core reads it.
		if ( isset( $_GET['rest_route'] ) ) {
			$asked = wp_unslash( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared exactly, never used.
			return is_string( $asked ) && untrailingslashit( $asked ) === $route;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? untrailingslashit( (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared exactly, never used.
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$rest = '/' . rest_get_url_prefix();
		return in_array( $path, array( $home . $rest . $route, $home . '/index.php' . $rest . $route ), true );
	}

	/**
	 * Filter rest_pre_dispatch, priority 0: a request signed in by a bearer token reaches the MCP route only. Any other
	 * route signs the user out again and gets 401, should a way past on_mcp_route() ever exist.
	 *
	 * @param mixed            $result  Response so far, null to dispatch.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function gate( $result, $server, $request ) {
		unset( $server );
		if ( null === self::$token || '/' . Mcp::ROUTE_NAMESPACE . '/' . Mcp::ROUTE === untrailingslashit( $request->get_route() ) ) {
			return $result;
		}
		self::sign_out();
		return new \WP_Error( 'leitvia_oauth_route', 'A bearer token is valid on the MCP route only.', array( 'status' => 401 ) );
	}

	/**
	 * Stops a request that a bearer token signed in outside the REST API. Runs on admin_init (admin, admin-ajax.php,
	 * admin-post.php), pre_comment_on_post and template_redirect; only a request with a bearer header looks the user up.
	 */
	public static function outside(): void {
		if ( 0 !== stripos( self::header(), 'bearer' ) ) {
			return;
		}
		get_current_user_id();
		if ( null === self::$token ) {
			return;
		}
		self::sign_out();
		wp_die( esc_html__( 'An access token is valid on the MCP server only.', 'leitvia-studio' ), '', array( 'response' => 401 ) );
	}

	/**
	 * Takes the sign-in of the token back.
	 */
	private static function sign_out(): void {
		self::reset();
		wp_set_current_user( 0 );
	}

	/**
	 * Value of the header Authorization; CGI setups hand it on as REDIRECT_HTTP_AUTHORIZATION.
	 */
	private static function header(): string {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				return trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
			}
		}
		return '';
	}

	/**
	 * Whether a tool only reads; an unknown tool counts as reading, the dispatcher answers it.
	 *
	 * @param string $tool Tool name.
	 */
	private static function reads( string $tool ): bool {
		$ability = Tools::abilities()[ $tool ] ?? null;
		return null === $ability || (bool) ( ( (array) $ability->get_meta_item( 'annotations', array() ) )['readonly'] ?? false );
	}

	/**
	 * Seconds to wait when the family of the token called the tool its limit of times in the last minute, else 0 and
	 * the call is counted.
	 *
	 * @param string $tool  Tool name, from Mcp-Name and the body (Envelope checked both).
	 * @param bool   $reads Whether the tool only reads.
	 */
	private static function wait( string $tool, bool $reads ): int {
		$token = (array) self::$token;
		/**
		 * Filters the calls per minute of one OAuth connection and one tool.
		 *
		 * @param int    $limit   READ_LIMIT or WRITE_LIMIT.
		 * @param string $tool    Tool name.
		 * @param bool   $reads   Whether the tool only reads.
		 * @param int    $user_id User of the token.
		 */
		$limit = (int) apply_filters( 'leitvia_studio_oauth_rate_limit', $reads ? self::READ_LIMIT : self::WRITE_LIMIT, $tool, $reads, (int) ( $token['user_id'] ?? 0 ) );
		// ponytail: sliding window in a transient, not atomic; an object cache counter when parallel calls must count exactly.
		$key  = 'leitvia_oauth_rate_' . md5( (string) ( $token['family'] ?? '' ) . '|' . $tool );
		$now  = time();
		$hits = array_values( array_filter( (array) get_transient( $key ), static fn( $hit ): bool => (int) $hit > $now - MINUTE_IN_SECONDS ) );
		if ( count( $hits ) >= $limit ) {
			return max( 1, MINUTE_IN_SECONDS - ( $now - (int) min( array() === $hits ? array( $now ) : $hits ) ) );
		}
		$hits[] = $now;
		set_transient( $key, $hits, MINUTE_IN_SECONDS );
		return 0;
	}
}
