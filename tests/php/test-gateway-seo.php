<?php
/**
 * Gateway SEO abilities.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\News\Head;

/**
 * Abilities get-seo and update-seo: core defaults without an SEO plugin, the meta of Yoast SEO and Rank Math through
 * the adapter (selected with the filter leitvia_studio_seo_adapter, so no plugin needs to be installed), length and
 * fact checks, packages and rollback.
 */
class Test_Gateway_Seo extends WP_UnitTestCase {

	/**
	 * Agent user, standard profile and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		update_option( Gateway::OPTION, Gateway::DEFAULTS );
		update_option( 'blogname', 'Bike Shop' );
		self::unregister();
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes the abilities and the adapter filter.
	 */
	public function tear_down(): void {
		self::unregister();
		remove_all_filters( 'leitvia_studio_seo_adapter' );
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
	 * Uses the meta of a plugin without installing it.
	 *
	 * @param string $key Plugin key.
	 */
	private static function adapter( string $key ): void {
		add_filter( 'leitvia_studio_seo_adapter', static fn() => $key );
	}

	/**
	 * A published page.
	 */
	private function page(): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Repairs',
				'post_excerpt' => 'We repair city bikes and e-bikes in 2 days, from 40 euros.',
				'post_content' => '<!-- wp:paragraph --><p>Bring your bike on weekdays.</p><!-- /wp:paragraph -->',
			)
		);
	}

	/**
	 * Runs update-seo with the hash from get-seo.
	 *
	 * @param int                  $post  Post.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function update( int $post, array $input ) {
		$read = wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $post ) ) );
		return wp_get_ability( 'leitvia/update-seo' )->execute(
			$input + array(
				'post'      => $post,
				'base_hash' => $read['items'][0]['hash'],
			)
		);
	}

	/**
	 * Asserts a rejection with code and reason.
	 *
	 * @param string $code   Code.
	 * @param string $reason Reason.
	 * @param mixed  $result Result.
	 */
	private function assert_rejected( string $code, string $reason, $result ): void {
		$this->assertWPError( $result );
		$this->assertSame( array( $code, $reason ), array( $result->get_error_code(), $result->get_error_data()['reason'] ), $result->get_error_message() );
	}

	/**
	 * Without an SEO plugin: Leitvia Studio keeps the fields, defaults hold the document title, permalink and a description
	 * from the excerpt, and unreadable IDs are skipped. An SEO plugin Studio cannot write to rejects update-seo for good.
	 */
	public function test_without_plugin(): void {
		$page    = $this->page();
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$result  = wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $page, $private, 999999 ) ) );
		$this->assertSame( 'leitvia', $result['plugin']['key'] );
		$this->assertNotSame( '', $result['notice'] );
		$this->assertSame( array( $private, 999999 ), $result['skipped'] );
		$item = $result['items'][0];
		$this->assertSame( '', $item['seo']['title'] );
		$this->assertSame( Hashes::document( $page ), $item['hash'] );
		$this->assertSame( 'Repairs - Bike Shop', $item['defaults']['title'] );
		$this->assertSame( 'We repair city bikes and e-bikes in 2 days, from 40 euros.', $item['defaults']['description'] );
		$this->assertSame( get_permalink( $page ), $item['defaults']['canonical'] );
		add_filter( 'leitvia_studio_seo_plugin_active', '__return_true' );
		$this->assertNull( wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $page ) ) )['plugin'] );
		$this->assert_rejected( 'leitvia_no_seo_plugin', 'seo_plugin', self::update( $page, array( 'title' => 'Bike repairs' ) ) );
		remove_filter( 'leitvia_studio_seo_plugin_active', '__return_true' );
		self::adapter( 'aioseo' );
		$this->assertFalse( wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $page ) ) )['plugin']['writable'] );
		$this->assert_rejected( 'leitvia_no_seo_plugin', 'seo_plugin', self::update( $page, array( 'title' => 'Bike repairs' ) ) );
	}

	/**
	 * Without an SEO plugin a stored title replaces the document title as written, the heading stays, and description
	 * and robots reach the head. An SEO plugin takes over all of it.
	 */
	public function test_head_without_plugin(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$page = $this->page();
		$this->go_to( get_permalink( $page ) );
		$result = self::update(
			$page,
			array(
				'title'       => 'Bike repairs in 2 days',
				'description' => 'We repair city bikes and e-bikes in 2 days, from 40 euros. Bring your bike on weekdays.',
				'noindex'     => true,
			)
		);
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'Repairs', get_post( $page )->post_title );
		$this->assertSame( 'Bike repairs in 2 days', wp_get_document_title() );
		$this->assertTrue( apply_filters( 'wp_robots', array() )['noindex'] );
		$this->assertStringContainsString( '<meta name="description" content="We repair city bikes', get_echo( array( Head::class, 'print' ) ) );

		add_filter( 'leitvia_studio_seo_plugin_active', '__return_true' );
		$this->assertStringContainsString( 'Repairs', wp_get_document_title() );
		$this->assertStringNotContainsString( 'in 2 days', wp_get_document_title() );
		$this->assertArrayNotHasKey( 'noindex', apply_filters( 'wp_robots', array() ) );
		$this->assertSame( '', get_echo( array( Head::class, 'print' ) ) );
		remove_filter( 'leitvia_studio_seo_plugin_active', '__return_true' );
	}

	/**
	 * Archives get a canonical address with their page number, single pages stay with core.
	 */
	public function test_canonical_of_archives(): void {
		// The default category: a new term would shift the term IDs in the fixtures of Test_News_Schema.
		$term = (int) get_option( 'default_category' );
		self::factory()->post->create_many( 3 );
		update_option( 'posts_per_page', 1 );
		$this->go_to( get_term_link( $term ) );
		$this->assertSame( get_term_link( $term ), Head::canonical() );
		$this->go_to( add_query_arg( 'paged', 2, get_term_link( $term ) ) );
		$this->assertMatchesRegularExpression( '#(/page/2/|paged=2)$#', Head::canonical() );
		$this->go_to( get_permalink( $this->page() ) );
		$this->assertSame( '', Head::canonical() );
		$this->go_to( '/?s=bike' );
		$this->assertSame( '', Head::canonical() );
	}

	/**
	 * Yoast SEO: reads its meta; a write lands in a package at the default level, goes live at full autonomy, changes the
	 * hash and rolls back.
	 */
	public function test_yoast_read_write_and_rollback(): void {
		self::adapter( 'yoast' );
		$page = $this->page();
		update_post_meta( $page, '_yoast_wpseo_title', 'Old title' );
		update_post_meta( $page, '_yoast_wpseo_meta-robots-nofollow', '1' );
		$seo = wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $page ) ) )['items'][0]['seo'];
		$this->assertSame( array( 'Old title', '', false, true ), array( $seo['title'], $seo['description'], $seo['noindex'], $seo['nofollow'] ) );

		$input   = array(
			'title'       => 'Bike repairs in 2 days',
			'description' => 'We repair city bikes and e-bikes in 2 days, from 40 euros. Bring your bike on weekdays.',
			'noindex'     => true,
		);
		$package = self::update( $page, $input );
		$this->assertSame( 'package', $package['mode'] );
		$this->assertSame( 'Old title', get_post_meta( $page, '_yoast_wpseo_title', true ) );

		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$before = Hashes::document( $page );
		$live   = self::update( $page, $input );
		$this->assertSame( 'live', $live['mode'] );
		$this->assertSame( 'Bike repairs in 2 days', get_post_meta( $page, '_yoast_wpseo_title', true ) );
		$this->assertSame( $input['description'], get_post_meta( $page, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( '1', get_post_meta( $page, '_yoast_wpseo_meta-robots-noindex', true ) );
		$this->assertNotSame( $before, $live['hash'] );
		$this->assertSame( $live['hash'], Hashes::document( $page ) );

		$stale = wp_get_ability( 'leitvia/update-seo' )->execute(
			array(
				'post'      => $page,
				'title'     => 'Repairs',
				'base_hash' => $before,
			)
		);
		$this->assertSame( 'leitvia_stale_hash', $stale->get_error_code() );

		$this->assertSame( 'applied', Packages::state( $live['package'] ) );
		$rolled = wp_get_ability( 'leitvia/rollback' )->execute( array( 'id' => $live['package'] ) );
		$this->assertSame( 'rolled_back', $rolled['state'] );
		$this->assertSame( 'Old title', get_post_meta( $page, '_yoast_wpseo_title', true ) );
		$this->assertSame( '', get_post_meta( $page, '_yoast_wpseo_meta-robots-noindex', true ) );
	}

	/**
	 * Rank Math keeps robots as a list: noindex and nofollow change their entries and keep the others.
	 */
	public function test_rank_math_robots(): void {
		self::adapter( 'rank-math' );
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$page = $this->page();
		update_post_meta( $page, 'rank_math_robots', array( 'index', 'noarchive' ) );
		$result = self::update( $page, array( 'noindex' => true ) );
		$this->assertSame( 'live', $result['mode'] );
		$this->assertSame( array( 'noindex', 'noarchive' ), get_post_meta( $page, 'rank_math_robots', true ) );
		self::update( $page, array( 'nofollow' => true ) );
		$this->assertSame( array( 'noindex', 'noarchive', 'nofollow' ), get_post_meta( $page, 'rank_math_robots', true ) );
		$seo = wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $page ) ) )['items'][0]['seo'];
		$this->assertSame( array( true, true ), array( $seo['noindex'], $seo['nofollow'] ) );
	}

	/**
	 * Lengths, empty input and numbers the page does not hold are rejected before anything is written.
	 */
	public function test_checks(): void {
		self::adapter( 'seopress' );
		$page = $this->page();
		$this->assert_rejected( 'leitvia_invalid_input', 'change', self::update( $page, array() ) );
		$this->assert_rejected( 'leitvia_invalid_input', 'length', self::update( $page, array( 'title' => str_repeat( 'a', 61 ) ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', 'length', self::update( $page, array( 'description' => 'Too short.' ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', 'unsupported_fact', self::update( $page, array( 'title' => 'Bike repairs since 1998' ) ) );
		$this->assertSame( '', get_post_meta( $page, '_seopress_titles_title', true ) );
		$this->assertSame( 'package', self::update( $page, array( 'title' => 'Bike repairs from 40 euros' ) )['mode'] );
	}

	/**
	 * Get-seo names the article type per post, whether the site is indexable and the news sitemap.
	 */
	public function test_schema_type_indexable_and_news_sitemap(): void {
		update_option( 'blog_public', '1' );
		$post   = self::factory()->post->create( array( 'post_title' => 'News' ) );
		$result = wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $post, $this->page() ) ) );
		$this->assertSame( array( 'NewsArticle', null ), array_column( $result['items'], 'schema_type' ) );
		$this->assertTrue( $result['indexable'] );
		$this->assertSame( \LeitviaStudio\News\Sitemap::url(), $result['news_sitemap'] );

		$category = self::factory()->category->create();
		update_option( 'leitvia_studio_settings', array( 'news_categories' => array( $category ) ) );
		update_option( 'blog_public', '0' );
		$result = wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $post ) ) );
		$this->assertSame( 'BlogPosting', $result['items'][0]['schema_type'] );
		$this->assertFalse( $result['indexable'] );
		$this->assertNull( $result['news_sitemap'] );

		add_filter( 'leitvia_studio_seo_plugin_active', '__return_true' );
		$this->assertNull( wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $post ) ) )['items'][0]['schema_type'] );
		remove_filter( 'leitvia_studio_seo_plugin_active', '__return_true' );
	}

	/**
	 * Create-content takes SEO title and description with the checks of update-seo and writes them through the
	 * adapter; with an SEO plugin Studio cannot write to they are left out and notice says so.
	 */
	public function test_create_content_with_seo(): void {
		update_option( Gateway::OPTION, array( 'profile' => 'full' ) + Gateway::DEFAULTS );
		$create = static fn( array $seo ) => wp_get_ability( 'leitvia/create-content' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Repairs',
				'excerpt'   => 'We repair city bikes and e-bikes in 2 days, from 40 euros.',
				'content'   => '<!-- wp:paragraph --><p>Bring your bike on weekdays.</p><!-- /wp:paragraph -->',
				'seo'       => $seo,
				'base_hash' => '',
			)
		);
		$texts  = array(
			'title'       => 'Bike repairs in 2 days',
			'description' => 'We repair city bikes and e-bikes in 2 days, from 40 euros. Bring your bike on weekdays.',
		);

		$result = $create( $texts );
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( $texts['title'], get_post_meta( $result['target']['post'], '_leitvia_seo_title', true ) );

		self::adapter( 'aioseo' );
		$result = $create( $texts );
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertNotSame( '', $result['notice'] ?? '' );
		$this->assertSame( '', get_post_meta( $result['target']['post'], '_yoast_wpseo_title', true ) );

		self::adapter( 'yoast' );
		$result = $create( $texts );
		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertArrayNotHasKey( 'notice', $result );
		$this->assertSame( $texts['title'], get_post_meta( $result['target']['post'], '_yoast_wpseo_title', true ) );
		$this->assertSame( $texts['description'], get_post_meta( $result['target']['post'], '_yoast_wpseo_metadesc', true ) );

		$this->assert_rejected( 'leitvia_invalid_input', 'length', $create( array( 'title' => str_repeat( 'a', 61 ) ) ) );
		$this->assert_rejected( 'leitvia_invalid_input', 'unsupported_fact', $create( array( 'title' => 'Bike repairs since 1998' ) ) );
	}
}
