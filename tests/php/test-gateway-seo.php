<?php
/**
 * Gateway SEO abilities.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;

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
	 * Without an SEO plugin: seo is null, defaults hold the document title, permalink and a description from the
	 * excerpt, and unreadable IDs are skipped; update-seo is rejected for good.
	 */
	public function test_without_plugin(): void {
		$page    = $this->page();
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$result  = wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $page, $private, 999999 ) ) );
		$this->assertNull( $result['plugin'] );
		$this->assertNotSame( '', $result['notice'] );
		$this->assertSame( array( $private, 999999 ), $result['skipped'] );
		$item = $result['items'][0];
		$this->assertNull( $item['seo'] );
		$this->assertSame( Hashes::document( $page ), $item['hash'] );
		$this->assertSame( 'Repairs - Bike Shop', $item['defaults']['title'] );
		$this->assertSame( 'We repair city bikes and e-bikes in 2 days, from 40 euros.', $item['defaults']['description'] );
		$this->assertSame( get_permalink( $page ), $item['defaults']['canonical'] );
		$this->assert_rejected( 'leitvia_no_seo_plugin', 'seo_plugin', self::update( $page, array( 'title' => 'Bike repairs' ) ) );
		self::adapter( 'aioseo' );
		$this->assertFalse( wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $page ) ) )['plugin']['writable'] );
		$this->assert_rejected( 'leitvia_no_seo_plugin', 'seo_plugin', self::update( $page, array( 'title' => 'Bike repairs' ) ) );
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
}
