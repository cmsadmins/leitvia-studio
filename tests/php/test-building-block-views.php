<?php
/**
 * Building blocks in the editor, on the front end, on the list screen and in the inserter; recipe runs of a person.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Extension\Studio;
use LeitviaStudio\Gateway\Building_Blocks;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Model\Building_Block_Views;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * Pattern needs, the editor data, empty sections on the front end, the way back from the list screen, the inserter
 * category and the recipe run of a person that settles at once.
 *
 * @group multisite
 */
class Test_Building_Block_Views extends WP_UnitTestCase {

	/**
	 * Pattern of the block Notes.
	 */
	private const PATTERN = 'leitvia/notes-list';

	/**
	 * Leitvia theme, an empty content model, the pattern of the block, the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_clean_theme_json_cache();
		delete_option( Model::OPTION );
		Model::forget();
		register_block_pattern(
			self::PATTERN,
			array(
				'title'      => 'Notes list',
				'categories' => array( 'text' ),
				'content'    => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Notes</h2><!-- /wp:heading --></div><!-- /wp:group -->',
			)
		);
		self::boot();
	}

	/**
	 * Removes the abilities, the test types, the pattern and its category.
	 */
	public function tear_down(): void {
		self::unregister();
		remove_all_actions( 'leitvia_studio_init', 20 );
		foreach ( array( 'lv_note', 'lv_rnote' ) as $type ) {
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
		if ( WP_Block_Patterns_Registry::get_instance()->is_registered( self::PATTERN ) ) {
			unregister_block_pattern( self::PATTERN );
		}
		$category = Building_Block_Views::category( 'leitvia/notes' );
		if ( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $category ) ) {
			unregister_block_pattern_category( $category );
		}
		set_current_screen( 'front' );
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
	 * The block Notes: a post type, a site setting with its default, the pattern of this test and two samples.
	 *
	 * @return array<string, mixed>
	 */
	private static function block(): array {
		return array(
			'title'       => 'Notes',
			'description' => 'Short notes.',
			'model'       => array(
				'postTypes' => array(
					'lv_note' => array(
						'label'         => 'Notes',
						'singularLabel' => 'Note',
					),
				),
				'settings'  => array(
					'notes_owner' => array(
						'type'  => 'text',
						'label' => 'Owner of the notes',
					),
				),
			),
			'patterns'    => array( self::PATTERN ),
			'settings'    => array( 'notes_owner' => 'Ada' ),
			'samples'     => static fn(): array => array(
				'postType' => 'lv_note',
				'entries'  => array(
					array(
						'slug'  => 'first-note',
						'title' => 'First note',
					),
					array(
						'slug'  => 'second-note',
						'title' => 'Second note',
					),
				),
			),
		);
	}

	/**
	 * Registers the block Notes and, optionally, entries of the registry patterns, then boots again.
	 *
	 * @param array<string, array<string, mixed>> $patterns Registry patterns by name, owner leitvia-studio.
	 */
	private static function register( array $patterns = array() ): void {
		$block = self::block();
		add_action(
			'leitvia_studio_init',
			static function ( Studio $studio ) use ( $block, $patterns ): void {
				$studio->registry( 'building_blocks' )->register( 'leitvia/notes', $block, 'leitvia-studio' );
				foreach ( $patterns as $name => $entry ) {
					$studio->registry( 'patterns' )->register( $name, $entry, 'leitvia-studio' );
				}
			},
			20
		);
		self::boot();
	}

	/**
	 * A registry pattern entry with the composition fields and the given needs.
	 *
	 * @param mixed $needs Needs.
	 * @return array<string, mixed>
	 */
	private static function pattern_entry( $needs ): array {
		return array(
			'title'       => 'Needs test',
			'description' => 'A pattern of a test.',
			'area'        => 'content',
			'composition' => array( 'tone' => 'surface' ),
			'needs'       => $needs,
		);
	}

	/**
	 * Adds the block in one go, as a person.
	 */
	private function install(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = Building_Blocks::add( 'leitvia/notes', true, '', true );
		$this->assertIsArray( $result );
		Model::forget();
	}

	/**
	 * Needs come from the registry patterns or from the block that lists the pattern or declares a needed type; an
	 * entry with needs of the wrong shape is refused. missing() names a registered block that is not installed, types,
	 * taxonomies and settings.
	 */
	public function test_needs_and_missing(): void {
		$this->setExpectedIncorrectUsage( 'LeitviaStudio\Extension\Registry::register' );
		self::register(
			array(
				'leitvia/needs-type'    => self::pattern_entry( array( 'postTypes' => array( 'lv_note' ) ) ),
				'leitvia/needs-setting' => self::pattern_entry(
					array(
						'taxonomies' => array( 'lv_nothing' ),
						'settings'   => array( 'notes_owner' ),
					)
				),
				'leitvia/needs-broken'  => self::pattern_entry( array( 'blocks' => array( 'x' ) ) ),
			)
		);
		$this->assertNull( leitvia_studio()->registry( 'patterns' )->get( 'leitvia/needs-broken' ) );
		$this->assertSame( array( 'buildingBlock' => 'leitvia/notes' ), Building_Blocks::needs_of_pattern( self::PATTERN ) );
		$this->assertSame( 'leitvia/notes', Building_Blocks::needs_of_pattern( 'leitvia/needs-type' )['buildingBlock'] );
		$this->assertArrayNotHasKey( 'buildingBlock', Building_Blocks::needs_of_pattern( 'leitvia/needs-setting' ) );
		$this->assertSame( array(), Building_Blocks::needs_of_pattern( 'leitvia/content-faq' ) );
		$this->assertSame( 'leitvia/notes', Building_Blocks::of_post_type( 'lv_note' ) );
		$this->assertSame( '', Building_Blocks::of_post_type( 'page' ) );

		$this->assertSame(
			array(
				'buildingBlock' => 'leitvia/notes',
				'postTypes'     => array( 'lv_note' ),
			),
			Building_Blocks::missing( Building_Blocks::needs_of_pattern( 'leitvia/needs-type' ) )
		);
		$this->assertSame(
			array(
				'taxonomies' => array( 'lv_nothing' ),
				'settings'   => array( 'notes_owner' ),
			),
			Building_Blocks::missing( Building_Blocks::needs_of_pattern( 'leitvia/needs-setting' ) )
		);
		$this->assertSame( array(), Building_Blocks::missing( array( 'buildingBlock' => 'leitvia/unknown' ) ), 'a block that is not registered counts as met' );

		$this->install();
		$this->assertSame( array(), Building_Blocks::missing( Building_Blocks::needs_of_pattern( 'leitvia/needs-type' ) ) );
		$this->assertSame( array( 'taxonomies' => array( 'lv_nothing' ) ), Building_Blocks::missing( Building_Blocks::needs_of_pattern( 'leitvia/needs-setting' ) ), 'the setting has its default' );
	}

	/**
	 * The data window.leitviaStudioNeeds: nothing without blocks; with the block its pattern, state, post type and the right to
	 * set it up; the post editor prints it.
	 */
	public function test_editor_data(): void {
		$this->assertNull( Building_Block_Views::editor_data() );
		self::register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$data = Building_Block_Views::editor_data();
		$this->assertSame(
			array(
				self::PATTERN => array(
					'buildingBlock' => 'leitvia/notes',
					'title'         => 'Notes',
					'missing'       => true,
				),
			),
			(array) $data['patterns']
		);
		$this->assertSame(
			array(
				array(
					'name'      => 'leitvia/notes',
					'title'     => 'Notes',
					'installed' => false,
					'category'  => 'leitvia-block-leitvia-notes',
				),
			),
			$data['blocks']
		);
		$this->assertSame( array( 'lv_note' => 'leitvia/notes' ), (array) $data['postTypes'] );
		$this->assertFalse( $data['canSetup'] );

		$this->install();
		$data = Building_Block_Views::editor_data();
		$this->assertTrue( $data['canSetup'] );
		$this->assertFalse( ( (array) $data['patterns'] )[ self::PATTERN ]['missing'] );

		$this->assertFileExists( dirname( LEITVIA_STUDIO_FILE ) . '/build/editor.asset.php', 'Run the build first.' );
		set_current_screen( 'page' );
		do_action( 'enqueue_block_editor_assets' );
		$inline = implode( '', (array) wp_scripts()->get_data( 'leitvia-studio-editor', 'before' ) );
		wp_dequeue_script( 'leitvia-studio-editor' );
		wp_deregister_script( 'leitvia-studio-editor' );
		$this->assertStringContainsString( 'window.leitviaStudioNeeds = {"patterns":{"leitvia\/notes-list"', $inline );
	}

	/**
	 * On the front end a section whose block is missing renders nothing; in the admin it stays; once the block is
	 * installed it renders. Blocks without pattern name, and sections of a block that is not registered (add-on gone,
	 * kit locked), are untouched.
	 */
	public function test_front_end_hides_unmet_sections(): void {
		self::register( array( 'leitvia/needs-gone' => self::pattern_entry( array( 'buildingBlock' => 'leitvia/gone' ) ) ) );
		$section = '<!-- wp:group {"metadata":{"patternName":"' . self::PATTERN . '"}} --><div class="wp-block-group"><h2>Notes</h2></div><!-- /wp:group -->';
		$plain   = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
		$gone    = '<!-- wp:group {"metadata":{"patternName":"leitvia/needs-gone"}} --><div class="wp-block-group"><h2>Kept</h2></div><!-- /wp:group -->';
		$this->assertSame( '', trim( do_blocks( $section ) ) );
		$this->assertStringContainsString( 'Hello', do_blocks( $plain ) );
		$this->assertSame( 'leitvia/gone', Building_Blocks::needs_of_pattern( 'leitvia/needs-gone' )['buildingBlock'] );
		$this->assertStringContainsString( '<h2>Kept</h2>', do_blocks( $gone ) );
		set_current_screen( 'edit-page' );
		$this->assertStringContainsString( '<h2>Notes</h2>', do_blocks( $section ), 'admin keeps it' );
		set_current_screen( 'front' );
		$this->install();
		$this->assertStringContainsString( '<h2>Notes</h2>', do_blocks( $section ) );
	}

	/**
	 * The bar above the list of a type of an installed block shows the way to a page with nonce link and editor link;
	 * the bar below, other screens and people without edit_pages show nothing. New page creates a draft with the section and its pattern name.
	 */
	public function test_list_notice_and_new_page(): void {
		self::register();
		$notice = static function (): string {
			ob_start();
			Building_Block_Views::list_notice();
			return (string) ob_get_clean();
		};
		$this->install();
		set_current_screen( 'edit-lv_note' );
		$html = $notice();
		$this->assertStringContainsString( 'Show Notes on a page:', $html );
		$this->assertStringContainsString( 'action=' . Building_Block_Views::PAGE_ACTION, $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
		$this->assertStringContainsString( 'leitvia-inserter=leitvia-block-leitvia-notes', $html );
		ob_start();
		Building_Block_Views::list_notice( 'bottom' );
		$this->assertSame( '', ob_get_clean(), 'only the bar above the list' );
		set_current_screen( 'edit-page' );
		$this->assertSame( '', $notice() );
		set_current_screen( 'edit-lv_note' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( '', $notice() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$page = Building_Block_Views::create_page( 'leitvia/notes' );
		$this->assertIsInt( $page );
		$this->assertSame( 'draft', get_post_status( $page ) );
		$this->assertSame( 'page', get_post_type( $page ) );
		$blocks = parse_blocks( get_post_field( 'post_content', $page ) );
		$this->assertSame( self::PATTERN, $blocks[0]['attrs']['metadata']['patternName'] );
		$this->assertWPError( Building_Block_Views::create_page( 'leitvia/unknown' ) );
	}

	/**
	 * Each block gets an inserter category with its title, and its patterns join it once: on the pattern routes of the
	 * editor, not on other REST requests.
	 */
	public function test_inserter_category(): void {
		self::register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/building-blocks' ) );
		$this->assertFalse( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( 'leitvia-block-leitvia-notes' ) );
		$this->assertSame( array( 'text' ), WP_Block_Patterns_Registry::get_instance()->get_registered( self::PATTERN )['categories'] );

		// The pattern directory stays out of the test.
		add_filter( 'should_load_remote_block_patterns', '__return_false' );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/block-patterns/categories' ) )->get_status() );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/block-patterns/patterns' ) )->get_status() );
		remove_filter( 'should_load_remote_block_patterns', '__return_false' );
		Building_Block_Views::categories();
		$category = WP_Block_Pattern_Categories_Registry::get_instance()->get_registered( 'leitvia-block-leitvia-notes' );
		$this->assertSame( 'Notes', $category['label'] );
		$this->assertSame( array( 'text', 'leitvia-block-leitvia-notes' ), WP_Block_Patterns_Registry::get_instance()->get_registered( self::PATTERN )['categories'] );
	}

	/**
	 * Registers the recipe Notes with its building block.
	 */
	private static function register_recipe(): void {
		add_action(
			'leitvia_studio_init',
			static function ( Studio $studio ): void {
				$studio->registry( 'recipes' )->register(
					'leitvia/notes',
					array(
						'label' => 'Notes',
						'path'  => __DIR__ . '/../fixtures/gateway/recipes/notes/recipe.json',
					),
					'leitvia-studio'
				);
				$studio->registry( 'building_blocks' )->register(
					'leitvia/recipe-notes',
					array(
						'title'       => 'Notes',
						'description' => 'Short notes.',
						'model'       => array(
							'postTypes' => array(
								'lv_rnote' => array(
									'label'         => 'Notes',
									'singularLabel' => 'Note',
								),
							),
						),
						'samples'     => static fn(): array => array(
							'postType' => 'lv_rnote',
							'entries'  => array(
								array(
									'slug'  => 'a-note',
									'title' => 'A note',
								),
							),
						),
					),
					'leitvia-studio'
				);
			},
			20
		);
		self::boot();
	}

	/**
	 * Everything at "suggest": a person's run with settle applies what collects at once (types, sample draft, page
	 * live) and lists the applied packages, which roll back; an agent's run waits in one package even with settle, as
	 * does a person's run without it.
	 */
	public function test_recipe_run_of_a_person_settles(): void {
		self::register_recipe();
		$suggest = array(
			'overrides' => array(
				'areas' => array_fill_keys( array( 'content', 'structure', 'design' ), 'suggest' ),
				'kinds' => array(),
			),
		) + Gateway::DEFAULTS;
		update_option( Gateway::OPTION, $suggest );
		$run = static fn( bool $settle ): array => (array) wp_get_ability( 'leitvia/apply-recipe' )->execute(
			array(
				'recipe' => 'leitvia/notes',
				'parts'  => array( 'buildingBlocks', 'samples', 'pages' ),
				'settle' => $settle,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$agent = $run( true );
		$this->assertGreaterThan( 0, $agent['package'] );
		$this->assertArrayNotHasKey( 'applied', $agent );
		$this->assertFalse( post_type_exists( 'lv_rnote' ) );
		Packages::reject( $agent['package'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$waits = $run( false );
		$this->assertGreaterThan( 0, $waits['package'] );
		$this->assertArrayNotHasKey( 'applied', $waits );
		$this->assertFalse( post_type_exists( 'lv_rnote' ) );
		Packages::reject( $waits['package'] );

		$person = $run( true );
		$this->assertSame( 0, $person['package'], wp_json_encode( $person['steps'] ) );
		$this->assertNotEmpty( $person['applied'] );
		$this->assertNotContains( 'package', array_column( $person['steps'], 'status' ), wp_json_encode( $person['steps'] ) );
		$this->assertNotContains( 'waiting', array_column( $person['steps'], 'status' ), wp_json_encode( $person['steps'] ) );
		$this->assertTrue( post_type_exists( 'lv_rnote' ) );
		$notes = get_posts(
			array(
				'post_type'   => 'lv_rnote',
				'post_status' => 'any',
			)
		);
		$this->assertSame( array( 'draft' ), wp_list_pluck( $notes, 'post_status' ) );
		$page = get_page_by_path( 'notes-home' );
		$this->assertInstanceOf( WP_Post::class, $page );
		foreach ( $person['applied'] as $id ) {
			$this->assertSame( 'applied', Packages::state( $id ) );
		}

		$last = (int) end( $person['applied'] );
		$this->assertIsArray( Packages::rollback( $last ) );
		$this->assertNotSame( 'publish', get_post_status( $page->ID ), 'the page rolls back with the last package' );
	}
}
