<?php
/**
 * Lightbox focus: keeps the keyboard focus inside the lightbox of core images.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Core's lightbox ("Enlarge on click") traps Tab between its close, previous and next buttons. A single image hides
 * previous and next, and Tab from "Close" then left the modal dialog for the page behind it. The script module of
 * this class cycles over the buttons that are shown; it loads only on pages with a lightbox.
 */
final class Lightbox_Focus {

	public const MODULE = '@leitvia/lightbox-focus';

	/**
	 * Registers the script module. Runs on init.
	 */
	public static function register_module(): void {
		$asset_file = dirname( LEITVIA_STUDIO_FILE ) . '/build/lightbox-focus/view.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_register_script_module( self::MODULE, plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'build/lightbox-focus/view.js', $asset['dependencies'], $asset['version'] );
	}

	/**
	 * Runs on render_block_core/image, after core added the lightbox (priority 15).
	 *
	 * @param string $content Rendered block HTML.
	 */
	public static function render( string $content ): string {
		if ( str_contains( $content, 'wp-lightbox-container' ) ) {
			wp_enqueue_script_module( self::MODULE );
		}
		return $content;
	}
}
