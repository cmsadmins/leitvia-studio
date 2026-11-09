<?php
/**
 * Gateway setup abilities.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Store;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;
use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Dtcg;
use LeitviaStudio\Tokens\Token_Sets;

/**
 * Abilities create-token-set, activate-token-set, set-variation, define-content-type, update-navigation and update-template-part
 * through the pipeline, as an agent in the profiles standard and full.
 */
class Test_Gateway_Setup extends WP_UnitTestCase {

	/**
	 * Leitvia theme, the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_clean_theme_json_cache();
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes the abilities and the category.
	 */
	public function tear_down(): void {
		self::unregister();
		foreach ( array( 'leitvia-test/single-wide', 'leitvia-test/page-plain', 'leitvia-test/product-wide' ) as $pattern ) {
			if ( WP_Block_Patterns_Registry::get_instance()->is_registered( $pattern ) ) {
				unregister_block_pattern( $pattern );
			}
		}
		if ( function_exists( 'unregister_block_template' ) && WP_Block_Templates_Registry::get_instance()->is_registered( 'leitvia-test//leitvia-test-plain' ) ) {
			unregister_block_template( 'leitvia-test//leitvia-test-plain' );
		}
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		Model::forget();
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
	 * Signs in a user in a profile.
	 *
	 * @param string $profile standard or full.
	 * @param string $role    Role.
	 */
	private function sign_in( string $profile, string $role = Gateway::ROLE ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		update_option( Gateway::OPTION, array( 'profile' => $profile ) + Gateway::DEFAULTS );
	}

	/**
	 * Runs an ability.
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function call( string $name, array $input ) {
		return wp_get_ability( $name )->execute( $input + array( 'base_hash' => '' ) );
	}

	/**
	 * Asserts a result and returns it.
	 *
	 * @param string $mode   Expected mode.
	 * @param mixed  $result Result.
	 * @return array<string, mixed>
	 */
	private function assert_mode( string $mode, $result ): array {
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertSame( $mode, $result['mode'] );
		return $result;
	}

	/**
	 * Asserts a rejection and returns its data.
	 *
	 * @param string $code   Code.
	 * @param mixed  $result Result.
	 * @return array<string, mixed>
	 */
	private function assert_rejected( string $code, $result ): array {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result->get_error_data();
	}

	/**
	 * Token document of the theme presets.
	 *
	 * @return array<string, mixed>
	 */
	private static function document(): array {
		return Dtcg::from_settings( wp_get_global_settings(), leitvia_studio_contract() );
	}

	/**
	 * ID of the user global styles of the active theme, 0 for none.
	 */
	private static function styles_id(): int {
		return (int) ( WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() )['ID'] ?? 0 );
	}

	/**
	 * Palette color of a slug in the user global styles.
	 *
	 * @param string $slug Color slug.
	 */
	private static function user_color( string $slug ): string {
		$data = json_decode( (string) get_post_field( 'post_content', self::styles_id() ), true );
		return (string) ( array_column( (array) ( $data['settings']['color']['palette']['theme'] ?? $data['settings']['color']['palette'] ?? array() ), 'color', 'slug' )[ $slug ] ?? '' );
	}

	/**
	 * A failing contrast pair is refused with findings and fixes; standard proposes the set in a package, full
	 * creates it, private and with the document.
	 */
	public function test_create_token_set(): void {
		$this->sign_in( 'full' );
		$bad                                  = self::document();
		$bad['color']['ink']['$value']['hex'] = '#ffffff';
		$data                                 = $this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/create-token-set',
				array(
					'title'    => 'Bad',
					'document' => $bad,
				)
			)
		);
		$contrast                             = wp_list_filter( $data['findings'], array( 'code' => 'contrast' ) );
		$this->assertNotEmpty( $contrast );
		$this->assertStringContainsString( ':1', (string) current( $contrast )['fix'] );
		$this->assertSame( 0, (int) wp_count_posts( Token_Sets::POST_TYPE )->private );

		$result = $this->assert_mode(
			'live',
			self::call(
				'leitvia/create-token-set',
				array(
					'title'    => 'Brand',
					'document' => self::document(),
				)
			)
		);
		$set    = get_post( $result['target']['post'] );
		$this->assertSame( 'private', $set->post_status );
		$this->assertSame( 'Brand', $set->post_title );
		$this->assertSame( self::document(), json_decode( (string) get_post_meta( $set->ID, Token_Sets::META, true ), true ) );

		// The same set again (a recipe run twice) targets the existing one and writes nothing.
		$again = $this->assert_mode(
			'unchanged',
			self::call(
				'leitvia/create-token-set',
				array(
					'title'     => 'Brand',
					'document'  => self::document(),
					'base_hash' => Hashes::document( $set->ID ),
				)
			)
		);
		$this->assertSame( $set->ID, $again['target']['post'] );
		$this->assertSame( 0, $again['snapshot'] );
		$this->assertSame( 1, (int) wp_count_posts( Token_Sets::POST_TYPE )->private );

		$this->sign_in( 'standard' );
		$result = $this->assert_mode(
			'package',
			self::call(
				'leitvia/create-token-set',
				array(
					'title'    => 'Proposal',
					'document' => self::document(),
				)
			)
		);
		$this->assertSame( 'suggest', $result['level'] );
		$this->assertSame( 1, (int) wp_count_posts( Token_Sets::POST_TYPE )->private );
	}

	/**
	 * Activation goes through the compiler with a snapshot of the global styles and needs their hash. An agent creates
	 * the set but only proposes its activation, also in the profile full; a person activates it.
	 */
	public function test_activate_token_set(): void {
		$this->sign_in( 'full' );
		$doc                                     = self::document();
		$doc['color']['accent']['$value']['hex'] = '#0b4f9c';
		$set                                     = self::call(
			'leitvia/create-token-set',
			array(
				'title'    => 'Blue',
				'document' => $doc,
			)
		)['target']['post'];
		$this->assertSame( 'suggest', $this->assert_mode( 'package', self::call( 'leitvia/activate-token-set', array( 'set' => $set ) ) )['level'] );
		$this->assertSame( 0, self::styles_id() );

		// No global styles yet: the operation creates them.
		$this->sign_in( 'full', 'administrator' );
		$this->assert_mode( 'live', self::call( 'leitvia/activate-token-set', array( 'set' => $set ) ) );
		$this->assertSame( $set, (int) get_option( Compiler::ACTIVE_OPTION ) );
		$styles = self::styles_id();
		$this->assertGreaterThan( 0, $styles );
		$this->assertSame( '#0b4f9c', self::user_color( 'accent' ) );

		$data = $this->assert_rejected( 'leitvia_stale_hash', self::call( 'leitvia/activate-token-set', array( 'set' => $set ) ) );
		$this->assertSame( Hashes::document( $styles ), $data['current_hash'] );
		// With the current hash the same set again leaves the global styles as they are: nothing written, no snapshot.
		$before = get_post_field( 'post_content', $styles );
		$result = $this->assert_mode(
			'unchanged',
			self::call(
				'leitvia/activate-token-set',
				array(
					'set'       => $set,
					'base_hash' => $data['current_hash'],
				)
			)
		);
		$this->assertSame( 0, $result['snapshot'] );
		$this->assertSame( $before, get_post_field( 'post_content', $styles ) );

		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/activate-token-set', array( 'set' => self::factory()->post->create() ) ) );

		$doc['color']['accent']['$value']['hex'] = '#b00020';
		$red                                     = self::call(
			'leitvia/create-token-set',
			array(
				'title'    => 'Red',
				'document' => $doc,
			)
		)['target']['post'];
		$this->sign_in( 'standard' );
		$result = $this->assert_mode(
			'package',
			self::call(
				'leitvia/activate-token-set',
				array(
					'set'       => $red,
					'base_hash' => Hashes::document( $styles ),
				)
			)
		);

		// The inbox names the set and the colors that change, not only the JSON of the global styles.
		$summary = Packages::detail( (int) $result['package'] )['items'][0]['styles'];
		$this->assertSame( 'Red', $summary['token_set'] );
		$accent = wp_list_filter( $summary['colors'], array( 'slug' => 'accent' ) );
		$this->assertCount( 1, $accent );
		$this->assertSame( '#0b4f9c', strtolower( reset( $accent )['before'] ) );
		$this->assertSame( '#b00020', strtolower( reset( $accent )['after'] ) );

		// A person activates the other set live; the snapshot keeps the global styles before.
		$this->sign_in( 'full', 'administrator' );
		$result = $this->assert_mode(
			'live',
			self::call(
				'leitvia/activate-token-set',
				array(
					'set'       => $red,
					'base_hash' => Hashes::document( $styles ),
				)
			)
		);
		$this->assertSame( $before, Store::get_snapshot( $result['snapshot'] )['state']['post_content'] );
		$this->assertSame( '#b00020', self::user_color( 'accent' ) );
	}

	/**
	 * A family that is neither bundled nor in the Font Library gives the warning font_not_bundled when the set is
	 * created and when it is activated, live, in a package and as a suggestion alike; the warning holds nothing back.
	 * A bundled family gives none.
	 */
	public function test_font_not_bundled_is_reported(): void {
		$warnings = static fn( array $result ): array => array_values( wp_list_filter( $result['findings'], array( 'code' => 'font_not_bundled' ) ) );
		$create   = static fn( string $title, array $doc ) => self::call(
			'leitvia/create-token-set',
			array(
				'title'    => $title,
				'document' => $doc,
			)
		);
		$doc      = self::document();
		$slug     = (string) array_key_first( array_filter( $doc['fontFamily'], 'is_array' ) );

		$this->sign_in( 'full', 'administrator' );
		$this->assertSame( array(), $warnings( $this->assert_mode( 'live', $create( 'Bundled', $doc ) ) ) );

		$doc['fontFamily'][ $slug ]['$value'] = array( 'Fraunces', 'serif' );
		$this->sign_in( 'standard' );
		$proposed = $this->assert_mode( 'package', $create( 'Proposed', $doc ) );
		$this->assertSame( 'suggest', $proposed['level'] );
		$this->assertCount( 1, $warnings( $proposed ) );

		$this->sign_in( 'full' );
		$created = $this->assert_mode( 'live', $create( 'Not bundled', $doc ) );
		$this->assertSame( array( 'slug' => $slug ), $warnings( $created )[0]['data'] ?? null );
		$set = (int) $created['target']['post'];
		$this->assertCount( 1, $warnings( $this->assert_mode( 'package', self::call( 'leitvia/activate-token-set', array( 'set' => $set ) ) ) ) );

		$this->sign_in( 'full', 'administrator' );
		$activated = $this->assert_mode( 'live', self::call( 'leitvia/activate-token-set', array( 'set' => $set ) ) );
		$this->assertCount( 1, $warnings( $activated ) );
		$this->assertSame( $set, (int) get_option( Compiler::ACTIVE_OPTION ) );
	}

	/**
	 * A group this version does not read gives the warning leitvia_studio_dtcg_unknown_group with the group and the
	 * Studio version when the set is created and when it is activated; the warning holds nothing back.
	 */
	public function test_unknown_group_is_reported(): void {
		$warnings         = static fn( array $result ): array => array_values( wp_list_filter( $result['findings'], array( 'code' => 'leitvia_studio_dtcg_unknown_group' ) ) );
		$doc              = self::document();
		$doc['maxWidths'] = array(
			'page' => array(
				'$type'  => 'dimension',
				'$value' => '80rem',
			),
		);

		$this->sign_in( 'full', 'administrator' );
		$created = $this->assert_mode(
			'live',
			self::call(
				'leitvia/create-token-set',
				array(
					'title'    => 'Later role',
					'document' => $doc,
				)
			)
		);
		$this->assertSame(
			array(
				'group'   => 'maxWidths',
				'version' => LEITVIA_STUDIO_VERSION,
			),
			$warnings( $created )[0]['data'] ?? null
		);
		$set       = (int) $created['target']['post'];
		$activated = $this->assert_mode( 'live', self::call( 'leitvia/activate-token-set', array( 'set' => $set ) ) );
		$this->assertCount( 1, $warnings( $activated ) );
		$this->assertSame( $set, (int) get_option( Compiler::ACTIVE_OPTION ) );
	}

	/**
	 * A full variation replaces the global styles, a colors variation only its colors; the snapshot keeps the state
	 * before; unknown variations are refused. An agent proposes a variation in the profile full as well.
	 */
	public function test_set_variation(): void {
		$this->sign_in( 'full' );
		$this->assert_mode( 'package', self::call( 'leitvia/set-variation', array( 'variation' => 'corporate-light' ) ) );
		$this->assertSame( 0, self::styles_id() );
		$this->sign_in( 'full', 'administrator' );
		$this->assert_mode( 'live', self::call( 'leitvia/set-variation', array( 'variation' => 'corporate-light' ) ) );
		$styles = self::styles_id();
		$this->assertSame( array( get_stylesheet() ), wp_get_object_terms( $styles, 'wp_theme', array( 'fields' => 'names' ) ) );
		$this->assertSame( '#0f1f3a', self::user_color( 'ink' ) );
		WP_Theme_JSON_Resolver::clean_cached_data();
		$this->assertSame( '#0f1f3a', array_column( wp_get_global_settings( array( 'color', 'palette', 'theme' ) ), 'color', 'slug' )['ink'] ?? '' );

		$typography = json_decode( (string) get_post_field( 'post_content', $styles ), true )['settings']['typography'] ?? null;
		$before     = get_post_field( 'post_content', $styles );
		$result     = $this->assert_mode(
			'live',
			self::call(
				'leitvia/set-variation',
				array(
					'variation' => 'clay',
					'base_hash' => Hashes::document( $styles ),
				)
			)
		);
		$this->assertSame( $before, Store::get_snapshot( $result['snapshot'] )['state']['post_content'] );
		$after = json_decode( (string) get_post_field( 'post_content', $styles ), true );
		$this->assertSame( $typography, $after['settings']['typography'] ?? null, 'colors only' );
		$this->assertNotSame( '#0f1f3a', self::user_color( 'ink' ) );

		$this->assert_rejected( 'leitvia_stale_hash', self::call( 'leitvia/set-variation', array( 'variation' => 'clay' ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/set-variation', array( 'variation' => 'neon' ) ) );

		$this->sign_in( 'standard' );
		$this->assert_mode(
			'package',
			self::call(
				'leitvia/set-variation',
				array(
					'variation' => 'corporate-dark',
					'base_hash' => Hashes::document( $styles ),
				)
			)
		);
	}

	/**
	 * Agents never set showInAbilities; invalid declarations are refused by the dry run; entries are added to the
	 * stored ones, a replaced entry keeps the flag a person set. An agent proposes, an administrator in full writes.
	 */
	public function test_define_content_type(): void {
		$events = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/model/events.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$this->sign_in( 'full' );
		$data = $this->assert_rejected( 'leitvia_forbidden_field', self::call( 'leitvia/define-content-type', array( 'declaration' => $events ) ) );
		$this->assertSame( 'show_in_abilities', $data['reason'] );

		$book = array(
			'version'   => 1,
			'postTypes' => array(
				'book' => array(
					'label'         => 'Books',
					'singularLabel' => 'Book',
				),
			),
		);
		$hash = static fn(): string => Hashes::of( (string) wp_json_encode( get_option( Model::OPTION ) ) );
		$data = $this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/define-content-type',
				array(
					'declaration' => array( 'postTypes' => array( 'book' => array( 'label' => 'Books' ) ) ),
					'base_hash'   => $hash(),
				)
			)
		);
		$this->assertSame( 'leitvia/content-model', $data['findings'][0]['check'] );
		$this->assertSame( array(), Model::stored() );

		$this->sign_in( 'standard' );
		$this->assert_mode(
			'package',
			self::call(
				'leitvia/define-content-type',
				array(
					'declaration' => $book,
					'base_hash'   => $hash(),
				)
			)
		);
		$this->assertSame( array(), Model::stored() );

		update_option(
			Model::OPTION,
			array(
				'version'   => 1,
				'postTypes' => array(
					'book' => array(
						'label'           => 'Old books',
						'singularLabel'   => 'Old book',
						'showInAbilities' => true,
					),
				),
			)
		);
		$this->sign_in( 'full', 'administrator' );
		$this->assert_mode(
			'live',
			self::call(
				'leitvia/define-content-type',
				array(
					'declaration' => $book + array(
						'taxonomies' => array(
							'genre' => array(
								'label'         => 'Genres',
								'singularLabel' => 'Genre',
								'postTypes'     => array( 'book' ),
							),
						),
					),
					'base_hash'   => $hash(),
				)
			)
		);
		$stored = Model::stored();
		$this->assertSame( 'Books', $stored['postTypes']['book']['label'] );
		$this->assertTrue( $stored['postTypes']['book']['showInAbilities'] );
		$this->assertSame( 'Genres', $stored['taxonomies']['genre']['label'] );
		$this->assertEquals( 1, get_option( Model::FLUSH_OPTION ) );
	}

	/**
	 * A live define-content-type registers the new post type, taxonomy and setting in the same request, so the next
	 * step of a recipe or building block can write entries; the rewrite rules still flush on the next init.
	 */
	public function test_define_content_type_registers_in_the_same_request(): void {
		$this->sign_in( 'full', 'administrator' );
		$this->assertFalse( post_type_exists( 'lv_movie' ) );
		$this->assert_mode(
			'live',
			self::call(
				'leitvia/define-content-type',
				array(
					'declaration' => array(
						'version'    => 1,
						'postTypes'  => array(
							'lv_movie' => array(
								'label'         => 'Movies',
								'singularLabel' => 'Movie',
								'fields'        => array(
									'movie_year' => array(
										'type'  => 'integer',
										'label' => 'Year',
									),
								),
							),
						),
						'taxonomies' => array(
							'lv_genre' => array(
								'label'         => 'Genres',
								'singularLabel' => 'Genre',
								'postTypes'     => array( 'lv_movie' ),
							),
						),
						'settings'   => array(
							'cinema_name' => array(
								'type'  => 'text',
								'label' => 'Cinema',
							),
						),
					),
					'base_hash'   => Hashes::of( (string) wp_json_encode( get_option( Model::OPTION ) ) ),
				)
			)
		);
		$this->assertTrue( post_type_exists( 'lv_movie' ) );
		$this->assertTrue( taxonomy_exists( 'lv_genre' ) );
		$this->assertTrue( is_object_in_taxonomy( 'lv_movie', 'lv_genre' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', 'movie_year', 'lv_movie' ) );
		$this->assertArrayHasKey( Registrar::SETTING_PREFIX . 'cinema_name', get_registered_settings() );
		$this->assertEquals( 1, get_option( Model::FLUSH_OPTION ) );
		unregister_post_type( 'lv_movie' );
		unregister_taxonomy( 'lv_genre' );
		unregister_setting( Registrar::SETTINGS_GROUP, Registrar::SETTING_PREFIX . 'cinema_name' );
	}

	/**
	 * An entry that is defined again keeps what a person added: fields and repeater fields not in the input stay, the
	 * input updates its own fields, supports and the post types of a taxonomy are joined, relations are replaced.
	 */
	public function test_define_content_type_merges_fields(): void {
		$text = static fn( string $label ): array => array(
			'type'  => 'text',
			'label' => $label,
		);
		update_option(
			Model::OPTION,
			array(
				'version'    => 1,
				'postTypes'  => array(
					'lv_book'     => array(
						'label'         => 'Books',
						'singularLabel' => 'Book',
						'description'   => 'Our own description.',
						'supports'      => array( 'title', 'thumbnail' ),
						'fields'        => array(
							'isbn'       => $text( 'ISBN' ),
							'shelf_note' => $text( 'Shelf note' ),
						),
						'repeaters'     => array(
							'chapters' => array(
								'postType'      => 'lv_chapter',
								'label'         => 'Chapters',
								'singularLabel' => 'Chapter',
								'fields'        => array( 'chapter_pages' => $text( 'Pages' ) ),
							),
						),
					),
					'lv_magazine' => array(
						'label'         => 'Magazines',
						'singularLabel' => 'Magazine',
					),
				),
				'taxonomies' => array(
					'lv_shelf' => array(
						'label'         => 'Shelves',
						'singularLabel' => 'Shelf',
						'postTypes'     => array( 'lv_magazine' ),
					),
				),
				'relations'  => array(
					'book_magazines' => array(
						'label'    => 'Magazines',
						'from'     => 'lv_book',
						'to'       => 'lv_magazine',
						'multiple' => true,
					),
				),
			)
		);
		Model::forget();
		$this->sign_in( 'full', 'administrator' );
		$this->assert_mode(
			'live',
			self::call(
				'leitvia/define-content-type',
				array(
					'declaration' => array(
						'version'    => 1,
						'postTypes'  => array(
							'lv_book' => array(
								'label'         => 'Books',
								'singularLabel' => 'Book',
								'supports'      => array( 'title', 'excerpt' ),
								'fields'        => array(
									'isbn'     => $text( 'ISBN-13' ),
									'subtitle' => $text( 'Subtitle' ),
								),
								'repeaters'     => array(
									'chapters' => array(
										'postType'      => 'lv_chapter',
										'label'         => 'Chapters',
										'singularLabel' => 'Chapter',
										'fields'        => array( 'chapter_title' => $text( 'Title' ) ),
									),
								),
							),
						),
						'taxonomies' => array(
							'lv_shelf' => array(
								'label'         => 'Shelves',
								'singularLabel' => 'Shelf',
								'postTypes'     => array( 'lv_book' ),
							),
						),
						'relations'  => array(
							'book_magazines' => array(
								'label' => 'Magazine',
								'from'  => 'lv_book',
								'to'    => 'lv_magazine',
							),
						),
					),
					'base_hash'   => Hashes::of( (string) wp_json_encode( get_option( Model::OPTION ) ) ),
				)
			)
		);
		$book = Model::stored()['postTypes']['lv_book'];
		$this->assertSame( array( 'isbn', 'shelf_note', 'subtitle' ), array_keys( $book['fields'] ) );
		$this->assertSame( 'ISBN-13', $book['fields']['isbn']['label'] );
		$this->assertSame( 'Our own description.', $book['description'] );
		$this->assertSame( array( 'title', 'thumbnail', 'excerpt' ), $book['supports'] );
		$this->assertSame( array( 'chapter_pages', 'chapter_title' ), array_keys( $book['repeaters']['chapters']['fields'] ) );
		$this->assertSame( array( 'lv_magazine', 'lv_book' ), Model::stored()['taxonomies']['lv_shelf']['postTypes'] );
		$this->assertArrayNotHasKey( 'multiple', Model::stored()['relations']['book_magazines'], 'a relation is replaced whole' );
		$this->assertArrayHasKey( 'lv_magazine', Model::stored()['postTypes'] );
		foreach ( array( 'lv_book', 'lv_chapter', 'lv_magazine' ) as $type ) {
			unregister_post_type( $type );
		}
		unregister_taxonomy( 'lv_shelf' );
	}

	/**
	 * Items become navigation links in the menu published last, or in a new one; foreign http targets, vague texts and
	 * other blocks are refused; a change needs the hash.
	 */
	public function test_update_navigation(): void {
		$this->sign_in( 'full' );
		$items  = array(
			array(
				'label' => 'About us',
				'url'   => home_url( '/about/' ),
			),
			array(
				'label' => 'Services',
				'url'   => '/services/',
				'items' => array(
					array(
						'label' => 'Consulting',
						'url'   => 'https://example.com/consulting',
					),
				),
			),
		);
		$result = $this->assert_mode( 'live', self::call( 'leitvia/update-navigation', array( 'items' => $items ) ) );
		$menu   = get_post( $result['target']['post'] );
		$this->assertSame( 'wp_navigation', $menu->post_type );
		$this->assertSame( 'publish', $menu->post_status );
		$names = array_filter( array_column( parse_blocks( $menu->post_content ), 'blockName' ) );
		$this->assertSame( array( 'core/navigation-link', 'core/navigation-submenu' ), array_values( $names ) );
		$this->assertStringContainsString( 'Consulting', $menu->post_content );

		$bad  = array(
			array(
				'label' => 'Click here',
				'url'   => 'http://foreign.example/',
			),
		);
		$data = $this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/update-navigation',
				array(
					'items'     => $bad,
					'base_hash' => Hashes::document( $menu->ID ),
				)
			)
		);
		$this->assertSame( array( 'leitvia/navigation', 'leitvia/link-text' ), array_column( $data['findings'], 'check' ) );
		$data = $this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/update-navigation',
				array(
					'content'   => '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->',
					'base_hash' => Hashes::document( $menu->ID ),
				)
			)
		);
		$this->assertSame( 'p:0', $data['findings'][0]['block'] );
		$this->assert_rejected( 'leitvia_stale_hash', self::call( 'leitvia/update-navigation', array( 'items' => $items ) ) );

		$this->assert_mode(
			'live',
			self::call(
				'leitvia/update-navigation',
				array(
					'navigation' => $menu->ID,
					'content'    => '<!-- wp:page-list /-->',
					'base_hash'  => Hashes::document( $menu->ID ),
				)
			)
		);
		$this->assertSame( array( 'core/page-list' ), array_column( parse_blocks( get_post_field( 'post_content', $menu->ID ) ), 'blockName' ) );

		$this->sign_in( 'standard' );
		$this->assert_mode(
			'package',
			self::call(
				'leitvia/update-navigation',
				array(
					'items'     => $items,
					'base_hash' => Hashes::document( $menu->ID ),
				)
			)
		);
	}

	/**
	 * A header from a header pattern gets a post with the terms core finds it by; patterns of another area and parts
	 * outside the contract are refused; changed markup needs the hash; standard proposes a new part in a package, since
	 * core renders no draft part.
	 */
	public function test_update_template_part(): void {
		$this->sign_in( 'full' );
		$result   = $this->assert_mode(
			'live',
			self::call(
				'leitvia/update-template-part',
				array(
					'part'    => 'header',
					'pattern' => 'leitvia/header-search',
				)
			)
		);
		$template = get_block_template( get_stylesheet() . '//header', 'wp_template_part' );
		$this->assertSame( $result['target']['post'], $template->wp_id );
		$this->assertSame( 'header', $template->area );
		$this->assertStringContainsString( 'wp:search', $template->content );

		$this->assert_rejected(
			'leitvia_unknown_pattern',
			self::call(
				'leitvia/update-template-part',
				array(
					'part'    => 'header',
					'pattern' => 'leitvia/footer-minimal',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-template-part',
				array(
					'part'    => 'banner',
					'pattern' => 'leitvia/header-default',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_stale_hash',
			self::call(
				'leitvia/update-template-part',
				array(
					'part'    => 'header',
					'pattern' => 'leitvia/header-default',
				)
			)
		);
		$content = '<!-- wp:site-title /-->';
		$result  = $this->assert_mode(
			'live',
			self::call(
				'leitvia/update-template-part',
				array(
					'part'      => 'header',
					'content'   => $content,
					'base_hash' => Hashes::document( $template->wp_id ),
				)
			)
		);
		$this->assertSame( array( 'core/site-title' ), array_column( parse_blocks( get_post_field( 'post_content', $template->wp_id ) ), 'blockName' ) );
		$this->assertGreaterThan( 0, $result['snapshot'] );

		$this->sign_in( 'standard' );
		$parts  = (int) array_sum( (array) wp_count_posts( 'wp_template_part' ) );
		$result = $this->assert_mode(
			'package',
			self::call(
				'leitvia/update-template-part',
				array(
					'part'    => 'footer',
					'pattern' => 'leitvia/footer-minimal',
				)
			)
		);
		$this->assertSame( $parts, (int) array_sum( (array) wp_count_posts( 'wp_template_part' ) ), 'no draft part' );
		$this->assertSame( 'publish', Packages::operations( $result['package'] )[0]['state']['post_status'] );
	}

	/**
	 * A template from a template pattern made for its slug gets a post with the theme term; patterns for another
	 * template, slugs outside the contract and the theme, shop templates without WooCommerce and invalid blocks are
	 * refused; changed markup needs the hash; rollback restores the content and then the template of the theme.
	 */
	public function test_update_template(): void {
		$marker = '<!-- wp:paragraph --><p>Template from a pattern</p><!-- /wp:paragraph -->';
		register_block_pattern(
			'leitvia-test/single-wide',
			array(
				'title'         => 'Single wide',
				'content'       => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title {"level":1} /-->' . $marker . '<!-- wp:post-content {"align":"full","layout":{"type":"constrained"}} /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
				'templateTypes' => array( 'single' ),
			)
		);
		register_block_pattern(
			'leitvia-test/page-plain',
			array(
				'title'         => 'Page plain',
				'content'       => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-content {"layout":{"type":"constrained"}} /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
				'templateTypes' => array( 'page' ),
			)
		);
		$this->sign_in( 'full' );
		$this->assertSame( 'theme', get_block_template( get_stylesheet() . '//single' )->source );
		$first    = $this->assert_mode(
			'live',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'single',
					'pattern'  => 'leitvia-test/single-wide',
				)
			)
		);
		$template = get_block_template( get_stylesheet() . '//single' );
		$this->assertSame( $first['target']['post'], $template->wp_id );
		$this->assertSame( 'custom', $template->source );
		$this->assertSame( 'publish', get_post_status( $template->wp_id ) );
		$this->assertStringContainsString( 'Template from a pattern', $template->content );

		$data = $this->assert_rejected(
			'leitvia_unknown_pattern',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'single',
					'pattern'  => 'leitvia-test/page-plain',
				)
			)
		);
		$this->assertContains( 'leitvia-test/single-wide', $data['patterns'], 'the rejection names the patterns that fit' );
		$this->assertNotContains( 'leitvia-test/page-plain', $data['patterns'] );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'banner',
					'pattern'  => 'leitvia-test/single-wide',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'single',
					'pattern'  => 'leitvia-test/single-wide',
					'content'  => $marker,
				)
			)
		);
		if ( ! class_exists( 'WooCommerce' ) ) {
			$shop = array(
				'template' => 'single-product',
				'content'  => $marker,
			);
			$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/update-template', $shop ) );
		}
		$this->assert_rejected(
			'leitvia_stale_hash',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'single',
					'content'  => $marker . '<!-- wp:post-content /-->',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/update-template',
				array(
					'template'  => 'single',
					'content'   => '<!-- wp:post-content /--><!-- wp:leitvia-test/missing /-->',
					'base_hash' => Hashes::document( $template->wp_id ),
				)
			)
		);
		$second = $this->assert_mode(
			'live',
			self::call(
				'leitvia/update-template',
				array(
					'template'  => 'single',
					'content'   => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-content /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
					'base_hash' => Hashes::document( $template->wp_id ),
				)
			)
		);
		$this->assertSame( array( 'core/template-part', 'core/group', 'core/template-part' ), array_column( parse_blocks( get_post_field( 'post_content', $template->wp_id ) ), 'blockName' ) );
		$this->assertStringNotContainsString( 'Template from a pattern', get_post_field( 'post_content', $template->wp_id ) );
		$this->assertGreaterThan( 0, $second['snapshot'] );

		$this->assertIsArray( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $second['package'] ) ) );
		$this->assertStringContainsString( 'Template from a pattern', get_post_field( 'post_content', $template->wp_id ) );
		$this->assertIsArray( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $first['package'] ) ) );
		$this->assertNull( get_post( $template->wp_id ), 'the post the package created is gone' );
		$this->assertSame( 'theme', get_block_template( get_stylesheet() . '//single' )->source );

		$this->sign_in( 'standard' );
		$templates = (int) array_sum( (array) wp_count_posts( 'wp_template' ) );
		$result    = $this->assert_mode(
			'package',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'page',
					'pattern'  => 'leitvia-test/page-plain',
				)
			)
		);
		$this->assertSame( $templates, (int) array_sum( (array) wp_count_posts( 'wp_template' ) ), 'no draft template' );
		$this->assertSame( 'publish', Packages::operations( $result['package'] )[0]['state']['post_status'] );
	}

	/**
	 * Templates for single posts and pages keep core/post-content, also inside a group; a saved template in the trash
	 * is named; a template only a plugin registers (get_block_templates()) is open as well.
	 */
	public function test_update_template_guards(): void {
		$this->sign_in( 'full' );
		$data = $this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'page-landing',
					'content'  => '<!-- wp:post-title /-->',
				)
			)
		);
		$this->assertSame( 'post_content', $data['reason'] );
		$this->assert_mode(
			'live',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'page-landing',
					'content'  => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:group --><div class="wp-block-group"><!-- wp:post-content /--></div><!-- /wp:group --></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
				)
			)
		);
		$this->assert_mode(
			'live',
			self::call(
				'leitvia/update-template',
				array(
					'template' => '404',
					'content'  => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:paragraph --><p>Not found</p><!-- /wp:paragraph --></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
				)
			)
		);

		$saved = (int) get_block_template( get_stylesheet() . '//page-landing' )->wp_id;
		wp_trash_post( $saved );
		$data = $this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'page-landing',
					'content'  => '<!-- wp:post-content /-->',
				)
			)
		);
		$this->assertSame( 'trashed', $data['reason'] );

		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'leitvia-test-plain',
					'content'  => '<!-- wp:post-content /-->',
				)
			)
		);
		register_block_template(
			'leitvia-test//leitvia-test-plain',
			array(
				'title'   => 'Plain',
				'content' => '<!-- wp:post-content /-->',
			)
		);
		$result = $this->assert_mode(
			'live',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'leitvia-test-plain',
					'content'  => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:paragraph --><p>Plain</p><!-- /wp:paragraph --><!-- wp:post-content /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
				)
			)
		);
		$this->assertSame( 'leitvia-test-plain', get_post_field( 'post_name', $result['target']['post'] ) );
	}

	/**
	 * With WooCommerce the product template takes a template pattern made for it, without core/post-content.
	 *
	 * @group woocommerce
	 */
	public function test_update_template_for_products(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'Needs WooCommerce (npm run test:php:commerce).' );
		}
		// The bootstrap loads WooCommerce without the option; the requires of the contract read the active plugins.
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
		register_block_pattern(
			'leitvia-test/product-wide',
			array(
				'title'         => 'Product wide',
				'content'       => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title {"level":1} /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
				'templateTypes' => array( 'single-product' ),
			)
		);
		$this->sign_in( 'full' );
		$result = $this->assert_mode(
			'live',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'single-product',
					'pattern'  => 'leitvia-test/product-wide',
				)
			)
		);
		$this->assertSame( $result['target']['post'], get_block_template( get_stylesheet() . '//single-product' )->wp_id );
	}

	/**
	 * Template slugs with an underscore pass the schema: without WooCommerce taxonomy-product_cat is refused as an
	 * unknown template, with WooCommerce it is written as a template of its own and a rollback removes it again.
	 *
	 * @group woocommerce
	 */
	public function test_update_template_for_product_categories(): void {
		$this->sign_in( 'full' );
		$content = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:query-title {"type":"archive","level":1} /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->';
		$input   = array(
			'template' => 'taxonomy-product_cat',
			'content'  => $content,
		);
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->assertSame( 'template', $this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/update-template', $input ) )['reason'] );
			return;
		}
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
		$result   = $this->assert_mode( 'live', self::call( 'leitvia/update-template', $input ) );
		$template = get_block_template( get_stylesheet() . '//taxonomy-product_cat' );
		$this->assertSame( $result['target']['post'], $template->wp_id );
		$this->assertSame( 'taxonomy-product_cat', get_post_field( 'post_name', $template->wp_id ) );
		$this->assertStringContainsString( 'wp:query-title', $template->content );
		$this->assertNotSame( $template->wp_id, get_block_template( get_stylesheet() . '//archive-product' )->wp_id ?? 0, 'the shop page keeps its own template' );

		$this->assertIsArray( wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $result['package'] ) ) );
		$this->assertNull( get_post( $result['target']['post'] ), 'the template the package created is gone' );
	}

	/**
	 * A product template pattern with blocks that take the product from their context, such as the add to cart form,
	 * passes the block check without a product to render.
	 *
	 * @group woocommerce
	 */
	public function test_update_template_with_product_blocks(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'Needs WooCommerce (npm run test:php:commerce).' );
		}
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
		register_block_pattern(
			'leitvia-test/product-form',
			array(
				'title'         => 'Product with form',
				'content'       => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-title {"level":1} /--><!-- wp:woocommerce/add-to-cart-form /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->',
				'templateTypes' => array( 'single-product' ),
			)
		);
		$this->sign_in( 'full' );
		$this->assert_mode(
			'live',
			self::call(
				'leitvia/update-template',
				array(
					'template' => 'single-product',
					'pattern'  => 'leitvia-test/product-form',
				)
			)
		);
	}

	/**
	 * With Polylang a part <part>-<language> of a language of the site is created in the area of its part; a suffix
	 * that is no language of the site, and any suffix without Polylang, stays refused.
	 */
	public function test_update_template_part_of_a_language(): void {
		$this->sign_in( 'full' );
		$input = array(
			'part'    => 'header-de',
			'pattern' => 'leitvia/header-search',
		);
		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/update-template-part', $input ) );

		if ( ! function_exists( 'pll_languages_list' ) ) {
			/**
			 * Languages of Polylang for this test: the slugs in the global leitvia_test_languages.
			 *
			 * @param array<string, mixed> $args Arguments.
			 * @return list<string>
			 */
			function pll_languages_list( array $args = array() ): array { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- Stand-in for Polylang.
				unset( $args );
				return (array) ( $GLOBALS['leitvia_test_languages'] ?? array() );
			}
		}
		$GLOBALS['leitvia_test_languages'] = array( 'en', 'de' );
		$result                            = $this->assert_mode( 'live', self::call( 'leitvia/update-template-part', $input ) );
		$template                          = get_block_template( get_stylesheet() . '//header-de', 'wp_template_part' );
		$this->assertSame( $result['target']['post'], $template->wp_id );
		$this->assertSame( 'header', $template->area );
		$this->assertSame( 'Header (de)', $template->title );
		$this->assertStringContainsString( 'wp:search', $template->content );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-template-part',
				array(
					'part'    => 'header-fr',
					'pattern' => 'leitvia/header-search',
				)
			)
		);
		unset( $GLOBALS['leitvia_test_languages'] );
	}

	/**
	 * A part left as a draft by an earlier run (core renders it nowhere) with the content of the pattern already: the
	 * same pattern with a package key still proposes it, and applying the package publishes it. Same for a draft menu.
	 */
	public function test_draft_part_is_published_by_a_package(): void {
		$this->sign_in( 'standard' );
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia/footer-minimal' );
		$id      = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_status'  => 'draft',
				'post_name'    => 'footer',
				'post_title'   => 'Footer',
				'post_content' => $pattern['content'],
				'tax_input'    => array(
					'wp_theme'              => array( get_stylesheet() ),
					'wp_template_part_area' => array( 'footer' ),
				),
			)
		);
		wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
		wp_set_object_terms( $id, 'footer', 'wp_template_part_area' );
		$this->assertSame( $id, (int) get_block_template( get_stylesheet() . '//footer', 'wp_template_part' )->wp_id );
		$result = $this->assert_mode(
			'package',
			self::call(
				'leitvia/update-template-part',
				array(
					'part'      => 'footer',
					'pattern'   => 'leitvia/footer-minimal',
					'base_hash' => Hashes::document( $id ),
					'package'   => 'repair-footer',
				)
			)
		);
		$menu   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:navigation-link {"label":"About","url":"/about/","kind":"custom"} /-->',
			)
		);
		$this->assert_mode(
			'package',
			self::call(
				'leitvia/update-navigation',
				array(
					'navigation' => $menu,
					'content'    => (string) get_post_field( 'post_content', $menu ),
					'base_hash'  => Hashes::document( $menu ),
					'package'    => 'repair-footer',
				)
			)
		);
		$this->assertSame( 'draft', get_post_status( $id ) );

		$this->sign_in( 'standard', 'administrator' );
		$report = Packages::apply( $result['package'] );
		$this->assertSame( 'applied', $report['state'], wp_json_encode( $report ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( 'publish', get_post_status( $menu ) );
	}

	/**
	 * The same change with the same package key lands in the package once.
	 */
	public function test_package_holds_a_change_once(): void {
		$this->sign_in( 'standard' );
		$input  = array(
			'variation' => 'corporate-light',
			'package'   => 'setup-task',
		);
		$first  = $this->assert_mode( 'package', self::call( 'leitvia/set-variation', $input ) );
		$second = $this->assert_mode( 'package', self::call( 'leitvia/set-variation', $input ) );
		$this->assertSame( $first['package'], $second['package'] );
		$this->assertCount( 1, Packages::operations( $first['package'] ) );
	}

	/**
	 * A header written as content keeps the settings of the navigation of the theme header (submenus on click, the
	 * overlay, the name) where the new navigation lacks them; a value it sets stays. Without a navigation in the part
	 * nothing is added.
	 */
	public function test_update_template_part_keeps_navigation_settings(): void {
		$this->sign_in( 'full' );
		$this->assert_mode(
			'live',
			self::call(
				'leitvia/update-template-part',
				array(
					'part'    => 'header',
					'content' => '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group"><!-- wp:site-title /--><!-- wp:navigation {"ref":4711} /--></header><!-- /wp:group -->',
				)
			)
		);
		$template = get_block_template( get_stylesheet() . '//header', 'wp_template_part' );
		$attrs    = parse_blocks( $template->content )[0]['innerBlocks'][1]['attrs'];
		$this->assertSame( 4711, $attrs['ref'] );
		$this->assertSame( 'click', $attrs['submenuVisibility'] );
		$this->assertSame( 'navigation-overlay', $attrs['overlay'] );
		$this->assertNotEmpty( $attrs['ariaLabel'] );
		$this->assertArrayNotHasKey( 'layout', $attrs, 'only the settings, not the layout' );

		$this->assertSame(
			'<!-- wp:navigation {"submenuVisibility":"hover","ariaLabel":"Main"} /-->',
			LeitviaStudio\Gateway\Abilities\Setup::keep_navigation(
				'<!-- wp:navigation {"submenuVisibility":"hover","ariaLabel":"Main"} /-->',
				'<!-- wp:navigation {"submenuVisibility":"click","ariaLabel":"Old"} /-->'
			),
			'values the content sets stay, byte for byte'
		);
		$this->assertSame( '<!-- wp:site-title /-->', LeitviaStudio\Gateway\Abilities\Setup::keep_navigation( '<!-- wp:site-title /-->', '<!-- wp:navigation {"submenuVisibility":"click"} /-->' ) );
	}
}
