<?php
/**
 * MCP Apps of the server leitvia: registry mcp_apps, tool meta, tools for views, ui:// resources and the extension.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Extension\Studio;
use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Mcp\Apps;
use LeitviaStudio\Mcp\Context;
use LeitviaStudio\Mcp\Dispatcher;
use LeitviaStudio\Mcp\OAuth\Bearer;
use LeitviaStudio\Mcp\Protocol_Error;
use LeitviaStudio\Mcp\Resources;
use LeitviaStudio\Mcp\Sessions;
use LeitviaStudio\Mcp\Tools;

/**
 * The add-on apps-addon brings one view on leitvia/get-package and one tool for views.
 */
class Test_Mcp_Apps extends WP_UnitTestCase {

	/**
	 * Client capabilities of a host that shows views.
	 */
	private const UI = array( 'extensions' => array( Apps::EXTENSION => array( 'mimeTypes' => array( Apps::MIME ) ) ) );

	/**
	 * HTML file of the view.
	 *
	 * @var string
	 */
	private static string $html = '';

	/**
	 * Fields of the view entry that a test adds.
	 *
	 * @var array<string, mixed>
	 */
	private array $entry = array();

	/**
	 * Writes the HTML file of the view.
	 */
	public static function wpSetUpBeforeClass(): void {
		self::$html = get_temp_dir() . 'leitvia-test-app.html';
		file_put_contents( self::$html, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Review</title></head><body><main id="app"></main></body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixture file.
	}

	/**
	 * Removes the HTML file.
	 */
	public static function wpTearDownAfterClass(): void {
		wp_delete_file( self::$html );
	}

	/**
	 * Studio with the add-on, abilities handed to core, an agent signed in.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		wp_get_abilities();
		add_action( 'leitvia_studio_init', array( $this, 'addon' ) );
		$this->restart();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
	}

	/**
	 * Studio without the add-on for the next test.
	 */
	public function tear_down(): void {
		remove_action( 'leitvia_studio_init', array( $this, 'addon' ) );
		remove_all_filters( 'leitvia_studio_mcp_tool_meta' );
		remove_all_filters( 'leitvia_studio_mcp_tool_result' );
		remove_all_filters( 'leitvia_studio_mcp_approve_tools' );
		Bearer::reset();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Registers the add-on, its view and its tool for views. Runs on leitvia_studio_init.
	 *
	 * @param Studio $studio Studio.
	 */
	public function addon( Studio $studio ): void {
		$studio->extensions()->register(
			'apps-addon',
			array(
				'label'           => 'Apps add-on',
				'version'         => '1.0.0',
				'requires_studio' => '0.1.0',
				'requires_api'    => LEITVIA_STUDIO_API,
			)
		);
		$studio->registry( 'mcp_apps' )->register(
			'apps-addon/review',
			$this->entry + array(
				'title' => 'Review',
				'path'  => self::$html,
				'tools' => array( 'leitvia/get-package' ),
			),
			'apps-addon'
		);
		$studio->registry( 'abilities' )->register(
			'apps-addon/refresh',
			array(
				'args'  => array(
					'label'               => 'Refresh',
					'description'         => 'Refresh for views.',
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => array( Abilities::class, 'can_use' ),
					'execute_callback'    => static fn() => array( 'ok' => true ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
						'show_in_rest' => true,
						'mcp'          => array(
							'public'     => false,
							'visibility' => array( 'app' ),
						),
					),
				),
				'group' => 'read',
			),
			'apps-addon'
		);
		$studio->registry( 'abilities' )->register(
			'apps-addon/locked',
			array(
				'args'  => array(
					'label'               => 'Locked',
					'description'         => 'Says why it is locked.',
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => static fn() => new \WP_Error( 'apps_addon_locked', 'Locked for this connection.', array( 'status' => 403 ) ),
					'execute_callback'    => static fn() => array( 'ok' => true ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
						'show_in_rest' => true,
					),
				),
				'group' => 'read',
			),
			'apps-addon'
		);
	}

	/**
	 * Runs leitvia_studio_init again and hands the abilities to core.
	 */
	private function restart(): void {
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Protocol state of a 2026-07-28 request.
	 *
	 * @param array<string, mixed> $capabilities Client capabilities.
	 */
	private static function context( array $capabilities = array() ): Context {
		return new Context( '2026-07-28', true, $capabilities );
	}

	/**
	 * The guard of the registry: an HTML file that exists, ability names, https origins without path in the known CSP
	 * fields, a callable data and a boolean prefers_border; title and path are required.
	 */
	public function test_registry_checks_its_entries(): void {
		$this->setExpectedIncorrectUsage( 'LeitviaStudio\Extension\Registry::register' );
		$results = array();
		$check   = static function ( Studio $studio ) use ( &$results ): void {
			$register = static fn( string $name, array $item ): bool => $studio->registry( 'mcp_apps' )->register(
				'apps-addon/' . $name,
				$item + array(
					'title' => 'View',
					'path'  => self::$html,
				),
				'apps-addon'
			);
			$results  = array(
				'ok'      => $register(
					'ok',
					array(
						'csp'            => array( 'resourceDomains' => array( 'https://cdn.example.org', 'https://*.example.net:8443' ) ),
						'prefers_border' => false,
						'data'           => '__return_empty_array',
						'description'    => 'A view.',
					)
				),
				'php'     => $register( 'php', array( 'path' => __FILE__ ) ),
				'missing' => $register( 'missing', array( 'path' => '/nowhere/app.html' ) ),
				'tools'   => $register( 'tools', array( 'tools' => array( 'Leitvia Get' ) ) ),
				'http'    => $register( 'http', array( 'csp' => array( 'connectDomains' => array( 'http://api.example.org' ) ) ) ),
				'path'    => $register( 'path', array( 'csp' => array( 'connectDomains' => array( 'https://api.example.org/v1' ) ) ) ),
				'field'   => $register( 'field', array( 'csp' => array( 'scriptDomains' => array( 'https://cdn.example.org' ) ) ) ),
				'list'    => $register( 'list', array( 'csp' => 'https://cdn.example.org' ) ),
				'data'    => $register( 'data', array( 'data' => 'no_such_function_in_this_test' ) ),
				'border'  => $register( 'border', array( 'prefers_border' => 'yes' ) ),
				'text'    => $register( 'text', array( 'description' => '' ) ),
				'title'   => $studio->registry( 'mcp_apps' )->register( 'apps-addon/title', array( 'path' => self::$html ), 'apps-addon' ),
			);
		};
		add_action( 'leitvia_studio_init', $check, 11 );
		$this->restart();
		remove_action( 'leitvia_studio_init', $check, 11 );
		$this->assertSame( array( 'ok' => true ) + array_fill_keys( array( 'php', 'missing', 'tools', 'http', 'path', 'field', 'list', 'data', 'border', 'text', 'title' ), false ), $results );
	}

	/**
	 * Views only for a client that declared the extension with the MIME type, in either revision, and only while a
	 * view is registered.
	 */
	public function test_views_only_for_a_client_that_declared_them(): void {
		$this->assertTrue( Apps::offered( self::context( self::UI ) ) );
		$this->assertTrue( Apps::offered( new Context( '2025-11-25', false, self::UI, 'session' ) ) );
		$this->assertFalse( Apps::offered( self::context() ) );
		$this->assertFalse( Apps::offered( self::context( array( 'extensions' => array( Apps::EXTENSION => array( 'mimeTypes' => array( 'text/html' ) ) ) ) ) ) );
		$this->assertFalse( Apps::offered( self::context( array( 'extensions' => array( Apps::EXTENSION => array() ) ) ) ) );
		$this->assertFalse( Apps::offered( null ) );
		remove_action( 'leitvia_studio_init', array( $this, 'addon' ) );
		$this->restart();
		$this->assertFalse( Apps::offered( self::context( self::UI ) ), 'no view registered' );
	}

	/**
	 * A view is a ui:// resource: listed with its _meta.ui only for a client that shows views, readable with the JSON
	 * of its data before </head>, which cannot close the script; an unknown ui:// URI is a missing resource.
	 */
	public function test_views_are_ui_resources(): void {
		$this->entry = array(
			'description' => 'Diff of a package.',
			'data'        => static fn(): array => array( 'strings' => array( 'title' => '</script><b>Ü' ) ),
		);
		$this->restart();
		$listed = array_column( Resources::list( self::context( self::UI ) )['resources'], null, 'uri' );
		$this->assertSame(
			array(
				'uri'         => 'ui://apps-addon/review',
				'name'        => 'apps-addon-review',
				'title'       => 'Review',
				'description' => 'Diff of a package.',
				'mimeType'    => 'text/html;profile=mcp-app',
				'_meta'       => array( 'ui' => array( 'prefersBorder' => true ) ),
			),
			$listed['ui://apps-addon/review']
		);
		$this->assertArrayNotHasKey( 'ui://apps-addon/review', array_column( Resources::list( self::context() )['resources'], null, 'uri' ) );
		$this->assertArrayNotHasKey( 'ui://apps-addon/review', array_column( Resources::list()['resources'], null, 'uri' ) );
		$read = Resources::read( array( 'uri' => 'ui://apps-addon/review' ), self::context() )['contents'][0];
		$this->assertSame( 'ui://apps-addon/review', $read['uri'] );
		$this->assertSame( Apps::MIME, $read['mimeType'] );
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $read['_meta'] );
		$this->assertStringContainsString( '<script type="application/json" id="mcp-app-data">{"strings":{"title":"\u003C\/script\u003E\u003Cb\u003EÜ"}}</script></head>', $read['text'] );
		$this->assertSame( 1, substr_count( $read['text'], '</script>' ) );
		foreach ( array(
			array( '2026-07-28', true, -32602 ),
			array( '2025-11-25', false, -32002 ),
		) as [ $version, $modern, $code ] ) {
			try {
				Resources::read( array( 'uri' => 'ui://apps-addon/nothing' ), new Context( $version, $modern ) );
				$this->fail( 'an unknown view is a missing resource' );
			} catch ( Protocol_Error $error ) {
				$this->assertSame( $code, $error->rpc_code );
			}
		}
	}

	/**
	 * A tool an entry names carries _meta.ui.resourceUri and the flat key, a tool for views its visibility; both only
	 * for a client that shows views. There a tool for views runs, elsewhere it is unknown, and the other tools stay as
	 * they are.
	 */
	public function test_tools_carry_views_and_tools_for_views_need_a_view_client(): void {
		$with    = array_column( Tools::list( self::context( self::UI ) )['tools'], null, 'name' );
		$without = array_column( Tools::list( self::context() )['tools'], null, 'name' );
		$this->assertSame(
			array(
				'ui'             => array( 'resourceUri' => 'ui://apps-addon/review' ),
				'ui/resourceUri' => 'ui://apps-addon/review',
			),
			$with['leitvia-get-package']['_meta']
		);
		$this->assertSame( array( 'ui' => array( 'visibility' => array( 'app' ) ) ), $with['apps-addon-refresh']['_meta'] );
		$this->assertArrayNotHasKey( '_meta', $with['leitvia-get-contract'] );
		$this->assertArrayNotHasKey( '_meta', $without['leitvia-get-package'] );
		$this->assertArrayNotHasKey( 'apps-addon-refresh', $without );
		$this->assertSame( array_keys( $without ), array_values( array_diff( array_keys( $with ), array( 'apps-addon-refresh' ) ) ) );
		$this->assertSame( array_keys( $without ), array_column( Tools::list()['tools'], 'name' ), 'without context as before' );
		$this->assertSame( array( 'ok' => true ), Tools::call( array( 'name' => 'apps-addon-refresh' ), self::context( self::UI ) )['structuredContent'] );
		try {
			Tools::call( array( 'name' => 'apps-addon-refresh' ), self::context() );
			$this->fail( 'a tool for views is unknown to a client without views' );
		} catch ( Protocol_Error $error ) {
			$this->assertSame( -32602, $error->rpc_code );
		}
	}

	/**
	 * A tool only views call answers with a short note as text, its data stays in structuredContent; a tool of the
	 * model keeps its JSON. The filter leitvia_studio_mcp_tool_result sees each result with tool, ability, output and
	 * context.
	 */
	public function test_results_of_tools_for_views_carry_no_json_as_text(): void {
		$view = Tools::call( array( 'name' => 'apps-addon-refresh' ), self::context( self::UI ) );
		$this->assertSame( array( 'ok' => true ), $view['structuredContent'] );
		$this->assertSame( 'Result of apps-addon-refresh for a view, in structuredContent.', $view['content'][0]['text'] );
		$model = Tools::call( array( 'name' => 'leitvia-get-contract' ), self::context( self::UI ) );
		$this->assertSame( $model['structuredContent'], json_decode( $model['content'][0]['text'], true ) );
		add_filter(
			'leitvia_studio_mcp_tool_result',
			static fn( array $tool, string $name, \WP_Ability $ability, $result, Context $context ): array => $tool + array( '_meta' => array( 'example/seen' => array( $name, $ability->get_name(), $result, Apps::offered( $context ) ) ) ),
			10,
			5
		);
		$this->assertSame( array( 'example/seen' => array( 'apps-addon-refresh', 'apps-addon/refresh', array( 'ok' => true ), true ) ), Tools::call( array( 'name' => 'apps-addon-refresh' ), self::context( self::UI ) )['_meta'] );
	}

	/**
	 * A permission callback that says why it refuses keeps its code and message in the tool result.
	 */
	public function test_a_refusal_of_the_permission_callback_keeps_its_code(): void {
		$result = Tools::call( array( 'name' => 'apps-addon-locked' ), self::context() );
		$this->assertTrue( $result['isError'] );
		$details = json_decode( $result['content'][0]['text'], true );
		$this->assertSame( 'apps_addon_locked', $details['code'] );
		$this->assertSame( 'Locked for this connection.', $details['message'] );
		$this->assertSame( 403, $details['data']['status'] );
	}

	/**
	 * The filter leitvia_studio_mcp_tool_meta sees every tool with its ability and the context.
	 */
	public function test_filter_changes_the_meta_of_a_tool(): void {
		add_filter(
			'leitvia_studio_mcp_tool_meta',
			static function ( array $meta, string $tool, \WP_Ability $ability, ?Context $context ): array {
				return 'leitvia-get-contract' === $tool && Apps::offered( $context ) ? $meta + array( 'example/hint' => $ability->get_name() ) : $meta;
			},
			10,
			4
		);
		$tools = array_column( Tools::list( self::context( self::UI ) )['tools'], null, 'name' );
		$this->assertSame( array( 'example/hint' => 'leitvia/get-contract' ), $tools['leitvia-get-contract']['_meta'] );
		$this->assertArrayNotHasKey( '_meta', array_column( Tools::list( self::context() )['tools'], null, 'name' )['leitvia-get-contract'] );
	}

	/**
	 * The methods server/discover and initialize name the extension only for a client that shows views; tools/list and
	 * resources/list of the dispatcher pass the context on.
	 */
	public function test_extension_in_capabilities_only_with_views(): void {
		$with = Dispatcher::handle( 'server/discover', array(), self::context( self::UI ) );
		$this->assertEquals( new stdClass(), $with['capabilities']['extensions'][ Apps::EXTENSION ] );
		$without = Dispatcher::handle( 'server/discover', array(), self::context() );
		$this->assertArrayNotHasKey( Apps::EXTENSION, (array) ( $without['capabilities']['extensions'] ?? array() ) );
		$legacy = Dispatcher::handle( 'initialize', array(), new Context( '2025-11-25', false, self::UI ) );
		$this->assertEquals( new stdClass(), $legacy['capabilities']['extensions'][ Apps::EXTENSION ] );
		$this->assertArrayNotHasKey( 'extensions', Dispatcher::handle( 'initialize', array(), new Context( '2025-11-25', false ) )['capabilities'] );
		$this->assertContains( 'apps-addon-refresh', array_column( Dispatcher::handle( 'tools/list', array(), self::context( self::UI ) )['tools'], 'name' ) );
		$this->assertContains( 'ui://apps-addon/review', array_column( Dispatcher::handle( 'resources/list', array(), self::context( self::UI ) )['resources'], 'uri' ) );
	}

	/**
	 * A client on 2025-11-25 declares the extension at initialize; the session keeps only its extensions, and the next
	 * tools/list of the session carries the views.
	 */
	public function test_a_2025_client_keeps_its_capabilities_in_the_session(): void {
		$send     = static function ( array $message, string $session = '' ): WP_REST_Response {
			$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' );
			$request->set_header( 'Content-Type', 'application/json' );
			if ( '' !== $session ) {
				$request->set_header( 'Mcp-Session-Id', $session );
			}
			$request->set_body( (string) wp_json_encode( array( 'jsonrpc' => '2.0' ) + $message ) );
			return rest_get_server()->dispatch( $request );
		};
		$response = $send(
			array(
				'id'     => 1,
				'method' => 'initialize',
				'params' => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => self::UI + array( 'roots' => array( 'listChanged' => true ) ),
					'clientInfo'      => array(
						'name'    => 'test',
						'version' => '1.0.0',
					),
				),
			)
		);
		$session  = $response->get_headers()['Mcp-Session-Id'];
		$this->assertEquals( new stdClass(), $response->get_data()['result']['capabilities']['extensions'][ Apps::EXTENSION ] );
		$this->assertSame( self::UI, Sessions::get( $session )['capabilities'], 'only the extensions are kept' );
		$tools = array_column(
			$send(
				array(
					'id'     => 2,
					'method' => 'tools/list',
				),
				$session
			)->get_data()['result']['tools'],
			null,
			'name'
		);
		$this->assertSame( 'ui://apps-addon/review', $tools['leitvia-get-package']['_meta']['ui']['resourceUri'] );
		$this->assertSame( array(), Sessions::get( Sessions::start( '2025-11-25' ) )['capabilities'] );
		$declared = array(
			'extensions' => array(
				Apps::EXTENSION => array(
					'mimeTypes' => array_merge( array( Apps::MIME, 7, str_repeat( 'x', 101 ), Apps::MIME ), array_map( static fn( int $n ): string => 'text/x-' . $n, range( 1, 12 ) ) ),
					'other'     => str_repeat( 'y', 5000 ),
				),
				'example/other' => array( 'big' => str_repeat( 'z', 5000 ) ),
			),
			'sampling'   => array(),
		);
		$this->assertSame(
			array( 'extensions' => array( Apps::EXTENSION => array( 'mimeTypes' => array_merge( array( Apps::MIME ), array_map( static fn( int $n ): string => 'text/x-' . $n, range( 1, 9 ) ) ) ) ) ),
			Sessions::get( Sessions::start( '2025-11-25', $declared ) )['capabilities'],
			'only up to 10 MIME types of the extension of views'
		);
		$this->assertSame( array(), Sessions::get( Sessions::start( '2025-11-25', array( 'extensions' => array( Apps::EXTENSION => array( 'mimeTypes' => 'text/html' ) ) ) ) )['capabilities'] );
	}

	/**
	 * Studio names no tool for leitvia:approve, so a token with approve only reads: apply-package, reject-package and
	 * rollback need write as for any token. An add-on that lets a person decide in a view names its own tool for views.
	 */
	public function test_approve_names_no_tool_unless_an_add_on_does(): void {
		$this->assertSame( array(), Bearer::approve_tools() );
		( static function (): void {
			self::$token = array( 'scope' => 'leitvia:read leitvia:approve' );
		} )->bindTo( null, Bearer::class )();
		foreach ( array( 'leitvia-apply-package', 'leitvia-reject-package', 'leitvia-rollback' ) as $tool ) {
			$denial = Bearer::denial( 'tools/call', array( 'name' => $tool ) );
			$this->assertSame( 403, $denial['error']->status, $tool );
			$this->assertSame( array( 'scope' => 'leitvia:write' ), $denial['error']->data, $tool );
		}
		add_filter( 'leitvia_studio_mcp_approve_tools', static fn(): array => array( 'apps-addon-decide', 7 ) );
		$this->assertSame( array( 'apps-addon-decide' ), Bearer::approve_tools() );
		$this->assertSame( array( 'scope' => 'leitvia:write' ), Bearer::denial( 'tools/call', array( 'name' => 'leitvia-apply-package' ) )['error']->data );
	}
}
