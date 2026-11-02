<?php
/**
 * JSON-LD for posts and author archives, news image sizes.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\News\Image_Sizes;
use LeitviaStudio\News\Schema;

/**
 * Google News criteria 13 to 20 and 22 to 25 (docs/clean-room/reportedip-analyse.md 4.3). The rendered JSON-LD, a
 * graph per page, is stored in tests/fixtures/news/*.jsonld and compared with the committed state;
 * tests/unit/news-schema.test.mjs validates those files against the schemas next to them.
 */
class Test_News_Schema extends WP_UnitTestCase {

	/**
	 * Folder of the reference output.
	 */
	private const FIXTURES = __DIR__ . '/../fixtures/news/';

	/**
	 * Host the reference output uses in place of the test site's.
	 */
	private const HOST = 'http://example.org';

	/**
	 * Author of the post.
	 *
	 * @var int
	 */
	private int $author;

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private int $post;

	/**
	 * Creates an author with a biography and a post with fixed dates on pretty permalinks.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		update_option( 'timezone_string', 'Europe/Berlin' );
		update_option( 'blogname', 'Leitvia News' );
		$this->author = self::factory()->user->create(
			array(
				'role'          => 'author',
				'user_login'    => 'mara',
				'user_nicename' => 'mara',
				'display_name'  => 'Mara Lindqvist',
				'description'   => 'Mara writes about design systems & accessible publishing.',
			)
		);
		$this->post   = self::factory()->post->create(
			array(
				'post_author'  => $this->author,
				'post_title'   => 'Structure &amp; rhythm in long reads',
				'post_name'    => 'structure-and-rhythm',
				'post_date'    => '2026-09-01 11:00:00',
				'post_excerpt' => 'How <em>headings</em> &amp; spacing guide readers through long texts.',
			)
		);
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture: wp_update_post() always stamps the current time.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => '2026-10-01 11:00:00',
				'post_modified_gmt' => '2026-10-01 09:00:00',
			),
			array( 'ID' => $this->post )
		);
		clean_post_cache( $this->post );
	}

	/**
	 * Removes the uploaded images.
	 */
	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * 20: the three crops are registered at 1200 px width and not offered in the editor's size picker.
	 */
	public function test_image_sizes_are_registered_crops(): void {
		$sizes = wp_get_registered_image_subsizes();
		foreach ( Image_Sizes::SIZES as $name => list( $width, $height ) ) {
			$this->assertSame(
				array(
					'width'  => $width,
					'height' => $height,
					'crop'   => true,
				),
				$sizes[ $name ] ?? null,
				$name
			);
		}
		$choices = apply_filters( 'image_size_names_choose', array() );
		$this->assertSame( array(), array_intersect_key( $choices, Image_Sizes::SIZES ) );
	}

	/**
	 * 13 to 17: a BlogPosting with a large featured image and a logo, for a post outside the news categories; written
	 * to blog-posting.jsonld.
	 */
	public function test_blog_posting(): void {
		add_filter( 'leitvia_studio_news_categories', static fn() => array( 'news' ) );
		set_post_thumbnail( $this->post, $this->upload( 'news-photo', 1600, 1000 ) );
		set_theme_mod( 'custom_logo', $this->upload( 'news-logo', 200, 200 ) );
		$this->go_to( get_permalink( $this->post ) );

		$head = get_echo( 'wp_head' );
		$this->assertSame( 1, substr_count( $head, '<script type="application/ld+json">' ), 'one JSON-LD block' );
		$data = $this->node( 'BlogPosting' );
		$this->assertSame( 'BlogPosting', $data['@type'] );
		$this->assertSame( 'Structure & rhythm in long reads', $data['headline'] );
		// 14: the values of the datetime attributes the post date block prints.
		$this->assertSame( '2026-09-01T11:00:00+02:00', $data['datePublished'] );
		$this->assertSame( '2026-10-01T11:00:00+02:00', $data['dateModified'] );
		$this->assertSame( get_the_date( 'c', $this->post ), $data['datePublished'] );
		// 15: authors as an array of Person with name and author page.
		$this->assertSame( 'Person', $data['author'][0]['@type'] );
		$this->assertSame( 'Mara Lindqvist', $data['author'][0]['name'] );
		$this->assertSame( get_author_posts_url( $this->author ), $data['author'][0]['url'] );
		// 16: 1600 x 1000 gives 16:9 and 4:3 at 1200 px; 1:1 would need upscaling and is left out.
		$this->assertCount( 2, $data['image'] );
		$this->assertMatchesRegularExpression( '/news-photo-1200x675\.png$/', $data['image'][0] );
		$this->assertMatchesRegularExpression( '/news-photo-1200x900\.png$/', $data['image'][1] );
		// 17: publisher with the logo.
		$this->assertSame( 'Organization', $data['publisher']['@type'] );
		$this->assertSame( 'Leitvia News', $data['publisher']['name'] );
		$this->assertSame( 200, $data['publisher']['logo']['width'] );

		$this->assertMatchesFixture( 'blog-posting', $head );
	}

	/**
	 * 13, 16, 17, 22, 23, 24: by default every post is a NewsArticle, with main page, language, free access, excerpt,
	 * section, the editorial principles of the publisher and a BreadcrumbList; a small image, the logo as featured image
	 * and a small logo are all left out. Written to news-article.jsonld.
	 */
	public function test_news_article_without_image_or_logo(): void {
		$news = self::factory()->category->create(
			array(
				'slug' => 'news',
				'name' => 'News',
			)
		);
		wp_set_post_categories( $this->post, array( $news ) );
		$editorial = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'editorial-guidelines',
			)
		);
		update_option( 'leitvia_studio_settings', array( 'editorial_page' => $editorial ) );
		set_post_thumbnail( $this->post, $this->upload( 'news-small', 1000, 800 ) );
		set_theme_mod( 'custom_logo', $this->upload( 'news-tiny-logo', 100, 100 ) );
		$this->go_to( get_permalink( $this->post ) );

		$data = $this->node( 'NewsArticle' );
		$this->assertArrayNotHasKey( 'image', $data );
		$this->assertArrayNotHasKey( 'logo', $data['publisher'] );
		$url = get_permalink( $this->post );
		$this->assertSame(
			array(
				'@type' => 'WebPage',
				'@id'   => $url,
			),
			$data['mainEntityOfPage']
		);
		$this->assertSame( 'en-US', $data['inLanguage'] );
		$this->assertTrue( $data['isAccessibleForFree'] );
		$this->assertSame( 'How headings & spacing guide readers through long texts.', $data['description'] );
		$this->assertSame( 'News', $data['articleSection'] );
		$this->assertSame( get_permalink( $editorial ), $data['publisher']['publishingPrinciples'] );
		$this->assertSame( array( home_url( '/' ), get_category_link( $news ), $url ), array_column( $this->node( 'BreadcrumbList' )['itemListElement'], 'item' ) );
		$this->assertMatchesFixture( 'news-article', get_echo( 'wp_head' ) );

		// The site logo never stands in for the article image, even when it is large enough.
		$logo = $this->upload( 'news-big-logo', 1200, 1200 );
		set_theme_mod( 'custom_logo', $logo );
		set_post_thumbnail( $this->post, $logo );
		$this->assertArrayNotHasKey( 'image', $this->node( 'NewsArticle' ) );
	}

	/**
	 * 22: news by the setting news_categories, or the filter: with categories set, posts outside them are a BlogPosting;
	 * no excerpt means no description; only the default category means no section and no category in the breadcrumbs.
	 * Pages are never news.
	 */
	public function test_news_categories_setting(): void {
		$news = self::factory()->category->create( array( 'slug' => 'news' ) );
		$this->assertTrue( Schema::is_news( get_post( $this->post ) ), 'every post by default' );
		update_option( 'leitvia_studio_settings', array( 'news_categories' => array( $news ) ) );
		$this->assertFalse( Schema::is_news( get_post( $this->post ) ) );
		$other = self::factory()->post->create( array( 'post_category' => array( $news ) ) );
		$this->assertTrue( Schema::is_news( get_post( $other ) ) );
		$this->assertFalse( Schema::is_news( get_post( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) ) );

		wp_update_post(
			array(
				'ID'           => $this->post,
				'post_excerpt' => '',
			)
		);
		$this->go_to( get_permalink( $this->post ) );
		$data = $this->node( 'BlogPosting' );
		$this->assertArrayNotHasKey( 'description', $data );
		$this->assertArrayNotHasKey( 'articleSection', $data );
		$this->assertCount( 2, $this->node( 'BreadcrumbList' )['itemListElement'] );

		// The filter receives the setting and wins.
		add_filter( 'leitvia_studio_news_categories', '__return_empty_array' );
		$this->assertSame( 'NewsArticle', $this->node( 'NewsArticle' )['@type'] );
	}

	/**
	 * 23: publishingPrinciples and the filter leitvia_editorial_url name the editorial page only while it is published;
	 * a value the theme already has stays.
	 */
	public function test_editorial_url(): void {
		$this->assertSame( '', Schema::editorial_url() );
		$this->assertSame( '', apply_filters( 'leitvia_editorial_url', '' ) );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		update_option( 'leitvia_studio_settings', array( 'editorial_page' => $page ) );
		$this->assertSame( '', Schema::editorial_url() );
		$this->go_to( get_permalink( $this->post ) );
		$this->assertArrayNotHasKey( 'publishingPrinciples', $this->node( 'NewsArticle' )['publisher'] );

		wp_publish_post( $page );
		$this->assertSame( get_permalink( $page ), Schema::editorial_url() );
		$this->assertSame( get_permalink( $page ), apply_filters( 'leitvia_editorial_url', '' ) );
		$this->assertSame( 'https://example.com/own', apply_filters( 'leitvia_editorial_url', 'https://example.com/own' ) );
		$this->assertSame( get_permalink( $page ), $this->node( 'NewsArticle' )['publisher']['publishingPrinciples'] );
	}

	/**
	 * 25: A category archive carries a CollectionPage of its posts and a BreadcrumbList; written to
	 * collection-page.jsonld. The posts page does the same without breadcrumbs, under its own title.
	 */
	public function test_collection_page(): void {
		$news = self::factory()->category->create(
			array(
				'slug' => 'news',
				'name' => 'News',
			)
		);
		wp_set_post_categories( $this->post, array( $news ) );
		$this->go_to( get_category_link( $news ) );
		$data = $this->node( 'CollectionPage' );
		$this->assertSame( 'News', $data['name'] );
		$this->assertSame( array( get_permalink( $this->post ) ), array_column( $data['mainEntity']['itemListElement'], 'url' ) );
		$this->assertMatchesFixture( 'collection-page', get_echo( 'wp_head' ) );

		$this->go_to( get_tag_link( self::factory()->tag->create( array( 'name' => 'Tagged' ) ) ) );
		$this->assertSame( array( 'CollectionPage', 'BreadcrumbList' ), array_column( Schema::current()['@graph'], '@type' ) );

		$blog = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Journal',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		update_option( 'page_for_posts', $blog );
		$this->go_to( get_permalink( $blog ) );
		$this->assertTrue( is_home() && ! is_front_page() );
		$graph = Schema::current()['@graph'];
		$this->assertSame( array( 'CollectionPage' ), array_column( $graph, '@type' ) );
		$this->assertSame( 'Journal', $graph[0]['name'] );
		$this->assertSame( get_permalink( $blog ), $graph[0]['url'] );
		$this->assertContains( get_permalink( $this->post ), array_column( $graph[0]['mainEntity']['itemListElement'], 'url' ) );

		// With a posts page, the breadcrumbs of a post lead through it.
		$this->go_to( get_permalink( $this->post ) );
		$this->assertSame( array( home_url( '/' ), get_permalink( $blog ), get_category_link( $news ), get_permalink( $this->post ) ), array_column( $this->node( 'BreadcrumbList' )['itemListElement'], 'item' ) );
	}

	/**
	 * BCP 47 tags from WordPress locales.
	 */
	public function test_language(): void {
		$this->assertSame( 'de-DE', Schema::language( 'de_DE' ) );
		$this->assertSame( 'de-DE', Schema::language( 'de_DE_formal' ) );
		$this->assertSame( 'fr', Schema::language( 'fr' ) );
		$this->assertSame( 'pt-BR', Schema::language( 'pt_BR' ) );
	}

	/**
	 * 16: an image uploaded before Studio registered its crops has none of them; the original stands in when it is at
	 * least 1200 px wide, a narrower one is still left out.
	 */
	public function test_original_stands_in_for_missing_crops(): void {
		$image = $this->upload_without_crops( 'news-old-photo', 1600, 1000 );
		set_post_thumbnail( $this->post, $image );
		$this->go_to( get_permalink( $this->post ) );
		$this->assertSame( array( wp_get_attachment_url( $image ) ), $this->node( 'NewsArticle' )['image'] );

		set_post_thumbnail( $this->post, $this->upload_without_crops( 'news-old-small', 1000, 800 ) );
		$this->assertArrayNotHasKey( 'image', $this->node( 'NewsArticle' ) );
	}

	/**
	 * 21: without a usable custom logo (missing, under 112 px, SVG) the site icon at 512 px is the publisher logo; with
	 * neither the logo is left out.
	 */
	public function test_site_icon_stands_in_for_the_logo(): void {
		$this->go_to( get_permalink( $this->post ) );
		$this->assertArrayNotHasKey( 'logo', $this->node( 'NewsArticle' )['publisher'] );

		$icon = $this->upload( 'news-icon', 512, 512 );
		update_option( 'site_icon', $icon );
		$svg   = self::factory()->attachment->create_object(
			array(
				'file'           => 'news-logo.svg',
				'post_mime_type' => 'image/svg+xml',
			)
		);
		$cases = array(
			'missing'   => 0,
			'too small' => $this->upload( 'news-tiny-logo', 100, 100 ),
			'SVG'       => $svg,
		);
		foreach ( $cases as $case => $logo ) {
			set_theme_mod( 'custom_logo', $logo );
			$this->assertSame(
				array(
					'@type'  => 'ImageObject',
					'url'    => get_site_icon_url( 512 ),
					'width'  => 512,
					'height' => 512,
				),
				$this->node( 'NewsArticle' )['publisher']['logo'] ?? null,
				"custom logo $case"
			);
		}

		// A usable custom logo wins over the icon; a too small icon is no fallback.
		set_theme_mod( 'custom_logo', $this->upload( 'news-logo', 200, 200 ) );
		$this->assertSame( 200, $this->node( 'NewsArticle' )['publisher']['logo']['width'] );
		set_theme_mod( 'custom_logo', 0 );
		update_option( 'site_icon', $this->upload( 'news-small-icon', 64, 64 ) );
		$this->assertArrayNotHasKey( 'logo', $this->node( 'NewsArticle' )['publisher'] );
	}

	/**
	 * 18: author archives get a ProfilePage; written to profile-page.jsonld.
	 */
	public function test_profile_page(): void {
		$this->go_to( get_author_posts_url( $this->author ) );
		$this->assertTrue( is_author() );
		$data = $this->node( 'ProfilePage' );
		$this->assertSame(
			array(
				'@type'       => 'Person',
				'name'        => 'Mara Lindqvist',
				'url'         => get_author_posts_url( $this->author ),
				'description' => 'Mara writes about design systems & accessible publishing.',
			),
			$data['mainEntity']
		);
		$this->assertMatchesFixture( 'profile-page', get_echo( 'wp_head' ) );
	}

	/**
	 * 19: nothing with an SEO plugin, with the setting off or on other views.
	 */
	public function test_no_output_with_seo_plugin_setting_off_or_elsewhere(): void {
		$this->assertFalse( leitvia_studio_seo_plugin_active() );
		$this->go_to( get_permalink( $this->post ) );
		$this->assertNotNull( Schema::current() );

		add_filter( 'leitvia_studio_seo_plugin_active', '__return_true' );
		$this->assertTrue( leitvia_studio_seo_plugin_active() );
		$this->assertStringNotContainsString( 'ld+json', get_echo( 'wp_head' ) );
		remove_filter( 'leitvia_studio_seo_plugin_active', '__return_true' );

		update_option( 'leitvia_studio_settings', array( 'news_schema' => false ) );
		$this->assertNull( Schema::current() );
		delete_option( 'leitvia_studio_settings' );

		$this->go_to( get_permalink( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );
		$this->assertNull( Schema::current() );
		$this->go_to( get_year_link( 2026 ) );
		$this->assertNull( Schema::current() );
	}

	/**
	 * The front page carries a WebSite with name, tagline and the site as publisher, for the site name in results.
	 */
	public function test_front_page_website(): void {
		update_option( 'blogdescription', 'Pages &amp; posts' );
		set_theme_mod( 'custom_logo', $this->upload( 'news-logo', 200, 200 ) );
		$this->go_to( home_url( '/' ) );
		$data = $this->node( 'WebSite' );
		$this->assertSame( 'Leitvia News', $data['name'] );
		$this->assertSame( home_url( '/' ), $data['url'] );
		$this->assertSame( 'Pages & posts', $data['description'] );
		$this->assertSame( 'Organization', $data['publisher']['@type'] );
		$this->assertSame( 200, $data['publisher']['logo']['width'] );
		$this->assertSame( 1, substr_count( get_echo( 'wp_head' ), 'application/ld+json' ) );

		update_option( 'blogdescription', '' );
		$this->assertArrayNotHasKey( 'description', $this->node( 'WebSite' ) );
	}

	/**
	 * Markup in titles and names never closes the script element.
	 */
	public function test_titles_cannot_close_the_script(): void {
		wp_update_post(
			array(
				'ID'         => $this->post,
				'post_title' => 'Why &lt;/script&gt; ends a script',
			)
		);
		$this->go_to( get_permalink( $this->post ) );
		$head = get_echo( 'wp_head' );
		// The block ends at the first closing tag: it must hold the whole JSON.
		$this->assertSame( 1, preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $head, $match ) );
		$this->assertSame( 'Why </script> ends a script', json_decode( $match[1], true )['@graph'][0]['headline'] ?? null );
		$this->assertStringNotContainsString( 'Why </script>', $head );
	}

	/**
	 * The node of a type in the graph of the current request; fails when there is none.
	 *
	 * @param string $type Schema.org type.
	 * @return array<string, mixed>
	 */
	private function node( string $type ): array {
		$graph = Schema::current()['@graph'] ?? array();
		$nodes = wp_list_filter( $graph, array( '@type' => $type ) );
		$this->assertCount( 1, $nodes, "one $type node" );
		return reset( $nodes );
	}

	/**
	 * Writes the JSON-LD of the head to tests/fixtures/news/<name>.jsonld with the test host and the upload month
	 * replaced, then compares it with the committed file. A missing file is written and fails once, so it gets reviewed
	 * before it is committed.
	 *
	 * @param string $name Fixture name.
	 * @param string $head Head markup.
	 */
	private function assertMatchesFixture( string $name, string $head ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit naming.
		$this->assertSame( 1, preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $head, $match ) );
		// Core escapes slashes in the printed JSON; the fixture compares it decoded, with plain slashes.
		$json     = str_replace( untrailingslashit( home_url() ), self::HOST, (string) wp_json_encode( json_decode( $match[1] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$json     = (string) preg_replace( '#/uploads/\d{4}/\d{2}/#', '/uploads/', $json );
		$pretty   = wp_json_encode( json_decode( $json ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		$pretty   = (string) preg_replace_callback( '/^( {4})+/m', static fn( array $m ): string => str_repeat( "\t", strlen( $m[0] ) / 4 ), $pretty );
		$file     = self::FIXTURES . $name . '.jsonld';
		$expected = is_readable( $file ) ? (string) file_get_contents( $file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		file_put_contents( $file, $pretty ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes the reference output.
		$this->assertNotNull( $expected, "$file was missing and is written now; review and commit it." );
		$this->assertSame( $expected, $pretty, "$file changed; review the diff and commit it." );
	}

	/**
	 * Uploads a generated PNG and drops Studio's crops from its metadata, as for an image uploaded before Studio.
	 *
	 * @param string $name   File name without extension.
	 * @param int    $width  Width in pixels.
	 * @param int    $height Height in pixels.
	 * @return int Attachment ID.
	 */
	private function upload_without_crops( string $name, int $width, int $height ): int {
		$id            = $this->upload( $name, $width, $height );
		$meta          = wp_get_attachment_metadata( $id );
		$meta['sizes'] = array_diff_key( $meta['sizes'], Image_Sizes::SIZES );
		wp_update_attachment_metadata( $id, $meta );
		return $id;
	}

	/**
	 * Uploads a generated PNG of the given size.
	 *
	 * @param string $name   File name without extension.
	 * @param int    $width  Width in pixels.
	 * @param int    $height Height in pixels.
	 * @return int Attachment ID.
	 */
	private function upload( string $name, int $width, int $height ): int {
		$file  = get_temp_dir() . $name . '.png';
		$image = imagecreatetruecolor( $width, $height );
		imagepng( $image, $file );
		$id = self::factory()->attachment->create_upload_object( $file );
		wp_delete_file( $file );
		return $id;
	}
}
