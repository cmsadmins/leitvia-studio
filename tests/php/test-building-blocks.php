<?php
/**
 * Building blocks: registry, schema, REST list and the ability add-building-block.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Extension\Studio;
use LeitviaStudio\Gateway\Building_Blocks;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * The registry building_blocks checks its entries against the schemas, the REST route lists them with their state.
 *
 * @group multisite
 */
class Test_Building_Blocks extends WP_UnitTestCase {

	/**
	 * Leitvia theme, an empty content model, the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_clean_theme_json_cache();
		delete_option( Model::OPTION );
		Model::forget();
		self::boot();
	}

	/**
	 * Removes the abilities, the test types and the block.
	 */
	public function tear_down(): void {
		self::unregister();
		remove_all_actions( 'leitvia_studio_init', 20 );
		foreach ( array( 'lv_note', 'lv_memo' ) as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
		if ( taxonomy_exists( 'lv_note_topic' ) ) {
			unregister_taxonomy( 'lv_note_topic' );
		}
		if ( isset( get_registered_settings()[ Registrar::SETTING_PREFIX . 'notes_owner' ] ) ) {
			unregister_setting( Registrar::SETTINGS_GROUP, Registrar::SETTING_PREFIX . 'notes_owner' );
		}
		Model::forget();
		wp_clean_theme_json_cache();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Runs leitvia_studio_init again and hands the abilities to core.
	 */
	private static function boot(): void {
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
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
	 * The block Notes: a post type with a field, a taxonomy, a relation, a setting with its default and two samples.
	 *
	 * @return array<string, mixed>
	 */
	public static function block(): array {
		return array(
			'title'       => 'Notes',
			'description' => 'Short notes with topics.',
			'icon'        => 'dashicons-edit',
			'model'       => array(
				'postTypes'  => array(
					'lv_note' => array(
						'label'         => 'Notes',
						'singularLabel' => 'Note',
						'supports'      => array( 'title', 'editor', 'excerpt' ),
						'fields'        => array(
							'note_mood' => array(
								'type'  => 'text',
								'label' => 'Mood',
							),
						),
					),
				),
				'taxonomies' => array(
					'lv_note_topic' => array(
						'label'         => 'Topics',
						'singularLabel' => 'Topic',
						'hierarchical'  => true,
						'postTypes'     => array( 'lv_note' ),
					),
				),
				'relations'  => array(
					'note_related' => array(
						'label'    => 'Related notes',
						'from'     => 'lv_note',
						'to'       => 'lv_note',
						'multiple' => true,
					),
				),
				'settings'   => array(
					'notes_owner' => array(
						'type'  => 'text',
						'label' => 'Owner of the notes',
					),
				),
			),
			'patterns'    => array( 'leitvia/content-faq' ),
			'settings'    => array( 'notes_owner' => 'Ada' ),
			'samples'     => dirname( __DIR__ ) . '/fixtures/building-blocks/notes.json',
		);
	}

	/**
	 * Registers blocks on leitvia_studio_init and boots again.
	 *
	 * @param array<string, array<string, mixed>> $blocks Entries by name, owner leitvia-studio.
	 */
	public static function register_blocks( array $blocks ): void {
		add_action(
			'leitvia_studio_init',
			static function ( Studio $studio ) use ( $blocks ): void {
				foreach ( $blocks as $name => $block ) {
					$studio->registry( 'building_blocks' )->register( $name, $block, 'leitvia-studio' );
				}
			},
			20
		);
		self::boot();
	}

	/**
	 * A valid block registers; one without model, with a model the content model schema refuses or with samples that
	 * are neither callable nor a path is refused.
	 */
	public function test_registry_checks_the_schemas(): void {
		$this->setExpectedIncorrectUsage( 'LeitviaStudio\Extension\Registry::register' );
		$broken_model                                  = self::block();
		$broken_model['model']['postTypes']['lv_note'] = array( 'label' => 'Notes' );
		$broken_icon                                   = array( 'icon' => 'star' ) + self::block();
		$broken_samples                                = array( 'samples' => 42 ) + self::block();
		$without_model                                 = self::block();
		unset( $without_model['model'] );
		self::register_blocks(
			array(
				'leitvia/notes'         => self::block(),
				'leitvia/memos'         => array( 'samples' => static fn(): array => array() ) + self::block(),
				'leitvia/broken-model'  => $broken_model,
				'leitvia/broken-icon'   => $broken_icon,
				'leitvia/broken-sample' => $broken_samples,
				'leitvia/no-model'      => $without_model,
			)
		);
		$registry = leitvia_studio()->registry( 'building_blocks' );
		$this->assertNotNull( $registry->get( 'leitvia/notes' ) );
		$this->assertNotNull( $registry->get( 'leitvia/memos' ) );
		foreach ( array( 'leitvia/broken-model', 'leitvia/broken-icon', 'leitvia/broken-sample', 'leitvia/no-model' ) as $name ) {
			$this->assertNull( $registry->get( $name ), $name );
		}
	}

	/**
	 * A block is installed once every key of its model is in the content model; samples come from the path.
	 */
	public function test_installed_and_samples(): void {
		$block = self::block();
		$this->assertFalse( Building_Blocks::installed( $block ) );
		update_option( Model::OPTION, array( 'version' => 1 ) + $block['model'] );
		Model::forget();
		$this->assertTrue( Building_Blocks::installed( $block ) );
		$samples = Building_Blocks::samples( $block );
		$this->assertSame( array( 'lv_note' ), array_column( $samples, 'postType' ) );
		$this->assertCount( 2, $samples[0]['entries'] );
		$this->assertSame( array(), Building_Blocks::samples( array( 'samples' => '/no/such/file.json' ) + $block ) );
		$this->assertSame( array( 'lv_note' ), array_column( Building_Blocks::samples( array( 'samples' => static fn(): array => array( $samples[0] ) ) + $block ), 'postType' ) );
	}

	/**
	 * GET /leitvia-studio/v1/building-blocks: people who may edit the theme get every block with its state.
	 */
	public function test_rest_list(): void {
		self::register_blocks( array( 'leitvia/notes' => self::block() ) );
		$request = static fn(): WP_REST_Response => rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/building-blocks' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $request()->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = $request();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'name'        => 'leitvia/notes',
				'owner'       => 'leitvia-studio',
				'title'       => 'Notes',
				'description' => 'Short notes with topics.',
				'icon'        => 'dashicons-edit',
				'installed'   => false,
				'postTypes'   => array( 'lv_note' ),
				'taxonomies'  => array( 'lv_note_topic' ),
				'patterns'    => array( 'leitvia/content-faq' ),
				'sampleCount' => 2,
			),
			$response->get_data()[0]
		);
	}

	/**
	 * Adds a block through the ability.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	private function add( array $input = array( 'name' => 'leitvia/notes' ) ): array {
		$result = wp_get_ability( 'leitvia/add-building-block' )->execute( $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		foreach ( $result['steps'] as $step ) {
			$this->assertNotSame( 'rejected', $step['status'], wp_json_encode( $step ) );
		}
		return $result;
	}

	/**
	 * Statuses of the steps of one kind.
	 *
	 * @param array<string, mixed> $result Result.
	 * @param string               $step   Step name.
	 * @return list<string>
	 */
	private static function statuses( array $result, string $step ): array {
		return array_values( array_column( array_filter( $result['steps'], static fn( array $s ): bool => $step === $s['step'] ), 'status' ) );
	}

	/**
	 * Sample notes on the site, any status.
	 *
	 * @return list<WP_Post>
	 */
	private static function notes(): array {
		return get_posts(
			array(
				'post_type'   => 'lv_note',
				'post_status' => 'any',
				'numberposts' => -1,
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
			)
		);
	}

	/**
	 * Everything at "suggest", so every write waits in a package unless the run settles.
	 */
	private static function suggest(): void {
		update_option(
			Gateway::OPTION,
			array(
				'overrides' => array(
					'areas' => array_fill_keys( array( 'content', 'structure', 'design' ), 'suggest' ),
					'kinds' => array(),
				),
			) + Gateway::DEFAULTS
		);
	}

	/**
	 * A person in the standard profile who asks to settle adds the block in one go: the change package is applied for
	 * them, the types exist, the setting has its default, terms and relation are set and the samples are drafts. A
	 * second run adds nothing.
	 */
	public function test_person_adds_a_block_in_one_go(): void {
		self::register_blocks( array( 'leitvia/notes' => self::block() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = $this->add(
			array(
				'name'   => 'leitvia/notes',
				'settle' => true,
			)
		);
		$this->assertSame( 'leitvia/notes', $result['buildingBlock'] );
		$this->assertSame( 0, $result['package'], wp_json_encode( $result['steps'] ) );
		$this->assertNotContains( 'waiting', array_column( $result['steps'], 'status' ), wp_json_encode( $result['steps'] ) );
		$this->assertSame( array( 'live' ), self::statuses( $result, 'contentModel' ) );
		$this->assertSame( array( 'live' ), self::statuses( $result, 'blockSettings' ) );
		$this->assertTrue( post_type_exists( 'lv_note' ) );
		$this->assertTrue( Building_Blocks::installed( self::block() ) );
		$this->assertSame( 'Ada', get_option( Registrar::SETTING_PREFIX . 'notes_owner' ) );

		$notes = self::notes();
		$this->assertSame( array( 'first-note', 'second-note' ), wp_list_pluck( $notes, 'post_name' ) );
		$this->assertSame( array( 'draft', 'draft' ), wp_list_pluck( $notes, 'post_status' ) );
		$this->assertSame( 'calm', get_post_meta( $notes[0]->ID, 'note_mood', true ) );
		$this->assertSame( array( 'garden' ), wp_get_post_terms( $notes[0]->ID, 'lv_note_topic', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( array( $notes[0]->ID ), array_map( 'intval', (array) get_post_meta( $notes[1]->ID, 'note_related', true ) ) );
		$this->assertNotFalse( get_term_by( 'slug', 'kitchen', 'lv_note_topic' ) );

		$again = $this->add(
			array(
				'name'   => 'leitvia/notes',
				'settle' => true,
			)
		);
		foreach ( $again['steps'] as $step ) {
			$this->assertContains( $step['status'], array( 'unchanged', 'exists' ), wp_json_encode( $step ) );
		}
		$this->assertCount( 2, self::notes() );
	}

	/**
	 * Only a person in the admin who asks to settle gets the package applied: without settle, with an application
	 * password or over MCP it waits, as for an agent; with settle it is applied and listed under applied.
	 */
	public function test_settle_only_on_request_of_a_person_in_the_admin(): void {
		self::register_blocks( array( 'leitvia/notes' => self::block() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::suggest();
		$settle = array(
			'name'   => 'leitvia/notes',
			'settle' => true,
		);
		$waits  = function ( array $result ): void {
			$this->assertGreaterThan( 0, $result['package'], wp_json_encode( $result ) );
			$this->assertArrayNotHasKey( 'applied', $result );
			$this->assertSame( array( 'package' ), self::statuses( $result, 'contentModel' ) );
			$this->assertFalse( post_type_exists( 'lv_note' ) );
			Packages::reject( $result['package'] );
		};

		$waits( $this->add() );

		$GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4();
		try {
			$waits( $this->add( $settle ) );
		} finally {
			unset( $GLOBALS['wp_rest_application_password_uuid'] );
		}

		$route                                   = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
		$GLOBALS['wp']->query_vars['rest_route'] = '/leitvia-studio/v1/mcp';
		try {
			$waits( $this->add( $settle ) );
		} finally {
			$GLOBALS['wp']->query_vars['rest_route'] = $route;
		}

		$result = $this->add( $settle );
		$this->assertSame( 0, $result['package'], wp_json_encode( $result ) );
		$this->assertNotEmpty( $result['applied'] );
		$this->assertArrayNotHasKey( 'partial', $result );
		$this->assertTrue( post_type_exists( 'lv_note' ) );
		foreach ( $result['applied'] as $id ) {
			$this->assertSame( 'applied', Packages::state( $id ) );
		}
	}

	/**
	 * Content at "suggest": the sample a relation points to waits in the package, so a run that settles applies it
	 * before the entry that links to it; the relation is set and nothing waits.
	 */
	public function test_settled_run_resolves_relations_at_suggest(): void {
		self::register_blocks( array( 'leitvia/notes' => self::block() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::suggest();
		$result = $this->add(
			array(
				'name'   => 'leitvia/notes',
				'settle' => true,
			)
		);
		$this->assertNotContains( 'waiting', array_column( $result['steps'], 'status' ), wp_json_encode( $result['steps'] ) );
		$this->assertNotContains( 'package', array_column( $result['steps'], 'status' ), wp_json_encode( $result['steps'] ) );
		$notes = self::notes();
		$this->assertSame( array( 'first-note', 'second-note' ), wp_list_pluck( $notes, 'post_name' ) );
		$this->assertSame( array( $notes[0]->ID ), array_map( 'intval', (array) get_post_meta( $notes[1]->ID, 'note_related', true ) ) );
	}

	/**
	 * A package a settled run applies only in part is not reported as applied: partial names it with its errors, and
	 * the later writes of the run join it instead of a second package with the same key.
	 */
	public function test_partial_settle_is_reported_and_reused(): void {
		self::register_blocks( array( 'leitvia/notes' => self::block() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::suggest();
		$option = Registrar::SETTING_PREFIX . 'notes_owner';
		// The default setting is proposed, then changed by hand: applying it conflicts.
		$change = static function ( $op, array $result ) use ( $option ): void {
			if ( 'package' === $result['mode'] && $option === $op->option() ) {
				update_option( $option, 'Grace' );
			}
		};
		add_action( 'leitvia_studio_gateway_executed', $change, 10, 2 );
		$result = $this->add(
			array(
				'name'   => 'leitvia/notes',
				'settle' => true,
			)
		);
		remove_action( 'leitvia_studio_gateway_executed', $change, 10 );

		$this->assertCount( 1, $result['partial'], wp_json_encode( $result ) );
		$partial = $result['partial'][0];
		$this->assertNotContains( $partial['package'], $result['applied'] );
		$this->assertSame( 'partial', Packages::state( $partial['package'] ) );
		$this->assertSame( array( 'leitvia_stale_hash' ), array_values( array_unique( array_column( $partial['errors'], 'code' ) ) ) );
		$this->assertSame( $partial['package'], $result['package'] );
		$this->assertSame( 'Grace', get_option( $option ) );
		$this->assertSame( array( 'package' ), self::statuses( $result, 'blockSettings' ) );

		// The second note was written after the package was applied in part, into the same package.
		$posts = array_column( array_column( Packages::operations( $partial['package'] ), 'state' ), 'post_name' );
		$this->assertSame( array( 'first-note', 'second-note' ), array_values( array_intersect( $posts, array( 'first-note', 'second-note' ) ) ) );
		$keyed = get_posts(
			array(
				'post_type'   => Packages::POST_TYPE,
				'post_status' => 'private',
				'fields'      => 'ids',
				'numberposts' => -1,
				'meta_key'    => Packages::KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test.
				'meta_value'  => 'building-block-leitvia-notes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test.
			)
		);
		$this->assertCount( count( $result['applied'] ) + 1, $keyed, 'no second package beside the partial one' );
		$notes = self::notes();
		$this->assertCount( 2, $notes );
		$this->assertSame( array( $notes[0]->ID ), array_map( 'intval', (array) get_post_meta( $notes[1]->ID, 'note_related', true ) ) );
	}

	/**
	 * Adding the block again keeps a field a person added to its type and a setting a person changed; without samples
	 * no entry is written.
	 */
	public function test_adding_again_keeps_changes_of_a_person(): void {
		self::register_blocks( array( 'leitvia/notes' => self::block() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->add(
			array(
				'name'    => 'leitvia/notes',
				'samples' => false,
				'settle'  => true,
			)
		);
		$this->assertSame( array(), self::notes() );
		$stored = Model::stored();
		$stored['postTypes']['lv_note']['fields']['note_color'] = array(
			'type'  => 'text',
			'label' => 'Color',
		);
		$this->assertTrue( Model::save( $stored ) );
		update_option( Registrar::SETTING_PREFIX . 'notes_owner', 'Grace' );

		$this->add(
			array(
				'name'   => 'leitvia/notes',
				'settle' => true,
			)
		);
		$this->assertArrayHasKey( 'note_color', Model::stored()['postTypes']['lv_note']['fields'] );
		$this->assertArrayHasKey( 'note_mood', Model::stored()['postTypes']['lv_note']['fields'] );
		$this->assertSame( 'Grace', get_option( Registrar::SETTING_PREFIX . 'notes_owner' ) );
		$this->assertCount( 2, self::notes() );
	}

	/**
	 * Sample terms reach the site only in a run that settles, by a user who may manage them: for an agent and for an
	 * administrator without manage_categories the entries with missing terms wait and no term is added.
	 */
	public function test_sample_terms_only_by_people_who_manage_them(): void {
		self::register_blocks( array( 'leitvia/notes' => self::block() ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->add(
			array(
				'name'    => 'leitvia/notes',
				'samples' => false,
				'settle'  => true,
			)
		);
		$this->assertTrue( taxonomy_exists( 'lv_note_topic' ) );
		$no_terms = function ( array $result ): void {
			$this->assertSame( array( 'waiting', 'waiting' ), self::statuses( $result, 'blockEntries' ), wp_json_encode( $result['steps'] ) );
			$this->assertSame( array( 'terms_pending' ), array_values( array_unique( array_column( array_filter( $result['steps'], static fn( array $s ): bool => 'blockEntries' === $s['step'] ), 'code' ) ) ) );
			$this->assertFalse( get_term_by( 'slug', 'garden', 'lv_note_topic' ) );
			$this->assertSame( array(), self::notes() );
		};

		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$no_terms( $this->add() );

		wp_set_current_user( $admin );
		$deny = static fn( array $caps, string $cap ): array => 'manage_categories' === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		try {
			$no_terms(
				$this->add(
					array(
						'name'   => 'leitvia/notes',
						'settle' => true,
					)
				)
			);
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
	}

	/**
	 * An agent in the standard profile proposes the model in a change package; settings and samples wait for it, and
	 * nothing is written.
	 */
	public function test_agent_proposes_the_model(): void {
		self::register_blocks( array( 'leitvia/notes' => self::block() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$result = $this->add();
		$this->assertGreaterThan( 0, $result['package'] );
		$this->assertSame( array( 'package' ), self::statuses( $result, 'contentModel' ) );
		$this->assertSame( array( 'waiting' ), self::statuses( $result, 'blockSettings' ) );
		$this->assertSame( array( 'waiting' ), self::statuses( $result, 'blockEntries' ) );
		$this->assertSame( array( 'model_pending' ), array_values( array_unique( array_column( array_slice( $result['steps'], 1 ), 'code' ) ) ) );
		$this->assertSame( array(), Model::stored() );
		$this->assertFalse( post_type_exists( 'lv_note' ) );
		$this->assertSame( 'building-block-leitvia-notes', get_post_meta( $result['package'], Packages::KEY_META, true ) );

		$unknown = wp_get_ability( 'leitvia/add-building-block' )->execute( array( 'name' => 'leitvia/nothing' ) );
		$this->assertSame( 'leitvia_unknown_building_block', $unknown->get_error_code() );
	}

	/**
	 * The ability asks for edit_theme_options as the REST list and the editor button do: an editor may neither.
	 */
	public function test_editor_may_not_set_up(): void {
		self::register_blocks( array( 'leitvia/notes' => self::block() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( Building_Blocks::can_set_up() );
		$this->assertWPError( wp_get_ability( 'leitvia/add-building-block' )->execute( array( 'name' => 'leitvia/notes' ) ) );
		$this->assertFalse( post_type_exists( 'lv_note' ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/building-blocks' ) )->get_status() );
	}
}
