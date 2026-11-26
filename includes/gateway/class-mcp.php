<?php
/**
 * MCP integration of the abilities of Studio.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * MCP integration of the abilities. Studio serves the server leitvia itself (LeitviaStudio\Mcp\Server) at
 * /wp-json/leitvia-studio/v1/mcp, with or without the MCP Adapter. The abilities set meta.mcp.public
 * (Studio::register_abilities()), so the default server of an active adapter offers them too through
 * discover-abilities, get-ability-info and execute-ability; this class keeps that second way safe and its rejections
 * complete.
 */
final class Mcp {

	public const SERVER = 'leitvia';

	public const ROUTE_NAMESPACE = 'leitvia-studio/v1';

	public const ROUTE = 'mcp';

	/**
	 * Ability through which the default server of the adapter runs every other ability.
	 */
	public const EXECUTE = 'mcp-adapter/execute-ability';

	/**
	 * Route of the default server of the adapter (DefaultServerFactory of 0.7.x).
	 */
	public const DEFAULT_ROUTE = '/mcp/mcp-adapter-default-server';

	/**
	 * Tools of the default server an agent may call besides execute-ability: they only read.
	 */
	private const DEFAULT_READ_TOOLS = array( 'mcp-adapter-discover-abilities', 'mcp-adapter-get-ability-info' );

	/**
	 * Last rejection of each Leitvia ability in this request. execute-ability keeps only the message of a WP_Error.
	 *
	 * @var array<string, \WP_Error>
	 */
	private static array $rejections = array();

	/**
	 * Route of the own server and the hooks for the default server of the adapter.
	 */
	public static function boot(): void {
		\LeitviaStudio\Mcp\Server::boot();
		add_filter( 'mcp_adapter_tool_call_result', array( self::class, 'error_details' ), 10, 5 );
		add_filter( 'wp_ability_execute_result', array( self::class, 'remember' ), 10, 2 );
	}

	/**
	 * Whether an agent may send this request to an MCP route. On the server leitvia every tool is a Leitvia ability.
	 * On the default server of the adapter execute-ability runs any public ability, so only calls of a Leitvia ability
	 * pass, plus the reading tools and the methods without a tool call (initialize, tools/list and the like). A batch
	 * passes only when every message does.
	 *
	 * @param \WP_REST_Request $request Request to an MCP route.
	 */
	public static function allows_agent( \WP_REST_Request $request ): bool {
		$route = untrailingslashit( $request->get_route() );
		if ( '/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE === $route ) {
			return true;
		}
		if ( self::DEFAULT_ROUTE !== $route ) {
			return false;
		}
		$body     = json_decode( (string) $request->get_body(), true );
		$messages = is_array( $body ) && array_is_list( $body ) ? $body : array( $body );
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				return false;
			}
			if ( 'tools/call' !== ( $message['method'] ?? '' ) ) {
				continue;
			}
			$tool = (string) ( $message['params']['name'] ?? '' );
			if ( in_array( $tool, self::DEFAULT_READ_TOOLS, true ) ) {
				continue;
			}
			$ability = (string) ( $message['params']['arguments']['ability_name'] ?? '' );
			if ( 'mcp-adapter-execute-ability' !== $tool || '' === $ability || null === leitvia_studio()->registry( 'abilities' )->get( $ability ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the current request is a call to an MCP server: the route of this server or of another server of the
	 * adapter (/mcp/…). A task of the server leitvia (Mcp\Tasks) counts as such a call too. Runs of an MCP client
	 * never apply a package at once, see Recipes::may_settle().
	 */
	public static function serving(): bool {
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? '/' . trim( (string) $GLOBALS['wp']->query_vars['rest_route'], '/' ) : '';
		return '/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE === $route || str_starts_with( $route, '/mcp/' ) || \LeitviaStudio\Mcp\Tasks::running();
	}

	/**
	 * Version of the active MCP Adapter, empty when it is not active.
	 */
	public static function version(): string {
		return defined( 'WP_MCP_VERSION' ) ? (string) constant( 'WP_MCP_VERSION' ) : '';
	}

	/**
	 * State for the Agents page and leitvia/get-site-health: the own server always answers; version is that of an
	 * active MCP Adapter, empty without one.
	 *
	 * @return array{ready: bool, version: string, reason: string, url: string, protocols: list<string>}
	 */
	public static function status(): array {
		return array(
			'ready'     => true,
			'version'   => self::version(),
			'reason'    => '',
			'url'       => rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE ),
			'protocols' => \LeitviaStudio\Mcp\Server::versions(),
		);
	}

	/**
	 * Names of all abilities of the Studio registry that are shown in REST, those of Studio and of compatible add-ons:
	 * the tools of the server.
	 *
	 * @return list<string>
	 */
	public static function abilities(): array {
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( null !== leitvia_studio()->registry( 'abilities' )->get( $ability->get_name() ) && true === $ability->get_meta_item( 'show_in_rest' ) ) {
				$names[] = $ability->get_name();
			}
		}
		return $names;
	}

	/**
	 * Keeps the rejection of a Leitvia ability, so error_details() can restore code and data after execute-ability of
	 * the default server reduced it to its message.
	 *
	 * @param mixed  $result       Result of the ability.
	 * @param string $ability_name Ability name.
	 * @return mixed The result, unchanged.
	 */
	public static function remember( $result, $ability_name = '' ) {
		if ( is_wp_error( $result ) && self::is_leitvia( (string) $ability_name ) ) {
			self::$rejections[ (string) $ability_name ] = $result;
		}
		return $result;
	}

	/**
	 * The adapter passes only the message of a WP_Error to the client. For Leitvia abilities the message becomes JSON
	 * with code, message and data, so agents get the hash, next_ability and retry of a rejection: for a Leitvia ability
	 * the default server offers as a tool, and for its execute-ability. The server leitvia needs no filter, it answers
	 * itself. Other tools stay as they are.
	 *
	 * @param mixed  $result    Result of the tool.
	 * @param mixed  $args      Tool arguments.
	 * @param string $tool_name Tool name.
	 * @param mixed  $tool      MCP tool.
	 * @param mixed  $server    MCP server.
	 * @return mixed
	 */
	public static function error_details( $result, $args = null, $tool_name = '', $tool = null, $server = null ) {
		unset( $server );
		$ability = is_object( $tool ) && method_exists( $tool, 'get_observability_context' ) ? (string) ( $tool->get_observability_context()['ability_name'] ?? '' ) : '';
		if ( is_wp_error( $result ) ) {
			return self::is_leitvia( $ability ) ? self::with_details( $result ) : $result;
		}
		$name = is_array( $args ) ? (string) ( $args['ability_name'] ?? '' ) : '';
		if ( self::EXECUTE !== $ability || ! is_array( $result ) || false !== ( $result['success'] ?? null ) || ! isset( self::$rejections[ $name ] ) ) {
			return $result;
		}
		$error = self::$rejections[ $name ];
		unset( self::$rejections[ $name ] );
		if ( ( $result['error'] ?? null ) === $error->get_error_message() ) {
			$result['error'] = self::with_details( $error )->get_error_message();
		}
		return $result;
	}

	/**
	 * Whether an ability is an entry of the Studio registry: of Studio, Pro or another compatible add-on.
	 *
	 * @param string $name Ability name.
	 */
	private static function is_leitvia( string $name ): bool {
		return '' !== $name && null !== leitvia_studio()->registry( 'abilities' )->get( $name );
	}

	/**
	 * Code, message and data of a rejection.
	 *
	 * @param \WP_Error $error Rejection.
	 * @return array{code: string|int, message: string, data: mixed}
	 */
	public static function details( \WP_Error $error ): array {
		return array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $error->get_error_data(),
		);
	}

	/**
	 * The error with code, message and data as JSON message.
	 *
	 * @param \WP_Error $error Rejection.
	 */
	private static function with_details( \WP_Error $error ): \WP_Error {
		return new \WP_Error( $error->get_error_code(), (string) wp_json_encode( self::details( $error ) ), $error->get_error_data() );
	}
}
