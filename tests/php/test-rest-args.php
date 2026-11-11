<?php
/**
 * Arguments and schemas of the Studio REST routes.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Mcp;

/**
 * Core refuses a parameter of the wrong type with rest_invalid_param before the callback runs, and the routes describe
 * their answers.
 *
 * @group multisite
 */
class Test_Rest_Args extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private static int $admin;

	/**
	 * Creates the admin.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WordPress test API.
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin );
		}
	}

	/**
	 * Logs the admin in and registers the routes.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$admin );
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Dispatches a request with a JSON body.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route below /leitvia-studio/v1.
	 * @param array<string, mixed> $body   JSON body.
	 */
	private static function rest( string $method, string $route, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/leitvia-studio/v1' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A wrong type ends in rest_invalid_param with status 400 and names the parameter; nothing is stored.
	 */
	public function test_wrong_types_are_refused(): void {
		$model = get_option( 'leitvia_studio_model' );
		$cases = array(
			array( 'PUT', '/model', array( 'postTypes' => 'book' ), 'postTypes' ),
			array( 'POST', '/model/validate', array( 'version' => 'one' ), 'version' ),
			array( 'PUT', '/gateway/settings', array( 'retention_days' => 'soon' ), 'retention_days' ),
			array( 'PUT', '/gateway/settings', array( 'confirm' => 'maybe' ), 'confirm' ),
			array( 'POST', '/patterns/import', array( 'title' => array( 'not', 'text' ) ), 'title' ),
			array( 'POST', '/patterns/import', array( 'leitvia' => 'flat' ), 'leitvia' ),
			array(
				'POST',
				'/token-sets/import',
				array(
					'title'    => 'Number',
					'document' => 42,
				),
				'document',
			),
			array(
				'POST',
				'/token-sets/import',
				array(
					'title'    => array( 'x' ),
					'document' => '{}',
				),
				'title',
			),
			array(
				'POST',
				'/token-sets',
				array(
					'title'  => array( 'x' ),
					'source' => 'none',
				),
				'title',
			),
		);
		foreach ( $cases as list( $method, $route, $body, $param ) ) {
			$response = self::rest( $method, $route, $body );
			$label    = $method . ' ' . $route . ' ' . wp_json_encode( $body );
			$this->assertSame( 400, $response->get_status(), $label );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'], $label );
			$this->assertArrayHasKey( $param, $response->get_data()['data']['params'], $label );
		}
		$this->assertSame( $model, get_option( 'leitvia_studio_model' ) );
	}

	/**
	 * The title of a new variant must be text; sanitize_callback alone would let an array through.
	 */
	public function test_variant_title_is_validated(): void {
		$id       = self::factory()->post->create( array( 'post_type' => 'wp_block' ) );
		$response = self::rest( 'POST', '/patterns/' . $id . '/variants', array( 'title' => array( 'x' ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'title', $response->get_data()['data']['params'] );
	}

	/**
	 * Usage and states take the same reference, a pattern slug with dots and underscores included.
	 */
	public function test_usage_and_states_share_the_reference(): void {
		$routes = rest_get_server()->get_routes();
		$usage  = $routes['/leitvia-studio/v1/usage'][0]['args']['ref'];
		$states = $routes['/leitvia-studio/v1/patterns/states'][0]['args']['ref'];
		$this->assertSame( $states, $usage );
		$request = new WP_REST_Request( 'GET', '/leitvia-studio/v1/usage' );
		$request->set_param( 'ref', 'pattern:my_theme/hero.v2' );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * OPTIONS of a route names the schema of its answer.
	 */
	public function test_routes_describe_their_answers(): void {
		$titles = array(
			'/model'                        => 'leitvia-studio-model',
			'/gateway/access'               => 'leitvia-studio-gateway-access',
			'/gateway/settings'             => 'leitvia-studio-gateway-settings',
			'/patterns/import'              => 'leitvia-studio-pattern',
			'/patterns/states'              => 'leitvia-studio-pattern',
			'/token-sets/import'            => 'leitvia-studio-token-set',
			'/packages'                     => 'leitvia-studio-package',
			'/building-blocks'              => 'leitvia-studio-building-block',
			'/extensions'                   => 'leitvia-studio-extensions',
			'/recipes'                      => 'leitvia-studio-recipe',
			'/token-sets/(?P<id>\d+)/check' => 'leitvia-studio-token-set',
		);
		foreach ( $titles as $route => $title ) {
			$path = '/leitvia-studio/v1' . $route;
			$data = rest_get_server()->get_data_for_route( $path, rest_get_server()->get_routes()[ $path ], 'help' );
			$this->assertSame( $title, $data['schema']['title'] ?? null, $route );
		}
	}

	/**
	 * Every route of Studio names a schema in OPTIONS, so a new route without one fails here. The namespace index of
	 * core and the MCP server of the adapter are not Studio routes.
	 */
	public function test_every_route_has_a_schema(): void {
		$server = rest_get_server();
		$skip   = array( '/' . Mcp::ROUTE_NAMESPACE, '/' . Mcp::ROUTE_NAMESPACE . '/' . Mcp::ROUTE );
		$routes = array_filter(
			$server->get_routes( Mcp::ROUTE_NAMESPACE ),
			static fn( string $path ): bool => ! in_array( $path, $skip, true ),
			ARRAY_FILTER_USE_KEY
		);
		$this->assertArrayHasKey( '/leitvia-studio/v1/notes/dismiss', $routes );
		$this->assertArrayHasKey( '/leitvia-studio/v1/packages/(?P<id>\d+)/rollback', $routes );
		foreach ( $routes as $path => $handlers ) {
			$data = $server->get_data_for_route( $path, $handlers, 'help' );
			$this->assertNotEmpty( $data['schema']['title'] ?? '', $path );
			$this->assertContains( $data['schema']['type'] ?? null, array( 'object', 'array' ), $path );
		}
	}
}
