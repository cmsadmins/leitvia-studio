<?php
/**
 * Content model declaration: loads, merges and validates.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Model;

defined( 'ABSPATH' ) || exit;

/**
 * The content model is one JSON declaration. Code declares entries through the filter leitvia_studio_content_model,
 * the Model screen stores the rest in the option leitvia_studio_model. Code wins.
 */
final class Model {

	public const OPTION = 'leitvia_studio_model';

	/**
	 * Option that asks for flush_rewrite_rules() on the next init.
	 */
	public const FLUSH_OPTION = 'leitvia_studio_flush_rewrite';

	/**
	 * Option with the hash of the last declaration that passed the schema.
	 */
	public const CHECKED_OPTION = 'leitvia_studio_model_checked';

	/**
	 * Sections that hold keyed entries.
	 */
	public const SECTIONS = array( 'postTypes', 'taxonomies', 'relations', 'settings' );

	/**
	 * Query vars and core names that post types, taxonomies and fields must not use. From the core documentation of
	 * register_post_type() and register_taxonomy().
	 */
	private const RESERVED = array(
		'action',
		'attachment',
		'attachment_id',
		'author',
		'author_name',
		'calendar',
		'cat',
		'category',
		'category__and',
		'category__in',
		'category__not_in',
		'category_name',
		'comments_per_page',
		'comments_popup',
		'custom',
		'custom_css',
		'customize_changeset',
		'customize_messenger_channel',
		'customized',
		'cpage',
		'day',
		'debug',
		'embed',
		'error',
		'exact',
		'feed',
		'fields',
		'hour',
		'link_category',
		'm',
		'minute',
		'monthnum',
		'more',
		'name',
		'nav_menu',
		'nav_menu_item',
		'nonce',
		'nopaging',
		'oembed_cache',
		'offset',
		'order',
		'orderby',
		'p',
		'page',
		'page_id',
		'paged',
		'pagename',
		'pb',
		'perm',
		'post',
		'post__in',
		'post__not_in',
		'post_format',
		'post_mime_type',
		'post_status',
		'post_tag',
		'post_type',
		'posts',
		'posts_per_archive_page',
		'posts_per_page',
		'preview',
		'revision',
		'robots',
		's',
		'search',
		'second',
		'sentence',
		'showposts',
		'static',
		'status',
		'subpost',
		'subpost_id',
		'tag',
		'tag__and',
		'tag__in',
		'tag__not_in',
		'tag_id',
		'tag_slug__and',
		'tag_slug__in',
		'taxonomy',
		'tb',
		'term',
		'terms',
		'theme',
		'title',
		'type',
		'types',
		'user_request',
		'w',
		'withcomments',
		'withoutcomments',
		'year',
	);

	/**
	 * Merged declaration of this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $current = null;

	/**
	 * The JSON schema of a declaration.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		static $schema = null;
		$schema      ??= (array) json_decode( (string) file_get_contents( dirname( LEITVIA_STUDIO_FILE ) . '/schemas/content-model.schema.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin.
		return $schema;
	}

	/**
	 * Declaration from the Model screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Declaration from code.
	 *
	 * @return array<string, mixed>
	 */
	public static function code(): array {
		/**
		 * Declares content model entries in code. They win over entries of the same key from the Model screen and
		 * cannot be changed there.
		 *
		 * @param array<string, mixed> $declaration Declaration with the keys postTypes, taxonomies, relations, settings.
		 */
		return (array) apply_filters( 'leitvia_studio_content_model', array( 'version' => 1 ) );
	}

	/**
	 * Code and stored declaration merged, code wins. Cached per request.
	 *
	 * @return array<string, mixed>
	 */
	public static function current(): array {
		self::$current ??= self::merge( self::code(), self::stored() );
		return self::$current;
	}

	/**
	 * Drops the cached declaration.
	 */
	public static function forget(): void {
		self::$current = null;
	}

	/**
	 * Merges two declarations entry by entry; entries of $first win.
	 *
	 * @param array<string, mixed> $first  Declaration that wins.
	 * @param array<string, mixed> $second Other declaration.
	 * @return array<string, mixed>
	 */
	private static function merge( array $first, array $second ): array {
		$merged = array( 'version' => 1 );
		foreach ( self::SECTIONS as $section ) {
			$entries = (array) ( $first[ $section ] ?? array() ) + (array) ( $second[ $section ] ?? array() );
			if ( $entries ) {
				$merged[ $section ] = $entries;
			}
		}
		return $merged;
	}

	/**
	 * Source of every entry of the current declaration: 'code' or 'ui'.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function sources(): array {
		$code    = self::code();
		$sources = array();
		foreach ( self::SECTIONS as $section ) {
			foreach ( array_keys( (array) ( self::current()[ $section ] ?? array() ) ) as $key ) {
				$sources[ $section ][ $key ] = isset( $code[ $section ][ $key ] ) ? 'code' : 'ui';
			}
		}
		return $sources;
	}

	/**
	 * Validates the declaration of the Model screen together with the one from code and stores it.
	 *
	 * @param array<string, mixed> $decl    Declaration without the entries from code.
	 * @param bool                 $dry_run Only validate.
	 * @return true|\WP_Error
	 */
	public static function save( array $decl, bool $dry_run = false ) {
		$code   = self::code();
		$errors = array();
		foreach ( self::SECTIONS as $section ) {
			foreach ( array_keys( is_array( $decl[ $section ] ?? null ) ? $decl[ $section ] : array() ) as $key ) {
				if ( isset( $code[ $section ][ $key ] ) ) {
					$errors[] = self::error( "/$section/$key", __( 'This entry is declared in code and cannot be changed here.', 'leitvia-studio' ) );
				}
			}
		}
		// The entries of the screen must pass the schema on their own; references may point to entries from code.
		$errors = $errors ? $errors : self::check_schema( $decl, self::schema(), '' );
		$valid  = $errors ? self::invalid( $errors ) : self::validate( self::merge( $code, $decl ) );
		if ( true !== $valid || $dry_run ) {
			return $valid;
		}
		update_option( self::OPTION, $decl, true );
		update_option( self::FLUSH_OPTION, 1, true );
		self::forget();
		return true;
	}

	/**
	 * Checks a declaration against the schema and the rules the schema cannot express.
	 *
	 * @param array<string, mixed> $decl Declaration.
	 * @return true|\WP_Error Error with status 400 and data.errors as a list of { path, message }.
	 */
	public static function validate( array $decl ) {
		// The schema check depends on the declaration only; a declaration that passed once is not walked again.
		$hash   = md5( (string) wp_json_encode( $decl ) );
		$errors = get_option( self::CHECKED_OPTION ) === $hash ? array() : self::check_schema( $decl, self::schema(), '' );
		if ( ! $errors ) {
			if ( get_option( self::CHECKED_OPTION ) !== $hash ) {
				update_option( self::CHECKED_OPTION, $hash, true );
			}
			$errors = self::check_references( $decl );
		}
		return $errors ? self::invalid( $errors ) : true;
	}

	/**
	 * Walks the schema to report every error with its JSON pointer; core validates the values.
	 *
	 * @param mixed                $value  Value.
	 * @param array<string, mixed> $schema Schema of the value.
	 * @param string               $path   JSON pointer of the value.
	 * @return list<array{path: string, message: string}>
	 */
	private static function check_schema( $value, array $schema, string $path ): array {
		if ( 'object' !== ( $schema['type'] ?? '' ) ) {
			$valid = rest_validate_value_from_schema( $value, $schema, '' === $path ? '/' : $path );
			return is_wp_error( $valid ) ? array( self::error( $path, $valid->get_error_message() ) ) : array();
		}
		if ( ! is_array( $value ) || ( $value && array_is_list( $value ) ) ) {
			return array( self::error( $path, __( 'Expected an object.', 'leitvia-studio' ) ) );
		}
		$errors = array();
		foreach ( $schema['required'] ?? array() as $required ) {
			if ( ! array_key_exists( $required, $value ) ) {
				/* translators: %s: name of a property. */
				$errors[] = self::error( $path, sprintf( __( '"%s" is required.', 'leitvia-studio' ), $required ) );
			}
		}
		foreach ( $value as $key => $child ) {
			$key        = (string) $key;
			$sub_schema = $schema['properties'][ $key ] ?? null;
			foreach ( $sub_schema ? array() : (array) ( $schema['patternProperties'] ?? array() ) as $pattern => $candidate ) {
				if ( rest_validate_json_schema_pattern( $pattern, $key ) ) {
					$sub_schema = $candidate;
					break;
				}
			}
			if ( $sub_schema ) {
				array_push( $errors, ...self::check_schema( $child, $sub_schema, "$path/$key" ) );
			} elseif ( isset( $schema['patternProperties'] ) ) {
				/* translators: 1: key, 2: regular expression. */
				$errors[] = self::error( "$path/$key", sprintf( __( 'The key "%1$s" is not allowed. Keys must match %2$s.', 'leitvia-studio' ), $key, (string) array_key_first( $schema['patternProperties'] ) ) );
			} else {
				/* translators: %s: key. */
				$errors[] = self::error( "$path/$key", sprintf( __( '"%s" is not a known property here.', 'leitvia-studio' ), $key ) );
			}
		}
		return $errors;
	}

	/**
	 * Rules across entries: reserved and foreign keys, relation targets, options of select fields, duplicate keys.
	 *
	 * @param array<string, mixed> $decl Declaration that passed the schema.
	 * @return list<array{path: string, message: string}>
	 */
	private static function check_references( array $decl ): array {
		$errors     = array();
		$post_types = (array) ( $decl['postTypes'] ?? array() );
		$reserved   = __( 'This key is reserved by WordPress.', 'leitvia-studio' );
		$foreign    = __( 'This key is already registered by WordPress or another plugin.', 'leitvia-studio' );

		$check_fields = static function ( array $fields, string $path, string $post_type = '' ) use ( &$errors, $reserved ): void {
			foreach ( $fields as $key => $field ) {
				if ( in_array( $key, self::RESERVED, true ) ) {
					$errors[] = self::error( "$path/$key", $reserved );
				} elseif ( '' !== $post_type && self::foreign_meta( $post_type, (string) $key ) ) {
					$errors[] = self::error( "$path/$key", __( 'Another plugin already registered a field with this key for the post type.', 'leitvia-studio' ) );
				}
				if ( 'select' === $field['type'] && empty( $field['options'] ) ) {
					$errors[] = self::error( "$path/$key", __( 'A select field needs options.', 'leitvia-studio' ) );
				}
			}
		};

		$type_keys = array();
		foreach ( $post_types as $key => $post_type ) {
			$type_keys[ $key ] = "/postTypes/$key";
			foreach ( (array) ( $post_type['repeaters'] ?? array() ) as $repeater_key => $repeater ) {
				$path = "/postTypes/$key/repeaters/$repeater_key";
				if ( isset( $type_keys[ $repeater['postType'] ] ) || isset( $post_types[ $repeater['postType'] ] ) ) {
					$errors[] = self::error( "$path/postType", __( 'This post type is declared twice.', 'leitvia-studio' ) );
					continue;
				}
				$type_keys[ $repeater['postType'] ] = "$path/postType";
				$check_fields( (array) ( $repeater['fields'] ?? array() ), "$path/fields", $repeater['postType'] );
			}
			$check_fields( (array) ( $post_type['fields'] ?? array() ), "/postTypes/$key/fields", (string) $key );
		}
		foreach ( $type_keys as $key => $path ) {
			if ( in_array( $key, self::RESERVED, true ) ) {
				$errors[] = self::error( $path, $reserved );
			} elseif ( post_type_exists( $key ) && ! Registrar::owns_post_type( $key ) ) {
				$errors[] = self::error( $path, $foreign );
			}
		}

		foreach ( (array) ( $decl['taxonomies'] ?? array() ) as $key => $taxonomy ) {
			if ( in_array( $key, self::RESERVED, true ) ) {
				$errors[] = self::error( "/taxonomies/$key", $reserved );
			} elseif ( taxonomy_exists( $key ) && ! Registrar::owns_taxonomy( $key ) ) {
				$errors[] = self::error( "/taxonomies/$key", $foreign );
			} elseif ( isset( $type_keys[ $key ] ) ) {
				$errors[] = self::error( "/taxonomies/$key", __( 'A post type of the content model already uses this key.', 'leitvia-studio' ) );
			}
			foreach ( $taxonomy['postTypes'] as $index => $post_type ) {
				if ( ! isset( $post_types[ $post_type ] ) && ( ! post_type_exists( $post_type ) || Registrar::owns_post_type( $post_type ) ) ) {
					$errors[] = self::error( "/taxonomies/$key/postTypes/$index", __( 'This post type is not declared.', 'leitvia-studio' ) );
				}
			}
		}

		foreach ( (array) ( $decl['relations'] ?? array() ) as $key => $relation ) {
			if ( ! isset( $post_types[ $relation['from'] ] ) ) {
				$errors[] = self::error( "/relations/$key/from", __( 'This post type is not declared.', 'leitvia-studio' ) );
			}
			// Targets may also be post types of WordPress or other plugins (pages, posts) that the REST API offers.
			if ( ! isset( $post_types[ $relation['to'] ] ) && ! self::foreign_target( $relation['to'] ) ) {
				$errors[] = self::error( "/relations/$key/to", __( 'This post type is not declared and not available in the REST API.', 'leitvia-studio' ) );
			}
			if ( in_array( $key, self::RESERVED, true ) ) {
				$errors[] = self::error( "/relations/$key", $reserved );
			} elseif ( isset( $post_types[ $relation['from'] ]['fields'][ $key ] ) ) {
				$errors[] = self::error( "/relations/$key", __( 'The post type already has a field with this key.', 'leitvia-studio' ) );
			} elseif ( self::foreign_meta( $relation['from'], (string) $key ) ) {
				$errors[] = self::error( "/relations/$key", __( 'Another plugin already registered a field with this key for the post type.', 'leitvia-studio' ) );
			}
		}

		// Public post types and taxonomies need distinct URL slugs.
		$slugs = array();
		foreach ( array( 'postTypes', 'taxonomies' ) as $section ) {
			foreach ( (array) ( $decl[ $section ] ?? array() ) as $key => $entry ) {
				if ( empty( $entry['public'] ) ) {
					continue;
				}
				$slug = $entry['slug'] ?? $key;
				if ( isset( $slugs[ $slug ] ) ) {
					/* translators: %s: JSON pointer of the other entry. */
					$errors[] = self::error( "/$section/$key", sprintf( __( 'The URL slug is already used by %s.', 'leitvia-studio' ), $slugs[ $slug ] ) );
				}
				$slugs[ $slug ] = "/$section/$key";
			}
		}

		$check_fields( (array) ( $decl['settings'] ?? array() ), '/settings' );
		return $errors;
	}

	/**
	 * Whether a post type that the content model does not register can be a relation target: a public type with REST
	 * support (the relation picker reads it there) other than media, and not a leftover of the content model.
	 *
	 * @param string $post_type Post type.
	 */
	private static function foreign_target( string $post_type ): bool {
		$object = get_post_type_object( $post_type );
		return $object && $object->public && $object->show_in_rest && 'attachment' !== $post_type
			&& ! Registrar::owns_post_type( $post_type ) && ! Registrar::is_repeater( $post_type );
	}

	/**
	 * Whether a plugin other than Studio registered the meta key for the post type or for all post types.
	 *
	 * @param string $post_type Post type.
	 * @param string $key       Meta key.
	 */
	private static function foreign_meta( string $post_type, string $key ): bool {
		return ( registered_meta_key_exists( 'post', $key, $post_type ) && ! Registrar::owns_meta( $post_type, $key ) )
			|| registered_meta_key_exists( 'post', $key );
	}

	/**
	 * One error entry.
	 *
	 * @param string $path    JSON pointer.
	 * @param string $message Message.
	 * @return array{path: string, message: string}
	 */
	private static function error( string $path, string $message ): array {
		return array(
			'path'    => $path,
			'message' => $message,
		);
	}

	/**
	 * The error of an invalid declaration.
	 *
	 * @param list<array{path: string, message: string}> $errors Errors.
	 */
	private static function invalid( array $errors ): \WP_Error {
		return new \WP_Error(
			'leitvia_studio_invalid_model',
			__( 'The content model is invalid.', 'leitvia-studio' ),
			array(
				'status' => 400,
				'errors' => $errors,
			)
		);
	}
}
