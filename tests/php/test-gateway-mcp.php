<?php
/**
 * MCP server leitvia, against a double of the public adapter method create_server().
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Mcp;

/**
 * Tool list, registration with and without a supported adapter, error details over MCP.
 */
class Test_Gateway_Mcp extends WP_UnitTestCase {

	/**
	 * Abilities handed to core, an agent signed in.
	 */
	public function set_up(): void {
		parent::set_up();
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
	}

	/**
	 * Removes the abilities and the category.
	 */
	public function tear_down(): void {
		self::unregister();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Unregisters the abilities of Leitvia and the category.
	 */
	private static function unregister(): void {
		foreach ( wp_get_abilities() as $ability ) {
			if ( 'leitvia' === $ability->get_category() ) {
				wp_unregister_ability( $ability->get_name() );
			}
		}
		if ( wp_has_ability_category( 'leitvia' ) ) {
			wp_unregister_ability_category( 'leitvia' );
		}
	}

	/**
	 * Double of WP\MCP\Core\McpServer: only the server ID.
	 *
	 * @param string $id Server ID.
	 */
	private static function server( string $id ): object {
		return new class( $id ) {
			/**
			 * Server ID.
			 *
			 * @param string $id Server ID.
			 */
			public function __construct( private string $id ) {}

			/**
			 * Server ID.
			 */
			public function get_server_id(): string {
				return $this->id;
			}
		};
	}

	/**
	 * Double of WP\MCP\Domain\Tools\McpTool: only the observability context with the ability behind the tool.
	 *
	 * @param string $ability Ability name.
	 */
	private static function tool( string $ability ): object {
		return new class( $ability ) {
			/**
			 * Ability name.
			 *
			 * @param string $ability Ability name.
			 */
			public function __construct( private string $ability ) {}

			/**
			 * Context as McpTool::fromAbility() sets it.
			 *
			 * @return array<string, string>
			 */
			public function get_observability_context(): array {
				return array(
					'component_type' => 'tool',
					'ability_name'   => $this->ability,
				);
			}
		};
	}

	/**
	 * Every leitvia ability shown in REST is a tool, among them the design reads and plan-page; none is public.
	 */
	public function test_abilities_lists_every_leitvia_ability(): void {
		$tools    = Mcp::abilities();
		$expected = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( str_starts_with( $ability->get_name(), 'leitvia/' ) ) {
				$expected[] = $ability->get_name();
				$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ), $ability->get_name() );
				$this->assertNotTrue( $ability->get_meta_item( 'public' ), $ability->get_name() );
			}
		}
		$this->assertSame( $expected, $tools );
		$this->assertSame( array_unique( $tools ), $tools );
		foreach ( array( 'leitvia/get-contract', 'leitvia/get-tokens', 'leitvia/list-patterns', 'leitvia/get-pattern', 'leitvia/get-content-model', 'leitvia/list-blocks', 'leitvia/plan-page', 'leitvia/update-content' ) as $name ) {
			$this->assertContains( $name, $tools );
		}
		foreach ( $tools as $name ) {
			$this->assertStringStartsWith( 'leitvia/', $name );
		}
	}

	/**
	 * An ability a compatible add-on registers through the Studio registry is a tool too.
	 */
	public function test_abilities_lists_abilities_of_add_ons(): void {
		$args = array(
			'label'               => 'Add-on read',
			'description'         => 'Reads something for an add-on.',
			'execute_callback'    => '__return_empty_array',
			'permission_callback' => array( Abilities::class, 'can_use' ),
			'output_schema'       => array( 'type' => 'object' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
		$init = static function ( $studio ) use ( $args ): void {
			$studio->extensions()->register(
				'demo-addon',
				array(
					'label'           => 'Demo add-on',
					'version'         => '1.0.0',
					'requires_studio' => '0.1.0',
					'requires_api'    => 1,
				)
			);
			$studio->registry( 'abilities' )->register(
				'demo-addon/read-thing',
				array(
					'args'  => $args,
					'group' => 'read',
				),
				'demo-addon'
			);
		};
		add_action( 'leitvia_studio_init', $init );
		self::unregister();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		remove_action( 'leitvia_studio_init', $init );

		$tools = Mcp::abilities();
		$this->assertContains( 'demo-addon/read-thing', $tools );
		$this->assertContains( 'leitvia/get-contract', $tools );
		$this->assertTrue( wp_get_ability( 'demo-addon/read-thing' )->get_meta()['mcp']['public'] );
	}

	/**
	 * The default server of the adapter offers exactly the tools of the server leitvia: discover-abilities lists an
	 * ability when meta.mcp.public is true (McpAbilityExposure::is_public() of 0.7.1). Other abilities stay as they are.
	 */
	public function test_default_server_lists_the_leitvia_tools(): void {
		$public = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( true === ( $ability->get_meta()['mcp']['public'] ?? null ) ) {
				$public[] = $ability->get_name();
			}
		}
		$tools = Mcp::abilities();
		$this->assertNotEmpty( $tools );
		$this->assertSame( array(), array_diff( $tools, $public ) );
		foreach ( array_diff( $public, $tools ) as $name ) {
			$this->assertNull( leitvia_studio()->registry( 'abilities' )->get( $name ), $name );
		}
		foreach ( $tools as $name ) {
			$this->assertNotTrue( wp_get_ability( $name )->get_meta_item( 'public' ), $name );
		}
	}

	/**
	 * Studio serves its own server and registers none with the adapter; its default server stays a second way.
	 */
	public function test_own_server_without_adapter(): void {
		$this->assertFalse( has_action( 'mcp_adapter_init' ) );
		$status = Mcp::status();
		$this->assertTrue( $status['ready'] );
		$this->assertSame( '', $status['reason'] );
		$this->assertSame( array( '2026-07-28', '2025-11-25' ), $status['protocols'] );
		$this->assertStringEndsWith( '/leitvia-studio/v1/mcp', $status['url'] );
		$this->assertSame( Mcp::version(), $status['version'] );
	}

	/**
	 * A rejection as array with code, message and data, the form tool results carry.
	 */
	public function test_details_of_a_rejection(): void {
		$error = new WP_Error( 'leitvia_stale_hash', 'Stale.', array( 'current_hash' => 'abc' ) );
		$this->assertSame(
			array(
				'code'    => 'leitvia_stale_hash',
				'message' => 'Stale.',
				'data'    => array( 'current_hash' => 'abc' ),
			),
			Mcp::details( $error )
		);
	}

	/**
	 * Rejections of Leitvia abilities keep code and data as JSON message; others and results stay as they are.
	 */
	public function test_error_details_only_for_leitvia(): void {
		$data   = array(
			'status'       => 409,
			'current_hash' => 'abc',
			'retry'        => 'once',
		);
		$error  = new WP_Error( 'leitvia_stale_hash', 'Stale.', $data );
		$result = Mcp::error_details( $error, array(), 'leitvia-update-content', self::tool( 'leitvia/update-content' ), self::server( 'mcp-adapter-default-server' ) );
		$this->assertWPError( $result );
		$this->assertSame(
			array(
				'code'    => 'leitvia_stale_hash',
				'message' => 'Stale.',
				'data'    => array(
					'status'       => 409,
					'current_hash' => 'abc',
					'retry'        => 'once',
				),
			),
			json_decode( $result->get_error_message(), true )
		);
		$this->assertSame( $error, Mcp::error_details( $error, array(), 'x', null, self::server( 'mcp-adapter-default-server' ) ) );
		$this->assertSame( $error, Mcp::error_details( $error, array(), 'x', null, self::server( 'leitvia' ) ) );
		$this->assertSame( array( 'ok' => true ), Mcp::error_details( array( 'ok' => true ), array(), 'x', null, self::server( 'mcp-adapter-default-server' ) ) );
	}

	/**
	 * On the default server code and data arrive too: for a Leitvia ability offered as a tool, and for execute-ability,
	 * which keeps only the message. Tools and abilities of others stay as they are.
	 */
	public function test_error_details_on_default_server(): void {
		$server   = self::server( 'mcp-adapter-default-server' );
		$data     = array(
			'status'       => 409,
			'current_hash' => 'abc',
		);
		$error    = new WP_Error( 'leitvia_stale_hash', 'Stale.', $data );
		$expected = array(
			'code'    => 'leitvia_stale_hash',
			'message' => 'Stale.',
			'data'    => $data,
		);

		$direct = Mcp::error_details( $error, array(), 'leitvia-update-content', self::tool( 'leitvia/update-content' ), $server );
		$this->assertWPError( $direct );
		$this->assertSame( $expected, json_decode( $direct->get_error_message(), true ) );
		$this->assertSame( $error, Mcp::error_details( $error, array(), 'other-tool', self::tool( 'other/tool' ), $server ) );

		$this->assertSame( 10, has_filter( 'wp_ability_execute_result', array( Mcp::class, 'remember' ) ) );
		$this->assertSame( $error, apply_filters( 'wp_ability_execute_result', $error, 'leitvia/update-content', array(), null ) );
		$failed = array(
			'success' => false,
			'error'   => 'Stale.',
		);
		$call   = array(
			'ability_name' => 'leitvia/update-content',
			'parameters'   => array(),
		);
		$result = Mcp::error_details( $failed, $call, 'mcp-adapter-execute-ability', self::tool( Mcp::EXECUTE ), $server );
		$this->assertFalse( $result['success'] );
		$this->assertSame( $expected, json_decode( $result['error'], true ) );
		// The rejection is used once.
		$this->assertSame( $failed, Mcp::error_details( $failed, $call, 'mcp-adapter-execute-ability', self::tool( Mcp::EXECUTE ), $server ) );

		apply_filters( 'wp_ability_execute_result', new WP_Error( 'other', 'Stale.' ), 'other/ability', array(), null );
		$other = array(
			'ability_name' => 'other/ability',
			'parameters'   => array(),
		);
		$this->assertSame( $failed, Mcp::error_details( $failed, $other, 'mcp-adapter-execute-ability', self::tool( Mcp::EXECUTE ), $server ) );
		$this->assertSame( array( 'success' => true ), Mcp::error_details( array( 'success' => true ), $call, 'mcp-adapter-execute-ability', self::tool( Mcp::EXECUTE ), $server ) );
	}

	/**
	 * An agent reaches both servers, but through execute-ability of the default server only Leitvia abilities.
	 */
	public function test_agent_reaches_the_default_server_with_leitvia_abilities_only(): void {
		$post  = static function ( string $route, $body ): WP_REST_Request {
			$request = new WP_REST_Request( 'POST', $route );
			$request->set_body( wp_json_encode( $body ) );
			return $request;
		};
		$call  = static fn( string $tool, array $arguments = array() ): array => array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => $tool,
				'arguments' => $arguments,
			),
		);
		$route = Mcp::DEFAULT_ROUTE;
		$this->assertNull( Gateway::rest_guard( null, rest_get_server(), $post( '/' . Mcp::ROUTE_NAMESPACE . '/' . Mcp::ROUTE, array() ) ) );
		$this->assertNull( Gateway::rest_guard( null, rest_get_server(), $post( $route, array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short message.
		$this->assertNull( Gateway::rest_guard( null, rest_get_server(), $post( $route, $call( 'mcp-adapter-discover-abilities' ) ) ) );
		$this->assertNull(
			Gateway::rest_guard(
				null,
				rest_get_server(),
				$post(
					$route,
					$call(
						'mcp-adapter-execute-ability',
						array(
							'ability_name' => 'leitvia/get-contract',
							'parameters'   => array(),
						)
					)
				)
			)
		);
		foreach ( array(
			$call(
				'mcp-adapter-execute-ability',
				array(
					'ability_name' => 'core/get-site-info',
					'parameters'   => array(),
				)
			),
			$call( 'other-plugin-write' ),
			array( $call( 'mcp-adapter-discover-abilities' ), $call( 'mcp-adapter-execute-ability' ) ),
		) as $body ) {
			$this->assertSame( 'leitvia_forbidden', Gateway::rest_guard( null, rest_get_server(), $post( $route, $body ) )->get_error_code(), wp_json_encode( $body ) );
		}
		$this->assertSame( 'leitvia_forbidden', Gateway::rest_guard( null, rest_get_server(), $post( '/mcp/other-server', array() ) )->get_error_code() );
	}
}
