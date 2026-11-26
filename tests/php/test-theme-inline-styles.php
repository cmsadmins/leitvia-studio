<?php
/**
 * Inline stylesheets of the theme without comments and runs of white space.
 *
 * @package LeitviaStudio
 */

/**
 * Functions compact_css and compact_inline_styles of the theme's functions.php.
 */
class Test_Theme_Inline_Styles extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme and loads its functions.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		if ( ! function_exists( 'Leitvia\compact_css' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
	}

	/**
	 * Comments and white space go, strings and selectors with a space before a colon stay.
	 */
	public function test_compact_css(): void {
		$css = "/*\n * Note.\n */\n.a :is(b, c) {\n\tcontent: \"x, y\" / \"\";\n\tcolor: red;\n}\n";
		$this->assertSame( '.a :is(b, c){content: "x, y" / "";color: red;}', \Leitvia\compact_css( $css ) );
	}

	/**
	 * Only stylesheets of the theme that core prints inline are compacted; a linked one and one of a plugin stay.
	 */
	public function test_only_inline_styles_of_the_theme(): void {
		$styles = wp_styles();
		$theme  = get_theme_file_path( 'assets/css/focus.css' );
		foreach ( array( 'lv-test-inline', 'lv-test-linked', 'lv-test-plugin' ) as $handle ) {
			wp_register_style( $handle, 'https://example.org/' . $handle . '.css', array(), '1' );
		}
		wp_style_add_data( 'lv-test-inline', 'path', $theme );
		wp_style_add_data( 'lv-test-linked', 'path', $theme );
		wp_style_add_data( 'lv-test-plugin', 'path', WP_PLUGIN_DIR . '/leitvia-studio/x.css' );
		$styles->registered['lv-test-inline']->src = false;
		$styles->registered['lv-test-plugin']->src = false;
		foreach ( array( 'lv-test-inline', 'lv-test-linked', 'lv-test-plugin' ) as $handle ) {
			$styles->registered[ $handle ]->extra['after'] = array( "/* c */\na {\n\tcolor: red;\n}" );
		}
		\Leitvia\compact_inline_styles();
		$this->assertSame( 'a{color: red;}', $styles->registered['lv-test-inline']->extra['after'][0] );
		$this->assertStringContainsString( '/* c */', $styles->registered['lv-test-linked']->extra['after'][0] );
		$this->assertStringContainsString( '/* c */', $styles->registered['lv-test-plugin']->extra['after'][0] );
	}
}
