<?php
/**
 * Plugin bootstrap tests.
 *
 * @package LeitviaStudio
 */

/**
 * Constants, boot and front-end silence.
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * The public constants and the plugin class exist.
	 */
	public function test_constants_and_boot(): void {
		$this->assertSame( '1.0.0', LEITVIA_STUDIO_VERSION );
		$this->assertSame( 1, LEITVIA_STUDIO_API );
		$this->assertTrue( class_exists( \LeitviaStudio\Plugin::class ) );
	}

	/**
	 * The plugin enqueues nothing on the front end.
	 */
	public function test_no_front_end_assets(): void {
		do_action( 'wp_enqueue_scripts' );
		foreach ( array_merge( wp_scripts()->queue, wp_styles()->queue ) as $handle ) {
			$this->assertStringStartsNotWith( 'leitvia-studio', $handle );
		}
	}
}
