<?php
/**
 * Announcement bar: dismiss button, content hash and the inline script that keeps a dismissed bar hidden.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Adds to the saved bar what only works with Studio: the dismiss button (hidden until the view module shows it, named
 * by its label, a drawn cross inside) and a hash of the saved markup. An inline script in the head (Blocks::head_script()) sets data-leitvia-announcement on <html> when the
 * visitor dismissed this content before, so the CSS hides the bar before the first paint. New content, new hash.
 */
final class Announcement_Bar {

	/**
	 * Runs on render_block_leitvia/announcement-bar.
	 *
	 * @param string $html Saved block markup.
	 * @return string
	 */
	public static function render( string $html ): string {
		$hash = substr( md5( $html ), 0, 12 );
		$tags = new \WP_HTML_Tag_Processor( $html );
		// The outer tag is a section; bars saved before version 0.2.0 of the block are an aside.
		if ( ! $tags->next_tag() || ! in_array( $tags->get_tag(), array( 'SECTION', 'ASIDE' ), true ) ) {
			return $html;
		}
		$tag = strtolower( (string) $tags->get_tag() );
		$tags->set_attribute( 'data-hash', $hash );
		$html   = $tags->get_updated_html();
		$button = sprintf(
			'<button type="button" class="wp-block-leitvia-announcement-bar__dismiss" aria-label="%s" hidden><svg viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M2 2l8 8M10 2l-8 8"></path></svg></button>',
			esc_attr__( 'Dismiss announcement', 'leitvia-studio' )
		);
		$end    = strrpos( $html, '</' . $tag . '>' );
		$html   = false === $end ? $html : substr_replace( $html, $button, $end, 0 );
		$script = sprintf(
			'try{localStorage.getItem("leitvia-announcement")==="%s"&&document.documentElement.setAttribute("data-leitvia-announcement","dismissed")}catch(e){}',
			$hash
		);
		Blocks::head_script( 'leitvia-announcement-bar-state', $script );
		return $html;
	}
}
