<?php
/**
 * Registration of the content model through core APIs.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * Post types, taxonomies, meta, relations, repeaters, settings, abilities flag, rewrite flush, deactivation.
 */
class Test_Registrar extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private static int $admin;

	/**
	 * Creates the administrator.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WordPress test API.
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Stores the fixture and registers it.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$admin );
		update_option( Model::OPTION, json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/model/events.json' ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		Model::forget();
		Registrar::register();
		// The REST server builds its routes and schemas from what is registered now.
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Unregisters the settings and drops the cached model.
	 */
	public function tear_down(): void {
		foreach ( array( 'company_phone', 'company_email' ) as $key ) {
			unregister_setting( Registrar::SETTINGS_GROUP, Registrar::SETTING_PREFIX . $key );
		}
		Model::forget();
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Post types, repeater type and taxonomy exist with the declared arguments.
	 */
	public function test_types_and_taxonomy(): void {
		foreach ( array( 'event', 'person', 'event_session' ) as $post_type ) {
			$this->assertTrue( post_type_exists( $post_type ), $post_type );
			$this->assertTrue( get_post_type_object( $post_type )->show_in_rest, $post_type );
		}
		$event = get_post_type_object( 'event' );
		$this->assertTrue( $event->public );
		$this->assertTrue( $event->has_archive );
		$this->assertSame( 'events', $event->rewrite['slug'] );
		$this->assertSame( 'dashicons-calendar', $event->menu_icon );
		$this->assertSame( 'Add new Event', $event->labels->add_new_item );
		$this->assertSame( 'No Events found.', $event->labels->not_found );
		$this->assertTrue( post_type_supports( 'event', 'custom-fields' ) );

		$session = get_post_type_object( 'event_session' );
		$this->assertFalse( $session->public );
		$this->assertFalse( $session->show_ui );
		foreach ( array( 'title', 'custom-fields', 'page-attributes' ) as $feature ) {
			$this->assertTrue( post_type_supports( 'event_session', $feature ), $feature );
		}

		$this->assertTrue( taxonomy_exists( 'event_type' ) );
		$this->assertTrue( is_taxonomy_hierarchical( 'event_type' ) );
		$this->assertSame( array( 'event' ), get_taxonomy( 'event_type' )->object_type );
		$this->assertSame( 'Add new Event type', get_taxonomy( 'event_type' )->labels->add_new_item );
	}

	/**
	 * Fields and relations are post meta with type and schema.
	 */
	public function test_meta(): void {
		$meta = get_registered_meta_keys( 'post', 'event' );
		$this->assertSame( 'string', $meta['event_start']['type'] );
		$this->assertSame( 'date-time', $meta['event_start']['show_in_rest']['schema']['format'] );
		$this->assertSame( 120, $meta['event_venue']['show_in_rest']['schema']['maxLength'] );
		$this->assertSame( 'number', $meta['event_price']['type'] );
		$this->assertSame( 0, $meta['event_price']['show_in_rest']['schema']['minimum'] );
		$this->assertSame( array( 'beginner', 'advanced' ), $meta['event_level']['show_in_rest']['schema']['enum'] );
		$this->assertSame( 'integer', $meta['event_poster']['type'] );
		$this->assertSame( 'array', $meta['event_speakers']['type'] );
		$this->assertTrue( $meta['event_speakers']['single'] );
		$this->assertSame( 'integer', $meta['event_speakers']['show_in_rest']['schema']['items']['type'] );
		$this->assertArrayNotHasKey( 'maxItems', $meta['event_speakers']['show_in_rest']['schema'] );

		$session = get_registered_meta_keys( 'post', 'event_session' );
		$this->assertSame( '^\d{2}:\d{2}$', $session['session_time']['show_in_rest']['schema']['pattern'] );
		$this->assertArrayHasKey( 'session_speaker', $session );
		$this->assertArrayHasKey( 'person_role', get_registered_meta_keys( 'post', 'person' ) );
	}

	/**
	 * Meta is in /wp/v2/event and invalid values are refused with 400.
	 */
	public function test_rest_meta_validation(): void {
		$id      = self::factory()->post->create( array( 'post_type' => 'event' ) );
		$person  = self::factory()->post->create( array( 'post_type' => 'person' ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/event/' . $id );
		$request->set_body_params(
			array(
				'meta' => array(
					'event_start'    => '2026-11-02T18:00:00',
					'event_price'    => 12.5,
					'event_level'    => 'beginner',
					'event_speakers' => array( $person ),
				),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '2026-11-02T18:00:00', $response->get_data()['meta']['event_start'] );
		$this->assertSame( array( $person ), get_post_meta( $id, 'event_speakers', true ) );

		foreach (
			array(
				array( 'event_price', -1 ),
				array( 'event_level', 'expert' ),
				array( 'event_speakers', array( 'abc' ) ),
			) as list( $key, $value )
		) {
			$request = new WP_REST_Request( 'POST', '/wp/v2/event/' . $id );
			$request->set_body_params( array( 'meta' => array( $key => $value ) ) );
			$this->assertSame( 400, rest_do_request( $request )->get_status(), $key );
		}
	}

	/**
	 * Relations keep only readable posts of the target type, image fields only image attachments.
	 */
	public function test_relation_and_image_targets_are_checked_on_save(): void {
		$id        = $this->event();
		$person    = self::factory()->post->create( array( 'post_type' => 'person' ) );
		$editor    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other     = self::factory()->user->create( array( 'role' => 'author' ) );
		$hidden    = self::factory()->post->create(
			array(
				'post_type'   => 'person',
				'post_status' => 'draft',
				'post_author' => $editor,
			)
		);
		$foreign   = self::factory()->post->create();
		$image     = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id );
		$not_image = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'application/pdf',
				'file'           => 'doc.pdf',
			)
		);
		$save      = static function ( array $meta ) use ( $id ): WP_REST_Response {
			$request = new WP_REST_Request( 'POST', '/wp/v2/event/' . $id );
			$request->set_body_params( array( 'meta' => $meta ) );
			return rest_do_request( $request );
		};

		foreach ( array( $foreign, 999999 ) as $wrong ) {
			$this->assertSame( 400, $save( array( 'event_speakers' => array( $person, $wrong ) ) )->get_status(), (string) $wrong );
		}
		$this->assertSame( '', get_post_meta( $id, 'event_speakers', true ), 'A refused request stores nothing.' );
		$this->assertSame( 200, $save( array( 'event_speakers' => array( $person, $hidden ) ) )->get_status() );
		$this->assertSame( array( $person, $hidden ), get_post_meta( $id, 'event_speakers', true ), 'Administrators read drafts.' );

		// An author edits an own event: links stored before stay although the author cannot read the draft person,
		// adding another unreadable draft is refused.
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $other,
			)
		);
		$other_draft = self::factory()->post->create(
			array(
				'post_type'   => 'person',
				'post_status' => 'draft',
				'post_author' => $editor,
			)
		);
		wp_set_current_user( $other );
		$this->assertSame( 200, $save( array( 'event_speakers' => array( $hidden, $person ) ) )->get_status() );
		$this->assertSame( array( $hidden, $person ), get_post_meta( $id, 'event_speakers', true ) );
		$this->assertSame( 400, $save( array( 'event_speakers' => array( $hidden, $person, $other_draft ) ) )->get_status() );

		// Imports without a user keep links to drafts.
		wp_set_current_user( 0 );
		update_post_meta( $id, 'event_speakers', array( $other_draft ) );
		$this->assertSame( array( $other_draft ), get_post_meta( $id, 'event_speakers', true ) );

		wp_set_current_user( self::$admin );
		$this->assertSame( 200, $save( array( 'event_poster' => $image ) )->get_status() );
		$this->assertSame( $image, (int) get_post_meta( $id, 'event_poster', true ) );
		$save( array( 'event_poster' => $not_image ) );
		$this->assertSame( 0, (int) get_post_meta( $id, 'event_poster', true ) );
		$save( array( 'event_poster' => $person ) );
		$this->assertSame( 0, (int) get_post_meta( $id, 'event_poster', true ) );
	}

	/**
	 * The block editor sends every registered meta value back; null for an unset select or date is skipped instead of
	 * failing on the empty stored value, null for a stored value still deletes it.
	 */
	public function test_editor_round_trip_of_unset_meta(): void {
		$id      = $this->event();
		$request = new WP_REST_Request( 'GET', '/wp/v2/event/' . $id );
		$request->set_param( 'context', 'edit' );
		$meta = rest_do_request( $request )->get_data()['meta'];
		$this->assertNull( $meta['event_level'] );

		$meta['event_venue'] = 'Hall';
		$request             = new WP_REST_Request( 'POST', '/wp/v2/event/' . $id );
		$request->set_body_params( array( 'meta' => $meta ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'Hall', get_post_meta( $id, 'event_venue', true ) );

		update_post_meta( $id, 'event_level', 'advanced' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/event/' . $id );
		$request->set_body_params( array( 'meta' => array( 'event_level' => null ) ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertFalse( metadata_exists( 'post', $id, 'event_level' ) );

		// An option removed from the declaration: core sends null back for the stored value, saving goes on, the value stays.
		update_post_meta( $id, 'event_level', 'expert' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/event/' . $id );
		$request->set_body_params(
			array(
				'meta' => array(
					'event_level' => null,
					'event_venue' => 'Hall 3',
				),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'Hall 3', get_post_meta( $id, 'event_venue', true ) );
		$this->assertSame( 'expert', get_post_meta( $id, 'event_level', true ) );
	}

	/**
	 * Required fields are checked on the server when the entry is published, not for drafts.
	 */
	public function test_required_fields_on_publish(): void {
		$send  = static function ( string $method, string $path, array $body ): WP_REST_Response {
			$request = new WP_REST_Request( $method, $path );
			$request->set_body_params( $body );
			return rest_do_request( $request );
		};
		$draft = $send( 'POST', '/wp/v2/event', array( 'title' => 'Draft without start' ) );
		$this->assertSame( 201, $draft->get_status() );
		$id = $draft->get_data()['id'];

		$response = $send( 'POST', "/wp/v2/event/$id", array( 'status' => 'publish' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'Start is required before publishing.', $response->get_data()['message'] );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame(
			200,
			$send(
				'POST',
				"/wp/v2/event/$id",
				array(
					'status' => 'publish',
					'meta'   => array( 'event_start' => '2026-11-02T18:00:00' ),
				)
			)->get_status()
		);
		$this->assertSame( 400, $send( 'POST', "/wp/v2/event/$id", array( 'meta' => array( 'event_start' => null ) ) )->get_status(), 'A published entry keeps its required values.' );
	}

	/**
	 * Repeater rows follow their entry: contributors and authors change rows only on entries they may edit, rows only
	 * hang on entries of the declaring type, and capabilities of a row map to the entry.
	 */
	public function test_repeater_rows_follow_entry_permissions(): void {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );
		$foreign     = $this->event();
		$own_draft   = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'draft',
				'post_author' => $contributor,
			)
		);
		$person      = self::factory()->post->create( array( 'post_type' => 'person' ) );
		$row         = self::factory()->post->create(
			array(
				'post_type'   => 'event_session',
				'post_parent' => $foreign,
			)
		);
		$create      = static function ( array $body ): WP_REST_Response {
			$request = new WP_REST_Request( 'POST', '/wp/v2/event_session' );
			$request->set_body_params( $body + array( 'title' => 'Row' ) );
			return rest_do_request( $request );
		};

		foreach ( array( $contributor, $author ) as $user ) {
			wp_set_current_user( $user );
			$this->assertSame( 403, $create( array( 'parent' => $foreign ) )->get_status(), 'Foreign entry' );
			$this->assertSame( 400, $create( array( 'parent' => $person ) )->get_status(), 'Wrong parent type' );
			$this->assertSame( 400, $create( array() )->get_status(), 'No parent' );
			$this->assertFalse( current_user_can( 'edit_post', $row ) );
			$this->assertFalse( current_user_can( 'delete_post', $row ) );
			$update = new WP_REST_Request( 'POST', "/wp/v2/event_session/$row" );
			$update->set_body_params( array( 'title' => 'Taken' ) );
			$this->assertSame( 403, rest_do_request( $update )->get_status() );
		}

		wp_set_current_user( $contributor );
		$response = $create( array( 'parent' => $own_draft ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$own_row = $response->get_data()['id'];
		$this->assertTrue( current_user_can( 'edit_post', $own_row ) );
		$this->assertTrue( current_user_can( 'delete_post', $own_row ) );
		$move = new WP_REST_Request( 'POST', "/wp/v2/event_session/$own_row" );
		$move->set_body_params( array( 'parent' => $foreign ) );
		$this->assertSame( 403, rest_do_request( $move )->get_status(), 'Rows do not move to foreign entries.' );

		wp_set_current_user( self::$admin );
		$this->assertTrue( current_user_can( 'edit_post', $row ) );
		$misplaced = self::factory()->post->create(
			array(
				'post_type'   => 'event_session',
				'post_parent' => $person,
			)
		);
		$this->assertFalse( current_user_can( 'edit_post', $misplaced ), 'A row on a wrong parent allows nothing.' );
	}

	/**
	 * Rows of a password protected entry stay hidden over REST until the password is given, except for editors of the
	 * entry; trashed rows are readable only for editors of the entry.
	 */
	public function test_rest_rows_of_protected_or_trashed_entries(): void {
		$author    = self::factory()->user->create( array( 'role' => 'author' ) );
		$protected = self::factory()->post->create(
			array(
				'post_type'     => 'event',
				'post_password' => 'secret',
				'meta_input'    => array( 'event_start' => '2026-11-02T18:00:00' ),
			)
		);
		$hidden    = self::factory()->post->create(
			array(
				'post_type'   => 'event_session',
				'post_parent' => $protected,
			)
		);
		$open      = $this->event();
		$visible   = self::factory()->post->create(
			array(
				'post_type'   => 'event_session',
				'post_parent' => $open,
			)
		);
		$request   = static function ( string $path, array $params = array() ): WP_REST_Response {
			$request = new WP_REST_Request( 'GET', $path );
			$request->set_query_params( $params );
			return rest_do_request( $request );
		};

		wp_set_current_user( 0 );
		$this->assertSame( 401, $request( "/wp/v2/event_session/$hidden" )->get_status() );
		$this->assertSame( 200, $request( "/wp/v2/event_session/$visible" )->get_status() );

		wp_set_current_user( $author );
		$this->assertSame( 403, $request( "/wp/v2/event_session/$hidden" )->get_status() );
		$ids = wp_list_pluck( $request( '/wp/v2/event_session', array( 'per_page' => 100 ) )->get_data(), 'id' );
		$this->assertContains( $visible, $ids );
		$this->assertNotContains( $hidden, $ids );

		wp_set_current_user( self::$admin );
		$this->assertSame( 200, $request( "/wp/v2/event_session/$hidden" )->get_status() );

		// Trashed rows: only editors of the entry read them.
		wp_trash_post( $visible );
		$this->assertTrue( user_can( self::$admin, 'read_post', $visible ) );
		$this->assertFalse( user_can( $author, 'read_post', $visible ) );
		wp_set_current_user( $author );
		$this->assertNotSame( 200, $request( "/wp/v2/event_session/$visible" )->get_status() );
	}

	/**
	 * Required fields of repeater rows are checked on every save over REST.
	 */
	public function test_required_repeater_fields(): void {
		$decl = get_option( Model::OPTION );
		$decl['postTypes']['event']['repeaters']['event_agenda']['fields']['session_time']['required'] = true;
		update_option( Model::OPTION, $decl );
		Model::forget();
		Registrar::register();
		$event    = $this->event();
		$send     = static function ( array $body ): WP_REST_Response {
			$request = new WP_REST_Request( 'POST', '/wp/v2/event_session' );
			$request->set_body_params( $body );
			return rest_do_request( $request );
		};
		$response = $send(
			array(
				'title'  => 'No time',
				'parent' => $event,
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'Time is required.', $response->get_data()['message'] );

		// An older row without the now required time can still be moved.
		$old  = self::factory()->post->create(
			array(
				'post_type'   => 'event_session',
				'post_parent' => $event,
			)
		);
		$move = new WP_REST_Request( 'POST', "/wp/v2/event_session/$old" );
		$move->set_body_params( array( 'menu_order' => 3 ) );
		$this->assertSame( 200, rest_do_request( $move )->get_status() );
		$this->assertSame( 3, get_post( $old )->menu_order );
		$edit = new WP_REST_Request( 'POST', "/wp/v2/event_session/$old" );
		$edit->set_body_params( array( 'meta' => array( 'session_speaker' => 'Someone' ) ) );
		$this->assertSame( 400, rest_do_request( $edit )->get_status(), 'Changing meta needs the required time.' );
		$this->assertSame(
			201,
			$send(
				array(
					'title'  => 'With time',
					'parent' => $event,
					'meta'   => array( 'session_time' => '09:30' ),
				)
			)->get_status()
		);
	}

	/**
	 * Published event with the required start.
	 */
	private function event(): int {
		return self::factory()->post->create(
			array(
				'post_type'  => 'event',
				'meta_input' => array( 'event_start' => '2026-11-02T18:00:00' ),
			)
		);
	}

	/**
	 * Repeater rows are created and listed with their entry as parent over REST, ordered by menu order.
	 */
	public function test_rest_rows_with_parent(): void {
		$event = self::factory()->post->create( array( 'post_type' => 'event' ) );
		foreach ( array(
			'Second' => 1,
			'First'  => 0,
		) as $title => $order ) {
			$request = new WP_REST_Request( 'POST', '/wp/v2/event_session' );
			$request->set_body_params(
				array(
					'title'      => $title,
					'parent'     => $event,
					'menu_order' => $order,
					'meta'       => array( 'session_time' => '09:30' ),
				)
			);
			$response = rest_do_request( $request );
			$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$this->assertSame( 'inherit', $response->get_data()['status'] );
		}
		$request = new WP_REST_Request( 'GET', '/wp/v2/event_session' );
		$request->set_query_params(
			array(
				'context' => 'edit',
				'parent'  => $event,
				'status'  => 'inherit',
				'orderby' => 'menu_order',
				'order'   => 'asc',
			)
		);
		$this->assertSame( array( 'First', 'Second' ), wp_list_pluck( wp_list_pluck( rest_do_request( $request )->get_data(), 'title' ), 'raw' ) );
	}

	/**
	 * Site settings are readable and writable for administrators under /wp/v2/settings; email values are checked.
	 */
	public function test_settings(): void {
		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( 'leitvia_site_company_phone' => '+49 89 1234' ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( '+49 89 1234', get_option( 'leitvia_site_company_phone' ) );
		$this->assertSame( '+49 89 1234', rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/settings' ) )->get_data()['leitvia_site_company_phone'] );

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( 'leitvia_site_company_email' => 'no-at-sign' ) );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
		$this->assertFalse( get_option( 'leitvia_site_company_email' ) );
	}

	/**
	 * Sets show_in_abilities only where the declaration sets showInAbilities; repeaters never.
	 */
	public function test_abilities_flag(): void {
		$this->assertTrue( get_post_type_object( 'event' )->show_in_abilities );
		$this->assertFalse( get_post_type_object( 'person' )->show_in_abilities );
		$this->assertFalse( get_post_type_object( 'event_session' )->show_in_abilities );
		$this->assertFalse( get_taxonomy( 'event_type' )->show_in_abilities );
		$settings = get_registered_settings();
		$this->assertTrue( $settings['leitvia_site_company_email']['show_in_abilities'] );
		$this->assertFalse( $settings['leitvia_site_company_phone']['show_in_abilities'] );
	}

	/**
	 * A changed declaration asks for one rewrite flush on the next init.
	 */
	public function test_rewrite_flush_after_change(): void {
		// Core adds the rewrite rules of a post type only when pretty permalinks are on at registration.
		$this->set_permalink_structure( '/%postname%/' );
		Registrar::register();
		delete_option( Model::FLUSH_OPTION );
		$decl                                      = get_option( Model::OPTION );
		$decl['postTypes']['person']['hasArchive'] = true;
		$this->assertTrue( Model::save( $decl ) );
		$this->assertEquals( 1, get_option( Model::FLUSH_OPTION ) );

		$flushed = 0;
		add_action(
			'generate_rewrite_rules',
			static function () use ( &$flushed ): void {
				++$flushed;
			}
		);
		Registrar::maybe_flush();
		$this->assertFalse( get_option( Model::FLUSH_OPTION ) );
		$this->assertSame( 1, $flushed );
		$this->assertArrayHasKey( 'events/?$', get_option( 'rewrite_rules' ) );
	}

	/**
	 * Deactivation unregisters the own types and keeps the content.
	 */
	public function test_deactivation_keeps_content(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'event' ) );
		update_post_meta( $id, 'event_venue', 'Hall 1' );
		Registrar::deactivate();
		$this->assertFalse( post_type_exists( 'event' ) );
		$this->assertFalse( post_type_exists( 'event_session' ) );
		$this->assertFalse( taxonomy_exists( 'event_type' ) );
		$this->assertSame( 'event', get_post( $id )->post_type );
		$this->assertSame( 'Hall 1', get_post_meta( $id, 'event_venue', true ) );
		$this->assertNotFalse( get_option( Model::OPTION ) );
	}

	/**
	 * Types without the public flag are read over REST by their editors only; repeater rows follow their entry.
	 */
	public function test_rest_reading_of_types_that_are_not_public(): void {
		$decl                                  = get_option( Model::OPTION );
		$decl['postTypes']['person']['public'] = false;
		update_option( Model::OPTION, $decl );
		Model::forget();
		Registrar::register();
		$GLOBALS['wp_rest_server'] = null;
		$this->assertFalse( get_post_type_object( 'person' )->rewrite );
		$this->assertFalse( get_taxonomy( 'event_type' )->rewrite );

		$person = self::factory()->post->create( array( 'post_type' => 'person' ) );
		$event  = self::factory()->post->create( array( 'post_type' => 'event' ) );
		$draft  = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'draft',
			)
		);
		$row    = self::factory()->post->create(
			array(
				'post_type'   => 'event_session',
				'post_parent' => $event,
			)
		);
		$hidden = self::factory()->post->create(
			array(
				'post_type'   => 'event_session',
				'post_parent' => $draft,
			)
		);
		$orphan = self::factory()->post->create( array( 'post_type' => 'event_session' ) );
		$term   = self::factory()->term->create( array( 'taxonomy' => 'event_type' ) );
		$this->assertSame( 'inherit', get_post_status( $row ) );
		$this->assertSame( 'private', get_post_status( $orphan ) );

		wp_set_current_user( 0 );
		$get = static fn( string $path ): WP_REST_Response => rest_do_request( new WP_REST_Request( 'GET', $path ) );
		$this->assertSame( 401, $get( '/wp/v2/person' )->get_status() );
		$this->assertSame( 401, $get( "/wp/v2/person/$person" )->get_status() );
		$this->assertSame( 401, $get( '/wp/v2/event_type' )->get_status() );
		$this->assertSame( 401, $get( "/wp/v2/event_type/$term" )->get_status() );
		$this->assertSame( 200, $get( "/wp/v2/event/$event" )->get_status() );
		$this->assertSame( 200, $get( "/wp/v2/event_session/$row" )->get_status() );
		$this->assertSame( 401, $get( "/wp/v2/event_session/$hidden" )->get_status() );
		$this->assertSame( 401, $get( "/wp/v2/event_session/$orphan" )->get_status() );
		// Rows are listed for editors only; visitors read single rows of entries they may read.
		$this->assertSame( 401, $get( '/wp/v2/event_session' )->get_status() );

		wp_set_current_user( self::$admin );
		$this->assertSame( 200, $get( "/wp/v2/person/$person" )->get_status() );
		$this->assertSame( 200, $get( "/wp/v2/event_type/$term" )->get_status() );
		$this->assertSame( 200, $get( "/wp/v2/event_session/$hidden" )->get_status() );
	}

	/**
	 * Labels are escaped for core, which prints them as HTML.
	 */
	public function test_labels_are_escaped(): void {
		$decl                                = get_option( Model::OPTION );
		$decl['postTypes']['event']['label'] = 'Events & Talks';
		update_option( Model::OPTION, $decl );
		Model::forget();
		Registrar::register();
		$this->assertSame( 'Events &amp; Talks', get_post_type_object( 'event' )->labels->name );
		$this->assertSame( 'All Events &amp; Talks', get_post_type_object( 'event' )->labels->all_items );
	}

	/**
	 * Network wide deactivation empties the rewrite rules of every site.
	 *
	 * @group ms-required
	 */
	public function test_network_deactivation_resets_rewrite_rules_per_site(): void {
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		update_option( 'rewrite_rules', array( 'x' => 'y' ) );
		restore_current_blog();
		Registrar::deactivate( true );
		switch_to_blog( $site );
		$this->assertFalse( get_option( 'rewrite_rules' ) );
		restore_current_blog();
	}
}
