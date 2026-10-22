<?php
/**
 * Easy language: the relation between a page and its version in easy language, the language switch and the
 * accessibility bar.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Easy_Language;

use LeitviaStudio\Tokens\Contrast;

defined( 'ABSPATH' ) || exit;

/**
 * A page in easy language is a normal page whose meta _leitvia_easy_language_of holds the ID of the original. One meta
 * links both ways: the original finds its version by searching for that value. Easy language is no language of its
 * own in the sense of hreflang, so nothing is added to the head and the lang attribute stays the site's.
 */
final class Easy_Language {

	public const META = '_leitvia_easy_language_of';

	/**
	 * Registers the meta for pages, editable in the block editor by everyone who may edit the page. Runs on init.
	 */
	public static function register(): void {
		register_post_meta(
			'page',
			self::META,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id ),
			)
		);
	}

	/**
	 * The published counterpart of a page: the original for a page in easy language, otherwise the first published
	 * page in easy language that points to it.
	 *
	 * @param int $post_id Page ID.
	 * @return array{post: \WP_Post, easy: bool}|null The counterpart and whether it is the easy language version.
	 */
	public static function counterpart( int $post_id ): ?array {
		if ( $post_id <= 0 || 'page' !== get_post_type( $post_id ) ) {
			return null;
		}
		$original = (int) get_post_meta( $post_id, self::META, true );
		if ( $original > 0 ) {
			$post = $original === $post_id ? null : get_post( $original );
			return $post && 'page' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password ? array(
				'post' => $post,
				'easy' => false,
			) : null;
		}
		$found = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup per rendered switch.
				'meta_value'       => (string) $post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				'suppress_filters' => false,
			)
		);
		return $found ? array(
			'post' => $found[0],
			'easy' => true,
		) : null;
	}

	/**
	 * Front end of leitvia/language-switch: a link to the counterpart of the shown page, nothing without one. Takes
	 * the post from the block context (inside the content) or the queried page (in a template part such as the header).
	 *
	 * @param \WP_Block $block Block instance.
	 */
	public static function render_switch( \WP_Block $block ): string {
		$post_id = (int) ( $block->context['postId'] ?? 0 );
		if ( ! $post_id && is_singular( 'page' ) ) {
			$post_id = get_queried_object_id();
		}
		$counterpart = self::counterpart( $post_id );
		if ( null === $counterpart ) {
			return '';
		}
		// Book with a speech bubble, drawn for this block; the text names the link.
		$icon = '<svg class="wp-block-leitvia-language-switch__icon" aria-hidden="true" focusable="false" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5.5h6.5a2.5 2.5 0 0 1 2.5 2.5v11a2 2 0 0 0-2-2H3z"/><path d="M21 5.5h-6.5A2.5 2.5 0 0 0 12 8v11a2 2 0 0 1 2-2h7z"/></svg>';
		return sprintf(
			'<a %1$s href="%2$s">%3$s<span>%4$s</span></a>',
			get_block_wrapper_attributes(),
			esc_url( get_permalink( $counterpart['post'] ) ),
			$icon,
			$counterpart['easy'] ? esc_html__( 'Easy language', 'leitvia-studio' ) : esc_html__( 'Standard language', 'leitvia-studio' )
		);
	}

	/**
	 * Adds to the saved accessibility bar what only works with JavaScript: text size, high contrast and read aloud,
	 * hidden until the view module shows them. An inline script before the bar applies the stored settings before the
	 * first paint, so a reload does not shift the layout. Runs on render_block_leitvia/a11y-bar.
	 *
	 * @param string $html Saved block markup.
	 */
	public static function render_bar( string $html ): string {
		$end = strrpos( $html, '</nav>' );
		if ( false === $end ) {
			return $html;
		}
		$css = self::contrast_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'leitvia-a11y-bar-style', $css );
		}
		$button   = static fn( string $action, string $text, string $extra = '' ) => sprintf( '<button type="button" data-action="%s"%s>%s</button>', esc_attr( $action ), $extra, $text );
		$controls = '<div class="wp-block-leitvia-a11y-bar__controls" hidden>'
			. '<div class="wp-block-leitvia-a11y-bar__size" role="group" aria-label="' . esc_attr__( 'Text size', 'leitvia-studio' ) . '">'
			. $button( 'smaller', 'A&minus;<span class="screen-reader-text"> ' . esc_html__( 'Decrease text size', 'leitvia-studio' ) . '</span>' )
			. '<span class="wp-block-leitvia-a11y-bar__value" role="status">100&nbsp;%</span>'
			. $button( 'larger', 'A+<span class="screen-reader-text"> ' . esc_html__( 'Increase text size', 'leitvia-studio' ) . '</span>' )
			. $button( 'reset', esc_html__( 'Reset', 'leitvia-studio' ) . '<span class="screen-reader-text"> ' . esc_html__( 'text size', 'leitvia-studio' ) . '</span>' )
			. '</div>'
			. ( '' === $css ? '' : $button( 'contrast', esc_html__( 'High contrast', 'leitvia-studio' ), ' aria-pressed="false"' ) )
			. $button( 'read', esc_html__( 'Read aloud', 'leitvia-studio' ), sprintf( ' hidden data-stop="%s"', esc_attr__( 'Stop reading', 'leitvia-studio' ) ) )
			. '</div>';
		$html     = substr_replace( $html, $controls, $end, 0 );
		// No & or < in the script: the content filters of a post would turn them into entities.
		$script = 'try{var s=JSON.parse(localStorage.getItem("leitvia-a11y"))||{},d=document.documentElement,z=Math.max(100,Math.min(200,+s.size||100));d.classList.add("leitvia-a11y-js");if(z-100)d.style.fontSize=z+"%";if(s.contrast)d.classList.add("leitvia-high-contrast")}catch(e){}';
		return wp_get_inline_script_tag( $script ) . $html;
	}

	/**
	 * CSS for html.leitvia-high-contrast: every color preset becomes ink or surface of the style variation whose ink and
	 * surface are furthest apart. Backgrounds take surface, text, lines and the focus ring ink. Empty when the theme has
	 * no ink and surface presets.
	 */
	public static function contrast_css(): string {
		$best = null;
		foreach ( self::palettes() as $palette ) {
			$colors = array_column( $palette, 'color', 'slug' );
			if ( ! isset( $colors['ink'], $colors['surface'] ) ) {
				continue;
			}
			$ratio = Contrast::ratio( (string) $colors['ink'], (string) $colors['surface'] );
			if ( ! is_wp_error( $ratio ) && ( null === $best || $ratio > $best['ratio'] ) ) {
				$best = array(
					'ratio'   => $ratio,
					// Colors come from theme files; keep only what a color value needs.
					'ink'     => (string) preg_replace( '/[^\w#%(),.\s-]/', '', (string) $colors['ink'] ),
					'surface' => (string) preg_replace( '/[^\w#%(),.\s-]/', '', (string) $colors['surface'] ),
					'slugs'   => array_keys( $colors ),
				);
			}
		}
		if ( null === $best ) {
			return '';
		}
		$surface = array( 'surface', 'surface-alt', 'on-accent' );
		$rules   = array();
		foreach ( $best['slugs'] as $slug ) {
			$slug    = sanitize_key( (string) $slug );
			$value   = in_array( $slug, $surface, true ) ? $best['surface'] : $best['ink'];
			$rules[] = '--wp--preset--color--' . $slug . ':' . $value;
		}
		return 'html.leitvia-high-contrast{' . implode( ';', $rules ) . '}';
	}

	/**
	 * Color palettes of the theme and its style variations.
	 *
	 * @return list<array<int, array<string, mixed>>>
	 */
	private static function palettes(): array {
		$palettes = array();
		$sources  = array_merge( array( \WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data() ), \WP_Theme_JSON_Resolver::get_style_variations() );
		foreach ( $sources as $data ) {
			$palette = $data['settings']['color']['palette']['theme'] ?? $data['settings']['color']['palette'] ?? null;
			if ( is_array( $palette ) && $palette ) {
				$palettes[] = $palette;
			}
		}
		return $palettes;
	}
}
