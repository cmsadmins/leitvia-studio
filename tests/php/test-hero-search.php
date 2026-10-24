<?php
/**
 * The theme pattern leitvia/hero-search and the docs demo home page built on it.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Composition;
use LeitviaStudio\Lab\Token_Lint;

/**
 * Registration, markup and contract entry of the search hero; composition of the docs demo home page.
 */
class Test_Hero_Search extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme so its patterns are registered.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
	}

	/**
	 * Content of the registered pattern.
	 */
	private static function content(): string {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia/hero-search' );
		self::assertIsArray( $pattern, 'leitvia/hero-search is registered' );
		return (string) $pattern['content'];
	}

	/**
	 * One h1, one search field with a visible label and a button text, buttons whose links the page edit sets.
	 */
	public function test_markup(): void {
		$content = self::content();
		$this->assertSame( 1, substr_count( $content, '<h1' ) );
		$blocks = wp_list_filter( parse_blocks( $content )[0]['innerBlocks'][1]['innerBlocks'], array( 'blockName' => 'core/search' ) );
		$this->assertCount( 1, $blocks );
		$search = reset( $blocks )['attrs'];
		$this->assertNotSame( '', $search['label'] ?? '' );
		$this->assertArrayNotHasKey( 'showLabel', $search, 'the label stays visible' );
		$this->assertNotSame( '', $search['buttonText'] ?? '' );
		$this->assertSame( 3, substr_count( $content, 'wp:button {"className":"is-style-outline"}' ) );
		$this->assertSame( array(), Token_Lint::lint_markup( $content ) );
	}

	/**
	 * The contract lists the pattern as a hook with full composition metadata.
	 */
	public function test_contract_entry(): void {
		$entry = wp_list_filter( leitvia_studio_contract()->data()['patterns'], array( 'slug' => 'leitvia/hero-search' ) );
		$this->assertCount( 1, $entry );
		$entry = reset( $entry );
		$this->assertSame( 'hero', $entry['area'] );
		$this->assertSame( 'hook', $entry['composition']['role'] );
		$this->assertSame( array(), $entry['images'] );
	}

	/**
	 * The home page of the docs demo (site/demo.leitvia.com/docs/content.php) scores 100.
	 */
	public function test_docs_home_composition(): void {
		$sections = array( 'leitvia/hero-search', 'leitvia-studio/content-story-highlight', 'leitvia-studio/content-bento-features', 'leitvia/proof-testimonial', 'leitvia/content-steps', 'leitvia/content-faq', 'leitvia/cta-contact' );
		$result   = Composition::score( $sections, leitvia_studio_contract()->data(), 'page-landing' );
		$this->assertSame( array(), $result['issues'] ?? array() );
		$this->assertSame( 100, $result['score'] );
	}
}
