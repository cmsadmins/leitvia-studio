<?php
/**
 * Navigation of the theme: the page of a submenu parent stays reachable where submenus open on click, and soft hyphens
 * stay out of document titles, feeds and breadcrumbs.
 *
 * @package LeitviaStudio
 */

/**
 * Filters submenu_parent_link, page_list_parent_links, without_soft_hyphens and plain_breadcrumbs of functions.php.
 */
class Test_Theme_Navigation extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme and makes sure its filters are attached.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		if ( ! function_exists( 'Leitvia\submenu_parent_link' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
		// functions.php may have been loaded by an earlier test class, whose hooks the test case has reset since.
		if ( ! has_filter( 'render_block_core/navigation-submenu', 'Leitvia\submenu_parent_link' ) ) {
			add_filter( 'render_block_core/navigation-submenu', 'Leitvia\submenu_parent_link', 10, 2 );
			add_filter( 'render_block_core/page-list', 'Leitvia\page_list_items_in_navigation', 10, 3 );
			add_filter( 'render_block_core/page-list', 'Leitvia\page_list_parent_links' );
			add_filter( 'document_title', 'Leitvia\without_soft_hyphens' );
			add_filter( 'the_title_rss', 'Leitvia\without_soft_hyphens' );
			add_filter( 'block_core_breadcrumbs_items', 'Leitvia\plain_breadcrumbs' );
		}
	}

	/**
	 * A submenu that opens on click lists the page of its parent first, once, inside the submenu; a parent without an
	 * address and a submenu that opens on hover (where the parent is a link already) gain nothing.
	 */
	public function test_submenu_on_click_links_its_parent(): void {
		$submenu = static fn( string $url ): string => '<!-- wp:navigation-submenu {"label":"Services","url":"' . $url . '","kind":"custom"} --><!-- wp:navigation-link {"label":"Repairs","url":"/services/repairs/","kind":"custom"} /--><!-- /wp:navigation-submenu -->';
		$click   = do_blocks( '<!-- wp:navigation {"submenuVisibility":"click","overlayMenu":"never"} -->' . $submenu( '/services/' ) . '<!-- /wp:navigation -->' );
		$this->assertSame( 1, substr_count( $click, 'href="/services/"' ) );
		$this->assertMatchesRegularExpression( '#<ul[^>]*wp-block-navigation__submenu-container[^>]*><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="/services/"><span class="wp-block-navigation-item__label">Services</span></a></li>#', $click );
		$this->assertLessThan( strpos( $click, 'href="/services/"' ), strpos( $click, 'wp-block-navigation-submenu__toggle' ), 'the button comes first' );

		$this->assertStringNotContainsString( 'wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="#', do_blocks( '<!-- wp:navigation {"submenuVisibility":"click","overlayMenu":"never"} -->' . $submenu( '#' ) . '<!-- /wp:navigation -->' ) );
		$hover = do_blocks( '<!-- wp:navigation {"submenuVisibility":"hover","overlayMenu":"never"} -->' . $submenu( '/services/' ) . '<!-- /wp:navigation -->' );
		$this->assertSame( 1, substr_count( $hover, 'href="/services/"' ) );
	}

	/**
	 * The same for the page list a navigation without a menu falls back to: a page with child pages is the first entry
	 * of its own submenu.
	 */
	public function test_page_list_on_click_links_its_parent(): void {
		$parent = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Services',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Repairs',
				'post_parent' => $parent,
			)
		);
		$navigation = do_blocks( '<!-- wp:navigation {"submenuVisibility":"click","overlayMenu":"never"} --><!-- wp:page-list /--><!-- /wp:navigation -->' );
		$this->assertMatchesRegularExpression( '#<ul[^>]*wp-block-navigation__submenu-container[^>]*><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="' . preg_quote( esc_url( get_permalink( $parent ) ), '#' ) . '">#', $navigation );
		$this->assertSame( 1, substr_count( $navigation, 'href="' . esc_url( get_permalink( $parent ) ) . '"' ) );
	}

	/**
	 * Soft hyphens of a title stay out of the document title, the feed and the breadcrumbs.
	 */
	public function test_soft_hyphens_stay_on_the_page(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Bar&shy;riere' . "\u{AD}" . 'frei&#173;heit',
			)
		);
		$this->go_to( get_permalink( $page ) );
		$this->assertStringStartsWith( 'Barrierefreiheit', wp_get_document_title() );
		$this->assertSame( 'Barrierefreiheit', get_the_title_rss( $page ) );
		$this->assertSame( array( array( 'label' => 'Barrierefreiheit' ) ), apply_filters( 'block_core_breadcrumbs_items', array( array( 'label' => get_the_title( $page ) ) ) ) );
	}
}
