<?php
/**
 * Announcement bar: dismiss button, content hash, inline script.
 *
 * @package LeitviaStudio
 */

/**
 * The rendered bar carries what only works with Studio; the saved markup stays a plain aside with text and link.
 */
class Test_Announcement_Bar extends WP_UnitTestCase {

	/**
	 * Saved bar markup, as in tests/fixtures/blocks/announcement-bar.html.
	 *
	 * @param string $text Text of the bar.
	 */
	private static function bar( string $text ): string {
		return '<!-- wp:leitvia/announcement-bar --><section class="wp-block-leitvia-announcement-bar alignfull" aria-label="Announcement"><p class="wp-block-leitvia-announcement-bar__text">' . $text . ' <a href="/pricing/">See plans</a></p></section><!-- /wp:leitvia/announcement-bar -->';
	}

	/**
	 * The inline script of the bar, queued on a handle without a file, '' when none is queued.
	 */
	private static function state_script(): string {
		return wp_script_is( 'leitvia-announcement-bar-state', 'enqueued' ) ? implode( "\n", array_filter( (array) wp_scripts()->get_data( 'leitvia-announcement-bar-state', 'after' ) ) ) : '';
	}

	/**
	 * The bar gets a hidden dismiss button named by its label and its hash; the script with that hash is queued for
	 * the head (no script inside the block markup), once however often the bar renders.
	 */
	public function test_render_adds_button_hash_and_script(): void {
		wp_deregister_script( 'leitvia-announcement-bar-state' );
		$html = do_blocks( self::bar( 'New plans' ) );
		do_blocks( self::bar( 'New plans' ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertSame( 1, preg_match( '#^<section[^>]*\bdata-hash="([0-9a-f]{12})"#', $html, $hash ) );
		$this->assertSame( 1, substr_count( self::state_script(), 'try{localStorage.getItem("leitvia-announcement")==="' . $hash[1] . '"' ) );
		$this->assertFalse( (bool) wp_scripts()->get_data( 'leitvia-announcement-bar-state', 'group' ), 'printed in the head, before the first paint' );
		$this->assertMatchesRegularExpression( '#<button type="button" class="wp-block-leitvia-announcement-bar__dismiss" aria-label="Dismiss announcement" hidden><svg [^>]*aria-hidden="true"[^>]*>.*?</svg></button></section>#', $html );
		$this->assertStringContainsString( 'aria-label="Announcement"', $html );
	}

	/**
	 * A bar saved as an aside before version 0.2.0 of the block still gets its button.
	 */
	public function test_render_keeps_bars_saved_as_aside(): void {
		$html = do_blocks( str_replace( array( '<section', '</section>' ), array( '<aside', '</aside>' ), self::bar( 'New plans' ) ) );
		$this->assertMatchesRegularExpression( '#<aside[^>]*\bdata-hash="[0-9a-f]{12}".*</button></aside>#s', $html );
	}

	/**
	 * The header pattern puts the bar above the theme's default header and is offered only with the Leitvia theme.
	 */
	public function test_header_pattern_puts_the_bar_above_the_default_header(): void {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia-studio/header-announcement' );
		$this->assertSame( array( 'core/template-part/header' ), $pattern['blockTypes'] );
		$this->assertSame( 'leitvia' === get_template(), $pattern['inserter'] );
		$blocks = array_values( array_filter( parse_blocks( $pattern['content'] ), static fn( $block ) => null !== $block['blockName'] ) );
		$this->assertSame( array( 'leitvia/announcement-bar', 'core/pattern' ), array_column( $blocks, 'blockName' ) );
		$this->assertSame( 'leitvia/header-default', $blocks[1]['attrs']['slug'] );
		$this->assertStringContainsString( '<section class="wp-block-leitvia-announcement-bar alignfull" aria-label="Announcement">', $blocks[0]['innerHTML'] );
	}

	/**
	 * Changed content gives a new hash, so a dismissed bar shows again.
	 */
	public function test_new_content_new_hash(): void {
		$hash = static fn( string $html ): string => preg_match( '/data-hash="([0-9a-f]+)"/', $html, $m ) ? $m[1] : '';
		$this->assertNotSame( $hash( do_blocks( self::bar( 'New plans' ) ) ), $hash( do_blocks( self::bar( 'Newer plans' ) ) ) );
		$this->assertSame( $hash( do_blocks( self::bar( 'New plans' ) ) ), $hash( do_blocks( self::bar( 'New plans' ) ) ) );
	}

	/**
	 * The inline script stays below 300 bytes.
	 */
	public function test_inline_script_is_small(): void {
		wp_deregister_script( 'leitvia-announcement-bar-state' );
		do_blocks( self::bar( 'New plans' ) );
		$this->assertNotEmpty( self::state_script() );
		$this->assertLessThan( 300, strlen( self::state_script() ) );
	}

	/**
	 * Users without unfiltered_html keep the radios of the pricing switch; script attributes stay filtered.
	 */
	public function test_post_kses_keeps_inputs_without_handlers(): void {
		$html = wp_kses_post( '<label><input type="radio" name="leitvia-pricing-a" value="yearly" checked onclick="x()"></label>' );
		$this->assertStringContainsString( '<input type="radio" name="leitvia-pricing-a" value="yearly" checked>', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}
}
