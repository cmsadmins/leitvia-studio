<?php
/**
 * Content hashes for the optimistic lock.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Document and block hashes: sha256, first 16 hex characters. Agents send the hash they read; the pipeline compares it
 * with the current one before it writes, and snapshots keep it for rolling back.
 */
final class Hashes {

	/**
	 * Hash of a post over title, content, excerpt, status, slug, parent, order, featured image, comment status, the meta
	 * keys of Pipeline::META (the alt text of an attachment), the props of a product and, for content types, date, terms
	 * and fields (Abilities\Content::state()); '' when the post does not exist.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function document( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$parts = array(
			$post->post_title,
			$post->post_content,
			$post->post_excerpt,
			$post->post_status,
			$post->post_name,
			(int) $post->post_parent,
			(int) $post->menu_order,
			(int) get_post_thumbnail_id( $post ),
			$post->comment_status,
		);
		foreach ( Pipeline::META[ $post->post_type ] ?? array() as $key ) {
			$parts[] = (string) get_post_meta( $post->ID, $key, true );
		}
		if ( Abilities\Commerce::handles( $post->post_type ) ) {
			$parts[] = Abilities\Commerce::props( $post->ID );
		}
		$content = Abilities\Content::state( $post->ID, $post->post_type );
		if ( $content ) {
			$parts[] = $content;
		}
		return self::of( $parts );
	}

	/**
	 * Hash of a term over the state the pipeline writes, see Pipeline::term_state(); '' when the term does not exist.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function term( int $term_id ): string {
		$state = Pipeline::term_state( $term_id );
		return $state ? self::of( $state ) : '';
	}

	/**
	 * Hash of a parsed block with its attributes, inner blocks and markup. Siblings and position do not count.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	public static function block( array $block ): string {
		return self::of( serialize_block( $block ) );
	}

	/**
	 * Hash of a value.
	 *
	 * @param mixed $value String or JSON-encodable value.
	 */
	public static function of( $value ): string {
		return substr( hash( 'sha256', is_string( $value ) ? $value : (string) wp_json_encode( $value ) ), 0, 16 );
	}
}
