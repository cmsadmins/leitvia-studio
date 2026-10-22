<?php
/**
 * Form error summary: translated headings on the rendered summary.
 *
 * @package LeitviaStudio
 */

/**
 * The rendered summary carries its headings for 1 to 20 errors; the saved markup stays unchanged otherwise.
 */
class Test_Form_Errors extends WP_UnitTestCase {

	/**
	 * Saved wrapper markup, as in tests/fixtures/blocks/form-errors.html.
	 */
	private const BLOCK = '<!-- wp:leitvia/form-errors --><div class="wp-block-leitvia-form-errors"><div class="wp-block-leitvia-form-errors__summary" role="region" tabindex="-1" hidden><h2 class="wp-block-leitvia-form-errors__title"></h2><ul class="wp-block-leitvia-form-errors__list"></ul></div><!-- wp:html --><form><input required></form><!-- /wp:html --></div><!-- /wp:leitvia/form-errors -->';

	/**
	 * The summary gets data-titles with 20 headings, the first without a number.
	 */
	public function test_render_adds_titles(): void {
		$tags = new WP_HTML_Tag_Processor( do_blocks( self::BLOCK ) );
		$this->assertTrue( $tags->next_tag( array( 'class_name' => 'wp-block-leitvia-form-errors__summary' ) ) );
		$titles = json_decode( (string) $tags->get_attribute( 'data-titles' ), true );
		$this->assertCount( 20, $titles );
		$this->assertSame( 'There is a problem', $titles[0] );
		$this->assertSame( 'There are %d problems', $titles[1] );
		$this->assertTrue( $tags->get_attribute( 'hidden' ) );
		$this->assertStringContainsString( '<form><input required></form>', do_blocks( self::BLOCK ) );
	}

	/**
	 * Fields the server marked invalid fill the summary on render: shown, labelled, one link per field with the label
	 * text without the required marker and the message the field points to.
	 */
	public function test_render_fills_summary_for_server_errors(): void {
		$form = '<form><div class="gfield gfield_contains_required gfield_error"><label for="input_7_2">Email<span class="gfield_required">(Required)</span></label><input id="input_7_2" type="email" aria-invalid="true" aria-describedby="validation_message_7_2"><div id="validation_message_7_2" class="validation_message">This field is required.</div></div><input id="ok" aria-invalid="false"></form>';
		$html = do_blocks( str_replace( '<form><input required></form>', $form, self::BLOCK ) );
		$tags = new WP_HTML_Tag_Processor( $html );
		$tags->next_tag( array( 'class_name' => 'wp-block-leitvia-form-errors__summary' ) );
		$this->assertNull( $tags->get_attribute( 'hidden' ) );
		$title_id = (string) $tags->get_attribute( 'aria-labelledby' );
		$this->assertStringContainsString( '<h2 class="wp-block-leitvia-form-errors__title" id="' . $title_id . '">There is a problem</h2>', $html );
		$this->assertStringContainsString( '<ul class="wp-block-leitvia-form-errors__list"><li><a href="#input_7_2">Email: This field is required.</a></li></ul>', $html );
	}
}
