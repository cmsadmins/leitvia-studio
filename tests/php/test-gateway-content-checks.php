<?php
/**
 * Content checks of the gateway and the site audit.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Operation;
use LeitviaStudio\Gateway\Pipeline;

/**
 * Each check passes its good fixture and finds its bad one; registry, pipeline and audit-site.
 */
class Test_Gateway_Content_Checks extends WP_UnitTestCase {

	/**
	 * Leitvia theme, agent user and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		wp_clean_theme_json_cache();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		delete_option( Gateway::OPTION );
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Drops the cached theme.json data of the theme.
	 */
	public function tear_down(): void {
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * Fixture markup.
	 *
	 * @param string $name File name without .html.
	 */
	private static function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . "/fixtures/gateway/checks/{$name}.html" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
	}

	/**
	 * Findings of one check as "severity block" strings.
	 *
	 * @param string               $check   Check name.
	 * @param string               $content Markup.
	 * @param array<string, mixed> $context Context.
	 * @return list<string>
	 */
	private static function run_check( string $check, string $content, array $context = array() ): array {
		$entry    = leitvia_studio()->registry( 'checks' )->get( $check );
		$findings = call_user_func(
			$entry['callback'],
			$content,
			$context + array(
				'post'      => 0,
				'post_type' => 'post',
				'ability'   => 'leitvia/test',
			)
		);
		foreach ( $findings as $finding ) {
			\PHPUnit\Framework\Assert::assertSame( array( 'check', 'severity', 'block', 'message', 'fix' ), array_keys( $finding ) );
			\PHPUnit\Framework\Assert::assertSame( $check, $finding['check'] );
		}
		return array_map( static fn( array $f ): string => $f['severity'] . ' ' . $f['block'], $findings );
	}

	/**
	 * The good fixture passes, the bad one gives the expected findings.
	 *
	 * @dataProvider provide_fixtures
	 *
	 * @param string   $check    Check name.
	 * @param string   $fixture  Fixture prefix.
	 * @param string[] $expected Findings of the bad fixture.
	 * @param string   $type     Post type of the context.
	 */
	public function test_fixtures( string $check, string $fixture, array $expected, string $type = 'post' ): void {
		$context = array( 'post_type' => $type );
		$this->assertSame( array(), self::run_check( $check, self::fixture( "{$fixture}-good" ), $context ), 'good' );
		$this->assertSame( $expected, self::run_check( $check, self::fixture( "{$fixture}-bad" ), $context ), 'bad' );
	}

	/**
	 * Checks, fixtures and expected findings.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function provide_fixtures(): array {
		return array(
			'headings'       => array( 'leitvia/headings', 'headings', array( 'error head0001', 'error p:1', 'error p:2' ) ),
			'alt-text'       => array( 'leitvia/alt-text', 'alt-text', array( 'error img00001', 'error p:1', 'warning p:2', 'error p:3.0' ) ),
			'link-text'      => array( 'leitvia/link-text', 'link-text', array( 'error par00001', 'error p:1', 'error p:1', 'error p:2.0' ) ),
			'block-validity' => array( 'leitvia/block-validity', 'block-validity', array( 'error htm00001', 'error p:1', 'error p:2', 'error p:3' ) ),
			'tokens'         => array( 'leitvia/content-tokens', 'content-tokens', array( 'error grp00001', 'error grp00001', 'error p:0.0', 'error p:0.0' ) ),
			'contrast'       => array( 'leitvia/content-contrast', 'content-contrast', array( 'error grp00001' ) ),
			'page-structure' => array( 'leitvia/page-structure', 'page-structure', array( 'error ' ), 'page' ),
		);
	}

	/**
	 * Studio registers its checks with the scopes content and page; post types without the editor are skipped.
	 */
	public function test_registry(): void {
		$checks = leitvia_studio()->registry( 'checks' );
		$this->assertSame(
			array( 'leitvia/headings', 'leitvia/alt-text', 'leitvia/link-text', 'leitvia/block-validity', 'leitvia/content-tokens', 'leitvia/content-contrast' ),
			array_keys( $checks->all( array( 'scope' => 'content' ) ) )
		);
		$this->assertSame( array( 'leitvia/page-structure', 'leitvia/composition' ), array_keys( $checks->all( array( 'scope' => 'page' ) ) ) );
		$this->assertSame( array(), Content_Checks::run( self::fixture( 'block-validity-bad' ), array( 'post_type' => 'leitvia_tokens' ) ) );
	}

	/**
	 * A template without the title lets the content bring the one h1; without it the page has none.
	 */
	public function test_landing_template(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'meta_input' => array( '_wp_page_template' => 'page-landing' ),
			)
		);
		$h1   = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Hero</h1><!-- /wp:heading -->';
		$this->assertSame(
			'page-landing',
			Content_Checks::template(
				array(
					'post'      => $page,
					'post_type' => 'page',
				)
			)->slug
		);
		$this->assertSame(
			array(),
			self::run_check(
				'leitvia/headings',
				$h1,
				array(
					'post'      => $page,
					'post_type' => 'page',
				)
			)
		);
		$this->assertSame(
			array(),
			self::run_check(
				'leitvia/page-structure',
				$h1,
				array(
					'post'      => $page,
					'post_type' => 'page',
				)
			)
		);
		$this->assertSame(
			array( 'error ' ),
			self::run_check(
				'leitvia/page-structure',
				self::fixture( 'page-structure-good' ),
				array(
					'post'      => $page,
					'post_type' => 'page',
				)
			)
		);
	}

	/**
	 * Missing landmarks in the template are warnings.
	 */
	public function test_landmarks(): void {
		$strip = static function ( $template ) {
			if ( $template ) {
				$template->content = '<!-- wp:post-title {"level":1} /--><!-- wp:post-content /-->';
			}
			return $template;
		};
		add_filter( 'get_block_template', $strip );
		$found = self::run_check( 'leitvia/page-structure', self::fixture( 'page-structure-good' ), array( 'post_type' => 'page' ) );
		remove_filter( 'get_block_template', $strip );
		$this->assertSame( array( 'warning ', 'warning ', 'warning ' ), $found );
	}

	/**
	 * A deprecated catalog block warns, a Studio block that fails to render is an error.
	 */
	public function test_catalog_blocks(): void {
		register_block_type(
			'leitvia-test/broken',
			array(
				'render_callback' => static function (): string {
					trigger_error( 'broken', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Test.
					return '';
				},
			)
		);
		$catalog = static fn( array $entries ): array => array_merge(
			$entries,
			array(
				array(
					'name'     => 'leitvia-test/broken',
					'owner'    => 'leitvia-test',
					'supports' => array( 'inserter' => false ),
					'parent'   => null,
					'ancestor' => null,
				),
			)
		);
		add_filter( 'leitvia_studio_block_catalog', $catalog );
		$found   = self::run_check( 'leitvia/block-validity', '<!-- wp:leitvia-test/broken /-->' );
		remove_filter( 'leitvia_studio_block_catalog', $catalog );
		unregister_block_type( 'leitvia-test/broken' );
		$this->assertSame( array( 'warning p:0', 'error p:0' ), $found );
	}

	/**
	 * The pipeline rejects a write with an error finding and names the check.
	 */
	public function test_pipeline(): void {
		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_content' => self::fixture( 'headings-good' ),
			)
		);
		$change = static fn( array $state ): array => array( 'post_content' => self::fixture( 'link-text-bad' ) ) + $state;
		$result = Pipeline::run( new Operation( 'leitvia/test-write', 'content', array( 'post' => $page ), Hashes::document( $page ), $change ) );
		$this->assertWPError( $result );
		$this->assertSame( 'leitvia_check_failed', $result->get_error_code() );
		$this->assertContains( 'leitvia/link-text', array_column( $result->get_error_data()['findings'], 'check' ) );
	}

	/**
	 * Images in classic HTML need alt text unless marked decorative, and audit-site reports classic content as such.
	 */
	public function test_classic_content(): void {
		$classic = "<p>Text</p>\n\n<img src=\"https://example.org/a.jpg\">\n\n[caption]<img src=\"https://example.org/b.jpg\" alt=\"\"> Caption[/caption]\n\n"
			. '<img src="https://example.org/c.jpg" alt="" role="presentation"><img src="https://example.org/d.jpg" alt="A loaf">';
		$this->assertSame( array( 'error p:0', 'warning p:0' ), self::run_check( 'leitvia/alt-text', $classic ) );

		$post  = self::factory()->post->create( array( 'post_content' => $classic ) );
		$items = wp_get_ability( 'leitvia/audit-site' )->execute( array( 'after' => $post - 1 ) )['items'];
		$this->assertSame( $post, $items[0]['post'] );
		$this->assertSame( array( 'leitvia/alt-text', 'leitvia/alt-text', 'leitvia/classic-content' ), array_column( $items[0]['findings'], 'check' ) );
	}

	/**
	 * The ability audit-site pages through published content with a cursor, reports audit-only findings and keeps memory flat.
	 */
	public function test_audit_site(): void {
		$pattern = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
				'meta_input'   => array( '_leitvia_lab_status' => 'deprecated' ),
			)
		);
		$first   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'impressum',
				'post_content' => '<!-- wp:block {"ref":' . $pattern . '} /--><!-- wp:paragraph {"backgroundColor":"no-such-role"} --><p class="has-no-such-role-background-color has-background">Text</p><!-- /wp:paragraph -->',
			)
		);
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create( array( 'post_status' => 'private' ) );
		$published = self::factory()->post->create_many( 22, array( 'post_content' => self::fixture( 'headings-good' ) ) );
		$ability   = wp_get_ability( 'leitvia/audit-site' );

		$before = memory_get_usage();
		$page   = $ability->execute(
			array(
				'after' => $first - 1,
				'limit' => 20,
			)
		);
		$this->assertLessThan( 8 * MB_IN_BYTES, memory_get_usage() - $before );
		$this->assertCount( 20, $page['items'] );
		$this->assertSame( $first, $page['items'][0]['post'] );
		$this->assertSame( array( 'leitvia/deprecated-pattern', 'leitvia/token-drift', 'leitvia/page-kind' ), array_column( $page['items'][0]['findings'], 'check' ) );
		$this->assertSame( array(), $page['items'][1]['findings'] );
		$this->assertSame( $published[18], $page['next'] );

		$rest = $ability->execute( array( 'after' => $page['next'] ) );
		$this->assertSame( array_slice( $published, 19 ), array_column( $rest['items'], 'post' ) );
		$this->assertNull( $rest['next'] );
		$this->assertWPError( $ability->execute( array( 'limit' => 21 ) ) );
	}
}
