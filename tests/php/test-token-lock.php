<?php
/**
 * Contrast lock for token sets and Site Editor global styles.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Dtcg;
use LeitviaStudio\Tokens\Token_Sets;

/**
 * Invalid sets cannot be saved over REST or activated; the Site Editor guard rejects failing palettes.
 */
class Test_Token_Lock extends WP_UnitTestCase {

	/**
	 * Leitvia theme, administrator.
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
	 * Drops filters, cached contract and theme.json data.
	 */
	public function tear_down(): void {
		remove_all_filters( 'leitvia_studio_contract_path' );
		delete_option( 'leitvia_studio_settings' );
		\LeitviaStudio\Plugin::forget_contract();
		WP_Theme_JSON_Resolver::clean_cached_data();
		parent::tear_down();
	}

	/**
	 * Creating and updating over REST: valid passes, a failing accent is rejected with its pairs.
	 */
	public function test_rest_rejects_invalid_sets(): void {
		$response = self::save( '/wp/v2/leitvia-tokens', self::doc() );
		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];

		$response = self::save( '/wp/v2/leitvia-tokens', self::doc( '#9ab8ae' ) );
		$this->assertSame( 422, $response->get_status() );
		$finding = $response->get_data()['data']['findings'][0];
		$this->assertSame( 'contrast', $finding['code'] );
		$this->assertSame( 'accent', $finding['data']['fg'] );
		$this->assertSame( 'surface', $finding['data']['bg'] );
		$this->assertStringContainsString( 'accent on surface', $finding['message'] );

		$before   = get_post_meta( $id, Token_Sets::META, true );
		$response = self::save( "/wp/v2/leitvia-tokens/$id", self::doc( '#9ab8ae' ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( $before, get_post_meta( $id, Token_Sets::META, true ) );
	}

	/**
	 * A set stored past REST stays inactivatable.
	 */
	public function test_activate_rejects_invalid_set(): void {
		$id    = self::factory()->post->create(
			array(
				'post_type'  => Token_Sets::POST_TYPE,
				'meta_input' => array( Token_Sets::META => wp_slash( (string) wp_json_encode( self::doc( '#9ab8ae' ) ) ) ),
			)
		);
		$error = Compiler::activate( $id );
		$this->assertWPError( $error );
		$this->assertSame( 'leitvia_studio_token_set_invalid', $error->get_error_code() );
		$this->assertFalse( get_option( Compiler::ACTIVE_OPTION ) );
	}

	/**
	 * The guard rejects a failing palette, unless switched off or the theme has no contract.
	 */
	public function test_site_editor_guard(): void {
		$response = self::save_styles( '#9ab8ae' );
		$this->assertSame( 422, $response->get_status() );
		$this->assertStringContainsString( 'accent on surface', $response->get_data()['message'] );
		$this->assertSame( 200, self::save_styles( '#123456' )->get_status() );

		update_option( 'leitvia_studio_settings', array( 'guard_site_editor_contrast' => false ) );
		$this->assertSame( 200, self::save_styles( '#9ab8ae' )->get_status() );

		delete_option( 'leitvia_studio_settings' );
		add_filter( 'leitvia_studio_contract_path', static fn(): array => array( '/nonexistent/leitvia-contract.json' ) );
		\LeitviaStudio\Plugin::forget_contract();
		$this->assertSame( 200, self::save_styles( '#9ab8ae' )->get_status() );
	}

	/**
	 * Colors in rgb(), hsl() and preset references are checked; unreadable colors pass with a warning.
	 */
	public function test_site_editor_guard_color_formats(): void {
		$this->assertSame( 422, self::save_styles( 'rgb(154 184 174)' )->get_status() );
		$this->assertSame( 422, self::save_styles( 'var(--wp--preset--color--surface-alt)' )->get_status() );
		$this->assertSame( 200, self::save_styles( 'hsl(163 33% 27.5%)' )->get_status() );

		$response = self::save_styles( 'currentColor' );
		$this->assertSame( 200, $response->get_status() );
		$warning = $response->get_data()['leitvia_studio_warnings'][0];
		$this->assertSame( 'contrast_unchecked', $warning['code'] );
		$this->assertStringContainsString( 'unsupported color format', $warning['message'] );
		$this->assertArrayNotHasKey( 'leitvia_studio_warnings', self::save_styles( '#123456' )->get_data() );
	}

	/**
	 * Users who may not edit global styles get the answer of core, not a contrast error.
	 */
	public function test_site_editor_guard_leaves_permissions_to_core(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, self::save_styles( '#9ab8ae' )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, self::save_styles( '#9ab8ae' )->get_status() );
	}

	/**
	 * Token document of the active theme, optionally with another accent.
	 *
	 * @param string|null $accent Accent hex.
	 * @return array<string, mixed>
	 */
	private static function doc( ?string $accent = null ): array {
		$doc = Dtcg::from_settings( wp_get_global_settings(), leitvia_studio_contract() );
		if ( $accent ) {
			$doc['color']['accent']['$value'] = array(
				'colorSpace' => 'srgb',
				'components' => array( 0, 0, 0 ),
				'hex'        => $accent,
			);
		}
		return $doc;
	}

	/**
	 * Saves a token set over REST.
	 *
	 * @param string               $route Route.
	 * @param array<string, mixed> $doc   Token document.
	 */
	private static function save( string $route, array $doc ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params(
			array(
				'title'  => 'Lock',
				'status' => 'private',
				'meta'   => array( Token_Sets::META => wp_json_encode( $doc ) ),
			)
		);
		return rest_do_request( $request );
	}

	/**
	 * Saves user global styles with one accent color over REST, as the Site Editor does.
	 *
	 * @param string $accent Accent hex.
	 */
	private static function save_styles( string $accent ): WP_REST_Response {
		$id      = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$request = new WP_REST_Request( 'POST', "/wp/v2/global-styles/$id" );
		$request->set_body_params(
			array(
				'settings' => array(
					'color' => array(
						'palette' => array(
							'theme' => array(
								array(
									'slug'  => 'accent',
									'name'  => 'Accent',
									'color' => $accent,
								),
							),
						),
					),
				),
			)
		);
		return rest_do_request( $request );
	}
}
