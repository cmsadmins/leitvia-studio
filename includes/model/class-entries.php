<?php
/**
 * Upkeep of content model entries: repeater rows saved with their entry, a copy of an entry, date columns in its list.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Makes an entry with rows, such as the lunch menu of a week, one form a person fills in and saves once:
 * - The REST field leitvia_rows carries the rows of every repeater of an entry (context edit). The editor edits them
 *   with the entry and sends them on Save; rows sent with an ID of the entry change, new ones are added, missing ones
 *   go to the trash. Rows are checked before the entry is written: a title, the required fields, and no value twice in
 *   a field declared unique.
 * - Duplicate in the list copies an entry with its fields, terms, image and rows as a draft; a type that declares
 *   copyShiftDays gets its date fields moved by as many days, so the copy of a week is the next week.
 * - The list of a type shows its first date, select and number fields as columns.
 * Agents keep writing rows through the REST routes of the repeater types.
 */
final class Entries {

	/**
	 * REST field of the rows.
	 */
	const FIELD = 'leitvia_rows';

	/**
	 * Admin action of a copy.
	 */
	const COPY = 'leitvia_studio_copy';

	/**
	 * Field types the list shows as columns, at most COLUMNS of them.
	 */
	const COLUMN_TYPES = array( 'date', 'datetime', 'select', 'number', 'integer' );

	/**
	 * Most columns a list gains.
	 */
	const COLUMNS = 3;

	/**
	 * Hooks, see class-plugin.php.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_field' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_action' ), 10, 2 );
		add_action( 'admin_action_' . self::COPY, array( self::class, 'copy' ) );
		add_action( 'load-edit.php', array( self::class, 'list_screen' ) );
	}

	/**
	 * Adds the columns to the list of a content model type. Runs on load-edit.php.
	 */
	public static function list_screen(): void {
		$type = (string) ( get_current_screen()->post_type ?? '' );
		if ( Registrar::owns_post_type( $type ) && self::columns( $type ) ) {
			add_filter( "manage_{$type}_posts_columns", array( self::class, 'add_columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( self::class, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * Registers the REST field and its check on every type with repeaters. Runs on rest_api_init.
	 */
	public static function register_field(): void {
		foreach ( Model::current()['postTypes'] ?? array() as $type => $declaration ) {
			if ( empty( $declaration['repeaters'] ) || ! Registrar::owns_post_type( (string) $type ) ) {
				continue;
			}
			add_filter( "rest_pre_insert_{$type}", array( self::class, 'check' ), 10, 2 );
			register_rest_field(
				(string) $type,
				self::FIELD,
				array(
					'get_callback'    => array( self::class, 'get' ),
					'update_callback' => array( self::class, 'update' ),
					'schema'          => array(
						'description' => __( 'Rows of each repeater of the entry, by repeater key: id (empty for a new row), title and meta.', 'leitvia-studio' ),
						'type'        => 'object',
						'context'     => array( 'edit' ),
					),
				)
			);
		}
	}

	/**
	 * Repeaters of a post type by key.
	 *
	 * @param string $type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	private static function repeaters( string $type ): array {
		return (array) ( Model::current()['postTypes'][ $type ]['repeaters'] ?? array() );
	}

	/**
	 * Rows of a repeater of an entry, in their order, without trashed ones.
	 *
	 * @param int    $entry    Entry ID.
	 * @param string $row_type Post type of the rows.
	 * @return array<int, \WP_Post>
	 */
	private static function children( int $entry, string $row_type ): array {
		if ( ! $entry ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => $row_type,
				'post_parent'    => $entry,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);
	}

	/**
	 * The rows of an entry for the REST field.
	 *
	 * @param array<string, mixed> $prepared Prepared post with its id.
	 * @return object
	 */
	public static function get( array $prepared ): object {
		$id   = (int) ( $prepared['id'] ?? 0 );
		$rows = array();
		foreach ( self::repeaters( (string) get_post_type( $id ) ) as $key => $repeater ) {
			$rows[ $key ] = array_map(
				static function ( \WP_Post $row ) use ( $repeater ): array {
					$meta = array();
					foreach ( array_keys( (array) ( $repeater['fields'] ?? array() ) ) as $field ) {
						$meta[ $field ] = get_post_meta( $row->ID, (string) $field, true );
					}
					return array(
						'id'    => $row->ID,
						'title' => $row->post_title,
						'meta'  => (object) $meta,
					);
				},
				self::children( $id, (string) $repeater['postType'] )
			);
		}
		return (object) $rows;
	}

	/**
	 * Checks the rows sent with an entry before the entry is written. Runs on rest_pre_insert_{$post_type}.
	 *
	 * @param \stdClass|\WP_Error $prepared Post prepared for the database.
	 * @param \WP_REST_Request    $request  Request.
	 * @return \stdClass|\WP_Error
	 */
	public static function check( $prepared, \WP_REST_Request $request ) {
		$sent = $request[ self::FIELD ];
		if ( is_wp_error( $prepared ) || ! is_array( $sent ) ) {
			return $prepared;
		}
		$type = (string) ( $prepared->post_type ?? get_post_type( (int) ( $prepared->ID ?? 0 ) ) );
		foreach ( self::repeaters( $type ) as $key => $repeater ) {
			if ( ! isset( $sent[ $key ] ) ) {
				continue;
			}
			$error = self::invalid( $sent[ $key ], $repeater );
			if ( '' !== $error ) {
				return new \WP_Error( 'rest_invalid_param', $error, array( 'status' => 400 ) );
			}
		}
		return $prepared;
	}

	/**
	 * Why rows of a repeater cannot be saved, '' when they can.
	 *
	 * @param mixed                $rows     Rows as sent.
	 * @param array<string, mixed> $repeater Repeater declaration.
	 */
	public static function invalid( $rows, array $repeater ): string {
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
			return __( 'Rows must be a list.', 'leitvia-studio' );
		}
		$fields = (array) ( $repeater['fields'] ?? array() );
		$seen   = array();
		foreach ( $rows as $index => $row ) {
			$number = $index + 1;
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['title'] ?? '' ) ) ) {
				/* translators: 1: singular label of the repeater, for example Day; 2: row number. */
				return sprintf( __( '%1$s %2$d has no title.', 'leitvia-studio' ), $repeater['singularLabel'], $number );
			}
			$meta = (array) ( $row['meta'] ?? array() );
			foreach ( $fields as $key => $field ) {
				$value = Registrar::sanitize( (array) $field, $meta[ $key ] ?? '' );
				if ( ! empty( $field['required'] ) && ( '' === $value || false === $value ) ) {
					/* translators: 1: singular label of the repeater, for example Day; 2: row number; 3: field label. */
					return sprintf( __( '%1$s %2$d: %3$s is required.', 'leitvia-studio' ), $repeater['singularLabel'], $number, $field['label'] );
				}
				if ( ! empty( $field['unique'] ) && '' !== $value ) {
					if ( isset( $seen[ $key ][ (string) $value ] ) ) {
						/* translators: 1: field label, for example Weekday; 2: the value. */
						return sprintf( __( '%1$s %2$s appears twice.', 'leitvia-studio' ), $field['label'], (string) ( $field['options'][ $value ] ?? $value ) );
					}
					$seen[ $key ][ (string) $value ] = true;
				}
			}
		}
		return '';
	}

	/**
	 * Writes the rows sent with an entry after the entry: rows with an ID of the entry change, new rows are added,
	 * the others go to the trash. Order follows the list.
	 *
	 * @param mixed    $value Rows by repeater key.
	 * @param \WP_Post $entry Entry.
	 * @return true|\WP_Error
	 */
	public static function update( $value, \WP_Post $entry ) {
		if ( ! is_array( $value ) ) {
			return true;
		}
		foreach ( self::repeaters( $entry->post_type ) as $key => $repeater ) {
			if ( ! isset( $value[ $key ] ) ) {
				continue;
			}
			$error = self::invalid( $value[ $key ], $repeater );
			if ( '' !== $error ) {
				return new \WP_Error( 'rest_invalid_param', $error, array( 'status' => 400 ) );
			}
			$saved = self::write( $entry->ID, (array) $value[ $key ], $repeater );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		return true;
	}

	/**
	 * Writes the rows of one repeater of an entry, see update().
	 *
	 * @param int                              $entry    Entry ID.
	 * @param array<int, array<string, mixed>> $rows     Checked rows.
	 * @param array<string, mixed>             $repeater Repeater declaration.
	 * @param bool                             $copy     Whether every row is new (a copy of another entry).
	 * @return true|\WP_Error
	 */
	private static function write( int $entry, array $rows, array $repeater, bool $copy = false ) {
		$row_type = (string) $repeater['postType'];
		$existing = array_map( static fn( \WP_Post $row ): int => $row->ID, self::children( $entry, $row_type ) );
		$keep     = array();
		foreach ( array_values( $rows ) as $order => $row ) {
			$id   = $copy ? 0 : (int) ( $row['id'] ?? 0 );
			$data = array(
				'post_type'   => $row_type,
				'post_parent' => $entry,
				'post_status' => 'inherit',
				'post_title'  => sanitize_text_field( (string) $row['title'] ),
				'menu_order'  => $order,
			);
			if ( in_array( $id, $existing, true ) ) {
				$data['ID'] = $id;
				$id         = wp_update_post( wp_slash( $data ), true );
			} else {
				$id = wp_insert_post( wp_slash( $data ), true );
			}
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$meta = (array) ( $row['meta'] ?? array() );
			foreach ( (array) ( $repeater['fields'] ?? array() ) as $key => $field ) {
				$value = Registrar::sanitize( (array) $field, $meta[ $key ] ?? '' );
				if ( '' === $value ) {
					delete_post_meta( $id, (string) $key );
				} else {
					update_post_meta( $id, (string) $key, $value );
				}
			}
			$keep[] = (int) $id;
		}
		foreach ( array_diff( $existing, $keep ) as $gone ) {
			wp_trash_post( $gone );
		}
		return true;
	}

	/**
	 * Adds Duplicate to an entry of a content model type in its list, for a user who may edit it and add entries.
	 * Runs on post_row_actions.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Entry.
	 * @return array<string, string>
	 */
	public static function row_action( array $actions, \WP_Post $post ): array {
		$object = get_post_type_object( $post->post_type );
		if ( ! $object || ! Registrar::owns_post_type( $post->post_type ) || Registrar::is_repeater( $post->post_type ) || 'trash' === $post->post_status || ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( (string) $object->cap->create_posts ) ) {
			return $actions;
		}
		$url                   = wp_nonce_url( admin_url( 'admin.php?action=' . self::COPY . '&post=' . $post->ID ), self::COPY . '_' . $post->ID );
		$actions[ self::COPY ] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $url ),
			/* translators: %s: entry title. */
			esc_attr( sprintf( __( 'Duplicate “%s” as a draft', 'leitvia-studio' ), get_the_title( $post ) ) ),
			esc_html__( 'Duplicate', 'leitvia-studio' )
		);
		return $actions;
	}

	/**
	 * Copies an entry as a draft and opens it in the editor. Runs on admin_action_leitvia_studio_copy.
	 */
	public static function copy(): void {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below with the post ID.
		check_admin_referer( self::COPY . '_' . $id );
		$copy = self::duplicate( $id );
		if ( is_wp_error( $copy ) ) {
			wp_die( esc_html( $copy->get_error_message() ), '', array( 'response' => 403 ) );
		}
		wp_safe_redirect( (string) get_edit_post_link( $copy, 'url' ) );
		exit;
	}

	/**
	 * A draft copy of an entry: title, content, excerpt, declared fields (date fields moved by copyShiftDays), relations,
	 * terms, featured image and rows.
	 *
	 * @param int $id Entry ID.
	 * @return int|\WP_Error ID of the copy.
	 */
	public static function duplicate( int $id ) {
		$post   = get_post( $id );
		$object = $post ? get_post_type_object( $post->post_type ) : null;
		if ( ! $post || ! $object || ! Registrar::owns_post_type( $post->post_type ) || Registrar::is_repeater( $post->post_type ) || ! current_user_can( 'edit_post', $id ) || ! current_user_can( (string) $object->cap->create_posts ) ) {
			return new \WP_Error( 'leitvia_studio_copy', __( 'You may not duplicate this entry.', 'leitvia-studio' ) );
		}
		$model       = Model::current();
		$declaration = (array) ( $model['postTypes'][ $post->post_type ] ?? array() );
		$shift       = (int) ( $declaration['copyShiftDays'] ?? 0 );
		$copy        = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $post->post_type,
					'post_status'  => 'draft',
					/* translators: %s: title of the entry copied. */
					'post_title'   => sprintf( __( '%s (copy)', 'leitvia-studio' ), $post->post_title ),
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'menu_order'   => $post->menu_order,
					'post_parent'  => $post->post_parent,
				)
			),
			true
		);
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}
		$keys = array_keys( (array) ( $declaration['fields'] ?? array() ) );
		foreach ( (array) ( $model['relations'] ?? array() ) as $key => $relation ) {
			if ( $relation['from'] === $post->post_type ) {
				$keys[] = $key;
			}
		}
		foreach ( array_merge( $keys, array( '_thumbnail_id' ) ) as $key ) {
			if ( ! metadata_exists( 'post', $id, (string) $key ) ) {
				continue;
			}
			$value = get_post_meta( $id, (string) $key, true );
			$type  = (string) ( $declaration['fields'][ $key ]['type'] ?? '' );
			if ( $shift && in_array( $type, array( 'date', 'datetime' ), true ) && is_string( $value ) && '' !== $value ) {
				$date  = date_create_immutable( $value, wp_timezone() );
				$value = $date ? $date->modify( "+{$shift} days" )->format( 'date' === $type ? 'Y-m-d' : 'Y-m-d\TH:i:s' ) : $value;
			}
			update_post_meta( $copy, (string) $key, wp_slash( $value ) );
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $terms ) && $terms ) {
				wp_set_object_terms( $copy, $terms, $taxonomy );
			}
		}
		$rows = self::get( array( 'id' => $id ) );
		foreach ( self::repeaters( $post->post_type ) as $key => $repeater ) {
			$list = array_map( static fn( array $row ): array => array( 'meta' => (array) $row['meta'] ) + $row, (array) ( $rows->$key ?? array() ) );
			self::write( $copy, $list, $repeater, true );
		}
		return $copy;
	}

	/**
	 * Fields of a type shown as list columns.
	 *
	 * @param string $type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	private static function columns( string $type ): array {
		$fields = array_filter( (array) ( Model::current()['postTypes'][ $type ]['fields'] ?? array() ), static fn( $field ): bool => in_array( $field['type'] ?? '', self::COLUMN_TYPES, true ) );
		return array_slice( $fields, 0, self::COLUMNS, true );
	}

	/**
	 * Adds the columns after the title. Runs on manage_{$post_type}_posts_columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function add_columns( array $columns ): array {
		$type  = (string) ( get_current_screen()->post_type ?? '' );
		$added = array();
		foreach ( self::columns( $type ) as $key => $field ) {
			$added[ 'leitvia_' . $key ] = esc_html( (string) $field['label'] );
		}
		$at = array_search( 'title', array_keys( $columns ), true );
		$at = false === $at ? count( $columns ) : $at + 1;
		return array_slice( $columns, 0, $at, true ) + $added + array_slice( $columns, $at, null, true );
	}

	/**
	 * Prints a column value in the format of the site. Runs on manage_{$post_type}_posts_custom_column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Entry ID.
	 */
	public static function render_column( string $column, int $post_id ): void {
		$key    = str_starts_with( $column, 'leitvia_' ) ? substr( $column, 8 ) : '';
		$fields = self::columns( (string) get_post_type( $post_id ) );
		if ( ! isset( $fields[ $key ] ) ) {
			return;
		}
		$field = $fields[ $key ];
		$raw   = get_post_meta( $post_id, $key, true );
		if ( '' === $raw || null === $raw ) {
			printf( '<span aria-hidden="true">—</span><span class="screen-reader-text">%s</span>', esc_html__( 'No value', 'leitvia-studio' ) );
			return;
		}
		switch ( $field['type'] ) {
			case 'date':
			case 'datetime':
				$date = date_create_immutable( (string) $raw, wp_timezone() );
				echo esc_html( $date ? (string) wp_date( (string) get_option( 'date_format' ), $date->getTimestamp() ) : (string) $raw );
				return;
			case 'select':
				echo esc_html( (string) ( $field['options'][ $raw ] ?? $raw ) );
				return;
			default:
				echo esc_html( is_numeric( $raw ) ? number_format_i18n( (float) $raw, floor( (float) $raw ) === (float) $raw ? 0 : 2 ) : (string) $raw );
		}
	}
}
