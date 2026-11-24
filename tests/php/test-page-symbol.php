<?php
/**
 * Lead symbol of a page, the hub of a post and the token iconStroke.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\News\Aside;
use LeitviaStudio\Seo\Cluster;
use LeitviaStudio\Tokens\Dtcg;

/**
 * The icon of set-page-structure and the hub of a post, the binding source leitvia/page-symbol with its filter, the
 * card of News\Aside and the optional token iconStroke of Tokens\Dtcg.
 */
class Test_Page_Symbol extends WP_UnitTestCase {

	/**
	 * Agent user at full autonomy and the abilities handed to core.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
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
	 * Removes the abilities and the filter of a test.
	 */
	public function tear_down(): void {
		self::unregister();
		remove_all_filters( 'leitvia_studio_page_symbol' );
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
	 * Runs set-page-structure with the hash of the post.
	 *
	 * @param int                  $post  Post or page.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function structure( int $post, array $input ) {
		return wp_get_ability( 'leitvia/set-page-structure' )->execute(
			$input + array(
				'post'      => $post,
				'base_hash' => Hashes::document( $post ),
			)
		);
	}

	/**
	 * A page takes an icon of the set as its lead symbol and get-seo shows it; an unknown name is refused, '' clears it.
	 * An icon block bound to leitvia/page-symbol draws it, keeps its own icon without one, and an add-on gives one
	 * through the filter.
	 */
	public function test_lead_symbol_of_a_page(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Hosting',
			)
		);
		$this->assertWPError( self::structure( $page, array( 'icon' => 'no-such-icon' ) ) );
		$this->assertNotWPError( self::structure( $page, array( 'icon' => 'server' ) ) );
		$this->assertSame( 'server', get_post_meta( $page, Cluster::ICON_META, true ) );
		$this->assertSame( 'server', wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $page ) ) )['items'][0]['structure']['icon'] );

		$GLOBALS['post'] = get_post( $page ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The page being rendered.
		$block           = '<!-- wp:leitvia/icon {"icon":"layers","metadata":{"bindings":{"icon":{"source":"leitvia/page-symbol"}}}} /-->';
		$this->assertStringContainsString( 'leitvia-icon--server', do_blocks( $block ) );

		$this->assertNotWPError( self::structure( $page, array( 'icon' => '' ) ) );
		$this->assertStringContainsString( 'leitvia-icon--layers', do_blocks( $block ) );

		add_filter( 'leitvia_studio_page_symbol', static fn( string $icon, int $id ): string => $id === $page ? 'box' : $icon, 10, 2 );
		$this->assertStringContainsString( 'leitvia-icon--box', do_blocks( $block ) );
	}

	/**
	 * A post takes only hub: the card beside it names the service of the hub and links it; frame or icon on a post are
	 * refused, and a post without a hub has no card.
	 */
	public function test_hub_of_a_post_and_its_card(): void {
		$hub  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Website care',
				'post_excerpt' => 'Updates, backups and support for your site.',
			)
		);
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertWPError( self::structure( $post, array( 'icon' => 'server' ) ) );
		$this->assertWPError( self::structure( $post, array( 'frame' => 'service' ) ) );
		$this->assertSame( '', Aside::card( 'leitvia-wide-only' ) );

		$this->assertNotWPError( self::structure( $post, array( 'hub' => $hub ) ) );
		$this->assertSame( $hub, (int) get_post_meta( $post, Cluster::HUB_META, true ) );
		$this->assertSame( $hub, wp_get_ability( 'leitvia/get-seo' )->execute( array( 'posts' => array( $post ) ) )['items'][0]['structure']['hub'] );

		$GLOBALS['post'] = get_post( $post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The post being rendered.
		$card            = do_blocks( Aside::card( 'leitvia-narrow-only' ) );
		$this->assertStringContainsString( 'leitvia-narrow-only', $card );
		$this->assertStringContainsString( 'Website care', $card );
		$this->assertStringContainsString( 'Updates, backups and support for your site.', $card );
		$this->assertStringContainsString( 'href="' . get_permalink( $hub ) . '"', $card );
		$this->assertStringContainsString( 'wp:leitvia/table-of-contents', Aside::top( '' ) );
	}

	/**
	 * The token iconStroke is an optional top-level number token from 1 to 3, written as settings.custom.iconStroke and exported
	 * again; outside the range or as a group it is refused.
	 */
	public function test_icon_stroke_token(): void {
		$contract = leitvia_studio_contract();
		$doc      = Dtcg::from_settings( wp_get_global_settings(), $contract );
		$this->assertArrayNotHasKey( Dtcg::ICON_STROKE, $doc );

		$doc[ Dtcg::ICON_STROKE ] = array(
			'$type'  => 'number',
			'$value' => 1.5,
		);
		$settings                 = Dtcg::to_settings( $doc, $contract );
		$this->assertNotWPError( $settings );
		$this->assertSame( 1.5, $settings['custom'][ Dtcg::ICON_STROKE ] );

		$back = Dtcg::from_settings( array( 'custom' => array( Dtcg::ICON_STROKE => 1.5 ) ) + wp_get_global_settings(), $contract );
		$this->assertSame( 1.5, $back[ Dtcg::ICON_STROKE ]['$value'] );

		$laid = \LeitviaStudio\Tokens\Compiler::lay_over( array(), array( 'custom' => array( Dtcg::ICON_STROKE => 1.5 ) ) );
		$this->assertSame( 1.5, $laid['settings']['custom'][ Dtcg::ICON_STROKE ] );
		$this->assertArrayNotHasKey( 'custom', \LeitviaStudio\Tokens\Compiler::lay_over( $laid, array() )['settings'] ?? array() );

		foreach ( array( 4, 0.5, '1.5' ) as $wrong ) {
			$doc[ Dtcg::ICON_STROKE ]['$value'] = $wrong;
			$this->assertSame( 'leitvia_studio_dtcg_icon_stroke', Dtcg::to_settings( $doc, $contract )->get_error_code() );
		}
	}
}
