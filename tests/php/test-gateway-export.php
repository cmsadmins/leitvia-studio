<?php
/**
 * Gateway export ability.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Abilities\Export;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Tokens\Token_Sets;

/**
 * Ability leitvia/export: parts, paging with next, the byte limit, and that it reads only what the gateway may read.
 */
class Test_Gateway_Export extends WP_UnitTestCase {

	/**
	 * Leitvia theme, agent user and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		update_option( Gateway::OPTION, Gateway::DEFAULTS );
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
	 * Runs the ability.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	private static function export( array $input ): array {
		return wp_get_ability( 'leitvia/export' )->execute( $input );
	}

	/**
	 * The first call holds content model and template parts; pages, menus and token sets follow page by page until
	 * next is null. Private and password protected pages stay out, nothing is written.
	 */
	public function test_pages_and_parts(): void {
		$pages  = self::factory()->post->create_many( 3, array( 'post_type' => 'page' ) );
		$hidden = array(
			self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 'private',
				)
			),
			self::factory()->post->create(
				array(
					'post_type'     => 'page',
					'post_password' => 'secret',
				)
			),
		);
		$menu   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
			)
		);
		$set    = self::factory()->post->create(
			array(
				'post_type'   => Token_Sets::POST_TYPE,
				'post_status' => 'private',
				'meta_input'  => array( Token_Sets::META => '{"color":{}}' ),
			)
		);

		$first = self::export( array( 'limit' => 2 ) );
		$this->assertSame( Export::FORMAT, $first['format'] );
		$this->assertSame( 'leitvia', $first['site']['theme'] );
		$this->assertIsArray( $first['content_model'] );
		$this->assertNotEmpty( $first['template_parts'] );
		$this->assertContains( 'header', array_column( $first['template_parts'], 'slug' ) );
		$this->assertSame( 2, $first['next'] );

		$items = $first['items'];
		$next  = $first['next'];
		while ( null !== $next ) {
			$page = self::export(
				array(
					'limit'  => 2,
					'offset' => $next,
				)
			);
			$this->assertNull( $page['content_model'] );
			$this->assertSame( array(), $page['template_parts'] );
			$items = array_merge( $items, $page['items'] );
			$next  = $page['next'];
		}
		$ids = array_column( $items, 'id' );
		$this->assertSame( array_unique( $ids ), $ids, 'no item twice' );
		$this->assertSame( array(), array_diff( array_merge( $pages, array( $menu, $set ) ), $ids ) );
		$this->assertSame( array(), array_intersect( $hidden, $ids ) );
		$by_id = array_column( $items, null, 'id' );
		$this->assertSame( 'pages', $by_id[ $pages[0] ]['kind'] );
		$this->assertSame( Hashes::document( $pages[0] ), $by_id[ $pages[0] ]['hash'] );
		$this->assertSame( 'navigation', $by_id[ $menu ]['kind'] );
		$this->assertStringContainsString( 'navigation-link', $by_id[ $menu ]['content'] );
		$this->assertSame( 'token_sets', $by_id[ $set ]['kind'] );
		$this->assertEquals( (object) array( 'color' => new stdClass() ), $by_id[ $set ]['document'] );
	}

	/**
	 * Parts limit the export; only content model asks for no posts at all.
	 */
	public function test_parts(): void {
		self::factory()->post->create( array( 'post_type' => 'page' ) );
		$model = self::export( array( 'parts' => array( 'content_model' ) ) );
		$this->assertSame( array( array(), null, array() ), array( $model['items'], $model['next'], $model['template_parts'] ) );
		$this->assertIsArray( $model['content_model'] );
		$pages = self::export( array( 'parts' => array( 'pages' ) ) );
		$this->assertNull( $pages['content_model'] );
		$this->assertSame( array( 'pages' ), array_values( array_unique( array_column( $pages['items'], 'kind' ) ) ) );
	}

	/**
	 * A call stops at MAX_BYTES but always carries one item.
	 */
	public function test_byte_limit(): void {
		$text = '<!-- wp:paragraph --><p>' . str_repeat( 'a', 600000 ) . '</p><!-- /wp:paragraph -->';
		self::factory()->post->create_many(
			3,
			array(
				'post_type'    => 'page',
				'post_content' => $text,
			)
		);
		$first = self::export( array( 'parts' => array( 'pages' ) ) );
		$this->assertCount( 1, $first['items'] );
		$this->assertSame( 1, $first['next'] );
	}
}
