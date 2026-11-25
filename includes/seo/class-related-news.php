<?php
/**
 * Topic of the news a page points to.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * A page names the categories and tags of its news in the meta META (field news of leitvia/set-page-structure). The
 * theme Leitvia asks for them through its filter leitvia_related_terms, so the pattern Related news on that page lists
 * the latest posts with any of them. Without such posts the theme asks leitvia_related_fallback: first the posts whose
 * hub is the page (set-page-structure hub of a post), then the posts in a category or tag with the slug of the page or
 * of the line of offerings it names; only without any of those the theme lists the latest posts. audit-site and
 * check-composition name a page with the pattern and no topic (source()).
 */
final class Related_News {

	/**
	 * Meta of a page: array( 'categories' => int[], 'tags' => int[] ).
	 */
	public const META = '_leitvia_news';

	/**
	 * Most terms per taxonomy.
	 */
	public const MAX = 5;

	/**
	 * Taxonomy of each key of the field.
	 */
	public const TAXONOMIES = array(
		'categories' => 'category',
		'tags'       => 'post_tag',
	);

	/**
	 * Most posts of the fallback; the query of the pattern shows its own number of them, newest first.
	 */
	public const FALLBACK_MAX = 12;

	/**
	 * The terms a page names, by key of the field; empty lists without any.
	 *
	 * @param int $page Page ID.
	 * @return array{categories: list<int>, tags: list<int>}
	 */
	public static function of( int $page ): array {
		$stored = get_post_meta( $page, self::META, true );
		$out    = array(
			'categories' => array(),
			'tags'       => array(),
		);
		foreach ( self::TAXONOMIES as $key => $taxonomy ) {
			$out[ $key ] = array_values( array_filter( array_map( 'intval', (array) ( is_array( $stored ) ? ( $stored[ $key ] ?? array() ) : array() ) ) ) );
		}
		return $out;
	}

	/**
	 * Terms of the page for the theme. Filter leitvia_related_terms.
	 *
	 * @param mixed $terms Terms by taxonomy from the theme.
	 * @param mixed $post  ID of the current page or post.
	 * @return array<string, list<int>>|mixed
	 */
	public static function terms( $terms, $post = 0 ) {
		if ( 'page' !== get_post_type( (int) $post ) ) {
			return $terms;
		}
		$named = array();
		foreach ( self::of( (int) $post ) as $key => $ids ) {
			if ( $ids ) {
				$named[ self::TAXONOMIES[ $key ] ] = $ids;
			}
		}
		return $named ? $named : $terms;
	}

	/**
	 * Posts for the theme when none has a term of the page. Filter leitvia_related_fallback.
	 *
	 * @param mixed $posts Post IDs of earlier filters.
	 * @param mixed $post  ID of the current page or post.
	 * @return list<int>|mixed
	 */
	public static function fallback( $posts, $post = 0 ) {
		if ( ( is_array( $posts ) && $posts ) || 'page' !== get_post_type( (int) $post ) ) {
			return $posts;
		}
		return self::pick( (int) $post )[1];
	}

	/**
	 * What the pattern Related news shows on a page: topic for the terms it names, hub for the posts that name the page
	 * as their hub, match for posts in a category or tag with the slug of the page or of its line of offerings, latest
	 * for the latest posts.
	 *
	 * @param int $page Page ID.
	 */
	public static function source( int $page ): string {
		return array_filter( self::of( $page ) ) ? 'topic' : self::pick( $page )[0];
	}

	/**
	 * Finding for a page whose content holds the query of the pattern Related news (namespace leitvia/related-news)
	 * without a topic: what it shows instead and how to name the topic; null for a page with a topic or without the
	 * pattern.
	 *
	 * @param int    $page    Page ID.
	 * @param string $content Block markup of the page.
	 * @return array{message: string, fix: string}|null
	 */
	public static function untopical( int $page, string $content ): ?array {
		if ( 'page' !== get_post_type( $page ) || ! str_contains( $content, '"namespace":"leitvia/related-news"' ) ) {
			return null;
		}
		$message = match ( self::source( $page ) ) {
			'topic' => '',
			'hub'   => __( 'The section Related news has no topic: it shows the posts that name this page as their hub.', 'leitvia-studio' ),
			'match' => __( 'The section Related news has no topic: it shows the posts in a category or tag with the slug of this page or of its line of offerings.', 'leitvia-studio' ),
			default => __( 'The section Related news has no topic: it shows the latest posts of the site, the same on every page without a topic, also posts on other topics.', 'leitvia-studio' ),
		};
		return '' === $message ? null : array(
			'message' => $message,
			'fix'     => __( 'Name the categories or tags of its news with leitvia/set-page-structure news, by slug or ID, or give the posts on its topic this page as hub (set-page-structure hub of each post).', 'leitvia-studio' ),
		);
	}

	/**
	 * The fallback of a page with its kind: hub, match or latest (no posts).
	 *
	 * @param int $page Page ID.
	 * @return array{0: string, 1: list<int>}
	 */
	private static function pick( int $page ): array {
		// Only pages come here and the query lists posts, so the page itself can never be among them.
		$query = array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'posts_per_page'   => self::FALLBACK_MAX,
			'fields'           => 'ids',
			'suppress_filters' => false,
		);
		$hub   = get_posts(
			$query + array(
				'meta_key'   => Cluster::HUB_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One small query per related news section.
				'meta_value' => (string) $page, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);
		if ( $hub ) {
			return array( 'hub', array_values( array_map( 'intval', $hub ) ) );
		}
		$slugs = array_values( array_filter( array( (string) get_post_field( 'post_name', $page ), Cluster::offerings( $page )['line'] ) ) );
		$tax   = array( 'relation' => 'OR' );
		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( $slugs ) {
				$tax[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $slugs,
				);
			}
		}
		$match = count( $tax ) > 1 ? get_posts( $query + array( 'tax_query' => $tax ) ) : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- See above.
		return $match ? array( 'match', array_values( array_map( 'intval', $match ) ) ) : array( 'latest', array() );
	}
}
