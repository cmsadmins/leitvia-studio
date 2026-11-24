<?php
/**
 * Sticky header: the measuring module loads only with a sticky header style.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Sticky_Header;

/**
 * A group with one of the theme's sticky header styles loads @leitvia/sticky-header; every other group does not.
 */
class Test_Sticky_Header extends WP_UnitTestCase {

	/**
	 * Starts every test with an empty module queue.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_dequeue_script_module( Sticky_Header::MODULE );
		Sticky_Header::register_module();
	}

	/**
	 * Whether the module is enqueued.
	 */
	private static function queued(): bool {
		$queue = ( new ReflectionProperty( WP_Script_Modules::class, 'queue' ) )->getValue( wp_script_modules() );
		return in_array( Sticky_Header::MODULE, array_values( (array) $queue ), true );
	}

	/**
	 * Each of the three styles enqueues the module, also next to other classes.
	 */
	public function test_sticky_styles_enqueue_the_module(): void {
		foreach ( array( 'is-style-sticky-header', 'is-style-sticky-header-desktop', 'site-head is-style-sticky-header-mobile' ) as $class ) {
			wp_dequeue_script_module( Sticky_Header::MODULE );
			do_blocks( '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group ' . $class . '"></div><!-- /wp:group -->' );
			$this->assertTrue( self::queued(), $class );
		}
	}

	/**
	 * Other groups and look-alike classes leave it out.
	 */
	public function test_other_groups_leave_it_out(): void {
		foreach ( array( '', 'is-style-inverse', 'is-style-sticky-headers', 'my-is-style-sticky-header' ) as $class ) {
			do_blocks( '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group ' . $class . '"></div><!-- /wp:group -->' );
			$this->assertFalse( self::queued(), $class );
		}
	}

	/**
	 * In a theme whose header sticks by default every header part loads it, except one that scrolls with the page;
	 * a footer part and a theme without the support never do.
	 */
	public function test_header_parts_of_a_theme_with_default_sticky(): void {
		$part = static fn( string $tag, string $html ) => apply_filters(
			'render_block_core/template-part', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The filter of core for this block.
			$html,
			array(
				'blockName' => 'core/template-part',
				'attrs'     => array( 'tagName' => $tag ),
			)
		);
		add_theme_support( 'leitvia-sticky-header' );
		$part( 'header', '<div class="wp-block-group"></div>' );
		$this->assertTrue( self::queued(), 'header' );
		wp_dequeue_script_module( Sticky_Header::MODULE );
		$part( 'header', '<div class="wp-block-group is-style-scrolling-header"></div>' );
		$this->assertFalse( self::queued(), 'scrolling header' );
		$part( 'footer', '<div class="wp-block-group"></div>' );
		$this->assertFalse( self::queued(), 'footer' );
		remove_theme_support( 'leitvia-sticky-header' );
		$part( 'header', '<div class="wp-block-group"></div>' );
		$this->assertFalse( self::queued(), 'theme without the support' );
		add_theme_support( 'leitvia-sticky-header' );
	}

	/**
	 * A header over the stage gets the inline measurement right after it, so the page does not jump once it is
	 * measured; other headers and footers do not.
	 */
	public function test_overlay_header_is_measured_inline(): void {
		$part = static fn( string $tag, string $html ) => apply_filters(
			'render_block_core/template-part', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The filter of core for this block.
			$html,
			array(
				'blockName' => 'core/template-part',
				'attrs'     => array( 'tagName' => $tag ),
			)
		);
		$html = $part( 'header', '<header class="wp-block-template-part"><div class="wp-block-group is-style-overlay-header"></div></header>' );
		$this->assertStringStartsWith( '<header class="wp-block-template-part"><div class="wp-block-group is-style-overlay-header"></div></header><script', $html );
		$this->assertStringContainsString( '--leitvia-sticky-header-height', $html );
		$this->assertStringNotContainsString( '<script', $part( 'header', '<header class="wp-block-template-part"><div class="wp-block-group is-style-overlay-header-x"></div></header>' ) );
		$this->assertStringNotContainsString( '<script', $part( 'footer', '<footer class="wp-block-template-part"><div class="wp-block-group is-style-overlay-header"></div></footer>' ) );
	}
}
