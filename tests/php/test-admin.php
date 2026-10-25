<?php
/**
 * Admin menu and screens.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Admin\Admin;

/**
 * Menu, frame, scripts only on Studio screens, inline data and Lab panels per capability.
 */
class Test_Admin extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private static int $admin;

	/**
	 * Creates the users.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WordPress test API.
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Registers the menu as an administrator.
	 */
	public function set_up(): void {
		parent::set_up();
		global $menu, $submenu;
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu per test.
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu per test.
		wp_set_current_user( self::$admin );
		Admin::register_menu();
	}

	/**
	 * Resets the screen and the scripts.
	 */
	public function tear_down(): void {
		set_current_screen( 'front' );
		wp_dequeue_script( 'leitvia-studio-admin' );
		wp_deregister_script( 'leitvia-studio-admin' );
		wp_dequeue_style( 'leitvia-studio-admin' );
		wp_deregister_style( 'leitvia-studio-admin' );
		leitvia_studio()->reset_for_tests();
		parent::tear_down();
	}

	/**
	 * The top-level menu sits after Appearance, the Lab is its first page.
	 */
	public function test_menu(): void {
		global $menu, $submenu;
		$this->assertSame( 'leitvia-studio', $menu[61][2] );
		$this->assertSame( 'edit_theme_options', $menu[61][1] );
		$this->assertSame( Admin::menu_icon(), $menu[61][6] );
		$this->assertSame( array( 'Lab', 'edit_theme_options', 'leitvia-studio' ), array_slice( $submenu['leitvia-studio'][0], 0, 3 ) );
		$this->assertSame( array( 'Tokens', 'edit_theme_options', 'leitvia-studio-tokens' ), array_slice( $submenu['leitvia-studio'][1], 0, 3 ) );
		$this->assertSame( array( 'Recipes', 'edit_theme_options', 'leitvia-studio-recipes' ), array_slice( $submenu['leitvia-studio'][3], 0, 3 ) );
	}

	/**
	 * Every screen prints only the frame with the upsell slot and the app root.
	 */
	public function test_render_prints_the_frame(): void {
		set_current_screen( 'toplevel_page_leitvia-studio' );
		$slot = '';
		add_action(
			'leitvia_studio_upsell_slot',
			static function ( string $screen_id ) use ( &$slot ): void {
				$slot = $screen_id;
			}
		);
		ob_start();
		Admin::render();
		$html = (string) ob_get_clean();

		$this->assertSame( 'leitvia-studio', $slot );
		$this->assertStringContainsString( '<div id="leitvia-studio-root" data-screen="leitvia-studio"></div>', $html );
		$this->assertStringContainsString( '<span class="leitvia-studio-brand__name">Leitvia Studio</span> <a class="leitvia-studio-brand__by" href="https://www.cms-admins.de/">by CMS ADMINS</a>', $html );
		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', Admin::menu_icon() );
		$this->assertMatchesRegularExpression( '#<svg [^>]*aria-hidden="true"#', $html );
	}

	/**
	 * The app loads on Studio screens only.
	 */
	public function test_scripts_only_on_studio_screens(): void {
		\LeitviaStudio\Plugin::register_api_script();
		set_current_screen( 'dashboard' );
		Admin::enqueue();
		$this->assertFalse( wp_script_is( 'leitvia-studio-admin' ) );
		$this->assertFalse( wp_style_is( 'leitvia-studio-admin' ) );

		set_current_screen( 'toplevel_page_leitvia-studio' );
		Admin::enqueue();
		$this->assertTrue( wp_script_is( 'leitvia-studio-admin' ) );
		$this->assertTrue( wp_style_is( 'leitvia-studio-admin' ) );
		$this->assertContains( 'leitvia-studio-api', wp_scripts()->registered['leitvia-studio-admin']->deps );
		$this->assertContains( 'wp-components', wp_styles()->registered['leitvia-studio-admin']->deps );
		$faces = implode( '', wp_styles()->get_data( 'leitvia-studio-admin', 'after' ) );
		$this->assertStringContainsString( 'assets/fonts/open-sans/open-sans-latin-600.woff2', $faces );
		$this->assertStringContainsString( 'assets/fonts/open-sans/open-sans-latin-700.woff2', $faces );

		$data = $this->inline_data();
		$this->assertSame( 'leitvia-studio', $data['screen'] );
		$this->assertStringContainsString( 'leitvia_studio_preview=1', $data['previewUrl'] );
		$this->assertStringContainsString( '_wpnonce=' . wp_create_nonce( 'leitvia_studio_preview' ), $data['previewUrl'] );
	}

	/**
	 * Lab panels reach the app only for users with their capability, sorted by order; variations come from the contract.
	 */
	public function test_inline_data_filters_lab_panels_by_capability(): void {
		switch_theme( 'leitvia' );
		add_action(
			'leitvia_studio_init',
			static function ( \LeitviaStudio\Extension\Studio $studio ): void {
				$studio->extensions()->register(
					'test-addon',
					array(
						'label'           => 'Test add-on',
						'version'         => '1.0.0',
						'requires_studio' => '0.1.0',
						'requires_api'    => 1,
					)
				);
				foreach ( array( array( 'first', 'edit_theme_options' ), array( 'network', 'manage_network_options' ) ) as list( $name, $capability ) ) {
					$studio->registry( 'lab_panels' )->register(
						"test-addon/{$name}",
						array(
							'label'      => $name,
							'script'     => 'test-addon-panel',
							'order'      => 1,
							'capability' => $capability,
						),
						'test-addon'
					);
				}
			}
		);
		leitvia_studio()->reset_for_tests();
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $user )->add_cap( 'edit_theme_options' );
		wp_set_current_user( $user );

		set_current_screen( 'toplevel_page_leitvia-studio' );
		Admin::enqueue();
		$data = $this->inline_data();

		$this->assertSame( array( 'test-addon/first', 'leitvia/variants', 'leitvia/states', 'leitvia/checks', 'leitvia/annotations', 'leitvia/usage' ), array_column( $data['labPanels'], 'name' ) );
		$this->assertContains(
			array(
				'slug'  => 'warm-editorial-dark',
				'title' => 'Warm Editorial Dark',
			),
			$data['variations']
		);
	}

	/**
	 * Data the app reads from window.leitviaStudioAdmin.
	 *
	 * @return array<string, mixed>
	 */
	private function inline_data(): array {
		$before = (array) wp_scripts()->get_data( 'leitvia-studio-admin', 'before' );
		$this->assertCount( 1, array_filter( $before ) );
		$this->assertSame( 1, preg_match( '/^window\.leitviaStudioAdmin = (.*);$/s', (string) end( $before ), $match ) );
		return json_decode( $match[1], true );
	}
}
