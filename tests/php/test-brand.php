<?php
/**
 * The filter leitvia_studio_brand and the places that show the brand.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Admin\Admin;
use LeitviaStudio\Avatars\Avatars;
use LeitviaStudio\Blocks\Blocks;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Store;

/**
 * Default brand, cleaning of filtered values, and menu, product line, inline data, categories, rejections, role and
 * privacy exports under another brand.
 */
class Test_Brand extends WP_UnitTestCase {

	/**
	 * Another brand, as an add-on would set it.
	 *
	 * @param array<string, string> $brand Brand of Studio.
	 * @return array<string, string>
	 */
	public static function acme( array $brand ): array {
		return array(
			'name'        => 'Acme Sites',
			'logo_url'    => 'https://example.org/wp-content/uploads/acme.png',
			'support_url' => 'https://acme.example/help/',
		) + $brand;
	}

	/**
	 * Administrator and a fresh menu.
	 */
	public function set_up(): void {
		parent::set_up();
		global $menu, $submenu;
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu per test.
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu per test.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Removes the brand and resets screen and roles.
	 */
	public function tear_down(): void {
		remove_filter( 'leitvia_studio_brand', array( self::class, 'acme' ) );
		set_current_screen( 'front' );
		Gateway::role_name();
		wp_dequeue_script( 'leitvia-studio-admin' );
		wp_deregister_script( 'leitvia-studio-admin' );
		wp_dequeue_style( 'leitvia-studio-admin' );
		wp_deregister_style( 'leitvia-studio-admin' );
		parent::tear_down();
	}

	/**
	 * Without the filter Studio is Leitvia Studio, the menu title follows the name.
	 */
	public function test_default_brand(): void {
		$this->assertSame(
			array(
				'name'        => 'Leitvia Studio',
				'menu_title'  => 'Leitvia Studio',
				'logo_url'    => '',
				'support_url' => '',
			),
			leitvia_studio_brand()
		);
		$this->assertSame( 'Leitvia', leitvia_studio_brand_short() );
	}

	/**
	 * Filtered values are cleaned: unknown keys and non-strings dropped, empty name falls back, unsafe URLs emptied.
	 */
	public function test_filtered_values_are_cleaned(): void {
		$filter = static fn(): array => array(
			'name'        => '',
			'menu_title'  => array( 'x' ),
			'logo_url'    => 'javascript:alert(1)',
			'support_url' => 'https://acme.example/help/',
			'extra'       => 'dropped',
		);
		add_filter( 'leitvia_studio_brand', $filter );
		$brand  = leitvia_studio_brand();
		remove_filter( 'leitvia_studio_brand', $filter );
		$this->assertSame( array( 'name', 'menu_title', 'logo_url', 'support_url' ), array_keys( $brand ) );
		$this->assertSame( 'Leitvia Studio', $brand['name'] );
		$this->assertSame( 'Leitvia Studio', $brand['menu_title'] );
		$this->assertSame( '', $brand['logo_url'] );
		$this->assertSame( 'https://acme.example/help/', $brand['support_url'] );
	}

	/**
	 * Menu, page title and the product line of the screens carry the brand; slugs stay.
	 */
	public function test_menu_and_product_line(): void {
		add_filter( 'leitvia_studio_brand', array( self::class, 'acme' ) );
		Admin::register_menu();
		global $menu;
		$this->assertSame( 'Acme Sites', $menu[61][0] );
		$this->assertSame( 'leitvia-studio', $menu[61][2] );
		$this->assertSame( 'Acme Sites', $menu[61][3] );
		$this->assertSame( 'dashicons-art', $menu[61][6] );

		set_current_screen( 'toplevel_page_leitvia-studio' );
		ob_start();
		Admin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<img src="https://example.org/wp-content/uploads/acme.png" alt="" class="leitvia-studio-brand__logo">', $html );
		$this->assertStringContainsString( '<span class="leitvia-studio-brand__name">Acme Sites</span> <a class="leitvia-studio-brand__by" href="https://acme.example/help/">Support</a>', $html );
		$this->assertStringNotContainsString( 'Leitvia', wp_strip_all_tags( $html ) );
		$this->assertStringNotContainsString( 'cms-admins.de', $html );
	}

	/**
	 * The admin app gets the brand name and the agent role through the inline data.
	 */
	public function test_inline_data_carries_brand(): void {
		add_filter( 'leitvia_studio_brand', array( self::class, 'acme' ) );
		Gateway::role_name();
		\LeitviaStudio\Plugin::register_api_script();
		Admin::register_menu();
		set_current_screen( 'toplevel_page_leitvia-studio' );
		Admin::enqueue();
		$inline = implode( '', (array) wp_scripts()->get_data( 'leitvia-studio-admin', 'before' ) );
		if ( '' === $inline ) {
			$this->assertFileDoesNotExist( dirname( LEITVIA_STUDIO_FILE ) . '/build/admin.asset.php' );
			return;
		}
		$this->assertStringContainsString( '"brand":"Acme Sites"', $inline );
		$this->assertStringContainsString( '"agentRole":"Acme Sites Agent"', $inline );
	}

	/**
	 * Block category, rejection of agents, agent role and privacy exports name the brand.
	 */
	public function test_agent_and_privacy_texts(): void {
		add_filter( 'leitvia_studio_brand', array( self::class, 'acme' ) );
		$this->assertSame( 'Acme Sites', array_column( Blocks::category( array() ), 'title', 'slug' )['leitvia'] );

		Gateway::role_name();
		$this->assertSame( 'Acme Sites Agent', wp_roles()->role_names[ Gateway::ROLE ] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$error = Gateway::rest_guard( null, rest_get_server(), new WP_REST_Request( 'POST', '/wp/v2/posts' ) );
		$this->assertWPError( $error );
		$this->assertSame( 'Agents change the site through the Acme Sites abilities only.', $error->get_error_message() );

		$this->assertSame( 'Acme Sites profile picture', Avatars::register_exporter( array() )['leitvia-studio-avatar']['exporter_friendly_name'] );
		$this->assertSame( 'Acme Sites Gateway audit log', Store::register_exporter( array() )['leitvia-studio-gateway']['exporter_friendly_name'] );
	}

	/**
	 * Without the filter the texts stay as before.
	 */
	public function test_default_texts_unchanged(): void {
		$this->assertSame( 'Leitvia', array_column( Blocks::category( array() ), 'title', 'slug' )['leitvia'] );
		$this->assertSame( 'Leitvia Agent', wp_roles()->role_names[ Gateway::ROLE ] );
		$this->assertSame( 'Leitvia Gateway audit log', Store::register_exporter( array() )['leitvia-studio-gateway']['exporter_friendly_name'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$error = Gateway::rest_guard( null, rest_get_server(), new WP_REST_Request( 'POST', '/wp/v2/posts' ) );
		$this->assertSame( 'Agents change the site through the Leitvia abilities only.', $error->get_error_message() );
	}
}
