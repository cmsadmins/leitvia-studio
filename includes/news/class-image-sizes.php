<?php
/**
 * Image sizes for article structured data.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\News;

defined( 'ABSPATH' ) || exit;

/**
 * Registers 16:9, 4:3 and 1:1 crops at 1200 px width, the formats search engines ask for in article images. They are
 * not added to the size picker of the editor; only the JSON-LD of Schema uses them.
 */
final class Image_Sizes {

	/**
	 * Size name => array( width, height ).
	 */
	public const SIZES = array(
		'leitvia-16x9' => array( 1200, 675 ),
		'leitvia-4x3'  => array( 1200, 900 ),
		'leitvia-1x1'  => array( 1200, 1200 ),
	);

	/**
	 * Registers the sizes. Runs on after_setup_theme.
	 */
	public static function register(): void {
		foreach ( self::SIZES as $name => list( $width, $height ) ) {
			add_image_size( $name, $width, $height, true );
		}
	}

	/**
	 * URLs of the sizes that exist at their exact dimensions for an attachment. WordPress never upscales, and a crop
	 * of an original smaller than the target keeps the wrong ratio, so such a size is left out.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return list<string>
	 */
	public static function urls( int $attachment_id ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) ) {
			return array();
		}
		$urls = array();
		foreach ( self::SIZES as $name => list( $width, $height ) ) {
			// An original of exactly the target size gets no copy; the original is the size then.
			$size = isset( $meta['sizes'][ $name ] ) ? $name : 'full';
			$dims = 'full' === $size ? $meta : $meta['sizes'][ $name ];
			$fits = array( $width, $height ) === array( (int) ( $dims['width'] ?? 0 ), (int) ( $dims['height'] ?? 0 ) );
			$src  = $fits ? wp_get_attachment_image_src( $attachment_id, $size ) : false;
			if ( $src ) {
				$urls[] = $src[0];
			}
		}
		return $urls;
	}
}
