<?php
/**
 * The archive template of the theme: title without prefix, chips of many categories, headings of a term description.
 *
 * @package LeitviaStudio
 */

/**
 * The template archive.html and the filters category_chips_all and term_description_h1 of functions.php.
 */
class Test_Theme_Archive extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme and attaches the filters under test.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		if ( ! function_exists( 'Leitvia\term_description_h1' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
		// functions.php may have been loaded by an earlier test class, whose hooks the test case has reset since.
		if ( ! has_filter( 'render_block_core/categories', 'Leitvia\category_chips_all' ) ) {
			add_filter( 'render_block_core/categories', 'Leitvia\category_chips_all', 10, 2 );
		}
		if ( ! has_filter( 'render_block_core/term-description', 'Leitvia\term_description_h1' ) ) {
			add_filter( 'render_block_core/term-description', 'Leitvia\term_description_h1' );
		}
	}

	/**
	 * The archive title is the name of the term alone: "Category:" or "Tag:" in front of it says nothing to visitors
	 * and puts the same word in front of every archive title.
	 */
	public function test_archive_title_has_no_prefix(): void {
		foreach ( array( 'archive', 'index' ) as $slug ) {
			$markup = (string) file_get_contents( get_theme_file_path( "templates/$slug.html" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme file.
			$this->assertStringContainsString( '<!-- wp:query-title {"type":"archive","showPrefix":false} /-->', $markup, $slug );
		}
		$cat = self::factory()->category->create( array( 'name' => 'Museums' ) );
		self::factory()->post->create( array( 'post_category' => array( $cat ) ) );
		$this->go_to( get_category_link( $cat ) );
		$html = do_blocks( '<!-- wp:query-title {"type":"archive","showPrefix":false} /-->' );
		$this->assertMatchesRegularExpression( '#<h1[^>]*>Museums</h1>#', $html );
	}

	/**
	 * On the archive of one of many categories the chips keep the twelve with the most posts and the current one, as
	 * on the posts index.
	 */
	public function test_chips_of_many_categories_on_an_archive(): void {
		$ids = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$ids[ $i ] = self::factory()->category->create( array( 'name' => sprintf( 'Topic %02d', $i ) ) );
			// Topic 15 has the fewest posts, one.
			self::factory()->post->create_many( 17 - $i, array( 'post_category' => array( $ids[ $i ] ) ) );
		}
		$this->go_to( get_category_link( $ids[15] ) );
		$html = do_blocks( '<!-- wp:categories {"className":"is-style-chips"} /-->' );
		$this->assertSame( 14, substr_count( $html, '<li' ), 'All, the twelve largest and the current one' );
		$this->assertStringContainsString( 'Topic 15', $html, 'the current category stays' );
		$this->assertStringNotContainsString( 'Topic 14', $html );
		$this->assertStringNotContainsString( 'Topic 13', $html );

		// A static front page without a posts page has no link to all posts, the row is limited all the same.
		update_option( 'show_on_front', 'page' );
		update_option( 'page_for_posts', 0 );
		$html = do_blocks( '<!-- wp:categories {"className":"is-style-chips"} /-->' );
		$this->assertSame( 13, substr_count( $html, '<li' ), 'the twelve largest and the current one, no link to all' );
	}

	/**
	 * An h1 in a term description becomes an h2 with the h1 size: the archive title is the one h1 of the page.
	 */
	public function test_h1_in_term_description_becomes_h2(): void {
		// A person with unfiltered_html may write headings into a description; KSES of other users drops them.
		remove_filter( 'pre_term_description', 'wp_filter_kses' );
		$cat = self::factory()->category->create(
			array(
				'name'        => 'Exhibitions',
				'description' => '<h1>All exhibitions</h1><p>What is on now.</p>',
			)
		);
		add_filter( 'pre_term_description', 'wp_filter_kses' );
		$this->go_to( get_category_link( $cat ) );
		$html = do_blocks( '<!-- wp:term-description /-->' );
		$this->assertStringNotContainsString( '<h1', $html );
		$this->assertMatchesRegularExpression( '#<h2 class="has-heading-l-font-size">All exhibitions</h2>#', $html );
		$this->assertStringContainsString( 'What is on now.', $html );
	}
}
