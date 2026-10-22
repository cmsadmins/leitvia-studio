<?php
/**
 * Easy language: meta, language switch, accessibility bar.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Easy_Language\Easy_Language;

/**
 * One meta on the easy language page links both pages; only published counterparts are linked.
 */
class Test_Easy_Language extends WP_UnitTestCase {

	/**
	 * The test case drops every meta key after each test, so the meta is registered again as on init. The high
	 * contrast colors come from the palettes of the Leitvia theme.
	 */
	public function set_up(): void {
		parent::set_up();
		Easy_Language::register();
		switch_theme( 'leitvia' );
	}

	/**
	 * Creates an original page and its easy language version.
	 *
	 * @param string $status Status of the easy language page.
	 * @return array{0: int, 1: int} Original and easy language page.
	 */
	private function pair( string $status = 'publish' ): array {
		$original = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Accessibility',
			)
		);
		$easy     = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Accessibility in easy language',
				'post_status' => $status,
			)
		);
		update_post_meta( $easy, Easy_Language::META, $original );
		return array( $original, $easy );
	}

	/**
	 * Renders the language switch as part of a page, like in the content of the page template.
	 *
	 * @param int $post_id Page ID.
	 */
	private function switch_on( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		return ( new WP_Block( parse_blocks( '<!-- wp:leitvia/language-switch /-->' )[0], array( 'postId' => $post_id ) ) )->render();
	}

	/**
	 * (1) The meta is registered for pages, in REST, as integer, and only users who may edit the page may change it.
	 */
	public function test_meta_is_registered(): void {
		$meta = get_registered_meta_keys( 'post', 'page' )[ Easy_Language::META ] ?? null;
		$this->assertIsArray( $meta );
		$this->assertSame( 'integer', $meta['type'] );
		$this->assertTrue( $meta['single'] );
		$this->assertNotEmpty( $meta['show_in_rest'] );
		$this->assertArrayNotHasKey( Easy_Language::META, get_registered_meta_keys( 'post', 'post' ) );

		list( $original, $easy ) = $this->pair();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( current_user_can( 'edit_post_meta', $easy, Easy_Language::META ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $easy, Easy_Language::META ) );
		$this->assertSame( (string) $original, get_post_meta( $easy, Easy_Language::META, true ) );
	}

	/**
	 * (2) The original links its easy language version, the version links the original, both from the one meta.
	 */
	public function test_switch_links_both_ways(): void {
		list( $original, $easy ) = $this->pair();
		$html                    = $this->switch_on( $original );
		$this->assertMatchesRegularExpression( '#^<a class="wp-block-leitvia-language-switch" href="' . preg_quote( get_permalink( $easy ), '#' ) . '"><svg [^>]*aria-hidden="true"[^>]*>.*</svg><span>Easy language</span></a>$#s', $html );
		$html = $this->switch_on( $easy );
		$this->assertStringContainsString( 'href="' . get_permalink( $original ) . '"', $html );
		$this->assertStringContainsString( '<span>Standard language</span>', $html );
		$this->assertFalse( metadata_exists( 'post', $original, Easy_Language::META ), 'one meta, on the easy language page only' );
	}

	/**
	 * (3) Without a counterpart the switch renders nothing, also outside of pages.
	 */
	public function test_nothing_without_counterpart(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( '', $this->switch_on( $page ) );
		$post = self::factory()->post->create();
		$this->assertSame( '', $this->switch_on( $post ) );
		update_post_meta( $page, Easy_Language::META, $page );
		$this->assertSame( '', $this->switch_on( $page ), 'a page is never its own counterpart' );
	}

	/**
	 * (4) A draft, private or password protected counterpart is not linked, in either direction.
	 */
	public function test_unpublished_counterpart_is_not_linked(): void {
		foreach ( array( 'draft', 'private' ) as $status ) {
			list( $original ) = $this->pair( $status );
			$this->assertSame( '', $this->switch_on( $original ), $status );
		}
		list( $original, $easy ) = $this->pair();
		wp_update_post(
			array(
				'ID'          => $original,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( '', $this->switch_on( $easy ) );
		list( $original, $easy ) = $this->pair();
		wp_update_post(
			array(
				'ID'            => $easy,
				'post_password' => 'secret',
			)
		);
		$this->assertSame( '', $this->switch_on( $original ) );
	}

	/**
	 * (5) Easy language is no language of its own: no rel="alternate" link in the head of either page.
	 */
	public function test_no_alternate_link(): void {
		list( $original, $easy ) = $this->pair();
		foreach ( array( $original, $easy ) as $id ) {
			$this->go_to( get_permalink( $id ) );
			$head = get_echo( 'wp_head' );
			$this->assertDoesNotMatchRegularExpression( '#<link[^>]+rel=["\']alternate["\'][^>]+hreflang#', $head );
			$this->assertStringNotContainsString( get_permalink( $original === $id ? $easy : $original ), $head );
		}
	}

	/**
	 * The bar gets the hidden controls and the inline script that applies stored settings before the first paint;
	 * the theme's palettes give the high contrast colors.
	 */
	public function test_bar_adds_hidden_controls_and_script(): void {
		$html = do_blocks( '<!-- wp:leitvia/a11y-bar --><nav class="wp-block-leitvia-a11y-bar" aria-label="Accessibility"><a class="wp-block-leitvia-a11y-bar__statement" href="/accessibility/">Accessibility statement</a></nav><!-- /wp:leitvia/a11y-bar -->' );
		$this->assertMatchesRegularExpression( '#^<script>\s*try\{var s=JSON.parse\(localStorage.getItem\("leitvia-a11y"\)\).*?</script>\s*<nav #s', $html );
		$this->assertStringContainsString( '<a class="wp-block-leitvia-a11y-bar__statement" href="/accessibility/">Accessibility statement</a><div class="wp-block-leitvia-a11y-bar__controls" hidden>', $html );
		$this->assertStringContainsString( '<button type="button" data-action="contrast" aria-pressed="false">High contrast</button>', $html );
		$this->assertMatchesRegularExpression( '#<button type="button" data-action="read" hidden data-stop="Stop reading">Read aloud</button></div></nav>$#', $html );
		preg_match( '#<script[^>]*>(.*?)</script>#s', $html, $m );
		$this->assertLessThan( 300, strlen( $m[1] ) );
		$this->assertStringNotContainsString( '&', $m[1], 'the content filters of a post would turn & into an entity' );
		$this->assertStringNotContainsString( '<', $m[1] );
	}

	/**
	 * High contrast maps every color preset to ink or surface of the variation with the highest contrast between them.
	 */
	public function test_contrast_css_uses_the_highest_ink_surface_pair(): void {
		$css = Easy_Language::contrast_css();
		$this->assertMatchesRegularExpression( '#^html\.leitvia-high-contrast\{--wp--preset--color--surface:(\#[0-9a-f]{3,6});.*--wp--preset--color--ink:(\#[0-9a-f]{3,6});#i', $css );
		preg_match( '#--surface:([^;]+);.*--ink:([^;}]+)#', $css, $m );
		$best = 0.0;
		foreach ( WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			$colors = array_column( $variation['settings']['color']['palette']['theme'] ?? array(), 'color', 'slug' );
			if ( isset( $colors['ink'], $colors['surface'] ) ) {
				$best = max( $best, LeitviaStudio\Tokens\Contrast::ratio( $colors['ink'], $colors['surface'] ) );
			}
		}
		$this->assertGreaterThan( 0, $best, 'the theme has variations with ink and surface' );
		$this->assertGreaterThanOrEqual( $best, LeitviaStudio\Tokens\Contrast::ratio( $m[2], $m[1] ) );
		$this->assertStringContainsString( '--wp--preset--color--accent:' . $m[2], $css );
		$this->assertStringContainsString( '--wp--preset--color--on-accent:' . $m[1], $css );
	}
}
