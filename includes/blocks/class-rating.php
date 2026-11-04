<?php
/**
 * Rating: stars with the value as text, optionally a review with a Review node for search engines.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Renders leitvia/rating. The stars are decorative (aria-hidden), the value is visible text in the site language
 * ("4.5 out of 5 stars"). With a reviewed item, its type and an author the block adds a schema.org Review through Schema_Nodes,
 * which prints it in the head unless an SEO plugin is active. No AggregateRating: the block has no source of real
 * ratings to average.
 */
final class Rating {

	/**
	 * Types of itemReviewed that Google accepts for review snippets, without Organization (self-serving reviews).
	 */
	public const TYPES = array( 'Product', 'Book', 'Course', 'Recipe', 'SoftwareApplication', 'LocalBusiness', 'Movie', 'Event', 'CreativeWorkSeason' );

	/**
	 * Renders the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		$max    = max( 1, min( 10, (int) ( $attributes['max'] ?? 5 ) ) );
		$rating = round( max( 0, min( $max, (float) ( $attributes['rating'] ?? 0 ) ) ) * 2 ) / 2;
		$body   = trim( (string) ( $attributes['reviewBody'] ?? '' ) );
		$author = trim( (string) ( $attributes['reviewAuthor'] ?? '' ) );
		$item   = trim( (string) ( $attributes['itemName'] ?? '' ) );
		$type   = (string) ( $attributes['itemType'] ?? '' );
		$label  = trim( (string) ( $attributes['label'] ?? '' ) );
		$label  = '' === $label ? $item : $label;

		$text = sprintf(
			/* translators: 1: rating, for example 4.5. 2: number of stars, for example 5. */
			_n( '%1$s out of %2$s star', '%1$s out of %2$s stars', $max, 'leitvia-studio' ),
			number_format_i18n( $rating, floor( $rating ) === $rating ? 0 : 1 ),
			number_format_i18n( $max )
		);

		// Google needs an author for review snippets, so no node without one.
		if ( '' !== $item && '' !== $author && in_array( $type, self::TYPES, true ) ) {
			$node = array(
				'@type'        => 'Review',
				'itemReviewed' => array(
					'@type' => $type,
					'name'  => wp_strip_all_tags( $item ),
				),
				// The scale starts at 0 (schema.org assumes 1 without worstRating).
				'reviewRating' => array(
					'@type'       => 'Rating',
					'ratingValue' => $rating,
					'bestRating'  => $max,
					'worstRating' => 0,
				),
				'author'       => array(
					'@type' => 'Person',
					'name'  => wp_strip_all_tags( $author ),
				),
			);
			if ( '' !== $body ) {
				$node['reviewBody'] = wp_strip_all_tags( $body );
			}
			Schema_Nodes::add( $node );
		}

		return sprintf(
			'<div %1$s>%2$s<p class="wp-block-leitvia-rating__score"><span class="wp-block-leitvia-rating__stars" style="%3$s" aria-hidden="true"></span> <span class="wp-block-leitvia-rating__text">%4$s</span></p>%5$s%6$s</div>',
			get_block_wrapper_attributes(),
			'' === $label ? '' : '<p class="wp-block-leitvia-rating__label">' . esc_html( $label ) . '</p>',
			esc_attr( sprintf( '--leitvia-rating-max:%d;--leitvia-rating:%s%%', $max, round( $rating / $max * 100, 2 ) ) ),
			esc_html( $text ),
			'' === $body ? '' : '<p class="wp-block-leitvia-rating__body">' . nl2br( esc_html( $body ) ) . '</p>',
			'' === $author ? '' : '<p class="wp-block-leitvia-rating__author">' . esc_html( $author ) . '</p>'
		);
	}
}
