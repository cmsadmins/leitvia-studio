<?php
/**
 * JSON-LD for posts, author archives and the front page.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\News;

use WP_Post;
use WP_Term;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Prints one JSON-LD block in the head, always an @graph: on single posts the article (NewsArticle for news posts, see
 * is_news(), else BlogPosting) with author and publisher plus a BreadcrumbList; on the posts page and on category and
 * tag archives a CollectionPage listing the posts of the page, on the archives with a BreadcrumbList; a ProfilePage on
 * author archives; a WebSite with the site as publisher on the front page (site name and logo in search results). Off
 * with the setting news_schema and whenever an SEO plugin is active, so a page never carries two article nodes. No
 * Open Graph tags: that stays the job of SEO plugins. The news sitemap is Sitemap.
 */
final class Schema {

	/**
	 * Smallest publisher logo, width and height in pixels.
	 */
	public const LOGO_MIN = 112;

	/**
	 * Smallest width of an original that stands in for missing crops, the width of the crops in Image_Sizes.
	 */
	public const IMAGE_MIN_WIDTH = 1200;

	/**
	 * Prints the JSON-LD for the current request. Runs on wp_head.
	 */
	public static function print(): void {
		$data = self::current();
		if ( $data ) {
			// JSON_HEX_TAG writes < and > as unicode escapes, so no title can close the script element.
			wp_print_inline_script_tag( (string) wp_json_encode( $data, JSON_HEX_TAG ), array( 'type' => 'application/ld+json' ) );
		}
	}

	/**
	 * Structured data for the current request as one @graph, null when there is none to print.
	 *
	 * @return array{"@context": string, "@graph": list<array<string, mixed>>}|null
	 */
	public static function current(): ?array {
		if ( ! leitvia_studio_setting( 'news_schema' ) || leitvia_studio_seo_plugin_active() ) {
			return null;
		}
		$object = get_queried_object();
		$nodes  = array();
		if ( is_front_page() ) {
			$nodes = array( self::website() );
		} elseif ( is_singular( 'post' ) && $object instanceof WP_Post ) {
			$nodes = array( self::article( $object ), self::breadcrumbs( $object ) );
		} elseif ( is_author() && $object instanceof WP_User ) {
			$nodes = array( self::profile_page( $object ) );
		} elseif ( is_home() ) {
			$nodes = array( self::collection_page( null ) );
		} elseif ( ( is_category() || is_tag() ) && $object instanceof WP_Term ) {
			$nodes = array( self::collection_page( $object ), self::breadcrumbs( $object ) );
		}
		return $nodes ? array(
			'@context' => 'https://schema.org',
			'@graph'   => $nodes,
		) : null;
	}

	/**
	 * Whether a post is news: a post (post type post) in one of the categories of the setting news_categories, or any
	 * post while that setting is empty.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function is_news( WP_Post $post ): bool {
		if ( 'post' !== $post->post_type ) {
			return false;
		}
		/**
		 * Categories whose posts are news articles (NewsArticle instead of BlogPosting, entries of the news sitemap).
		 * Empty makes every post news.
		 *
		 * @param array<int|string> $categories Category IDs, slugs or names. Default the setting news_categories.
		 */
		$news = (array) apply_filters( 'leitvia_studio_news_categories', (array) leitvia_studio_setting( 'news_categories' ) );
		return ! $news || has_category( $news, $post );
	}

	/**
	 * NewsArticle for news posts (is_news()), else BlogPosting.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public static function article( WP_Post $post ): array {
		$url  = (string) get_permalink( $post );
		$data = array(
			'@type'               => self::is_news( $post ) ? 'NewsArticle' : 'BlogPosting',
			'headline'            => self::text( get_the_title( $post ) ),
			'url'                 => $url,
			'mainEntityOfPage'    => array(
				'@type' => 'WebPage',
				'@id'   => $url,
			),
			// The same calls as the datetime attribute of the post date block, ISO 8601 with the site's offset.
			'datePublished'       => get_the_date( 'c', $post ),
			'dateModified'        => get_the_modified_date( 'c', $post ),
			'inLanguage'          => self::language( self::locale( $post ) ),
			'isAccessibleForFree' => true,
			'author'              => array( self::person( (int) $post->post_author ) ),
			'publisher'           => self::organization(),
		);
		$excerpt = self::text( $post->post_excerpt );
		if ( '' !== $excerpt ) {
			$data['description'] = $excerpt;
		}
		$section = self::section( $post );
		if ( $section ) {
			$data['articleSection'] = self::text( $section->name );
		}
		$thumbnail = (int) get_post_thumbnail_id( $post );
		$thumbnail = (int) get_theme_mod( 'custom_logo' ) !== $thumbnail ? $thumbnail : 0;
		$images    = $thumbnail ? Image_Sizes::urls( $thumbnail ) : array();
		// An image uploaded before Studio registered its crops has none of them: the original stands in when it is at
		// least as wide as the crops.
		$original = $thumbnail && ! $images ? wp_get_attachment_image_src( $thumbnail, 'full' ) : false;
		if ( $original && $original[1] >= self::IMAGE_MIN_WIDTH ) {
			$images = array( $original[0] );
		}
		if ( $images ) {
			$data['image'] = $images;
		}
		return $data;
	}

	/**
	 * CollectionPage of the posts page or a category or tag archive: title, address, language and the posts of the main
	 * query in their order.
	 *
	 * @param WP_Term|null $term Category or tag of the archive, null for the posts page.
	 * @return array<string, mixed>
	 */
	public static function collection_page( ?WP_Term $term ): array {
		global $wp_query;
		$page  = (int) get_option( 'page_for_posts' );
		$items = array();
		foreach ( array_values( (array) ( $wp_query->posts ?? array() ) ) as $index => $post ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'url'      => (string) get_permalink( $post ),
			);
		}
		return array(
			'@type'      => 'CollectionPage',
			'name'       => self::text( $term ? $term->name : ( $page ? get_the_title( $page ) : get_bloginfo( 'name' ) ) ),
			'url'        => $term ? self::term_link( $term ) : ( $page ? (string) get_permalink( $page ) : home_url( '/' ) ),
			'inLanguage' => self::language( get_locale() ),
			'mainEntity' => array(
				'@type'           => 'ItemList',
				'itemListElement' => $items,
			),
		);
	}

	/**
	 * BreadcrumbList of a post or a category or tag archive: start page, posts page when there is one, the category of
	 * the post (as in articleSection) or the archive term, then the post.
	 *
	 * @param WP_Post|WP_Term $target Post or term.
	 * @return array<string, mixed>
	 */
	public static function breadcrumbs( WP_Post|WP_Term $target ): array {
		$trail = array( array( self::text( get_bloginfo( 'name' ) ), home_url( '/' ) ) );
		$page  = (int) get_option( 'page_for_posts' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			$trail[] = array( self::text( get_the_title( $page ) ), (string) get_permalink( $page ) );
		}
		$term = $target instanceof WP_Post ? self::section( $target ) : $target;
		if ( $term ) {
			$trail[] = array( self::text( $term->name ), self::term_link( $term ) );
		}
		if ( $target instanceof WP_Post ) {
			$trail[] = array( self::text( get_the_title( $target ) ), (string) get_permalink( $target ) );
		}
		$items = array();
		foreach ( $trail as $index => list( $name, $url ) ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $name,
				'item'     => $url,
			);
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}

	/**
	 * Address of the page of the setting editorial_page (its translation under Polylang), '' when none is set or it is
	 * not published. The theme reads it through the filter leitvia_editorial_url.
	 */
	public static function editorial_url(): string {
		$page       = (int) leitvia_studio_setting( 'editorial_page' );
		$translated = $page && function_exists( 'pll_get_post' ) ? (int) pll_get_post( $page ) : 0;
		$page       = $translated ? $translated : $page;
		return $page && 'publish' === get_post_status( $page ) ? (string) get_permalink( $page ) : '';
	}

	/**
	 * Locale of a post: its Polylang language, else the site locale.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function locale( WP_Post $post ): string {
		$locale = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID, 'locale' ) : '';
		return is_string( $locale ) && '' !== $locale ? $locale : get_locale();
	}

	/**
	 * BCP 47 tag of a WordPress locale: de_DE and de_DE_formal give de-DE, fr gives fr.
	 *
	 * @param string $locale WordPress locale.
	 */
	public static function language( string $locale ): string {
		$parts = explode( '_', $locale );
		return isset( $parts[1] ) && preg_match( '/^[A-Z]{2}$/', $parts[1] ) ? $parts[0] . '-' . $parts[1] : $parts[0];
	}

	/**
	 * Address of a term archive, '' when there is none.
	 *
	 * @param WP_Term $term Term.
	 */
	private static function term_link( WP_Term $term ): string {
		$link = get_term_link( $term );
		return is_string( $link ) ? $link : '';
	}

	/**
	 * Category that names the section of a post: its first category, none when that is only the default category.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function section( WP_Post $post ): ?WP_Term {
		$categories = get_the_category( $post->ID );
		if ( ! $categories || ( 1 === count( $categories ) && (int) get_option( 'default_category' ) === $categories[0]->term_id ) ) {
			return null;
		}
		return $categories[0];
	}

	/**
	 * ProfilePage of an author archive.
	 *
	 * @param WP_User $user Author.
	 * @return array<string, mixed>
	 */
	public static function profile_page( WP_User $user ): array {
		return array(
			'@type'      => 'ProfilePage',
			'mainEntity' => self::person( $user->ID ),
		);
	}

	/**
	 * WebSite of the front page: name, address, tagline when there is one, the site as publisher.
	 *
	 * @return array<string, mixed>
	 */
	public static function website(): array {
		$data        = array(
			'@type'     => 'WebSite',
			'name'      => self::text( get_bloginfo( 'name' ) ),
			'url'       => home_url( '/' ),
			'publisher' => self::organization(),
		);
		$description = self::text( get_bloginfo( 'description' ) );
		if ( '' !== $description ) {
			$data['description'] = $description;
		}
		return $data;
	}

	/**
	 * Person with name, author page and biography when there is one.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, string>
	 */
	private static function person( int $user_id ): array {
		$person      = array(
			'@type' => 'Person',
			'name'  => self::text( get_the_author_meta( 'display_name', $user_id ) ),
			'url'   => get_author_posts_url( $user_id ),
		);
		$description = self::text( get_the_author_meta( 'description', $user_id ) );
		if ( '' !== $description ) {
			$person['description'] = $description;
		}
		return $person;
	}

	/**
	 * The site as publisher with its editorial principles (editorial_url()) when there are some. Logo: the custom logo when that is a raster image of at least LOGO_MIN pixels in both
	 * directions, else the site icon in its 512 px size under the same rule, else none. An SVG has no pixel size and
	 * never qualifies.
	 *
	 * @return array<string, mixed>
	 */
	private static function organization(): array {
		$organization = array(
			'@type' => 'Organization',
			'name'  => self::text( get_bloginfo( 'name' ) ),
			'url'   => home_url( '/' ),
		);
		$principles   = self::editorial_url();
		if ( '' !== $principles ) {
			$organization['publishingPrinciples'] = $principles;
		}
		$icon_url = get_site_icon_url( 512 );
		$icon     = $icon_url ? wp_get_attachment_image_src( (int) get_option( 'site_icon' ), array( 512, 512 ) ) : false;
		if ( $icon ) {
			$icon[0] = $icon_url;
		}
		foreach ( array( wp_get_attachment_image_src( (int) get_theme_mod( 'custom_logo' ), 'full' ), $icon ) as $logo ) {
			if ( $logo && $logo[1] >= self::LOGO_MIN && $logo[2] >= self::LOGO_MIN ) {
				$organization['logo'] = array(
					'@type'  => 'ImageObject',
					'url'    => $logo[0],
					'width'  => $logo[1],
					'height' => $logo[2],
				);
				break;
			}
		}
		return $organization;
	}

	/**
	 * Plain text as readers see it: no tags, entities decoded.
	 *
	 * @param string $html Text that may contain markup or entities.
	 */
	public static function text( string $html ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
