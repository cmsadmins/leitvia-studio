<?php
/**
 * Core tabs without JavaScript.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress 7.1.3 renders core/tab-panel with data-wp-bind--hidden="!state.isActiveTab". The server-side pass of the
 * Interactivity API does not know the derived state isActiveTab and writes `hidden` on every panel, so without
 * JavaScript no tab content is visible (docs/accessibility/known-limitations.md). Without scripting these filters
 * show all panels one below the other, each with its tab label, and hide the tab list, which does nothing then.
 * Core keeps its own logic; nothing changes when scripting is on.
 */
final class Tabs {

	/**
	 * Enqueues the rules for browsers without scripting (media feature scripting: none) once a tabs block is on the
	 * page. Block themes render the content before the head is printed, so the style still lands in the head. Runs on
	 * render_block_core/tabs.
	 *
	 * @param string $content Rendered block HTML.
	 */
	public static function render_tabs( string $content ): string {
		if ( ! wp_style_is( 'leitvia-studio-tabs', 'enqueued' ) ) {
			wp_register_style( 'leitvia-studio-tabs', false, array(), LEITVIA_STUDIO_VERSION );
			wp_add_inline_style( 'leitvia-studio-tabs', '@media (scripting: none){.wp-block-tabs .wp-block-tab-list{display:none}.wp-block-tabs .wp-block-tab-panel[hidden]{display:block!important}}' );
			wp_enqueue_style( 'leitvia-studio-tabs' );
		}
		return $content;
	}

	/**
	 * Puts the tab label at the top of the panel, only shown without scripting. Runs on render_block_core/tab-panel.
	 *
	 * @param string               $content Rendered block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function render_panel( string $content, array $block ): string {
		$label = wp_strip_all_tags( (string) ( $block['attrs']['label'] ?? '' ) );
		$at    = strpos( $content, '>' );
		if ( '' === $label || false === $at ) {
			return $content;
		}
		return substr_replace( $content, '<noscript><p class="wp-block-tab-panel__label">' . esc_html( $label ) . '</p></noscript>', $at + 1, 0 );
	}
}
