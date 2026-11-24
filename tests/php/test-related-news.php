<?php
/**
 * Related news: the topic a page names with set-page-structure and the query of the pattern Related news.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Seo\Related_News;

/**
 * A page names categories and tags by ID or slug; the theme lists the latest posts with any of them, the latest posts
 * when none has one, and never the current post.
 */
class Test_Related_News extends WP_UnitTestCase {

	/**
	 * Agent user at full autonomy and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		// functions.php of the theme loads after switch_theme only on the next request: load it, add the filters under test.
		if ( ! function_exists( 'Leitvia\related_news_vars' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
		add_filter( 'render_block_data', 'Leitvia\related_news_mark' );
		add_filter( 'query_loop_block_query_vars', 'Leitvia\related_news_vars', 10, 2 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		update_option(
			Gateway::OPTION,
			array(
				'profile'   => 'full',
				'overrides' => array(
					'areas' => array(
						'content'   => 'autonomous',
						'structure' => 'autonomous',
					),
					'kinds' => Gateway::KINDS,
				),
			) + Gateway::DEFAULTS
		);
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes the abilities.
	 */
	public function tear_down(): void {
		self::unregister();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Unregisters the abilities of Leitvia and the category.
	 */
	private static function unregister(): void {
		foreach ( wp_get_abilities() as $ability ) {
			if ( 'leitvia' === $ability->get_category() ) {
				wp_unregister_ability( $ability->get_name() );
			}
		}
		if ( wp_has_ability_category( 'leitvia' ) ) {
			wp_unregister_ability_category( 'leitvia' );
		}
	}

	/**
	 * Titles of the posts the related news query lists on the current page.
	 *
	 * @return list<string>
	 */
	private static function listed(): array {
		$html = do_blocks( '<!-- wp:query {"queryId":1,"query":{"perPage":3,"postType":"post","order":"desc","orderBy":"date","inherit":false,"excludeCurrent":true},"namespace":"leitvia/related-news"} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->' );
		preg_match_all( '#<h2[^>]*>(.*?)</h2>#', $html, $found );
		return array_map( 'wp_strip_all_tags', $found[1] );
	}

	/**
	 * A published post.
	 *
	 * @param string $title      Title.
	 * @param int[]  $categories Category IDs.
	 * @param int[]  $tags       Tag IDs.
	 * @param string $date       Date.
	 */
	private function post( string $title, array $categories, array $tags, string $date ): int {
		$id = self::factory()->post->create(
			array(
				'post_title'    => $title,
				'post_status'   => 'publish',
				'post_category' => $categories,
				'post_date'     => $date,
			)
		);
		if ( $tags ) {
			wp_set_post_tags( $id, $tags );
		}
		return $id;
	}

	/**
	 * The ability set-page-structure stores the topic by ID and slug, get-seo shows it, the query follows it and falls back.
	 */
	public function test_page_names_its_news(): void {
		$hosting = self::factory()->category->create( array( 'slug' => 'hosting' ) );
		$tag     = self::factory()->tag->create( array( 'slug' => 'backup' ) );
		$other   = self::factory()->category->create( array( 'slug' => 'events' ) );
		$this->post( 'Server move', array( $hosting ), array(), '2026-11-01 10:00:00' );
		$this->post( 'Backups explained', array( $other ), array( $tag ), '2026-11-02 10:00:00' );
		$this->post( 'Summer party', array( $other ), array(), '2026-11-03 10:00:00' );
		$page   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Hosting',
			)
		);
		$result = wp_get_ability( 'leitvia/set-page-structure' )->execute(
			array(
				'post'      => $page,
				'base_hash' => Hashes::document( $page ),
				'news'      => array(
					'categories' => array( 'hosting' ),
					'tags'       => array( $tag ),
				),
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			array(
				'categories' => array( $hosting ),
				'tags'       => array( $tag ),
			),
			Related_News::of( $page )
		);
		$seo = wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $page ) ) );
		$this->assertSame( array( $hosting ), $seo['items'][0]['structure']['news']['categories'] );

		$this->go_to( (string) get_permalink( $page ) );
		$this->assertSame( array( 'Backups explained', 'Server move' ), self::listed(), 'posts with any of the terms, newest first' );

		update_post_meta( $page, Related_News::META, array( 'categories' => array( self::factory()->category->create() ) ) );
		$this->go_to( (string) get_permalink( $page ) );
		$this->assertSame( array( 'Summer party', 'Backups explained', 'Server move' ), self::listed(), 'no post on the topic: the latest posts' );

		$wrong = wp_get_ability( 'leitvia/set-page-structure' )->execute(
			array(
				'post'      => $page,
				'base_hash' => Hashes::document( $page ),
				'news'      => array( 'categories' => array( 'no-such-category' ) ),
			)
		);
		$this->assertWPError( $wrong );
		$cleared = wp_get_ability( 'leitvia/set-page-structure' )->execute(
			array(
				'post'      => $page,
				'base_hash' => Hashes::document( $page ),
				'news'      => null,
			)
		);
		$this->assertIsArray( $cleared, is_wp_error( $cleared ) ? $cleared->get_error_message() : '' );
		$this->assertSame( '', get_post_meta( $page, Related_News::META, true ) );
	}

	/**
	 * On a post the query takes its categories and leaves the post itself out.
	 */
	public function test_post_takes_its_categories(): void {
		$cat   = self::factory()->category->create();
		$first = $this->post( 'First', array( $cat ), array(), '2026-11-01 10:00:00' );
		$this->post( 'Second', array( $cat ), array(), '2026-11-02 10:00:00' );
		$this->post( 'Elsewhere', array(), array(), '2026-11-03 10:00:00' );
		$this->go_to( (string) get_permalink( $first ) );
		$this->assertSame( array( 'Second' ), self::listed() );
	}
}
