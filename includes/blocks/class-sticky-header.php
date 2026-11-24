<?php
/**
 * Sticky header: measures a header the theme keeps at the top of the screen.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * A block theme can keep its header at the top of the screen with a block style on the outermost group of the header
 * template part. The Leitvia theme does it with CSS alone (styles sticky-header, sticky-header-desktop and
 * sticky-header-mobile) and keeps a scroll padding at the top of the page so focused elements and jump targets stop
 * below the header (WCAG 2.4.11). CSS cannot measure the header, so that padding covers the tallest header pattern. The
 * script module of this class measures the header and hands its height to the theme as --leitvia-sticky-header-height
 * on html, and marks a header taller than a quarter of the window with data-leitvia-header-tall, where the theme lets
 * it scroll away (WCAG 1.4.10). It loads only on pages whose header has one of these styles, or, in a theme that
 * declares the support leitvia-sticky-header (its header sticks without a style, as in Leitvia), on every page whose
 * header does not have the style scrolling-header.
 *
 * A header over the stage (block style overlay-header) lies over a first cover only once its height is known, since it
 * moves the page up by that height. The module runs after the page is parsed, often after the first paint, and the
 * page would jump by the height of the header (layout shift). So such a header gets a small inline script right after
 * it that sets the height before anything below the header is laid out; the module keeps it up to date from there.
 */
final class Sticky_Header {

	public const MODULE = '@leitvia/sticky-header';

	/**
	 * Sets the height of the header as the module does (src/sticky-header/view.js), synchronously after the header.
	 */
	public const MEASURE = '(()=>{const h=document.querySelector(".wp-site-blocks>header");h&&document.documentElement.style.setProperty("--leitvia-sticky-header-height",Math.ceil(h.getBoundingClientRect().height)+"px")})();';

	/**
	 * Registers the script module. Runs on init.
	 */
	public static function register_module(): void {
		$asset_file = dirname( LEITVIA_STUDIO_FILE ) . '/build/sticky-header/view.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_register_script_module( self::MODULE, plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'build/sticky-header/view.js', $asset['dependencies'], $asset['version'] );
	}

	/**
	 * Runs on render_block_core/group: a group with a sticky header style loads the module.
	 *
	 * @param string               $content Rendered block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function render( string $content, array $block ): string {
		if ( preg_match( '/(?:^|\s)is-style-sticky-header(?:-desktop|-mobile)?(?:\s|$)/', (string) ( $block['attrs']['className'] ?? '' ) ) ) {
			wp_enqueue_script_module( self::MODULE );
		}
		return $content;
	}

	/**
	 * Runs on render_block_core/template-part: in a theme whose header sticks by default, a header part without the
	 * style scrolling-header loads the module.
	 *
	 * @param string               $content Rendered block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function render_part( string $content, array $block ): string {
		if ( 'header' === ( $block['attrs']['tagName'] ?? '' ) && current_theme_supports( 'leitvia-sticky-header' ) && ! preg_match( '/class="[^"]*(?<![\w-])is-style-scrolling-header(?![\w-])/', $content ) ) {
			wp_enqueue_script_module( self::MODULE );
		}
		if ( 'header' === ( $block['attrs']['tagName'] ?? '' ) && preg_match( '/class="[^"]*(?<![\w-])is-style-overlay-header(?![\w-])/', $content ) ) {
			$content .= wp_get_inline_script_tag( self::MEASURE );
		}
		return $content;
	}
}
