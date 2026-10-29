<?php
/**
 * JSON-LD for posts and author archives, news image sizes.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\News\Image_Sizes;
use LeitviaStudio\News\Schema;

/**
 * Google News criteria 13 to 20 (docs/clean-room/reportedip-analyse.md 4.3). The rendered JSON-LD is stored in
 * tests/fixtures/news/*.jsonld and compared with the committed state; tests/unit/news-schema.test.mjs validates
 * those files against the schemas next to them.
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
				'post_author' => $this->author,
				'post_title'  => 'Structure &amp; rhythm in long reads',
				'post_name'   => 'structure-and-rhythm',
				'post_date'   => '2026-09-01 11:00:00',
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
	 * 13 to 17: a BlogPosting with a large featured image and a logo; written to blog-posting.jsonld.
	 */
	public function test_blog_posting(): void {
		set_post_thumbnail( $this->post, $this->upload( 'news-photo', 1600, 1000 ) );
		set_theme_mod( 'custom_logo', $this->upload( 'news-logo', 200, 200 ) );
		$this->go_to( get_permalink( $this->post ) );

		$head = get_echo( 'wp_head' );
		$this->assertSame( 1, substr_count( $head, '<script type="application/ld+json">' ), 'one JSON-LD block' );
		$data = Schema::current();
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
	 * 13, 16, 17: NewsArticle for a news category; a small image, the logo as featured image and a small logo are
	 * all left out. Written to news-article.jsonld.
	 */
	public function test_news_article_without_image_or_logo(): void {
		$news = self::factory()->category->create( array( 'slug' => 'news' ) );
		wp_set_post_categories( $this->post, array( $news ) );
		add_filter( 'leitvia_studio_news_categories', static fn() => array( 'news' ) );
		set_post_thumbnail( $this->post, $this->upload( 'news-small', 1000, 800 ) );
		set_theme_mod( 'custom_logo', $this->upload( 'news-tiny-logo', 100, 100 ) );
		$this->go_to( get_permalink( $this->post ) );

		$data = Schema::current();
		$this->assertSame( 'NewsArticle', $data['@type'] );
		$this->assertArrayNotHasKey( 'image', $data );
		$this->assertArrayNotHasKey( 'logo', $data['publisher'] );
		$this->assertMatchesFixture( 'news-article', get_echo( 'wp_head' ) );

		// The site logo never stands in for the article image, even when it is large enough.
		$logo = $this->upload( 'news-big-logo', 1200, 1200 );
		set_theme_mod( 'custom_logo', $logo );
		set_post_thumbnail( $this->post, $logo );
		$this->assertArrayNotHasKey( 'image', Schema::current() );
	}

	/**
	 * 16: an image uploaded before Studio registered its crops has none of them; the original stands in when it is at
	 * least 1200 px wide, a narrower one is still left out.
	 */
	public function test_original_stands_in_for_missing_crops(): void {
		$image = $this->upload_without_crops( 'news-old-photo', 1600, 1000 );
		set_post_thumbnail( $this->post, $image );
		$this->go_to( get_permalink( $this->post ) );
		$this->assertSame( array( wp_get_attachment_url( $image ) ), Schema::current()['image'] );

		set_post_thumbnail( $this->post, $this->upload_without_crops( 'news-old-small', 1000, 800 ) );
		$this->assertArrayNotHasKey( 'image', Schema::current() );
	}

	/**
	 * 21: without a usable custom logo (missing, under 112 px, SVG) the site icon at 512 px is the publisher logo; with
	 * neither the logo is left out.
	 */
	public function test_site_icon_stands_in_for_the_logo(): void {
		$this->go_to( get_permalink( $this->post ) );
		$this->assertArrayNotHasKey( 'logo', Schema::current()['publisher'] );

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
				Schema::current()['publisher']['logo'] ?? null,
				"custom logo $case"
			);
		}

		// A usable custom logo wins over the icon; a too small icon is no fallback.
		set_theme_mod( 'custom_logo', $this->upload( 'news-logo', 200, 200 ) );
		$this->assertSame( 200, Schema::current()['publisher']['logo']['width'] );
		set_theme_mod( 'custom_logo', 0 );
		update_option( 'site_icon', $this->upload( 'news-small-icon', 64, 64 ) );
		$this->assertArrayNotHasKey( 'logo', Schema::current()['publisher'] );
	}

	/**
	 * 18: author archives get a ProfilePage; written to profile-page.jsonld.
	 */
	public function test_profile_page(): void {
		$this->go_to( get_author_posts_url( $this->author ) );
		$this->assertTrue( is_author() );
		$data = Schema::current();
		$this->assertSame( 'ProfilePage', $data['@type'] );
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
		$this->go_to( get_category_link( 1 ) );
		$this->assertNull( Schema::current() );
	}

	/**
	 * The front page carries a WebSite with name, tagline and the site as publisher, for the site name in results.
	 */
	public function test_front_page_website(): void {
		update_option( 'blogdescription', 'Pages &amp; posts' );
		set_theme_mod( 'custom_logo', $this->upload( 'news-logo', 200, 200 ) );
		$this->go_to( home_url( '/' ) );
		$data = Schema::current();
		$this->assertSame( 'WebSite', $data['@type'] );
		$this->assertSame( 'Leitvia News', $data['name'] );
		$this->assertSame( home_url( '/' ), $data['url'] );
		$this->assertSame( 'Pages & posts', $data['description'] );
		$this->assertSame( 'Organization', $data['publisher']['@type'] );
		$this->assertSame( 200, $data['publisher']['logo']['width'] );
		$this->assertSame( 1, substr_count( get_echo( 'wp_head' ), 'application/ld+json' ) );

		update_option( 'blogdescription', '' );
		$this->assertArrayNotHasKey( 'description', Schema::current() );
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
		$this->assertSame( 'Why </script> ends a script', json_decode( $match[1], true )['headline'] ?? null );
		$this->assertStringNotContainsString( 'Why </script>', $head );
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
