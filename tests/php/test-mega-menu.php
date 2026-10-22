<?php
/**
 * Mega menu block: template part area and server markup.
 *
 * @package LeitviaStudio
 */

/**
 * The item is a link; with its template part it gets a hidden disclosure button and a hidden panel.
 */
class Test_Mega_Menu extends WP_UnitTestCase {

	/**
	 * Block markup with the given attributes.
	 *
	 * @param array<string, string> $attrs Block attributes.
	 */
	private static function block( array $attrs ): string {
		return get_comment_delimited_block_content( 'leitvia/mega-menu', $attrs, '' );
	}

	/**
	 * Creates the template part mega-test of the active theme in the area mega-menu.
	 */
	private function part(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => 'mega-test',
				'post_title'   => 'Mega test',
				'post_content' => '<!-- wp:paragraph --><p>Panel content</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_post_terms( $id, get_stylesheet(), 'wp_theme' );
		wp_set_post_terms( $id, 'mega-menu', 'wp_template_part_area' );
	}

	/**
	 * The area mega-menu is available for template parts.
	 */
	public function test_area_is_registered(): void {
		$this->assertContains( 'mega-menu', array_column( get_allowed_block_template_part_areas(), 'area' ) );
		$this->assertSame( 'mega-menu', _filter_block_template_part_area( 'mega-menu' ) );
	}

	/**
	 * Link, button with aria-expanded and aria-controls, panel with the template part; button and panel hidden.
	 */
	public function test_renders_link_button_and_panel(): void {
		$this->part();
		$html = do_blocks(
			self::block(
				array(
					'label' => 'Products',
					'url'   => '/products/',
					'slug'  => 'mega-test',
				)
			)
		);
		$this->assertMatchesRegularExpression( '#^<li class="wp-block-navigation-item wp-block-leitvia-mega-menu"><a class="wp-block-navigation-item__content" href="/products/"><span class="wp-block-navigation-item__label">Products</span></a>#', $html );
		$this->assertMatchesRegularExpression( '#<button type="button" class="wp-block-leitvia-mega-menu__toggle" aria-expanded="false" aria-controls="(leitvia-mega-menu-\d+)" aria-label="Products submenu" hidden>.*</button><div id="\1" class="wp-block-leitvia-mega-menu__panel" hidden>#s', $html );
		$this->assertStringContainsString( 'Panel content</p>', $html );
		$this->assertStringEndsWith( '</div></li>', $html );
	}

	/**
	 * Without the template part only the link remains, without a notice.
	 */
	public function test_missing_part_leaves_the_link(): void {
		$html = do_blocks(
			self::block(
				array(
					'label' => 'Products',
					'url'   => '/products/',
					'slug'  => 'gone',
				)
			)
		);
		$this->assertSame( '<li class="wp-block-navigation-item wp-block-leitvia-mega-menu"><a class="wp-block-navigation-item__content" href="/products/"><span class="wp-block-navigation-item__label">Products</span></a></li>', $html );
	}

	/**
	 * Label, description and URL are escaped.
	 */
	public function test_output_is_escaped(): void {
		$html = do_blocks(
			self::block(
				array(
					'label'       => '<script>x()</script>A & B',
					'url'         => 'javascript:alert(1)',
					'description' => '"><img src=x onerror=y>',
				)
			)
		);
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( '&lt;script&gt;x()&lt;/script&gt;A &amp; B', $html );
	}

	/**
	 * Navigation markup with inline submenus.
	 *
	 * @param array<int, int>      $groups Links per submenu.
	 * @param bool                 $nested Whether the last submenu sits inside the first.
	 * @param array<string, mixed> $attrs  Navigation attributes.
	 */
	private static function navigation( array $groups, bool $nested = false, array $attrs = array() ): string {
		$subs = array();
		foreach ( $groups as $i => $links ) {
			$items  = str_repeat( '<!-- wp:navigation-link {"label":"Link","url":"/link/"} /-->', $links );
			$subs[] = get_comment_delimited_block_content( 'core/navigation-submenu', array( 'label' => "Group $i" ), $items );
		}
		if ( $nested && count( $subs ) > 1 ) {
			$last    = array_pop( $subs );
			$subs[0] = str_replace( '<!-- /wp:navigation-submenu -->', $last . '<!-- /wp:navigation-submenu -->', $subs[0] );
		}
		return get_comment_delimited_block_content( 'core/navigation', $attrs, implode( '', $subs ) );
	}

	/**
	 * Nested submenus or more than 12 submenu links mark the navigation; the seed's flat menu with 10 links stays as
	 * core renders it.
	 */
	public function test_long_or_nested_navigation_is_collapsible(): void {
		$marked = static fn( string $markup ): bool => str_contains( do_blocks( $markup ), 'data-leitvia-collapsible' );
		$this->assertFalse( $marked( self::navigation( array( 3, 7 ) ) ) );
		$this->assertTrue( $marked( self::navigation( array( 6, 7 ) ) ) );
		$this->assertTrue( $marked( self::navigation( array( 2, 2 ), true ) ) );
		$this->assertFalse( $marked( self::navigation( array( 2, 2 ), true, array( 'overlayMenu' => 'never' ) ) ) );
	}

	/**
	 * The filter forces the behaviour on or off.
	 */
	public function test_filter_decides(): void {
		add_filter( 'leitvia_studio_collapsible_overlay', '__return_true' );
		$this->assertStringContainsString( 'data-leitvia-collapsible', do_blocks( self::navigation( array( 1 ) ) ) );
		remove_filter( 'leitvia_studio_collapsible_overlay', '__return_true' );
		add_filter( 'leitvia_studio_collapsible_overlay', '__return_false' );
		$this->assertStringNotContainsString( 'data-leitvia-collapsible', do_blocks( self::navigation( array( 2, 2 ), true ) ) );
		remove_filter( 'leitvia_studio_collapsible_overlay', '__return_false' );
	}
}
