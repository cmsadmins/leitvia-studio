<?php
/**
 * The image of a post in the RSS feed.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\News;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the featured image of a post to its item in the RSS 2 feed as media:content (Media RSS), the 16:9 crop of
 * Image_Sizes at 1200 px when there is one, else the original from 1200 px on. Feed readers and news aggregators such
 * as the Google News Publisher Center show it with the item. A post without such an image gets no element.
 */
final class Feed {

	/**
	 * Adds the namespace of Media RSS to the rss element. Runs on rss2_ns.
	 */
	public static function namespace(): void {
		echo ' xmlns:media="http://search.yahoo.com/mrss/"' . "\n";
	}

	/**
	 * Prints media:content for the current item. Runs on rss2_item.
	 */
	public static function item(): void {
		$image = self::image( (int) get_post_thumbnail_id() );
		if ( $image ) {
			printf( "\t\t<media:content url=\"%1\$s\" medium=\"image\" width=\"%2\$d\" height=\"%3\$d\" />\n", esc_url( $image[0] ), (int) $image[1], (int) $image[2] );
		}
	}

	/**
	 * URL, width and height of the feed image of an attachment, null without one of at least 1200 px width.
	 *
	 * @param int $attachment_id Attachment ID, 0 for none.
	 * @return array{0: string, 1: int, 2: int}|null
	 */
	public static function image( int $attachment_id ): ?array {
		if ( ! $attachment_id ) {
			return null;
		}
		foreach ( array( 'leitvia-16x9', 'full' ) as $size ) {
			$src = wp_get_attachment_image_src( $attachment_id, $size );
			if ( $src && (int) $src[1] >= Schema::IMAGE_MIN_WIDTH ) {
				return array( (string) $src[0], (int) $src[1], (int) $src[2] );
			}
		}
		return null;
	}
}
