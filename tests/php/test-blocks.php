<?php
/**
 * Studio blocks: build, registry, category, assets, saved markup.
 *
 * @package LeitviaStudio
 */

/**
 * Every folder in build/blocks/ reaches core through the blocks registry, assets load only with the block.
 */
class Test_Blocks extends WP_UnitTestCase {

	/**
	 * Resets the queues the asset tests fill.
	 */
	public function tear_down(): void {
		foreach ( self::studio_handles() as $handle ) {
			wp_dequeue_script( $handle );
			wp_dequeue_style( $handle );
			wp_script_modules()->dequeue( $handle );
		}
		parent::tear_down();
	}

	/**
	 * Script, style and module handles of every registered leitvia/* block.
	 *
	 * @return string[]
	 */
	private static function studio_handles(): array {
		$handles = array();
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( str_starts_with( $name, 'leitvia/' ) ) {
				$handles = array_merge( $handles, $type->editor_script_handles, $type->script_handles, $type->view_script_handles, $type->view_script_module_ids, $type->editor_style_handles, $type->style_handles, $type->view_style_handles );
			}
		}
		return array_unique( $handles );
	}

	/**
	 * Queued handles of Studio: anything named leitvia-studio* plus the assets of Studio blocks.
	 *
	 * @return string[]
	 */
	private static function queued_studio_handles(): array {
		$queued = array_merge( wp_scripts()->queue, wp_styles()->queue, array_keys( wp_script_modules()->get_queue() ) );
		return array_values(
			array_filter(
				$queued,
				static fn( string $handle ): bool => str_starts_with( $handle, 'leitvia-studio' ) || in_array( $handle, self::studio_handles(), true )
			)
		);
	}

	/**
	 * Saved timeline markup, as in tests/fixtures/blocks/timeline.html (the test page fixture is not mounted in wp-env).
	 */
	private static function fixture(): string {
		$item = static fn( string $date, string $heading ): string => '<!-- wp:leitvia/timeline-item {"level":2} --><li class="wp-block-leitvia-timeline-item"><time class="wp-block-leitvia-timeline-item__date" datetime="' . $date . '">' . $date . '</time><h2 class="wp-block-leitvia-timeline-item__heading">' . $heading . '</h2><div class="wp-block-leitvia-timeline-item__content"><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div></li><!-- /wp:leitvia/timeline-item -->';
		return '<!-- wp:leitvia/timeline --><ol class="wp-block-leitvia-timeline">' . $item( '2019-03', 'Opened' ) . $item( '2024', 'Grown' ) . '</ol><!-- /wp:leitvia/timeline -->';
	}

	/**
	 * The timeline and its entry are registered with core after init.
	 */
	public function test_timeline_is_registered(): void {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'leitvia/timeline' ) );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'leitvia/timeline-item' ) );
	}

	/**
	 * Every built block folder is an entry leitvia/<folder> of the blocks registry, owned by Studio. The product
	 * comparison needs WooCommerce, which this run does not load (test-product-compare.php).
	 */
	public function test_every_build_folder_is_a_registry_entry(): void {
		$folders = glob( dirname( LEITVIA_STUDIO_FILE ) . '/build/blocks/*', GLOB_ONLYDIR );
		$this->assertNotEmpty( $folders );
		foreach ( (array) $folders as $folder ) {
			if ( 'product-compare' === basename( $folder ) ) {
				continue;
			}
			$entry = leitvia_studio()->registry( 'blocks' )->get( 'leitvia/' . basename( $folder ) );
			$this->assertNotNull( $entry, basename( $folder ) );
			$this->assertSame( $folder, $entry['path'] );
			$this->assertSame( 'leitvia-studio', $entry['owner'] );
		}
	}

	/**
	 * The inserter category leitvia exists once.
	 */
	public function test_category(): void {
		$slugs = array_column( get_block_categories( get_post( self::factory()->post->create() ) ), 'slug' );
		$this->assertSame( 1, count( array_keys( $slugs, 'leitvia', true ) ) );
	}

	/**
	 * Every patterns/<name>.php is registered as leitvia-studio/<name> with a title and parseable Studio blocks.
	 */
	public function test_patterns_are_registered(): void {
		$files = glob( dirname( LEITVIA_STUDIO_FILE ) . '/patterns/*.php' );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia-studio/' . basename( $file, '.php' ) );
			$this->assertIsArray( $pattern, $file );
			$this->assertNotEmpty( $pattern['title'], $file );
			// Starter content for a template part area (the mega menu panel) is no page section.
			$areas = array_filter( (array) ( $pattern['blockTypes'] ?? array() ), static fn( $type ) => str_starts_with( $type, 'core/template-part/' ) );
			if ( $areas ) {
				continue;
			}
			$names = array_column( parse_blocks( $pattern['content'] ), 'blockName' );
			$this->assertSame( array( 'core/group' ), array_values( array_filter( $names ) ), $file );
		}
	}

	/**
	 * A page without a Studio block queues no Studio script, style or module; the timeline queues its style.
	 */
	public function test_assets_load_only_with_the_block(): void {
		// Other tests replace wp_styles(); register the blocks again so their handles exist in the current one.
		foreach ( leitvia_studio()->registry( 'blocks' )->all() as $name => $entry ) {
			unregister_block_type( $name );
			register_block_type( $entry['path'] );
		}
		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );
		do_blocks( '<!-- wp:paragraph --><p>Plain</p><!-- /wp:paragraph -->' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertSame( array(), self::queued_studio_handles() );

		do_blocks( self::fixture() );
		$this->assertContains( 'leitvia-timeline-style', self::queued_studio_handles() );
	}

	/**
	 * The saved markup is an ordered list, one item per entry, each with a machine-readable date.
	 */
	public function test_fixture_renders_list_with_dates(): void {
		$html = do_blocks( self::fixture() );
		$this->assertSame( 1, substr_count( $html, '<ol class="wp-block-leitvia-timeline' ) );
		$items = substr_count( $html, '<li class="wp-block-leitvia-timeline-item' );
		$this->assertGreaterThan( 1, $items );
		$this->assertSame( $items, preg_match_all( '#<time class="wp-block-leitvia-timeline-item__date" datetime="\d{4}(-\d{2}){0,2}">#', $html ) );
	}

	/**
	 * Without unfiltered_html, kses keeps the radios and checkboxes the blocks save and removes every other input: no
	 * text or password field for a fake login form, no event handler, style, formaction or src.
	 */
	public function test_kses_keeps_only_choice_inputs(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$kept = '<input type="radio" name="leitvia-pricing-a" value="monthly" checked /><input type="checkbox" name="level[]" value="1" required />';
		$this->assertSame( $kept, wp_kses_post( $kept ) );
		$this->assertSame( '<input type="RADIO" name="a" />', wp_kses_post( '<input type="RADIO" name="a" />' ) );
		foreach ( array( 'text', 'password', 'email', 'hidden', 'submit', 'image', 'file' ) as $type ) {
			$this->assertSame( '', wp_kses_post( '<input type="' . $type . '" name="pwd" />' ), $type );
		}
		$this->assertSame( '', wp_kses_post( '<input name="pwd" />' ), 'an input without a type is a text field' );
		$this->assertSame(
			'<input type="radio" name="a" />',
			wp_kses_post( '<input type="radio" name="a" onclick="alert(1)" onfocus="alert(1)" autofocus style="position:fixed" formaction="https://example.org" src="https://example.org/x.png" />' )
		);
		$this->assertSame( '<form><input type="checkbox" value="1" /></form>', wp_kses_post( '<form><input type="password" name="pwd" /><input type="checkbox" value="1" /></form>' ) );

		// What an author saves through the post editor goes through the same filter.
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_author'  => $author,
					'post_content' => '<input type="radio" name="a" value="1" /><input type="password" name="pwd" />',
				)
			)
		);
		$this->assertSame( '<input type="radio" name="a" value="1" />', get_post( $id )->post_content );
	}

	/**
	 * A duplicated pricing block keeps the saved group name; each rendered block gets its own radio group anyway.
	 */
	public function test_duplicated_pricing_blocks_get_own_groups(): void {
		$block = '<!-- wp:leitvia/pricing {"groupId":"abc123"} --><div class="wp-block-leitvia-pricing"><fieldset class="wp-block-leitvia-pricing__period"><legend>Billing period</legend><label><input type="radio" name="leitvia-pricing-abc123" value="monthly" checked/><span class="wp-block-leitvia-pricing__monthly">Monthly</span></label><label><input type="radio" name="leitvia-pricing-abc123" value="yearly"/><span class="wp-block-leitvia-pricing__yearly">Yearly</span></label></fieldset><div class="wp-block-leitvia-pricing__plans"></div></div><!-- /wp:leitvia/pricing -->';
		$html  = do_blocks( $block . $block );
		preg_match_all( '/name="([^"]+)"/', $html, $names );
		$this->assertCount( 4, $names[1] );
		$this->assertSame( $names[1][0], $names[1][1] );
		$this->assertSame( $names[1][2], $names[1][3] );
		$this->assertNotSame( $names[1][0], $names[1][2] );
		$this->assertStringStartsWith( 'leitvia-pricing-', $names[1][2] );
	}
}
