<?php
/**
 * Abilities for the single post, archives and header: get-template, set-post-details, update-term and the dark ground
 * of a header group.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Abilities\Build;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;

/**
 * Abilities get-template, set-post-details and update-term, the term-heading audit and ink in header parts.
 */
class Test_Gateway_Post_Template extends WP_UnitTestCase {

	/**
	 * Agent.
	 *
	 * @var int
	 */
	private int $agent;

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Leitvia active, abilities registered, every area autonomous, the agent signed in.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		if ( ! function_exists( 'Leitvia\post_details' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		$this->agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_option(
			Gateway::OPTION,
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(),
					'kinds' => Gateway::KINDS,
				),
			) + Gateway::DEFAULTS
		);
		add_filter( 'leitvia_studio_gateway_areas', array( self::class, 'open_everything' ) );
		add_filter( 'leitvia_post_details', array( LeitviaStudio\Gateway\Abilities\Setup::class, 'post_details' ) );
		wp_set_current_user( $this->agent );
	}

	/**
	 * Removes the abilities and filters.
	 */
	public function tear_down(): void {
		remove_filter( 'leitvia_studio_gateway_areas', array( self::class, 'open_everything' ) );
		self::unregister();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Every area autonomous.
	 *
	 * @param array<string, string> $levels Levels by area.
	 * @return array<string, string>
	 */
	public static function open_everything( array $levels ): array {
		return array_fill_keys( array_keys( $levels ), 'autonomous' ) + array( 'admin' => 'autonomous' );
	}

	/**
	 * Unregisters the abilities of Studio.
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
	 * Runs an ability; base_hash "" unless the input brings one.
	 *
	 * @param string               $name  Ability.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function call( string $name, array $input ) {
		$ability = wp_get_ability( $name );
		return $ability->execute( isset( $ability->get_input_schema()['properties']['base_hash'] ) ? $input + array( 'base_hash' => '' ) : $input );
	}

	/**
	 * The template single as the theme ships it: source theme, no hash, its patterns and the parts for blocks after the
	 * text and in the sidebar; a part reads the same way, an unknown slug is refused.
	 */
	public function test_get_template_reads_theme_files(): void {
		$single = self::call( 'leitvia/get-template', array( 'template' => 'single' ) );
		$this->assertIsArray( $single, is_wp_error( $single ) ? $single->get_error_message() : '' );
		$this->assertSame( array( 'single', 'template', 'theme', '' ), array( $single['slug'], $single['type'], $single['source'], $single['hash'] ) );
		$this->assertStringContainsString( '<!-- wp:template-part {"slug":"post-after-content"', $single['content'] );
		$this->assertStringContainsString( '"className":"leitvia-post-image"', $single['content'] );
		$this->assertContains( 'leitvia/hidden-page-post-aside', $single['patterns'] );
		$this->assertContains( 'leitvia/hidden-page-related-posts', $single['patterns'] );
		$this->assertSame( 'core/template-part', $single['outline'][0]['name'] );
		$this->assertSame( 'Updated %s', $single['post_details']['updated_label'] );

		$part = self::call( 'leitvia/get-template', array( 'part' => 'header' ) );
		$this->assertSame( array( 'part', 'header' ), array( $part['type'], $part['area'] ) );
		$this->assertArrayNotHasKey( 'post_details', $part );
		$this->assertWPError( self::call( 'leitvia/get-template', array( 'template' => 'nothing-here' ) ) );
		$this->assertWPError( self::call( 'leitvia/get-template', array() ) );
	}

	/**
	 * Labels, heading, source of the related posts and image ratio wait for a person; once applied the theme shows them,
	 * "" brings a default back, a label without %s is refused.
	 */
	public function test_set_post_details(): void {
		$result = self::call(
			'leitvia/set-post-details',
			array(
				'updated_label'   => 'As of %s',
				'related_heading' => 'More from this category',
				'related_by'      => 'primary',
				'image_ratio'     => '3/2',
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'package', $result['mode'] );
		$this->assertSame( 'Updated %s', Leitvia\post_details()['updated_label'] );
		wp_set_current_user( $this->admin );
		$this->assertSame( 'applied', Packages::apply( (int) $result['package'] )['state'] );
		$details = Leitvia\post_details();
		ksort( $details );
		$this->assertSame(
			array(
				'image_ratio'     => '3/2',
				'published_label' => 'Published %s',
				'related_by'      => 'primary',
				'related_heading' => 'More from this category',
				'updated_label'   => 'As of %s',
			),
			$details
		);

		$image = Leitvia\post_image_ratio( parse_blocks( '<!-- wp:post-featured-image {"aspectRatio":"16/9","align":"wide","className":"leitvia-post-image"} /-->' )[0] );
		$this->assertSame( '3/2', $image['attrs']['aspectRatio'] );
		$card = Leitvia\post_image_ratio( parse_blocks( '<!-- wp:post-featured-image {"aspectRatio":"16/9"} /-->' )[0] );
		$this->assertSame( '16/9', $card['attrs']['aspectRatio'], 'cards keep their ratio' );

		wp_set_current_user( $this->agent );
		$result = self::call( 'leitvia/set-post-details', array( 'related_heading' => '' ) );
		wp_set_current_user( $this->admin );
		Packages::apply( (int) $result['package'] );
		$this->assertSame( 'Related articles', Leitvia\post_details()['related_heading'] );
		$this->assertSame( 'As of %s', Leitvia\post_details()['updated_label'], 'the other fields stay' );
		wp_set_current_user( $this->agent );
		$this->assertWPError( self::call( 'leitvia/set-post-details', array( 'updated_label' => 'As of today' ) ) );
		$this->assertWPError( self::call( 'leitvia/set-post-details', array() ) );
	}

	/**
	 * Name and description of a category change through the pipeline with paragraphs and h2 kept; an h1 in the
	 * description and a product category are refused; the audit reports a description with an h1 written another way.
	 */
	public function test_update_term(): void {
		$cat    = self::factory()->category->create( array( 'name' => 'Museums' ) );
		$list   = self::call(
			'leitvia/list-content',
			array(
				'terms_of' => 'category',
				'limit'    => 1,
			)
		);
		$entry  = current( wp_list_filter( $list['term_list'], array( 'id' => $cat ) ) );
		$intro  = '<p>All museums of the city.</p><h2>Open today</h2><p>Hours change on holidays.</p>';
		$result = self::call(
			'leitvia/update-term',
			array(
				'term'        => $cat,
				'name'        => 'Museums in town',
				'description' => $intro,
				'base_hash'   => $entry['hash'],
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'live', $result['mode'] );
		$term = get_term( $cat );
		$this->assertSame( 'Museums in town', $term->name );
		$this->assertSame( $intro, $term->description, 'paragraphs and headings stay' );
		$this->assertSame( 'museums', $term->slug, 'the address stays' );

		$this->assertWPError(
			self::call(
				'leitvia/update-term',
				array(
					'term'        => $cat,
					'description' => '<h1>Museums</h1>',
					'base_hash'   => Hashes::term( $cat ),
				)
			)
		);
		$this->assertWPError( self::call( 'leitvia/update-term', array( 'term' => $cat ) ) );
		$this->assertWPError( self::call( 'leitvia/update-term', array( 'term' => self::factory()->term->create( array( 'taxonomy' => 'nav_menu' ) ) ) ) );

		// A description with an h1 written past the gateway, as an import or a person with unfiltered_html would.
		remove_filter( 'pre_term_description', 'wp_filter_kses' );
		wp_update_term( $cat, 'category', array( 'description' => '<h1>Pasted</h1>' ) );
		add_filter( 'pre_term_description', 'wp_filter_kses' );
		$found = wp_list_filter( LeitviaStudio\Gateway\Abilities\Read_Audit::site(), array( 'check' => 'leitvia/term-heading' ) );
		$this->assertCount( 1, $found );
		$this->assertStringContainsString( 'Museums in town (category)', current( $found )['message'] );
	}

	/**
	 * A group of a header part takes ink, as a dark header or bar; the same edit outside a header is refused.
	 */
	public function test_header_group_takes_ink(): void {
		$header = (string) WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia/header-default' )['content'];
		$edit   = array(
			array(
				'block'      => 'p:0',
				'attributes' => array( 'backgroundColor' => 'ink' ),
			),
		);
		$dark   = Build::edit( $header, $edit, array( 'part' => 'header' ) );
		$this->assertIsString( $dark, is_wp_error( $dark ) ? $dark->get_error_message() : '' );
		$this->assertStringContainsString( 'has-ink-background-color', $dark );
		$this->assertStringNotContainsString( 'has-surface-background-color', $dark );
		$light = array(
			array(
				'block'      => 'p:0',
				'attributes' => array( 'backgroundColor' => 'surface' ),
			),
		);
		$back  = Build::edit( $dark, $light, array( 'part' => 'header' ) );
		$this->assertIsString( $back, 'a dark header turns light again' );
		$this->assertWPError( Build::edit( $header, $edit, array( 'part' => 'footer' ) ) );
		$this->assertWPError( Build::edit( $header, $edit, array( 'post' => self::factory()->post->create( array( 'post_type' => 'page' ) ) ) ) );

		$this->assertNotNull( WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia/header-two-rows' ) );
		$two = (string) WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia/header-two-rows' )['content'];
		$this->assertStringContainsString( 'leitvia-header-top', $two );
		$this->assertStringContainsString( 'has-ink-background-color', $two );
	}
}
