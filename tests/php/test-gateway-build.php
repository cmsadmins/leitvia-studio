<?php
/**
 * Gateway build abilities.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;

/**
 * Block edits from fixtures, compose-page, update-block, create-pattern, vary-pattern and add-media through the
 * pipeline.
 */
class Test_Gateway_Build extends WP_UnitTestCase {

	/**
	 * Abilities of this task.
	 */
	private const ABILITIES = array( 'leitvia/compose-page', 'leitvia/update-block', 'leitvia/create-pattern', 'leitvia/vary-pattern', 'leitvia/add-media' );

	/**
	 * Leitvia theme, agent user, autonomous profile and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		wp_clean_theme_json_cache();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes the abilities and the category.
	 */
	public function tear_down(): void {
		foreach ( self::ABILITIES as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		foreach ( wp_get_abilities() as $ability ) {
			if ( 'leitvia' === $ability->get_category() ) {
				wp_unregister_ability( $ability->get_name() );
			}
		}
		if ( wp_has_ability_category( 'leitvia' ) ) {
			wp_unregister_ability_category( 'leitvia' );
		}
		wp_clean_theme_json_cache();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
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
	 * Fixture cases of Block_Edit.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function provide_edits(): array {
		$json  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/gateway/build/block-edits.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$cases = array();
		foreach ( $json['cases'] as $case ) {
			$cases[ $case['name'] ] = array( $case );
		}
		return $cases;
	}

	/**
	 * Block edits change only the target, rebuild static markup or refuse.
	 *
	 * @dataProvider provide_edits
	 *
	 * @param array<string, mixed> $edit Case.
	 */
	public function test_block_edit( array $edit ): void {
		$result = Block_Edit::apply( $edit['content'], $edit['block'], $edit['change'], array( 'post' => 1 ) );
		if ( isset( $edit['error'] ) ) {
			$data = $this->assert_rejected( $edit['error'], $result );
			$this->assertSame( 1, $data['target']['post'] );
			return;
		}
		$this->assertSame( $edit['expect'], $result );
	}

	/**
	 * A page from theme patterns and an approved Lab pattern: page frame template, patternName per section (core's
	 * core/block/<ID> for the Lab pattern, so the composition knows it), edits at pattern paths including the quote
	 * citation, no findings for the decorative hero cover; the slashes of the title survive.
	 */
	public function test_compose_page(): void {
		$lab    = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>From the Lab</p><!-- /wp:paragraph -->',
				'meta_input'   => array( '_leitvia_lab_status' => 'approved' ),
			)
		);
		$result = self::call(
			'leitvia/compose-page',
			array(
				'title'    => 'Walks \\ "trails"',
				'frame'    => 'home',
				'sections' => array(
					array(
						'pattern' => 'leitvia/hero-cover-image',
						'edits'   => array(
							array(
								'block' => 'p:0.0.0',
								'text'  => 'Walks between towns',
							),
							array(
								'block' => 'p:0.0.2.0',
								'text'  => 'See the routes',
								'url'   => '/routes/',
							),
						),
					),
					array(
						'pattern' => 'leitvia/content-quote',
						'edits'   => array(
							array(
								'block' => 'p:0.0',
								'text'  => 'Jane Roe, Walking Club',
							),
						),
					),
					array(
						'pattern' => 'block:' . $lab,
					),
				),
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( array(), $result['findings'] );
		$this->assertGreaterThan( 0, $result['snapshot'] );
		$post = get_post( $result['target']['post'] );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'Walks \\ "trails"', $post->post_title );
		$this->assertSame( 'page-landing', get_page_template_slug( $post ) );
		$roots = array_values( array_filter( parse_blocks( $post->post_content ), static fn( array $block ): bool => null !== $block['blockName'] ) );
		$this->assertSame( array( 'leitvia/hero-cover-image', 'leitvia/content-quote', 'core/block/' . $lab ), array_map( static fn( array $block ): string => $block['attrs']['metadata']['patternName'], $roots ) );
		$this->assertStringContainsString( '<cite>Jane Roe, Walking Club</cite>', $post->post_content );
		$this->assertStringNotContainsString( 'Achterberg', $post->post_content );
		$this->assertTrue( $roots[0]['attrs']['metadata']['leitviaDecorative'] );
		$this->assertStringContainsString( '>Walks between towns</h1>', $post->post_content );
		$this->assertStringContainsString( 'href="/routes/">See the routes</a>', $post->post_content );
	}

	/**
	 * The hero button still links to "#" without an edit, so the link check stops the page.
	 */
	public function test_compose_page_runs_checks(): void {
		$data = $this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/compose-page',
				array(
					'title'    => 'Home',
					'frame'    => 'home',
					'sections' => array( array( 'pattern' => 'leitvia/hero-cover-image' ) ),
				)
			)
		);
		$this->assertContains( 'leitvia/link-text', array_column( $data['findings'], 'check' ) );
	}

	/**
	 * Unknown patterns point to list-patterns, deprecated ones name the replacement, unknown frames are refused.
	 */
	public function test_compose_page_rejections(): void {
		$page = static fn( string $pattern, string $frame = 'home' ) => self::call(
			'leitvia/compose-page',
			array(
				'title'    => 'Page',
				'frame'    => $frame,
				'sections' => array( array( 'pattern' => $pattern ) ),
			)
		);
		$data = $this->assert_rejected( 'leitvia_unknown_pattern', $page( 'leitvia/no-such-pattern' ) );
		$this->assertSame( 'leitvia/list-patterns', $data['next_ability'] );
		$old  = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
				'meta_input'   => array(
					'_leitvia_lab_status'      => 'deprecated',
					'_leitvia_lab_replaced_by' => 'pattern:leitvia/content-quote',
				),
			)
		);
		$data = $this->assert_rejected( 'leitvia_deprecated_pattern', $page( 'block:' . $old ) );
		$this->assertSame( 'leitvia/content-quote', $data['replaced_by'] );
		$this->assert_rejected( 'leitvia_invalid_input', $page( 'leitvia/content-quote', 'no-such-frame' ) );

		// Lab patterns in draft or review, or approved with a draft part, wait for a person; nothing is written.
		$lab   = static fn( string $status, string $content = '<!-- wp:paragraph --><p>Lab</p><!-- /wp:paragraph -->' ): int => self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => $content,
				'meta_input'   => array( '_leitvia_lab_status' => $status ),
			)
		);
		$draft = $lab( 'draft' );
		$pages = wp_count_posts( 'page' )->publish;
		foreach ( array( $draft, $lab( 'review' ), $lab( 'approved', '<!-- wp:block {"ref":' . $draft . '} /-->' ) ) as $id ) {
			$data = $this->assert_rejected( 'leitvia_pattern_not_approved', $page( 'block:' . $id ) );
			$this->assertSame( 'leitvia/list-patterns', $data['next_ability'] );
			$this->assertSame( 'block:' . $id, $data['target']['pattern'] );
			$this->assertNotSame( 'approved', $data['status'] );
		}
		$this->assertSame( $pages, wp_count_posts( 'page' )->publish );
	}

	/**
	 * The ability update-block changes the target only, keeps the base hash rule and refuses static markup.
	 */
	public function test_update_block(): void {
		$content = "<!-- wp:paragraph {\"metadata\":{\"leitviaId\":\"aaaa1111\"}} -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph {\"metadata\":{\"leitviaId\":\"bbbb2222\"}} -->\n<p>Two \\ slash</p>\n<!-- /wp:paragraph -->";
		$page    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => wp_slash( $content ),
			)
		);
		$result  = self::call(
			'leitvia/update-block',
			array(
				'post'      => $page,
				'block'     => 'aaaa1111',
				'text'      => 'One \\ changed',
				'base_hash' => Hashes::document( $page ),
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( str_replace( '<p>One</p>', '<p>One \\ changed</p>', $content ), get_post( $page )->post_content );
		$this->assertSame( 'aaaa1111', $result['target']['block'] );

		$data = $this->assert_rejected(
			'leitvia_static_markup',
			self::call(
				'leitvia/update-block',
				array(
					'post'      => $page,
					'block'     => 'bbbb2222',
					'url'       => '/x/',
					'base_hash' => Hashes::document( $page ),
				)
			)
		);
		$this->assertSame( 'leitvia/compose-page', $data['next_ability'] );
		$this->assert_rejected(
			'leitvia_stale_hash',
			self::call(
				'leitvia/update-block',
				array(
					'post'      => $page,
					'block'     => 'bbbb2222',
					'text'      => 'Late',
					'base_hash' => '0000000000000000',
				)
			)
		);
	}

	/**
	 * The ability update-block sets the level of a heading: the tag and the attribute change together, level 2 drops the
	 * attribute as the editor does. A level out of range or another attribute is refused, and a level that skips further
	 * fails the heading check although the post had a skipped level before.
	 */
	public function test_update_block_heading_level(): void {
		$content = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Intro</h2>\n<!-- /wp:heading -->\n\n<!-- wp:heading {\"level\":4} -->\n<h4 class=\"wp-block-heading\">Details <em>here</em></h4>\n<!-- /wp:heading -->";
		$page    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => wp_slash( $content ),
			)
		);
		$level   = static fn( int $value ): array => array(
			'post'       => $page,
			'block'      => 'p:1',
			'attributes' => array( 'level' => $value ),
			'base_hash'  => Hashes::document( $page ),
		);
		$heading = static fn(): array => parse_blocks( get_post( $page )->post_content )[2];

		$result = self::call( 'leitvia/update-block', $level( 3 ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertSame( 3, $heading()['attrs']['level'] );
		$this->assertSame( "\n<h3 class=\"wp-block-heading\">Details <em>here</em></h3>\n", $heading()['innerHTML'] );

		$this->assert_rejected( 'leitvia_check_failed', self::call( 'leitvia/update-block', $level( 5 ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/update-block', $level( 7 ) ) );
		$data = $this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/update-block', array( 'attributes' => array( 'textAlign' => 'center' ) ) + $level( 2 ) ) );
		$this->assertSame( 'attributes', $data['reason'] );

		$this->assertIsArray( self::call( 'leitvia/update-block', $level( 2 ) ) );
		$this->assertArrayNotHasKey( 'level', $heading()['attrs'] );
		$this->assertSame( "\n<h2 class=\"wp-block-heading\">Details <em>here</em></h2>\n", $heading()['innerHTML'] );
	}

	/**
	 * An image from the library goes into a cover with ID, URL, class and alt text; without alt text it is refused.
	 */
	public function test_update_block_image(): void {
		$image   = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png', 0 );
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia/hero-cover-image' );
		$page    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_content' => wp_slash( str_replace( 'href="#"', 'href="/routes/"', $pattern['content'] ) ),
				'meta_input'   => array( '_wp_page_template' => 'page-landing' ),
			)
		);
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-block',
				array(
					'post'      => $page,
					'block'     => 'p:0',
					'image'     => array( 'id' => $image ),
					'base_hash' => Hashes::document( $page ),
				)
			)
		);
		$result = self::call(
			'leitvia/update-block',
			array(
				'post'      => $page,
				'block'     => 'p:0',
				'image'     => array(
					'id'  => $image,
					'alt' => 'A path through hills at dusk',
				),
				'base_hash' => Hashes::document( $page ),
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$cover = parse_blocks( get_post( $page )->post_content )[0];
		$this->assertSame( $image, $cover['attrs']['id'] );
		$this->assertSame( wp_get_attachment_url( $image ), $cover['attrs']['url'] );
		$this->assertSame( 'A path through hills at dusk', $cover['attrs']['alt'] );
		$this->assertArrayNotHasKey( 'leitviaDecorative', $cover['attrs']['metadata'] );
		$this->assertStringContainsString( 'class="wp-block-cover__image-background wp-image-' . $image . '" alt="A path through hills at dusk" src="' . wp_get_attachment_url( $image ) . '"', $cover['innerHTML'] );
		$this->assertCount( 1, $cover['innerBlocks'] );
	}

	/**
	 * Patterns: a Lab draft with version, the token lint as a check, variants of Lab and theme patterns.
	 */
	public function test_patterns(): void {
		$result = self::call(
			'leitvia/create-pattern',
			array(
				'title'   => 'Note',
				'content' => '<!-- wp:paragraph --><p>A note</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$id = $result['target']['post'];
		$this->assertSame( 'wp_block', get_post_type( $id ) );
		$this->assertSame( 'draft', get_post_meta( $id, '_leitvia_lab_status', true ) );
		$this->assertSame( '0.1.0', get_post_meta( $id, '_leitvia_lab_version', true ) );
		$this->assertSame( 'unsynced', get_post_meta( $id, 'wp_pattern_sync_status', true ) );

		$data = $this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/create-pattern',
				array(
					'title'   => 'Raw',
					'content' => '<!-- wp:paragraph {"style":{"color":{"text":"#ff0000"}}} --><p class="has-text-color" style="color:#ff0000">Red</p><!-- /wp:paragraph -->',
				)
			)
		);
		$this->assertContains( 'leitvia/content-tokens', array_column( $data['findings'], 'check' ) );

		$variant = self::call(
			'leitvia/vary-pattern',
			array(
				'original' => 'block:' . $id,
				'edits'    => array(
					array(
						'block' => 'p:0',
						'text'  => 'Another note',
					),
				),
			)
		);
		$this->assertIsArray( $variant, is_wp_error( $variant ) ? wp_json_encode( $variant->get_error_data() ) : '' );
		$variant = $variant['target']['post'];
		$this->assertSame( (string) $id, get_post_meta( $variant, '_leitvia_lab_variant_of', true ) );
		$this->assertSame( 'Note (variant)', get_the_title( $variant ) );
		$this->assertStringContainsString( '<p>Another note</p>', get_post( $variant )->post_content );
		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/vary-pattern', array( 'original' => 'block:' . $variant ) ) );

		$theme = self::call( 'leitvia/vary-pattern', array( 'original' => 'leitvia/content-quote' ) );
		$this->assertIsArray( $theme, is_wp_error( $theme ) ? wp_json_encode( $theme->get_error_data() ) : '' );
		$this->assertSame( 'leitvia/content-quote', get_post_meta( $theme['target']['post'], '_leitvia_lab_source_pattern', true ) );
	}

	/**
	 * Media: Base64 and own URLs with alt text or the decorative mark; no alt text, other hosts and non-images are
	 * refused.
	 */
	public function test_add_media(): void {
		$png    = base64_encode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/avatar.png' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture.
		$result = self::call(
			'leitvia/add-media',
			array(
				'data'     => $png,
				'filename' => 'portrait.png',
				'alt'      => 'Portrait of the author',
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$id = $result['target']['post'];
		$this->assertTrue( wp_attachment_is_image( $id ) );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
		$this->assertSame( 'Portrait of the author', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertFileExists( get_attached_file( $id ) );
		$this->assertGreaterThan( 0, wp_get_attachment_metadata( $id )['width'] );

		$own = self::call(
			'leitvia/add-media',
			array(
				'url'        => get_theme_file_uri( 'assets/images/landscape.webp' ),
				'decorative' => true,
			)
		);
		$this->assertIsArray( $own, is_wp_error( $own ) ? wp_json_encode( $own->get_error_data() ) : '' );
		$this->assertSame( 'image/webp', get_post_mime_type( $own['target']['post'] ) );

		$this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/add-media',
				array(
					'data'     => $png,
					'filename' => 'portrait.png',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_check_failed',
			self::call(
				'leitvia/add-media',
				array(
					'data'     => $png,
					'filename' => 'portrait.png',
					'alt'      => 'portrait.png',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_foreign_host',
			self::call(
				'leitvia/add-media',
				array(
					'url' => 'https://example.org/image.png',
					'alt' => 'Image',
				)
			)
		);
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/add-media',
				array(
					'data'     => base64_encode( 'not an image' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data.
					'filename' => 'fake.png',
					'alt'      => 'Fake',
				)
			)
		);
	}
}
