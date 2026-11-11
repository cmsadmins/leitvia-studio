<?php
/**
 * Child theme tests: the contract that extends the parent theme and the switch without losing the design.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Contract\Contract;
use LeitviaStudio\Gateway\Composition;
use LeitviaStudio\Tokens\Compiler;

/**
 * Contract::for_active_theme() with "extends" and Contract\Child_Theme on switch_theme.
 */
class Test_Child_Theme extends WP_UnitTestCase {

	/**
	 * Contract fixtures.
	 */
	private const FIXTURES = __DIR__ . '/../fixtures/contract/';

	/**
	 * Theme root with the child theme leitvia-child-test (Template: leitvia).
	 */
	private const THEMES = __DIR__ . '/../fixtures/themes';

	/**
	 * Child theme of the fixture.
	 */
	private const CHILD = 'leitvia-child-test';

	/**
	 * Theme roots before the test.
	 *
	 * @var array<string>
	 */
	private array $roots = array();

	/**
	 * Registers the fixture theme root and activates Leitvia.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->roots = (array) $GLOBALS['wp_theme_directories'];
		register_theme_directory( self::THEMES );
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( 'leitvia' );
	}

	/**
	 * Restores the theme roots and drops the cached contract.
	 */
	public function tear_down(): void {
		switch_theme( 'leitvia' );
		$GLOBALS['wp_theme_directories'] = $this->roots; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the roots of the test site.
		search_theme_directories( true );
		wp_clean_themes_cache();
		\LeitviaStudio\Plugin::forget_contract();
		parent::tear_down();
	}

	/**
	 * Contract from a child theme contract and the contract of Leitvia.
	 *
	 * @param string $child Path of the child theme contract.
	 */
	private function contract_with( string $child ): Contract {
		add_filter( 'leitvia_studio_contract_path', static fn() => array( $child, get_template_directory() . '/leitvia-contract.json' ) );
		return Contract::for_active_theme();
	}

	/**
	 * A child theme contract file with the given data.
	 *
	 * @param array<string, mixed> $data Contract data.
	 */
	private function child_file( array $data ): string {
		$path = wp_tempnam( 'leitvia-child-contract' );
		file_put_contents( $path, (string) wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $path;
	}

	/**
	 * The patterns of the child theme join those of Leitvia; a slug of Leitvia keeps its entry.
	 */
	public function test_extends_adds_child_patterns(): void {
		$parent   = Contract::from_file( get_template_directory() . '/leitvia-contract.json' );
		$contract = $this->contract_with( self::FIXTURES . 'child.json' );

		$this->assertTrue( $contract->is_valid(), $contract->error() );
		$this->assertCount( count( $parent->patterns() ) + 1, $contract->patterns() );
		$this->assertSame( 'Starter section', $contract->pattern( 'leitvia-child-test/content-starter' )['title'] ?? null );
		$this->assertSame( $parent->pattern( 'leitvia/cta-banner' ), $contract->pattern( 'leitvia/cta-banner' ) );
		$this->assertSame( $parent->color_roles(), $contract->color_roles() );
		$this->assertSame( $parent->pairs(), $contract->pairs() );
	}

	/**
	 * The ability leitvia/list-patterns reads the patterns of the contract, so a registered child theme pattern is listed
	 * as a theme pattern next to those of Leitvia.
	 */
	public function test_list_patterns_includes_child_patterns(): void {
		$slug = 'leitvia-child-test/content-starter';
		register_block_pattern(
			$slug,
			array(
				'title'   => 'Starter section',
				'content' => '<!-- wp:paragraph --><p>Starter</p><!-- /wp:paragraph -->',
			)
		);
		add_filter( 'leitvia_studio_contract_path', static fn() => array( self::FIXTURES . 'child.json', get_template_directory() . '/leitvia-contract.json' ) );
		\LeitviaStudio\Plugin::forget_contract();

		$items = array();
		for ( $page = 1, $pages = 1; $page <= $pages; $page++ ) {
			$result = \LeitviaStudio\Gateway\Abilities\Read_Design::list_patterns(
				array(
					'per_page' => 50,
					'page'     => $page,
				)
			);
			$pages  = $result['pages'];
			$items  = array_merge( $items, $result['items'] );
		}
		unregister_block_pattern( $slug );
		$by_slug = array_column( $items, null, 'slug' );

		$this->assertSame( 'theme', $by_slug[ $slug ]['source'] ?? null );
		$this->assertSame( 'theme', $by_slug['leitvia/cta-banner']['source'] ?? null );
	}

	/**
	 * A child theme pattern listed in the child theme contract is no unknownPattern.
	 */
	public function test_child_pattern_is_known_to_the_composition_check(): void {
		$slugs = array( 'leitvia-child-test/content-starter' );
		$rules = static fn( Contract $contract ): array => array_column( Composition::check( $slugs, $contract->data() ), 'rule' );

		$this->assertContains( 'unknownPattern', $rules( Contract::from_file( get_template_directory() . '/leitvia-contract.json' ) ) );
		$this->assertNotContains( 'unknownPattern', $rules( $this->contract_with( self::FIXTURES . 'child.json' ) ) );
	}

	/**
	 * Without the contract it extends, a child theme contract is found but not valid.
	 */
	public function test_extends_without_parent_contract(): void {
		add_filter( 'leitvia_studio_contract_path', static fn() => array( self::FIXTURES . 'child.json', self::FIXTURES . 'missing.json' ) );
		$contract = Contract::for_active_theme();
		$this->assertTrue( $contract->exists() );
		$this->assertFalse( $contract->is_valid() );
		$this->assertStringContainsString( 'leitvia', $contract->error() );

		$this->assertFalse( Contract::from_file( self::FIXTURES . 'child.json' )->is_valid(), 'Valid only together with its parent.' );
	}

	/**
	 * A child theme contract that extends another theme or has broken patterns shows its error.
	 */
	public function test_extends_other_theme_or_broken_patterns(): void {
		$cases = array(
			'other'    => array(
				'extends'  => 'twentytwentyfive',
				'patterns' => array( array( 'slug' => 'x/y' ) ),
			),
			'patterns' => array(
				'extends'  => 'leitvia',
				'patterns' => array( array( 'title' => 'No slug' ) ),
			),
			'extends'  => array(
				'extends'  => array( 'leitvia' ),
				'patterns' => array(),
			),
		);
		foreach ( $cases as $case => $data ) {
			$path = $this->child_file( $data );
			\LeitviaStudio\Plugin::forget_contract();
			remove_all_filters( 'leitvia_studio_contract_path' );
			$contract = $this->contract_with( $path );
			wp_delete_file( $path );
			$this->assertTrue( $contract->exists(), $case );
			$this->assertFalse( $contract->is_valid(), $case );
			$this->assertStringContainsString( 'other' === $case ? 'twentytwentyfive' : $case, $contract->error(), $case );
		}
	}

	/**
	 * Posts of a type that belong to a stylesheet.
	 *
	 * @param string $type       Post type.
	 * @param string $stylesheet Stylesheet.
	 * @return list<WP_Post>
	 */
	private function posts( string $type, string $stylesheet ): array {
		return array_values(
			get_posts(
				array(
					'post_type'   => $type,
					'post_status' => 'any',
					'numberposts' => -1,
					'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Test.
						array(
							'taxonomy' => 'wp_theme',
							'field'    => 'name',
							'terms'    => $stylesheet,
						),
					),
				)
			)
		);
	}

	/**
	 * A post of the given type for a stylesheet.
	 *
	 * @param string $type       Post type.
	 * @param string $name       Post name.
	 * @param string $content    Content.
	 * @param string $stylesheet Stylesheet.
	 */
	private function saved( string $type, string $name, string $content, string $stylesheet = 'leitvia' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => $type,
				'post_name'    => $name,
				'post_title'   => $name,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		wp_set_object_terms( $id, $stylesheet, 'wp_theme' );
		return $id;
	}

	/**
	 * Global styles, templates and parts of Leitvia reach the child theme; the originals stay.
	 */
	public function test_switch_copies_styles_templates_and_parts(): void {
		$styles = '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"text":"var:preset|color|ink"}}}';
		$source = $this->saved( 'wp_global_styles', 'wp-global-styles-leitvia', $styles );
		$this->saved( 'wp_template', 'index', '<!-- wp:template-part {"slug":"header","theme":"leitvia","tagName":"header"} /--><!-- wp:paragraph --><p>"theme":"leitvia"</p><!-- /wp:paragraph -->' );
		$part = $this->saved( 'wp_template_part', 'header', '<!-- wp:site-title /-->' );
		wp_set_object_terms( $part, 'header', 'wp_template_part_area' );
		add_post_meta( $part, 'origin', 'theme' );
		update_option(
			Compiler::HASH_OPTION,
			array(
				'theme' => 'leitvia',
				'hash'  => 'h',
			)
		);

		switch_theme( self::CHILD );

		$copy = $this->posts( 'wp_global_styles', self::CHILD );
		$this->assertCount( 1, $copy );
		$this->assertSame( get_post_field( 'post_content', $source ), $copy[0]->post_content );
		$this->assertStringContainsString( '--wp--preset--color--ink', $copy[0]->post_content, 'Core stores global styles sanitized; the copy keeps that content.' );
		$this->assertSame( 'wp-global-styles-' . self::CHILD, $copy[0]->post_name );
		$this->assertSame( $copy[0]->ID, WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() )['ID'] ?? null, 'Core finds the copy for the child theme.' );

		$template = $this->posts( 'wp_template', self::CHILD );
		$this->assertCount( 1, $template );
		$this->assertSame( 'index', $template[0]->post_name );
		$this->assertStringContainsString( '"theme":"' . self::CHILD . '"', $template[0]->post_content, 'The part block names the child theme.' );
		$this->assertStringContainsString( '<p>"theme":"leitvia"</p>', $template[0]->post_content, 'Only template part blocks change.' );
		$this->assertSame( self::CHILD . '//index', get_block_template( self::CHILD . '//index' )->id ?? null );

		$parts = $this->posts( 'wp_template_part', self::CHILD );
		$this->assertCount( 1, $parts );
		$this->assertSame( array( 'header' ), wp_get_object_terms( $parts[0]->ID, 'wp_template_part_area', array( 'fields' => 'names' ) ) );
		$this->assertSame( 'theme', get_post_meta( $parts[0]->ID, 'origin', true ) );

		$this->assertSame( self::CHILD, get_option( Compiler::HASH_OPTION )['theme'] ?? null, 'The applied hash follows the copied styles.' );
		$this->assertCount( 1, $this->posts( 'wp_global_styles', 'leitvia' ), 'The original stays.' );
		$this->assertCount( 1, $this->posts( 'wp_template', 'leitvia' ) );
	}

	/**
	 * Entries the child theme already has are never overwritten, and switching back copies nothing.
	 */
	public function test_switch_keeps_what_the_target_has(): void {
		// The child theme first: core makes a template slug unique within the active theme while the post has no term.
		$own = $this->saved( 'wp_global_styles', 'wp-global-styles-' . self::CHILD, '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"text":"var:preset|color|ink"}}}', self::CHILD );
		$this->saved( 'wp_template', 'index', 'child index', self::CHILD );
		$this->saved( 'wp_global_styles', 'wp-global-styles-leitvia', '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"text":"var:preset|color|accent"}}}' );
		$this->saved( 'wp_template', 'index', 'parent index' );
		$this->saved( 'wp_template', 'single', 'parent single' );

		switch_theme( self::CHILD );

		$this->assertSame( array( get_post_field( 'post_content', $own ) ), wp_list_pluck( $this->posts( 'wp_global_styles', self::CHILD ), 'post_content' ), 'The styles of the child theme stay, the ones of Leitvia (accent) do not replace them.' );
		$contents = wp_list_pluck( $this->posts( 'wp_template', self::CHILD ), 'post_content' );
		sort( $contents );
		$this->assertSame( array( 'child index', 'parent single' ), $contents );

		switch_theme( 'leitvia' );

		$contents = wp_list_pluck( $this->posts( 'wp_template', 'leitvia' ), 'post_content' );
		sort( $contents );
		$this->assertSame( array( 'parent index', 'parent single' ), $contents, 'Back on Leitvia, nothing is overwritten or added.' );
		$this->assertCount( 1, $this->posts( 'wp_global_styles', 'leitvia' ) );
	}
}
