<?php
/**
 * Animation on scroll for every block: attribute, classes on the outer element, style only when used.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Animation;

/**
 * Attribute leitviaAnimation and the render filter of Animation.
 */
class Test_Animation extends WP_UnitTestCase {

	/**
	 * Handle of the reveal style, which holds the rules.
	 */
	private const STYLE = 'leitvia-reveal-style';

	/**
	 * Starts every test without the style in the queue.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_dequeue_style( self::STYLE );
	}

	/**
	 * Core and Studio blocks know the attribute with its default, so REST and ServerSideRender accept it; the excluded
	 * blocks do not.
	 */
	public function test_attribute_is_registered_on_every_block_but_the_excluded(): void {
		$types = WP_Block_Type_Registry::get_instance();
		foreach ( array( 'core/paragraph', 'core/group', 'core/latest-posts', 'leitvia/timeline' ) as $name ) {
			$attribute = $types->get_registered( $name )->attributes['leitviaAnimation'] ?? null;
			$this->assertIsArray( $attribute, $name );
			$this->assertSame( array( 'none', 'fade', 'rise', 'stagger' ), $attribute['enum'] );
			$this->assertSame( 'none', $attribute['default'] );
		}
		foreach ( array( 'core/html', 'core/navigation', 'core/template-part', 'leitvia/reveal' ) as $name ) {
			$type = $types->get_registered( $name );
			if ( $type ) {
				$this->assertArrayNotHasKey( 'leitviaAnimation', (array) $type->attributes, $name );
			}
		}
	}

	/**
	 * The classes land on the outer element only; the saved markup is otherwise unchanged, style and script are queued.
	 */
	public function test_classes_on_the_outer_element(): void {
		$html = do_blocks( '<!-- wp:group {"leitviaAnimation":"stagger"} --><div class="wp-block-group"><!-- wp:paragraph --><p>One</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
		$this->assertStringContainsString( '<div class="wp-block-group has-lv-animation is-lv-animation-stagger">', $html );
		$this->assertStringContainsString( 'One</p>', $html );
		$this->assertSame( 1, substr_count( $html, 'has-lv-animation' ) );
		$this->assertTrue( wp_style_is( self::STYLE ) );
		ob_start();
		wp_script_modules()->print_enqueued_script_modules();
		$this->assertStringContainsString( 'leitvia-reveal-view-script-module-js', (string) ob_get_clean() );
	}

	/**
	 * A dynamic block gets the classes on its rendered wrapper.
	 */
	public function test_dynamic_block(): void {
		$html = do_blocks( '<!-- wp:latest-posts {"leitviaAnimation":"fade"} /-->' );
		$this->assertMatchesRegularExpression( '#^<ul class="[^"]*has-lv-animation is-lv-animation-fade"#', trim( $html ) );
	}

	/**
	 * The value none, an unknown value and an excluded block change nothing and load no style.
	 */
	public function test_nothing_without_an_effect(): void {
		foreach ( array(
			'<!-- wp:paragraph --><p>Plain</p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph {"leitviaAnimation":"none"} --><p>Plain</p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph {"leitviaAnimation":"spin"} --><p>Plain</p><!-- /wp:paragraph -->',
			'<!-- wp:html {"leitviaAnimation":"rise"} --><p>Plain</p><!-- /wp:html -->',
		) as $markup ) {
			$this->assertStringNotContainsString( 'lv-animation', do_blocks( $markup ), $markup );
		}
		$this->assertFalse( wp_style_is( self::STYLE ) );
	}

	/**
	 * The block catalog shows agents the attribute with its description on Studio blocks; the schema still holds.
	 */
	public function test_catalog_describes_the_attribute(): void {
		$catalog   = array_column( leitvia_studio_contract()->blocks(), null, 'name' );
		$attribute = $catalog['leitvia/timeline']['attributes']['properties']['leitviaAnimation'] ?? array();
		$this->assertSame( 'none', $attribute['default'] ?? null );
		$this->assertStringContainsString( 'sparingly', $attribute['description'] ?? '' );
		$this->assertArrayNotHasKey( 'leitviaAnimation', $catalog['leitvia/reveal']['attributes']['properties'] );
		$this->assertContains( 'leitvia/reveal', Animation::EXCLUDED );
	}
}
