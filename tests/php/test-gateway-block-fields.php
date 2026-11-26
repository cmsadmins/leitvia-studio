<?php
/**
 * Gateway: captions, text tracks, new links, text attributes of static blocks, query settings, status, discarding a
 * package and the privacy policy page.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Catalog;
use LeitviaStudio\Gateway\Block_Edit;
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
	 * Removes the abilities and the category, and the styles the rendered block styles queued: their handle
	 * block-style-variation-styles depends on global-styles, which a later test printing wp_head has not registered.
	 */
	public function tear_down(): void {
		self::unregister();
		wp_clean_theme_json_cache();
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh request.
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

		// An SVG, also under another name, is refused with the way to a raster logo.
		foreach ( array(
			'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
			'logo.png' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>',
		) as $name => $bytes ) {
			$svg = self::call( 'leitvia/add-media', self::file( $bytes, $name ) );
			$this->assert_rejected( 'leitvia_invalid_input', $svg );
			$this->assertStringContainsString( 'PNG or WebP', $svg->get_error_message(), $name );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( self::call( 'leitvia/add-media', self::file( self::VTT, 'denied.vtt' ) ), 'no upload without the right to upload files' );
	}

	/**
	 * Add-media takes a local video, MP4 with H.264 and WebM, without alt text, with a poster from the library as its
	 * featured image and a caption; core reads its length. Other codecs, a wrong extension, a poster that is no image,
	 * a poster for an image and a video above the limit of the filter are refused.
	 */
	public function test_add_media_takes_video(): void {
		$dir    = dirname( __DIR__ ) . '/fixtures/';
		$mp4    = (string) file_get_contents( $dir . 'clip.mp4' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$webm   = (string) file_get_contents( $dir . 'clip.webm' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$poster = self::factory()->attachment->create_upload_object( $dir . 'avatar.png', 0 );

		$result = self::call(
			'leitvia/add-media',
			self::file( $mp4, 'process.mp4' ) + array(
				'poster'  => $poster,
				'caption' => 'The process in five steps, without sound.',
			)
		);
		$this->assert_done( $result );
		$id = (int) $result['target']['post'];
		$this->assertSame( 'video/mp4', get_post_mime_type( $id ) );
		$this->assertTrue( wp_attachment_is( 'video', $id ) );
		$this->assertSame( $mp4, file_get_contents( get_attached_file( $id ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test.
		$this->assertSame( $poster, (int) get_post_thumbnail_id( $id ) );
		$this->assertSame( 'The process in five steps, without sound.', get_post_field( 'post_excerpt', $id ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertArrayHasKey( 'length', (array) wp_get_attachment_metadata( $id ), 'core reads the length as on upload' );

		$webm_result = self::call( 'leitvia/add-media', self::file( $webm, 'process.webm' ) );
		$this->assert_done( $webm_result );
		$this->assertSame( 'video/webm', get_post_mime_type( $webm_result['target']['post'] ) );

		$refused = array(
			'HEVC instead of H.264' => self::file( str_replace( array( 'avc1', 'avc3' ), 'hvc1', $mp4 ), 'hevc.mp4' ),
			'WebM named .mp4'       => self::file( $webm, 'clip.mp4' ),
			'MP4 named .webm'       => self::file( $mp4, 'clip.webm' ),
			'poster is no image'    => self::file( $mp4, 'clip.mp4' ) + array( 'poster' => $id ),
			'poster for an image'   => self::file( (string) file_get_contents( $dir . 'avatar.png' ), 'portrait.png' ) + array( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
				'alt'    => 'Portrait',
				'poster' => $poster,
			),
		);
		foreach ( $refused as $case => $input ) {
			$rejected = self::call( 'leitvia/add-media', $input );
			$this->assertWPError( $rejected, $case );
			$this->assertSame( 'leitvia_invalid_input', $rejected->get_error_code(), $case );
		}

		$limit = static fn(): int => 1000;
		add_filter( 'leitvia_studio_max_video_bytes', $limit );
		$this->assert_rejected( 'leitvia_invalid_input', self::call( 'leitvia/add-media', self::file( $mp4, 'large.mp4' ) ) );
		remove_filter( 'leitvia_studio_max_video_bytes', $limit );
	}

	/**
	 * A video names its poster by file name while the image still waits in the same package: applying writes the image
	 * first and sets it as featured image of the video. Without an image of that name in the package, without a
	 * package key and together with poster the call is refused.
	 */
	public function test_add_media_video_with_waiting_poster(): void {
		$dir   = dirname( __DIR__ ) . '/fixtures/';
		$mp4   = self::file( (string) file_get_contents( $dir . 'clip.mp4' ), 'process.mp4' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$image = self::call(
			'leitvia/add-media',
			self::file( (string) file_get_contents( $dir . 'avatar.png' ), 'still.png' ) + array( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
				'alt'     => 'Still of the process',
				'package' => 'video-1',
			)
		);
		$this->assertSame( 'package', $image['mode'] );

		$refused = array(
			'no image of that name' => array(
				'poster_file' => 'other.png',
				'package'     => 'video-1',
			),
			'no package key'        => array( 'poster_file' => 'still.png' ),
			'poster and file'       => array(
				'poster_file' => 'still.png',
				'poster'      => 1,
				'package'     => 'video-1',
			),
		);
		foreach ( $refused as $case => $input ) {
			$rejected = self::call( 'leitvia/add-media', $mp4 + $input );
			$this->assertWPError( $rejected, $case );
			$this->assertSame( 'leitvia_invalid_input', $rejected->get_error_code(), $case );
		}

		$video = self::call(
			'leitvia/add-media',
			$mp4 + array(
				'poster_file' => 'still.png',
				'package'     => 'video-1',
			)
		);
		$this->assertSame( array( 'package', $image['package'] ), array( $video['mode'], $video['package'] ) );

		$report = Packages::apply( $video['package'] );
		$this->assertSame( array( 1, 2 ), $report['applied'], (string) wp_json_encode( $report ) );
		$applied = array_column( array_column( Packages::detail( $video['package'] )['items'], 'applied' ), 'post' );
		$this->assertTrue( wp_attachment_is( 'video', $applied[1] ) );
		$this->assertSame( $applied[0], get_post_thumbnail_id( $applied[1] ) );
	}

	/**
	 * Add-media with replace puts a new file under the ID and the address of an attachment and keeps its title; the
	 * diff names the old file and says that it goes for good. Another type, further fields and an unknown ID are
	 * refused and leave the file as it is.
	 */
	public function test_add_media_replaces_the_file(): void {
		$old   = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
		$new   = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog /Lang (en) >>\nendobj\n%%EOF\n";
		$added = self::call( 'leitvia/add-media', self::file( $old, 'terms.pdf' ) + array( 'title' => 'Terms' ) );
		$this->assert_done( $added );
		$id   = (int) $added['target']['post'];
		$path = (string) get_attached_file( $id );
		$url  = (string) wp_get_attachment_url( $id );

		$refused = array(
			'another type'  => array( 'leitvia_invalid_input', self::file( self::VTT, 'terms.vtt' ) + array( 'replace' => $id ) ),
			'with a title'  => array(
				'leitvia_invalid_input',
				self::file( $new, 'terms-new.pdf' ) + array(
					'replace' => $id,
					'title'   => 'New terms',
				),
			),
			'an unknown ID' => array( 'leitvia_not_found', self::file( $new, 'terms-new.pdf' ) + array( 'replace' => $id + 1000 ) ),
		);
		foreach ( $refused as $case => $input ) {
			$rejected = self::call( 'leitvia/add-media', $input[1] );
			$this->assertWPError( $rejected, $case );
			$this->assertSame( $input[0], $rejected->get_error_code(), $case );
		}
		$this->assertSame( $old, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test.

		$result = self::call(
			'leitvia/add-media',
			self::file( $new, 'terms-new.pdf' ) + array(
				'replace' => $id,
				'package' => 'terms-1',
			)
		);
		$this->assert_done( $result );
		$this->assertSame( array( 'package', $id ), array( $result['mode'], (int) $result['target']['post'] ) );
		$diff = Packages::detail( $result['package'] )['items'][0]['diff'][0];
		$this->assertSame( array( 'file', 'changed' ), array( $diff['id'], $diff['change'] ) );
		$this->assertStringContainsString( 'terms.pdf', $diff['before_text'] );
		$this->assertStringContainsString( 'gone for good', $diff['after_text'] );
		$this->assertSame( $old, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test.

		// The old file goes for good, so a person with delete_post applies it, as for delete-media.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$report = Packages::apply( $result['package'] );
		$this->assertSame( array( 1 ), $report['applied'], (string) wp_json_encode( $report ) );
		clearstatcache();
		$this->assertSame( $new, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test.
		$this->assertFileDoesNotExist( $path . '.new' );
		$this->assertSame( array( $path, $url, 'Terms' ), array( get_attached_file( $id ), wp_get_attachment_url( $id ), get_the_title( $id ) ) );
	}

	/**
	 * Add-media takes a PDF with its title and without alt text, only with the signature of the format under its
	 * extension and up to the size of an image; any other document and a script under the extension are refused.
	 */
	public function test_add_media_takes_pdf(): void {
		$pdf    = '%PDF-1.4
1 0 obj
<< /Type /Catalog >>
endobj
trailer
<< /Root 1 0 R >>
%%EOF
';
		$result = self::call(
			'leitvia/add-media',
			self::file( $pdf, 'price-list.pdf' ) + array(
				'title' => 'Price list 2026',
				'alt'   => 'A PDF shows no picture',
			)
		);
		$this->assert_done( $result );
		$id = (int) $result['target']['post'];
		$this->assertSame( 'application/pdf', get_post_mime_type( $id ) );
		$this->assertSame( 'Price list 2026', get_the_title( $id ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( $pdf, file_get_contents( get_attached_file( $id ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test.
		$this->assertStringEndsWith( '.pdf', (string) wp_get_attachment_url( $id ) );

		$refused = array(
			'no signature'        => self::file(
				'Price list
',
				'plain.pdf'
			),
			'signature not first' => self::file(
				'<?php echo 1; ?>
' . $pdf,
				'code.pdf'
			),
			'a page as PDF'       => self::file( '<html><script>alert(1)</script></html>', 'page.pdf' ),
			'PDF named .docx'     => self::file( $pdf, 'price-list.docx' ),
			'PDF named .php'      => self::file( $pdf, 'price-list.php' ),
			'PDF named .png'      => self::file( $pdf, 'price-list.png' ) + array( 'alt' => 'Price list' ),
			'above 5 MB'          => self::file( $pdf . str_repeat( ' ', \LeitviaStudio\Gateway\Abilities\Build::MAX_MEDIA_BYTES ), 'large.pdf' ),
			'poster for a PDF'    => self::file( $pdf, 'poster.pdf' ) + array( 'caption' => 'Only a video takes one.' ),
		);
		foreach ( $refused as $case => $input ) {
			$rejected = self::call( 'leitvia/add-media', $input );
			$this->assertWPError( $rejected, $case );
			$this->assertSame( 'leitvia_invalid_input', $rejected->get_error_code(), $case );
		}
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

		// Wider than the text: align on a video, also with its tracks in the same call, and on audio and embeds.
		$content = self::edit(
			$page,
			array(
				'attributes' => array(
					'align'  => 'wide',
					'tracks' => array(),
				),
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertSame( 'wide', parse_blocks( $content )[0]['attrs']['align'] );
		$this->assertStringContainsString( '<figure class="wp-block-video alignwide">', $content );
		$content = self::edit( $page, array( 'attributes' => array( 'align' => null ) ) );
		$this->assertStringContainsString( '<figure class="wp-block-video">', $content );
		$this->assertArrayNotHasKey( 'align', parse_blocks( $content )[0]['attrs'] );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'align' => 'huge' ) ) ) );
		$cases = array(
			'<!-- wp:audio --><figure class="wp-block-audio"><audio controls src="/a.mp3"></audio></figure><!-- /wp:audio -->' => 'wp-block-audio',
			'<!-- wp:embed {"url":"https://example.com/v","type":"video","providerNameSlug":"example"} --><figure class="wp-block-embed is-type-video is-provider-example wp-block-embed-example"><div class="wp-block-embed__wrapper">https://example.com/v</div></figure><!-- /wp:embed -->' => 'wp-block-embed',
		);
		foreach ( $cases as $markup => $class ) {
			$content = self::edit( self::page( $markup ), array( 'attributes' => array( 'align' => 'full' ) ) );
			$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
			$this->assertMatchesRegularExpression( '/<figure class="' . $class . '[^"]* alignfull">/', $content );
		}
	}

	/**
	 * The field video fills the empty video block of a pattern from the media library: the element as the editor saves
	 * it, the ID in the comment, the poster of the video or the one named, with tracks, align and caption in the same
	 * call. A second video replaces the first and keeps flags and tracks. Other blocks, files that are no video and a
	 * poster that is no image are refused; the catalog lists only what attributes takes.
	 */
	public function test_update_block_video_from_the_library(): void {
		$dir    = dirname( __DIR__ ) . '/fixtures/';
		$video  = self::factory()->attachment->create_upload_object( $dir . 'clip.mp4', 0 );
		$other  = self::factory()->attachment->create_upload_object( $dir . 'clip.webm', 0 );
		$poster = self::factory()->attachment->create_upload_object( $dir . 'avatar.png', 0 );
		$still  = self::factory()->attachment->create_upload_object( $dir . 'avatar.png', 0 );
		update_post_meta( $video, '_thumbnail_id', $poster );
		$track = $this->track();
		$page  = self::page( "<!-- wp:video -->\n<figure class=\"wp-block-video\"><figcaption class=\"wp-element-caption\">Placeholder</figcaption></figure>\n<!-- /wp:video -->" );

		$content = self::edit(
			$page,
			array(
				'video'      => array( 'id' => $video ),
				'caption'    => 'The process in five steps.',
				'attributes' => array(
					'align'  => 'wide',
					'tracks' => array(
						array(
							'id'      => $track,
							'srclang' => 'en',
							'label'   => 'English',
						),
					),
				),
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<figure class="wp-block-video alignwide"><video controls poster="' . wp_get_attachment_url( $poster ) . '" preload="none" src="' . wp_get_attachment_url( $video ) . '"><track ', $content );
		$this->assertStringContainsString( '</video><figcaption class="wp-element-caption">The process in five steps.</figcaption></figure>', $content );
		$block = parse_blocks( $content )[0];
		// metadata holds the block ID Block_Ids adds on save.
		$this->assertSame( array( 'id', 'tracks', 'align' ), array_keys( array_diff_key( $block['attrs'], array( 'metadata' => true ) ) ) );
		$this->assertSame( $video, $block['attrs']['id'] );

		// Another video with a poster of its own: one element, the flags and the tracks stay.
		$page    = self::page( "<!-- wp:video {\"id\":1} -->\n<figure class=\"wp-block-video\"><video controls loop muted src=\"/old.mp4\" playsinline><track src=\"/a.vtt\" label=\"A\" srclang=\"en\" kind=\"captions\"/></video></figure>\n<!-- /wp:video -->" );
		$content = self::edit(
			$page,
			array(
				'video' => array(
					'id'     => $other,
					'poster' => $still,
				),
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<figure class="wp-block-video"><video controls loop muted poster="' . wp_get_attachment_url( $still ) . '" preload="none" src="' . wp_get_attachment_url( $other ) . '" playsinline><track src="/a.vtt"', $content );
		$this->assertSame( 1, substr_count( $content, '<video' ) );
		$this->assertStringNotContainsString( '/old.mp4', $content );
		$this->assertSame( $other, parse_blocks( $content )[0]['attrs']['id'] );

		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'video' => array( 'id' => $poster ) ) ) );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::edit(
				$page,
				array(
					'video' => array(
						'id'     => $video,
						'poster' => $track,
					),
				)
			)
		);
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'src' => '/other.mp4' ) ) ) );
		$text = self::page( "<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->" );
		$this->assert_rejected( 'leitvia_static_markup', self::edit( $text, array( 'video' => array( 'id' => $video ) ) ) );

		$catalog = array_column( Catalog::get(), null, 'name' );
		$this->assertSame( array( 'tracks' ), array_keys( $catalog['core/video']['attributes']['properties'] ) );
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
	 * The field replace_text changes words in a text with two links and inline formatting: links, targets and formatting stay,
	 * inside a link or a strong too. Words that stand twice, nowhere, only in an attribute or across a tag are refused
	 * with a pointer to what to send; text on such a paragraph names replace_text. update-content takes it as well.
	 */
	public function test_replace_text_keeps_links_and_formatting(): void {
		$page = self::page( "<!-- wp:paragraph -->\n<p>Plans start at <strong>129 €</strong> per month. See <a href=\"/pricing/\">all plans</a> or <a href=\"/contact/\">ask us</a>, Tom &amp; Jerry.</p>\n<!-- /wp:paragraph -->" );

		$refused = self::edit( $page, array( 'text' => 'Plans start at 149 € per month.' ) );
		$this->assert_rejected( 'leitvia_static_markup', $refused );
		$this->assertStringContainsString( 'replace_text', $refused->get_error_message() );

		$steps = array(
			array( 'per month', 'a month' ),
			array( '129 €', '149 €' ),
			array( 'all plans', 'every plan' ),
			array( 'Tom & Jerry', 'Tom & Friends' ),
		);
		foreach ( $steps as $step ) {
			$content = self::edit(
				$page,
				array(
					'replace_text' => array(
						'find' => $step[0],
						'with' => $step[1],
					),
				)
			);
			$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		}
		$this->assertStringContainsString( '<p>Plans start at <strong>149 €</strong> a month. See <a href="/pricing/">every plan</a> or <a href="/contact/">ask us</a>, Tom &amp; Friends.</p>', get_post( $page )->post_content );

		$refused = array(
			'at 149 € a' => 'across',
			'a'          => 'times',
			'yearly'     => 'does not contain',
			'pricing'    => 'does not contain',
		);
		foreach ( $refused as $find => $reason ) {
			$result = self::edit(
				$page,
				array(
					'replace_text' => array(
						'find' => $find,
						'with' => 'x',
					),
				)
			);
			$this->assert_rejected( 'leitvia_invalid_input', $result );
			$this->assertSame( 'replace_text', $result->get_error_data()['reason'] );
			$this->assertStringContainsString( $reason, $result->get_error_message(), $find );
		}
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::edit(
				$page,
				array(
					'text'         => 'New',
					'replace_text' => array(
						'find' => 'ask us',
						'with' => 'write to us',
					),
				)
			)
		);
		$this->assertStringContainsString( 'href="/pricing/"', get_post( $page )->post_content );

		$result = self::call(
			'leitvia/update-content',
			array(
				'post'      => $page,
				'blocks'    => array(
					array(
						'block'        => 'p:0',
						'replace_text' => array(
							'find' => 'ask us',
							'with' => 'write to us',
						),
					),
				),
				'base_hash' => Hashes::document( $page ),
			)
		);
		$this->assert_done( $result );
		$this->assertStringContainsString( '<a href="/contact/">write to us</a>', get_post( $page )->post_content );
	}

	/**
	 * A find with normal spaces finds protected ones (&nbsp;, U+00A0, U+202F) and replaces the place as it stands. A
	 * repeated run into the same open package is checked against the state of that package, and the rejection says so.
	 */
	public function test_replace_text_protected_spaces_and_package_state(): void {
		$page = self::page( "<!-- wp:paragraph -->\n<p>Care from&nbsp;89\u{202F}€ a month, <a href=\"/plans/\">all plans</a>.</p>\n<!-- /wp:paragraph -->" );
		$done = self::edit(
			$page,
			array(
				'replace_text' => array(
					'find' => 'from 89 €',
					'with' => 'from 99 €',
				),
			)
		);
		$this->assertIsString( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$this->assertStringContainsString( '<p>Care from 99 € a month, <a href="/plans/">all plans</a>.</p>', $done );

		$input = array(
			'post'      => $page,
			'blocks'    => array(
				array(
					'block'        => 'p:0',
					'replace_text' => array(
						'find' => 'a month',
						'with' => 'per month',
					),
				),
			),
			'package'   => 'q1-replace-twice',
			'base_hash' => Hashes::document( $page ),
		);
		$this->assert_done( self::call( 'leitvia/update-content', $input ) );
		$again = self::call( 'leitvia/update-content', $input );
		$this->assert_rejected( 'leitvia_invalid_input', $again );
		$this->assertStringContainsString( 'q1-replace-twice', $again->get_error_message() );
		$this->assertSame( 'q1-replace-twice', $again->get_error_data()['package_key'] );
	}

	/**
	 * The ability update-content sets and clears the kind of a page; a legal kind waits in a package, other post types refuse it.
	 */
	public function test_update_content_sets_kind(): void {
		$page = self::page( "<!-- wp:paragraph -->\n<p>Our rules.</p>\n<!-- /wp:paragraph -->" );
		$set  = static fn( string $kind, int $post ): mixed => self::call(
			'leitvia/update-content',
			array(
				'post'      => $post,
				'kind'      => $kind,
				'base_hash' => Hashes::document( $post ),
			)
		);
		$this->assert_done( $set( 'editorial', $page ) );
		$this->assertSame( 'editorial', get_post_meta( $page, '_leitvia_page_kind', true ) );
		$legal = $set( 'imprint', $page );
		$this->assert_done( $legal );
		$this->assertSame( 'package', $legal['mode'] );
		$this->assertSame( 'editorial', get_post_meta( $page, '_leitvia_page_kind', true ) );
		$this->assert_done( $set( '', $page ) );
		$this->assertSame( '', get_post_meta( $page, '_leitvia_page_kind', true ) );
		$this->assert_rejected( 'leitvia_invalid_input', $set( 'editorial', self::factory()->post->create() ) );
	}

	/**
	 * An attribute null goes back to its default on every block: an attribute of the comment leaves it, also on a new
	 * block of insert and on one outside the catalog, the level of a heading goes back to h2.
	 */
	public function test_null_resets_an_attribute(): void {
		$icon    = self::page( '<!-- wp:leitvia/icon {"icon":"phone","label":"Call us"} /-->' );
		$content = self::edit( $icon, array( 'attributes' => array( 'label' => null ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertSame( array( 'icon' => 'phone' ), array_diff_key( parse_blocks( $content )[0]['attrs'], array( 'metadata' => true ) ) );
		$content = self::edit( $icon, array( 'attributes' => array( 'icon' => null ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertSame( array(), array_diff_key( parse_blocks( $content )[0]['attrs'], array( 'metadata' => true ) ) );

		$heading = self::page( '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Title</h3><!-- /wp:heading -->' );
		$content = self::edit( $heading, array( 'attributes' => array( 'level' => null ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertSame( '<h2 class="wp-block-heading">Title</h2>', parse_blocks( $content )[0]['innerHTML'] );
		$this->assertArrayNotHasKey( 'level', parse_blocks( $content )[0]['attrs'] );

		$built = LeitviaStudio\Gateway\Block_Edit::build(
			array(
				'block'      => 'leitvia/icon',
				'attributes' => array(
					'icon'  => 'mail',
					'label' => null,
				),
			),
			array(),
			'core/group'
		);
		$this->assertIsArray( $built, is_wp_error( $built ) ? $built->get_error_message() : '' );
		$this->assertSame( 'mail', $built['attrs']['icon'] );
		$this->assertArrayNotHasKey( 'label', $built['attrs'] );

		$logo = LeitviaStudio\Gateway\Block_Edit::build(
			array(
				'block'      => 'core/site-logo',
				'attributes' => array( 'width' => null ),
			),
			array(),
			'core/group'
		);
		$this->assertIsArray( $logo, is_wp_error( $logo ) ? $logo->get_error_message() : '' );
		$this->assertArrayNotHasKey( 'width', $logo['attrs'] );
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
	 * A registered block style goes onto any core block that has styles, not only media blocks: the class swaps in the
	 * comment and on the element, other classes stay, null removes it. Unknown styles and other classes are refused.
	 */
	public function test_update_block_style_of_a_core_block(): void {
		$registered = WP_Block_Styles_Registry::get_instance()->is_registered( 'core/group', 'inverse' );
		if ( ! $registered ) {
			register_block_style(
				'core/group',
				array(
					'name'  => 'inverse',
					'label' => 'Inverse',
				)
			);
		}
		$page = self::page( '<!-- wp:group {"className":"band is-style-tile","layout":{"type":"constrained"}} --><div class="wp-block-group band is-style-tile"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );

		$content = self::edit( $page, array( 'attributes' => array( 'className' => 'is-style-inverse' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$block = parse_blocks( $content )[0];
		$this->assertSame( 'band is-style-inverse', $block['attrs']['className'] );
		$this->assertSame( array( 'type' => 'constrained' ), $block['attrs']['layout'] );
		$this->assertStringContainsString( '<div class="wp-block-group band is-style-inverse">', $block['innerContent'][0] );
		$this->assertStringNotContainsString( 'is-style-tile', $content );
		$this->assertStringContainsString( '<p>Hello</p>', $content );

		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'className' => 'is-style-unbekannt' ) ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'className' => 'beliebig' ) ) ) );

		// Styles that combine, such as a sticky and an overlay header, go space separated; each must be a style.
		register_block_style(
			'core/group',
			array(
				'name'  => 'test-pair',
				'label' => 'Pair',
			)
		);
		$pair = self::edit( $page, array( 'attributes' => array( 'className' => 'is-style-inverse is-style-test-pair' ) ) );
		$this->assertIsString( $pair, is_wp_error( $pair ) ? $pair->get_error_message() : '' );
		$this->assertSame( 'band is-style-inverse is-style-test-pair', parse_blocks( $pair )[0]['attrs']['className'] );
		$this->assertStringContainsString( '<div class="wp-block-group band is-style-inverse is-style-test-pair">', $pair );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'className' => 'is-style-inverse is-style-unbekannt' ) ) ) );
		unregister_block_style( 'core/group', 'test-pair' );

		// A new className goes before layout, where the editor saves it.
		$plain = self::page( '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
		$saved = self::edit( $plain, array( 'attributes' => array( 'className' => 'is-style-inverse' ) ) );
		$this->assertIsString( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
		$keys = array_keys( parse_blocks( $saved )[0]['attrs'] );
		$this->assertLessThan( array_search( 'layout', $keys, true ), array_search( 'className', $keys, true ) );
		$this->assertStringContainsString( '<div class="wp-block-group is-style-inverse">', $saved );

		$content = self::edit( $page, array( 'attributes' => array( 'className' => null ) ) );
		if ( ! $registered ) {
			unregister_block_style( 'core/group', 'inverse' );
		}
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$block = parse_blocks( $content )[0];
		$this->assertSame( 'band', $block['attrs']['className'] );
		$this->assertStringContainsString( '<div class="wp-block-group band">', $block['innerContent'][0] );
		$this->assertStringNotContainsString( 'is-style-', $content );
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
	 * The filters taxQuery in both formats of core, replaced as a whole; keys of add-ons only with the filter
	 * leitvia_studio_query_attribute_keys and as its schema says; the catalog names both.
	 */
	public function test_update_block_query_terms_and_addon_keys(): void {
		$inner = '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div>';
		$page  = self::page( '<!-- wp:query {"queryId":3,"query":{"perPage":3,"postType":"post","inherit":false,"taxQuery":{"post_tag":[9]}}} -->' . $inner . '<!-- /wp:query -->' );

		$content = self::edit( $page, array( 'attributes' => array( 'query' => array( 'taxQuery' => array( 'category' => array( 12 ) ) ) ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$query = parse_blocks( $content )[0]['attrs']['query'];
		$this->assertSame( array( 'category' => array( 12 ) ), $query['taxQuery'] );
		$this->assertSame( 3, $query['perPage'] );

		$new     = array(
			'include' => array( 'category' => array( 12 ) ),
			'exclude' => array( 'post_tag' => array( 34 ) ),
		);
		$content = self::edit( $page, array( 'attributes' => array( 'query' => array( 'taxQuery' => $new ) ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertSame( $new, parse_blocks( $content )[0]['attrs']['query']['taxQuery'] );

		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'query' => array( 'taxQuery' => array( 'category' => array( 'news' ) ) ) ) ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'query' => array( 'taxQuery' => array( 'include' => array( 'category' => array( 0 ) ) ) ) ) ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'query' => array( 'myAddonList' => 'a' ) ) ) ) );

		$keys = static fn( array $keys ): array => $keys + array(
			'myAddonList' => array(
				'type' => 'string',
				'enum' => array( 'a', 'b' ),
			),
			'perPage'     => array( 'type' => 'string' ),
		);
		add_filter( 'leitvia_studio_query_attribute_keys', $keys );
		try {
			$content = self::edit( $page, array( 'attributes' => array( 'query' => array( 'myAddonList' => 'b' ) ) ) );
			$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
			$this->assertSame( 'b', parse_blocks( $content )[0]['attrs']['query']['myAddonList'] );
			$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'query' => array( 'myAddonList' => 'c' ) ) ) ) );
			$this->assert_rejected( 'leitvia_invalid_input', self::edit( $page, array( 'attributes' => array( 'query' => array( 'perPage' => 'six' ) ) ) ), 'an add-on cannot replace a key of core' );
			$this->assertArrayHasKey( 'myAddonList', Block_Edit::core_attributes( 'core/query' )['query']['properties'] );
		} finally {
			remove_filter( 'leitvia_studio_query_attribute_keys', $keys );
		}
		$this->assertArrayHasKey( 'taxQuery', array_column( Catalog::get(), null, 'name' )['core/query']['attributes']['properties']['query']['properties'] );
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

	/**
	 * An empty url removes the saved link of a button whose url a binding fills, through the whole pipeline: the HTML
	 * API leaves a space where href was (<a class="..." >), which is no unsafe markup. The markup is that of a stored
	 * button of a job posting pattern after an agent gave it a link.
	 */
	public function test_update_block_removes_the_saved_link_of_a_bound_button(): void {
		register_block_bindings_source(
			'example/job-posting',
			array(
				'label'              => 'Job posting',
				'get_value_callback' => static fn(): string => '/apply/',
			)
		);
		$page   = self::page( '<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons"><!-- wp:button {"width":100,"metadata":{"leitviaId":"a1b2c3d4","bindings":{"url":{"source":"example/job-posting"}}}} -->' . "\n" . '<div class="wp-block-button has-custom-width wp-block-button__width-100"><a class="wp-block-button__link wp-element-button" href="' . home_url( '/contact/' ) . '">Apply now</a></div>' . "\n" . '<!-- /wp:button --></div>' . "\n" . '<!-- /wp:buttons -->' );
		$result = self::call(
			'leitvia/update-block',
			array(
				'post'      => $page,
				'block'     => 'a1b2c3d4',
				'url'       => '',
				'base_hash' => Hashes::document( $page ),
			)
		);
		unregister_block_bindings_source( 'example/job-posting' );
		$this->assert_done( $result );
		$content = get_post( $page )->post_content;
		$this->assertStringNotContainsString( 'href=', $content );
		$this->assertStringContainsString( '"bindings":{"url":{"source":"example/job-posting"}}', $content, 'the binding stays' );
		$this->assertStringContainsString( '>Apply now</a>', $content );
	}

	/**
	 * The attribute align goes on every block with supports.align, with the values it supports: in the comment of a block that
	 * renders on the server, also as class on the outer element of a static block; null removes it. A value outside
	 * supports is refused. An inserted block takes it as well.
	 */
	public function test_update_block_align_follows_supports(): void {
		$embed = self::page( '<!-- wp:leitvia/consent-embed {"url":"https://planner.example.com/embed","title":"Planner"} /-->' );
		$this->assertIsString( self::edit( $embed, array( 'attributes' => array( 'align' => 'wide' ) ) ) );
		$this->assertSame( 'wide', parse_blocks( get_post( $embed )->post_content )[0]['attrs']['align'] );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $embed, array( 'attributes' => array( 'align' => 'left' ) ) ) );
		$this->assertIsString( self::edit( $embed, array( 'attributes' => array( 'align' => null ) ) ) );
		$this->assertArrayNotHasKey( 'align', parse_blocks( get_post( $embed )->post_content )[0]['attrs'] );

		$group   = self::page( '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
		$content = self::edit( $group, array( 'attributes' => array( 'align' => 'full' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		// The pipeline gives the group its metadata.leitviaId first, so the comment is matched from align on.
		$this->assertMatchesRegularExpression( '/<!-- wp:group \{("metadata":\{[^}]*\},)?"align":"full","layout":\{"type":"constrained"\}\} --><div class="wp-block-group alignfull">/', $content, 'align before layout, as the editor saves it' );
		$content = self::edit( $group, array( 'attributes' => array( 'align' => 'wide' ) ) );
		$this->assertStringContainsString( '<div class="wp-block-group alignwide">', $content );

		$inserted = \LeitviaStudio\Gateway\Block_Edit::build(
			array(
				'block'      => 'leitvia/consent-embed',
				'attributes' => array(
					'url'   => 'https://planner.example.com/embed',
					'title' => 'Planner',
					'align' => 'wide',
				),
			),
			array(),
			'core/group'
		);
		$this->assertIsArray( $inserted, is_wp_error( $inserted ) ? $inserted->get_error_message() : '' );
		$this->assertSame( 'wide', $inserted['attrs']['align'] );
	}
	/**
	 * Presets and counts a section needs without new markup: the ground of a light group (surface, surface-alt), the
	 * font size of a heading, the columns of a gallery and the name for screen readers a block keeps as aria-label.
	 * Attribute and class change together, as the editor saves them; anything else is refused.
	 */
	public function test_update_block_presets_gallery_columns_and_label(): void {
		$group   = self::page( '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
		$content = self::edit( $group, array( 'attributes' => array( 'backgroundColor' => 'surface-alt' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertSame( 'surface-alt', parse_blocks( $content )[0]['attrs']['backgroundColor'] );
		$this->assertMatchesRegularExpression( '/<div class="wp-block-group has-surface-alt-background-color has-background">/', $content );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $group, array( 'attributes' => array( 'backgroundColor' => 'ink' ) ) ) );
		$content = self::edit( $group, array( 'attributes' => array( 'backgroundColor' => null ) ) );
		$this->assertStringContainsString( '<div class="wp-block-group">', $content );
		$this->assertArrayNotHasKey( 'backgroundColor', parse_blocks( $content )[0]['attrs'] );

		$dark = self::page( '<!-- wp:group {"backgroundColor":"ink","textColor":"surface","layout":{"type":"constrained"}} --><div class="wp-block-group has-surface-color has-ink-background-color has-text-color has-background"><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $dark, array( 'attributes' => array( 'backgroundColor' => 'surface' ) ) ) );

		$heading = self::page( '<!-- wp:heading --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->' );
		$content = self::edit( $heading, array( 'attributes' => array( 'fontSize' => 'heading-m' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<h2 class="wp-block-heading has-heading-m-font-size">Title</h2>', $content );
		$this->assertSame( 'heading-m', parse_blocks( $content )[0]['attrs']['fontSize'] );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $heading, array( 'attributes' => array( 'fontSize' => 'giant' ) ) ) );

		$image   = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.org/a.jpg" alt="A"/></figure><!-- /wp:image -->';
		$gallery = self::page( '<!-- wp:gallery {"columns":4,"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-4 is-cropped">' . $image . $image . '</figure><!-- /wp:gallery -->' );
		$content = self::edit( $gallery, array( 'columns' => 2 ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertSame( 2, parse_blocks( $content )[0]['attrs']['columns'] );
		$this->assertStringContainsString( 'columns-2', $content );
		$this->assertStringNotContainsString( 'columns-4', $content );
		// The input schema of the ability stops a count outside 1 to 6 before the edit runs.
		$this->assert_rejected( 'ability_invalid_input', self::edit( $gallery, array( 'columns' => 9 ) ) );

		// The count caps the minimum column width at a share of 64rem, so three fit where two of 31rem did.
		$markup  = '<!-- wp:group {"layout":{"type":"grid","columnCount":2,"minimumColumnWidth":"31rem"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$content = self::edit( self::page( $markup ), array( 'columns' => 3 ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertSame( 3, parse_blocks( $content )[0]['attrs']['layout']['columnCount'] );
		$this->assertSame( '21.33rem', parse_blocks( $content )[0]['attrs']['layout']['minimumColumnWidth'] );
		// A minimum below the cap stays; a fresh page, as the edit above stored 21.33rem.
		$content = self::edit( self::page( $markup ), array( 'columns' => 2 ) );
		$this->assertSame( '31rem', parse_blocks( $content )[0]['attrs']['layout']['minimumColumnWidth'] );

		$bar     = self::page( '<!-- wp:leitvia/announcement-bar --><section class="wp-block-leitvia-announcement-bar alignfull" aria-label="Announcement"><p class="wp-block-leitvia-announcement-bar__text">New prices</p></section><!-- /wp:leitvia/announcement-bar -->' );
		$content = self::edit( $bar, array( 'attributes' => array( 'label' => 'Hinweis' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( 'aria-label="Hinweis"', $content );
		$this->assertStringContainsString( '>New prices</p>', $content );
	}

	/**
	 * Text color and size where the supports allow them: a heading takes a text role, the icon (no markup of its own)
	 * a color role and a font size in its comment, the icon list both as classes of its list; a cover its height in vh.
	 */
	public function test_update_block_text_colors_icon_presets_and_cover_height(): void {
		$heading = self::page( '<!-- wp:heading {"textColor":"ink-muted"} --><h2 class="wp-block-heading has-ink-muted-color has-text-color">Title</h2><!-- /wp:heading -->' );
		$content = self::edit( $heading, array( 'attributes' => array( 'textColor' => 'accent' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertMatchesRegularExpression( '/<h2 class="(?=[^"]*has-accent-color)(?=[^"]*has-text-color)(?![^"]*has-ink-muted-color)[^"]*">Title<\/h2>/', $content );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $heading, array( 'attributes' => array( 'textColor' => 'surface' ) ) ) );

		$icon    = self::page( '<!-- wp:leitvia/icon {"icon":"gauge"} /-->' );
		$content = self::edit(
			$icon,
			array(
				'attributes' => array(
					'textColor' => 'accent',
					'fontSize'  => 'heading-m',
				),
			)
		);
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$attrs = parse_blocks( $content )[0]['attrs'];
		$this->assertSame( array( 'accent', 'heading-m' ), array( $attrs['textColor'], $attrs['fontSize'] ) );
		$this->assertStringContainsString( 'has-accent-color', do_blocks( $content ) );

		$list    = self::page( '<!-- wp:leitvia/icon-list --><ul role="list" class="wp-block-leitvia-icon-list"><!-- wp:leitvia/icon-list-item --><li class="wp-block-leitvia-icon-list-item"><span class="wp-block-leitvia-icon-list-item__text">A</span></li><!-- /wp:leitvia/icon-list-item --></ul><!-- /wp:leitvia/icon-list -->' );
		$content = self::edit( $list, array( 'attributes' => array( 'textColor' => 'ink-muted' ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( 'class="wp-block-leitvia-icon-list has-ink-muted-color has-text-color"', $content );

		$cover   = self::page( '<!-- wp:cover {"dimRatio":80,"isDark":true} --><div class="wp-block-cover is-dark"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-80 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->' );
		$content = self::edit( $cover, array( 'attributes' => array( 'minHeight' => 90 ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( 'style="min-height:90vh"', $content );
		$this->assertSame( array( 90, 'vh' ), array( parse_blocks( $content )[0]['attrs']['minHeight'], parse_blocks( $content )[0]['attrs']['minHeightUnit'] ) );
		$this->assert_rejected( 'leitvia_invalid_input', self::edit( $cover, array( 'attributes' => array( 'minHeight' => 120 ) ) ) );
	}

	/**
	 * A cover takes the focal point of its photo as the editor saves it, as object-position of the background image in
	 * whole percent; a share outside 0 to 1 is refused, null puts the photo back in the middle.
	 */
	public function test_cover_focal_point(): void {
		$url     = home_url( '/wp-content/uploads/stage.jpg' );
		$cover   = self::page( '<!-- wp:cover {"url":"' . $url . '","dimRatio":70} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" alt="" src="' . $url . '" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim-70 has-background-dim"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->' );
		$point   = array(
			'x' => 0.3,
			'y' => 0.25,
		);
		$content = self::edit( $cover, array( 'attributes' => array( 'focalPoint' => $point ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( 'data-object-position="30% 25%"', $content );
		$this->assertStringContainsString( 'style="object-position:30% 25%"', $content );
		$this->assertEquals( $point, parse_blocks( $content )[0]['attrs']['focalPoint'] );
		$this->assertStringContainsString( 'object-position:30% 25%', do_blocks( $content ) );
		$this->assert_rejected(
			'leitvia_invalid_input',
			self::edit(
				$cover,
				array(
					'attributes' => array(
						'focalPoint' => array(
							'x' => 1.5,
							'y' => 0,
						),
					),
				)
			)
		);
		$content = self::edit( $cover, array( 'attributes' => array( 'focalPoint' => null ) ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringNotContainsString( 'object-position', $content );
		$this->assertArrayNotHasKey( 'focalPoint', parse_blocks( $content )[0]['attrs'] );
	}

	/**
	 * An empty url ("") without link_text takes the one link out of a heading and keeps its words, such as the head of a mega menu
	 * column without a page; a new text without the old words still needs link_text, two links stay refused.
	 */
	public function test_empty_url_removes_the_link_of_a_text(): void {
		$head    = '<!-- wp:heading {"fontSize":"meta"} -->' . "\n" . '<h2 class="wp-block-heading has-meta-font-size"><a href="/services/">Services</a></h2>' . "\n" . '<!-- /wp:heading -->';
		$content = Block_Edit::apply( $head, 'p:0', array( 'url' => '' ) );
		$this->assertIsString( $content, is_wp_error( $content ) ? $content->get_error_message() : '' );
		$this->assertStringContainsString( '<h2 class="wp-block-heading has-meta-font-size">Services</h2>', $content );
		$content = Block_Edit::apply(
			$head,
			'p:0',
			array(
				'text' => 'Exhibitions',
				'url'  => '',
			)
		);
		$this->assertIsString( $content );
		$this->assertStringContainsString( '>Exhibitions</h2>', $content );
		$this->assertStringNotContainsString( '<a', $content );
		$this->assert_rejected( 'leitvia_invalid_input', Block_Edit::apply( $head, 'p:0', array( 'text' => 'Exhibitions' ) ) );
		$two = '<!-- wp:paragraph -->' . "\n" . '<p><a href="/a/">A</a> and <a href="/b/">B</a></p>' . "\n" . '<!-- /wp:paragraph -->';
		$this->assertWPError( Block_Edit::apply( $two, 'p:0', array( 'url' => '' ) ) );
	}
}
