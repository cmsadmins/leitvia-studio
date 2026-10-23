<?php
/**
 * Content abilities: entries of every content type, lists of content and media, site health.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Rejection;
use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/create-content, leitvia/list-content, leitvia/list-media and leitvia/get-site-health, and the part
 * of the state of a post that only content types have: date, terms, fields of the content model and featured image
 * (see state()). Content types are posts, pages and the entry types of the content model in scope; products stay with
 * Commerce. The pipeline writes that part with prepare() and set_terms().
 */
final class Content {

	/**
	 * Statuses create-content may ask for; the autonomy level may lower them to a draft.
	 */
	public const STATUSES = array( 'draft', 'publish', 'future' );

	/**
	 * Statuses list-content shows.
	 */
	public const LIST_STATUSES = array( 'publish', 'future', 'draft', 'pending' );

	/**
	 * Meta that marks a text an agent wrote with AI. Without underscore, so a person can remove it in the custom fields
	 * of the editor after taking editorial responsibility; the note in the front end goes with it.
	 */
	public const AI_META = 'leitvia_ai_generated';

	/**
	 * Meta with the source URLs of a text.
	 */
	public const SOURCES_META = '_leitvia_sources';

	/**
	 * Most items per page of list-content and list-media.
	 */
	public const MAX_LIMIT = 100;

	/**
	 * Registers the abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$date    = self::date_schema();
		$page    = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$limit   = array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => self::MAX_LIMIT,
		);
		$search  = array(
			'type'      => 'string',
			'maxLength' => 100,
		);
		$objects = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		$list    = array(
			'type'       => 'object',
			'properties' => array(
				'items' => $objects,
				'total' => array( 'type' => 'integer' ),
				'pages' => array( 'type' => 'integer' ),
			),
		);
		Abilities::write(
			'leitvia/create-content',
			__( 'Create content', 'leitvia-studio' ),
			__( 'Creates a post, a page or an entry of the content model from block markup or patterns; base_hash is empty. Post types come from leitvia/get-content-model, sections work as in leitvia/compose-page. The content runs through the same checks as leitvia/update-content. Terms take IDs, names or slugs; only tags and other flat taxonomies get new terms. Fields follow the content model; required fields must be set before publishing. The featured image needs alt text (leitvia/set-alt-text) or the decorative mark, or names the file of an image that waits in the same package from leitvia/add-media. The status is capped by the autonomy level. ai_generated adds a visible note to the text.', 'leitvia-studio' ),
			'build',
			array(
				'destructive' => false,
				'idempotent'  => false,
			),
			array(
				'properties'           => array(
					'post_type'      => array(
						'type'        => 'string',
						'description' => __( 'post, page or a post type of the content model.', 'leitvia-studio' ),
					),
					'title'          => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'content'        => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Block markup; not together with sections.', 'leitvia-studio' ),
					),
					'sections'       => Build::sections_schema(),
					'excerpt'        => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
					'slug'           => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]+$',
					),
					'parent'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the parent entry of the same hierarchical post type.', 'leitvia-studio' ),
					),
					'date'           => $date,
					'template'       => array(
						'type'        => 'string',
						'description' => __( 'Template of the contract: page templates such as page-docs for pages, single templates for the other types.', 'leitvia-studio' ),
					),
					'kind'           => array(
						'type'        => 'string',
						'enum'        => Build::PAGE_KINDS,
						'description' => __( 'Kind of a page.', 'leitvia-studio' ),
					),
					'terms'          => self::terms_schema(),
					'fields'         => self::fields_schema(),
					'featured_image' => self::image_schema( true ),
					'status'         => array(
						'type' => 'string',
						'enum' => self::STATUSES,
					),
					'ai_generated'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether AI wrote the text; it then shows a note that says so.', 'leitvia-studio' ),
					),
					'sources'        => array(
						'type'        => 'array',
						'maxItems'    => 20,
						'items'       => array(
							'type'      => 'string',
							'format'    => 'uri',
							'maxLength' => 2000,
						),
						'description' => __( 'URLs of the sources of the text, stored with it.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'post_type', 'title' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_create' )
		);
		Abilities::read(
			'leitvia/list-content',
			__( 'List content', 'leitvia-studio' ),
			__( 'Lists posts, pages and entries of the content model, newest first, with hash, terms and the open package that waits for each. Per item: ID, type, status, title, slug, date, modified, excerpt, terms, fields, link, hash and package. Filter by type, status, search text, modified since and published after a date. Only content agents may read; no comments, users or form entries.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => __( 'One post type; empty for all content types.', 'leitvia-studio' ),
					),
					'status'    => array(
						'type' => 'string',
						'enum' => array_merge( self::LIST_STATUSES, array( 'any' ) ),
					),
					'search'    => $search,
					'since'     => $date + array( 'description' => __( 'Only content modified after this date.', 'leitvia-studio' ) ),
					'after'     => $date + array( 'description' => __( 'Only content dated after this date.', 'leitvia-studio' ) ),
					'limit'     => $limit,
					'page'      => $page,
				),
				'additionalProperties' => false,
			),
			$list,
			static fn( $input ) => self::list( is_array( $input ) ? $input : array() )
		);
		Abilities::read(
			'leitvia/list-media',
			__( 'List media', 'leitvia-studio' ),
			__( 'Lists the media library, newest first: ID, URL, file name, alt text, width, height and hash (the base_hash for leitvia/set-alt-text). Filter by search text, MIME type (image or image/png) and uploaded after a date.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'search' => $search,
					'mime'   => array(
						'type'    => 'string',
						'pattern' => '^[a-z]+(/[a-z0-9.+-]+)?$',
					),
					'after'  => $date,
					'limit'  => $limit,
					'page'   => $page,
				),
				'additionalProperties' => false,
			),
			$list,
			static fn( $input ) => self::media( is_array( $input ) ? $input : array() )
		);
		Abilities::read(
			'leitvia/get-site-health',
			__( 'Get site health', 'leitvia-studio' ),
			__( 'Returns the versions of WordPress, PHP, the theme and the plugins and the updates WordPress knows of, read from its last update check; nothing is requested. Needs a user who may edit the theme options, such as the agent role.', 'leitvia-studio' ),
			array(),
			array( 'type' => 'object' ),
			static fn() => self::health()
		);
	}

	/**
	 * Schema of a date.
	 *
	 * @return array<string, mixed>
	 */
	public static function date_schema(): array {
		return array(
			'type'        => 'string',
			'pattern'     => '^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$',
			'description' => __( 'Date in the time zone of the site, YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS.', 'leitvia-studio' ),
		);
	}

	/**
	 * Schema of terms by taxonomy.
	 *
	 * @return array<string, mixed>
	 */
	public static function terms_schema(): array {
		return array(
			'type'                 => 'object',
			'description'          => __( 'Terms by taxonomy, such as {"category": ["News"], "post_tag": ["events", 12]}: IDs as numbers, names or slugs as text. They replace the terms of that taxonomy.', 'leitvia-studio' ),
			'additionalProperties' => array(
				'type'     => 'array',
				'maxItems' => 20,
				'items'    => array(
					'type'      => array( 'integer', 'string' ),
					'minLength' => 1,
					'maxLength' => 200,
				),
			),
		);
	}

	/**
	 * Schema of fields of the content model.
	 *
	 * @return array<string, mixed>
	 */
	public static function fields_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'Values of the fields and relations of the content model by meta key; null empties a field.', 'leitvia-studio' ),
		);
	}

	/**
	 * Schema of a featured image.
	 *
	 * @param bool $file Whether the image may name a file waiting in the package (create-content only).
	 * @return array<string, mixed>
	 */
	public static function image_schema( bool $file ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'         => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Attachment ID of an image, 0 to remove the featured image.', 'leitvia-studio' ),
				),
				'decorative' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the image only decorates, so it needs no alt text.', 'leitvia-studio' ),
				),
			),
			'additionalProperties' => false,
		);
		if ( $file ) {
			$schema['properties']['file'] = array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => __( 'File name of an image that waits in the same package from leitvia/add-media, instead of id; it is resolved when the package is applied.', 'leitvia-studio' ),
			);
		}
		return $schema;
	}

	/**
	 * Post types create-content writes and list-content lists: posts, pages and entry types of the content model, as far
	 * as they are in scope.
	 *
	 * @return list<string>
	 */
	public static function types(): array {
		$model = array_filter( Registrar::post_types(), static fn( string $type ): bool => ! Registrar::is_repeater( $type ) );
		return array_values( array_intersect( array_merge( array( 'post', 'page' ), $model ), Scope::post_types() ) );
	}

	/**
	 * Taxonomies of a content type agents may set: categories, tags and taxonomies of the content model.
	 *
	 * @param string $type Post type.
	 * @return list<string>
	 */
	public static function taxonomies( string $type ): array {
		return array_values( array_filter( get_object_taxonomies( $type ), static fn( string $taxonomy ): bool => in_array( $taxonomy, array( 'category', 'post_tag' ), true ) || Registrar::owns_taxonomy( $taxonomy ) ) );
	}

	/**
	 * Fields and relations of the content model for a post type, by meta key; a relation has type relation and to.
	 *
	 * @param string $type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( string $type ): array {
		$model  = Model::current();
		$fields = array();
		foreach ( (array) ( $model['postTypes'][ $type ]['fields'] ?? array() ) as $key => $field ) {
			if ( Registrar::owns_meta( $type, (string) $key ) ) {
				$fields[ (string) $key ] = (array) $field;
			}
		}
		foreach ( (array) ( $model['relations'] ?? array() ) as $key => $relation ) {
			if ( ( $relation['from'] ?? '' ) === $type && Registrar::owns_meta( $type, (string) $key ) ) {
				$fields[ (string) $key ] = array( 'type' => 'relation' ) + (array) $relation;
			}
		}
		return $fields;
	}

	/**
	 * Part of the state of a post of a content type: post_date, terms by taxonomy (IDs) and, under meta_input, the
	 * featured image and the fields of the content model. Empty for other post types.
	 *
	 * @param int    $id   Post ID.
	 * @param string $type Post type.
	 * @return array<string, mixed>
	 */
	public static function state( int $id, string $type ): array {
		if ( ! in_array( $type, self::types(), true ) ) {
			return array();
		}
		$state = array( 'post_date' => (string) get_post_field( 'post_date', $id, 'raw' ) );
		foreach ( self::taxonomies( $type ) as $taxonomy ) {
			$ids = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
			sort( $ids );
			$state['terms'][ $taxonomy ] = $ids;
		}
		if ( post_type_supports( $type, 'thumbnail' ) ) {
			$state['meta_input']['_thumbnail_id'] = (int) get_post_thumbnail_id( $id );
		}
		foreach ( array_keys( self::fields( $type ) ) as $key ) {
			$state['meta_input'][ $key ] = get_post_meta( $id, $key, true );
		}
		return $state;
	}

	/**
	 * Fields of a state for wp_insert_post(): without terms and without empty meta the post does not have yet, with
	 * post_date_gmt when the date changes, so a draft keeps the date it was given.
	 *
	 * @param int                  $id    Post ID, 0 for a new post.
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	public static function prepare( int $id, array $state ): array {
		unset( $state['terms'] );
		foreach ( (array) ( $state['meta_input'] ?? array() ) as $key => $value ) {
			if ( in_array( $value, array( '', 0, null, array() ), true ) && ! ( $id && metadata_exists( 'post', $id, (string) $key ) ) ) {
				unset( $state['meta_input'][ $key ] );
			}
		}
		$date = (string) ( $state['post_date'] ?? '' );
		if ( '' !== $date && ( ! $id || get_post_field( 'post_date', $id, 'raw' ) !== $date ) ) {
			$state['post_date_gmt'] = get_gmt_from_date( $date );
		}
		return $state;
	}

	/**
	 * Writes the terms of a state. Categories without any get the default category, as core does.
	 *
	 * @param int                  $id    Post ID.
	 * @param array<string, mixed> $state State.
	 */
	public static function set_terms( int $id, array $state ): bool {
		foreach ( (array) ( $state['terms'] ?? array() ) as $taxonomy => $terms ) {
			$set = 'category' === $taxonomy ? wp_set_post_categories( $id, array_map( 'intval', (array) $terms ) ) : wp_set_post_terms( $id, (array) $terms, (string) $taxonomy );
			if ( ! is_array( $set ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * State with date, terms, fields and featured image of the input: each checked, a key the state of the post type
	 * does not have is invalid. Shared by create-content and update-content.
	 *
	 * @param array<string, mixed> $state  State, see state().
	 * @param array<string, mixed> $input  Input with date, terms, fields and featured_image.
	 * @param array<string, mixed> $target Target for rejections.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function change( array $state, array $input, array $target ) {
		$type = (string) $state['post_type'];
		if ( isset( $input['date'] ) ) {
			$date = self::date( (string) $input['date'] );
			if ( null === $date || ! array_key_exists( 'post_date', $state ) ) {
				return Block_Edit::invalid( 'date', __( 'Use a valid date, YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS, on a post, page or entry.', 'leitvia-studio' ), $target );
			}
			$state['post_date'] = $date;
		}
		foreach ( (array) ( $input['terms'] ?? array() ) as $taxonomy => $values ) {
			$terms = self::terms( (string) $taxonomy, (array) $values, $state, $target );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
			$state['terms'][ $taxonomy ] = $terms;
		}
		$fields = self::fields( $type );
		foreach ( (array) ( $input['fields'] ?? array() ) as $key => $value ) {
			$value = isset( $fields[ $key ] ) ? self::field( $type, (string) $key, $fields[ $key ], $value, $target ) : Block_Edit::invalid( 'field', __( 'The post type has no field with this key. Read the content model for its fields.', 'leitvia-studio' ), $target + array( 'field' => (string) $key ), 'leitvia/get-content-model' );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$state['meta_input'][ $key ] = $value;
		}
		if ( isset( $input['featured_image']['id'] ) ) {
			$image = (int) $input['featured_image']['id'];
			if ( ! array_key_exists( '_thumbnail_id', (array) ( $state['meta_input'] ?? array() ) ) ) {
				return Block_Edit::invalid( 'featured_image', __( 'This post type has no featured image.', 'leitvia-studio' ), $target );
			}
			$invalid = $image ? self::invalid_image( $image, ! empty( $input['featured_image']['decorative'] ), $target ) : null;
			if ( $invalid ) {
				return $invalid;
			}
			$state['meta_input']['_thumbnail_id'] = $image;
		}
		return $state;
	}

	/**
	 * Operation of create-content.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_create( array $input ) {
		$type   = (string) ( $input['post_type'] ?? '' );
		$target = array( 'post' => 0 );
		if ( ! in_array( $type, self::types(), true ) ) {
			return Block_Edit::invalid( 'post_type', __( 'Use post, page or a post type of the content model that agents may change.', 'leitvia-studio' ), $target, 'leitvia/get-content-model' );
		}
		if ( isset( $input['content'] ) && isset( $input['sections'] ) ) {
			return Block_Edit::invalid( 'change', __( 'Send either content or sections, not both.', 'leitvia-studio' ), $target );
		}
		$sections = isset( $input['sections'] ) ? Build::sections( (array) $input['sections'] ) : array();
		if ( is_wp_error( $sections ) ) {
			return $sections;
		}
		$status = (string) ( $input['status'] ?? 'draft' );
		$date   = isset( $input['date'] ) ? self::date( (string) $input['date'] ) : '';
		$now    = current_time( 'mysql' );
		if ( null === $date || ( 'future' === $status && ( '' === $date || $date <= $now ) ) || ( 'publish' === $status && $date > $now ) ) {
			return Block_Edit::invalid( 'date', __( 'Use a valid date. Status future needs a date in the future, a date in the future needs status future.', 'leitvia-studio' ), $target );
		}
		$parent = (int) ( $input['parent'] ?? 0 );
		if ( $parent && ( ! is_post_type_hierarchical( $type ) || get_post_type( $parent ) !== $type || ! Scope::readable( $parent ) ) ) {
			return Block_Edit::invalid( 'parent', __( 'The parent must be an entry of the same hierarchical post type that agents can read.', 'leitvia-studio' ), $target + array( 'parent' => $parent ) );
		}
		$kind = (string) ( $input['kind'] ?? '' );
		if ( '' !== $kind && 'page' !== $type ) {
			return Block_Edit::invalid( 'kind', __( 'Only pages have a kind.', 'leitvia-studio' ), $target );
		}
		$template = (string) ( $input['template'] ?? '' );
		$known    = array_column( (array) ( leitvia_studio_contract()->data()['templates'] ?? array() ), 'slug' );
		if ( '' !== $template && ( ! str_starts_with( $template, 'page' === $type ? 'page' : 'single' ) || ! in_array( $template, $known, true ) ) ) {
			return Block_Edit::invalid( 'template', __( 'Use a template of the contract: a page template for pages, a single template for the other types.', 'leitvia-studio' ), $target, 'leitvia/get-contract' );
		}
		$sources = array();
		foreach ( (array) ( $input['sources'] ?? array() ) as $url ) {
			$parts = wp_parse_url( (string) $url );
			if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
				return Block_Edit::invalid( 'sources', __( 'Sources must be http or https URLs.', 'leitvia-studio' ), $target );
			}
			$sources[] = esc_url_raw( (string) $url );
		}
		$media = self::waiting_media( $input, $target );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		$meta    = array_filter(
			array(
				'_wp_page_template'  => $template,
				'_leitvia_page_kind' => $kind,
				self::AI_META        => empty( $input['ai_generated'] ) ? '' : '1',
				self::SOURCES_META   => $sources,
			)
		);
		$target += array( 'post_type' => $type ) + ( '' !== $media ? array( 'media' => $media ) : array() );
		return array(
			'area'   => 'content',
			'target' => $target,
			'kinds'  => in_array( $kind, \LeitviaStudio\Gateway\Autonomy::LEGAL_PAGES, true ) ? array( 'legal' ) : array(),
			'change' => static function ( array $state ) use ( $input, $sections, $status, $date, $parent, $meta, $type ) {
				$content = $sections ? Build::compose( $sections ) : (string) ( $input['content'] ?? '' );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
				$state = array(
					'post_title'   => (string) $input['title'],
					'post_content' => $content,
					'post_excerpt' => (string) ( $input['excerpt'] ?? '' ),
					'post_name'    => (string) ( $input['slug'] ?? '' ),
					'post_parent'  => $parent,
					'post_status'  => $status,
					'post_date'    => (string) $date,
					'terms'        => array_fill_keys( self::taxonomies( $type ), array() ),
					'meta_input'   => array_fill_keys( array_keys( self::fields( $type ) ), '' ) + ( post_type_supports( $type, 'thumbnail' ) ? array( '_thumbnail_id' => 0 ) : array() ),
				) + $state;
				$state = self::change( $state, array_diff_key( $input, array( 'date' => true ) ), array( 'post' => 0 ) );
				if ( is_wp_error( $state ) ) {
					return $state;
				}
				$missing = self::missing( $type, $state );
				if ( $missing ) {
					/* translators: %s: field labels */
					return Block_Edit::invalid( 'required', sprintf( __( 'Set these required fields before publishing, or create a draft: %s.', 'leitvia-studio' ), implode( ', ', $missing ) ), array( 'post' => 0 ), 'leitvia/get-content-model' );
				}
				$state['meta_input'] = array_filter( (array) $state['meta_input'], static fn( $value ): bool => ! in_array( $value, array( '', 0, null, array() ), true ) ) + $meta;
				$state['terms']      = array_filter( (array) $state['terms'] );
				return array_filter( $state, static fn( $value, string $key ): bool => ! in_array( $key, array( 'post_date', 'terms', 'meta_input' ), true ) || ( array() !== $value && '' !== $value ), ARRAY_FILTER_USE_BOTH );
			},
		);
	}

	/**
	 * Labels of required fields without a value when the state is public, else none.
	 *
	 * @param string               $type  Post type.
	 * @param array<string, mixed> $state State.
	 * @return list<string>
	 */
	private static function missing( string $type, array $state ): array {
		if ( ! in_array( $state['post_status'], array( 'publish', 'future' ), true ) ) {
			return array();
		}
		$missing = array();
		foreach ( self::fields( $type ) as $key => $field ) {
			if ( ! empty( $field['required'] ) && in_array( $state['meta_input'][ $key ] ?? '', array( '', null ), true ) ) {
				$missing[] = (string) ( $field['label'] ?? $key );
			}
		}
		return $missing;
	}

	/**
	 * File name of the image of add-media the featured image names, '' when it names none; a rejection when no image
	 * with that name waits in the package of the key.
	 *
	 * @param array<string, mixed> $input  Input.
	 * @param array<string, mixed> $target Target for rejections.
	 * @return string|\WP_Error
	 */
	private static function waiting_media( array $input, array $target ) {
		$file = sanitize_file_name( (string) ( $input['featured_image']['file'] ?? '' ) );
		if ( '' === $file ) {
			return '';
		}
		$package = '' !== (string) ( $input['package'] ?? '' ) ? Packages::find( (string) $input['package'] ) : 0;
		foreach ( $package ? Packages::operations( $package ) : array() as $entry ) {
			if ( 'leitvia/add-media' === ( $entry['ability'] ?? '' ) && ! isset( $entry['applied'] ) && wp_basename( (string) ( $entry['state']['file'] ?? '' ) ) === $file ) {
				return $file;
			}
		}
		return Block_Edit::invalid( 'featured_image', __( 'No image with this file name waits in the package of this key. Add it with leitvia/add-media and the same package key first, or use the ID of an image of the library.', 'leitvia-studio' ), $target, 'leitvia/add-media' );
	}

	/**
	 * Term IDs, and names of new terms of a flat taxonomy, for a taxonomy of the state.
	 *
	 * @param string               $taxonomy Taxonomy.
	 * @param array<mixed>         $values   IDs, names or slugs.
	 * @param array<string, mixed> $state    State.
	 * @param array<string, mixed> $target   Target for rejections.
	 * @return list<int|string>|\WP_Error
	 */
	private static function terms( string $taxonomy, array $values, array $state, array $target ) {
		$object = get_taxonomy( $taxonomy );
		if ( ! $object || ! isset( $state['terms'][ $taxonomy ] ) ) {
			return Block_Edit::invalid( 'taxonomy', __( 'The post type has no taxonomy of this name that agents may set.', 'leitvia-studio' ), $target + array( 'taxonomy' => $taxonomy ), 'leitvia/get-content-model' );
		}
		if ( ! current_user_can( $object->cap->assign_terms ) ) {
			return Rejection::make( 'leitvia_forbidden', __( 'Your user may not assign terms of this taxonomy.', 'leitvia-studio' ), 403, 'capability', $target + array( 'taxonomy' => $taxonomy ), '', 'never' );
		}
		$ids = array();
		$new = array();
		foreach ( $values as $value ) {
			$term = is_int( $value ) ? get_term( $value, $taxonomy ) : get_term_by( 'name', (string) $value, $taxonomy );
			$term = $term instanceof \WP_Term || is_int( $value ) ? $term : get_term_by( 'slug', sanitize_title( (string) $value ), $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$ids[] = $term->term_id;
			} elseif ( is_int( $value ) || $object->hierarchical ) {
				/* translators: %s: term as sent */
				return Block_Edit::invalid( 'term', sprintf( __( 'There is no term %s. Hierarchical taxonomies such as categories take existing terms only.', 'leitvia-studio' ), (string) $value ), $target + array( 'taxonomy' => $taxonomy ) );
			} else {
				$new[] = trim( (string) $value );
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return array_merge( $ids, array_values( array_unique( array_filter( $new ) ) ) );
	}

	/**
	 * Valid and sanitized value of a field of the content model; null empties it. Relations take posts of the target
	 * type the user may read, image fields images of the library.
	 *
	 * @param string               $type   Post type.
	 * @param string               $key    Meta key.
	 * @param array<string, mixed> $field  Field or relation of the content model.
	 * @param mixed                $value  Value.
	 * @param array<string, mixed> $target Target for rejections.
	 * @return mixed|\WP_Error
	 */
	private static function field( string $type, string $key, array $field, $value, array $target ) {
		if ( null === $value ) {
			return '';
		}
		$target += array( 'field' => $key );
		$schema  = get_registered_meta_keys( 'post', $type )[ $key ]['show_in_rest']['schema'] ?? null;
		$valid   = is_array( $schema ) ? rest_validate_value_from_schema( $value, $schema, $key ) : false;
		if ( true !== $valid ) {
			$message = is_wp_error( $valid ) ? $valid->get_error_message() : __( 'The field cannot be set.', 'leitvia-studio' );
			return Block_Edit::invalid( 'field', $message, $target, 'leitvia/get-content-model' );
		}
		$value = rest_sanitize_value_from_schema( $value, (array) $schema, $key );
		if ( 'relation' === $field['type'] ) {
			foreach ( (array) $value as $id ) {
				if ( get_post_type( (int) $id ) !== ( $field['to'] ?? '' ) || ! Scope::readable( (int) $id ) ) {
					return Block_Edit::invalid( 'field', __( 'A related ID is no entry of the target type that agents can read.', 'leitvia-studio' ), $target + array( 'related' => (int) $id ) );
				}
			}
		}
		if ( 'image' === $field['type'] && $value && ( ! wp_attachment_is_image( (int) $value ) || ! Scope::readable( (int) $value ) ) ) {
			return Block_Edit::invalid( 'field', __( 'The ID is no image of the media library.', 'leitvia-studio' ), $target );
		}
		return $value;
	}

	/**
	 * A rejection when the image is no image of the library or has no alt text that passes the check, unless it is
	 * marked as decorative; else null.
	 *
	 * @param int                  $image      Attachment ID.
	 * @param bool                 $decorative Whether the image only decorates.
	 * @param array<string, mixed> $target     Target for rejections.
	 */
	private static function invalid_image( int $image, bool $decorative, array $target ): ?\WP_Error {
		if ( ! wp_attachment_is_image( $image ) || ! Scope::readable( $image ) ) {
			return Block_Edit::invalid( 'featured_image', __( 'The ID is no image of the media library.', 'leitvia-studio' ), $target );
		}
		$alt      = $decorative ? '' : trim( (string) get_post_meta( $image, '_wp_attachment_image_alt', true ) );
		$findings = Content_Checks::alt_text( Build::image_markup( wp_basename( (string) get_attached_file( $image ) ), $alt, $decorative ), array( 'post_type' => 'page' ) );
		if ( ! $findings ) {
			return null;
		}
		return Rejection::make( 'leitvia_check_failed', __( 'The featured image needs alt text that describes it. Set it with leitvia/set-alt-text, or mark the image as decorative.', 'leitvia-studio' ), 422, 'checks', $target, 'leitvia/set-alt-text', 'once', array( 'findings' => array_map( static fn( array $finding ): array => array( 'image' => $image ) + $finding, $findings ) ) );
	}

	/**
	 * Date as Y-m-d H:i:s from YYYY-MM-DD with optional time, null when it is no valid date.
	 *
	 * @param string $value Date.
	 */
	private static function date( string $value ): ?string {
		$value = str_replace( 'T', ' ', trim( $value ) );
		$value = 10 === strlen( $value ) ? $value . ' 00:00:00' : ( 16 === strlen( $value ) ? $value . ':00' : $value );
		$date  = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, wp_timezone() );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value ? $value : null;
	}

	/**
	 * Output of list-content.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list( array $input ) {
		$type  = (string) ( $input['post_type'] ?? '' );
		$types = '' === $type ? self::types() : array_intersect( array( $type ), self::types() );
		if ( ! $types ) {
			return Block_Edit::invalid( 'post_type', __( 'Use post, page or a post type of the content model that agents may read.', 'leitvia-studio' ), array(), 'leitvia/get-content-model' );
		}
		$status = (string) ( $input['status'] ?? 'any' );
		$args   = array(
			'post_type'   => array_values( $types ),
			'post_status' => 'any' === $status ? self::LIST_STATUSES : array( $status ),
			'date_query'  => array_values(
				array_filter(
					array(
						isset( $input['since'] ) ? array(
							'column' => 'post_modified',
							'after'  => self::date( (string) $input['since'] ),
						) : null,
						isset( $input['after'] ) ? array( 'after' => self::date( (string) $input['after'] ) ) : null,
					)
				)
			),
		);
		$query  = self::query( $args, $input );
		$waits  = self::waiting();
		$items  = array();
		foreach ( $query['posts'] as $post ) {
			$terms = array();
			foreach ( self::taxonomies( $post->post_type ) as $taxonomy ) {
				$names = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
				if ( is_array( $names ) && $names ) {
					$terms[ $taxonomy ] = $names;
				}
			}
			$fields  = array_intersect_key( (array) ( self::state( $post->ID, $post->post_type )['meta_input'] ?? array() ), self::fields( $post->post_type ) );
			$excerpt = '' !== $post->post_excerpt ? $post->post_excerpt : wp_strip_all_tags( excerpt_remove_blocks( strip_shortcodes( $post->post_content ) ) );
			$items[] = array(
				'id'       => $post->ID,
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'title'    => $post->post_title,
				'slug'     => $post->post_name,
				'date'     => $post->post_date,
				'modified' => $post->post_modified,
				'excerpt'  => wp_trim_words( $excerpt, 40, '…' ),
				'terms'    => (object) $terms,
				'link'     => (string) get_permalink( $post ),
				'hash'     => Hashes::document( $post->ID ),
				'package'  => $waits[ $post->ID ] ?? null,
			) + ( $fields ? array( 'fields' => $fields ) : array() );
		}
		return array(
			'items' => $items,
			'total' => $query['total'],
			'pages' => $query['pages'],
		);
	}

	/**
	 * Output of list-media.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function media( array $input ): array {
		$args  = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => (string) ( $input['mime'] ?? '' ),
			'date_query'     => isset( $input['after'] ) ? array( array( 'after' => self::date( (string) $input['after'] ) ) ) : array(),
		);
		$query = self::query( $args, $input );
		$items = array();
		foreach ( $query['posts'] as $post ) {
			$meta    = wp_get_attachment_metadata( $post->ID );
			$items[] = array(
				'id'       => $post->ID,
				'url'      => (string) wp_get_attachment_url( $post->ID ),
				'filename' => wp_basename( (string) get_attached_file( $post->ID ) ),
				'mime'     => $post->post_mime_type,
				'alt'      => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
				'width'    => (int) ( $meta['width'] ?? 0 ),
				'height'   => (int) ( $meta['height'] ?? 0 ),
				'hash'     => Hashes::document( $post->ID ),
			);
		}
		return array(
			'items' => $items,
			'total' => $query['total'],
			'pages' => $query['pages'],
		);
	}

	/**
	 * Posts of a query, newest first, with search, limit and page of the input; only posts agents may read.
	 *
	 * @param array<string, mixed> $args  Query arguments.
	 * @param array<string, mixed> $input Input.
	 * @return array{posts: list<\WP_Post>, total: int, pages: int}
	 */
	private static function query( array $args, array $input ): array {
		$args += array(
			'has_password'           => false,
			'posts_per_page'         => (int) ( $input['limit'] ?? 20 ),
			'paged'                  => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'orderby'                => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => true,
		);
		if ( '' !== (string) ( $input['search'] ?? '' ) ) {
			$args['s'] = (string) $input['search'];
		}
		$query = new \WP_Query( $args );
		$posts = array_values( array_filter( (array) $query->posts, static fn( $post ): bool => $post instanceof \WP_Post && Scope::readable( $post ) ) );
		return array(
			'posts' => $posts,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * ID of the open change package that waits for each post, by post ID: the packages of the user, all of them for a
	 * user with manage_options, as list-packages shows them.
	 *
	 * @return array<int, int>
	 */
	private static function waiting(): array {
		$author = current_user_can( 'manage_options' ) ? 0 : get_current_user_id();
		$waits  = array();
		foreach ( array( 'open', 'partial' ) as $state ) {
			foreach ( Packages::all( $author, $state ) as $package ) {
				foreach ( Packages::operations( (int) $package['id'] ) as $entry ) {
					$post = (int) ( $entry['target']['post'] ?? 0 );
					if ( $post && ! isset( $entry['applied'] ) ) {
						$waits[ $post ] = $waits[ $post ] ?? (int) $package['id'];
					}
				}
			}
		}
		return $waits;
	}

	/**
	 * Output of get-site-health, or leitvia_forbidden for a user who may not edit the theme options.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function health() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return Rejection::make( 'leitvia_forbidden', __( 'Your user may not read the versions of this site.', 'leitvia-studio' ), 403, 'capability', array(), '', 'never' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins_update = get_site_transient( 'update_plugins' );
		$themes_update  = get_site_transient( 'update_themes' );
		$core_update    = get_site_transient( 'update_core' );
		$new            = static fn( $transient, string $key ): ?string => is_object( $transient ) && isset( $transient->response[ $key ] ) ? (string) ( (array) $transient->response[ $key ] )['new_version'] : null;
		$plugins        = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => (string) $data['Name'],
				'version' => (string) $data['Version'],
				'active'  => is_plugin_active( $file ),
				'update'  => $new( $plugins_update, $file ),
			);
		}
		$theme  = wp_get_theme();
		$parent = $theme->parent();
		$core   = null;
		foreach ( is_object( $core_update ) ? (array) ( $core_update->updates ?? array() ) : array() as $offer ) {
			if ( 'upgrade' === ( $offer->response ?? '' ) ) {
				$core = (string) $offer->current;
				break;
			}
		}
		return array(
			'wordpress' => array(
				'version' => (string) get_bloginfo( 'version' ),
				'update'  => $core,
			),
			'php'       => PHP_VERSION,
			'theme'     => array(
				'slug'    => $theme->get_stylesheet(),
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'update'  => $new( $themes_update, $theme->get_stylesheet() ),
				'parent'  => $parent ? (string) $parent->get( 'Name' ) : null,
			),
			'plugins'   => $plugins,
			'checked'   => is_object( $plugins_update ) && isset( $plugins_update->last_checked ) ? gmdate( 'Y-m-d H:i:s', (int) $plugins_update->last_checked ) : null,
		);
	}

	/**
	 * Note before a text an agent marked as written with AI, see AI_META. Runs on the_content; not in excerpts.
	 *
	 * @param string $content Content.
	 */
	public static function note( $content ): string {
		$id = (int) get_the_ID();
		if ( ! $id || ! get_post_meta( $id, self::AI_META, true ) || doing_filter( 'get_the_excerpt' ) ) {
			return (string) $content;
		}
		/**
		 * Filters the note before a text written with AI; an empty string hides it.
		 *
		 * @param string $note    Text of the note.
		 * @param int    $post_id Post ID.
		 */
		$note = (string) apply_filters( 'leitvia_studio_ai_note', __( 'This text was written with the help of AI.', 'leitvia-studio' ), $id );
		return ( '' === $note ? '' : '<p class="leitvia-ai-note">' . esc_html( $note ) . '</p>' ) . $content;
	}
}
