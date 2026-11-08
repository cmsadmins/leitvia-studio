<?php
/**
 * The head of a single post in the theme: one category, an excerpt only when the author wrote one.
 *
 * @package LeitviaStudio
 */

/**
 * Filters primary_term_only and own_excerpt_only of functions.php.
 */
class Test_Theme_Post_Head extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme and makes sure its filters are attached.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		if ( ! function_exists( 'Leitvia\primary_term_only' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
		// functions.php may have been loaded by an earlier test class, whose hooks the test case has reset since.
		if ( ! has_filter( 'render_block_core/post-terms', 'Leitvia\primary_term_only' ) ) {
			add_filter( 'render_block_core/post-terms', 'Leitvia\primary_term_only', 10, 3 );
		}
		if ( ! has_filter( 'render_block_core/post-excerpt', 'Leitvia\own_excerpt_only' ) ) {
			add_filter( 'render_block_core/post-excerpt', 'Leitvia\own_excerpt_only', 10, 3 );
		}
	}

	/**
	 * Renders blocks with the post as the current one.
	 *
	 * @param int    $post_id Post.
	 * @param string $markup  Block markup.
	 */
	private function render( int $post_id, string $markup ): string {
		$this->go_to( get_permalink( $post_id ) );
		return do_blocks( $markup );
	}

	/**
	 * A post in many categories shows the first one, the one Yoast names as primary when there is one.
	 */
	public function test_category_line_names_one_category(): void {
		$names = array();
		$ids   = array();
		foreach ( array( 'Alpha', 'Beta', 'Gamma' ) as $name ) {
			$ids[]   = self::factory()->category->create( array( 'name' => $name ) );
			$names[] = $name;
		}
		$post   = self::factory()->post->create( array( 'post_category' => $ids ) );
		$markup = '<!-- wp:post-terms {"term":"category","className":"leitvia-primary-term"} /-->';

		$html = $this->render( $post, $markup );
		$this->assertSame( 1, substr_count( $html, '<a ' ), 'one link' );
		$this->assertStringContainsString( 'taxonomy-category', $html );

		update_post_meta( $post, '_yoast_wpseo_primary_category', $ids[2] );
		$html = $this->render( $post, $markup );
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
		$this->assertStringContainsString( '>Gamma</a>', $html );

		$this->assertGreaterThan( 1, substr_count( $this->render( $post, '<!-- wp:post-terms {"term":"category"} /-->' ), '<a ' ), 'the block without the class stays complete' );
	}

	/**
	 * The lead only shows an excerpt the author wrote, not the one WordPress makes from the text.
	 */
	public function test_lead_needs_an_own_excerpt(): void {
		$markup = '<!-- wp:post-excerpt {"className":"leitvia-own-excerpt"} /-->';
		// The factory writes an excerpt of its own unless told not to.
		$without = self::factory()->post->create(
			array(
				'post_content' => 'The text of the post itself, long enough to be cut into an excerpt.',
				'post_excerpt' => '',
			)
		);
		$with    = self::factory()->post->create(
			array(
				'post_content' => 'The text of the post itself.',
				'post_excerpt' => 'Written by hand.',
			)
		);

		$this->assertStringNotContainsString( 'wp-block-post-excerpt', $this->render( $without, $markup ) );
		$this->assertStringContainsString( 'Written by hand.', $this->render( $with, $markup ) );
		$this->assertStringContainsString( 'wp-block-post-excerpt', $this->render( $without, '<!-- wp:post-excerpt /-->' ), 'teasers keep the automatic excerpt' );
	}
}
