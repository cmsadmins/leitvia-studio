<?php
/**
 * The theme binding source leitvia/query-archive and the patterns that use it.
 *
 * @package LeitviaStudio
 */

/**
 * The button of leitvia/teaser-posts-row and leitvia/teaser-posts-trio leads to the archive of the term the query shows.
 */
class Test_Theme_Query_Archive extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme and registers its patterns and the binding source.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
		if ( ! function_exists( 'Leitvia\query_archive_value' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
		if ( ! get_block_bindings_source( 'leitvia/query-archive' ) ) {
			Leitvia\register_query_archive_binding();
		}
	}

	/**
	 * Content of a theme pattern with the given filters in its query.
	 *
	 * @param string $slug    Pattern slug.
	 * @param string $filters JSON of taxQuery, '' for none.
	 */
	private static function pattern( string $slug, string $filters ): string {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
		self::assertIsArray( $pattern, "$slug is registered" );
		$content = (string) $pattern['content'];
		self::assertSame( 1, substr_count( $content, '"inherit":false}' ), 'one query' );
		return '' === $filters ? $content : str_replace( '"inherit":false}', '"inherit":false,"taxQuery":' . $filters . '}', $content );
	}

	/**
	 * With a category in the filters the row lists its four latest posts and links to the category archive.
	 */
	public function test_row_links_to_the_category(): void {
		$printing = self::factory()->category->create( array( 'name' => 'Printing' ) );
		self::factory()->post->create_many( 5, array( 'post_category' => array( $printing ) ) );
		self::factory()->post->create( array( 'post_title' => 'Somewhere else' ) );

		$html = do_blocks( self::pattern( 'leitvia/teaser-posts-row', '{"category":[' . $printing . ']}' ) );
		$this->assertStringContainsString( 'href="' . esc_url( get_category_link( $printing ) ) . '"', $html );
		$this->assertSame( 4, substr_count( $html, 'class="wp-block-post ' ), 'four cards' );
		$this->assertStringNotContainsString( 'Somewhere else', $html, 'only the category' );
	}

	/**
	 * Terms under include, as newer editors save them, lead to the tag archive.
	 */
	public function test_trio_links_to_the_tag_under_include(): void {
		$tag  = self::factory()->tag->create( array( 'name' => 'Letterpress' ) );
		$post = self::factory()->post->create();
		wp_set_post_terms( $post, array( $tag ), 'post_tag' );

		$html = do_blocks( self::pattern( 'leitvia/teaser-posts-trio', '{"include":{"post_tag":[' . $tag . ']}}' ) );
		$this->assertStringContainsString( 'href="' . esc_url( get_tag_link( $tag ) ) . '"', $html );
		$this->assertStringContainsString( '<time datetime=', $html, 'dated' );
	}

	/**
	 * Without filters the button leads to the posts page, and a button outside a query keeps its saved url.
	 */
	public function test_without_filters_the_posts_page(): void {
		self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_for_posts', $page );

		$html = do_blocks( self::pattern( 'leitvia/teaser-posts-trio', '' ) );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $page ) ) . '"', $html );

		$alone = do_blocks( '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"leitvia/query-archive"}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/kept/">Kept</a></div><!-- /wp:button -->' );
		$this->assertStringContainsString( 'href="/kept/"', $alone );
	}
}
