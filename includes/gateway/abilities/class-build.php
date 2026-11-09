<?php
/**
 * Build abilities: pages from patterns, block changes, patterns and media.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Autonomy;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Rejection;
use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Lab\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/compose-page, leitvia/update-block, leitvia/create-pattern, leitvia/vary-pattern,
 * leitvia/add-media and leitvia/crop-media. Each one describes an operation for Pipeline::run(); pattern markup comes from the pattern
 * registry or the Lab, never from files.
 */
final class Build {

	/**
	 * Largest media file in bytes.
	 */
	public const MAX_MEDIA_BYTES = 5 * MB_IN_BYTES;

	/**
	 * Page kinds compose-page may set in _leitvia_page_kind.
	 */
	public const PAGE_KINDS = array( 'imprint', 'privacy', 'terms', 'withdrawal', 'accessibility', 'editorial', 'contact', 'authors' );

	/**
	 * Post types whose changes belong to the area structure.
	 */
	private const STRUCTURE_TYPES = array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );

	/**
	 * Registers the abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$create = array(
			'destructive' => false,
			'idempotent'  => false,
		);
		Abilities::write(
			'leitvia/compose-page',
			__( 'Compose page', 'leitvia-studio' ),
			__( 'Creates a page from patterns in the order of a page frame of the contract. Plan it with leitvia/plan-page first; base_hash is empty for the new page. Per section texts (also quote citations and details summaries), links and images at block IDs of the pattern outline can change, and remove and repeat take blocks from a parent or add copies, for fewer or more entries than the pattern; a text that would drop a link is refused. Edits apply in order. Each section keeps metadata.patternName (core/block/<ID> for a Lab pattern). Unknown patterns are rejected with a pointer to leitvia/list-patterns, deprecated ones with their replacement, Lab patterns that are not approved (also through a part) with leitvia_pattern_not_approved. Later changes go through leitvia/update-content and leitvia/update-block.', 'leitvia-studio' ),
			'build',
			$create,
			array(
				'properties'           => array(
					'title'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'frame'    => array(
						'type'        => 'string',
						'description' => __( 'Type of a page frame from pageFrames in the contract, for example home or service.', 'leitvia-studio' ),
					),
					'sections' => self::sections_schema(),
					'kind'     => array(
						'type' => 'string',
						'enum' => self::PAGE_KINDS,
					),
					'slug'     => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]+$',
					),
					'parent'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the parent page.', 'leitvia-studio' ),
					),
					'template' => array(
						'type'        => 'string',
						'description' => __( 'Page template of the contract instead of the one of the frame, for example page-docs.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'title', 'frame', 'sections' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_compose' )
		);
		$edit = self::edit_schema( true );
		unset( $edit['required'] );
		$edit['properties']['post'] = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$edit['required']           = array( 'post', 'block' );
		Abilities::write(
			'leitvia/update-block',
			__( 'Update block', 'leitvia-studio' ),
			__( 'Changes one block found by its ID or path ID: attributes from the block catalog and the level of a heading, the text of a paragraph, heading, list item or button, the citation of a quote, the summary of a details block, the link of a button or of the one link in a text (link_text names the linked part), or the image of an image or cover block with alt text. add_link sets a new link on a passage of a text, caption the caption of an image, video, audio, gallery, table or embed. Attributes that a static block keeps in its markup, such as prefix, value, suffix and label of a counter, change like any other. query of a core/query merges into its settings. tracks of a core/video replaces its text tracks: each names a WebVTT file from leitvia/add-media by id, with kind, srclang and label. A spotlight point moves with attributes x and y in percent. The effect on scroll (leitviaAnimation, leitviaMotion) changes on every block that has it, core blocks and saved markup included, since only the block comment changes. Static markup is rebuilt with the HTML API; changes it cannot rebuild safely are rejected with leitvia_static_markup. Read the post with leitvia/get-page-outline first and send its hash as base_hash; get-block shows the current attributes. For several text changes in one post use leitvia/update-content.', 'leitvia-studio' ),
			'build',
			array(
				'destructive' => true,
				'idempotent'  => false,
			),
			$edit,
			array( self::class, 'describe_update' )
		);
		$content = array(
			'type'      => 'string',
			'minLength' => 1,
		);
		Abilities::write(
			'leitvia/create-pattern',
			__( 'Create pattern', 'leitvia-studio' ),
			__( 'Creates a pattern in the Lab as a draft with version 0.1.0 from block markup; base_hash is empty. Blocks and their attributes come from leitvia/list-blocks. The checks run on its markup, design tokens included. To build on an existing pattern use leitvia/vary-pattern.', 'leitvia-studio' ),
			'build',
			$create,
			array(
				'properties'           => array(
					'title'   => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'content' => $content,
					'synced'  => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'title', 'content' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_create_pattern' )
		);
		Abilities::write(
			'leitvia/vary-pattern',
			__( 'Vary pattern', 'leitvia-studio' ),
			__( 'Creates a variant of a Lab pattern (block:<ID>) or a theme pattern (slug) as a draft in the Lab, from new markup or from the original with edits at block IDs; base_hash is empty. Originals come from leitvia/list-patterns, block IDs for edits from leitvia/get-pattern.', 'leitvia-studio' ),
			'build',
			$create,
			array(
				'properties'           => array(
					'original' => array( 'type' => 'string' ),
					'title'    => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'content'  => $content,
					'edits'    => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => self::edit_schema( false ),
					),
				),
				'required'             => array( 'original' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_vary_pattern' )
		);
		Abilities::write(
			'leitvia/add-media',
			__( 'Add media', 'leitvia-studio' ),
			__( 'Adds an image to the media library from a URL of this site or as Base64 data up to 5 MB, with alt text or marked as decorative. It also takes a WebVTT file with captions for a video (.vtt, UTF-8, starts with WEBVTT, no alt text). It needs the right to upload files. URLs of other hosts are rejected; nothing is fetched from them. base_hash is empty. Images already in the library are used by their ID in compose-page and update-block, a WebVTT file by its ID in tracks of update-block.', 'leitvia-studio' ),
			'build',
			$create,
			array(
				'properties'           => array(
					'url'        => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'data'       => array(
						'type'      => 'string',
						'maxLength' => (int) ceil( self::MAX_MEDIA_BYTES / 3 ) * 4 + 4,
					),
					'filename'   => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'title'      => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'alt'        => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
					'decorative' => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_add_media' )
		);
		$percent = static fn( string $description ): array => array(
			'type'        => 'number',
			'minimum'     => 0,
			'maximum'     => 100,
			'description' => $description,
		);
		Abilities::write(
			'leitvia/crop-media',
			__( 'Crop media', 'leitvia-studio' ),
			__( 'Crops an image of the media library into a new image. The rectangle is in percent of the image size; the original stays. The new image takes the alt text of the original and its title with an addition. Use it for images with text burned in: keep the part without the text band. Only JPEG, PNG, GIF, WebP and AVIF, no SVG; it needs the right to upload files. base_hash is empty. A live crop gets a change package, so leitvia/rollback deletes the new image with its files.', 'leitvia-studio' ),
			'build',
			$create,
			array(
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the image in the media library, from leitvia/list-media.', 'leitvia-studio' ),
					),
					'x'            => $percent( __( 'Left edge of the rectangle in percent of the image width.', 'leitvia-studio' ) ),
					'y'            => $percent( __( 'Top edge of the rectangle in percent of the image height.', 'leitvia-studio' ) ),
					'width'        => $percent( __( 'Width of the rectangle in percent of the image width, above 0; x plus width is at most 100.', 'leitvia-studio' ) ),
					'height'       => $percent( __( 'Height of the rectangle in percent of the image height, above 0; y plus height is at most 100.', 'leitvia-studio' ) ),
					'title_suffix' => array(
						'type'        => 'string',
						'maxLength'   => 100,
						'description' => __( 'Addition to the title of the original, in brackets after it; without it the addition is cropped.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'id', 'x', 'y', 'width', 'height' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_crop_media' )
		);
	}

	/**
	 * Schema of the sections of compose-page and create-content.
	 *
	 * @return array<string, mixed>
	 */
	public static function sections_schema(): array {
		return array(
			'type'     => 'array',
			'minItems' => 1,
			'maxItems' => 30,
			'items'    => array(
				'type'                 => 'object',
				'properties'           => array(
					'pattern' => array(
						'type'        => 'string',
						'description' => __( 'Pattern slug such as leitvia/hero-split-image, or block:<ID> for a Lab pattern.', 'leitvia-studio' ),
					),
					'edits'   => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => self::edit_schema( false ),
					),
				),
				'required'             => array( 'pattern' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Schema of one block edit.
	 *
	 * @param bool $attributes Whether catalog attributes may change too (update-block only).
	 * @return array<string, mixed>
	 */
	private static function edit_schema( bool $attributes ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'block'     => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => __( 'Block ID or path ID from the outline.', 'leitvia-studio' ),
				),
				'text'      => array(
					'type'        => 'string',
					'maxLength'   => 5000,
					'description' => __( 'Plain text of a paragraph, heading, list item or button, the citation of a quote or the summary of a details block.', 'leitvia-studio' ),
				),
				'link_text' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 500,
					'description' => __( 'Part of text that stays linked when the text holds a link, such as the mail address in a contact line. Without it the link stays around its old text, and a text without that is refused unless the link only points to #.', 'leitvia-studio' ),
				),
				'url'       => array(
					'type'        => 'string',
					'maxLength'   => 2000,
					'description' => __( 'Link of a button, or of the one link in a paragraph, heading or list item (mailto: and tel: work too).', 'leitvia-studio' ),
				),
				'add_link'  => self::add_link_schema(),
				'caption'   => self::caption_schema(),
				'image'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'         => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'alt'        => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
						'decorative' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'block' ),
			'additionalProperties' => false,
		);
		if ( ! $attributes ) {
			$schema['properties']['remove'] = array(
				'type'        => 'boolean',
				'description' => __( 'Removes the block from its parent, for a section with fewer entries than the pattern. Later edits of the list count from the result, so work from the last block to the first.', 'leitvia-studio' ),
			);
			$schema['properties']['repeat'] = array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 20,
				'description' => __( 'Number of copies to add right after the block, for a section with more entries than the pattern. Later edits reach the copies by the IDs they get.', 'leitvia-studio' ),
			);
		}
		if ( $attributes ) {
			$schema['properties']['attributes'] = array(
				'type'        => 'object',
				'description' => __( 'Attributes from leitvia/list-blocks; for a core/heading its level, 1 to 6; for a core/query query with perPage, pages, offset, postType, order, orderBy, sticky and inherit (perPage counts with inherit false); for a core/video tracks, each with id, kind, srclang and label; leitviaAnimation and leitviaMotion on every block that lists them (null removes leitviaMotion); on core media blocks align and className (is-style-<name>), on core/image also width, height, aspectRatio, scale, sizeSlug, linkDestination, href, lightbox (null removes one).', 'leitvia-studio' ),
			);
		}
		return $schema;
	}

	/**
	 * Schema of a new link on a passage of a text, shared by the block edits and update-content.
	 *
	 * @return array<string, mixed>
	 */
	public static function add_link_schema(): array {
		return array(
			'type'                 => 'object',
			'description'          => __( 'New link around a passage of a paragraph, heading, list item, citation or summary, as text with the exact words and url. Other links and inline formatting stay; the first place outside a link counts.', 'leitvia-studio' ),
			'properties'           => array(
				'text' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 500,
				),
				'url'  => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 2000,
				),
			),
			'required'             => array( 'text', 'url' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Schema of a caption, shared by the block edits and update-content.
	 *
	 * @return array<string, mixed>
	 */
	public static function caption_schema(): array {
		return array(
			'type'        => 'string',
			'maxLength'   => 1000,
			'description' => __( 'Caption of an image, video, audio, gallery, table or embed as plain text; empty removes it.', 'leitvia-studio' ),
		);
	}

	/**
	 * Operation of compose-page.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_compose( array $input ) {
		$frame = null;
		foreach ( (array) ( leitvia_studio_contract()->data()['pageFrames'] ?? array() ) as $candidate ) {
			if ( ( $candidate['type'] ?? null ) === ( $input['frame'] ?? '' ) ) {
				$frame = $candidate;
			}
		}
		if ( ! $frame ) {
			return Block_Edit::invalid( 'frame', __( 'The contract has no page frame of this type.', 'leitvia-studio' ), array(), 'leitvia/get-contract' );
		}
		$sections = self::sections( (array) ( $input['sections'] ?? array() ) );
		if ( is_wp_error( $sections ) ) {
			return $sections;
		}
		$type     = 'single' === ( $frame['template'] ?? '' ) ? 'post' : 'page';
		$template = (string) ( $input['template'] ?? $frame['template'] ?? 'page' );
		$known    = array_column( (array) ( leitvia_studio_contract()->data()['templates'] ?? array() ), 'slug' );
		if ( isset( $input['template'] ) && ( 'page' !== $type || ! str_starts_with( $template, 'page' ) || ! in_array( $template, $known, true ) ) ) {
			return Block_Edit::invalid( 'template', __( 'Use a page template of the contract, for example page-docs.', 'leitvia-studio' ), array(), 'leitvia/get-contract' );
		}
		$parent = (int) ( $input['parent'] ?? 0 );
		if ( $parent && ( get_post_type( $parent ) !== $type || ! Scope::readable( $parent ) ) ) {
			return Block_Edit::invalid( 'parent', __( 'The parent must be a page agents can read.', 'leitvia-studio' ), array( 'post' => $parent ) );
		}
		$kind = (string) ( $input['kind'] ?? '' );
		$meta = array_filter(
			array(
				'_wp_page_template'  => 'page' === $type && 'page' !== $template ? $template : '',
				'_leitvia_page_kind' => $kind,
			)
		);
		return array(
			'area'   => 'content',
			'target' => array(
				'post'      => 0,
				'post_type' => $type,
			),
			'kinds'  => in_array( $kind, Autonomy::LEGAL_PAGES, true ) ? array( 'legal' ) : array(),
			'change' => static function ( array $state ) use ( $sections, $input, $meta, $parent ) {
				$content = self::compose( $sections );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
				return array(
					'post_title'   => (string) $input['title'],
					'post_content' => $content,
					'post_name'    => (string) ( $input['slug'] ?? '' ),
					'post_parent'  => $parent,
					'meta_input'   => $meta,
				) + $state;
			},
		);
	}

	/**
	 * Patterns of the sections with their edits, or the rejection of the first unknown, deprecated or unapproved one.
	 *
	 * @param array<mixed> $sections Sections with pattern and edits.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	public static function sections( array $sections ) {
		$found = array();
		foreach ( $sections as $section ) {
			$pattern = self::pattern( (string) ( $section['pattern'] ?? '' ) );
			if ( is_wp_error( $pattern ) ) {
				return $pattern;
			}
			// An agent's own Lab pattern reaches a page only after a person approved it.
			$status = $pattern['id'] ? Status::effective( 'block:' . $pattern['id'] ) : 'approved';
			if ( 'approved' !== $status ) {
				return Rejection::make( 'leitvia_pattern_not_approved', __( 'The Lab pattern is not approved yet. Use an approved pattern, or ask a person to approve this one in the Lab.', 'leitvia-studio' ), 422, 'lab_status', array( 'pattern' => $pattern['ref'] ), 'leitvia/list-patterns', 'once', array( 'status' => $status ) );
			}
			$found[] = $pattern + array( 'edits' => (array) ( $section['edits'] ?? array() ) );
		}
		return $found;
	}

	/**
	 * Markup of sections from sections(): each pattern with its edits and metadata.patternName.
	 *
	 * @param list<array<string, mixed>> $sections Sections.
	 * @return string|\WP_Error
	 */
	public static function compose( array $sections ) {
		$parts = array();
		foreach ( $sections as $section ) {
			$content = self::edit( (string) $section['content'], (array) $section['edits'], array( 'pattern' => $section['ref'] ) );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$parts[] = self::name_sections( $content, (string) $section['name'] );
		}
		return implode( "\n\n", $parts );
	}

	/**
	 * Operation of update-block.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update( array $input ) {
		$post   = (int) ( $input['post'] ?? 0 );
		$block  = (string) ( $input['block'] ?? '' );
		$change = array_intersect_key( $input, array_flip( array( 'attributes', 'text', 'link_text', 'url', 'add_link', 'caption', 'image' ) ) );
		if ( ! array_diff_key( $change, array( 'link_text' => true ) ) ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: attributes, text, url, add_link, caption or image.', 'leitvia-studio' ), array( 'post' => $post ) );
		}
		return array(
			'area'   => in_array( get_post_type( $post ), self::STRUCTURE_TYPES, true ) ? 'structure' : 'content',
			'target' => array(
				'post'  => $post,
				'block' => $block,
			),
			'change' => static function ( array $state ) use ( $post, $block, $change ) {
				$content = Block_Edit::apply( (string) $state['post_content'], $block, $change, array( 'post' => $post ) );
				return is_wp_error( $content ) ? $content : array( 'post_content' => $content ) + $state;
			},
		);
	}

	/**
	 * Operation of create-pattern.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function describe_create_pattern( array $input ): array {
		$sync = empty( $input['synced'] ) ? 'unsynced' : '';
		return self::new_pattern( (string) $input['title'], static fn(): string => (string) $input['content'], array_filter( array( 'wp_pattern_sync_status' => $sync ) ) );
	}

	/**
	 * Operation of vary-pattern.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_vary_pattern( array $input ) {
		$ref      = (string) ( $input['original'] ?? '' );
		$original = self::pattern( $ref, false );
		if ( is_wp_error( $original ) ) {
			return $original;
		}
		$id = $original['id'];
		if ( $id && (int) get_post_meta( $id, '_leitvia_lab_variant_of', true ) ) {
			return Block_Edit::invalid( 'variant', __( 'Variants are created from the original pattern.', 'leitvia-studio' ), array( 'post' => $id ) );
		}
		$meta  = $id ? array(
			'_leitvia_lab_variant_of' => $id,
			'wp_pattern_sync_status'  => (string) get_post_meta( $id, 'wp_pattern_sync_status', true ),
		) : array(
			'_leitvia_lab_source_pattern' => $original['name'],
			'wp_pattern_sync_status'      => 'unsynced',
		);
		$title = (string) ( $input['title'] ?? '' );
		/* translators: %s: title of the original pattern. */
		$title = '' !== $title ? $title : sprintf( __( '%s (variant)', 'leitvia-studio' ), $original['title'] );
		$build = static fn() => self::edit( (string) ( $input['content'] ?? $original['content'] ), (array) ( $input['edits'] ?? array() ), array( 'pattern' => $ref ) );
		return self::new_pattern( $title, $build, array_filter( $meta ) );
	}

	/**
	 * Operation that creates a Lab pattern as a draft.
	 *
	 * @param string               $title   Title.
	 * @param callable             $content Returns the markup or a WP_Error.
	 * @param array<string, mixed> $meta    Further meta.
	 * @return array<string, mixed>
	 */
	private static function new_pattern( string $title, callable $content, array $meta ): array {
		return array(
			'area'   => 'structure',
			'target' => array(
				'post'      => 0,
				'post_type' => 'wp_block',
			),
			'change' => static function ( array $state ) use ( $title, $content, $meta ) {
				$markup = $content();
				if ( is_wp_error( $markup ) ) {
					return $markup;
				}
				return array(
					'post_title'   => $title,
					'post_content' => $markup,
					'meta_input'   => array(
						'_leitvia_lab_status'  => 'draft',
						'_leitvia_lab_version' => '0.1.0',
					) + $meta,
				) + $state;
			},
		);
	}

	/**
	 * Operation of add-media. The file is checked here and put in the private area of packages by the change; the
	 * pipeline moves it into the uploads folder only when it writes the attachment.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_add_media( array $input ) {
		$decorative = ! empty( $input['decorative'] );
		$alt        = $decorative ? '' : trim( (string) ( $input['alt'] ?? '' ) );
		$url        = (string) ( $input['url'] ?? '' );
		$data       = (string) ( $input['data'] ?? '' );
		if ( ( '' === $url ) === ( '' === $data ) ) {
			return Block_Edit::invalid( 'source', __( 'Send either a URL of this site or Base64 data with a file name.', 'leitvia-studio' ) );
		}
		$name = sanitize_file_name( wp_basename( '' !== $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : (string) ( $input['filename'] ?? '' ) ) );
		$file = '' !== $url ? self::local_file( $url ) : self::decode( $data );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( false === $file || '' === $name ) {
			return Block_Edit::invalid( 'source', __( 'The data is not valid Base64, or the file name is missing.', 'leitvia-studio' ) );
		}
		if ( strlen( $file ) > self::MAX_MEDIA_BYTES ) {
			return Block_Edit::invalid( 'size', __( 'The file is larger than 5 MB.', 'leitvia-studio' ) );
		}
		$track = null;
		$type  = Packages::image_type( $file, $name );
		if ( ! $type ) {
			$track = Packages::track_type( $file, $name );
			$type  = $track;
		}
		if ( ! $type ) {
			return Block_Edit::invalid( 'type', __( 'Only images of a type this site allows can be added, JPEG, PNG, GIF, WebP or AVIF, and WebVTT files that start with WEBVTT in UTF-8.', 'leitvia-studio' ) );
		}
		$name     = $type['name'];
		$findings = $track ? array() : Content_Checks::alt_text( self::image_markup( $name, $decorative ? '' : $alt, $decorative ), array( 'post_type' => 'page' ) );
		if ( $findings ) {
			return Rejection::make( 'leitvia_check_failed', __( 'The image needs alt text that describes it, or mark it as decorative.', 'leitvia-studio' ), 422, 'checks', array(), '', 'once', array( 'findings' => $findings ) );
		}
		$title = (string) ( $input['title'] ?? '' );
		return array(
			'area'   => 'content',
			'target' => array(
				'post'      => 0,
				'post_type' => 'attachment',
			),
			'change' => static fn( array $state ) => self::new_media( $state, $name, $file, $type['type'], $alt, $title ),
		);
	}

	/**
	 * Operation of crop-media: a new image from a rectangle of an image of the library, in percent of its size, with
	 * the alt text of the original and its title with an addition. The original stays; the pipeline writes the new
	 * image like one from add-media, and a rollback deletes it with its files. The image is cut in the change, after
	 * the rate limit and the scope checks.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_crop_media( array $input ) {
		$id = (int) ( $input['id'] ?? 0 );
		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! Scope::readable( $id ) ) {
			return Rejection::make( 'leitvia_not_found', __( 'There is no file with this ID in the media library. List the media for the current IDs.', 'leitvia-studio' ), 404, 'id', array( 'post' => $id ), 'leitvia/list-media' );
		}
		$source = (string) get_attached_file( $id );
		// wp_attachment_is_image() knows raster formats only, so an SVG is refused like any other file.
		if ( ! wp_attachment_is_image( $id ) || ! is_file( $source ) ) {
			return Block_Edit::invalid( 'type', __( 'Only images of the media library in JPEG, PNG, GIF, WebP or AVIF can be cropped, no SVG or other file.', 'leitvia-studio' ), array( 'post' => $id ) );
		}
		$box = array();
		foreach ( array( 'x', 'y', 'width', 'height' ) as $key ) {
			$box[ $key ] = (float) ( $input[ $key ] ?? 0 );
		}
		// A small tolerance, so 33.3 and 66.7 still end at the edge.
		if ( $box['width'] <= 0 || $box['height'] <= 0 || min( $box['x'], $box['y'] ) < 0 || $box['x'] + $box['width'] > 100.000001 || $box['y'] + $box['height'] > 100.000001 ) {
			return Block_Edit::invalid( 'rectangle', __( 'The rectangle must lie within the image: x, y, width and height in percent from 0 to 100, width and height above 0, x plus width and y plus height at most 100.', 'leitvia-studio' ), array( 'post' => $id ) );
		}
		$suffix = trim( (string) ( $input['title_suffix'] ?? '' ) );
		$title  = (string) get_post_field( 'post_title', $id, 'raw' );
		$title  = ( '' !== $title ? $title : (string) pathinfo( $source, PATHINFO_FILENAME ) ) . ' (' . ( '' !== $suffix ? $suffix : __( 'cropped', 'leitvia-studio' ) ) . ')';
		$alt    = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		return array(
			'area'   => 'content',
			'target' => array(
				'post'      => 0,
				'post_type' => 'attachment',
			),
			'record' => true,
			'change' => static function ( array $state ) use ( $source, $box, $alt, $title ) {
				$failed = Rejection::make( 'leitvia_execution_failed', __( 'The image could not be cropped; nothing was changed. Try again later.', 'leitvia-studio' ), 500, 'execution', array(), '', 'never' );
				$editor = wp_get_image_editor( $source );
				if ( is_wp_error( $editor ) ) {
					return $failed;
				}
				$size   = $editor->get_size();
				$left   = (int) round( $size['width'] * $box['x'] / 100 );
				$top    = (int) round( $size['height'] * $box['y'] / 100 );
				$width  = max( 1, min( $size['width'] - $left, (int) round( $size['width'] * $box['width'] / 100 ) ) );
				$height = max( 1, min( $size['height'] - $top, (int) round( $size['height'] * $box['height'] / 100 ) ) );
				$temp   = get_temp_dir();
				$saved  = $editor->crop( $left, $top, $width, $height );
				$saved  = is_wp_error( $saved ) ? $saved : $editor->save( $temp . wp_unique_filename( $temp, wp_basename( $editor->generate_filename( 'crop' ) ) ) );
				if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
					return $failed;
				}
				$files = Packages::filesystem();
				$bytes = $files ? $files->get_contents( (string) $saved['path'] ) : false;
				wp_delete_file( (string) $saved['path'] );
				$name = pathinfo( $source, PATHINFO_FILENAME ) . '-crop.' . pathinfo( (string) $saved['path'], PATHINFO_EXTENSION );
				$type = false === $bytes ? null : Packages::image_type( $bytes, $name );
				return $type ? self::new_media( $state, $type['name'], (string) $bytes, $type['type'], $alt, $title ) : $failed;
			},
		);
	}

	/**
	 * State of a new attachment of add-media and crop-media. The file waits in the private area of packages; the
	 * pipeline moves it into the uploads folder only when it writes the attachment.
	 *
	 * @param array<string, mixed> $state State.
	 * @param string               $name  File name.
	 * @param string               $bytes File content.
	 * @param string               $type  MIME type.
	 * @param string               $alt   Alt text.
	 * @param string               $title Title, '' for the file name.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function new_media( array $state, string $name, string $bytes, string $type, string $alt, string $title ) {
		$path = Packages::stash_file( $name, $bytes );
		if ( '' === $path ) {
			return Rejection::make( 'leitvia_execution_failed', __( 'The file could not be saved; nothing was changed. Try again later.', 'leitvia-studio' ), 500, 'execution', array(), '', 'never' );
		}
		$size = 'text/vtt' === $type ? false : wp_getimagesize( $path );
		// ponytail: no sub-sizes; a person can generate them with a thumbnail tool when the theme needs them.
		// The pipeline sets the file path of the metadata when it moves the file into the uploads folder.
		$meta = 'text/vtt' === $type ? array() : array(
			'width'  => (int) ( $size[0] ?? 0 ),
			'height' => (int) ( $size[1] ?? 0 ),
		);
		return array(
			'post_title'     => '' !== $title ? $title : (string) pathinfo( $name, PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
			'post_mime_type' => $type,
			'file'           => $path,
			'meta_input'     => array_filter(
				array(
					'_wp_attachment_image_alt' => $alt,
					'_wp_attachment_metadata'  => $meta,
				)
			),
		) + $state;
	}

	/**
	 * Markup and display data of a pattern by slug or block:<ID>, or a rejection for an unknown or deprecated one. name
	 * is the metadata.patternName of its sections: the slug, or core/block/<ID> as core names a Lab pattern.
	 *
	 * @param string $ref        Slug of a registered pattern or block:<ID>.
	 * @param bool   $deprecated Whether to reject deprecated patterns.
	 * @return array{ref: string, id: int, name: string, title: string, content: string}|\WP_Error
	 */
	public static function pattern( string $ref, bool $deprecated = true ) {
		$id      = preg_match( '/^block:([1-9]\d*)$/D', $ref, $match ) ? (int) $match[1] : 0;
		$pattern = $id ? null : \WP_Block_Patterns_Registry::get_instance()->get_registered( $ref );
		$post    = $id && 'wp_block' === get_post_type( $id ) && Scope::readable( $id ) ? get_post( $id ) : null;
		if ( ! $pattern && ! $post ) {
			return Rejection::make( 'leitvia_unknown_pattern', __( 'There is no pattern with this name. List the patterns and use one of them.', 'leitvia-studio' ), 404, 'pattern', array( 'pattern' => $ref ), 'leitvia/list-patterns' );
		}
		$lab = $id ? 'block:' . $id : 'pattern:' . $ref;
		if ( $deprecated && 'deprecated' === Status::own( $lab ) ) {
			$replacement = $id ? (string) get_post_meta( $id, '_leitvia_lab_replaced_by', true ) : (string) ( leitvia_studio_contract()->pattern( $ref )['docs']['replacedBy'] ?? '' );
			return Rejection::make( 'leitvia_deprecated_pattern', __( 'The pattern is deprecated. Use its replacement instead.', 'leitvia-studio' ), 422, 'deprecated', array( 'pattern' => $ref ), 'leitvia/get-pattern', 'once', array( 'replaced_by' => (string) preg_replace( '/^pattern:/', '', $replacement ) ) );
		}
		return array(
			'ref'     => $ref,
			'id'      => $post ? $post->ID : 0,
			'name'    => $post ? 'core/block/' . $post->ID : $ref,
			'title'   => $post ? $post->post_title : (string) ( $pattern['title'] ?? '' ),
			'content' => $post ? $post->post_content : (string) ( $pattern['content'] ?? '' ),
		);
	}

	/**
	 * Markup with block edits applied in order.
	 *
	 * @param string               $content Markup.
	 * @param array<mixed>         $edits   Edits with block and the changes.
	 * @param array<string, mixed> $target  Target for rejections.
	 * @return string|\WP_Error
	 */
	public static function edit( string $content, array $edits, array $target ) {
		foreach ( $edits as $edit ) {
			$block     = (string) ( $edit['block'] ?? '' );
			$change    = array_intersect_key( (array) $edit, array_flip( array( 'text', 'link_text', 'url', 'add_link', 'caption', 'image' ) ) );
			$structure = array_filter( array_intersect_key( (array) $edit, array_flip( array( 'remove', 'repeat' ) ) ) );
			if ( $structure && ( $change || 2 === count( $structure ) ) ) {
				return Block_Edit::invalid( 'structure', __( 'An edit with remove or repeat changes nothing else. Send remove, repeat and text changes as edits of their own.', 'leitvia-studio' ), $target + array( 'block' => $block ) );
			}
			$content = $structure ? Block_Edit::structure( $content, $block, $structure, $target ) : Block_Edit::apply( $content, $block, $change, $target );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
		}
		return $content;
	}

	/**
	 * Markup with metadata.patternName on every root block, as the editor sets it on insert; '' leaves it unchanged.
	 *
	 * @param string $content Markup.
	 * @param string $name    Pattern slug, or core/block/<ID> for a Lab pattern.
	 */
	public static function name_sections( string $content, string $name ): string {
		if ( '' === $name ) {
			return $content;
		}
		$blocks = parse_blocks( $content );
		foreach ( $blocks as $index => $block ) {
			if ( null !== $block['blockName'] ) {
				$meta                                  = is_array( $block['attrs']['metadata'] ?? null ) ? $block['attrs']['metadata'] : array();
				$blocks[ $index ]['attrs']['metadata'] = array( 'patternName' => $name ) + $meta;
			}
		}
		return serialize_blocks( $blocks );
	}

	/**
	 * Bytes of Base64 image data, false when it is not valid Base64. Uses the strict decoder of sodium (in PHP, or the
	 * polyfill WordPress ships); the data is an image, never code.
	 *
	 * @param string $data Base64 data.
	 * @return string|false
	 */
	private static function decode( string $data ) {
		try {
			return sodium_base642bin( $data, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException $e ) {
			return false;
		}
	}

	/**
	 * Bytes of a file of this site behind a URL, read from disk; leitvia_foreign_host for other hosts. Nothing is
	 * requested over HTTP. Only the uploads of this site, themes, plugins and wp-includes count: in a network the
	 * uploads of the other sites share host and content folder, and the private folder of change packages stays closed.
	 *
	 * @param string $url URL.
	 * @return string|\WP_Error
	 */
	private static function local_file( string $url ) {
		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return Rejection::make( 'leitvia_foreign_host', __( 'Media from other hosts is not fetched. Send the file as Base64 data instead.', 'leitvia-studio' ), 422, 'foreign_host', array(), '', 'once' );
		}
		$path    = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$uploads = wp_upload_dir( null, false );
		// The main site of a network keeps the uploads of the other sites below its own, in sites/<ID>.
		$closed = array_filter( array( realpath( $uploads['basedir'] . '/' . Packages::PRIVATE_DIR ), realpath( $uploads['basedir'] . '/' . Packages::LEGACY_PRIVATE_DIR ), is_multisite() ? realpath( $uploads['basedir'] . '/sites' ) : false ) );
		foreach ( array(
			$uploads['basedir']     => $uploads['baseurl'],
			get_theme_root()        => get_theme_root_uri(),
			WP_PLUGIN_DIR           => plugins_url(),
			ABSPATH . 'wp-includes' => includes_url(),
		) as $dir => $base ) {
			$prefix = untrailingslashit( (string) wp_parse_url( $base, PHP_URL_PATH ) ) . '/';
			if ( ! str_starts_with( $path, $prefix ) ) {
				continue;
			}
			$file = realpath( trailingslashit( $dir ) . substr( $path, strlen( $prefix ) ) );
			$root = realpath( $dir );
			if ( ! $file || ! $root || ! str_starts_with( $file, trailingslashit( $root ) ) || ! is_file( $file ) || ! is_readable( $file ) ) {
				continue;
			}
			foreach ( $closed as $folder ) {
				if ( str_starts_with( $file, trailingslashit( $folder ) ) ) {
					continue 2;
				}
			}
			return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of this site.
		}
		return Block_Edit::invalid( 'url', __( 'The URL does not point to a file of this site.', 'leitvia-studio' ) );
	}

	/**
	 * Image block markup for the alt text check; empty alt without the decorative mark leaves the attribute out.
	 *
	 * @param string $name       File name.
	 * @param string $alt        Alt text.
	 * @param bool   $decorative Whether the image is decorative.
	 */
	public static function image_markup( string $name, string $alt, bool $decorative ): string {
		$attrs = $decorative ? ' {"metadata":{"leitviaDecorative":true}}' : '';
		$alt   = '' !== $alt || $decorative ? ' alt="' . esc_attr( $alt ) . '"' : '';
		return '<!-- wp:image' . $attrs . ' --><figure class="wp-block-image"><img src="' . esc_url( content_url( $name ) ) . '"' . $alt . '/></figure><!-- /wp:image -->';
	}
}
