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
	 * Activation goes through the compiler with a snapshot of the global styles and needs their hash.
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

		// No global styles yet: the operation creates them.
		$this->assertSame( 0, self::styles_id() );
		$this->assert_mode( 'live', self::call( 'leitvia/activate-token-set', array( 'set' => $set ) ) );
		$this->assertSame( $set, (int) get_option( Compiler::ACTIVE_OPTION ) );
		$styles = self::styles_id();
		$this->assertGreaterThan( 0, $styles );
		$this->assertSame( '#0b4f9c', self::user_color( 'accent' ) );

		$data = $this->assert_rejected( 'leitvia_stale_hash', self::call( 'leitvia/activate-token-set', array( 'set' => $set ) ) );
		$this->assertSame( Hashes::document( $styles ), $data['current_hash'] );
		$before = get_post_field( 'post_content', $styles );
		$result = $this->assert_mode(
			'live',
			self::call(
				'leitvia/activate-token-set',
				array(
					'set'       => $set,
					'base_hash' => $data['current_hash'],
				)
			)
		);
		$this->assertSame( $before, Store::get_snapshot( $result['snapshot'] )['state']['post_content'] );

		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/activate-token-set', array( 'set' => self::factory()->post->create() ) ) );

		$this->sign_in( 'standard' );
		$this->assert_mode(
			'package',
			self::call(
				'leitvia/activate-token-set',
				array(
					'set'       => $set,
					'base_hash' => Hashes::document( $styles ),
				)
			)
		);
	}

	/**
	 * A full variation replaces the global styles, a colors variation only its colors; the snapshot keeps the state
	 * before; unknown variations are refused.
	 */
	public function test_set_variation(): void {
		$this->sign_in( 'full' );
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
}
