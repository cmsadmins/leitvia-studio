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
	 * Double of WP\MCP\Core\McpAdapter: records the arguments of create_server().
	 */
	private static function adapter(): object {
		return new class() {
			/**
			 * Arguments of each create_server() call.
			 *
			 * @var list<array<int, mixed>>
			 */
			public array $servers = array();

			/**
			 * Records the call.
			 *
			 * @param mixed ...$args Server arguments.
			 */
			public function create_server( ...$args ): bool {
				$this->servers[] = $args;
				return true;
			}
		};
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
	 * Without the adapter the hook never fires: nothing registered, no output, a reason for the Agents page.
	 */
	public function test_without_adapter_nothing_happens(): void {
		$this->assertFalse( defined( 'WP_MCP_VERSION' ) );
		$this->assertSame( 10, has_action( 'mcp_adapter_init', array( Mcp::class, 'register' ) ) );
		$status = Mcp::status();
		$this->assertFalse( $status['ready'] );
		$this->assertStringContainsString( 'MCP Adapter', $status['reason'] );
		$adapter = self::adapter();
		ob_start();
		Mcp::register( $adapter );
		$this->assertSame( '', ob_get_clean() );
		$this->assertSame( array(), $adapter->servers );
	}

	/**
	 * A supported adapter gets exactly one server leitvia with the tool list and the HTTP transport.
	 */
	public function test_supported_adapter_gets_one_server(): void {
		$adapter = self::adapter();
		Mcp::register( $adapter, '0.7.1' );
		$this->assertCount( 1, $adapter->servers );
		$args = $adapter->servers[0];
		$this->assertSame( 'leitvia', $args[0] );
		$this->assertSame( 'leitvia-studio/v1', $args[1] );
		$this->assertSame( 'mcp', $args[2] );
		$this->assertSame( array( 'WP\\MCP\\Transport\\HttpTransport' ), $args[6] );
		$this->assertSame( Mcp::abilities(), $args[9] );
		$this->assertSame( array( Abilities::class, 'can_use' ), $args[12] );
		$this->assertTrue( call_user_func( $args[12] ) );
		$this->assertTrue( Mcp::status( '0.7.1' )['ready'] );
		$this->assertStringEndsWith( '/leitvia-studio/v1/mcp', Mcp::status( '0.7.1' )['url'] );
	}

	/**
	 * Another adapter series registers nothing and names the installed and the supported version.
	 */
	public function test_unsupported_version_registers_nothing(): void {
		foreach ( array( '0.6.0', '0.8.0', '1.0.0' ) as $version ) {
			$adapter = self::adapter();
			Mcp::register( $adapter, $version );
			$this->assertSame( array(), $adapter->servers, $version );
			$status = Mcp::status( $version );
			$this->assertFalse( $status['ready'] );
			$this->assertStringContainsString( $version, $status['reason'] );
			$this->assertStringContainsString( '0.7.x', $status['reason'] );
		}
	}

	/**
	 * Rejections over the server leitvia keep code and data as JSON message; other servers and results stay as they are.
	 */
	public function test_error_details_only_for_leitvia(): void {
		$data   = array(
			'status'       => 409,
			'current_hash' => 'abc',
			'retry'        => 'once',
		);
		$error  = new WP_Error( 'leitvia_stale_hash', 'Stale.', $data );
		$result = Mcp::error_details( $error, array(), 'leitvia-update-content', null, self::server( 'leitvia' ) );
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
		$this->assertSame( array( 'ok' => true ), Mcp::error_details( array( 'ok' => true ), array(), 'x', null, self::server( 'leitvia' ) ) );
	}
}
