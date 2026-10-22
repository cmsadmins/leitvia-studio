<?php
/**
 * Gateway composition check: parity with the Node checker, the ability and the check in the pipeline.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Abilities\Read_Composition;
use LeitviaStudio\Gateway\Composition;
use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Operation;
use LeitviaStudio\Gateway\Pipeline;

/**
 * Same fixtures as tests/unit/composition.test.mjs.
 */
class Test_Gateway_Composition extends WP_UnitTestCase {

	private const FIXTURES = __DIR__ . '/../fixtures/composition/';

	/**
	 * Leitvia theme, an agent at "autonomous" in the structure area.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		update_option(
			Gateway::OPTION,
			array(
				'overrides' => array(
					'areas' => array(
						'content'   => 'autonomous',
						'structure' => 'autonomous',
					),
					'kinds' => Gateway::KINDS,
				),
			) + Gateway::DEFAULTS
		);
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
	}

	/**
	 * Removes the abilities and the category.
	 */
	public function tear_down(): void {
		self::unregister();
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
	 * Contract data of the theme.
	 *
	 * @return array<string, mixed>
	 */
	private static function contract(): array {
		return leitvia_studio_contract()->data();
	}

	/**
	 * Fixtures by file name.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function provide_fixtures(): array {
		$cases = array();
		foreach ( glob( self::FIXTURES . '*.json' ) as $file ) {
			$cases[ basename( $file, '.json' ) ] = array( json_decode( (string) file_get_contents( $file ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		}
		return $cases;
	}

	/**
	 * Same findings (rule, index) and score as the Node checker for every fixture.
	 *
	 * @dataProvider provide_fixtures
	 *
	 * @param array<string, mixed> $fixture Fixture.
	 */
	public function test_fixture_parity( array $fixture ): void {
		$contract = array( 'labPatterns' => $fixture['labPatterns'] ?? array() ) + self::contract();
		$result   = Composition::score( $fixture['sections'], $contract, (string) ( $fixture['template'] ?? '' ) );
		$this->assertSame( $fixture['issues'], array_map( static fn( array $issue ): array => array( $issue['rule'], $issue['index'] ), $result['issues'] ) );
		$this->assertSame( $fixture['score'], $result['score'] );
	}

	/**
	 * Every example of the contract has a fixture and reaches the minimum score.
	 */
	public function test_examples_reach_minimum(): void {
		$sections = array_map( static fn( array $fixture ): array => $fixture[0]['sections'], self::provide_fixtures() );
		foreach ( self::contract()['examples'] as $example ) {
			$this->assertContains( $example['sections'], $sections, $example['name'] );
			$this->assertGreaterThanOrEqual( 80, Composition::score( $example['sections'], self::contract(), 'page-landing' )['score'], $example['name'] );
		}
	}

	/**
	 * Messages read like the Node ones.
	 */
	public function test_messages(): void {
		$issues = Composition::check( array( 'leitvia/hero-centered', 'leitvia/content-text-media', 'leitvia/content-two-columns' ), self::contract() );
		$this->assertSame( 'Sections 2 and 3 are both split/surface; give one of them another tone or layout.', $issues[0]['message'] );
		$issues = Composition::check( array( 'leitvia/hero-split-image', 'leitvia/content-feature-grid', 'leitvia/content-faq', 'leitvia/teaser-services', 'leitvia/content-steps' ), self::contract() );
		$this->assertSame( 'Sections 1 to 4 have no break; add one of: tone image, accent or ink; layout full-bleed; pattern leitvia/content-quote.', $issues[0]['message'] );
	}

	/**
	 * Sections of saved markup: patternName of root blocks, block names otherwise, template parts skipped; markup
	 * without any pattern is no composed page.
	 */
	public function test_sections_from_markup(): void {
		$markup = '<!-- wp:template-part {"slug":"header"} /-->'
			. "\n\n" . '<!-- wp:group {"metadata":{"patternName":"leitvia/hero-centered"}} --><div class="wp-block-group"></div><!-- /wp:group -->'
			. "\n\n" . '<!-- wp:paragraph --><p>Lead</p><!-- /wp:paragraph -->'
			. "\n\n" . '<!-- wp:group {"metadata":{"patternName":"leitvia/cta-banner"}} --><div class="wp-block-group"></div><!-- /wp:group -->';
		$this->assertSame( array( 'leitvia/hero-centered', 'core/paragraph', 'leitvia/cta-banner' ), Composition::sections( $markup ) );
		$this->assertSame( array(), Composition::sections( '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->' ) );
	}

	/**
	 * The ability scores slugs or a saved page and names patterns that fix a finding.
	 */
	public function test_ability(): void {
		$ability = wp_get_ability( 'leitvia/check-composition' );
		$this->assertNotNull( $ability );
		$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );

		$result = $ability->execute( array( 'slugs' => array( 'leitvia/hero-centered', 'leitvia/content-text-media', 'leitvia/content-two-columns', 'leitvia/cta-banner' ) ) );
		$this->assertSame( 90, $result['score'] );
		$this->assertSame( 80, $result['min_score'] );
		$suggestion = $result['suggestions'][0];
		$this->assertSame( array( 'noAdjacentRepeat', 2 ), array( $suggestion['rule'], $suggestion['index'] ) );
		$this->assertNotEmpty( $suggestion['replace_with'] );
		$this->assertLessThanOrEqual( 3, count( $suggestion['replace_with'] ) );
		$this->assertStringContainsString( 'Replace section 3 with', $suggestion['message'] );
		foreach ( $suggestion['replace_with'] as $slug ) {
			$try    = array( 'leitvia/hero-centered', 'leitvia/content-text-media', $slug, 'leitvia/cta-banner' );
			$better = Composition::score( $try, self::contract() );
			$this->assertGreaterThan( 90, $better['score'], $slug );
		}

		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => self::page_markup( array( 'leitvia/hero-centered', 'leitvia/content-quote', 'leitvia/cta-contact' ) ),
				'meta_input'   => array( '_wp_page_template' => 'page' ),
			)
		);
		$result = $ability->execute( array( 'post' => $page ) );
		$this->assertSame( array( 'leitvia/hero-centered', 'leitvia/content-quote', 'leitvia/cta-contact' ), $result['sections'] );
		$this->assertSame( array( 'heroTemplate' ), array_column( $result['issues'], 'rule' ) );

		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$this->assertSame( 'leitvia_out_of_scope', Read_Composition::check( array( 'post' => $private ) )->get_error_code() );
		$this->assertSame( 'leitvia_invalid_input', Read_Composition::check( array( 'slugs' => array() ) )->get_error_code() );
	}

	/**
	 * Section patterns of an add-on (registry patterns) join the contract data as addonPatterns, so composition knows
	 * them; an entry outside the areas of the contract is refused.
	 */
	public function test_addon_patterns(): void {
		$this->setExpectedIncorrectUsage( 'LeitviaStudio\Extension\Registry::register' );
		$composition = array(
			'tone'    => 'surface',
			'density' => 'dense',
			'layout'  => 'list',
			'weight'  => 'medium',
			'role'    => 'detail',
			'width'   => 'wide',
		);
		$register    = static function ( \LeitviaStudio\Extension\Studio $studio ) use ( $composition ): void {
			$studio->extensions()->register(
				'test-addon',
				array(
					'label'           => 'Test add-on',
					'version'         => '1.0.0',
					'requires_studio' => '0.1.0',
					'requires_api'    => LEITVIA_STUDIO_API,
				)
			);
			$studio->registry( 'patterns' )->register(
				'test-addon/events',
				array(
					'title'       => 'Events',
					'description' => 'Upcoming events.',
					'area'        => 'content',
					'composition' => $composition,
				),
				'test-addon'
			);
			$studio->registry( 'patterns' )->register(
				'test-addon/header',
				array(
					'title'       => 'Header',
					'description' => 'A header.',
					'area'        => 'header',
					'composition' => $composition,
				),
				'test-addon'
			);
		};
		add_action( 'leitvia_studio_init', $register, 20 );
		leitvia_studio()->reset_for_tests();
		remove_action( 'leitvia_studio_init', $register, 20 );
		$addon = leitvia_studio_contract()->data()['addonPatterns'];
		$this->assertSame( array( 'test-addon/events' ), array_column( $addon, 'slug' ) );
		$this->assertSame( $composition, $addon[0]['composition'] );
		$this->assertArrayNotHasKey( 'owner', $addon[0] );
		$slugs = array( 'leitvia/hero-centered', 'test-addon/events', 'leitvia/cta-contact' );
		$this->assertNotContains( 'unknownPattern', array_column( Composition::check( $slugs, leitvia_studio_contract()->data() ), 'rule' ) );
		$theme = array_diff_key( leitvia_studio_contract()->data(), array( 'addonPatterns' => true ) );
		$this->assertContains( 'unknownPattern', array_column( Composition::check( $slugs, $theme ), 'rule' ) );
	}

	/**
	 * Approved Lab patterns count as known sections, with the composition of the theme pattern they vary; drafts stay
	 * unknown. compose-page names Lab sections core/block/<ID> as core does, and sections() reads them as block:<ID>.
	 */
	public function test_lab_patterns(): void {
		$lab      = fn( string $status, array $meta = array() ): int => self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Lab</p><!-- /wp:paragraph -->',
				'meta_input'   => array( '_leitvia_lab_status' => $status ) + $meta,
			)
		);
		$grid     = $lab( 'approved', array( '_leitvia_lab_source_pattern' => 'leitvia/content-feature-grid' ) );
		$variant  = $lab( 'approved', array( '_leitvia_lab_variant_of' => $grid ) );
		$own      = $lab( 'approved' );
		$draft    = $lab( 'draft' );
		$slugs    = array( 'leitvia/hero-centered', 'block:' . $grid, 'block:' . $own, 'block:' . $draft, 'leitvia/cta-contact' );
		$contract = Composition::lab( $slugs, self::contract() );
		$this->assertSame( array( 'block:' . $grid, 'block:' . $own ), array_column( $contract['labPatterns'], 'slug' ) );
		$this->assertSame( array_column( self::contract()['patterns'], 'composition', 'slug' )['leitvia/content-feature-grid'], $contract['labPatterns'][0]['composition'] );
		$this->assertArrayNotHasKey( 'composition', $contract['labPatterns'][1] );
		$this->assertSame( $contract['labPatterns'][0]['composition'], Composition::lab( array( 'block:' . $variant ), self::contract() )['labPatterns'][0]['composition'] );

		$result = Read_Composition::check( array( 'slugs' => $slugs ) );
		$this->assertSame( array( array( 'unknownPattern', 3 ) ), array_map( static fn( array $issue ): array => array( $issue['rule'], $issue['index'] ), $result['issues'] ) );

		$markup = '<!-- wp:group {"metadata":{"patternName":"leitvia/hero-centered"}} --><div class="wp-block-group"></div><!-- /wp:group -->'
			. '<!-- wp:paragraph {"metadata":{"patternName":"core/block/' . $grid . '"}} --><p>Lab</p><!-- /wp:paragraph -->';
		$this->assertSame( array( 'leitvia/hero-centered', 'block:' . $grid ), Composition::sections( $markup ) );
		$this->assertSame( array(), Composition::page( $markup, array( 'post_type' => 'page' ) ) );
	}

	/**
	 * The check is registered with scope page; below the minimum it warns, and an autonomous write becomes a package.
	 * An existing page that scored low before and does not get worse is no warning.
	 */
	public function test_check_downgrades_autonomous_writes(): void {
		$this->assertArrayHasKey( Composition::CHECK, leitvia_studio()->registry( 'checks' )->all( array( 'scope' => 'page' ) ) );
		$good = self::page_markup( array( 'leitvia/hero-centered', 'leitvia/content-quote', 'leitvia/cta-contact' ) );
		// 75: an unknown section.
		$low = self::page_markup( array( 'leitvia/hero-centered', 'leitvia/no-such-pattern', 'leitvia/cta-contact' ) );
		$this->assertSame( array(), Composition::page( $good, array( 'post_type' => 'page' ) ) );
		$found = Composition::page( $low, array( 'post_type' => 'page' ) );
		$this->assertSame( 'warning', $found[0]['severity'] );
		$this->assertSame( 75, $found[0]['score'] );
		$this->assertSame( array(), Composition::page( $low, array( 'post_type' => 'leitvia_tokens' ) ) );

		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $good,
				'meta_input'   => array( '_wp_page_template' => 'page-landing' ),
			)
		);
		$result = Pipeline::run( new Operation( 'leitvia/update-content', 'content', array( 'post' => $page ), Hashes::document( $page ), static fn( array $state ): array => array( 'post_content' => $low ) + $state ) );
		$this->assertIsArray( $result, wp_json_encode( $result ) );
		$this->assertSame( 'package', $result['mode'] );
		$this->assertContains( Composition::CHECK, array_column( $result['findings'], 'check' ) );

		// The page already scores 75; a change that keeps the score goes live.
		$weak   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $low,
				'meta_input'   => array( '_wp_page_template' => 'page-landing' ),
			)
		);
		$result = Pipeline::run( new Operation( 'leitvia/update-content', 'content', array( 'post' => $weak ), Hashes::document( $weak ), static fn( array $state ): array => array( 'post_title' => 'Typo fixed' ) + $state ) );
		$this->assertSame( 'live', $result['mode'], wp_json_encode( $result ) );

		// The saved content itself, as audit-site checks it, still reports the low score.
		$saved = (string) get_post_field( 'post_content', $weak, 'raw' );
		$found = Content_Checks::run(
			$saved,
			array(
				'post'      => $weak,
				'post_type' => 'page',
				'ability'   => 'leitvia/audit-site',
			)
		);
		$this->assertContains( Composition::CHECK, array_column( $found, 'check' ) );
	}

	/**
	 * Markup of a page from patterns: one group per section with metadata.patternName and an h1 in the first.
	 *
	 * @param string[] $slugs Pattern slugs.
	 */
	private static function page_markup( array $slugs ): string {
		$parts = array();
		foreach ( $slugs as $index => $slug ) {
			$inner   = 0 === $index ? '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Title</h1><!-- /wp:heading -->' : '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->';
			$parts[] = '<!-- wp:group {"metadata":{"patternName":"' . $slug . '"}} --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
		}
		return implode( "\n\n", $parts );
	}
}
