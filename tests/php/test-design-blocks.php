<?php
/**
 * Format "Highlight" style and the marquee button labels.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Blocks;
use LeitviaStudio\Blocks\Marquee;

/**
 * The style highlight.css loads only with the format; a rendered marquee gets its translated button text from the server.
 */
class Test_Design_Blocks extends WP_UnitTestCase {

	/**
	 * Empties the style queue and the marquee state.
	 */
	public function tear_down(): void {
		wp_dequeue_style( 'leitvia-studio-highlight' );
		wp_interactivity_state( 'leitvia/marquee', array( 'label' => null ) );
		parent::tear_down();
	}

	/**
	 * Content without the format loads nothing, content with it loads the style.
	 */
	public function test_highlight_style_only_with_the_format(): void {
		$this->assertSame( '<p>Plain</p>', Blocks::highlight_style( '<p>Plain</p>' ) );
		$this->assertFalse( wp_style_is( 'leitvia-studio-highlight' ) );
		$html = '<p>A <mark class="leitvia-highlight is-marker is-accent">word</mark></p>';
		$this->assertStringContainsString( 'is-marker is-accent">word</mark>', do_blocks( '<!-- wp:paragraph -->' . $html . '<!-- /wp:paragraph -->' ) );
		$this->assertTrue( wp_style_is( 'leitvia-studio-highlight' ) );
		$this->assertStringEndsWith( 'assets/highlight.css', strtok( (string) wp_styles()->registered['leitvia-studio-highlight']->src, '?' ) );
	}

	/**
	 * Not on the front end through enqueue_block_assets, in the editor always.
	 */
	public function test_highlight_style_in_the_editor(): void {
		Blocks::editor_highlight_style();
		$this->assertFalse( wp_style_is( 'leitvia-studio-highlight' ) );
		set_current_screen( 'post' );
		Blocks::editor_highlight_style();
		$this->assertTrue( wp_style_is( 'leitvia-studio-highlight' ) );
		set_current_screen( 'front' );
	}

	/**
	 * The saved, empty Pause button of a marquee renders with the label from the state and stays hidden.
	 */
	public function test_marquee_button_label_from_the_server(): void {
		$this->assertSame( array( 'blockName' => 'core/paragraph' ), Marquee::state( array( 'blockName' => 'core/paragraph' ) ) );
		$html = do_blocks( '<!-- wp:leitvia/marquee --><div class="wp-block-leitvia-marquee" data-wp-interactive="leitvia/marquee" data-wp-context="{&quot;running&quot;:false,&quot;paused&quot;:false}"><button type="button" class="wp-block-leitvia-marquee__toggle wp-element-button" hidden data-wp-bind--hidden="!context.running" data-wp-text="state.label"></button><div class="wp-block-leitvia-marquee__viewport"><div class="wp-block-leitvia-marquee__track"></div></div></div><!-- /wp:leitvia/marquee -->' );
		$this->assertMatchesRegularExpression( '#<button[^>]*\shidden[\s>=][^>]*>Pause</button>#', $html );
		$this->assertSame( 'Play', wp_interactivity_state( 'leitvia/marquee' )['play'] );
	}
}
