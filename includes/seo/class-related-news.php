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
 * the latest posts with any of them, and the latest posts when none has one.
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
}
