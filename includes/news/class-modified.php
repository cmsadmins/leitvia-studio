<?php
/**
 * The date of the last change counts changes of the text only.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\News;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress sets the date of the last change (post_modified) on every save, also when only meta, the markup of the
 * blocks or a pattern around the same words changed. Readers see it as "Updated" in the byline and as dateModified in
 * the structured data, so a round of maintenance would date every post to the same day. A published post that keeps
 * its title, excerpt and text (as plain words) keeps its date; core, REST, feeds, blocks and sitemaps all read the same
 * field, so nothing else needs to know.
 */
final class Modified {

	/**
	 * Puts the date of the last change back when an update of a published post leaves its words alone. Filter
	 * wp_insert_post_data, late, where the data is slashed.
	 *
	 * @param mixed $data    Post data to save.
	 * @param mixed $postarr Data of the call, with ID on an update.
	 * @return mixed
	 */
	public static function keep( $data, $postarr ) {
		$id  = is_array( $postarr ) ? (int) ( $postarr['ID'] ?? 0 ) : 0;
		$old = $id ? get_post( $id ) : null;
		if ( ! is_array( $data ) || ! $old instanceof WP_Post || 'publish' !== $old->post_status || 'publish' !== ( $data['post_status'] ?? '' )
			|| 'attachment' === $old->post_type || ! is_post_type_viewable( $old->post_type ) ) {
			return $data;
		}
		$new  = wp_unslash( $data );
		$same = true;
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $field ) {
			$same = $same && self::words( (string) $old->$field ) === self::words( (string) ( $new[ $field ] ?? '' ) );
		}
		/**
		 * Whether an update keeps the date of the last change of a published post. True when title, excerpt and the
		 * words of the text stay the same.
		 *
		 * @param bool                 $same Whether the words stayed the same.
		 * @param int                  $id   Post ID.
		 * @param array<string, mixed> $new  Unslashed data to save.
		 */
		if ( apply_filters( 'leitvia_studio_keep_modified', $same, $id, $new ) ) {
			$data['post_modified']     = $old->post_modified;
			$data['post_modified_gmt'] = $old->post_modified_gmt;
		}
		return $data;
	}

	/**
	 * The words of a text as readers see them: no tags, block comments or entities, white space collapsed.
	 *
	 * @param string $html Text with markup.
	 */
	public static function words( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}
