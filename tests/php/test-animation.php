<?php
/**
 * Animation on scroll for every block: attribute, classes on the outer element, style only when used.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Animation;
use LeitviaStudio\Gateway\Block_Edit;

/**
 * Attribute leitviaAnimation and the render filter of Animation.
 */
class Test_Animation extends WP_UnitTestCase {

	/**
	 * Handle of the reveal style, which holds the rules.
	 */
	private const STYLE = 'leitvia-reveal-style';

	/**
	 * Starts every test without the style and the script in the queue, with the default site setting.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_dequeue_style( self::STYLE );
		wp_dequeue_script_module( 'leitvia-reveal-view-script-module' );
		delete_option( 'leitvia_studio_settings' );
	}

	/**
	 * Script modules queued for the page, printed.
	 */
	private static function modules(): string {
		ob_start();
		wp_script_modules()->print_enqueued_script_modules();
		return (string) ob_get_clean();
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
			$this->assertSame( array( 'none', 'fade', 'rise', 'stagger', 'zoom', 'slide-start', 'slide-end', 'wipe-up', 'wipe-start', 'blur', 'mask', 'parallax' ), $attribute['enum'] );
			$this->assertSame( 'none', $attribute['default'] );
			$motion = $types->get_registered( $name )->attributes['leitviaMotion'] ?? array();
			$this->assertSame( array( 'subtle', 'bold' ), $motion['enum'] ?? null, $name );
			$this->assertArrayNotHasKey( 'default', $motion, 'without it the site default applies' );
		}
		foreach ( array( 'core/html', 'core/navigation', 'core/template-part', 'leitvia/reveal' ) as $name ) {
			$type = $types->get_registered( $name );
			if ( $type ) {
				$this->assertArrayNotHasKey( 'leitviaAnimation', (array) $type->attributes, $name );
				$this->assertArrayNotHasKey( 'leitviaMotion', (array) $type->attributes, $name );
			}
		}
	}

	/**
	 * Every new effect lands as its class; the strength comes from leitviaMotion, else from the site default.
	 */
	public function test_effects_and_strength(): void {
		foreach ( array( 'zoom', 'slide-start', 'slide-end', 'wipe-up', 'wipe-start', 'blur', 'mask' ) as $effect ) {
			$html = do_blocks( '<!-- wp:heading {"leitviaAnimation":"' . $effect . '","leitviaMotion":"bold"} --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->' );
			$this->assertStringContainsString( '<h2 class="wp-block-heading has-lv-animation is-lv-animation-' . $effect . ' is-lv-motion-bold">Title</h2>', $html, $effect );
		}
		$this->assertStringContainsString( 'is-lv-motion-subtle', do_blocks( '<!-- wp:paragraph {"leitviaAnimation":"fade","leitviaMotion":"loud"} --><p>A</p><!-- /wp:paragraph -->' ) );

		update_option( 'leitvia_studio_settings', array( 'motion' => 'bold' ) );
		$this->assertStringContainsString( 'is-lv-animation-fade is-lv-motion-bold', do_blocks( '<!-- wp:paragraph {"leitviaAnimation":"fade"} --><p>A</p><!-- /wp:paragraph -->' ) );
		$this->assertStringContainsString( 'is-lv-animation-fade is-lv-motion-subtle', do_blocks( '<!-- wp:paragraph {"leitviaAnimation":"fade","leitviaMotion":"subtle"} --><p>A</p><!-- /wp:paragraph -->' ) );
		// The reveal block takes the site default as well.
		$this->assertMatchesRegularExpression( '/class="wp-block-leitvia-reveal is-effect-rise[^"]* is-lv-motion-bold"/', do_blocks( '<!-- wp:leitvia/reveal --><div class="wp-block-leitvia-reveal is-effect-rise"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:leitvia/reveal -->' ) );
	}

	/**
	 * Parallax is bound to the scroll position: its own class, the style, no script.
	 */
	public function test_parallax_loads_the_style_only(): void {
		$html = do_blocks( '<!-- wp:leitvia/timeline {"leitviaAnimation":"parallax"} --><ol class="wp-block-leitvia-timeline"></ol><!-- /wp:leitvia/timeline -->' );
		$this->assertStringContainsString( 'has-lv-parallax is-lv-motion-subtle', $html );
		$this->assertStringNotContainsString( 'has-lv-animation', $html );
		$this->assertTrue( wp_style_is( self::STYLE ) );
		$this->assertStringNotContainsString( 'leitvia-reveal-view-script-module', self::modules() );
	}

	/**
	 * The site setting motion off: no class, no style, no script, the body class for the theme, and the reveal block
	 * registers without its assets.
	 */
	public function test_site_setting_off_loads_nothing(): void {
		update_option( 'leitvia_studio_settings', array( 'motion' => 'off' ) );
		$html = do_blocks( '<!-- wp:group {"leitviaAnimation":"rise","leitviaMotion":"bold"} --><div class="wp-block-group"><p>One</p></div><!-- /wp:group -->' );
		$this->assertStringContainsString( '<p>One</p>', $html );
		$this->assertStringNotContainsString( 'lv-', $html );
		$this->assertFalse( wp_style_is( self::STYLE ) );
		$this->assertStringNotContainsString( 'leitvia-reveal-view-script-module', self::modules() );
		$this->assertContains( 'leitvia-motion-off', Animation::body_class( array() ) );
		$args = Animation::block_args(
			array(
				'style_handles'          => array( self::STYLE ),
				'view_script_module_ids' => array( 'leitvia-reveal-view-script-module' ),
			),
			'leitvia/reveal'
		);
		$this->assertArrayNotHasKey( 'style_handles', $args );
		$this->assertArrayNotHasKey( 'view_script_module_ids', $args );

		update_option( 'leitvia_studio_settings', array( 'motion' => 'subtle' ) );
		$this->assertSame( array(), Animation::body_class( array() ) );
		$this->assertArrayHasKey( 'style_handles', Animation::block_args( array( 'style_handles' => array( self::STYLE ) ), 'leitvia/reveal' ) );
	}

	/**
	 * Agents set and remove the effect on any block through update-block, static markup and core blocks included: only
	 * the block comment changes, in the order the editor saves it. Excluded blocks and unknown values are refused.
	 */
	public function test_update_block_sets_the_effect_on_static_blocks(): void {
		$content = "<!-- wp:group {\"metadata\":{\"leitviaId\":\"aaaa1111\"},\"align\":\"wide\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group alignwide\"><!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";
		$changed = Block_Edit::apply(
			$content,
			'aaaa1111',
			array(
				'attributes' => array(
					'leitviaMotion'    => 'bold',
					'leitviaAnimation' => 'zoom',
				),
			)
		);
		$this->assertIsString( $changed, is_wp_error( $changed ) ? $changed->get_error_message() : '' );
		$this->assertSame( str_replace( '"aaaa1111"},"align"', '"aaaa1111"},"leitviaAnimation":"zoom","leitviaMotion":"bold","align"', $content ), $changed );

		$back = Block_Edit::apply(
			$changed,
			'aaaa1111',
			array(
				'attributes' => array(
					'leitviaAnimation' => 'none',
					'leitviaMotion'    => null,
				),
			)
		);
		$this->assertSame( $content, $back );

		$icons = "<!-- wp:leitvia/icon-list {\"metadata\":{\"leitviaId\":\"bbbb2222\"}} -->\n<ul role=\"list\" class=\"wp-block-leitvia-icon-list\"></ul>\n<!-- /wp:leitvia/icon-list -->";
		$this->assertStringContainsString( '{"metadata":{"leitviaId":"bbbb2222"},"leitviaAnimation":"stagger"}', (string) Block_Edit::apply( $icons, 'bbbb2222', array( 'attributes' => array( 'leitviaAnimation' => 'stagger' ) ) ) );

		$wrong = Block_Edit::apply( $content, 'aaaa1111', array( 'attributes' => array( 'leitviaAnimation' => 'spin' ) ) );
		$this->assertWPError( $wrong );
		$this->assertSame( 'leitvia_invalid_input', $wrong->get_error_code() );
		$html = "<!-- wp:html {\"metadata\":{\"leitviaId\":\"cccc3333\"}} -->\n<p>Raw</p>\n<!-- /wp:html -->";
		$this->assertWPError( Block_Edit::apply( $html, 'cccc3333', array( 'attributes' => array( 'leitviaAnimation' => 'fade' ) ) ) );
	}

	/**
	 * The classes land on the outer element only; the saved markup is otherwise unchanged, style and script are queued.
	 */
	public function test_classes_on_the_outer_element(): void {
		$html = do_blocks( '<!-- wp:group {"leitviaAnimation":"stagger"} --><div class="wp-block-group"><!-- wp:paragraph --><p>One</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
		$this->assertStringContainsString( '<div class="wp-block-group has-lv-animation is-lv-animation-stagger is-lv-motion-subtle">', $html );
		$this->assertStringContainsString( 'One</p>', $html );
		$this->assertSame( 1, substr_count( $html, 'has-lv-animation' ) );
		$this->assertTrue( wp_style_is( self::STYLE ) );
		$this->assertStringContainsString( 'leitvia-reveal-view-script-module-js', self::modules() );
	}

	/**
	 * A dynamic block gets the classes on its rendered wrapper.
	 */
	public function test_dynamic_block(): void {
		$html = do_blocks( '<!-- wp:latest-posts {"leitviaAnimation":"fade"} /-->' );
		$this->assertMatchesRegularExpression( '#^<ul class="[^"]*has-lv-animation is-lv-animation-fade is-lv-motion-subtle"#', trim( $html ) );
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
