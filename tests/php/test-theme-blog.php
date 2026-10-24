<?php
/**
 * The theme pattern leitvia/content-downloads and the blog parts of the theme: posts page heading, continue reading
 * link, author box, post navigation and related posts.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Composition;
use LeitviaStudio\Lab\Token_Lint;

/**
 * Markup, contract entries and the front end of the download list and the blog patterns.
 */
class Test_Theme_Blog extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme so its patterns and filters are registered.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		// The registry keeps the content of a file pattern once read; read the dynamic heading fresh in every test.
		if ( WP_Block_Patterns_Registry::get_instance()->is_registered( 'leitvia/hidden-page-blog-heading' ) ) {
			unregister_block_pattern( 'leitvia/hidden-page-blog-heading' );
		}
		_register_theme_block_patterns();
		// functions.php of the theme loads after switch_theme only on the next request, and the test case restores the
		// hooks after every test: load it once, then add the filters under test and the block style again.
		if ( ! function_exists( 'Leitvia\related_posts_query' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
		add_filter( 'render_block_data', 'Leitvia\related_posts_query' );
		add_filter( 'render_block_core/query', 'Leitvia\hide_empty_related_posts', 10, 2 );
		if ( ! WP_Block_Styles_Registry::get_instance()->is_registered( 'core/list', 'download' ) ) {
			Leitvia\register_block_style_variations();
		}
	}

	/**
	 * Content of a registered pattern.
	 *
	 * @param string $slug Pattern slug.
	 */
	private static function content( string $slug ): string {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
		self::assertIsArray( $pattern, "$slug is registered" );
		return (string) $pattern['content'];
	}

	/**
	 * A semantic list in the Download style; every item has exactly one link whose text names format and size,
	 * followed by a description. No icon links, no raw values.
	 */
	public function test_downloads_markup(): void {
		$content = self::content( 'leitvia/content-downloads' );
		$list    = parse_blocks( $content )[0]['innerBlocks'][2];
		$this->assertSame( 'core/list', $list['blockName'] );
		$this->assertSame( 'is-style-download', $list['attrs']['className'] );
		$this->assertStringContainsString( '<ul ', $list['innerHTML'] );
		$this->assertCount( 4, $list['innerBlocks'] );
		foreach ( $list['innerBlocks'] as $item ) {
			$this->assertSame( 'core/list-item', $item['blockName'] );
			$this->assertSame( 1, substr_count( $item['innerHTML'], '<a ' ) );
			$this->assertMatchesRegularExpression( '#<li><a href="[^"]*">[^<]+ \([A-Z]+, [\d.]+ [KM]B\)</a> [^<]+\.</li>#', trim( $item['innerHTML'] ) );
		}
		$this->assertStringNotContainsString( '<svg', $content );
		$this->assertStringNotContainsString( '<img', $content );
		$this->assertSame( array(), Token_Lint::lint_markup( $content ) );
	}

	/**
	 * An agent fills an item through a page edit: the new link text and the file URL, the description after the link.
	 */
	public function test_downloads_item_edit(): void {
		$url    = 'https://example.org/wp-content/uploads/2026/10/price-list-2026.pdf';
		$result = Block_Edit::apply(
			self::content( 'leitvia/content-downloads' ),
			'p:0.2.1',
			array(
				'text'      => 'Price list 2026 (PDF, 180 KB) All plans with prices excluding VAT.',
				'link_text' => 'Price list 2026 (PDF, 180 KB)',
				'url'       => $url,
			)
		);
		$this->assertIsString( $result );
		$item = parse_blocks( $result )[0]['innerBlocks'][2]['innerBlocks'][1];
		$this->assertSame( '<li><a href="' . $url . '">Price list 2026 (PDF, 180 KB)</a> All plans with prices excluding VAT.</li>', trim( $item['innerHTML'] ) );
	}

	/**
	 * The contract lists the download pattern with full metadata and the Download list style; a product page outline
	 * with it scores without findings.
	 */
	public function test_downloads_contract(): void {
		$contract = leitvia_studio_contract()->data();
		$entry    = wp_list_filter( $contract['patterns'], array( 'slug' => 'leitvia/content-downloads' ) );
		$this->assertCount( 1, $entry );
		$entry = reset( $entry );
		$this->assertSame( array( 'content', 'detail', 'surface-alt' ), array( $entry['area'], $entry['composition']['role'], $entry['composition']['tone'] ) );
		$this->assertNotEmpty( $entry['docs']['a11y'] );
		$this->assertCount(
			1,
			wp_list_filter(
				$contract['blockStyles'],
				array(
					'block' => 'core/list',
					'name'  => 'download',
				)
			)
		);
		$this->assertTrue( WP_Block_Styles_Registry::get_instance()->is_registered( 'core/list', 'download' ) );

		$result = Composition::score( array( 'leitvia/hero-centered', 'leitvia/content-text-media', 'leitvia/content-downloads', 'leitvia/content-quote', 'leitvia/cta-banner' ), $contract, 'page-landing' );
		$this->assertSame( array(), $result['issues'] ?? array() );
		$this->assertSame( 100, $result['score'] );
	}

	/**
	 * The blog heading names the posts page and takes its excerpt as lead; without a posts page the default texts stay.
	 */
	public function test_blog_heading_follows_posts_page(): void {
		$this->assertStringContainsString( 'Latest writing</h1>', self::content( 'leitvia/hidden-page-blog-heading' ) );

		$journal = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Journal',
				'post_excerpt' => 'Notes from the workshop, every Friday.',
			)
		);
		update_option( 'page_for_posts', $journal );
		unregister_block_pattern( 'leitvia/hidden-page-blog-heading' );
		_register_theme_block_patterns();
		$content = self::content( 'leitvia/hidden-page-blog-heading' );
		$this->assertStringContainsString( '<h1 class="wp-block-heading">Journal</h1>', $content );
		$this->assertStringContainsString( 'Notes from the workshop, every Friday.</p>', $content );
		$this->assertSame( 1, substr_count( $content, '<h1' ) );
	}

	/**
	 * The continue reading link carries the post title for screen readers; the post navigation names direction and
	 * title in each link inside a named nav landmark.
	 */
	public function test_read_more_and_post_navigation(): void {
		$cat   = self::factory()->category->create();
		$older = self::factory()->post->create(
			array(
				'post_title'    => 'Older field note',
				'post_date'     => '2026-01-01 10:00:00',
				'post_category' => array( $cat ),
			)
		);
		$post  = self::factory()->post->create(
			array(
				'post_title'    => 'Current field note',
				'post_date'     => '2026-02-01 10:00:00',
				'post_category' => array( $cat ),
			)
		);

		$this->go_to( get_permalink( $post ) );
		the_post();
		$more = do_blocks( '<!-- wp:pattern {"slug":"leitvia/hidden-page-read-more"} /-->' );
		$nav  = do_blocks( '<!-- wp:pattern {"slug":"leitvia/hidden-page-post-navigation"} /-->' );
		wp_reset_postdata();

		$this->assertStringContainsString( 'Continue reading<span class="screen-reader-text">', $more );
		$this->assertStringContainsString( 'Current field note', $more );
		$this->assertStringContainsString( '<nav aria-label="More articles"', $nav );
		$this->assertMatchesRegularExpression( '#<a [^>]*href="' . preg_quote( get_permalink( $older ), '#' ) . '"[^>]*>\s*<span class="post-navigation-link__label">Previous article:</span> <span class="post-navigation-link__title">Older field note</span>#', $nav );
		$this->assertStringNotContainsString( 'Next article:', $nav, 'no next post, no link' );
	}

	/**
	 * The author box names and links the author and shows the biography, without an avatar.
	 */
	public function test_author_box(): void {
		$author = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Mara Lindqvist',
				'description'  => 'Mara writes about accessible publishing.',
			)
		);
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );

		$this->go_to( get_permalink( $post ) );
		the_post();
		$html = do_blocks( '<!-- wp:pattern {"slug":"leitvia/hidden-page-post-author"} /-->' );
		wp_reset_postdata();

		$this->assertStringContainsString( '<aside aria-label="About the author"', $html );
		$this->assertStringContainsString( 'About the author</h2>', $html );
		$this->assertStringContainsString( get_author_posts_url( $author ), $html );
		$this->assertStringContainsString( 'Mara writes about accessible publishing.', $html );
		$this->assertStringNotContainsString( 'avatar', $html );
	}

	/**
	 * Related posts come from the categories of the current post, without the post itself; with no other post in them
	 * the section and its heading are left out.
	 */
	public function test_related_posts(): void {
		$design = self::factory()->category->create();
		$other  = self::factory()->category->create();
		$post   = self::factory()->post->create(
			array(
				'post_title'    => 'Grids in print',
				'post_category' => array( $design ),
			)
		);
		self::factory()->post->create(
			array(
				'post_title'    => 'Margins that breathe',
				'post_category' => array( $design ),
			)
		);
		self::factory()->post->create(
			array(
				'post_title'    => 'Opening hours in winter',
				'post_category' => array( $other ),
			)
		);
		$lonely = self::factory()->post->create(
			array(
				'post_title'    => 'A post alone',
				'post_category' => array( self::factory()->category->create() ),
			)
		);
		$markup = '<!-- wp:pattern {"slug":"leitvia/hidden-page-related-posts"} /-->';

		$this->go_to( get_permalink( $post ) );
		the_post();
		$html = do_blocks( $markup );
		wp_reset_postdata();
		$this->assertStringContainsString( 'Related articles</h2>', $html );
		$this->assertStringContainsString( 'Margins that breathe', $html );
		$this->assertStringNotContainsString( 'Grids in print', $html, 'the current post is left out' );
		$this->assertStringNotContainsString( 'Opening hours in winter', $html, 'other categories are left out' );

		$this->go_to( get_permalink( $lonely ) );
		the_post();
		$this->assertSame( '', trim( do_blocks( $markup ) ) );
		wp_reset_postdata();
	}

	/**
	 * The contract lists the blog parts as frame patterns of the page area.
	 */
	public function test_blog_parts_in_contract(): void {
		$patterns = leitvia_studio_contract()->data()['patterns'];
		foreach ( array( 'post-author', 'post-navigation', 'read-more', 'related-posts' ) as $name ) {
			$entry = wp_list_filter( $patterns, array( 'slug' => "leitvia/hidden-page-$name" ) );
			$this->assertCount( 1, $entry, $name );
			$entry = reset( $entry );
			$this->assertSame( array( 'page', 'frame' ), array( $entry['area'], $entry['composition']['role'] ), $name );
		}
	}
}
