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
	 * keys of Pipeline::META (the alt text of an attachment), the props of a product and, for content types, date, terms,
	 * template and fields (Abilities\Content::state()); '' when the post does not exist.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function document( int $post_id ): string {
		return self::post( $post_id, false );
	}

	/**
	 * Whether a stored hash (a base hash, the hash a package left) is the hash of the post now. Hashes from before
	 * Studio 1.3.31 left the template (_wp_page_template) out of the state of content types; such a hash matches too
	 * when the post is otherwise the same, so packages applied before the update still roll back. It cannot tell a
	 * template change apart, so it never matches for an operation that writes another template than the post has.
	 *
	 * Sunset: remove legacy() and the legacy branch once no site can hold a package applied before 1.3.31, at the
	 * latest with Studio 1.5 (packages and snapshots older than that are no longer kept for rollback).
	 *
	 * @param string      $stored   Stored hash.
	 * @param int         $post_id  Post ID.
	 * @param string|null $template Template the operation writes, null when it writes none.
	 */
	public static function matches( string $stored, int $post_id, ?string $template = null ): bool {
		$current = self::document( $post_id );
		if ( '' === $current || hash_equals( $current, $stored ) ) {
			return '' !== $current;
		}
		$same = null === $template || (string) get_post_meta( $post_id, '_wp_page_template', true ) === $template;
		return $same && self::legacy( $stored, $post_id );
	}

	/**
	 * Whether a stored hash is the hash of the post in the format before Studio 1.3.31, see matches().
	 *
	 * @param string $stored  Stored hash.
	 * @param int    $post_id Post ID.
	 */
	public static function legacy( string $stored, int $post_id ): bool {
		return hash_equals( self::post( $post_id, true ), $stored );
	}

	/**
	 * Hash of a post, see document(); legacy leaves the template out of the state of content types.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $legacy  Hash as before Studio 1.3.31.
	 */
	private static function post( int $post_id, bool $legacy ): string {
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
		if ( $legacy && isset( $content['meta_input'] ) ) {
			unset( $content['meta_input']['_wp_page_template'] );
			if ( ! $content['meta_input'] ) {
				unset( $content['meta_input'] );
			}
		}
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
