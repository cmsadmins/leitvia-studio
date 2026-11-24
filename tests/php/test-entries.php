<?php
/**
 * Upkeep of content model entries: rows saved with the entry, copies, list columns.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Model\Entries;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * Rows travel with their entry over REST and are checked first; a copy moves its dates and takes its rows; a type
 * without editor support opens in the block editor with title and fields only.
 */
class Test_Entries extends WP_UnitTestCase {

	/**
	 * A weekly menu with a unique weekday per row, as an editor.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			Model::OPTION,
			array(
				'version'   => 1,
				'postTypes' => array(
					'lunch' => array(
						'label'         => 'Lunch menus',
						'singularLabel' => 'Lunch menu',
						'public'        => true,
						'singlePages'   => false,
						'supports'      => array( 'title', 'revisions' ),
						'copyShiftDays' => 7,
						'fields'        => array(
							'valid_from' => array(
								'type'  => 'date',
								'label' => 'Served from',
							),
							'price'      => array(
								'type'  => 'number',
								'label' => 'Price',
							),
						),
						'repeaters'     => array(
							'days' => array(
								'postType'      => 'lunch_day',
								'label'         => 'Days',
								'singularLabel' => 'Day',
								'titleLabel'    => 'Dish',
								'fields'        => array(
									'weekday'   => array(
										'type'     => 'select',
										'label'    => 'Weekday',
										'required' => true,
										'unique'   => true,
										'options'  => array(
											'mon' => 'Monday',
											'tue' => 'Tuesday',
										),
									),
									'allergens' => array(
										'type'  => 'text',
										'label' => 'Allergens',
									),
								),
							),
						),
					),
				),
			)
		);
		Model::forget();
		$this->assertTrue( Model::validate( Model::current() ), 'the declaration with unique, titleLabel and copyShiftDays is valid' );
		Registrar::register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Drops the cached model.
	 */
	public function tear_down(): void {
		Model::forget();
		parent::tear_down();
	}

	/**
	 * Saves an entry over REST with rows.
	 *
	 * @param int                              $id   Entry ID, 0 for a new one.
	 * @param array<int, array<string, mixed>> $days Rows.
	 */
	private static function save( int $id, array $days ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp/v2/lunch' . ( $id ? "/$id" : '' ) );
		$request->set_body_params(
			array(
				'title'        => 'Week 48',
				'status'       => 'publish',
				'meta'         => array( 'valid_from' => '2026-11-23' ),
				Entries::FIELD => array( 'days' => $days ),
			)
		);
		return rest_do_request( $request );
	}

	/**
	 * Rows of an entry as title and weekday, in order.
	 *
	 * @param int $id Entry ID.
	 * @return array<int, array<int, string>>
	 */
	private static function rows( int $id ): array {
		$request = new WP_REST_Request( 'GET', "/wp/v2/lunch/$id" );
		$request->set_param( 'context', 'edit' );
		$rows = (array) rest_do_request( $request )->get_data()[ Entries::FIELD ]->days;
		return array_map( static fn( array $row ): array => array( $row['title'], (string) $row['meta']->weekday ), $rows );
	}

	/**
	 * A new entry is saved with its rows; a second save changes, reorders and drops rows; visitors never see the field.
	 */
	public function test_rows_are_saved_with_the_entry(): void {
		$response = self::save(
			0,
			array(
				array(
					'title' => 'Ham noodles',
					'meta'  => array(
						'weekday'   => 'mon',
						'allergens' => 'A, C',
					),
				),
				array(
					'title' => 'Pancakes',
					'meta'  => array( 'weekday' => 'tue' ),
				),
			)
		);
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$id = (int) $response->get_data()['id'];
		$this->assertSame(
			array(
				array( 'Ham noodles', 'mon' ),
				array( 'Pancakes', 'tue' ),
			),
			self::rows( $id )
		);
		$rows  = (array) $response->get_data()[ Entries::FIELD ]->days;
		$first = (int) $rows[0]['id'];
		$this->assertSame( 'A, C', get_post_meta( $first, 'allergens', true ) );
		$this->assertSame( 'inherit', get_post_status( $first ) );

		// Tuesday first, Monday renamed and kept by its ID; the row left out goes to the trash.
		$response = self::save(
			$id,
			array(
				array(
					'title' => 'Goulash',
					'meta'  => array( 'weekday' => 'tue' ),
				),
				array(
					'id'    => $first,
					'title' => 'Noodles',
					'meta'  => array( 'weekday' => 'mon' ),
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array( 'Goulash', 'tue' ),
				array( 'Noodles', 'mon' ),
			),
			self::rows( $id )
		);
		$this->assertSame( '', get_post_meta( $first, 'allergens', true ), 'an emptied field is deleted' );
		$this->assertSame( 'trash', get_post_status( (int) $rows[1]['id'] ) );

		wp_set_current_user( 0 );
		$this->assertArrayNotHasKey( Entries::FIELD, rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/lunch/$id" ) )->get_data() );
	}

	/**
	 * Rows without a title, without a required value or with a unique value twice keep the whole entry from saving.
	 */
	public function test_invalid_rows_save_nothing(): void {
		$cases = array(
			'Day 1 has no title.'           => array( array( 'meta' => array( 'weekday' => 'mon' ) ) ),
			'Day 1: Weekday is required.'   => array( array( 'title' => 'Soup' ) ),
			'Weekday Monday appears twice.' => array(
				array(
					'title' => 'Soup',
					'meta'  => array( 'weekday' => 'mon' ),
				),
				array(
					'title' => 'Stew',
					'meta'  => array( 'weekday' => 'mon' ),
				),
			),
		);
		foreach ( $cases as $message => $days ) {
			$response = self::save( 0, $days );
			$this->assertSame( 400, $response->get_status(), $message );
			$this->assertSame( $message, $response->get_data()['message'] );
		}
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => array( 'lunch', 'lunch_day' ),
					'post_status' => 'any',
				)
			)
		);
	}

	/**
	 * A copy is a draft a week later with the same fields and its own rows; the original keeps its rows.
	 */
	public function test_copy_moves_the_dates_and_takes_the_rows(): void {
		$id = (int) self::save(
			0,
			array(
				array(
					'title' => 'Ham noodles',
					'meta'  => array( 'weekday' => 'mon' ),
				),
			)
		)->get_data()['id'];
		update_post_meta( $id, 'price', 8.5 );
		$copy = Entries::duplicate( $id );
		$this->assertIsInt( $copy );
		$this->assertSame( 'draft', get_post_status( $copy ) );
		$this->assertSame( 'Week 48 (copy)', get_the_title( $copy ) );
		$this->assertSame( '2026-11-30', get_post_meta( $copy, 'valid_from', true ) );
		$this->assertEquals( 8.5, get_post_meta( $copy, 'price', true ) );
		$this->assertSame( array( array( 'Ham noodles', 'mon' ) ), self::rows( $copy ) );
		$this->assertSame( array( array( 'Ham noodles', 'mon' ) ), self::rows( $id ) );
		$this->assertNotSame( self::rows( $copy ), array(), 'rows of the copy are new posts' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( Entries::duplicate( $id ) );
	}

	/**
	 * A type without editor support uses the block editor with no blocks to add; the list offers Duplicate.
	 */
	public function test_type_without_text_opens_with_fields_only(): void {
		$this->assertTrue( use_block_editor_for_post_type( 'lunch' ) );
		$this->assertSame( 'all', get_post_type_object( 'lunch' )->template_lock );
		$this->assertSame( array(), get_post_type_object( 'lunch' )->template );
		$post = get_post( self::factory()->post->create( array( 'post_type' => 'lunch' ) ) );
		$this->assertArrayHasKey( Entries::COPY, Entries::row_action( array(), $post ) );
	}
}
