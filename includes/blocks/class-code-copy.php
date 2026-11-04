<?php
/**
 * Code copy: a "Copy code" button for core/code, shown once the script module runs.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the code block with a hidden button and a live region. The script module @leitvia/code-copy loads only on
 * pages with a code block, shows the button and copies the text of <code>. Without scripting nothing changes for the
 * visitor: no button, the code stays selectable. The wrapper takes over the alignment class, so wide code stays wide.
 */
final class Code_Copy {

	public const MODULE = '@leitvia/code-copy';

	/**
	 * Registers the script module. Runs on init.
	 */
	public static function register_module(): void {
		$asset_file = dirname( LEITVIA_STUDIO_FILE ) . '/build/code-copy/view.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_register_script_module( self::MODULE, plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'build/code-copy/view.js', $asset['dependencies'], $asset['version'] );
		wp_register_style( 'leitvia-code-copy', plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'assets/code-copy.css', array(), LEITVIA_STUDIO_VERSION );
	}

	/**
	 * Runs on render_block_core/code. A code block with a name (metadata.name, "Rename" in the editor) gives its button
	 * that name after the visible text, so a page with many code blocks has one distinct button name per block and the
	 * name still starts with the visible label (WCAG 2.5.3).
	 *
	 * @param string               $content Rendered block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function render( string $content, array $block = array() ): string {
		$tags = new \WP_HTML_Tag_Processor( $content );
		if ( is_admin() || ! $tags->next_tag( array( 'tag_name' => 'pre' ) ) ) {
			return $content;
		}
		preg_match( '/\balign(?:wide|full)\b/', (string) $tags->get_attribute( 'class' ), $align );
		wp_enqueue_script_module( self::MODULE );
		wp_enqueue_style( 'leitvia-code-copy' );
		$name = trim( (string) ( $block['attrs']['metadata']['name'] ?? '' ) );
		return sprintf(
			'<div class="%1$s">%2$s<div class="leitvia-code-copy__bar"><span class="leitvia-code-copy__status" role="status" data-copied="%3$s" data-failed="%4$s"></span><button type="button" class="leitvia-code-copy__button"%6$s hidden>%5$s</button></div></div>',
			esc_attr( trim( 'leitvia-code-copy ' . ( $align[0] ?? '' ) ) ),
			$content,
			esc_attr__( 'Copied', 'leitvia-studio' ),
			esc_attr__( 'Copy failed, select the code and copy it manually', 'leitvia-studio' ),
			esc_html__( 'Copy code', 'leitvia-studio' ),
			/* translators: %s: name of the code block, such as the icon the code inserts. */
			'' === $name ? '' : sprintf( ' aria-label="%s"', esc_attr( sprintf( __( 'Copy code: %s', 'leitvia-studio' ), $name ) ) )
		);
	}
}
