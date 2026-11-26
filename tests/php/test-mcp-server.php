<?php
/**
 * MCP server of Studio: both protocol revisions over the REST route leitvia-studio/v1/mcp.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Mcp\Completion;
use LeitviaStudio\Mcp\Context;
use LeitviaStudio\Mcp\Dispatcher;
use LeitviaStudio\Mcp\Envelope;
use LeitviaStudio\Mcp\Protocol_Error;
use LeitviaStudio\Mcp\Resources;
use LeitviaStudio\Mcp\Server;
use LeitviaStudio\Mcp\Sessions;
use LeitviaStudio\Mcp\Stream;
use LeitviaStudio\Mcp\Subscriptions;
use LeitviaStudio\Mcp\Tools;

/**
 * Revisions, headers, sessions, tools and errors of the own MCP server.
 */
class Test_Mcp_Server extends WP_UnitTestCase {

	/**
	 * Abilities handed to core, an agent signed in.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
	}

	/**
	 * Studio state of the next test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * The server names both revisions, the newest first, and itself.
	 */
	public function test_server_names_revisions_and_itself(): void {
		$this->assertSame( array( '2026-07-28', '2025-11-25' ), Server::versions() );
		$info = Server::info();
		$this->assertSame( 'leitvia', $info['name'] );
		$this->assertSame( LEITVIA_STUDIO_VERSION, $info['version'] );
		$this->assertNotSame( '', $info['title'] );
	}

	/**
	 * The method initialize gets the revision asked for when the server knows it, else 2025-11-25; a session belongs to its user.
	 */
	public function test_sessions_negotiate_and_belong_to_their_user(): void {
		$this->assertSame( '2025-06-18', Sessions::negotiate( '2025-06-18' ) );
		$this->assertSame( '2025-11-25', Sessions::negotiate( '2024-11-05' ) );
		$this->assertSame( '2025-11-25', Sessions::negotiate( '' ) );
		$id = Sessions::start( '2025-11-25' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $id );
		$this->assertSame( '2025-11-25', Sessions::get( $id )['version'] );
		$owner = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assertNull( Sessions::get( $id ) );
		$this->assertFalse( Sessions::end( $id ) );
		wp_set_current_user( $owner );
		$this->assertTrue( Sessions::end( $id ) );
		$this->assertNull( Sessions::get( $id ) );
		wp_set_current_user( 0 );
		$this->assertSame( '', Sessions::start( '2025-11-25' ) );
		$this->assertNull( Sessions::get( $id ) );
	}

	/**
	 * A REST request to the server with the headers a 2026-07-28 client sends for this message.
	 *
	 * @param array<string, mixed>  $message JSON-RPC message.
	 * @param array<string, string> $headers Headers that replace or remove (empty value) the default ones.
	 */
	private static function modern( array $message, array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' );
		$name    = $message['params']['name'] ?? $message['params']['uri'] ?? '';
		$name    = is_string( $name ) ? $name : '';
		$headers = $headers + array(
			'Content-Type'         => 'application/json',
			'Accept'               => 'application/json, text/event-stream',
			'MCP-Protocol-Version' => '2026-07-28',
			'Mcp-Method'           => (string) ( $message['method'] ?? '' ),
			'Mcp-Name'             => $name,
		);
		foreach ( array_filter( $headers, 'strlen' ) as $header => $value ) {
			$request->set_header( $header, $value );
		}
		$request->set_body( (string) wp_json_encode( $message ) );
		return $request;
	}

	/**
	 * A JSON-RPC request of a 2026-07-28 client: _meta with revision and capabilities.
	 *
	 * @param string               $method Method.
	 * @param array<string, mixed> $params Params without _meta.
	 */
	private static function message( string $method, array $params = array() ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 7,
			'method'  => $method,
			'params'  => $params + array(
				'_meta' => array(
					'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
					'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
					'io.modelcontextprotocol/clientInfo' => array(
						'name'    => 'test',
						'version' => '1.0.0',
					),
				),
			),
		);
	}

	/**
	 * The protocol error Envelope::read() throws, null when it throws none.
	 *
	 * @param array<string, mixed> $message JSON-RPC message.
	 * @param WP_REST_Request      $request Request.
	 */
	private static function envelope_error( array $message, WP_REST_Request $request ): ?Protocol_Error {
		try {
			Envelope::read( json_decode( (string) wp_json_encode( $message ), true ), $request );
			return null;
		} catch ( Protocol_Error $error ) {
			return $error;
		}
	}

	/**
	 * _meta with the revision makes a request stateless; header and body must agree.
	 */
	public function test_envelope_checks_headers_against_body(): void {
		$call    = self::message( 'tools/call', array( 'name' => 'leitvia-get-contract' ) );
		$decoded = json_decode( (string) wp_json_encode( $call ), true );
		$context = Envelope::read( $decoded, self::modern( $call ) );
		$this->assertTrue( $context->modern );
		$this->assertSame( '2026-07-28', $context->version );
		foreach ( array(
			array( 'MCP-Protocol-Version' => '2025-11-25' ),
			array( 'MCP-Protocol-Version' => '' ),
			array( 'Mcp-Method' => 'tools/list' ),
			array( 'Mcp-Name' => 'leitvia-rollback' ),
			array( 'Mcp-Name' => '' ),
		) as $headers ) {
			$error = self::envelope_error( $call, self::modern( $call, $headers ) );
			$this->assertSame( -32020, $error?->rpc_code, (string) wp_json_encode( $headers ) );
			$this->assertSame( 400, $error->status );
		}
	}

	/**
	 * An unknown revision names the supported ones, missing capabilities are invalid params.
	 */
	public function test_envelope_rejects_unknown_revision_and_missing_capabilities(): void {
		$old = self::message( 'tools/list' );
		$old['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '2027-01-01';

		$error = self::envelope_error( $old, self::modern( $old, array( 'MCP-Protocol-Version' => '2027-01-01' ) ) );
		$this->assertSame( -32022, $error?->rpc_code );
		$this->assertSame( 400, $error->status );
		$this->assertSame( array( '2026-07-28', '2025-11-25' ), $error->data['supported'] );
		$this->assertSame( '2027-01-01', $error->data['requested'] );
		$bare = self::message( 'tools/list' );
		unset( $bare['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] );
		$error = self::envelope_error( $bare, self::modern( $bare ) );
		$this->assertSame( -32602, $error?->rpc_code );
		$this->assertSame( 400, $error->status );
		$wrong = self::message( 'tools/list' );
		$this->assertSame( -32600, self::envelope_error( array( 'jsonrpc' => '1.0' ) + $wrong, self::modern( $wrong ) )?->rpc_code );
		$typed = self::message( 'tools/list' );
		$typed['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = array( '2026-07-28' );
		$this->assertSame( -32600, self::envelope_error( $typed, self::modern( $typed, array( 'MCP-Protocol-Version' => '2026-07-28' ) ) )?->rpc_code );
		$read = self::message( 'resources/read', array( 'uri' => 'leitvia://contract' ) );
		$this->assertTrue( Envelope::read( json_decode( (string) wp_json_encode( $read ), true ), self::modern( $read ) )->modern );
		$this->assertSame( -32020, self::envelope_error( $read, self::modern( $read, array( 'Mcp-Name' => 'leitvia://other' ) ) )?->rpc_code );
		$badname = self::message( 'tools/call', array( 'name' => array( 'x' ) ) );
		$this->assertSame( -32602, self::envelope_error( $badname, self::modern( $badname, array( 'Mcp-Name' => 'x' ) ) )?->rpc_code );
	}

	/**
	 * Without _meta a request needs the session of its user; initialize starts one.
	 */
	public function test_envelope_legacy_needs_own_session(): void {
		$list    = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/list',
		);
		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' );
		$this->assertSame( 400, self::envelope_error( $list, $request )?->status );
		$request->set_header( 'Mcp-Session-Id', 'no-such-session' );
		$this->assertSame( 404, self::envelope_error( $list, $request )?->status );
		$request->set_header( 'Mcp-Session-Id', Sessions::start( '2025-06-18' ) );
		$context = Envelope::read( $list, $request );
		$this->assertFalse( $context->modern );
		$this->assertSame( '2025-06-18', $context->version );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		$this->assertSame( -32020, self::envelope_error( $list, $request )?->rpc_code );
		$request->set_header( 'MCP-Protocol-Version', '' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assertSame( 404, self::envelope_error( $list, $request )?->status );
		$init = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array( 'protocolVersion' => '2025-11-25' ),
		);
		$this->assertSame( '2025-11-25', Envelope::read( $init, new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' ) )->version );
	}

	/**
	 * Every Leitvia ability shown in REST is a tool, sorted, with schema and hints; empty properties stay objects.
	 */
	public function test_tools_list_the_abilities(): void {
		$tools = Tools::list()['tools'];
		$names = array_column( $tools, 'name' );
		$this->assertContains( 'leitvia-get-contract', $names );
		$sorted = $names;
		sort( $sorted );
		$this->assertSame( $sorted, $names );
		$this->assertCount( count( \LeitviaStudio\Gateway\Mcp::abilities() ), $tools );
		$contract = $tools[ array_search( 'leitvia-get-contract', $names, true ) ];
		$this->assertSame( 'object', $contract['inputSchema']['type'] );
		$this->assertTrue( $contract['annotations']['readOnlyHint'] );
		$this->assertFalse( $contract['annotations']['destructiveHint'] );
		$this->assertStringNotContainsString( '"properties":[]', (string) wp_json_encode( $tools ) );
	}

	/**
	 * A call runs the ability with the same result as REST; a rejection is a tool result with code and data.
	 */
	public function test_tools_call_runs_the_ability(): void {
		$modern = new Context( '2026-07-28', true, array() );
		$result = Tools::call( array( 'name' => 'leitvia-get-contract' ), $modern );
		$this->assertArrayNotHasKey( 'isError', $result );
		$ability = wp_get_ability( 'leitvia/get-contract' );
		$this->assertSame( $ability->execute( empty( $ability->get_input_schema() ) ? null : array() ), $result['structuredContent'] );
		$this->assertSame( $result['structuredContent'], json_decode( $result['content'][0]['text'], true ) );
		$failed = Tools::call(
			array(
				'name'      => 'leitvia-get-page-outline',
				'arguments' => array( 'post' => 999999 ),
			),
			$modern
		);
		$this->assertTrue( $failed['isError'] );
		$details = json_decode( $failed['content'][0]['text'], true );
		$this->assertStringStartsWith( 'leitvia_', $details['code'] );
		$this->assertArrayHasKey( 'data', $details );
	}

	/**
	 * Under 2025-11-25 structuredContent is an object or absent; an empty result of an object schema is {}.
	 */
	public function test_tools_call_structured_content_per_revision(): void {
		$legacy = new Context( '2025-11-25', false );
		$result = Tools::call( array( 'name' => 'leitvia-get-contract' ), $legacy );
		$this->assertIsArray( $result['structuredContent'] );
		$this->assertFalse( array_is_list( $result['structuredContent'] ) );
		$this->expectException( Protocol_Error::class );
		Tools::call( array( 'name' => array( 'x' ) ), $legacy );
	}

	/**
	 * An unknown tool is a protocol error, not a tool result.
	 */
	public function test_unknown_tool(): void {
		$this->expectException( Protocol_Error::class );
		Tools::call( array( 'name' => 'core-get-site-info' ), new Context( '2026-07-28', true ) );
	}

	/**
	 * Methods per revision: discover and cacheable lists under 2026-07-28, initialize and ping under 2025-11-25.
	 */
	public function test_dispatcher_methods_per_revision(): void {
		$modern   = new Context( '2026-07-28', true );
		$discover = Dispatcher::handle( 'server/discover', array(), $modern );
		$this->assertSame( array( '2026-07-28', '2025-11-25' ), $discover['supportedVersions'] );
		$this->assertArrayHasKey( 'tools', $discover['capabilities'] );
		$this->assertSame( 'private', $discover['cacheScope'] );
		$this->assertIsInt( $discover['ttlMs'] );
		$this->assertNotSame( '', $discover['instructions'] );
		$list = Dispatcher::handle( 'tools/list', array(), $modern );
		$this->assertSame( 'private', $list['cacheScope'] );
		$legacy = new Context( '2025-06-18', false );
		$init   = Dispatcher::handle( 'initialize', array(), $legacy );
		$this->assertSame( '2025-06-18', $init['protocolVersion'] );
		$this->assertSame( 'leitvia', $init['serverInfo']['name'] );
		$this->assertSame( array(), Dispatcher::handle( 'ping', array(), $legacy ) );
		$this->assertArrayNotHasKey( 'ttlMs', Dispatcher::handle( 'tools/list', array(), $legacy ) );
		foreach ( array( array( 'initialize', $modern ), array( 'server/discover', $legacy ), array( 'tasks/list', $modern ), array( 'resources/subscribe', $legacy ), array( 'subscriptions/listen', $legacy ) ) as [ $method, $context ] ) {
			try {
				Dispatcher::handle( $method, array(), $context );
				$this->fail( $method );
			} catch ( Protocol_Error $error ) {
				$this->assertSame( -32601, $error->rpc_code, $method );
				$this->assertSame( 404, $error->status );
			}
		}
	}

	/**
	 * Response of the REST server to a request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function send( WP_REST_Request $request ): WP_REST_Response {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * 2026-07-28 end to end: discover and tools/call with resultType and serverInfo, no session.
	 */
	public function test_endpoint_modern(): void {
		$response = self::send( self::modern( self::message( 'server/discover' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 7, $body['id'] );
		$this->assertSame( 'complete', $body['result']['resultType'] );
		$this->assertSame( 'leitvia', $body['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response->get_headers() );
		$call = self::send( self::modern( self::message( 'tools/call', array( 'name' => 'leitvia-get-contract' ) ) ) );
		$this->assertSame( 'complete', $call->get_data()['result']['resultType'] );
		$this->assertArrayHasKey( 'structuredContent', $call->get_data()['result'] );
		$wrong = self::send( self::modern( self::message( 'tools/list' ), array( 'Mcp-Method' => 'tools/call' ) ) );
		$this->assertSame( 400, $wrong->get_status() );
		$this->assertSame( -32020, $wrong->get_data()['error']['code'] );
		$missing = self::send( self::modern( self::message( 'tasks/list' ) ) );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( -32601, $missing->get_data()['error']['code'] );
	}

	/**
	 * 2025-11-25 end to end: initialize returns a session, later requests need it, errors of methods come with 200.
	 */
	public function test_endpoint_legacy(): void {
		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => '2025-11-25',
						'capabilities'    => new stdClass(),
						'clientInfo'      => array(
							'name'    => 't',
							'version' => '1',
						),
					),
				)
			)
		);
		$init = self::send( $request );
		$this->assertSame( 200, $init->get_status() );
		$this->assertSame( '2025-11-25', $init->get_data()['result']['protocolVersion'] );
		$session = $init->get_headers()['Mcp-Session-Id'];
		$this->assertNotEmpty( $session );
		$list = new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' );
		$list->set_header( 'Mcp-Session-Id', $session );
		$list->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		$list->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/list',
				)
			)
		);
		$this->assertNotEmpty( self::send( $list )->get_data()['result']['tools'] );
		$list->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 3,
					'method'  => 'tasks/get',
				)
			)
		);
		$unknown = self::send( $list );
		$this->assertSame( 200, $unknown->get_status() );
		$this->assertSame( -32601, $unknown->get_data()['error']['code'] );
		$ping = new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' );
		$ping->set_header( 'Mcp-Session-Id', $session );
		$ping->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 4,
					'method'  => 'ping',
				)
			)
		);
		$this->assertSame( '{}', wp_json_encode( self::send( $ping )->get_data()['result'] ) );
		$end = new WP_REST_Request( 'DELETE', '/leitvia-studio/v1/mcp' );
		$end->set_header( 'Mcp-Session-Id', $session );
		$this->assertSame( 204, self::send( $end )->get_status() );
		$this->assertSame( 404, self::send( $list )->get_status() );
	}

	/**
	 * Notifications, batches, parse errors, GET, foreign origins and anonymous requests.
	 */
	public function test_endpoint_edges(): void {
		$note = self::modern(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			)
		);
		$this->assertSame( 202, self::send( $note )->get_status() );
		$batch = new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' );
		$batch->set_body( (string) wp_json_encode( array( self::message( 'tools/list' ) ) ) );
		$this->assertSame( -32600, self::send( $batch )->get_data()['error']['code'] );
		$batch->set_body( '{nope' );
		$this->assertSame( -32700, self::send( $batch )->get_data()['error']['code'] );
		$batch->set_header( 'Content-Type', 'application/json' );
		$this->assertSame( -32700, self::send( $batch )->get_data()['error']['code'] );
		$this->assertSame( 405, self::send( new WP_REST_Request( 'DELETE', '/leitvia-studio/v1/mcp' ) )->get_status() );
		$port = (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST ) . ':8099';
		$this->assertSame( 403, self::send( self::modern( self::message( 'tools/list' ), array( 'Origin' => $port ) ) )->get_status() );
		$this->assertSame( 405, self::send( new WP_REST_Request( 'GET', '/leitvia-studio/v1/mcp' ) )->get_status() );
		$this->assertSame( 403, self::send( self::modern( self::message( 'tools/list' ), array( 'Origin' => 'https://evil.example' ) ) )->get_status() );
		$this->assertSame( 200, self::send( self::modern( self::message( 'tools/list' ), array( 'Origin' => home_url() ) ) )->get_status() );
		wp_set_current_user( 0 );
		$anonymous = self::send( self::modern( self::message( 'tools/list' ) ) );
		$this->assertSame( 401, $anonymous->get_status() );
		$this->assertStringContainsString( 'Basic realm="leitvia"', $anonymous->get_headers()['WWW-Authenticate'], 'application passwords stay a way in, with or without OAuth' );
	}

	/**
	 * Arguments marked with x-mcp-header come as Mcp-Param-* too, plain or base64, and must equal the body.
	 */
	public function test_param_headers(): void {
		$this->assertSame(
			array( 'Site' => 'site' ),
			Tools::param_headers(
				array(
					'type'       => 'object',
					'properties' => array(
						'site' => array(
							'type'         => 'string',
							'x-mcp-header' => 'Site',
						),
						'post' => array( 'type' => 'integer' ),
					),
				)
			)
		);
		$map    = array(
			'Site'  => 'site',
			'Draft' => 'draft',
		);
		$check  = static function ( array $arguments, array $headers ) use ( $map ): ?int {
			$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' );
			foreach ( $headers as $name => $value ) {
				$request->set_header( $name, $value );
			}
			try {
				Envelope::params( $map, $arguments, $request );
				return null;
			} catch ( Protocol_Error $error ) {
				return $error->rpc_code;
			}
		};
		$base64 = '=?base64?' . base64_encode( 'Bäckerei' ) . '?='; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Header encoding of MCP 2026-07-28.
		$this->assertNull( $check( array(), array() ) );
		$this->assertNull( $check( array( 'site' => null ), array() ), 'A null argument expects no header.' );
		$this->assertNull( $check( array( 'site' => 'a.example' ), array( 'Mcp-Param-Site' => 'a.example' ) ) );
		$this->assertNull( $check( array( 'site' => 'Bäckerei' ), array( 'Mcp-Param-Site' => $base64 ) ) );
		$this->assertNull( $check( array( 'draft' => true ), array( 'Mcp-Param-Draft' => 'true' ) ) );
		foreach ( array(
			array( array( 'site' => 'a.example' ), array() ),
			array( array(), array( 'Mcp-Param-Site' => 'a.example' ) ),
			array( array( 'site' => 'a.example' ), array( 'Mcp-Param-Site' => 'b.example' ) ),
			array( array( 'site' => 'a.example' ), array( 'Mcp-Param-Site' => '=?base64?***?=' ) ),
			array( array( 'site' => array( 'a' ) ), array( 'Mcp-Param-Site' => 'a' ) ),
		) as [ $arguments, $headers ] ) {
			$this->assertSame( -32020, $check( $arguments, $headers ), (string) wp_json_encode( $headers ) );
		}
		$this->assertSame( 'Bäckerei', Envelope::decode( $base64 ) );
		$this->assertSame( 'plain', Envelope::decode( 'plain' ) );
	}

	/**
	 * Four resources and three templates, each with title and description, JSON as type but for the skill files.
	 */
	public function test_resources_list_and_templates(): void {
		$this->assertSame( array( 'leitvia://contract', 'leitvia://tokens', 'leitvia://patterns', 'leitvia://inbox' ), array_column( Resources::list()['resources'], 'uri' ) );
		$this->assertSame( array( 'leitvia://page/{id}/outline', 'leitvia://pattern/{slug}', 'leitvia://skills/{owner}/{+path}' ), array_column( Resources::templates()['resourceTemplates'], 'uriTemplate' ) );
		foreach ( array_merge( Resources::list()['resources'], array_slice( Resources::templates()['resourceTemplates'], 0, 2 ) ) as $resource ) {
			$this->assertSame( 'application/json', $resource['mimeType'] );
			$this->assertNotSame( '', $resource['name'] );
			$this->assertNotSame( '', $resource['title'] );
			$this->assertNotSame( '', $resource['description'] );
		}
	}

	/**
	 * Contents come from the abilities, so rights and results match tools/call.
	 */
	public function test_resources_read_through_abilities(): void {
		$modern = new Context( '2026-07-28', true );
		$read   = static fn( string $uri ) => json_decode( Resources::read( array( 'uri' => $uri ), $modern )['contents'][0]['text'], true );
		$this->assertSame( Tools::call( array( 'name' => 'leitvia-get-contract' ), $modern )['structuredContent'], $read( 'leitvia://contract' ) );
		$this->assertArrayHasKey( 'document', $read( 'leitvia://tokens' ) );
		$this->assertSame( wp_get_ability( 'leitvia/list-patterns' )->execute( array( 'per_page' => 1 ) )['total'], $read( 'leitvia://patterns' )['total'] );
		$this->assertIsArray( $read( 'leitvia://inbox' ) );
		$this->assertSame( 'leitvia/hero-split-image', $read( 'leitvia://pattern/leitvia/hero-split-image' )['slug'] );
		$this->assertSame( 'leitvia/hero-split-image', $read( 'leitvia://pattern/leitvia%2Fhero-split-image' )['slug'] );
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertSame( $page, $read( 'leitvia://page/' . $page . '/outline' )['id'] );
	}

	/**
	 * A missing resource is -32602 under 2026-07-28 and -32002 under 2025-11-25, with its URI.
	 */
	public function test_resources_missing_per_revision(): void {
		foreach ( array( 'leitvia://nothing', 'leitvia://page/999999/outline', 'leitvia://pattern/none/such' ) as $uri ) {
			foreach ( array( array( new Context( '2026-07-28', true ), -32602 ), array( new Context( '2025-11-25', false ), -32002 ) ) as [ $context, $code ] ) {
				try {
					Resources::read( array( 'uri' => $uri ), $context );
					$this->fail( $uri );
				} catch ( Protocol_Error $error ) {
					$this->assertSame( $code, $error->rpc_code, $uri );
					$this->assertSame( 404, $error->status );
					$this->assertSame( $uri, $error->data['uri'] );
				}
			}
		}
	}

	/**
	 * Pattern slugs and page IDs for the arguments of the templates; prompts follow in phase 3.
	 */
	public function test_completion_of_template_arguments(): void {
		$complete = static fn( string $uri, string $name, string $value ): array => Completion::complete(
			array(
				'ref'      => array(
					'type' => 'ref/resource',
					'uri'  => $uri,
				),
				'argument' => array(
					'name'  => $name,
					'value' => $value,
				),
			)
		)['completion'];
		$slugs    = $complete( 'leitvia://pattern/{slug}', 'slug', 'hero-split' );
		$this->assertContains( 'leitvia/hero-split-image', $slugs['values'] );
		$this->assertSame( count( $slugs['values'] ), $slugs['total'] );
		$this->assertFalse( $slugs['hasMore'] );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertContains( (string) $page, $complete( 'leitvia://page/{id}/outline', 'id', (string) $page )['values'] );
		$this->assertSame( array(), $complete( 'leitvia://page/{id}/outline', 'id', 'x' )['values'] );
		$this->assertSame( array(), $complete( 'leitvia://unknown/{x}', 'x', '' )['values'] );
		$prompt = Completion::complete(
			array(
				'ref'      => array(
					'type' => 'ref/prompt',
					'name' => 'leitvia-page',
				),
				'argument' => array(
					'name'  => 'goal',
					'value' => '',
				),
			)
		);
		$this->assertSame( array(), $prompt['completion']['values'] );
	}

	/**
	 * JSON-RPC messages of an event stream, in order.
	 *
	 * @param string $stream Text of the stream.
	 * @return list<array<string, mixed>>
	 */
	private static function events( string $stream ): array {
		preg_match_all( '/^data: (.+)$/m', $stream, $matches );
		return array_map( static fn( string $json ): array => json_decode( $json, true ), $matches[1] );
	}

	/**
	 * One window: acknowledged with the subscription ID, both notifications after a change, a keep-alive every 15
	 * seconds without one, at the end the result with resultType complete.
	 */
	public function test_subscriptions_window(): void {
		$out   = '';
		$ticks = 0;
		Subscriptions::listen(
			array(
				'notifications' => array(
					'resourcesListChanged'  => true,
					'resourceSubscriptions' => array( 'leitvia://patterns' ),
				),
			),
			9,
			Subscriptions::acquire(),
			static function ( string $chunk ) use ( &$out ): void {
				$out .= $chunk;
			},
			static function () use ( &$ticks ): bool {
				if ( 3 === ++$ticks ) {
					Subscriptions::touch();
				}
				return true;
			}
		);
		$events = self::events( $out );
		$this->assertSame( Subscriptions::WINDOW, $ticks );
		$this->assertSame( 'notifications/subscriptions/acknowledged', $events[0]['method'] );
		$id = $events[0]['params']['_meta']['io.modelcontextprotocol/subscriptionId'];
		$this->assertSame( 9, $id, 'The subscription ID is the ID of the listen request.' );
		$this->assertSame(
			array(
				'resourcesListChanged'  => true,
				'resourceSubscriptions' => array( 'leitvia://patterns' ),
			),
			$events[0]['params']['notifications']
		);
		$this->assertSame( array( 'notifications/resources/list_changed', 'notifications/resources/updated' ), array_column( array_slice( $events, 1, 2 ), 'method' ) );
		$this->assertSame( 'leitvia://patterns', $events[2]['params']['uri'] );
		$this->assertSame( $id, $events[2]['params']['_meta']['io.modelcontextprotocol/subscriptionId'] );
		$this->assertSame( 3, substr_count( $out, ": keep-alive\n\n" ) );
		$end = end( $events );
		$this->assertSame( array( 9, 'complete' ), array( $end['id'], $end['result']['resultType'] ) );
		$this->assertSame( 'leitvia', $end['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] );
	}

	/**
	 * At most two streams per user; a closed connection ends the stream without a result and frees its slot.
	 */
	public function test_subscriptions_stream_limit(): void {
		$first = Subscriptions::acquire();
		Subscriptions::acquire();
		try {
			Subscriptions::acquire();
			$this->fail( 'A third stream opened.' );
		} catch ( Protocol_Error $error ) {
			$this->assertSame( 429, $error->status );
		}
		$out = '';
		Subscriptions::listen(
			array(),
			1,
			$first,
			static function ( string $chunk ) use ( &$out ): void {
				$out .= $chunk;
			},
			static fn(): bool => false
		);
		$this->assertCount( 1, self::events( $out ) );
		$this->assertNotSame( '', Subscriptions::acquire(), 'The ended stream freed its slot.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assertNotSame( '', Subscriptions::acquire(), 'Another user has slots of its own.' );
	}

	/**
	 * Acceptance of phase 2: a new pattern of the child theme is listed as unreviewed, and the subscriber hears of it.
	 */
	public function test_new_site_pattern_reaches_a_subscriber(): void {
		delete_transient( Subscriptions::CHECKED );
		Subscriptions::check();
		$slug  = get_stylesheet() . '/phase-two-new';
		$out   = '';
		$ticks = 0;
		Subscriptions::listen(
			array(
				'notifications' => array(
					'resourcesListChanged'  => true,
					'resourceSubscriptions' => array( 'leitvia://patterns' ),
				),
			),
			5,
			Subscriptions::acquire(),
			static function ( string $chunk ) use ( &$out ): void {
				$out .= $chunk;
			},
			static function () use ( &$ticks, $slug ): bool {
				if ( 2 === ++$ticks ) {
					register_block_pattern(
						$slug,
						array(
							'title'   => 'New section',
							'content' => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->',
						)
					);
					delete_transient( Subscriptions::CHECKED );
					Subscriptions::check();
				}
				return $ticks < 4;
			}
		);
		$pattern = \LeitviaStudio\Gateway\Abilities\Read_Design::pattern( $slug );
		unregister_block_pattern( $slug );
		$this->assertSame( array( 'notifications/subscriptions/acknowledged', 'notifications/resources/list_changed', 'notifications/resources/updated' ), array_column( self::events( $out ), 'method' ) );
		$this->assertSame( array( 'site', 'unreviewed', 'untrusted', null ), array( $pattern['source'], $pattern['status'], $pattern['trust'], $pattern['composition'] ) );
	}

	/**
	 * The fingerprint changes with the registered patterns; the check runs at most once a minute.
	 */
	public function test_subscriptions_fingerprint(): void {
		delete_transient( Subscriptions::CHECKED );
		Subscriptions::check();
		$before = Subscriptions::state()['changed'];
		delete_transient( Subscriptions::CHECKED );
		Subscriptions::check();
		$this->assertSame( $before, Subscriptions::state()['changed'], 'Unchanged patterns keep the counter.' );
		register_block_pattern(
			'leitvia-test/fingerprint',
			array(
				'title'   => 'Fingerprint',
				'content' => '<!-- wp:paragraph --><p>F</p><!-- /wp:paragraph -->',
			)
		);
		Subscriptions::check();
		$this->assertSame( $before, Subscriptions::state()['changed'], 'Checked within the minute.' );
		delete_transient( Subscriptions::CHECKED );
		Subscriptions::check();
		unregister_block_pattern( 'leitvia-test/fingerprint' );
		$this->assertSame( $before + 1, Subscriptions::state()['changed'] );
		do_action( 'leitvia_studio_patterns_changed', 'leitvia-test/fingerprint' );
		$this->assertSame( $before + 2, Subscriptions::state()['changed'], 'An approval in the Lab counts at once.' );
	}

	/**
	 * Resources and completions in both revisions, subscriptions only under 2026-07-28; instructions follow the state
	 * of the site.
	 */
	public function test_capabilities_and_instructions_per_state(): void {
		$modern = Dispatcher::handle( 'server/discover', array(), new Context( '2026-07-28', true ) );
		$this->assertSame(
			array(
				'subscribe'   => true,
				'listChanged' => true,
			),
			$modern['capabilities']['resources']
		);
		$this->assertArrayHasKey( 'completions', $modern['capabilities'] );
		$legacy = Dispatcher::handle( 'initialize', array(), new Context( '2025-11-25', false ) );
		$this->assertSame(
			array(
				'subscribe'   => false,
				'listChanged' => false,
			),
			$legacy['capabilities']['resources']
		);
		$this->assertArrayNotHasKey( 'ttlMs', Dispatcher::handle( 'resources/list', array(), new Context( '2025-11-25', false ) ) );
		$this->assertSame( 'private', Dispatcher::handle( 'resources/templates/list', array(), new Context( '2026-07-28', true ) )['cacheScope'] );
		$this->assertStringContainsString( 'leitvia-get-contract', Server::instructions() );
		$this->assertStringNotContainsString( 'leitvia://inbox', Server::instructions() );
		Packages::add(
			'',
			array(
				'ability'   => 'leitvia/update-content',
				'area'      => 'content',
				'target'    => array( 'post' => 12 ),
				'base_hash' => 'abcdef0123456789',
				'level'     => 'suggest',
				'kinds'     => array(),
				'state'     => array(),
				'findings'  => array(),
			)
		);
		$this->assertStringContainsString( 'leitvia://inbox', Server::instructions() );
		add_filter( 'leitvia_studio_contract_path', '__return_empty_array' );
		\LeitviaStudio\Plugin::forget_contract();
		$this->assertStringContainsString( 'A person activates the Leitvia theme', Server::instructions() );
		$this->assertStringNotContainsString( 'leitvia-setup', Server::instructions() );
		remove_filter( 'leitvia_studio_contract_path', '__return_empty_array' );
		\LeitviaStudio\Plugin::forget_contract();
	}

	/**
	 * The method resources/read over the route: Mcp-Name carries the URI, cache fields under 2026-07-28, 404 for a missing one.
	 */
	public function test_endpoint_resources(): void {
		$read = self::send( self::modern( self::message( 'resources/read', array( 'uri' => 'leitvia://contract' ) ) ) );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( 'private', $read->get_data()['result']['cacheScope'] );
		$this->assertSame( 'leitvia://contract', $read->get_data()['result']['contents'][0]['uri'] );
		$missing = self::send( self::modern( self::message( 'resources/read', array( 'uri' => 'leitvia://nothing' ) ) ) );
		$this->assertSame( array( 404, -32602 ), array( $missing->get_status(), $missing->get_data()['error']['code'] ) );
		$wrong = self::send( self::modern( self::message( 'resources/read', array( 'uri' => 'leitvia://contract' ) ), array( 'Mcp-Name' => 'leitvia://tokens' ) ) );
		$this->assertSame( -32020, $wrong->get_data()['error']['code'] );
	}

	/**
	 * The method subscriptions/listen answers with a stream; the third open stream of a user gets 429.
	 */
	public function test_endpoint_listen_streams(): void {
		$response = self::send( self::modern( self::message( 'subscriptions/listen', array( 'notifications' => array( 'resourcesListChanged' => true ) ) ) ) );
		$this->assertInstanceOf( Stream::class, $response );
		$this->assertSame( 'text/event-stream; charset=utf-8', $response->get_headers()['Content-Type'] );
		$this->assertSame( 'no', $response->get_headers()['X-Accel-Buffering'] );
		$out = '';
		$response->run(
			static function ( string $chunk ) use ( &$out ): void {
				$out .= $chunk;
			},
			static fn(): bool => false
		);
		$this->assertSame( 'notifications/subscriptions/acknowledged', self::events( $out )[0]['method'] );
		$this->assertFalse( Stream::serve( false, new WP_REST_Response() ) );
		$listen = array( 'notifications' => array( 'resourcesListChanged' => true ) );
		self::send( self::modern( self::message( 'subscriptions/listen', $listen ) ) );
		self::send( self::modern( self::message( 'subscriptions/listen', $listen ) ) );
		$third = self::send( self::modern( self::message( 'subscriptions/listen', $listen ) ) );
		$this->assertSame( 429, $third->get_status() );
		$this->assertSame( -32600, $third->get_data()['error']['code'] );
	}

	/**
	 * Without params.notifications there is no stream and no slot taken: invalid params.
	 */
	public function test_listen_requires_notifications(): void {
		foreach ( array( null, array(), 'x' ) as $notifications ) {
			$params   = null === $notifications ? array() : array( 'notifications' => $notifications );
			$response = self::send( self::modern( self::message( 'subscriptions/listen', $params ) ) );
			$this->assertNotInstanceOf( Stream::class, $response );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( -32602, $response->get_data()['error']['code'] );
			$this->assertSame( 'params.notifications is required.', $response->get_data()['error']['message'] );
		}
		$this->assertNotSame( '', Subscriptions::acquire() );
	}

	/**
	 * The site holds at most four streams (filterable) on top of two per user; the answer is 429.
	 */
	public function test_subscriptions_site_limit(): void {
		add_filter( 'leitvia_studio_mcp_stream_limit', static fn(): int => 2 );
		Subscriptions::acquire();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$second = Subscriptions::acquire();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		try {
			Subscriptions::acquire();
			$this->fail( 'A third stream opened on the site.' );
		} catch ( Protocol_Error $error ) {
			$this->assertSame( 429, $error->status );
		}
		Subscriptions::release( $second );
		Subscriptions::release( $second );
		$this->assertNotSame( '', Subscriptions::acquire(), 'A released slot is free again, a second release changes nothing.' );
		remove_all_filters( 'leitvia_studio_mcp_stream_limit' );
	}

	/**
	 * An author may use the abilities (edit_posts) but sees neither the private page nor the draft of an admin, not
	 * through resources/read and not through completion.
	 */
	public function test_author_does_not_reach_foreign_pages(): void {
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$private = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'private',
				'post_author'  => $admin,
				'post_title'   => 'Geheimseite',
				'post_content' => '<!-- wp:paragraph --><p>Geheimtext</p><!-- /wp:paragraph -->',
			)
		);
		$draft   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_author'  => $admin,
				'post_title'   => 'Entwurf',
				'post_content' => '<!-- wp:paragraph --><p>Geheimtext</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertTrue( \LeitviaStudio\Gateway\Abilities::can_use() );
		foreach ( array( $private, $draft ) as $id ) {
			foreach ( array( array( new Context( '2026-07-28', true ), -32602 ), array( new Context( '2025-11-25', false ), -32002 ) ) as [ $context, $code ] ) {
				try {
					$out = Resources::read( array( 'uri' => 'leitvia://page/' . $id . '/outline' ), $context );
					$this->fail( 'Page ' . $id . ' was read: ' . wp_json_encode( $out ) );
				} catch ( Protocol_Error $error ) {
					$this->assertContains( $error->status, array( 403, 404 ) );
					$this->assertStringNotContainsString( 'Geheim', (string) wp_json_encode( array( $error->getMessage(), $error->data ) ) );
				}
			}
			$values = Completion::complete(
				array(
					'ref'      => array(
						'type' => 'ref/resource',
						'uri'  => 'leitvia://page/{id}/outline',
					),
					'argument' => array(
						'name'  => 'id',
						'value' => '',
					),
				)
			)['completion']['values'];
			$this->assertNotContains( (string) $id, $values );
		}
	}
}
