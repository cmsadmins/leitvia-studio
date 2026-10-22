<?php
/**
 * Content model declaration and its REST routes.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * PUT, GET and validation of /leitvia-studio/v1/model, merge with code, schema route.
 */
class Test_Model extends WP_UnitTestCase {

	/**
	 * User IDs by role.
	 *
	 * @var array<string, int>
	 */
	private static array $users = array();

	/**
	 * Creates one user per role.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WordPress test API.
		foreach ( array( 'administrator', 'editor', 'subscriber' ) as $role ) {
			self::$users[ $role ] = $factory->user->create( array( 'role' => $role ) );
		}
	}

	/**
	 * Starts as administrator with an empty model.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$users['administrator'] );
		Model::forget();
	}

	/**
	 * Unregisters the test types and drops the cached model.
	 */
	public function tear_down(): void {
		// Types registered here outlive the test; a foreign "event" would block the next test class.
		foreach ( array( 'event', 'person', 'event_session', 'foreign' ) as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}
		Model::forget();
		parent::tear_down();
	}

	/**
	 * A fixture of tests/fixtures/model/.
	 *
	 * @param string $name File name without .json.
	 * @return array<string, mixed>
	 */
	private static function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . "/fixtures/model/$name.json" ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
	}

	/**
	 * Sends a declaration.
	 *
	 * @param array<string, mixed> $decl   Declaration.
	 * @param string               $method Method.
	 * @param string               $route  Route below /leitvia-studio/v1.
	 */
	private static function send( array $decl, string $method = 'PUT', string $route = '/model' ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/leitvia-studio/v1' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $decl ) );
		return rest_do_request( $request );
	}

	/**
	 * Administrators store the fixture; editors and subscribers may not.
	 */
	public function test_put_stores_the_declaration_for_administrators_only(): void {
		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			wp_set_current_user( self::$users[ $role ] );
			$this->assertSame( 403, self::send( self::fixture( 'events' ) )->get_status(), $role );
			$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/model' ) )->get_status(), $role );
		}
		$this->assertFalse( get_option( Model::OPTION ) );

		wp_set_current_user( self::$users['administrator'] );
		$response = self::send( self::fixture( 'events' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::fixture( 'events' ), get_option( Model::OPTION ) );
		$this->assertSame( 'ui', $response->get_data()['model']['postTypes']['event']['source'] );
		$this->assertEquals( 1, get_option( Model::FLUSH_OPTION ) );
	}

	/**
	 * Every invalid fixture answers 400 with data.errors as a list of { path, message } and stores nothing.
	 *
	 * @dataProvider invalid_fixtures
	 *
	 * @param string $name File name without .json.
	 * @param string $path Expected JSON pointer of the error.
	 */
	public function test_invalid_declarations_are_refused( string $name, string $path ): void {
		register_post_type( 'foreign' );
		$response = self::send( self::fixture( $name ) );
		$this->assertSame( 400, $response->get_status(), $name );
		$errors = $response->get_data()['data']['errors'];
		$this->assertTrue( array_is_list( $errors ) );
		foreach ( $errors as $error ) {
			$this->assertSame( array( 'path', 'message' ), array_keys( $error ) );
			$this->assertNotSame( '', $error['message'] );
		}
		$this->assertContains( $path, array_column( $errors, 'path' ), wp_json_encode( $errors ) );
		$this->assertFalse( get_option( Model::OPTION ) );

		$this->assertSame( 400, self::send( self::fixture( $name ), 'POST', '/model/validate' )->get_status() );
	}

	/**
	 * Invalid fixtures and the path their error names.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function invalid_fixtures(): array {
		return array(
			'key too long'                => array( 'invalid-key-too-long', '/postTypes/event_with_a_long_key' ),
			'unknown field type'          => array( 'invalid-field-type', '/postTypes/event/fields/event_color/type' ),
			'select without options'      => array( 'invalid-select-options', '/postTypes/event/fields/event_level' ),
			'relation to undeclared type' => array( 'invalid-relation-target', '/relations/event_venue/to' ),
			'reserved term'               => array( 'invalid-reserved', '/postTypes/author' ),
			'repeater type is post type'  => array( 'invalid-repeater-type', '/postTypes/event/repeaters/event_agenda/postType' ),
			'core post type'              => array( 'invalid-core-type', '/postTypes/post' ),
			'foreign post type'           => array( 'invalid-foreign-type', '/postTypes/foreign' ),
			'abilities on a field'        => array( 'invalid-field-abilities', '/postTypes/event/fields/event_venue/showInAbilities' ),
			'abilities on a repeater'     => array( 'invalid-repeater-abilities', '/postTypes/event/repeaters/event_agenda/showInAbilities' ),
		);
	}

	/**
	 * Validate answers 200 for the fixture and stores nothing.
	 */
	public function test_validate_does_not_store(): void {
		$response = self::send( self::fixture( 'events' ), 'POST', '/model/validate' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'valid' => true ), $response->get_data() );
		$this->assertFalse( get_option( Model::OPTION ) );
	}

	/**
	 * Relations may point to public post types of WordPress such as pages; they start at declared types only, and media
	 * or internal types are no targets.
	 */
	public function test_relation_to_core_post_type(): void {
		$decl = self::fixture( 'events' );
		$with = static function ( string $from, string $to ) use ( $decl ): array {
			$decl['relations']['event_guide'] = array(
				'label' => 'Guide',
				'from'  => $from,
				'to'    => $to,
			);
			return $decl;
		};
		$this->assertSame( 200, self::send( $with( 'event', 'page' ), 'POST', '/model/validate' )->get_status() );
		foreach ( array( 'attachment', 'wp_block', 'event_session' ) as $to ) {
			$errors = self::send( $with( 'event', $to ), 'POST', '/model/validate' )->get_data()['data']['errors'];
			$this->assertContains( '/relations/event_guide/to', array_column( $errors, 'path' ), $to );
		}
		$errors = self::send( $with( 'page', 'event' ), 'POST', '/model/validate' )->get_data()['data']['errors'];
		$this->assertContains( '/relations/event_guide/from', array_column( $errors, 'path' ) );
	}

	/**
	 * Code declarations merge with the option, win, are marked and cannot be stored from the screen.
	 */
	public function test_code_declaration_wins_and_is_read_only(): void {
		$events = self::fixture( 'events' );
		$code   = static fn( array $decl ): array => array( 'postTypes' => array( 'person' => $events['postTypes']['person'] ) ) + $decl;
		add_filter( 'leitvia_studio_content_model', $code );

		update_option(
			Model::OPTION,
			array(
				'version'   => 1,
				'postTypes' => array(
					'person' => array(
						'label'         => 'Stored people',
						'singularLabel' => 'Stored person',
					),
				),
			)
		);
		Model::forget();
		$this->assertSame( 'People', Model::current()['postTypes']['person']['label'] );

		// The screen may declare entries that point to code entries, but not entries of the same key.
		unset( $events['postTypes']['person'] );
		$this->assertSame( 200, self::send( $events )->get_status() );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/model' ) );
		$model    = $response->get_data()['model'];
		$this->assertSame( 'code', $model['postTypes']['person']['source'] );
		$this->assertSame( 'ui', $model['postTypes']['event']['source'] );
		$this->assertSame( 'ui', $model['relations']['event_speakers']['source'] );
		$this->assertSame( 'ui', $model['settings']['company_phone']['source'] );

		$events['postTypes']['person'] = array(
			'label'         => 'Mine',
			'singularLabel' => 'Mine',
		);
		$response                      = self::send( $events );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( '/postTypes/person' ), array_column( $response->get_data()['data']['errors'], 'path' ) );
		$this->assertArrayNotHasKey( 'person', get_option( Model::OPTION )['postTypes'] );
	}

	/**
	 * The schema route serves the JSON schema to authors and up.
	 */
	public function test_schema_route(): void {
		wp_set_current_user( self::$users['editor'] );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/model/schema' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Model::schema(), $response->get_data() );
		$this->assertSame( 'object', $response->get_data()['type'] );

		wp_set_current_user( self::$users['subscriber'] );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/model/schema' ) )->get_status() );
	}

	/**
	 * The schema stays inside the subset rest_validate_value_from_schema() understands.
	 */
	public function test_schema_uses_the_core_subset_only(): void {
		$allowed = array( '$schema', 'title', 'description', 'type', 'properties', 'additionalProperties', 'patternProperties', 'required', 'enum', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum', 'items' );
		$walk    = function ( array $node ) use ( &$walk, $allowed ): void {
			foreach ( $node as $key => $child ) {
				$this->assertContains( $key, $allowed );
				if ( in_array( $key, array( 'properties', 'patternProperties' ), true ) ) {
					array_map( $walk, $child );
				} elseif ( is_array( $child ) && in_array( $key, array( 'items', 'additionalProperties' ), true ) ) {
					$walk( $child );
				}
			}
		};
		$walk( Model::schema() );
		$this->assertTrue( rest_validate_value_from_schema( self::fixture( 'events' ), Model::schema() ) );
	}

	/**
	 * Labels, descriptions and option texts refuse HTML; an empty object is a declaration without version.
	 */
	public function test_html_and_empty_object_are_refused(): void {
		$decl = self::fixture( 'events' );

		$decl['postTypes']['event']['label'] = '<img src=x onerror=alert(1)>';
		$response                            = self::send( $decl );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( '/postTypes/event/label' ), array_column( $response->get_data()['data']['errors'], 'path' ) );

		$decl = self::fixture( 'events' );
		$decl['postTypes']['event']['fields']['event_level']['options']['advanced'] = 'Advanced <script>';
		$response = self::send( $decl );
		$this->assertSame( array( '/postTypes/event/fields/event_level/options/advanced' ), array_column( $response->get_data()['data']['errors'], 'path' ) );

		$response = self::send( array() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( '' ), array_column( $response->get_data()['data']['errors'], 'path' ) );
		$this->assertStringContainsString( 'version', $response->get_data()['data']['errors'][0]['message'] );
		$this->assertFalse( get_option( Model::OPTION ) );
	}

	/**
	 * Keys shared by a post type and a taxonomy, URL slugs used twice and meta keys of other plugins are refused.
	 */
	public function test_collisions_are_refused(): void {
		register_post_meta( 'event', 'event_room', array( 'type' => 'string' ) );
		$decl = self::fixture( 'events' );
		$decl['postTypes']['event']['fields']['event_room'] = array(
			'type'  => 'text',
			'label' => 'Room',
		);
		$decl['taxonomies']['person']                       = array(
			'label'         => 'People',
			'singularLabel' => 'Person',
			'postTypes'     => array( 'event' ),
		);
		$decl['postTypes']['person']['slug']                = 'events';
		$decl['taxonomies']['event_type']['public']         = true;
		$decl['taxonomies']['event_type']['slug']           = 'events';
		$paths = array_column( self::send( $decl )->get_data()['data']['errors'], 'path' );
		sort( $paths );
		$this->assertSame( array( '/postTypes/event/fields/event_room', '/postTypes/person', '/taxonomies/event_type', '/taxonomies/person' ), $paths );
	}

	/**
	 * A plugin that registers a declared key later replaces the Studio type; GET reports it and saving is refused.
	 */
	public function test_later_foreign_registration_is_reported(): void {
		$this->assertSame( 200, self::send( self::fixture( 'events' ) )->get_status() );
		Registrar::register();
		$this->assertTrue( Registrar::owns_post_type( 'event' ) );
		$this->assertSame( array(), rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/model' ) )->get_data()['errors'] );

		register_post_type( 'event', array( 'public' => true ) );
		$this->assertFalse( Registrar::owns_post_type( 'event' ) );
		$errors = rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/model' ) )->get_data()['errors'];
		$this->assertSame( array( '/postTypes/event' ), array_column( $errors, 'path' ) );
		$response = self::send( self::fixture( 'events' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertContains( '/postTypes/event', array_column( $response->get_data()['data']['errors'], 'path' ) );
	}

	/**
	 * A declaration from code that fails validation registers nothing and is reported by GET.
	 */
	public function test_invalid_code_declaration_registers_nothing(): void {
		add_filter( 'leitvia_studio_content_model', static fn(): array => self::fixture( 'invalid-reserved' ) );
		Registrar::register();
		$this->assertFalse( post_type_exists( 'author' ) );
		$errors = rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/model' ) )->get_data()['errors'];
		$this->assertSame( '/postTypes/author', $errors[0]['path'] );
	}
}
