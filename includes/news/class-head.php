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
 *
 * With the setting title_site_name (leitvia/set-seo-titles, on by default) every title but the one of the front page
 * ends with " | " and the site name, stored or built by WordPress, on pages, posts, entries and archives alike, as long
 * as the whole title keeps to Seo::TITLE_MAX characters and does not name the site already; otherwise the title stands
 * alone. Off, a stored title stands as written and WordPress builds the others with its own separator.
 */
final class Head {

	/**
	 * Separator between the title and the site name.
	 */
	public const SEPARATOR = '|';

	/**
	 * Whether titles take the site name: setting title_site_name, no SEO plugin, not the front page.
	 */
	private static function site_name_on(): bool {
		return leitvia_studio_setting( 'title_site_name' ) && 'leitvia' === Seo::plugin() && ! is_front_page();
	}

	/**
	 * A title with " | " and the site name when the whole fits Seo::TITLE_MAX and the title does not name the site.
	 *
	 * @param string $title Title without the site name.
	 */
	public static function with_site_name( string $title ): string {
		$site = html_entity_decode( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' );
		$full = $title . ' ' . self::SEPARATOR . ' ' . $site;
		return '' === $site || '' === $title || false !== mb_stripos( $title, $site ) || mb_strlen( $full ) > Seo::TITLE_MAX ? $title : $full;
	}

	/**
	 * The separator of the titles WordPress builds while titles take the site name. Filter document_title_separator.
	 *
	 * @param mixed $separator Separator.
	 */
	public static function separator( $separator ): string {
		return self::site_name_on() ? self::SEPARATOR : (string) $separator;
	}

	/**
	 * The parts of a title WordPress builds: the site name stays only where with_site_name() would add it. Filter
	 * document_title_parts.
	 *
	 * @param mixed $parts Title parts: title, page, tagline, site.
	 * @return mixed
	 */
	public static function parts( $parts ) {
		if ( ! is_array( $parts ) || ! self::site_name_on() || ! isset( $parts['site'] ) ) {
			return $parts;
		}
		$title = html_entity_decode( wp_strip_all_tags( implode( ' ' . self::SEPARATOR . ' ', array_filter( array( $parts['title'] ?? '', $parts['page'] ?? '' ) ) ) ), ENT_QUOTES, 'UTF-8' );
		if ( self::with_site_name( $title ) === $title ) {
			unset( $parts['site'] );
		}
		return $parts;
	}

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
		if ( '' === $stored ) {
			return (string) $title;
		}
		return self::site_name_on() ? self::with_site_name( $stored ) : $stored;
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
