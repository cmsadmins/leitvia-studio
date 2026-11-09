<?php
/**
 * Gateway: captions, text tracks, new links, text attributes of static blocks, query settings, status, discarding a
 * package and the privacy policy page.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Catalog;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Pipeline;

/**
 * The fields of update-block and update-content that reach block markup, add-media with WebVTT files and the abilities
 * reject-package and set-privacy-page.
 */
class Test_Gateway_Block_Fields extends WP_UnitTestCase {

	private const VTT = "WEBVTT\n\n00:00.000 --> 00:02.000\nHello\n";

	/**
	 * Agent user.
	 *
	 * @var int
	 */
	private int $agent;

	/**
	 * Leitvia theme, the abilities handed to core, an agent with full autonomy.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		wp_clean_theme_json_cache();
		$this->agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		wp_set_current_user( $this->agent );
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
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
		wp_clean_theme_json_cache();
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
	 * One level for the content, structure and design areas.
	 *
	 * @param string $level Level.
	 */
	private static function level( string $level ): void {
		update_option(
			Gateway::OPTION,
			array(
				'overrides' => array(
					'areas' => array_fill_keys( array( 'content', 'structure', 'design' ), $level ),
					'kinds' => Gateway::KINDS,
				),
			) + Gateway::DEFAULTS
		);
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
	 * Asserts a successful result.
	 *
	 * @param mixed $result Result.
	 */
	private function assert_done( $result ): void {
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() . ' ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );
	}

	/**
	 * Asserts a rejection.
	 *
	 * @param string $code   Code.
	 * @param mixed  $result Result.
	 */
	private function assert_rejected( string $code, $result ): void {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
	}

	/**
	 * A published page by the current user.
	 *
	 * @param string $content Block markup.
	 */
	private static function page( string $content ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
				'post_content' => wp_slash( $content ),
			)
		);
	}

	/**
	 * Runs update-block on the first block of a page and returns the stored content.
	 *
	 * @param int                  $page  Page.
	 * @param array<string, mixed> $input Change.
	 * @return mixed Content, or the rejection.
	 */
	private static function edit( int $page, array $input ) {
		$result = self::call(
			'leitvia/update-block',
			$input + array(
				'post'      => $page,
				'block'     => 'p:0',
				'base_hash' => Hashes::document( $page ),
			)
		);
		return is_wp_error( $result ) ? $result : get_post( $page )->post_content;
	}

	/**
	 * Input of add-media for bytes under a name.
	 *
	 * @param string $bytes Bytes.
	 * @param string $name  File name.
	 * @return array<string, string>
	 */
	private static function file( string $bytes, string $name ): array {
		return array(
			'data'     => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture.
			'filename' => $name,
		);
	}

	/**
	 * A WebVTT file in the media library.
	 */
	private function track(): int {
		$result = self::call( 'leitvia/add-media', self::file( self::VTT, 'captions.vtt' ) );
		$this->assert_done( $result );
		return (int) $result['target']['post'];
	}

	/**
	 * Add-media takes a WebVTT file without alt text and only with the signature, as UTF-8, under its extension.
	 */
	public function test_add_media_takes_webvtt(): void {
		$id = $this->track();
		$this->assertSame( 'text/vtt', get_post_mime_type( $id ) );
		$this->assertSame( self::VTT, file_get_contents( get_attached_file( $id ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test.
		$this->assertStringEndsWith( '.vtt', (string) wp_get_attachment_url( $id ) );

		$this->assert_done( self::call( 'leitvia/add-media', self::file( "\xEF\xBB\xBF" . self::VTT, 'with-bom.vtt' ) ) );
		$refused = array(
			'plain.vtt'     => "Hello\n",
			'signature.vtt' => "WEBVTTX\n",
			'code.vtt'      => "<?php echo 1;\nWEBVTT\n",
			'page.vtt'      => "<html><script>alert(1)</script></html>\n",
			'encoding.vtt'  => "WEBVTT\n\xFF\xFE",
			'zero.vtt'      => "WEBVTT\n\0",
			'captions.txt'  => self::VTT,
			'captions.php'  => self::VTT,
		);
		foreach ( $refused as $name => $bytes ) {
			$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/add-media', self::file( $bytes, $name ) ) );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( self::call( 'leitvia/add-media', self::file( self::VTT, 'denied.vtt' ) ), 'no upload without the right to upload files' );
	}

	/**
	 * Tracks reach attribute and markup, survive the write and render; only WebVTT files of the library count; an empty
	 * list removes them. Caption and tracks work together on one video.
	 */
	public function test_update_block_tracks_and_caption_of_a_video(): void {
		$track = $this->track();
		$page  = self::page( "<!-- wp:video -->\n<figure class=\"wp-block-video\"><video controls src=\"/movie.mp4\"></video></figure>\n<!-- /wp:video -->" );

		$content = self::edit(
			$page,
			array(
				'attributes' => array(
					'tracks' => array(
						array(
							'id'      => $track,
							'kind'    => 'captions',
							'srclang' => 'de',
							'label'   => 'Deutsch',
						),
					),
				),
				'caption'    => 'Der <b>Film</b>',
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$url = wp_get_attachment_url( $track );
		$this->assertStringContainsString( '<track src="' . $url . '" label="Deutsch" srclang="de" kind="captions" /></video>', $content );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Der &lt;b&gt;Film&lt;/b&gt;</figcaption></figure>', $content );
		$this->assertSame(
			array(
				'src'     => $url,
				'label'   => 'Deutsch',
				'srcLang' => 'de',
				'kind'    => 'captions',
			),
			parse_blocks( $content )[0]['attrs']['tracks'][0]
		);
		$this->assertStringContainsString( '<track', do_blocks( $content ) );

		$image = self::factory()->attachment->create_object( 'photo.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::edit(
				$page,
				array(
					'attributes' => array(
						'tracks' => array(
							array(
								'id'      => $image,
								'srclang' => 'de',
								'label'   => 'Deutsch',
							),
						),
					),
				)
			)
		);

		$content = self::edit(
			$page,
			array(
				'attributes' => array( 'tracks' => array() ),
				'caption'    => '',
			)
		);
		$this->assertStringNotContainsString( '<track', $content );
		$this->assertStringNotContainsString( 'figcaption', $content );
		$this->assertArrayNotHasKey( 'tracks', parse_blocks( $content )[0]['attrs'] );
	}

	/**
	 * The caption works on every block with a figcaption, keeps classes of an existing one and stays closed to blocks
	 * without one.
	 */
	public function test_caption_of_every_figure(): void {
		$cases = array(
			'<!-- wp:image --><figure class="wp-block-image"><img src="/a.jpg" alt="A"/></figure><!-- /wp:image -->',
			'<!-- wp:audio --><figure class="wp-block-audio"><audio controls src="/a.mp3"></audio></figure><!-- /wp:audio -->',
			'<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>A</td></tr></tbody></table></figure><!-- /wp:table -->',
		);
		foreach ( $cases as $markup ) {
			$content = self::edit( self::page( $markup ), array( 'caption' => 'New' ) );
			$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
			$this->assertStringContainsString( '<figcaption class="wp-element-caption">New</figcaption></figure>', $content );
		}
		$page    = self::page( '<!-- wp:image --><figure class="wp-block-image"><img src="/a.jpg" alt="A"/><figcaption class="wp-element-caption has-text-align-center">Old</figcaption></figure><!-- /wp:image -->' );
		$content = self::edit( $page, array( 'caption' => 'New' ) );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption has-text-align-center">New</figcaption>', $content );

		$page = self::page( "<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->" );
		$this->assert_rejected( 'leitvia_static_markup', self::edit( $page, array( 'caption' => 'New' ) ) );
	}

	/**
	 * A new link goes around a passage, keeps formatting and other links, never nests, and works from update-content.
	 */
	public function test_add_link(): void {
		$page    = self::page( "<!-- wp:paragraph -->\n<p>Read <strong>the guide</strong> at <a href=\"/x/\">our guide</a> or the guide below.</p>\n<!-- /wp:paragraph -->" );
		$content = self::edit(
			$page,
			array(
				'add_link' => array(
					'text' => 'guide',
					'url'  => '/guide/',
				),
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<strong>the <a href="/guide/">guide</a></strong> at <a href="/x/">our guide</a> or the guide below.', $content );

		$refused = array(
			'our guide' => '/y/',
			'not there' => '/z/',
			'below'     => 'javascript:alert(1)',
		);
		foreach ( $refused as $text => $url ) {
			$this->assert_rejected(
				'leitvia_invalid_input',
				self::edit(
					$page,
					array(
						'add_link' => array(
							'text' => $text,
							'url'  => $url,
						),
					)
				)
			);
		}

		$page   = self::page( "<!-- wp:paragraph -->\n<p>Tom &amp; Jerry's page</p>\n<!-- /wp:paragraph -->" );
		$result = self::call(
			'leitvia/update-content',
			array(
				'post'      => $page,
				'blocks'    => array(
					array(
						'block'    => 'p:0',
						'add_link' => array(
							'text' => "Tom & Jerry's",
							'url'  => 'https://example.org/',
						),
					),
				),
				'base_hash' => Hashes::document( $page ),
			)
		);
		$this->assert_done( $result );
		$this->assertStringContainsString( '<a href="https://example.org/">Tom &amp; Jerry\'s</a> page', get_post( $page )->post_content );
	}

	/**
	 * The markup of every catalog example passes the gateway as saved; the counter is the block that showed the gap.
	 */
	public function test_catalog_examples_are_writable(): void {
		foreach ( Catalog::get() as $entry ) {
			if ( isset( $entry['example'] ) ) {
				$this->assertNull( Pipeline::unsafe( (string) $entry['example'] ), (string) $entry['name'] );
			}
		}
		$this->assertStringContainsString( 'min-inline-size:4ch', wp_kses_post( '<span style="min-inline-size:4ch">1</span>' ) );
		$this->assertStringContainsString( '__prefix', (string) array_column( Catalog::get(), null, 'name' )['leitvia/counter']['example'] );
	}

	/**
	 * The parts of a counter change like the save function builds them: the width follows the value, prefix and suffix
	 * come and go. Other static blocks change a text whose element they have.
	 */
	public function test_update_block_counter(): void {
		$page    = self::page( '<!-- wp:leitvia/counter --><div class="wp-block-leitvia-counter"><p class="wp-block-leitvia-counter__number"><span class="wp-block-leitvia-counter__value" style="min-inline-size:4ch">1250</span><span class="wp-block-leitvia-counter__suffix">+</span></p><p class="wp-block-leitvia-counter__label">Projects</p></div><!-- /wp:leitvia/counter -->' );
		$content = self::edit(
			$page,
			array(
				'attributes' => array(
					'prefix' => '€',
					'value'  => '12,500',
					'suffix' => '',
					'label'  => 'Budget & more',
				),
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<p class="wp-block-leitvia-counter__number"><span class="wp-block-leitvia-counter__prefix">€</span><span class="wp-block-leitvia-counter__value" style="min-inline-size:6ch">12,500</span></p>', $content );
		$this->assertStringContainsString( '<p class="wp-block-leitvia-counter__label">Budget &amp; more</p>', $content );

		$this->assert_rejected( 'leitvia_static_markup', self::edit( $page, array( 'attributes' => array( 'value' => '' ) ) ) );

		$page    = self::page( '<!-- wp:leitvia/icon-list-item --><li class="wp-block-leitvia-icon-list-item"><span class="wp-block-leitvia-icon-list-item__text">Old</span></li><!-- /wp:leitvia/icon-list-item -->' );
		$content = self::edit( $page, array( 'attributes' => array( 'text' => 'New' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '__text">New<', $content );
	}

	/**
	 * Layout attributes of core media blocks change one by one: a fixed height goes with null, an aspect ratio and a
	 * crop come in, a size swaps class and src, a link wraps the image and goes again, align and a block style change
	 * the classes. Other attributes and unknown block styles are refused.
	 */
	public function test_update_block_media_attributes(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$src   = (string) wp_get_attachment_image_url( $image, 'large' );
		$page  = self::page( '<!-- wp:image {"id":' . $image . ',"width":"1198px","height":"512px","sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large is-resized"><img src="' . $src . '" alt="Lake at dusk" class="wp-image-' . $image . '" style="width:1198px;height:512px"/></figure><!-- /wp:image -->' );

		$content = self::edit( $page, array( 'attributes' => array( 'height' => null ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$block = parse_blocks( $content )[0];
		$this->assertArrayNotHasKey( 'height', $block['attrs'] );
		$this->assertSame( '1198px', $block['attrs']['width'] );
		$this->assertStringContainsString( 'style="width:1198px"', $block['innerHTML'] );
		$this->assertStringContainsString( 'is-resized', $block['innerHTML'] );

		$content = self::edit(
			$page,
			array(
				'attributes' => array(
					'width'       => null,
					'aspectRatio' => '16/9',
					'scale'       => 'cover',
				),
			)
		);
		$block   = parse_blocks( $content )[0];
		$this->assertSame( '16/9', $block['attrs']['aspectRatio'] );
		$this->assertSame( 'cover', $block['attrs']['scale'] );
		$this->assertStringContainsString( 'style="aspect-ratio:16/9;object-fit:cover"', $block['innerHTML'] );
		$this->assertStringNotContainsString( 'is-resized', $block['innerHTML'] );

		$content = self::edit(
			$page,
			array(
				'attributes' => array(
					'sizeSlug' => 'medium',
					'href'     => '/journeys/',
					'align'    => 'wide',
				),
			)
		);
		$block   = parse_blocks( $content )[0];
		$this->assertStringContainsString( 'size-medium', $block['innerHTML'] );
		$this->assertStringNotContainsString( 'size-large', $block['innerHTML'] );
		$this->assertStringContainsString( 'alignwide', $block['innerHTML'] );
		$this->assertStringContainsString( 'src="' . wp_get_attachment_image_url( $image, 'medium' ) . '"', $block['innerHTML'] );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*/journeys/"><img [^>]+></a>#', $block['innerHTML'] );
		$this->assertSame( 'custom', $block['attrs']['linkDestination'] );

		$content = self::edit( $page, array( 'attributes' => array( 'href' => null ) ) );
		$block   = parse_blocks( $content )[0];
		$this->assertStringNotContainsString( '<a ', $block['innerHTML'] );
		$this->assertArrayNotHasKey( 'href', $block['attrs'] );
		$this->assertSame( 'none', $block['attrs']['linkDestination'] );

		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'url' => 'https://example.com/x.jpg' ) ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'height' => '12 parsecs' ) ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'className' => 'is-style-unknown' ) ) ) );

		register_block_style(
			'core/gallery',
			array(
				'name'  => 'test-frame',
				'label' => 'Frame',
			)
		);
		$gallery = self::page( '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":' . $image . '} --><figure class="wp-block-image"><img src="' . $src . '" alt="Lake at dusk" class="wp-image-' . $image . '"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->' );
		$content = self::edit( $gallery, array( 'attributes' => array( 'className' => 'is-style-test-frame' ) ) );
		unregister_block_style( 'core/gallery', 'test-frame' );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$block = parse_blocks( $content )[0];
		$this->assertSame( 'is-style-test-frame', $block['attrs']['className'] );
		$this->assertStringContainsString( 'wp-block-gallery has-nested-images columns-default is-cropped is-style-test-frame', $block['innerHTML'] );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $gallery, array( 'attributes' => array( 'width' => '10px' ) ) ) );
	}

	/**
	 * A stored block of a plugin that is gone, with markup KSES would cut (an iframe), holds back no write elsewhere in
	 * the post: only changed blocks are checked, the stored block stays byte for byte, and a rejection names the block
	 * that is unsafe, not the block the write targets.
	 */
	public function test_stored_unsafe_block_stays(): void {
		$text    = '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->';
		$foreign = '<!-- wp:acf/decor {"name":"acf/decor"} --><div class="decor"><iframe src="https://maps.example.com/embed"></iframe></div><!-- /wp:acf/decor -->';
		$page    = self::page( $text );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $text . $foreign ), array( 'ID' => $page ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Content as a person with unfiltered_html stored it.
		clean_post_cache( $page );

		$content = self::edit( $page, array( 'text' => 'New' ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_code() . ' ' . $content->get_error_message() : '' );
		$this->assertStringContainsString( '<p>New</p>', $content );
		// The markup stays byte for byte; only the block ID of the gateway lands in its delimiter.
		$this->assertStringContainsString( '<div class="decor"><iframe src="https://maps.example.com/embed"></iframe></div><!-- /wp:acf/decor -->', $content, 'the stored block stays byte for byte' );

		$changed = Pipeline::unsafe( $text . str_replace( 'maps.example.com', 'other.example.com', $foreign ), false, $text . $foreign );
		$this->assertSame( 'p:1', $changed['block'], 'a changed foreign block is checked' );
		$this->assertNull( Pipeline::unsafe( '<!-- wp:paragraph --><p>Other</p><!-- /wp:paragraph -->' . $foreign, false, $text . $foreign ) );

		$page   = self::page( $text . '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->' );
		$result = self::call(
			'leitvia/update-content',
			array(
				'post'      => $page,
				'content'   => '<!-- wp:paragraph --><p>Hi<script>x()</script></p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->',
				'base_hash' => Hashes::document( $page ),
			)
		);
		$this->assert_rejected( 'leitvia_unsafe_content', $result );
		$this->assertSame( 'p:0', $result->get_error_data()['found']['block'] );
		$this->assertSame( 'p:0', $result->get_error_data()['target']['block'] );
	}

	/**
	 * Only markup the content before holds as it is passes unchecked. A new block that splits stored markup around an
	 * inner block (at a | of the stored text) is checked, and so is a new block inside a stored foreign block. A copy
	 * of a stored block in the same post passes: it brings no markup that was not there.
	 */
	public function test_stored_keys_do_not_cover_new_markup(): void {
		$stored = '<!-- wp:acf/decor --><div class="decor"><a href="java|script:x()">Link</a></div><!-- /wp:acf/decor -->';
		$split  = '<!-- wp:acf/decor --><div class="decor"><a href="java<!-- wp:paragraph /-->script:x()">Link</a></div><!-- /wp:acf/decor -->';
		$this->assertNull( Pipeline::unsafe( $stored, false, $stored ), 'the stored block itself passes' );
		$found = Pipeline::unsafe( $split, false, $stored );
		$this->assertNotNull( $found, 'markup split around an inner block is new' );
		$this->assertSame( 'p:0', $found['block'] );

		$box   = '<!-- wp:acf/box --><div class="box"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:acf/box -->';
		$found = Pipeline::unsafe( '<!-- wp:acf/box --><div class="box"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --><!-- wp:html --><script>x()</script><!-- /wp:html --></div><!-- /wp:acf/box -->', false, $box );
		$this->assertSame( 'html_block', $found['reason'] ?? '', 'a new block inside a stored foreign block is checked' );
		$this->assertSame( 'p:0.1', $found['block'] );
		$foreign = '<!-- wp:acf/decor {"name":"acf/decor"} --><div class="decor"><iframe src="https://maps.example.com/embed"></iframe><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:acf/decor -->';
		$this->assertNotNull( Pipeline::unsafe( str_replace( '<p>A</p>', '<p>A<script>x()</script></p>', $foreign ), false, $foreign ) );

		$this->assertNull( Pipeline::unsafe( $foreign . $foreign, false, $foreign ), 'a copy of a stored block brings nothing new' );
	}

	/**
	 * Query is in the catalog; perPage alone keeps the other settings, or the default of core on a query without any.
	 */
	public function test_update_block_query(): void {
		$names = array_column( Catalog::get(), null, 'name' );
		$this->assertArrayHasKey( 'core/query', $names );
		$this->assertArrayHasKey( 'core/video', $names );

		$inner   = '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div>';
		$page    = self::page( '<!-- wp:query {"queryId":3,"query":{"perPage":3,"postType":"post","inherit":false}} -->' . $inner . '<!-- /wp:query -->' );
		$content = self::edit( $page, array( 'attributes' => array( 'query' => array( 'perPage' => 6 ) ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$attrs = parse_blocks( $content )[0]['attrs'];
		$this->assertSame(
			array(
				'perPage'  => 6,
				'postType' => 'post',
				'inherit'  => false,
			),
			$attrs['query']
		);
		$this->assertSame( 3, $attrs['queryId'] );

		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'query' => array( 'perPage' => 0 ) ) ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'query' => array( 'other' => 1 ) ) ) ) );

		$page    = self::page( '<!-- wp:query -->' . $inner . '<!-- /wp:query -->' );
		$content = self::edit( $page, array( 'attributes' => array( 'query' => array( 'perPage' => 6 ) ) ) );
		$this->assertTrue( parse_blocks( $content )[0]['attrs']['query']['inherit'] );
	}

	/**
	 * Status of update-content at suggest waits in a package like any change; applied by a person the draft is public
	 * with today's date. Future needs a future date.
	 */
	public function test_update_content_publishes_a_draft(): void {
		self::level( 'suggest' );
		$draft = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_author'  => $this->agent,
				'post_date'    => '2020-01-01 10:00:00',
				'post_content' => "<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->",
			)
		);
		$first = self::call(
			'leitvia/update-content',
			array(
				'post'      => $draft,
				'status'    => 'publish',
				'base_hash' => Hashes::document( $draft ),
				'package'   => 'publish-1',
			)
		);
		$this->assert_done( $first );
		$this->assertSame( 'package', $first['mode'] );
		$this->assertSame( 'draft', get_post_status( $draft ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$report = Packages::apply( (int) $first['package'] );
		$this->assertSame( array( 1 ), $report['applied'], (string) wp_json_encode( $report ) );
		$this->assertSame( 'publish', get_post_status( $draft ) );
		$this->assertNotSame( '2020', get_the_date( 'Y', $draft ) );

		wp_set_current_user( $this->agent );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::call(
				'leitvia/update-content',
				array(
					'post'      => $draft,
					'status'    => 'future',
					'base_hash' => Hashes::document( $draft ),
				)
			)
		);
	}

	/**
	 * Own packages can be discarded, someone else's only with manage_options; the waiting file goes, the package stays.
	 */
	public function test_reject_package(): void {
		self::level( 'suggest' );
		$queued = self::call( 'leitvia/add-media', self::file( self::VTT, 'waits.vtt' ) + array( 'package' => 'discard-1' ) );
		$this->assert_done( $queued );
		$package = (int) $queued['package'];
		$file    = Packages::operations( $package )[0]['state']['file'];
		$this->assertFileExists( $file );

		$reject = static fn() => wp_get_ability( 'leitvia/reject-package' )->execute( array( 'id' => $package ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assert_rejected( 'leitvia_forbidden', $reject() );
		$this->assertSame( 'open', Packages::state( $package ) );

		wp_set_current_user( $this->agent );
		$this->assert_done( $reject() );
		$this->assertSame( 'rejected', Packages::state( $package ) );
		$this->assertNotEmpty( get_post( $package ), 'the package stays for the audit trail' );
		$this->assertFileDoesNotExist( $file );
		$this->assert_rejected( 'leitvia_package_closed', $reject() );
	}

	/**
	 * The privacy policy page: an agent proposes it, a person applies it; the audit reports a contact form without one.
	 */
	public function test_set_privacy_page(): void {
		update_option( 'wp_page_for_privacy_policy', 0 );
		$form   = self::page( '<!-- wp:leitvia/contact-form /-->' );
		$silent = self::page( '<!-- wp:leitvia/contact-form {"privacyNote":false} /-->' );
		$audit  = static fn( int $id ): array => array_column( ( new ReflectionMethod( \LeitviaStudio\Gateway\Abilities\Read_Audit::class, 'item' ) )->invoke( null, get_post( $id ) )['findings'], 'check' );
		$this->assertContains( 'leitvia/privacy-page', $audit( $form ) );
		$this->assertNotContains( 'leitvia/privacy-page', $audit( $silent ) );

		$page   = self::page( "<!-- wp:paragraph -->\n<p>Privacy</p>\n<!-- /wp:paragraph -->" );
		$result = self::call( 'leitvia/set-privacy-page', array( 'page' => $page ) );
		$this->assert_done( $result );
		$this->assertSame( 'package', $result['mode'] );
		$this->assertSame( 0, (int) get_option( 'wp_page_for_privacy_policy' ) );
		$this->assert_rejected( 'leitvia_needs_human', wp_get_ability( 'leitvia/apply-package' )->execute( array( 'id' => (int) $result['package'] ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( array( 1 ), Packages::apply( (int) $result['package'] )['applied'] );
		$this->assertSame( $page, (int) get_option( 'wp_page_for_privacy_policy' ) );
		$this->assertNotContains( 'leitvia/privacy-page', $audit( $form ) );

		wp_set_current_user( $this->agent );
		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/set-privacy-page', array( 'page' => $form + 1000 ) ) );
	}
}
