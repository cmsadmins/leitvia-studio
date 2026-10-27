<?php
/**
 * Lab metadata of wp_block patterns and their variants.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Lab;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the lab meta keys on wp_block, validates REST writes and creates variants.
 */
final class Pattern_Meta {

	/**
	 * Free text field of the list entries, by meta key.
	 */
	private const FREE_TEXT = array(
		'_leitvia_lab_changelog'   => 'note',
		'_leitvia_lab_annotations' => 'text',
	);

	/**
	 * Meta schemas by key, loaded from schemas/lab-meta.schema.json.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		static $schema = null;
		if ( null === $schema ) {
			$file   = dirname( LEITVIA_STUDIO_FILE ) . '/schemas/lab-meta.schema.json';
			$data   = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
			$schema = is_array( $data ) && is_array( $data['properties'] ?? null ) ? $data['properties'] : array();
		}
		return $schema;
	}

	/**
	 * Registers the meta keys. Runs on init.
	 */
	public static function register(): void {
		foreach ( self::schema() as $key => $schema ) {
			register_post_meta(
				'wp_block',
				$key,
				array(
					'type'              => $schema['type'],
					'description'       => $schema['description'] ?? '',
					'single'            => true,
					'default'           => $schema['default'],
					'show_in_rest'      => array( 'schema' => $schema ),
					'auth_callback'     => static fn( $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
					'sanitize_callback' => array( self::class, 'sanitize_entries' ),
				)
			);
		}
	}

	/**
	 * Sanitizes the free text of changelog and annotation entries, line breaks stay; other keys pass unchanged.
	 *
	 * @param mixed  $value    Meta value.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public static function sanitize_entries( $value, string $meta_key ) {
		$field = self::FREE_TEXT[ $meta_key ] ?? '';
		if ( '' === $field || ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as &$entry ) {
			if ( is_array( $entry ) && is_string( $entry[ $field ] ?? null ) ) {
				$entry[ $field ] = sanitize_textarea_field( $entry[ $field ] );
			}
		}
		return $value;
	}

	/**
	 * Rejects lab meta that is valid per field but inconsistent as a whole. Runs on rest_pre_insert_wp_block.
	 *
	 * @param \stdClass|\WP_Error $prepared Post prepared for the database.
	 * @param \WP_REST_Request    $request  Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \stdClass|\WP_Error
	 */
	public static function validate_rest( $prepared, \WP_REST_Request $request ) {
		$meta = $request->get_param( 'meta' );
		if ( is_wp_error( $prepared ) || ! is_array( $meta ) ) {
			return $prepared;
		}
		$id = (int) ( $prepared->ID ?? 0 );
		foreach ( array_intersect_key( $meta, self::schema() ) as $key => $value ) {
			// Core validates meta only after the post is saved; check the types first so nothing is half written.
			$valid = rest_validate_value_from_schema( $value, self::schema()[ $key ], $key );
			if ( is_wp_error( $valid ) ) {
				return new \WP_Error( 'leitvia_studio_invalid_lab_meta', $valid->get_error_message(), array( 'status' => 400 ) );
			}
		}
		$value = static fn( string $key ) => array_key_exists( $key, $meta ) ? $meta[ $key ] : ( $id ? get_post_meta( $id, $key, true ) : self::schema()[ $key ]['default'] );
		$text  = static fn( string $key ): string => is_string( $value( $key ) ) ? $value( $key ) : '';
		$old   = $id ? (string) get_post_meta( $id, '_leitvia_lab_status', true ) : '';

		$error = self::check( $text( '_leitvia_lab_status' ), '' === $old ? 'draft' : $old, $text( '_leitvia_lab_version' ), $text( '_leitvia_lab_replaced_by' ), (array) $value( '_leitvia_lab_changelog' ) );
		if ( '' === $error && array_key_exists( '_leitvia_lab_replaced_by', $meta ) ) {
			$error = self::check_replacement( $text( '_leitvia_lab_replaced_by' ), $id );
		}
		if ( '' === $error && ! empty( $meta['_leitvia_lab_variant_of'] ) ) {
			$error = self::check_original( (int) $meta['_leitvia_lab_variant_of'], $id );
		}
		return '' === $error ? $prepared : new \WP_Error( 'leitvia_studio_invalid_lab_meta', $error, array( 'status' => 400 ) );
	}

	/**
	 * Returns why the replacement is invalid, or '' for an existing pattern other than the pattern itself.
	 *
	 * @param string $ref Replacement reference, may be empty.
	 * @param int    $id  ID of the pattern being saved, 0 for a new one.
	 */
	private static function check_replacement( string $ref, int $id ): string {
		if ( '' === $ref ) {
			return '';
		}
		if ( str_starts_with( $ref, 'pattern:' ) ) {
			$slug   = substr( $ref, 8 );
			$exists = \WP_Block_Patterns_Registry::get_instance()->is_registered( $slug ) || null !== leitvia_studio_contract()->pattern( $slug );
		} else {
			$target = (int) substr( $ref, 6 );
			$exists = $target !== $id && 'wp_block' === get_post_type( $target ) && 'trash' !== get_post_status( $target );
		}
		return $exists ? '' : __( 'The replacement must be another existing pattern.', 'leitvia-studio' );
	}

	/**
	 * Returns why the original is invalid, or '' for an existing original pattern other than the pattern itself.
	 *
	 * @param int $original ID of the original.
	 * @param int $id       ID of the pattern being saved, 0 for a new one.
	 */
	private static function check_original( int $original, int $id ): string {
		$ok = $original !== $id && 'wp_block' === get_post_type( $original ) && ! (int) get_post_meta( $original, '_leitvia_lab_variant_of', true );
		return $ok ? '' : __( 'A variant must point to another original pattern.', 'leitvia-studio' );
	}

	/**
	 * Returns why the combination is invalid, or '' when it is valid.
	 *
	 * @param string            $status      New status.
	 * @param string            $old_status  Stored status.
	 * @param string            $version     Pattern version.
	 * @param string            $replaced_by Replacement reference.
	 * @param array<int, mixed> $changelog   Changelog, newest first.
	 */
	public static function check( string $status, string $old_status, string $version, string $replaced_by, array $changelog ): string {
		if ( 'deprecated' === $status && '' === $replaced_by ) {
			return __( 'A deprecated pattern needs a replacement.', 'leitvia-studio' );
		}
		$latest = is_string( $changelog[0]['version'] ?? null ) ? $changelog[0]['version'] : '';
		if ( 'approved' === $status && 'approved' !== $old_status && '' !== $latest && version_compare( $version, $latest, '<' ) ) {
			/* translators: %s: latest version in the changelog. */
			return sprintf( __( 'An approved pattern needs a version of at least %s, the latest changelog version.', 'leitvia-studio' ), $latest );
		}
		$changelog = array_values( $changelog );
		foreach ( $changelog as $i => $entry ) {
			$version  = is_array( $entry ) && is_string( $entry['version'] ?? null ) ? $entry['version'] : '';
			$previous = $changelog[ $i + 1 ]['version'] ?? null;
			if ( ! empty( $entry['breaking'] ) && is_string( $previous ) && (int) $version <= (int) $previous ) {
				/* translators: %s: version of the changelog entry. */
				return sprintf( __( 'The breaking change %s needs a higher major version than the entry before it.', 'leitvia-studio' ), $version );
			}
		}
		return '';
	}

	/**
	 * Creates a variant of a wp_block pattern as its own wp_block, so it is edited in the normal pattern editor.
	 *
	 * @param int    $original ID of the original pattern.
	 * @param string $state    State of the variant, '' for a content variant.
	 * @param string $title    Title of the variant.
	 * @param string $content  Block markup of the variant, null for a copy of the original.
	 * @return int|\WP_Error ID of the variant.
	 */
	public static function create_variant( int $original, string $state, string $title, ?string $content = null ) {
		$post = get_post( $original );
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return new \WP_Error( 'leitvia_studio_no_pattern', __( 'The original pattern does not exist.', 'leitvia-studio' ), array( 'status' => 404 ) );
		}
		if ( (int) get_post_meta( $original, '_leitvia_lab_variant_of', true ) ) {
			return new \WP_Error( 'leitvia_studio_variant_of_variant', __( 'Variants are created from the original pattern.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $state, self::schema()['_leitvia_lab_state']['enum'], true ) ) {
			return new \WP_Error( 'leitvia_studio_invalid_state', __( 'Unknown variant state.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => $post->post_status,
				'post_title'   => wp_slash( '' !== $title ? $title : $post->post_title ),
				'post_content' => wp_slash( $content ?? $post->post_content ),
				'meta_input'   => array_filter(
					array(
						'_leitvia_lab_variant_of' => $original,
						'_leitvia_lab_state'      => $state,
						'wp_pattern_sync_status'  => get_post_meta( $original, 'wp_pattern_sync_status', true ),
					)
				),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$terms = wp_get_object_terms( $original, 'wp_pattern_category', array( 'fields' => 'ids' ) );
		if ( is_array( $terms ) && $terms ) {
			wp_set_object_terms( $id, array_map( 'intval', $terms ), 'wp_pattern_category' );
		}
		return $id;
	}

	/**
	 * Creates a state of a pattern: long and empty are derived from the content, error and disabled start as exact
	 * copies the author edits in the pattern editor. A state of a theme pattern is a new wp_block that points to the
	 * pattern through _leitvia_lab_source_pattern, since there is no original wp_block.
	 *
	 * @param int|string $original ID of the original wp_block, or slug of a registered pattern.
	 * @param string     $state    error, empty, long or disabled.
	 * @return int|\WP_Error ID of the state.
	 */
	public static function create_state( $original, string $state ) {
		if ( '' === $state || ! in_array( $state, self::schema()['_leitvia_lab_state']['enum'], true ) ) {
			return new \WP_Error( 'leitvia_studio_invalid_state', __( 'Unknown variant state.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		if ( is_string( $original ) ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $original );
			if ( ! $pattern ) {
				return new \WP_Error( 'leitvia_studio_no_pattern', __( 'The original pattern does not exist.', 'leitvia-studio' ), array( 'status' => 404 ) );
			}
			$content = (string) $pattern['content'];
			$title   = (string) $pattern['title'];
		} else {
			$content = (string) get_post_field( 'post_content', $original );
			$title   = (string) get_post_field( 'post_title', $original );
		}
		if ( isset( self::states( $original )[ $state ] ) ) {
			return new \WP_Error( 'leitvia_studio_state_exists', __( 'This state already exists.', 'leitvia-studio' ), array( 'status' => 409 ) );
		}
		$content = match ( $state ) {
			'long'  => self::long_content( $content ),
			'empty' => self::empty_content( $content ),
			default => $content,
		};
		/* translators: 1: pattern title, 2: state label such as Long text. */
		$title = sprintf( __( '%1$s (%2$s)', 'leitvia-studio' ), $title, self::state_label( $state ) );
		if ( is_int( $original ) ) {
			return self::create_variant( $original, $state, $title, $content );
		}
		return wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => wp_slash( $title ),
				'post_content' => wp_slash( $content ),
				'meta_input'   => array(
					'_leitvia_lab_state'          => $state,
					'_leitvia_lab_source_pattern' => $original,
					'wp_pattern_sync_status'      => 'unsynced',
				),
			),
			true
		);
	}

	/**
	 * Label of a state.
	 *
	 * @param string $state State.
	 */
	public static function state_label( string $state ): string {
		$labels = array(
			'error'    => __( 'Error', 'leitvia-studio' ),
			'empty'    => __( 'Empty', 'leitvia-studio' ),
			'long'     => __( 'Long text', 'leitvia-studio' ),
			'disabled' => __( 'Disabled', 'leitvia-studio' ),
		);
		return $labels[ $state ] ?? $state;
	}

	/**
	 * States of a pattern that are not in the trash.
	 *
	 * @param int|string $original ID of the original wp_block, or slug of a theme pattern.
	 * @return array<string, int> State => ID.
	 */
	public static function states( $original ): array {
		$ids    = is_int( $original ) ? self::variants( $original ) : get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => array_values( get_post_stati() ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_leitvia_lab_source_pattern', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- States are few.
				'meta_value'     => $original, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- States are few.
			)
		);
		$states = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$state = (string) get_post_meta( $id, '_leitvia_lab_state', true );
			if ( '' !== $state && 'trash' !== get_post_status( $id ) && ! isset( $states[ $state ] ) ) {
				$states[ $state ] = $id;
			}
		}
		return $states;
	}

	/**
	 * Long text state: every text of headings, paragraphs, buttons and list items three times; headings also end in
	 * one long word made of their own words, to test overflow and line breaks.
	 *
	 * @param string $content Block markup.
	 */
	public static function long_content( string $content ): string {
		$text     = static function ( string $text, bool $heading ): string {
			// Spaces around the text separate it from neighbouring inline elements and stay.
			$core = trim( $text );
			$long = "{$core} {$core} {$core}" . ( $heading ? ' ' . str_repeat( (string) preg_replace( '/\s+/u', '', $core ), 3 ) : '' );
			return substr_replace( $text, $long, (int) strpos( $text, $core ), strlen( $core ) );
		};
		$lengthen = static function ( array &$block ) use ( $text ): string {
			$heading = 'core/heading' === $block['blockName'];
			if ( $heading || in_array( $block['blockName'], array( 'core/paragraph', 'core/button', 'core/list-item' ), true ) ) {
				foreach ( $block['innerContent'] as &$chunk ) {
					$chunk = is_string( $chunk ) ? self::map_text( $chunk, static fn( string $node ): string => $text( $node, $heading ) ) : null;
				}
				$block['innerHTML'] = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
			}
			return '';
		};
		return traverse_and_serialize_blocks( parse_blocks( $content ), $lengthen );
	}

	/**
	 * Empty state: without empty paragraphs and images without a source, lists cut to their first item.
	 *
	 * @param string $content Block markup.
	 */
	public static function empty_content( string $content ): string {
		$keep = static function ( array $block ): bool {
			if ( 'core/paragraph' === $block['blockName'] ) {
				return '' !== trim( wp_strip_all_tags( $block['innerHTML'] ) );
			}
			if ( 'core/image' === $block['blockName'] ) {
				$html = new \WP_HTML_Tag_Processor( $block['innerHTML'] );
				return $html->next_tag( array( 'tag_name' => 'img' ) ) && '' !== trim( (string) $html->get_attribute( 'src' ) );
			}
			return true;
		};
		// Runs on each block before its inner blocks are serialized, so dropped inner blocks are never visited.
		$prune = static function ( array &$block ) use ( $keep ): string {
			$kept  = 0;
			$list  = 'core/list' === $block['blockName'];
			$block = self::filter_inner(
				$block,
				static function ( array $child ) use ( $keep, $list, &$kept ): bool {
					$stays = $keep( $child ) && ( ! $list || 0 === $kept );
					$kept += (int) $stays;
					return $stays;
				}
			);
			return '';
		};
		return traverse_and_serialize_blocks( array_values( array_filter( parse_blocks( $content ), $keep ) ), $prune );
	}

	/**
	 * Drops inner blocks; the markup around a dropped block is joined.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param callable             $keep  Whether an inner block stays, called in order.
	 * @phpstan-param callable(array<string, mixed>): bool $keep
	 * @return array<string, mixed>
	 */
	private static function filter_inner( array $block, callable $keep ): array {
		$inner   = array();
		$content = array();
		$index   = 0;
		foreach ( $block['innerContent'] as $chunk ) {
			if ( null === $chunk ) {
				$child = $block['innerBlocks'][ $index++ ];
				if ( $keep( $child ) ) {
					$inner[]   = $child;
					$content[] = null;
				}
				continue;
			}
			$last = count( $content ) - 1;
			if ( $last >= 0 && is_string( $content[ $last ] ) ) {
				$content[ $last ] .= $chunk;
			} else {
				$content[] = $chunk;
			}
		}
		$block['innerBlocks']  = $inner;
		$block['innerContent'] = $content;
		return $block;
	}

	/**
	 * Replaces every non-blank text node of an HTML fragment; tags and attributes stay as they are.
	 *
	 * @param string   $html     HTML fragment.
	 * @param callable $callback Returns the new text of a text node.
	 * @phpstan-param callable(string): string $callback
	 */
	private static function map_text( string $html, callable $callback ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() && '' !== trim( $processor->get_modifiable_text() ) ) {
				$processor->set_modifiable_text( $callback( $processor->get_modifiable_text() ) );
			}
		}
		return $processor->get_updated_html();
	}

	/**
	 * IDs of the variants of a pattern, in every status including trash.
	 *
	 * @param int $original ID of the original, 0 for the variants of all patterns.
	 * @return list<int>
	 */
	public static function variants( int $original = 0 ): array {
		$args = array(
			'post_type'      => 'wp_block',
			'post_status'    => array_values( get_post_stati() ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_leitvia_lab_variant_of', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Variants are few.
			'meta_value'     => $original ? (string) $original : '0', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Variants are few.
			'meta_compare'   => $original ? '=' : '!=',
		);
		return array_values( array_map( 'intval', get_posts( $args ) ) );
	}

	/**
	 * Variants follow their original: deleted with it (before_delete_post), trashed with it (wp_trash_post) and
	 * restored with it (untrashed_post).
	 *
	 * @param int $post_id ID of the original.
	 */
	public static function follow_original( int $post_id ): void {
		if ( 'wp_block' !== get_post_type( $post_id ) ) {
			return;
		}
		$action = current_action();
		foreach ( self::variants( $post_id ) as $variant ) {
			if ( 'before_delete_post' === $action ) {
				wp_delete_post( $variant, true );
			} elseif ( 'wp_trash_post' === $action ) {
				wp_trash_post( $variant );
			} else {
				wp_untrash_post( $variant );
			}
		}
	}

	/**
	 * Leaves variants out of the status counts of the admin pattern list. Runs on wp_count_posts.
	 *
	 * @param \stdClass $counts Number of posts per status.
	 * @param string    $type   Post type.
	 * @return \stdClass
	 */
	public static function count_originals( $counts, $type ) {
		if ( 'wp_block' !== $type || ! is_admin() ) {
			return $counts;
		}
		$counts = clone $counts;
		foreach ( self::variants() as $variant ) {
			$status = (string) get_post_status( $variant );
			if ( isset( $counts->$status ) ) {
				$counts->$status = max( 0, (int) $counts->$status - 1 );
			}
		}
		return $counts;
	}

	/**
	 * Meta query that leaves variants out.
	 *
	 * @return array<int|string, mixed>
	 */
	public static function originals_query(): array {
		$unset = static fn( string $key, string $blank ): array => array(
			'relation' => 'OR',
			array(
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => $key,
				'value' => $blank,
			),
		);
		// States of theme patterns have no original wp_block, but they are no originals either.
		return array( $unset( '_leitvia_lab_variant_of', '0' ), $unset( '_leitvia_lab_source_pattern', '' ) );
	}

	/**
	 * Hides variants, and patterns the Lab holds as draft or in review, from the core pattern lists of the editor:
	 * the inserter offers only patterns that are approved, deprecated or never went through the Lab. The Lab lists all
	 * of them through its own route. Runs on rest_wp_block_query.
	 *
	 * @param array<string, mixed> $args WP_Query arguments.
	 * @return array<string, mixed>
	 */
	public static function hide_variants_in_rest( array $args ): array {
		// Without the status meta a pattern was made outside the Lab; it stays in the inserter.
		$ready              = self::originals_query();
		$ready[]            = array(
			'relation' => 'OR',
			array(
				'key'     => '_leitvia_lab_status',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_leitvia_lab_status',
				'value'   => array( 'draft', 'review' ),
				'compare' => 'NOT IN',
			),
		);
		$args['meta_query'] = isset( $args['meta_query'] ) ? array( $args['meta_query'], $ready ) : $ready; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Variants and drafts are few; core has no other hook.
		return $args;
	}

	/**
	 * Hides variants from the admin list of patterns. Runs on pre_get_posts.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function hide_variants_in_admin( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'wp_block' !== $query->get( 'post_type' ) ) {
			return;
		}
		$meta = $query->get( 'meta_query' );
		$query->set( 'meta_query', $meta ? array( $meta, self::originals_query() ) : self::originals_query() );
	}
}
