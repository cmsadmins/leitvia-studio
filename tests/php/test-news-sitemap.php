<?php
/**
 * Google News sitemap.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\News\Sitemap;

/**
 * /news.xml lists the news of the last 48 hours with the news:news details, escaped, in the language of each
 * post; robots.txt names it; it is off with the setting, on a site closed to search engines and beside another news
 * sitemap plugin.
 */
class Test_News_Sitemap extends WP_UnitTestCase {

	/**
	 * Pretty permalinks, an open site, an empty cache.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		update_option( 'blog_public', '1' );
		update_option( 'blogname', 'Leitvia & Friends' );
		delete_transient( Sitemap::CACHE );
	}

	/**
	 * Only published news of the last 48 hours, newest first, with publication, language, date and title; no private,
	 * old or non-news posts.
	 */
	public function test_lists_news_of_the_last_48_hours(): void {
		$new   = $this->news( 'Fresh &amp; &lt;new&gt;', 1 );
		$older = $this->news( 'Yesterday', 47 );
		$old   = $this->news( 'Too old', 49 );
		$this->news( 'Private', 2, array( 'post_status' => 'private' ) );
		$this->news( 'Page', 2, array( 'post_type' => 'page' ) );

		$xml = simplexml_load_string( Sitemap::xml() );
		$this->assertNotFalse( $xml, 'well-formed XML' );
		$this->assertSame( 'http://www.sitemaps.org/schemas/sitemap/0.9', $xml->getNamespaces()[''] );
		$this->assertSame( 'http://www.google.com/schemas/sitemap-news/0.9', $xml->getNamespaces( true )['news'] );
		$this->assertSame( array( get_permalink( $new ), get_permalink( $older ) ), array_map( 'strval', $xml->xpath( '//*[local-name()="loc"]' ) ) );
		$news = $xml->url[0]->children( 'http://www.google.com/schemas/sitemap-news/0.9' )->news;
		$this->assertSame( 'Leitvia & Friends', (string) $news->publication->name );
		$this->assertSame( 'en', (string) $news->publication->language );
		$this->assertSame( get_the_date( 'c', $new ), (string) $news->publication_date );
		$this->assertSame( 'Fresh & <new>', (string) $news->title );
		$this->assertStringContainsString( 'Fresh &amp; &lt;new&gt;', Sitemap::xml() );
		$this->assertStringNotContainsString( get_permalink( $old ), Sitemap::xml() );
		$this->assertStringNotContainsString( 'Private', Sitemap::xml() );
		$this->assertSame( 1000, Sitemap::MAX );
	}

	/**
	 * Restricted to news categories, other posts stay out; an empty site gives a valid empty urlset.
	 */
	public function test_news_categories_and_empty_sitemap(): void {
		$this->assertSame( 0, simplexml_load_string( Sitemap::xml() )->count() );
		delete_transient( Sitemap::CACHE );
		$category = self::factory()->category->create( array( 'slug' => 'news' ) );
		update_option( 'leitvia_studio_settings', array( 'news_categories' => array( $category ) ) );
		$news = $this->news( 'In news', 1, array( 'post_category' => array( $category ) ) );
		$this->news( 'Blog post', 1 );
		$this->assertSame( array( get_permalink( $news ) ), array_map( 'strval', simplexml_load_string( Sitemap::xml() )->xpath( '//*[local-name()="loc"]' ) ) );
	}

	/**
	 * The cache holds the XML until a post changes status.
	 */
	public function test_cache_until_a_post_changes(): void {
		$this->news( 'First', 1 );
		$this->assertStringContainsString( 'First', Sitemap::xml() );
		$this->assertIsString( get_transient( Sitemap::CACHE ) );
		$this->news( 'Second', 1 );
		$this->assertFalse( get_transient( Sitemap::CACHE ) );
		$this->assertStringContainsString( 'Second', Sitemap::xml() );
	}

	/**
	 * ISO 639 codes from the locale, zh-cn and zh-tw for Chinese.
	 */
	public function test_language(): void {
		$post = get_post( $this->news( 'Sprache', 1 ) );
		foreach ( array(
			'de_DE'        => 'de',
			'de_DE_formal' => 'de',
			'fil'          => 'fil',
			'zh_CN'        => 'zh-cn',
			'zh_TW'        => 'zh-tw',
		) as $locale => $code ) {
			$filter = static fn(): string => $locale;
			add_filter( 'locale', $filter );
			$this->assertSame( $code, Sitemap::language( $post ), $locale );
			remove_filter( 'locale', $filter );
		}
	}

	/**
	 * The robots.txt names the sitemap at the site root only while it is active: blog_public 1, setting on, no news
	 * sitemap plugin; inactive the rule is gone and a flush is asked for.
	 */
	public function test_robots_and_switches(): void {
		$line = 'Sitemap: ' . untrailingslashit( get_option( 'home' ) ) . '/news.xml';
		$this->assertTrue( Sitemap::enabled() );
		$this->assertStringContainsString( $line, apply_filters( 'robots_txt', "User-agent: *\n", '1' ) );

		update_option( 'blog_public', '0' );
		$this->assertFalse( Sitemap::enabled() );
		$this->assertStringNotContainsString( 'news.xml', apply_filters( 'robots_txt', "User-agent: *\n", '0' ) );

		update_option( 'blog_public', '1' );
		update_option( 'leitvia_studio_settings', array( 'news_sitemap' => false ) );
		$this->assertFalse( Sitemap::enabled() );
		$this->assertStringNotContainsString( 'news.xml', apply_filters( 'robots_txt', "User-agent: *\n", '1' ) );

		delete_option( 'leitvia_studio_settings' );
		add_filter( 'leitvia_studio_news_sitemap_enabled', '__return_false' );
		$this->assertFalse( Sitemap::enabled() );
		remove_filter( 'leitvia_studio_news_sitemap_enabled', '__return_false' );

		// A change of the state asks for a flush; the rule exists only while active.
		update_option( Sitemap::RULES_OPTION, '0' );
		delete_option( \LeitviaStudio\Model\Model::FLUSH_OPTION );
		Sitemap::register();
		$this->assertSame( Sitemap::RULE, get_option( Sitemap::RULES_OPTION ) );
		$this->assertSame( 1, (int) get_option( \LeitviaStudio\Model\Model::FLUSH_OPTION ) );
		global $wp_rewrite;
		$this->assertArrayHasKey( Sitemap::RULE, $wp_rewrite->extra_rules_top );
	}

	/**
	 * Inactive, the address answers 404 instead of the sitemap.
	 */
	public function test_inactive_answers_404(): void {
		update_option( 'leitvia_studio_settings', array( 'news_sitemap' => false ) );
		$this->go_to( home_url( '/?' . Sitemap::QUERY_VAR . '=1' ) );
		Sitemap::maybe_render();
		$this->assertTrue( is_404() );
	}

	/**
	 * Creates a post published the given number of hours ago.
	 *
	 * @param string               $title Title.
	 * @param int                  $hours Hours since publishing.
	 * @param array<string, mixed> $args  More post arguments.
	 */
	private function news( string $title, int $hours, array $args = array() ): int {
		return self::factory()->post->create(
			$args + array(
				'post_title' => $title,
				'post_date'  => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) ),
			)
		);
	}
}
