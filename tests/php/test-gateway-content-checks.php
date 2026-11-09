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
			'block-validity' => array( 'leitvia/block-validity', 'block-validity', array( 'error htm00001', 'error p:2', 'error p:3' ) ),
			'tokens'         => array( 'leitvia/content-tokens', 'content-tokens', array( 'error grp00001', 'error grp00001', 'error p:0.0', 'error p:0.0' ) ),
			'contrast'       => array( 'leitvia/content-contrast', 'content-contrast', array( 'error grp00001' ) ),
			'page-structure' => array( 'leitvia/page-structure', 'page-structure', array( 'error ' ), 'page' ),
		);
	}

	/**
	 * A query filter or Load more outside a query, in an inheriting query or without queryId is an error; two queries
	 * with the same queryId and a filter are a warning.
	 */
	public function test_query_context(): void {
		$filter = '<!-- wp:leitvia/query-filter /-->';
		$query  = static fn( string $attrs, string $inner = '' ): string => '<!-- wp:query ' . $attrs . ' -->' . $inner . '<!-- /wp:query -->';
		$own    = '"query":{"perPage":3,"postType":"post","inherit":false}';
		$found  = static fn( string $content ): array => array_map(
			static fn( array $finding ): string => $finding['severity'] . ':' . $finding['block'],
			Content_Checks::query_context( $content, array( 'post_type' => 'page' ) )
		);

		$this->assertSame( array(), $found( $query( '{"queryId":7,' . $own . '}', $filter ) ) );
		$this->assertSame( array( 'error:p:0' ), $found( $filter ), 'outside a query' );
		$this->assertSame( array( 'error:p:0.0' ), $found( $query( '{' . $own . '}', $filter ) ), 'no queryId' );
		$this->assertSame( array( 'error:p:0.0' ), $found( $query( '{"queryId":7,"query":{"inherit":true}}', $filter ) ), 'inherits' );
		$this->assertSame( array( 'error:p:0.0' ), $found( $query( '{"queryId":7}', '<!-- wp:leitvia/load-more /-->' ) ), 'the default query inherits' );
		$this->assertSame( array( 'warning:p:0.0' ), $found( $query( '{"queryId":7,' . $own . '}', $filter ) . $query( '{"queryId":7,' . $own . '}' ) ), 'same queryId twice' );
	}

	/**
	 * Studio registers its checks with the scopes content and page; post types without the editor are skipped.
	 */
	public function test_registry(): void {
		$checks = leitvia_studio()->registry( 'checks' );
		$this->assertSame(
			array( 'leitvia/headings', 'leitvia/alt-text', 'leitvia/link-text', 'leitvia/block-validity', 'leitvia/unregistered', 'leitvia/shortcodes', 'leitvia/image-height', 'leitvia/content-tokens', 'leitvia/content-contrast', 'leitvia/query-context', 'leitvia/template-parts' ),
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
	 * A template without header or footer part or without main gets a warning each; a complete one and content none.
	 */
	public function test_template_parts(): void {
		$template = array( 'post_type' => 'wp_template' );
		$this->assertSame( array( 'warning ', 'warning ', 'warning ' ), self::run_check( 'leitvia/template-parts', '<!-- wp:post-content /-->', $template ) );
		$complete = '<!-- wp:template-part {"slug":"header-shop","tagName":"header"} /--><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:post-content /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer"} /-->';
		$this->assertSame( array(), self::run_check( 'leitvia/template-parts', $complete, $template ) );
		$this->assertSame( array( 'warning ' ), self::run_check( 'leitvia/template-parts', str_replace( '"tagName":"main"', '"tagName":"div"', $complete ), $template ) );
		$this->assertSame( array(), self::run_check( 'leitvia/template-parts', '<!-- wp:post-content /-->' ) );
	}

	/**
	 * In a template a block that takes the post from its context is not rendered for the check, in content it is.
	 */
	public function test_contextual_blocks_in_templates(): void {
		register_block_type(
			'leitvia-test/needs-post',
			array(
				'uses_context'    => array( 'postId' ),
				'render_callback' => static function ( array $attributes, string $content, \WP_Block $block ): string {
					if ( empty( $block->context['postId'] ) ) {
						trigger_error( 'no post', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Test.
					}
					return '';
				},
			)
		);
		$catalog  = static fn( array $entries ): array => array_merge(
			$entries,
			array(
				array(
					'name'     => 'leitvia-test/needs-post',
					'owner'    => 'leitvia-test',
					'supports' => array(),
					'parent'   => null,
					'ancestor' => null,
				),
			)
		);
		add_filter( 'leitvia_studio_block_catalog', $catalog );
		$template = self::run_check( 'leitvia/block-validity', '<!-- wp:leitvia-test/needs-post /-->', array( 'post_type' => 'wp_template' ) );
		$content  = self::run_check( 'leitvia/block-validity', '<!-- wp:leitvia-test/needs-post /-->' );
		remove_filter( 'leitvia_studio_block_catalog', $catalog );
		unregister_block_type( 'leitvia-test/needs-post' );
		$this->assertSame( array(), $template );
		$this->assertSame( array( 'error p:0' ), $content );
	}

	/**
	 * A block that keeps its content in saved HTML is rejected when it is empty, self-closing or has an attribute with a
	 * source in the comment; with its markup it passes, and a block rendered on the server needs none.
	 */
	public function test_saved_blocks_need_their_markup(): void {
		$value = '<span class="wp-block-leitvia-counter__value">10</span>';
		foreach ( array(
			'<!-- wp:leitvia/counter /-->',
			'<!-- wp:leitvia/counter {"value":"10","label":"Years"} /-->',
			'<!-- wp:leitvia/counter {"value":"10"} --><div class="wp-block-leitvia-counter">' . $value . '</div><!-- /wp:leitvia/counter -->',
			'<!-- wp:leitvia/bento /-->',
			'<!-- wp:leitvia/timeline /-->',
			'<!-- wp:leitvia/marquee /-->',
			'<!-- wp:leitvia/spotlight /-->',
			'<!-- wp:leitvia/reveal {"effect":"rise"} /-->',
			'<!-- wp:leitvia/reveal --><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --><!-- /wp:leitvia/reveal -->',
		) as $markup ) {
			$this->assertSame( array( 'error p:0' ), self::run_check( 'leitvia/block-validity', $markup ), $markup );
		}
		$this->assertSame( array(), self::run_check( 'leitvia/block-validity', '<!-- wp:leitvia/counter --><div class="wp-block-leitvia-counter">' . $value . '</div><!-- /wp:leitvia/counter -->' ) );
		$this->assertSame( array(), self::run_check( 'leitvia/block-validity', '<!-- wp:leitvia/contact-form {"buttonText":"Send"} /-->' ) );
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
	 * Blocks of types that are not registered (a plugin that is gone, a core/missing placeholder) give one warning per
	 * content with code and count per type; the write goes through, audit-site and check-composition report them, and a
	 * template keeps the error of block validity.
	 */
	public function test_unregistered_blocks(): void {
		$paragraph = '<!-- wp:paragraph --><p>Kept</p><!-- /wp:paragraph -->';
		$content   = '<!-- wp:acf/hero {"name":"acf/hero"} /--><!-- wp:group --><div class="wp-block-group"><!-- wp:acf/hero /--><!-- wp:missing {"originalName":"old/box"} --><div>Box</div><!-- /wp:missing --></div><!-- /wp:group -->' . $paragraph;
		$findings  = Content_Checks::unregistered(
			$content,
			array(
				'post'      => 0,
				'post_type' => 'post',
			)
		);
		$this->assertCount( 1, $findings );
		$this->assertSame(
			array(
				'code'     => 'leitvia_unregistered_block',
				'types'    => array(
					'acf/hero' => 2,
					'old/box'  => 1,
				),
				'check'    => 'leitvia/unregistered',
				'severity' => 'warning',
				'block'    => 'p:0',
			),
			array_intersect_key( $findings[0], array_flip( array( 'code', 'types', 'check', 'severity', 'block' ) ) )
		);
		$this->assertStringContainsString( 'acf/hero (2), old/box (1)', $findings[0]['message'] );
		$this->assertSame( array(), Content_Checks::unregistered( $paragraph . '<!-- wp:freeform --><p>Classic</p><!-- /wp:freeform -->', array( 'post_type' => 'post' ) ) );
		$this->assertSame( array(), self::run_check( 'leitvia/block-validity', '<!-- wp:acf/hero /-->' ), 'no error in content' );
		$this->assertSame( array( 'error p:0' ), self::run_check( 'leitvia/block-validity', '<!-- wp:acf/hero /-->', array( 'post_type' => 'wp_template' ) ) );
		$this->assertSame( array(), Content_Checks::unregistered( '<!-- wp:acf/hero /-->', array( 'post_type' => 'wp_template' ) ) );

		$page   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$change = static fn( array $state ): array => array( 'post_content' => $content ) + $state;
		$result = Pipeline::run( new Operation( 'leitvia/test-write', 'content', array( 'post' => $page ), Hashes::document( $page ), $change ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertContains( 'leitvia_unregistered_block', array_column( $result['findings'], 'code' ) );

		$post  = self::factory()->post->create( array( 'post_content' => $content ) );
		$items = wp_get_ability( 'leitvia/audit-site' )->execute( array( 'after' => $post - 1 ) )['items'];
		$this->assertSame( $post, $items[0]['post'] );
		$this->assertContains( 'leitvia/unregistered', array_column( $items[0]['findings'], 'check' ) );
		$checked = wp_get_ability( 'leitvia/check-composition' )->execute( array( 'post' => $post ) );
		$this->assertWPError( $checked, 'no pattern sections' );
		$this->assertSame(
			array(
				'acf/hero' => 2,
				'old/box'  => 1,
			),
			$checked->get_error_data()['unregistered']
		);
	}

	/**
	 * Shortcodes of page builders that no plugin handles give one warning with name and block; registered ones,
	 * placeholders such as [date], escaped shortcodes and code blocks do not count. The write goes through, the page
	 * outline lists them, templates are not checked.
	 */
	public function test_unknown_shortcodes(): void {
		$content = '<!-- wp:paragraph --><p>[vc_row][vc_column width="1/2"]Text[/vc_column][/vc_row]</p><!-- /wp:paragraph -->'
			. '<!-- wp:shortcode -->[us_btn link="/x/" /]<!-- /wp:shortcode -->'
			. '<!-- wp:paragraph --><p>Since [date] at [Organization name], [[vc_row]] and [gallery ids="1"]</p><!-- /wp:paragraph -->'
			. '<!-- wp:code --><pre class="wp-block-code"><code>[et_pb_section]</code></pre><!-- /wp:code -->';
		$this->assertSame(
			array(
				array(
					'name'  => 'vc_row',
					'block' => 'p:0',
				),
				array(
					'name'  => 'vc_column',
					'block' => 'p:0',
				),
				array(
					'name'  => 'us_btn',
					'block' => 'p:1',
				),
			),
			Content_Checks::unknown_shortcodes( $content )
		);
		$findings = Content_Checks::shortcodes( $content, array( 'post_type' => 'post' ) );
		$this->assertCount( 1, $findings );
		$this->assertSame( 'leitvia_unknown_shortcode', $findings[0]['code'] );
		$this->assertSame( 'warning', $findings[0]['severity'] );
		$this->assertSame( 'p:0', $findings[0]['block'] );
		$this->assertStringContainsString( '[vc_row] (p:0), [vc_column] (p:0), [us_btn] (p:1)', $findings[0]['message'] );
		$this->assertSame( array(), Content_Checks::shortcodes( $content, array( 'post_type' => 'wp_template' ) ) );
		$this->assertSame( array(), Content_Checks::shortcodes( '<!-- wp:paragraph --><p>[gallery]</p><!-- /wp:paragraph -->', array( 'post_type' => 'post' ) ) );

		$page   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$change = static fn( array $state ): array => array( 'post_content' => $content ) + $state;
		$result = Pipeline::run( new Operation( 'leitvia/test-write', 'content', array( 'post' => $page ), Hashes::document( $page ), $change ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertContains( 'leitvia_unknown_shortcode', array_column( $result['findings'], 'code' ) );

		$post    = self::factory()->post->create( array( 'post_content' => $content ) );
		$outline = wp_get_ability( 'leitvia/get-page-outline' )->execute( array( 'post' => $post ) );
		$this->assertSame( array( 'vc_row', 'vc_column', 'us_btn' ), array_column( $outline['shortcodes'], 'name' ) );
		$plain = self::factory()->post->create( array( 'post_content' => '<!-- wp:paragraph --><p>[date]</p><!-- /wp:paragraph -->' ) );
		$this->assertArrayNotHasKey( 'shortcodes', wp_get_ability( 'leitvia/get-page-outline' )->execute( array( 'post' => $plain ) ) );
		$items = wp_get_ability( 'leitvia/audit-site' )->execute( array( 'after' => $post - 1 ) )['items'];
		$this->assertContains( 'leitvia/shortcodes', array_column( $items[0]['findings'], 'check' ) );
	}

	/**
	 * An image block with a height and no aspect ratio gives the warning leitvia_image_fixed_height per block; with an
	 * aspect ratio, without a height and in templates it does not.
	 */
	public function test_image_fixed_height(): void {
		$image    = static fn( string $attrs ): string => '<!-- wp:image ' . $attrs . ' --><figure class="wp-block-image"><img src="https://example.org/a.jpg" alt="Lake"/></figure><!-- /wp:image -->';
		$findings = Content_Checks::image_height( $image( '{"width":"1198px","height":"512px"}' ) . $image( '{"width":"800px","height":"600px","aspectRatio":"4/3","scale":"cover"}' ) . $image( '{"width":"800px"}' ) . '<!-- wp:group --><div class="wp-block-group">' . $image( '{"height":"300px"}' ) . '</div><!-- /wp:group -->', array( 'post_type' => 'page' ) );
		$this->assertSame( array( 'p:0', 'p:3.0' ), array_column( $findings, 'block' ) );
		$this->assertSame( array( 'leitvia_image_fixed_height' ), array_values( array_unique( array_column( $findings, 'code' ) ) ) );
		$this->assertSame( 'warning', $findings[0]['severity'] );
		$this->assertSame( 'leitvia/image-height', $findings[0]['check'] );
		$this->assertSame( array(), Content_Checks::image_height( $image( '{"height":"20px"}' ), array( 'post_type' => 'wp_template_part' ) ) );
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

	/**
	 * Placeholders in square brackets on a legal page (by page kind or by a legal slug) are open points of the audit,
	 * each once; registered shortcodes and pages that are no legal text are left alone.
	 */
	public function test_audit_legal_placeholders(): void {
		add_shortcode( 'leitvia_test_code', '__return_empty_string' );
		$content  = '<!-- wp:paragraph --><p>We aim to make [domain, for example www.example.org] accessible. Date: [date].</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Updated [date]. [leitvia_test_code id="1"]</p><!-- /wp:paragraph -->';
		$kind     = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'our-accessibility',
				'post_content' => $content,
				'meta_input'   => array( '_leitvia_page_kind' => 'accessibility' ),
			)
		);
		$slug     = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'imprint',
				'post_content' => '<!-- wp:paragraph --><p>[Organization name], [postal address]</p><!-- /wp:paragraph -->',
			)
		);
		$other    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'about',
				'post_content' => $content,
			)
		);
		$items    = array_column( wp_get_ability( 'leitvia/audit-site' )->execute( array( 'after' => $kind - 1 ) )['items'], null, 'post' );
		$messages = static fn( int $id ): array => array_column( wp_list_filter( $items[ $id ]['findings'], array( 'check' => 'leitvia/legal-placeholder' ) ), 'message' );
		$this->assertCount( 2, $messages( $kind ) );
		$this->assertStringContainsString( '[domain, for example www.example.org]', $messages( $kind )[0] );
		$this->assertStringContainsString( '[date]', $messages( $kind )[1] );
		$this->assertCount( 2, $messages( $slug ) );
		$this->assertSame( array(), $messages( $other ) );
		remove_shortcode( 'leitvia_test_code' );
	}

	/**
	 * Every legal page a base recipe writes holds a placeholder the audit reports: the texts of its edits as the
	 * recipe puts them on the page, one block each.
	 */
	public function test_audit_finds_the_placeholders_of_the_recipes(): void {
		$legal = array_unique( array_values( LeitviaStudio\Gateway\Abilities\Read_Audit::LEGAL_SLUGS ) );
		$pages = 0;
		foreach ( LeitviaStudio\Gateway\Recipes::BASE as $name ) {
			$recipe = LeitviaStudio\Gateway\Recipes::load( dirname( __DIR__, 2 ) . '/recipes/' . $name . '/recipe.json' );
			foreach ( (array) $recipe['pages'] as $page ) {
				if ( ! in_array( $page['kind'] ?? '', $legal, true ) ) {
					continue;
				}
				$texts   = array_column( array_merge( ...array_map( static fn( $section ): array => (array) ( $section['edits'] ?? array() ), (array) $page['sections'] ) ), 'text' );
				$content = implode( "\n\n", array_map( static fn( string $text ): string => '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->', $texts ) );
				$id      = self::factory()->post->create(
					array(
						'post_type'    => 'page',
						'post_name'    => $name . '-' . $page['slug'],
						'post_content' => $content,
						'meta_input'   => array( '_leitvia_page_kind' => $page['kind'] ),
					)
				);
				$item    = array_column( wp_get_ability( 'leitvia/audit-site' )->execute( array( 'after' => $id - 1 ) )['items'], null, 'post' )[ $id ];
				$this->assertNotEmpty( wp_list_filter( $item['findings'], array( 'check' => 'leitvia/legal-placeholder' ) ), $name . ' ' . $page['slug'] );
				++$pages;
			}
		}
		$this->assertGreaterThanOrEqual( 6, $pages, 'imprints of company and publication, four legal pages of the shop' );
	}

	/**
	 * The first page of audit-site reports the site: closed to search engines (error, not on a preview site), missing
	 * imprint, contact and editorial page while there are posts, recent news without a large image or author biography
	 * (warnings). Later pages carry no site findings.
	 */
	public function test_audit_site_findings(): void {
		$site = static fn(): array => wp_get_ability( 'leitvia/audit-site' )->execute( array( 'limit' => 1 ) )['site'];
		update_option( 'blog_public', '0' );
		add_filter( 'leitvia_studio_preview_site', '__return_false' );
		$this->assertSame( array( 'leitvia/search-blocked' ), array_column( $site(), 'check' ), 'no posts, no page or news findings' );
		$this->assertSame( 'error', $site()[0]['severity'] );
		add_filter( 'leitvia_studio_preview_site', '__return_true', 20 );
		$this->assertSame( array(), $site() );
		remove_all_filters( 'leitvia_studio_preview_site' );
		update_option( 'blog_public', '1' );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );
		$checks = array_column( $site(), 'check' );
		$this->assertSame( array( 'leitvia/missing-page', 'leitvia/missing-page', 'leitvia/missing-page', 'leitvia/news-image', 'leitvia/author-bio' ), $checks );
		$this->assertSame( array( 'warning' ), array_values( array_unique( array_column( $site(), 'severity' ) ) ) );

		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'impressum',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'reach-us',
				'meta_input' => array( '_leitvia_page_kind' => 'contact' ),
			)
		);
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'redaktion',
			)
		);
		$image = self::factory()->attachment->create_object(
			array(
				'file'           => 'news-wide.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		wp_update_attachment_metadata(
			$image,
			array(
				'width'  => 1600,
				'height' => 900,
				'file'   => 'news-wide.jpg',
			)
		);
		set_post_thumbnail( $post, $image );
		update_user_meta( $author, 'description', 'Writes about bikes.' );
		$this->assertSame( array(), $site() );

		// Outside the news categories a post is no news.
		update_post_meta( $post, '_thumbnail_id', 0 );
		update_option( 'leitvia_studio_settings', array( 'news_categories' => array( self::factory()->category->create() ) ) );
		$this->assertSame( array(), $site() );
		$this->assertArrayNotHasKey( 'site', wp_get_ability( 'leitvia/audit-site' )->execute( array( 'after' => $post ) ) );
	}

	/**
	 * The empty part post-closing of the template single leaves no element; with content it keeps its wrapper.
	 */
	public function test_empty_template_part(): void {
		// The tests site starts with another theme, so functions.php of Leitvia is not loaded; the hooks end with the test.
		switch_theme( 'leitvia' );
		require_once get_theme_file_path( 'functions.php' );
		add_filter( 'render_block_core/template-part', 'Leitvia\drop_empty_template_part' );
		$this->assertSame( '', do_blocks( '<!-- wp:template-part {"slug":"post-closing","theme":"leitvia"} /-->' ) );
		$this->assertSame( '<div class="x"><p>Text</p></div>', \Leitvia\drop_empty_template_part( '<div class="x"><p>Text</p></div>' ) );
	}

	/**
	 * A page list inside a navigation, the fallback of a site without a menu, leaves no list inside the list of the
	 * navigation; outside a navigation it keeps its own.
	 */
	public function test_page_list_in_navigation(): void {
		switch_theme( 'leitvia' );
		require_once get_theme_file_path( 'functions.php' );
		add_filter( 'render_block_core/page-list', 'Leitvia\page_list_items_in_navigation', 10, 3 );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Kontakt',
			)
		);
		$navigation = do_blocks( '<!-- wp:navigation --><!-- wp:page-list /--><!-- /wp:navigation -->' );
		$this->assertStringContainsString( 'Kontakt', $navigation );
		$this->assertStringNotContainsString( 'wp-block-page-list', $navigation );
		$this->assertStringContainsString( '<ul class="wp-block-page-list', do_blocks( '<!-- wp:page-list /-->' ) );
	}

	/**
	 * A raw px value names its size in rem, which passes, and the rejection points to the tokens.
	 */
	public function test_px_finding_names_the_solution(): void {
		$image = static fn( string $width ): string => '<!-- wp:image {"width":"' . $width . '"} --><figure class="wp-block-image is-resized"><img src="https://example.org/a.png" alt="A map" style="width:' . $width . '"/></figure><!-- /wp:image -->';
		$found = Content_Checks::tokens( $image( '320px' ), array( 'post_type' => 'page' ) );
		$this->assertStringContainsString( '20rem', $found[0]['message'] );
		$this->assertSame( array(), Content_Checks::tokens( $image( '20rem' ), array( 'post_type' => 'page' ) ) );
		$rejected = wp_get_ability( 'leitvia/create-content' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Map',
				'content'   => $image( '320px' ),
				'base_hash' => '',
			)
		);
		$this->assertSame( 'leitvia/get-tokens', $rejected->get_error_data()['next_ability'] );
	}
}
