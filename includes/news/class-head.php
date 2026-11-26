<?php
/**
 * Title, description, robots, canonical and Open Graph in the head without an SEO plugin.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\News;

use LeitviaStudio\Gateway\Abilities\Seo;
use LeitviaStudio\Gateway\Outline;
use WP_Post;
use WP_Post_Type;
use WP_Term;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Prints what leitvia/update-seo stored for a page (Seo::PLUGINS entry leitvia), so the title of search results can
 * differ from the heading of the page, plus the canonical address of archives, which core prints for single pages
 * only, and the Open Graph and Twitter Card tags of shared links (social()). All of it steps back when an SEO plugin is active. Pages without stored values keep what WordPress prints.
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
	 * The separator of the titles WordPress builds while titles take the site name, the front page included (name and
	 * tagline). Filter document_title_separator.
	 *
	 * @param mixed $separator Separator.
	 */
	public static function separator( $separator ): string {
		// The front page joins site name and tagline with the same separator, so it reads "Name | Tagline".
		return leitvia_studio_setting( 'title_site_name' ) && 'leitvia' === Seo::plugin() ? self::SEPARATOR : (string) $separator;
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
	 * Stored value of the current page or archive of a content type (update-seo with archive), '' when there is none or
	 * an SEO plugin takes care of it.
	 *
	 * @param string $field Field of the Seo::PLUGINS entry leitvia.
	 */
	private static function stored( string $field ): string {
		$object = get_queried_object();
		if ( 'leitvia' !== Seo::plugin() ) {
			return '';
		}
		if ( $object instanceof WP_Post_Type && is_post_type_archive() ) {
			$archives = get_option( Seo::ARCHIVE_OPTION );
			return is_array( $archives ) ? (string) ( $archives[ $object->name ][ $field ] ?? '' ) : '';
		}
		if ( ! $object instanceof WP_Post ) {
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
	 * Open Graph and Twitter Card tags of the current request as pairs of property and content, without an SEO plugin:
	 * og:type (article on posts and article pages, else website), og:title (og_title of update-seo, else the SEO
	 * title, else the title of the post or the document title), og:description (og_description, else the meta
	 * description, else one from the excerpt or the tagline), og:url (the canonical address), og:site_name, og:locale,
	 * og:image with its size and alternative text (image()), the dates of an article and twitter:card. None on search
	 * results, 404 pages and with the filter leitvia_studio_open_graph false.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public static function social(): array {
		/**
		 * Whether Leitvia Studio prints Open Graph and Twitter Card tags while no SEO plugin is active. Return false when
		 * another plugin prints them.
		 *
		 * @param bool $print Default true.
		 */
		if ( 'leitvia' !== Seo::plugin() || is_search() || is_404() || ! apply_filters( 'leitvia_studio_open_graph', true ) ) {
			return array();
		}
		$object  = get_queried_object();
		$post    = is_singular() && $object instanceof WP_Post ? $object : null;
		$article = $post && ( 'post' === $post->post_type || Schema::is_article_page( $post ) );
		$title   = self::first( self::stored( 'og_title' ), self::stored( 'title' ), $post && ! is_front_page() ? Schema::text( get_the_title( $post ) ) : Outline::decoded( wp_get_document_title() ) );
		$text    = self::first( self::stored( 'og_description' ), self::stored( 'description' ), $post ? Seo::suggested_description( $post ) : '', Outline::decoded( (string) get_bloginfo( 'description' ) ) );
		$url     = $post ? (string) wp_get_canonical_url( $post ) : self::canonical();
		$locale  = $post ? Schema::locale( $post ) : get_locale();
		$tags    = array(
			array( 'og:type', $article ? 'article' : 'website' ),
			array( 'og:title', $title ),
			array( 'og:description', $text ),
			array( 'og:url', $url ),
			array( 'og:site_name', Outline::decoded( (string) get_bloginfo( 'name' ) ) ),
			array( 'og:locale', $locale ),
		);
		$image   = self::image( $post );
		if ( $image ) {
			$tags[] = array( 'og:image', $image['url'] );
			$tags[] = array( 'og:image:width', (string) $image['width'] );
			$tags[] = array( 'og:image:height', (string) $image['height'] );
			$tags[] = array( 'og:image:alt', $image['alt'] );
		}
		if ( $article ) {
			$tags[] = array( 'article:published_time', (string) get_the_date( 'c', $post ) );
			$tags[] = array( 'article:modified_time', (string) get_the_modified_date( 'c', $post ) );
		}
		$tags[] = array( 'twitter:card', $image ? 'summary_large_image' : 'summary' );
		return array_values( array_filter( $tags, static fn( array $tag ): bool => '' !== $tag[1] ) );
	}

	/**
	 * The first text that is not empty, '' when all are.
	 *
	 * @param string ...$texts Candidates in order.
	 */
	private static function first( string ...$texts ): string {
		return (string) current( array_filter( array_map( 'trim', $texts ), static fn( string $text ): bool => '' !== $text ) );
	}

	/**
	 * Image of the preview: og_image of update-seo, else the featured image of the post (other than the logo), else
	 * the logo when it is a raster image, else the site icon; in the 16:9 crop of News\Image_Sizes where there is one.
	 * null without any.
	 *
	 * @param WP_Post|null $post Post of the request.
	 * @return array{url: string, width: int, height: int, alt: string}|null
	 */
	public static function image( ?WP_Post $post ): ?array {
		$logo       = (int) get_theme_mod( 'custom_logo' );
		$featured   = $post ? (int) get_post_thumbnail_id( $post ) : 0;
		$candidates = array( (int) self::stored( 'og_image' ), $featured !== $logo ? $featured : 0, $logo, (int) get_option( 'site_icon' ) );
		foreach ( $candidates as $id ) {
			$src = $id && wp_attachment_is_image( $id ) && 'image/svg+xml' !== get_post_mime_type( $id ) ? wp_get_attachment_image_src( $id, 'leitvia-16x9' ) : false;
			if ( $src ) {
				return array(
					'url'    => (string) $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'alt'    => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
				);
			}
		}
		return null;
	}

	/**
	 * Prints description, canonical address and the tags of social(). Runs on wp_head.
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
		foreach ( self::social() as list( $property, $content ) ) {
			// Open Graph names its tags with property, Twitter Cards with name.
			$attribute = str_starts_with( $property, 'twitter:' ) ? 'name' : 'property';
			$value     = in_array( $property, array( 'og:url', 'og:image' ), true ) ? esc_url( $content ) : esc_attr( $content );
			echo '<meta ' . $attribute . '="' . esc_attr( $property ) . '" content="' . $value . '" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}
	}
}
