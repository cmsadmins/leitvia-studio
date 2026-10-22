<?php
/**
 * Token set activation tests.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Dtcg;
use LeitviaStudio\Tokens\Token_Sets;

/**
 * Writing into user global styles, drift, checks and the REST route.
 */
class Test_Compiler extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme as an administrator.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		// The test case unregisters all meta keys after each test.
		Token_Sets::register();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
	}

	/**
	 * Drops cached contract, checks and theme.json data.
	 */
	public function tear_down(): void {
		remove_all_filters( 'leitvia_studio_contract_path' );
		remove_all_actions( 'leitvia_studio_init', 99 );
		leitvia_studio()->reset_for_tests();
		\LeitviaStudio\Plugin::forget_contract();
		WP_Theme_JSON_Resolver::clean_cached_data();
		parent::tear_down();
	}

	/**
	 * Activation replaces only the preset lists and keeps other user styles.
	 */
	public function test_activate_writes_presets_and_keeps_user_styles(): void {
		$styles = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $styles['ID'],
					'post_content' => wp_json_encode(
						array(
							'version'                     => 3,
							'isGlobalStylesUserThemeJSON' => true,
							'title'                       => 'Clay Colors',
							'styles'                      => array( 'color' => array( 'background' => 'var:preset|color|surface-alt' ) ),
						)
					),
				)
			)
		);
		WP_Theme_JSON_Resolver::clean_cached_data();

		$id = self::set_with_accent( '#123456' );
		$this->assertTrue( Compiler::activate( $id ) );

		$data = json_decode( get_post( $styles['ID'] )->post_content, true );
		$this->assertSame( 'Clay Colors', $data['title'] );
		$this->assertSame( 'var:preset|color|surface-alt', $data['styles']['color']['background'] );
		$this->assertSame( 3, $data['version'] );
		$this->assertTrue( $data['isGlobalStylesUserThemeJSON'] );
		$this->assertSame( array( 'color', 'typography', 'spacing', 'border', 'shadow' ), array_keys( $data['settings'] ) );
		$accent = wp_list_filter( $data['settings']['color']['palette']['theme'], array( 'slug' => 'accent' ) );
		$this->assertSame( '#123456', array_values( $accent )[0]['color'] );

		$this->assertStringContainsString( '--wp--preset--color--accent: #123456', wp_get_global_stylesheet() );
		$this->assertSame( $id, (int) get_option( Compiler::ACTIVE_OPTION ) );
	}

	/**
	 * Activation without a logged-in user (WP-CLI, cron) still reaches the front end: the user global styles post keeps
	 * its theme term, so core finds it instead of creating an empty one.
	 */
	public function test_activate_without_user_reaches_the_front_end(): void {
		wp_set_current_user( 0 );
		$this->assertTrue( Compiler::activate( self::set_with_accent( '#123456' ) ) );
		WP_Theme_JSON_Resolver::clean_cached_data();

		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'wp_global_styles',
					'post_status' => 'any',
					'numberposts' => -1,
				)
			)
		);
		$this->assertStringContainsString( '--wp--preset--color--accent: #123456', wp_get_global_stylesheet() );
	}

	/**
	 * Drift is detected after the active set is saved again and after the palette changes outside Studio.
	 */
	public function test_drift(): void {
		$this->assertFalse( Compiler::drift() );

		// Saving the active set with other values after the activation is drift as well.
		$id = self::set_with_accent( '#123456' );
		$this->assertTrue( Compiler::activate( $id ) );
		$this->assertFalse( Compiler::drift() );
		$doc                                     = json_decode( get_post_meta( $id, Token_Sets::META, true ), true );
		$doc['color']['accent']['$value']['hex'] = '#123457';
		update_post_meta( $id, Token_Sets::META, wp_slash( wp_json_encode( $doc ) ) );
		$this->assertTrue( Compiler::drift() );
		$this->assertTrue( Compiler::activate( $id ) );
		$this->assertFalse( Compiler::drift() );

		$styles = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$data   = json_decode( $styles['post_content'], true );
		$data['settings']['color']['palette']['theme'][0]['color'] = '#000000';
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $styles['ID'],
					'post_content' => wp_json_encode( $data ),
				)
			)
		);
		$this->assertTrue( Compiler::drift() );

		// The hash belongs to the theme it was written for.
		update_option(
			Compiler::HASH_OPTION,
			array(
				'theme' => 'another-theme',
				'hash'  => 'x',
			)
		);
		$this->assertFalse( Compiler::drift() );
	}

	/**
	 * On a site with a style variation in the user global styles (plain preset lists, origin custom for core) a set
	 * starts from the variation, its activation wins in the front end, fonts included, and drift stays false. A custom
	 * preset that shadows a role of the set again is drift, although the theme lists are unchanged.
	 */
	public function test_activate_over_a_style_variation(): void {
		self::apply_variation( 'corporate-light' );
		$this->assertSame( '#2152c7', Dtcg::effective( wp_get_global_settings(), 'color', 'palette' )['accent']['color'] );

		$id  = self::set_with_accent( '#b84a00' );
		$doc = Compiler::document( $id );
		$this->assertSame( 'IBM Plex Sans', $doc['fontFamily']['heading']['$value'][0] );
		$this->assertTrue( Compiler::activate( $id ) );

		$effective = Dtcg::effective( wp_get_global_settings(), 'color', 'palette' );
		$this->assertSame( '#b84a00', $effective['accent']['color'] );
		$css = wp_get_global_stylesheet( array( 'variables' ) );
		$this->assertStringContainsString( '--wp--preset--color--accent: #b84a00', $css );
		$this->assertStringNotContainsString( '#2152c7', $css );
		$this->assertStringContainsString( '--wp--preset--font-family--heading: "IBM Plex Sans"', $css );
		$this->assertFalse( Compiler::drift() );

		$styles = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$data   = json_decode( $styles['post_content'], true );
		$this->assertArrayNotHasKey( 'custom', $data['settings']['color']['palette'] );
		$data['settings']['color']['palette']['custom'] = array(
			array(
				'slug'  => 'accent',
				'name'  => 'Accent',
				'color' => '#2152c7',
			),
		);
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $styles['ID'],
					'post_content' => wp_json_encode( $data ),
				)
			)
		);
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		$this->assertTrue( Compiler::drift() );
	}

	/**
	 * Writes a style variation of the theme into the user global styles, as the site editor and set-variation do.
	 *
	 * @param string $slug Variation slug.
	 */
	public static function apply_variation( string $slug ): void {
		$variation = wp_json_file_decode( get_theme_file_path( "styles/$slug.json" ), array( 'associative' => true ) );
		$styles    = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		// The site editor saves as a user with unfiltered_html; without it kses drops the settings of the variation.
		kses_remove_filters();
		wp_update_post(
			array(
				'ID'           => $styles['ID'],
				'post_content' => wp_slash(
					(string) wp_json_encode(
						array(
							'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
							'isGlobalStylesUserThemeJSON' => true,
						) + array_intersect_key( $variation, array_flip( array( 'settings', 'styles' ) ) )
					)
				),
			)
		);
		kses_init();
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
	}

	/**
	 * Sets in the trash are not activated.
	 */
	public function test_trashed_set_is_refused(): void {
		$id = self::set_with_accent( '#123456' );
		wp_trash_post( $id );
		$this->assertSame( 'leitvia_studio_token_set_status', Compiler::activate( $id )->get_error_code() );
	}

	/**
	 * Without a valid contract nothing is activated.
	 */
	public function test_no_contract(): void {
		$id = self::set_with_accent( '#123456' );
		add_filter( 'leitvia_studio_contract_path', static fn(): array => array( '/nonexistent/leitvia-contract.json' ) );
		\LeitviaStudio\Plugin::forget_contract();
		$this->assertSame( 'leitvia_studio_no_contract', Compiler::activate( $id )->get_error_code() );
	}

	/**
	 * A check with an error blocks activation; warnings do not.
	 */
	public function test_checks_block_activation(): void {
		$id                                  = self::set_with_accent( '#123456' );
		$doc                                 = json_decode( get_post_meta( $id, Token_Sets::META, true ), true );
		$doc['fontFamily']['mono']['$value'] = array( 'Comic Neue', 'monospace' );
		update_post_meta( $id, Token_Sets::META, wp_slash( wp_json_encode( $doc ) ) );
		$this->assertSame( 'font_not_bundled', Compiler::check( $doc )[0]['code'] );
		$this->assertTrue( Compiler::activate( $id ) );

		add_action(
			'leitvia_studio_init',
			static function ( $studio ): void {
				$studio->registry( 'checks' )->register(
					'leitvia/test-fail',
					array(
						'label'    => 'Fail',
						'scope'    => 'token_set',
						'callback' => static fn(): array => array(
							array(
								'code'     => 'test',
								'severity' => 'error',
								'message'  => 'Failed.',
							),
						),
					),
					'leitvia-studio'
				);
			},
			99
		);
		leitvia_studio()->reset_for_tests();
		$error = Compiler::activate( $id );
		$this->assertSame( 'leitvia_studio_token_set_invalid', $error->get_error_code() );
		$this->assertSame( 'test', $error->get_error_data()['findings'][0]['code'] );
	}

	/**
	 * The REST route activates for theme editors only.
	 */
	public function test_rest_activate(): void {
		$id       = self::set_with_accent( '#123456' );
		$response = rest_do_request( new WP_REST_Request( 'POST', "/leitvia-studio/v1/token-sets/$id/activate" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $response->get_data()['active'] );
		$this->assertArrayHasKey( 'palette', $response->get_data()['settings']['color'] );

		$doc = json_decode( get_post_meta( $id, Token_Sets::META, true ), true );
		unset( $doc['color']['focus'] );
		update_post_meta( $id, Token_Sets::META, wp_slash( wp_json_encode( $doc ) ) );
		$response = rest_do_request( new WP_REST_Request( 'POST', "/leitvia-studio/v1/token-sets/$id/activate" ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'leitvia_studio_dtcg_missing_role', $response->get_data()['data']['findings'][0]['code'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', "/leitvia-studio/v1/token-sets/$id/activate" ) )->get_status() );
	}

	/**
	 * Deleting the active set drops the active marker and the hash; other deletions leave them.
	 */
	public function test_deleting_the_active_set_forgets_it(): void {
		$id    = self::set_with_accent( '#123456' );
		$other = self::set_with_accent( '#123456' );
		$this->assertTrue( Compiler::activate( $id ) );
		wp_delete_post( $other, true );
		$this->assertSame( $id, (int) get_option( Compiler::ACTIVE_OPTION ) );
		wp_delete_post( $id, true );
		$this->assertFalse( get_option( Compiler::ACTIVE_OPTION ) );
		$this->assertFalse( get_option( Compiler::HASH_OPTION ) );
	}

	/**
	 * Edit links of token sets, as on the core revisions screen, lead to the Studio editor.
	 */
	public function test_edit_link(): void {
		$id = self::set_with_accent( '#123456' );
		$this->assertSame( admin_url( "admin.php?page=leitvia-studio-tokens&amp;set=$id" ), get_edit_post_link( $id ) );
		$this->assertSame( admin_url( "admin.php?page=leitvia-studio-tokens&set=$id" ), get_edit_post_link( $id, 'raw' ) );
		$post = self::factory()->post->create();
		$this->assertStringContainsString( 'post.php?post=' . $post, (string) get_edit_post_link( $post, 'raw' ) );
	}

	/**
	 * The check route reports errors, warnings and the compiled settings without activating.
	 */
	public function test_rest_check(): void {
		$id       = self::set_with_accent( '#2f5d50' );
		$response = rest_do_request( new WP_REST_Request( 'GET', "/leitvia-studio/v1/token-sets/$id/check" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['valid'] );
		$this->assertSame( array(), wp_list_filter( $response->get_data()['findings'], array( 'severity' => 'error' ) ) );
		$this->assertSame( '#2f5d50', array_values( wp_list_filter( $response->get_data()['settings']['color']['palette']['theme'], array( 'slug' => 'accent' ) ) )[0]['color'] );
		$this->assertFalse( get_option( Compiler::ACTIVE_OPTION ) );

		$id   = self::set_with_accent( '#9ab8ae' );
		$data = rest_do_request( new WP_REST_Request( 'GET', "/leitvia-studio/v1/token-sets/$id/check" ) )->get_data();
		$this->assertFalse( $data['valid'] );
		$this->assertSame( 'contrast', $data['findings'][0]['code'] );
		$this->assertSame( array( 'accent', 'surface' ), array( $data['findings'][0]['data']['fg'], $data['findings'][0]['data']['bg'] ) );

		$doc = json_decode( get_post_meta( $id, Token_Sets::META, true ), true );
		unset( $doc['color']['focus'] );
		update_post_meta( $id, Token_Sets::META, wp_slash( wp_json_encode( $doc ) ) );
		$data = rest_do_request( new WP_REST_Request( 'GET', "/leitvia-studio/v1/token-sets/$id/check" ) )->get_data();
		$this->assertFalse( $data['valid'] );
		$this->assertNull( $data['settings'] );
		$this->assertSame( 'leitvia_studio_dtcg_missing_role', $data['findings'][0]['code'] );

		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/token-sets/999999/check' ) )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', "/leitvia-studio/v1/token-sets/$id/check" ) )->get_status() );
	}

	/**
	 * Creates a set from the theme with a different accent color.
	 *
	 * @param string $hex Accent color.
	 */
	private static function set_with_accent( string $hex ): int {
		$id                               = Token_Sets::create_from_active_theme( 'Test' );
		$doc                              = json_decode( get_post_meta( $id, Token_Sets::META, true ), true );
		$doc['color']['accent']['$value'] = array(
			'colorSpace' => 'srgb',
			'components' => array( 0, 0, 0 ),
			'hex'        => $hex,
		);
		update_post_meta( $id, Token_Sets::META, wp_slash( wp_json_encode( $doc ) ) );
		return $id;
	}
}
