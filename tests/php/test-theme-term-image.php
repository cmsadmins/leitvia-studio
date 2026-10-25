<?php
/**
 * The theme binding source leitvia/term-image.
 *
 * @package LeitviaStudio
 */

/**
 * An image block in a term template shows the image of the term and drops out without one.
 */
class Test_Theme_Term_Image extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme and registers its binding source.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		if ( ! function_exists( 'Leitvia\term_image_value' ) ) {
			require_once get_theme_file_path( 'functions.php' );
		}
		if ( ! get_block_bindings_source( 'leitvia/term-image' ) ) {
			Leitvia\register_term_image_binding();
		}
		// functions.php may have been loaded by an earlier test class, whose hooks the test case has reset since.
		if ( ! has_filter( 'render_block_core/image', 'Leitvia\term_image_size' ) ) {
			add_filter( 'render_block_core/image', 'Leitvia\term_image_size', 10, 3 );
		}
	}

	/**
	 * The tile image of leitvia/shop-category-tiles in a terms query of categories: the term with an image renders it,
	 * the term without renders no image.
	 */
	public function test_tile_image_follows_the_term(): void {
		$with    = self::factory()->category->create( array( 'name' => 'Notebooks' ) );
		$without = self::factory()->category->create( array( 'name' => 'Belts' ) );
		self::factory()->post->create( array( 'post_category' => array( $with, $without ) ) );
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_term_meta( $with, 'thumbnail_id', $image );

		$source = (string) file_get_contents( get_theme_file_path( 'patterns/shop-category-tiles.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Theme file.
		$this->assertSame( 1, preg_match( '#<!-- wp:image .*?<!-- /wp:image -->#s', $source, $tile ) );
		$markup = '<!-- wp:terms-query {"termQuery":{"perPage":6,"taxonomy":"category","order":"asc","orderBy":"name","include":[],"hideEmpty":true,"showNested":false,"inherit":false}} --><div class="wp-block-terms-query"><!-- wp:term-template -->'
			. $tile[0] . '<!-- wp:term-name /--><!-- /wp:term-template --></div><!-- /wp:terms-query -->';
		$html   = do_blocks( $markup );

		$this->assertSame( 1, substr_count( $html, '<img' ), 'one image, for the term with one' );
		$this->assertStringContainsString( 'src="' . wp_get_attachment_image_url( $image, 'medium_large' ) . '"', $html );
		$this->assertStringContainsString( 'alt=""', $html );
		$size = wp_get_attachment_image_src( $image, 'medium_large' );
		$this->assertStringContainsString( 'height="' . $size[2] . '" width="' . $size[1] . '"', $html, 'sized on render (CLS)' );
		$this->assertLessThan( strpos( $html, 'Notebooks' ), strpos( $html, '<img' ), 'image above the name' );
		$this->assertLessThan( strpos( $html, '<img' ), strpos( $html, 'Belts' ), 'Belts comes first, without image' );
	}
}
