<?php
/**
 * What Leitvia Studio adds beside and below a post in the theme Leitvia.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\News;

use LeitviaStudio\Seo\Cluster;

defined( 'ABSPATH' ) || exit;

/**
 * The template single of the theme Leitvia asks plugins for blocks through three filters: leitvia_post_aside (top of
 * the sidebar, before the details of the post), leitvia_post_aside_end (end of the sidebar) and leitvia_post_end (end of
 * the post, above the author box). Studio puts the table of contents ("On this page") at the top of the sidebar, where
 * it renders before the content and so gives the headings their ids (Blocks\Table_Of_Contents), and a card for the
 * service the post is about: the hub of the post (leitvia/set-page-structure hub), in the sidebar on large screens and
 * at the end of the post on small ones (classes leitvia-wide-only, leitvia-narrow-only of the theme). Without a hub
 * there is no card.
 */
final class Aside {

	/**
	 * Table of contents at the top of the sidebar. Runs on leitvia_post_aside.
	 *
	 * @param mixed $blocks Block markup of earlier filters.
	 */
	public static function top( $blocks ): string {
		return (string) $blocks . '<!-- wp:leitvia/table-of-contents /-->';
	}

	/**
	 * Card of the service at the end of the sidebar. Runs on leitvia_post_aside_end.
	 *
	 * @param mixed $blocks Block markup of earlier filters.
	 */
	public static function end( $blocks ): string {
		return (string) $blocks . self::card( 'leitvia-wide-only' );
	}

	/**
	 * Card of the service at the end of the post. Runs on leitvia_post_end.
	 *
	 * @param mixed $blocks Block markup of earlier filters.
	 */
	public static function post_end( $blocks ): string {
		return (string) $blocks . self::card( 'leitvia-narrow-only' );
	}

	/**
	 * Card for the hub of the current post: eyebrow, its title, the description of its service or its excerpt and a
	 * button to it; '' without a published hub.
	 *
	 * @param string $visibility Class that shows the card on large or small screens.
	 */
	public static function card( string $visibility ): string {
		$post = get_post();
		$hub  = $post && 'post' === $post->post_type ? (int) get_post_meta( $post->ID, Cluster::HUB_META, true ) : 0;
		if ( ! $hub || 'page' !== get_post_type( $hub ) || 'publish' !== get_post_status( $hub ) ) {
			return '';
		}
		$service = Cluster::service( $hub );
		$text    = Schema::text( (string) ( $service['description'] ?? '' ) );
		$text    = '' !== $text ? $text : Schema::text( get_the_excerpt( $hub ) );
		$name    = Schema::text( (string) ( $service['name'] ?? get_the_title( $hub ) ) );
		$class   = 'is-style-card ' . $visibility;
		return '<!-- wp:group {"className":"' . esc_attr( $class ) . '","layout":{"type":"default"}} -->'
			. '<div class="wp-block-group ' . esc_attr( $class ) . '">'
			. '<!-- wp:paragraph {"className":"is-style-eyebrow"} --><p class="is-style-eyebrow">' . esc_html__( 'Related service', 'leitvia-studio' ) . '</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph {"fontSize":"lead"} --><p class="has-lead-font-size">' . esc_html( $name ) . '</p><!-- /wp:paragraph -->'
			. ( '' !== $text ? '<!-- wp:paragraph {"textColor":"ink-muted"} --><p class="has-ink-muted-color has-text-color">' . esc_html( wp_trim_words( $text, 30, '…' ) ) . '</p><!-- /wp:paragraph -->' : '' )
			. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( (string) get_permalink( $hub ) ) . '">' . esc_html__( 'See the service', 'leitvia-studio' ) . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
			. '</div><!-- /wp:group -->';
	}
}
