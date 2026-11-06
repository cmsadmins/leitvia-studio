<?php
/**
 * Title, description, robots and canonical in the head without an SEO plugin.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\News;

use LeitviaStudio\Gateway\Abilities\Seo;
use WP_Post;
use WP_Term;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Prints what leitvia/update-seo stored for a page (Seo::PLUGINS entry leitvia), so the title of search results can
 * differ from the heading of the page, plus the canonical address of archives, which core prints for single pages
 * only. All of it steps back when an SEO plugin is active. Pages without stored values keep what WordPress prints.
 */
final class Head {

	/**
	 * Stored value of the current page, '' when there is none or an SEO plugin takes care of it.
	 *
	 * @param string $field Field of the Seo::PLUGINS entry leitvia.
	 */
	private static function stored( string $field ): string {
		$object = get_queried_object();
		if ( 'leitvia' !== Seo::plugin() || ! $object instanceof WP_Post ) {
			return '';
		}
		return (string) get_post_meta( $object->ID, Seo::PLUGINS['leitvia']['meta'][ $field ], true );
	}

	/**
	 * The stored title as written. Filter pre_get_document_title.
	 *
	 * @param string $title Title of an earlier filter.
	 */
	public static function title( $title ): string {
		$stored = self::stored( 'title' );
		return '' !== $stored ? $stored : (string) $title;
	}

	/**
	 * Stored robots flags. Filter wp_robots.
	 *
	 * @param array<string, mixed> $robots Directives.
	 * @return array<string, mixed>
	 */
	public static function robots( $robots ): array {
		$robots = (array) $robots;
		foreach ( array( 'noindex', 'nofollow' ) as $flag ) {
			if ( '' !== self::stored( $flag ) ) {
				$robots[ $flag ] = true;
			}
		}
		return $robots;
	}

	/**
	 * Canonical address of an archive with its page number, '' on single pages, where core prints it, and on search
	 * and date archives, which list no fixed set of posts.
	 */
	public static function canonical(): string {
		global $wp_rewrite;
		$object = get_queried_object();
		$page   = (int) get_option( 'page_for_posts' );
		$url    = match ( true ) {
			is_home() && $page         => get_permalink( $page ),
			is_home()                  => home_url( '/' ),
			$object instanceof WP_Term => get_term_link( $object ),
			$object instanceof WP_User => get_author_posts_url( $object->ID ),
			is_post_type_archive()     => get_post_type_archive_link( (string) ( $object->name ?? '' ) ),
			default                    => '',
		};
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$number = (int) get_query_var( 'paged' );
		if ( $number < 2 ) {
			return $url;
		}
		return $wp_rewrite->using_permalinks() ? user_trailingslashit( trailingslashit( $url ) . $wp_rewrite->pagination_base . '/' . $number, 'paged' ) : add_query_arg( 'paged', $number, $url );
	}

	/**
	 * Prints description and canonical address. Runs on wp_head.
	 */
	public static function print(): void {
		if ( 'leitvia' !== Seo::plugin() ) {
			return;
		}
		$description = self::stored( 'description' );
		if ( '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		$canonical = self::canonical();
		if ( '' !== $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}
	}
}
