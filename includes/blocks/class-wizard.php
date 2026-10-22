<?php
/**
 * Wizard block: one step at a time on top of the saved form.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * The saved leitvia/wizard is a GET form with all steps and a submit button. On render this class hides every step
 * but the first, the submit button, and adds the progress text, Back and Next, translated. The page keeps that
 * layout from the first paint, so view.js shifts nothing. Without scripting a <noscript> style shows all steps and the
 * submit button again and hides the step controls (plan 3.3, point 1).
 */
final class Wizard {

	/**
	 * Filters the rendered wizard. Runs on render_block_leitvia/wizard.
	 *
	 * @param string $content Rendered block HTML.
	 */
	public static function render( string $content ): string {
		$step  = array(
			'tag_name'   => 'FIELDSET',
			'class_name' => 'wp-block-leitvia-wizard-step',
		);
		$count = new \WP_HTML_Tag_Processor( $content );
		$total = 0;
		while ( $count->next_tag( $step ) ) {
			++$total;
		}
		if ( $total < 2 ) {
			return $content;
		}

		$at = strpos( $content, '<p class="wp-block-leitvia-wizard__submit"' );
		if ( false === $at ) {
			return $content;
		}
		/* translators: 1: number of the current step, 2: number of steps. */
		$template = __( 'Step %1$d of %2$d', 'leitvia-studio' );
		$nav      = sprintf(
			'<div class="wp-block-leitvia-wizard__nav"><p class="wp-block-leitvia-wizard__progress" data-template="%1$s">%2$s</p><button type="button" class="wp-element-button" data-wizard="back" hidden>%3$s</button><button type="button" class="wp-element-button" data-wizard="next">%4$s</button></div>',
			esc_attr( $template ),
			esc_html( sprintf( $template, 1, $total ) ),
			esc_html__( 'Back', 'leitvia-studio' ),
			esc_html__( 'Next', 'leitvia-studio' )
		);

		// ponytail: counts nested wizards into their parent; a wizard inside a wizard is not planned.
		$html = new \WP_HTML_Tag_Processor( substr_replace( $content, $nav, $at, 0 ) );
		$n    = 0;
		while ( $html->next_tag( $step ) ) {
			if ( ++$n > 1 ) {
				$html->set_attribute( 'hidden', true );
			}
			if ( $html->next_tag( array( 'tag_name' => 'LEGEND' ) ) ) {
				$html->set_attribute( 'tabindex', '-1' );
			}
		}
		$html = new \WP_HTML_Tag_Processor( $html->get_updated_html() );
		if ( $html->next_tag(
			array(
				'tag_name'   => 'P',
				'class_name' => 'wp-block-leitvia-wizard__submit',
			)
		) ) {
			$html->set_attribute( 'hidden', true );
		}
		return $html->get_updated_html() . '<noscript><style>.wp-block-leitvia-wizard .wp-block-leitvia-wizard__submit[hidden]{display:flex!important}.wp-block-leitvia-wizard .wp-block-leitvia-wizard-step[hidden]{display:grid!important}.wp-block-leitvia-wizard__nav{display:none!important}</style></noscript>';
	}
}
