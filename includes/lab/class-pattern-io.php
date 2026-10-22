<?php
/**
 * Export and import of wp_block patterns with their lab metadata.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Lab;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the JSON format core exports patterns in ({"__file":"wp_block","title","content","syncStatus"}) and adds one
 * "leitvia" member with the lab metadata and the variants. Core imports the files as plain patterns; Studio imports
 * core files as well as its own.
 */
final class Pattern_IO {

	/**
	 * Version of the "leitvia" member.
	 */
	public const FORMAT = 1;

	/**
	 * Largest accepted file.
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Most variants per file.
	 */
	private const MAX_VARIANTS = 50;

	/**
	 * Most categories per pattern.
	 */
	private const MAX_CATEGORIES = 50;

	/**
	 * Lab meta keys by field of the "leitvia" member.
	 */
	private const FIELDS = array(
		'status'      => '_leitvia_lab_status',
		'version'     => '_leitvia_lab_version',
		'replaced_by' => '_leitvia_lab_replaced_by',
		'changelog'   => '_leitvia_lab_changelog',
		'annotations' => '_leitvia_lab_annotations',
		'state'       => '_leitvia_lab_state',
	);

	/**
	 * Export of a pattern and the variants the current user may edit.
	 *
	 * @param int $id ID of the wp_block.
	 * @return array<string, mixed>
	 */
	public static function export( int $id ): array {
		$data     = self::entry( $id );
		$variants = array_filter( Pattern_Meta::variants( $id ), static fn( int $variant ): bool => 'trash' !== get_post_status( $variant ) && current_user_can( 'edit_post', $variant ) );

		$data['leitvia']['variants'] = array_values( array_map( array( self::class, 'entry' ), $variants ) );
		return $data;
	}

	/**
	 * Imports a pattern file. Nothing is written unless the whole file is valid.
	 *
	 * @param string $json File content.
	 * @return array{id: int, variants: list<int>, failed_variants: int}|\WP_Error
	 */
	public static function import( string $json ) {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error( 'leitvia_studio_pattern_too_large', __( 'The pattern file is larger than 1 MB.', 'leitvia-studio' ), array( 'status' => 413 ) );
		}
		$data = json_decode( $json, true, 64 );
		if ( ! is_array( $data ) ) {
			return self::invalid( __( 'The file is not valid JSON.', 'leitvia-studio' ) );
		}
		$original = self::normalize( $data, false );
		if ( is_wp_error( $original ) ) {
			return $original;
		}
		$variants = array();
		$list     = $data['leitvia']['variants'] ?? array();
		if ( ! is_array( $list ) || ! array_is_list( $list ) || count( $list ) > self::MAX_VARIANTS ) {
			/* translators: %d: maximum number of variants. */
			return self::invalid( sprintf( __( 'The variants must be a list of at most %d patterns.', 'leitvia-studio' ), self::MAX_VARIANTS ) );
		}
		foreach ( $list as $variant ) {
			$variant = is_array( $variant ) ? self::normalize( $variant, true ) : self::invalid( __( 'Every variant must be a pattern.', 'leitvia-studio' ) );
			if ( is_wp_error( $variant ) ) {
				return $variant;
			}
			$variants[] = $variant;
		}

		$id = self::insert( $original, 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$ids    = array();
		$failed = 0;
		foreach ( $variants as $variant ) {
			$variant_id = self::insert( $variant, $id );
			if ( is_wp_error( $variant_id ) ) {
				++$failed;
			} else {
				$ids[] = $variant_id;
			}
		}
		Status::flush();
		return array(
			'id'              => $id,
			'variants'        => $ids,
			'failed_variants' => $failed,
		);
	}

	/**
	 * One wp_block in the export format, without variants.
	 *
	 * @param int $id ID of the wp_block.
	 * @return array<string, mixed>
	 */
	private static function entry( int $id ): array {
		$post  = get_post( $id );
		$terms = wp_get_object_terms( $id, 'wp_pattern_category', array( 'fields' => 'slugs' ) );
		$lab   = array( 'format' => self::FORMAT );
		foreach ( self::FIELDS as $field => $key ) {
			$lab[ $field ] = get_post_meta( $id, $key, true );
			if ( '' === $lab[ $field ] && 'state' !== $field ) {
				$lab[ $field ] = Pattern_Meta::schema()[ $key ]['default'];
			}
		}
		if ( '' === $lab['state'] ) {
			unset( $lab['state'] );
		}
		$lab['categories'] = is_array( $terms ) ? $terms : array();
		return array(
			'__file'     => 'wp_block',
			'title'      => $post ? $post->post_title : '',
			'content'    => $post ? $post->post_content : '',
			'syncStatus' => (string) get_post_meta( $id, 'wp_pattern_sync_status', true ),
			'leitvia'    => $lab,
		);
	}

	/**
	 * Checks one pattern of a file and returns what is stored.
	 *
	 * @param array<mixed> $data       Pattern in the export format.
	 * @param bool         $is_variant Whether it is a variant; only variants have a state.
	 * @return array{title: string, content: string, sync: string, meta: array<string, mixed>, categories: list<string>}|\WP_Error
	 */
	private static function normalize( array $data, bool $is_variant ) {
		$title = is_string( $data['title'] ?? null ) ? sanitize_text_field( $data['title'] ) : '';
		if ( 'wp_block' !== ( $data['__file'] ?? null ) || '' === $title || ! is_string( $data['content'] ?? null ) ) {
			return self::invalid( __( 'This is not a pattern file: it needs "__file": "wp_block", a title and the content.', 'leitvia-studio' ) );
		}
		$lab = $data['leitvia'] ?? array();
		if ( ! is_array( $lab ) || ( $lab && self::FORMAT !== ( $lab['format'] ?? null ) ) ) {
			return self::invalid( __( 'The Leitvia part of the file has an unknown format.', 'leitvia-studio' ) );
		}

		$meta = array();
		foreach ( self::FIELDS as $field => $key ) {
			if ( ! array_key_exists( $field, $lab ) || ( 'state' === $field && ! $is_variant ) ) {
				continue;
			}
			$schema = Pattern_Meta::schema()[ $key ];
			$valid  = rest_validate_value_from_schema( $lab[ $field ], $schema, $field );
			if ( is_wp_error( $valid ) ) {
				return self::invalid( $valid->get_error_message() );
			}
			$meta[ $key ] = rest_sanitize_value_from_schema( $lab[ $field ], $schema, $field );
		}

		// The file comes from elsewhere: nothing arrives approved, and only registered patterns stay as replacement.
		$status  = (string) ( $meta['_leitvia_lab_status'] ?? 'draft' );
		$replace = (string) ( $meta['_leitvia_lab_replaced_by'] ?? '' );
		if ( ! str_starts_with( $replace, 'pattern:' ) || ! \WP_Block_Patterns_Registry::get_instance()->is_registered( substr( $replace, 8 ) ) ) {
			$replace = '';
		}
		if ( 'approved' === $status || ( 'deprecated' === $status && '' === $replace ) ) {
			$status = 'review';
		}
		$meta['_leitvia_lab_status']      = $status;
		$meta['_leitvia_lab_replaced_by'] = $replace;
		$error                            = Pattern_Meta::check( $status, 'draft', (string) ( $meta['_leitvia_lab_version'] ?? '' ), $replace, (array) ( $meta['_leitvia_lab_changelog'] ?? array() ) );
		if ( '' !== $error ) {
			return self::invalid( $error );
		}

		$categories = $lab['categories'] ?? array();
		if ( ! is_array( $categories ) || count( $categories ) > self::MAX_CATEGORIES ) {
			/* translators: %d: maximum number of categories. */
			return self::invalid( sprintf( __( 'The categories must be a list of at most %d slugs.', 'leitvia-studio' ), self::MAX_CATEGORIES ) );
		}
		$categories = array_values( array_unique( array_filter( array_map( static fn( $slug ): string => is_string( $slug ) ? sanitize_title( $slug ) : '', $categories ) ) ) );

		return array(
			'title'      => $title,
			'content'    => current_user_can( 'unfiltered_html' ) ? $data['content'] : wp_kses_post( $data['content'] ),
			'sync'       => 'unsynced' === ( $data['syncStatus'] ?? '' ) ? 'unsynced' : '',
			'meta'       => array_filter( $meta, static fn( $value ): bool => '' !== $value && array() !== $value ),
			'categories' => $categories,
		);
	}

	/**
	 * Creates the wp_block of a checked pattern.
	 *
	 * @param array<string, mixed> $pattern  Checked pattern, see normalize().
	 * @param int                  $original ID of the original for a variant, 0 otherwise.
	 * @phpstan-param array{title: string, content: string, sync: string, meta: array<string, mixed>, categories: list<string>} $pattern
	 * @return int|\WP_Error
	 */
	private static function insert( array $pattern, int $original ) {
		$meta = $pattern['meta'];
		if ( $original ) {
			$meta['_leitvia_lab_variant_of'] = $original;
		}
		if ( 'unsynced' === $pattern['sync'] ) {
			$meta['wp_pattern_sync_status'] = 'unsynced';
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'wp_block',
					'post_status'  => 'publish',
					'post_title'   => $pattern['title'],
					'post_content' => $pattern['content'],
					'meta_input'   => $meta,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$terms    = array();
		$taxonomy = get_taxonomy( 'wp_pattern_category' );
		foreach ( $pattern['categories'] as $slug ) {
			$term = get_term_by( 'slug', $slug, 'wp_pattern_category' );
			if ( ! $term && $taxonomy && current_user_can( $taxonomy->cap->edit_terms ) ) {
				$created = wp_insert_term( ucfirst( str_replace( '-', ' ', $slug ) ), 'wp_pattern_category', array( 'slug' => $slug ) );
				$term    = is_wp_error( $created ) ? false : get_term( (int) $created['term_id'], 'wp_pattern_category' );
			}
			if ( $term instanceof \WP_Term ) {
				$terms[] = $term->term_id;
			}
		}
		if ( $terms ) {
			wp_set_object_terms( $id, $terms, 'wp_pattern_category' );
		}
		return $id;
	}

	/**
	 * Error for an invalid file.
	 *
	 * @param string $message Reason.
	 */
	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'leitvia_studio_invalid_pattern_file', $message, array( 'status' => 400 ) );
	}
}
