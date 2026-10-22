<?php
/**
 * MCP server leitvia through the separately installed plugin MCP Adapter.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the own MCP server leitvia with a fixed list of the Leitvia abilities when the MCP Adapter is active in a
 * supported version. Leitvia abilities never set meta.public, so the default server of the adapter lists none of them.
 * Without the adapter nothing happens and the abilities stay available over REST.
 */
final class Mcp {

	public const SERVER = 'leitvia';

	public const ROUTE_NAMESPACE = 'leitvia-studio/v1';

	public const ROUTE = 'mcp';

	/**
	 * Supported adapter versions: 0.7.x, tested with 0.7.1 in the CI job studio-compat. 0.x releases may break.
	 */
	public const SUPPORTED = '0.7.';

	/**
	 * Hooks of the MCP integration. The adapter fires mcp_adapter_init on rest_api_init, under WP-CLI on init.
	 */
	public static function boot(): void {
		add_action( 'mcp_adapter_init', array( self::class, 'register' ) );
		add_filter( 'mcp_adapter_tool_call_result', array( self::class, 'error_details' ), 10, 5 );
	}

	/**
	 * Version of the active MCP Adapter, empty when it is not active.
	 */
	public static function version(): string {
		return defined( 'WP_MCP_VERSION' ) ? (string) constant( 'WP_MCP_VERSION' ) : '';
	}

	/**
	 * State for the Agents page: whether the server is available, why not, and its endpoint.
	 *
	 * @param string|null $version Adapter version, null for the active one.
	 * @return array{ready: bool, version: string, reason: string, url: string}
	 */
	public static function status( ?string $version = null ): array {
		$version = $version ?? self::version();
		$reason  = '';
		if ( '' === $version ) {
			$reason = __( 'Install and activate the plugin MCP Adapter to connect MCP clients. Without it the abilities stay available over the REST API.', 'leitvia-studio' );
		} elseif ( ! str_starts_with( $version, self::SUPPORTED ) ) {
			/* translators: 1: installed version of the MCP Adapter, 2: product name, 3: supported version series */
			$reason = sprintf( __( 'MCP Adapter %1$s is not supported, %2$s needs version %3$sx. The abilities stay available over the REST API.', 'leitvia-studio' ), $version, leitvia_studio_brand()['name'], self::SUPPORTED );
		}
		return array(
			'ready'   => '' === $reason,
			'version' => $version,
			'reason'  => $reason,
			'url'     => rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE ),
		);
	}

	/**
	 * Names of all registered Leitvia abilities that are shown in REST: the tools of the server.
	 *
	 * @return list<string>
	 */
	public static function abilities(): array {
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( str_starts_with( $ability->get_name(), 'leitvia/' ) && true === $ability->get_meta_item( 'show_in_rest' ) ) {
				$names[] = $ability->get_name();
			}
		}
		return $names;
	}

	/**
	 * Creates the server leitvia on mcp_adapter_init. HTTP transport at /wp-json/leitvia-studio/v1/mcp, sign-in with
	 * an application password; the transport asks for the same capability as the abilities, the pipeline checks again.
	 *
	 * @param object      $adapter Instance of WP\MCP\Core\McpAdapter.
	 * @param string|null $version Adapter version, null for the active one.
	 */
	public static function register( $adapter, ?string $version = null ): void {
		if ( ! self::status( $version )['ready'] || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		$adapter->create_server(
			self::SERVER,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			leitvia_studio_brand_short(),
			/* translators: %s: product name */
			sprintf( __( 'Design contract, patterns, pages and change packages of this site. Every write runs through the %s pipeline with autonomy levels, checks and rollback.', 'leitvia-studio' ), leitvia_studio_brand_short() ),
			LEITVIA_STUDIO_VERSION,
			array( 'WP\\MCP\\Transport\\HttpTransport' ),
			null,
			null,
			self::abilities(),
			array(),
			array(),
			array( Abilities::class, 'can_use' )
		);
	}

	/**
	 * The adapter passes only the message of a WP_Error to the client. For tools of the server leitvia the message
	 * becomes JSON with code, message and data, so agents get the hash, next_ability and retry of a rejection.
	 *
	 * @param mixed  $result    Result of the tool.
	 * @param mixed  $args      Tool arguments.
	 * @param string $tool_name Tool name.
	 * @param mixed  $tool      MCP tool.
	 * @param mixed  $server    MCP server.
	 * @return mixed
	 */
	public static function error_details( $result, $args = null, $tool_name = '', $tool = null, $server = null ) {
		if ( ! is_wp_error( $result ) || ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) || self::SERVER !== $server->get_server_id() ) {
			return $result;
		}
		$details = array(
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
			'data'    => $result->get_error_data(),
		);
		return new \WP_Error( $result->get_error_code(), (string) wp_json_encode( $details ), $result->get_error_data() );
	}
}
