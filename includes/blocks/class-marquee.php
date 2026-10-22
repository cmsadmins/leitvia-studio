<?php
/**
 * Marquee block: the translated labels of its Pause button.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the button labels to the Interactivity store leitvia/marquee, only on pages that render the block.
 */
final class Marquee {

	/**
	 * Sets the state of leitvia/marquee before a marquee renders, so the server fills the button text
	 * (data-wp-text="state.label"). Runs on render_block_data.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	public static function state( array $block ): array {
		if ( 'leitvia/marquee' === ( $block['blockName'] ?? '' ) ) {
			wp_interactivity_state(
				'leitvia/marquee',
				array(
					'label' => __( 'Pause', 'leitvia-studio' ),
					'pause' => __( 'Pause', 'leitvia-studio' ),
					'play'  => __( 'Play', 'leitvia-studio' ),
				)
			);
		}
		return $block;
	}
}
