<?php
/**
 * What Google News reads from a post beyond the reference output of Test_News_Schema.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\News\Feed;
use LeitviaStudio\News\Schema;

/**
 * Headline of at most 110 characters, the page of an author without an account, sameAs of a profile,
 * max-image-preview and the image of a feed item.
 */
class Test_News_Google extends WP_UnitTestCase {

	/**
	 * Site public, no SEO plugin.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'blog_public', '1' );
		update_option( 'blogname', 'Harbour Notes' );
	}

	/**
	 * A title over 110 characters is cut at a word with an ellipsis; a short one stays.
	 */
	public function test_headline_fits_110_characters(): void {
		$this->assertSame( 'Short title', Schema::headline( 'Short title' ) );
		$long     = str_repeat( 'Harbour ', 20 ) . 'end';
		$headline = Schema::headline( $long );
		$this->assertLessThanOrEqual( Schema::HEADLINE_MAX, mb_strlen( $headline ) );
		$this->assertStringEndsWith( 'Harbour…', $headline );
		$post = self::factory()->post->create(
			array(
				'post_title'  => $long,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( $headline, Schema::article( get_post( $post ) )['headline'] );
	}

	/**
	 * An author without an account with a page: the Person names the page as url and the author link leads there. The
	 * profile website of an author with an account becomes sameAs.
	 */
	public function test_author_page_and_same_as(): void {
		$about = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Team',
			)
		);
		$post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post, Schema::AUTHOR_NAME_META, 'Jona Weller' );
		update_post_meta( $post, Schema::AUTHOR_PAGE_META, $about );
		$author = Schema::article( get_post( $post ) )['author'][0];
		$this->assertSame( 'Jona Weller', $author['name'] );
		$this->assertSame( get_permalink( $about ), $author['url'] );
		$GLOBALS['post'] = get_post( $post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The post being rendered.
		$this->assertSame( get_permalink( $about ), get_author_posts_url( (int) get_post( $post )->post_author ) );

		$user  = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Mara Lindqvist',
				'user_url'     => 'https://mara.example',
			)
		);
		$other = self::factory()->post->create(
			array(
				'post_author' => $user,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( array( 'https://mara.example' ), Schema::article( get_post( $other ) )['author'][0]['sameAs'] );
	}

	/**
	 * A site open to search engines asks for large image previews; Studio keeps the directive of core.
	 */
	public function test_max_image_preview(): void {
		$this->assertSame( 'large', apply_filters( 'wp_robots', array() )['max-image-preview'] ?? null );
	}

	/**
	 * Titles end with " | " and the site name where the whole keeps to 60 characters and the title does not name the
	 * site, stored or built by WordPress; the front page keeps its title; off, a stored title stands as written. The
	 * ability set-seo-titles switches it.
	 */
	public function test_site_name_in_titles(): void {
		$meta  = \LeitviaStudio\Gateway\Abilities\Seo::PLUGINS['leitvia']['meta']['title'];
		$page  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Opening hours',
			)
		);
		$long  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Ferries, buses and trains between the harbour and the old town',
			)
		);
		$named = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'About',
			)
		);
		update_post_meta( $page, $meta, 'Opening hours of the harbour office' );
		update_post_meta( $named, $meta, 'About Harbour Notes' );
		$title = static function ( int $id ): string {
			$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $id ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The request of the page.
			return html_entity_decode( wp_get_document_title(), ENT_QUOTES, 'UTF-8' );
		};
		$this->assertSame( 'Opening hours of the harbour office | Harbour Notes', $title( $page ) );
		$this->assertSame( 'Ferries, buses and trains between the harbour and the old town', $title( $long ) );
		$this->assertSame( 'About Harbour Notes', $title( $named ) );

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );
		$this->assertSame( 'Opening hours of the harbour office', $title( $page ) );
		update_option( 'show_on_front', 'posts' );

		update_option( \LeitviaStudio\Admin\Settings::OPTION, array( 'title_site_name' => false ) + (array) get_option( \LeitviaStudio\Admin\Settings::OPTION, array() ) );
		$this->assertSame( 'Opening hours of the harbour office', $title( $page ) );
		$this->assertStringContainsString( 'Harbour Notes', $title( $long ) );
		$change = \LeitviaStudio\Gateway\Abilities\Seo::describe_titles( array( 'site_name' => true ) )['change'];
		$this->assertTrue( $change( array( 'value' => array( 'title_site_name' => false ) ) )['value']['title_site_name'] );
	}

	/**
	 * A featured image of 1200 px or more goes into the feed item, a smaller one does not.
	 */
	public function test_feed_image(): void {
		$this->assertNull( Feed::image( 0 ) );
		$large = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/33772.jpg' );
		$meta  = wp_get_attachment_metadata( $large );
		$image = Feed::image( $large );
		if ( (int) $meta['width'] >= Schema::IMAGE_MIN_WIDTH ) {
			$this->assertGreaterThanOrEqual( Schema::IMAGE_MIN_WIDTH, $image[1] );
		} else {
			$this->assertNull( $image );
		}
		ob_start();
		Feed::namespace();
		$this->assertStringContainsString( 'xmlns:media', (string) ob_get_clean() );
	}
}
