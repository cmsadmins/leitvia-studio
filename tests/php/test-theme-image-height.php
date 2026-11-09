<?php
/**
 * The theme filter image_height_from_file() and the WooCommerce mini cart workaround.
 *
 * @package LeitviaStudio
 */

/**
 * An image block with a height but no aspect ratio takes its height from the image; one with an aspect ratio, and one
 * without a height, render as core renders them.
 */
class Test_Theme_Image_Height extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme and its filter.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		if ( ! function_exists( 'Leitvia\image_height_from_file' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
		// functions.php may have been loaded by an earlier test class, whose hooks the test case has reset since.
		if ( ! has_filter( 'render_block_core/image', 'Leitvia\image_height_from_file' ) ) {
			add_filter( 'render_block_core/image', 'Leitvia\image_height_from_file', 10, 2 );
		}
	}

	/**
	 * Width and height in pixels without an aspect ratio, as a migration leaves them: the inline height becomes auto,
	 * the width stays, no !important.
	 */
	public function test_fixed_height_without_ratio_becomes_auto(): void {
		$html = do_blocks( '<!-- wp:image {"width":"900px","height":"200px","sizeSlug":"full","linkDestination":"none"} --><figure class="wp-block-image size-full is-resized"><img src="https://example.org/a.jpg" alt="A" style="width:900px;height:200px"/></figure><!-- /wp:image -->' );
		$this->assertStringContainsString( 'style="width:900px;height:auto"', $html );
		$this->assertStringNotContainsString( '200px', $html );
		$this->assertStringNotContainsString( '!important', $html );
	}

	/**
	 * With an aspect ratio the block crops, and without a height there is nothing to change.
	 */
	public function test_ratio_and_plain_images_stay(): void {
		$cropped = '<!-- wp:image {"aspectRatio":"3/1","height":"200px","scale":"cover","sizeSlug":"full","linkDestination":"none"} --><figure class="wp-block-image size-full is-resized"><img src="https://example.org/a.jpg" alt="A" style="aspect-ratio:3/1;object-fit:cover;height:200px"/></figure><!-- /wp:image -->';
		$this->assertStringContainsString( 'style="aspect-ratio:3/1;object-fit:cover;height:200px"', do_blocks( $cropped ) );
		$plain = '<!-- wp:image {"width":"300px","sizeSlug":"full","linkDestination":"none"} --><figure class="wp-block-image size-full is-resized"><img src="https://example.org/a.jpg" alt="A" style="width:300px"/></figure><!-- /wp:image -->';
		$this->assertStringContainsString( 'style="width:300px"', do_blocks( $plain ) );
	}

	/**
	 * Only the property height changes, not max-height or line-height.
	 */
	public function test_only_the_height_property_changes(): void {
		$html = Leitvia\image_height_from_file( '<figure class="wp-block-image"><img src="a.jpg" alt="" style="max-height:10rem;height:12rem;line-height:1"/></figure>', array( 'attrs' => array( 'height' => '12rem' ) ) );
		$this->assertStringContainsString( 'style="max-height:10rem;height:auto;line-height:1"', $html );
	}

	/**
	 * Without WooCommerce the mini cart workaround leaves the markup and the module queue alone.
	 */
	public function test_mini_cart_workaround_without_woocommerce(): void {
		if ( defined( 'WC_VERSION' ) ) {
			$this->markTestSkipped( 'WooCommerce is active; tests/e2e/commerce/mini-cart-module.spec.mjs covers it.' );
		}
		$this->assertSame( '<div>cart</div>', Leitvia\mini_cart_module_off_cart( '<div>cart</div>' ) );
	}
}
