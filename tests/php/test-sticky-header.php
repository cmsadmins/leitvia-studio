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
}
