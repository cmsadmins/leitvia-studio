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
		// The constant follows the plugin header, so a release only changes the header and the constant together.
		$this->assertSame( get_file_data( LEITVIA_STUDIO_FILE, array( 'v' => 'Version' ) )['v'], LEITVIA_STUDIO_VERSION );
		$this->assertSame( 1, LEITVIA_STUDIO_API );
		$this->assertTrue( class_exists( \LeitviaStudio\Plugin::class ) );
	}

	/**
	 * The plugin enqueues nothing on the front end.
	 */
	public function test_no_front_end_assets(): void {
		// Earlier tests render blocks that enqueue their own rules (the facts list); only wp_enqueue_scripts counts here.
		wp_scripts()->queue = array();
		wp_styles()->queue  = array();
		do_action( 'wp_enqueue_scripts' );
		foreach ( array_merge( wp_scripts()->queue, wp_styles()->queue ) as $handle ) {
			$this->assertStringStartsNotWith( 'leitvia-studio', $handle );
		}
	}
}
