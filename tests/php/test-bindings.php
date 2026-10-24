<?php
/**
 * Block bindings sources of the content model.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * The sources leitvia/meta, leitvia/setting and leitvia/relation render declared, readable values, escaped, and nothing else.
 */
class Test_Bindings extends WP_UnitTestCase {

	/**
	 * Stores the fixture and registers it; renders as an anonymous visitor.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Model::OPTION, json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/model/events.json' ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		Model::forget();
		Registrar::register();
		update_option( 'date_format', 'd.m.Y' );
		update_option( 'time_format', 'H:i' );
		wp_set_current_user( 0 );
	}

	/**
	 * Unregisters the settings and drops the cached model.
	 */
	public function tear_down(): void {
		foreach ( array( 'company_phone', 'company_email' ) as $key ) {
			unregister_setting( Registrar::SETTINGS_GROUP, Registrar::SETTING_PREFIX . $key );
		}
		Model::forget();
		parent::tear_down();
	}

	/**
	 * Renders a paragraph whose content is bound, with "Fallback" as saved content.
	 *
	 * @param string               $source  Source name.
	 * @param array<string, mixed> $args    Source arguments.
	 * @param int                  $post_id Post of the block context.
	 */
	private function paragraph( string $source, array $args, int $post_id = 0 ): string {
		$attrs = array(
			'metadata' => array(
				'bindings' => array(
					'content' => array(
						'source' => $source,
						'args'   => $args,
					),
				),
			),
		);
		$html  = $this->render( '<!-- wp:paragraph ' . wp_json_encode( $attrs ) . ' --><p>Fallback</p><!-- /wp:paragraph -->', $post_id );
		// Core adds the block class to the paragraph since 7.0.
		return str_replace( '<p class="wp-block-paragraph">', '<p>', $html );
	}

	/**
	 * Renders block markup with the post as context.
	 *
	 * @param string $markup  Block markup.
	 * @param int    $post_id Post ID.
	 */
	private function render( string $markup, int $post_id ): string {
		$block = new WP_Block(
			parse_blocks( $markup )[0],
			array(
				'postId'   => $post_id,
				'postType' => (string) get_post_type( $post_id ),
			)
		);
		return trim( $block->render() );
	}

	/**
	 * Creates a published event with meta.
	 *
	 * @param array<string, mixed> $meta Meta values.
	 * @param array<string, mixed> $post Post fields.
	 */
	private function event( array $meta, array $post = array() ): int {
		return self::factory()->post->create(
			$post + array(
				'post_type'   => 'event',
				'post_status' => 'publish',
				'meta_input'  => $meta,
			)
		);
	}

	/**
	 * A text field renders on the event.
	 */
	public function test_meta_renders_field(): void {
		$event = $this->event( array( 'event_venue' => 'Hall 2' ) );
		$this->assertSame( '<p>Hall 2</p>', $this->paragraph( 'leitvia/meta', array( 'key' => 'event_venue' ), $event ) );
	}

	/**
	 * Dates follow the site format, select fields show their label, numbers stay as stored.
	 */
	public function test_meta_formats_date_and_select(): void {
		$event = $this->event(
			array(
				'event_start' => '2026-11-05T18:30:00',
				'event_level' => 'advanced',
				'event_price' => 12.5,
			)
		);
		$this->assertSame( '<p>05.11.2026 18:30</p>', $this->paragraph( 'leitvia/meta', array( 'key' => 'event_start' ), $event ) );
		$this->assertSame( '<p>Advanced</p>', $this->paragraph( 'leitvia/meta', array( 'key' => 'event_level' ), $event ) );
		$this->assertSame( '<p>12.5</p>', $this->paragraph( 'leitvia/meta', array( 'key' => 'event_price' ), $event ) );
	}

	/**
	 * Keys the model does not declare keep the saved content, even when the meta exists.
	 */
	public function test_undeclared_keys_fall_back(): void {
		$event = $this->event( array( 'event_venue' => 'Hall 2' ) );
		update_post_meta( $event, 'secret_note', 'Do not show' );
		update_post_meta( $event, '_edit_lock', '123:1' );
		foreach ( array( 'secret_note', '_edit_lock', 'event_speakers', '' ) as $key ) {
			$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/meta', array( 'key' => $key ), $event ), $key );
		}
		// A post of a type outside the model has no declared fields.
		$post = self::factory()->post->create( array( 'meta_input' => array( 'event_venue' => 'Hall 2' ) ) );
		$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/meta', array( 'key' => 'event_venue' ), $post ) );
	}

	/**
	 * Drafts, private posts and password-protected posts stay closed to visitors; an editor reads the draft.
	 */
	public function test_unreadable_posts_fall_back(): void {
		$args   = array( 'key' => 'event_venue' );
		$meta   = array( 'event_venue' => 'Hall 2' );
		$draft  = $this->event( $meta, array( 'post_status' => 'draft' ) );
		$secret = $this->event( $meta, array( 'post_status' => 'private' ) );
		$locked = $this->event( $meta, array( 'post_password' => 'secret' ) );
		foreach ( array( $draft, $secret, $locked ) as $post ) {
			$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/meta', $args, $post ), get_post_status( $post ) );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/meta', $args, $draft ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '<p>Hall 2</p>', $this->paragraph( 'leitvia/meta', $args, $draft ) );
	}

	/**
	 * A repeater row is readable when its entry is.
	 */
	public function test_repeater_row_follows_entry(): void {
		$args = array( 'key' => 'session_speaker' );
		foreach ( array(
			'publish' => '<p>Mara</p>',
			'draft'   => '<p>Fallback</p>',
		) as $status => $expected ) {
			$event = $this->event( array(), array( 'post_status' => $status ) );
			$row   = self::factory()->post->create(
				array(
					'post_type'   => 'event_session',
					'post_parent' => $event,
					'meta_input'  => array( 'session_speaker' => 'Mara' ),
				)
			);
			$this->assertSame( $expected, $this->paragraph( 'leitvia/meta', $args, $row ), $status );
		}
	}

	/**
	 * A setting declared as bindable renders; undeclared options and settings without the flag never do.
	 */
	public function test_setting(): void {
		update_option( 'leitvia_site_company_phone', '+49 30 123456' );
		update_option( 'leitvia_site_secret', 'hidden' );
		$this->assertSame( '<p>+49 30 123456</p>', $this->paragraph( 'leitvia/setting', array( 'key' => 'company_phone' ) ) );
		foreach ( array( 'secret', 'admin_email', 'company_email' ) as $key ) {
			$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/setting', array( 'key' => $key ) ), $key );
		}
	}

	/**
	 * Title, field and URL of a related person; a missing index or a trashed person falls back.
	 */
	public function test_relation(): void {
		$person = self::factory()->post->create(
			array(
				'post_type'   => 'person',
				'post_status' => 'publish',
				'post_title'  => 'Mara Lindqvist',
				'meta_input'  => array( 'person_role' => 'Keynote speaker' ),
			)
		);
		$event  = $this->event( array( 'event_speakers' => array( $person ) ) );
		$args   = array( 'relation' => 'event_speakers' );
		$this->assertSame( '<p>Mara Lindqvist</p>', $this->paragraph( 'leitvia/relation', $args + array( 'field' => 'title' ), $event ) );
		$this->assertSame( '<p>Keynote speaker</p>', $this->paragraph( 'leitvia/relation', $args + array( 'field' => 'person_role' ), $event ) );
		$this->assertSame( '<p>Mara Lindqvist</p>', $this->paragraph( 'leitvia/relation', $args, $event ), 'title is the default field' );
		$this->assertSame( '<p>' . esc_html( get_permalink( $person ) ) . '</p>', $this->paragraph( 'leitvia/relation', $args + array( 'field' => 'url' ), $event ) );
		$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/relation', $args + array( 'index' => 5 ), $event ) );
		$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/relation', $args + array( 'field' => 'event_venue' ), $event ), 'field of another type' );

		$button = '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"leitvia/relation","args":{"relation":"event_speakers","field":"url"}},"text":{"source":"leitvia/relation","args":{"relation":"event_speakers","field":"title"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Speaker</a></div><!-- /wp:button -->';
		$html   = $this->render( $button, $event );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $person ) ) . '"', $html );
		$this->assertStringContainsString( '>Mara Lindqvist</a>', $html );

		wp_trash_post( $person );
		$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/relation', $args, $event ) );
	}

	/**
	 * A relation target of a core post type is readable like any other post: published pages show, drafts do not.
	 */
	public function test_relation_to_core_post_type(): void {
		$decl                            = get_option( Model::OPTION );
		$decl['relations']['event_page'] = array(
			'label' => 'Page',
			'from'  => 'event',
			'to'    => 'page',
		);
		update_option( Model::OPTION, $decl );
		Model::forget();
		$page  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Venue guide',
			)
		);
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$args  = array( 'relation' => 'event_page' );
		$this->assertSame( '<p>Venue guide</p>', $this->paragraph( 'leitvia/relation', $args, $this->event( array( 'event_page' => array( $page ) ) ) ) );
		$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/relation', $args, $this->event( array( 'event_page' => array( $draft ) ) ) ) );
	}

	/**
	 * A declared field without a value empties the block; only undeclared or unreadable values keep the saved content.
	 */
	public function test_empty_declared_field_gives_empty_string(): void {
		$event = $this->event( array( 'event_level' => 'unknown' ) );
		$this->assertSame( '<p></p>', $this->paragraph( 'leitvia/meta', array( 'key' => 'event_venue' ), $event ) );
		$this->assertSame( '<p></p>', $this->paragraph( 'leitvia/meta', array( 'key' => 'event_level' ), $event ) );
		$this->assertSame( '<p></p>', $this->paragraph( 'leitvia/setting', array( 'key' => 'company_phone' ) ) );
	}

	/**
	 * Prefix and suffix go around a value, escaped like it, and stay away from an empty value and from a URL.
	 */
	public function test_meta_prefix_and_suffix(): void {
		$event = $this->event( array( 'event_price' => 45 ) );
		$this->assertSame(
			'<p>45 €</p>',
			$this->paragraph(
				'leitvia/meta',
				array(
					'key'    => 'event_price',
					'suffix' => ' €',
				),
				$event
			)
		);
		$this->assertSame(
			'<p>&lt;b&gt;EUR 45</p>',
			$this->paragraph(
				'leitvia/meta',
				array(
					'key'    => 'event_price',
					'prefix' => '<b>EUR ',
				),
				$event
			)
		);
		$this->assertSame(
			'<p></p>',
			$this->paragraph(
				'leitvia/meta',
				array(
					'key'    => 'event_venue',
					'suffix' => ' €',
				),
				$event
			)
		);
	}

	/**
	 * Line breaks of a textarea field become <br> in HTML attributes and stay plain text elsewhere.
	 */
	public function test_textarea_keeps_line_breaks(): void {
		$decl = get_option( Model::OPTION );
		$decl['postTypes']['event']['fields']['event_notes'] = array(
			'type'  => 'textarea',
			'label' => 'Notes',
		);
		update_option( Model::OPTION, $decl );
		Model::forget();
		$event = $this->event(
			array(
				'event_notes' => 'Doors 18:00
<Bring ID>',
			)
		);
		$this->assertSame(
			'<p>Doors 18:00<br>
&lt;Bring ID&gt;</p>',
			$this->paragraph( 'leitvia/meta', array( 'key' => 'event_notes' ), $event )
		);
		$image = new WP_Block(
			parse_blocks( '<!-- wp:image --><figure class="wp-block-image"><img alt=""/></figure><!-- /wp:image -->' )[0],
			array( 'postId' => $event )
		);
		$this->assertSame(
			'Doors 18:00
<Bring ID>',
			LeitviaStudio\Model\Bindings::meta_value( array( 'key' => 'event_notes' ), $image, 'alt' )
		);
	}

	/**
	 * A related draft stays closed to visitors.
	 */
	public function test_relation_to_unreadable_post_falls_back(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$person = self::factory()->post->create(
			array(
				'post_type'   => 'person',
				'post_status' => 'draft',
				'post_title'  => 'Hidden person',
			)
		);
		$event  = $this->event( array( 'event_speakers' => array( $person ) ) );
		wp_set_current_user( 0 );
		$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/relation', array( 'relation' => 'event_speakers' ), $event ) );
	}

	/**
	 * An image binds its URL and alt text to the poster.
	 */
	public function test_image_url_and_alt(): void {
		$image = self::factory()->attachment->create_upload_object( dirname( __DIR__ ) . '/fixtures/avatar.png' );
		update_post_meta( $image, '_wp_attachment_image_alt', 'Poster "Night" & more' );
		$event = $this->event( array( 'event_poster' => $image ) );
		$bind  = array(
			'source' => 'leitvia/meta',
			'args'   => array( 'key' => 'event_poster' ),
		);
		$attrs = array(
			'metadata' => array(
				'bindings' => array(
					'url' => $bind,
					'alt' => $bind,
				),
			),
		);
		$html  = $this->render( '<!-- wp:image ' . wp_json_encode( $attrs ) . ' --><figure class="wp-block-image"><img src="" alt=""/></figure><!-- /wp:image -->', $event );
		$this->assertStringContainsString( 'src="' . esc_url( wp_get_attachment_url( $image ) ) . '"', $html );
		$this->assertStringContainsString( 'alt="Poster &quot;Night&quot; &amp; more"', $html );
		$block = new WP_Block( parse_blocks( '<!-- wp:image /-->' )[0], array( 'postId' => $event ) );
		$this->assertSame( (string) $image, LeitviaStudio\Model\Bindings::meta_value( $bind['args'], $block, 'id' ) );
	}

	/**
	 * Markup in a field shows as text.
	 */
	public function test_output_is_escaped(): void {
		$event = $this->event( array( 'event_venue' => '<script>alert(1)</script><b>Hall</b>' ) );
		$html  = $this->paragraph( 'leitvia/meta', array( 'key' => 'event_venue' ), $event );
		$this->assertSame( '<p>&lt;script&gt;alert(1)&lt;/script&gt;&lt;b&gt;Hall&lt;/b&gt;</p>', $html );
	}

	/**
	 * Without the sources, as after deactivating Studio, core renders the saved content.
	 */
	public function test_without_source_core_renders_saved_content(): void {
		$event = $this->event( array( 'event_venue' => 'Hall 2' ) );
		unregister_block_bindings_source( 'leitvia/meta' );
		try {
			$this->assertSame( '<p>Fallback</p>', $this->paragraph( 'leitvia/meta', array( 'key' => 'event_venue' ), $event ) );
		} finally {
			unregister_block_bindings_source( 'leitvia/setting' );
			unregister_block_bindings_source( 'leitvia/relation' );
			LeitviaStudio\Model\Bindings::register();
		}
	}

	/**
	 * The pattern Entries of a type with the post type of the model and paragraphs bound to its fields and relation lists the
	 * entries with their values, as the skill leitvia-page shows it; posts of other types stay out.
	 */
	public function test_entry_list_pattern_lists_entries_with_fields(): void {
		$speaker = self::factory()->post->create(
			array(
				'post_type'   => 'person',
				'post_title'  => 'Ada Brandt',
				'post_status' => 'publish',
			)
		);
		$this->event(
			array(
				'event_venue'    => 'Hall 2',
				'event_level'    => 'advanced',
				'event_speakers' => array( $speaker ),
			),
			array( 'post_title' => 'Spring walk' )
		);
		self::factory()->post->create( array( 'post_title' => 'A blog post' ) );
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia-studio/teaser-type-entries' );
		$this->assertIsArray( $pattern );
		$field   = static fn( string $source, string $args ): string => sprintf( '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"%s","args":%s}}}} --><p></p><!-- /wp:paragraph -->', $source, $args );
		$excerpt = '<!-- wp:post-excerpt {"excerptLength":30,"textColor":"ink-muted"} /-->';
		$this->assertStringContainsString( $excerpt, $pattern['content'] );
		$markup = str_replace(
			array( '"postType":"post"', $excerpt ),
			array( '"postType":"event"', $field( 'leitvia/meta', '{"key":"event_venue"}' ) . $field( 'leitvia/meta', '{"key":"event_level"}' ) . $field( 'leitvia/relation', '{"relation":"event_speakers"}' ) ),
			$pattern['content']
		);
		$html   = do_blocks( $markup );
		$this->assertStringContainsString( 'Spring walk', $html );
		$this->assertStringContainsString( '>Hall 2</p>', $html );
		$this->assertStringContainsString( '>Advanced</p>', $html );
		$this->assertStringContainsString( '>Ada Brandt</p>', $html );
		$this->assertStringNotContainsString( 'A blog post', $html );
	}
}
