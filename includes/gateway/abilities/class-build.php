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
use LeitviaStudio\Gateway\Composition;
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
	 * Largest video file in bytes by default, see max_video_bytes().
	 */
	public const MAX_VIDEO_BYTES = 50 * MB_IN_BYTES;

	/**
	 * Most edits of one pattern in one call (a section of compose-page, vary-pattern, update-template-part).
	 */
	public const MAX_EDITS = 250;

	/**
	 * Page kinds compose-page may set in _leitvia_page_kind.
	 */
	public const PAGE_KINDS = array( 'imprint', 'privacy', 'terms', 'withdrawal', 'accessibility', 'editorial', 'contact', 'authors' );

	/**
	 * Post types whose changes belong to the area structure.
	 */
	private const STRUCTURE_TYPES = array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );

	/**
	 * Keys of a block edit that Block_Edit::apply() takes.
	 */
	private const CHANGES = array( 'attributes', 'text', 'link_text', 'url', 'add_link', 'caption', 'anchor', 'columns', 'image', 'video', 'table', 'bind' );

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
			__( 'Creates a page from patterns in the order of a page frame of the contract, or rebuilds an existing page (post). With post all its sections are replaced in one step, and the checks (one h1, structure, composition) see only the finished page, never a state in between. Plan it with leitvia/plan-page first; base_hash is empty for a new page and the hash of leitvia/get-page-outline for post. The page remembers its frame, so check-composition counts the sections the frame requires (hub, audience) or recommends (service). Per section texts (also quote citations and details summaries), links, images, captions, catalog attributes, an HTML anchor and the columns of a grid at block IDs of the pattern outline can change, remove and repeat take blocks from a parent or add copies, for fewer or more entries than the pattern, and insert adds a block of leitvia/list-blocks after a block, given as name with attributes, anchor and inner blocks, never as markup (text with link_text and url for a link inside it, table for the cells of a table, any number of list items as inner); table fills the head, rows and foot of a core/table or leitvia/comparison-table accessibly; a text that would drop a link is refused. Edits apply in order. Each section keeps metadata.patternName (core/block/<ID> for a Lab pattern). Unknown patterns are rejected with a pointer to leitvia/list-patterns, deprecated ones with their replacement, Lab patterns that are not approved (also through a part) with leitvia_pattern_not_approved. Later changes go through leitvia/update-content and leitvia/update-block, further sections through leitvia/insert-section.', 'leitvia-studio' ),
			'build',
			$create,
			array(
				'properties'           => array(
					'title'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => __( 'Title of the new page, or the new title of the page of post.', 'leitvia-studio' ),
					),
					'post'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of an existing page (or post for the frame article) to rebuild: its content becomes the sections, its address stays unless slug is given.', 'leitvia-studio' ),
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
					'type'     => array(
						'type'        => 'string',
						'enum'        => array( 'post', 'page' ),
						'description' => __( 'For the frame article only: page makes a guide or long read under a page address (template page-article: title, byline with author and dates, author box, and an Article in the structured data), post (the default) a post.', 'leitvia-studio' ),
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
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Post ID from leitvia/list-content.', 'leitvia-studio' ),
		);
		$edit['required']           = array( 'post', 'block' );
		Abilities::write(
			'leitvia/update-block',
			__( 'Update block', 'leitvia-studio' ),
			__( 'Changes one block found by its ID or path ID: attributes from the block catalog and the level of a heading, the text of a paragraph, heading, list item or button, the citation of a quote, the summary of a details block, the link of a button or of the one link in a text (link_text names the linked part, also for a text that gets its first link), the cells of a core/table or leitvia/comparison-table with table (head, rows, foot), or the image of an image or cover block with alt text. video sets the video of a core/video from the media library by its ID, with poster, controls and preload none, also on the empty video of a pattern. add_link sets a new link on a passage of a text, caption the caption of an image, video, audio, gallery, table or embed, anchor the HTML anchor of a block with the support anchor (unique on the page), columns the column count of a grid (group or post template with the layout grid) or of a gallery. A heading or paragraph takes fontSize with a font size of the tokens, a group with a light ground takes backgroundColor surface or surface-alt, each as attribute. label changes the name for screen readers (aria-label) of a slider, announcement bar or accessibility bar. A timeline entry takes date, dateLabel and heading as attributes. Attributes that a static block keeps in its markup, such as prefix, value, suffix and label of a counter, change like any other. query of a core/query merges into its settings. tracks of a core/video replaces its text tracks: each names a WebVTT file from leitvia/add-media by id, with kind, srclang and label. A spotlight point moves with attributes x and y in percent. The effect on scroll (leitviaAnimation, leitviaMotion) changes on every block that has it, core blocks and saved markup included, since only the block comment changes. Static markup is rebuilt with the HTML API; changes it cannot rebuild safely are rejected with leitvia_static_markup. Read the post with leitvia/get-page-outline first and send its hash as base_hash; get-block shows the current attributes. For several text changes in one post use leitvia/update-content.', 'leitvia-studio' ),
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
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => __( 'Title of the pattern in the Lab.', 'leitvia-studio' ),
					),
					'content' => $content + array( 'description' => __( 'Block markup of the pattern.', 'leitvia-studio' ) ),
					'synced'  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a person inserts it in the editor as a synced pattern. Abilities insert every pattern as a copy, so pattern overrides never apply there; a value that every page shows, such as opening hours, is a site setting with bindable true (leitvia/update-site-values).', 'leitvia-studio' ),
					),
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
					'original' => array(
						'type'        => 'string',
						'description' => __( 'The pattern to vary: block:<ID> of a Lab pattern or the slug of a theme pattern from leitvia/list-patterns.', 'leitvia-studio' ),
					),
					'title'    => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'content'  => $content,
					'edits'    => self::edits_schema(),
				),
				'required'             => array( 'original' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_vary_pattern' )
		);
		Abilities::write(
			'leitvia/add-media',
			__( 'Add media', 'leitvia-studio' ),
			__( 'Adds an image to the media library from a URL of this site or as Base64 data up to 5 MB, with alt text or marked as decorative. It also takes a WebVTT file with captions for a video (.vtt, UTF-8, starts with WEBVTT, no alt text) and a video hosted on this site, MP4 (H.264) or WebM up to 50 MB, with poster and caption instead of alt text (poster_file names a poster image that still waits in the same package), and a PDF up to 5 MB (.pdf, with the PDF signature at its start) with a title that says what the document is, without alt text; no other document type. It needs the right to upload files. URLs of other hosts are rejected; nothing is fetched from them. base_hash is empty. With replace the file takes the place of the file of an attachment under the same ID and address, for a new cut of a video or a corrected PDF; a person applies that. Images already in the library are used by their ID in compose-page and update-block, a WebVTT file by its ID in tracks of update-block, a video by its ID in the field video of update-block, a PDF by its URL from the result or leitvia/list-media in url or add_link of update-block.', 'leitvia-studio' ),
			'build',
			$create,
			array(
				'properties'           => array(
					'url'          => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'data'         => array(
						'type'      => 'string',
						'maxLength' => (int) ceil( max( self::MAX_MEDIA_BYTES, self::max_video_bytes() ) / 3 ) * 4 + 4,
					),
					'filename'     => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'title'        => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => __( 'Title in the media library, the file name without it. Give a PDF a title that says what the document is.', 'leitvia-studio' ),
					),
					'alt'          => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
					'decorative'   => array( 'type' => 'boolean' ),
					'replace'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Attachment ID of a file of the media library whose file this one replaces: ID, address, title, alt text and every place that uses it stay, the metadata and the image sizes are made anew. The new file must have the same type. The old file is gone for good, so the change always waits in a change package for a person. Send only the file with it; base_hash may stay empty.', 'leitvia-studio' ),
					),
					'poster'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Video only: attachment ID of an image of the media library that shows before the video plays, a still of it. Also set it as poster of the core/video.', 'leitvia-studio' ),
					),
					'poster_file'  => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => __( 'Video only, instead of poster: file name of an image an earlier leitvia/add-media added under the same package key and that still waits there. Applying the package writes the image first and sets it as poster of the video, so image and video go into one package.', 'leitvia-studio' ),
					),
					'caption'      => array(
						'type'        => 'string',
						'maxLength'   => 2000,
						'description' => __( 'Video only: what the video shows, in plain text, as caption of the file in the media library. A video without speech needs this text on the page as well.', 'leitvia-studio' ),
					),
					'ai_generated' => array(
						'type'        => 'boolean',
						'description' => __( 'Image or video only: the file was made or changed with AI. Stored on the file (leitvia/list-media returns it, leitvia/set-alt-text changes it later); a site may show a short note at such images.', 'leitvia-studio' ),
					),
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
			__( 'Crops an image of the media library into a new image. The rectangle is in percent of the image size; the original stays. The new image takes the alt text of the original and its title with an addition. Use it for images with text burned in: keep the part without the text band. Only JPEG, PNG, GIF, WebP and AVIF, no SVG; it needs the right to upload files. base_hash is empty. A live crop gets a change package, so leitvia/rollback deletes the new image with its files. In a package the new image has no ID before a person applies it (target.post 0); applied.post of leitvia/get-package gives it, so a further step on it goes into the next package.', 'leitvia-studio' ),
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
			'type'        => 'array',
			'minItems'    => 1,
			'maxItems'    => 30,
			'description' => __( 'Sections of the page in order, each a pattern with optional edits at block IDs of its outline.', 'leitvia-studio' ),
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'pattern' => array(
						'type'        => 'string',
						'description' => __( 'Pattern slug such as leitvia/hero-split-image, or block:<ID> for a Lab pattern.', 'leitvia-studio' ),
					),
					'edits'   => self::edits_schema(),
				),
				'required'             => array( 'pattern' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Schema of the edits of a pattern at block IDs, for the sections of compose-page, vary-pattern and
	 * update-template-part. MAX_EDITS covers a section of ten cards with two dozen texts each, such as ten price cards made with repeat from one card.
	 *
	 * @return array<string, mixed>
	 */
	public static function edits_schema(): array {
		return array(
			'type'     => 'array',
			'maxItems' => self::MAX_EDITS,
			'items'    => self::edit_schema( false ),
		);
	}

	/**
	 * Schema of a block built from its name, for the edit insert and for its inner blocks.
	 *
	 * @param int $depth Levels of inner blocks below this one.
	 * @return array<string, mixed>
	 */
	private static function block_schema( int $depth ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'block'      => array(
					'type'        => 'string',
					'pattern'     => '^[a-z0-9-]+/[a-z0-9-]+$',
					'description' => __( 'Name of a block from leitvia/list-blocks, such as leitvia/consent-embed, or core/paragraph, core/heading, core/separator, core/site-logo, core/buttons (inner: one or two core/button) or core/button (text and url; className is-style-outline for the second one). Inner blocks may also be other blocks that render on the server, such as gravityforms/form.', 'leitvia-studio' ),
				),
				'text'       => array(
					'type'        => 'string',
					'maxLength'   => 5000,
					'description' => __( 'Plain text of a new paragraph, heading, list item or button.', 'leitvia-studio' ),
				),
				'link_text'  => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 500,
					'description' => __( 'With text and url: the part of text that becomes the link, such as the mail address in a contact line.', 'leitvia-studio' ),
				),
				'url'        => array(
					'type'        => 'string',
					'maxLength'   => 2000,
					'description' => __( 'Link of a new button, or with link_text of the link in a new paragraph, heading or list item (mailto: and tel: work too).', 'leitvia-studio' ),
				),
				'table'      => self::table_schema(),
				'attributes' => array(
					'type'        => 'object',
					'description' => __( 'Attributes from leitvia/list-blocks, checked as in leitvia/update-block.', 'leitvia-studio' ),
				),
				'anchor'     => self::anchor_schema(),
				'bind'       => self::bind_schema(),
			),
			'required'             => array( 'block' ),
			'additionalProperties' => false,
		);
		if ( $depth > 0 ) {
			$schema['properties']['inner'] = array(
				'type'        => 'array',
				'minItems'    => 1,
				'maxItems'    => 30,
				'description' => __( 'Inner blocks in order, in place of those of the catalog example.', 'leitvia-studio' ),
				'items'       => self::block_schema( $depth - 1 ),
			);
		}
		return $schema;
	}

	/**
	 * Schema of block bindings, shared by the block edits and the blocks of insert.
	 *
	 * @return array<string, mixed>
	 */
	private static function bind_schema(): array {
		return array(
			'type'                 => 'object',
			'maxProperties'        => 5,
			'description'          => __( 'Block bindings per attribute, written to metadata.bindings: {"content": {"source": …, "args": {…}}} shows a value of the source in place of the saved text, such as a field, a site setting or, with an add-on, a price; null removes the binding of that attribute. Sources and bindable attributes come from leitvia/get-content-model (bindings). The text of the same edit stays as the saved fallback.', 'leitvia-studio' ),
			'additionalProperties' => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => array(
					'source' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 100,
						'description' => __( 'Name of a registered binding source.', 'leitvia-studio' ),
					),
					'args'   => array(
						'type'        => 'object',
						'description' => __( 'Arguments of the source, such as key.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'source' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Schema of an HTML anchor, shared by the block edits and the blocks of insert.
	 *
	 * @return array<string, mixed>
	 */
	private static function anchor_schema(): array {
		return array(
			'type'        => array( 'string', 'null' ),
			'maxLength'   => 100,
			'description' => __( 'HTML anchor (id) of a block with the support anchor, unique on the page, such as contact-form for links to #contact-form; empty or null removes it.', 'leitvia-studio' ),
		);
	}

	/**
	 * Schema of one block edit.
	 *
	 * @param bool $single Whether the edit is the input of update-block, without remove and repeat.
	 * @return array<string, mixed>
	 */
	private static function edit_schema( bool $single ): array {
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
					'description' => __( 'Part of text that stays linked when the text holds a link, such as the mail address in a contact line; with url it links that part of a text without a link. Without it the link stays around its old text, and a text without that is refused unless the link only points to #.', 'leitvia-studio' ),
				),
				'url'       => array(
					'type'        => 'string',
					'maxLength'   => 2000,
					'description' => __( 'Link of a button, or of the one link in a paragraph, heading or list item (mailto: and tel: work too). "" removes the saved link of a button whose link a binding fills; the binding stays.', 'leitvia-studio' ),
				),
				'add_link'  => self::add_link_schema(),
				'caption'   => self::caption_schema(),
				'anchor'    => self::anchor_schema(),
				'bind'      => self::bind_schema(),
				'table'     => self::table_schema(),
				'columns'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 6,
					'description' => __( 'Column count of a grid or a gallery: a group or post template with the layout grid, such as the cards of a teaser pattern, or a gallery block. Four cards take 2: with 4 a narrower window still wraps to 3 + 1.', 'leitvia-studio' ),
				),
				'image'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'         => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Attachment ID of the image, from leitvia/list-media or leitvia/add-media.', 'leitvia-studio' ),
						),
						'alt'        => array(
							'type'        => 'string',
							'maxLength'   => 500,
							'description' => __( 'Alt text of the image here. Left out, the image keeps its alt text from the media library; an image without one needs alt or decorative true.', 'leitvia-studio' ),
						),
						'decorative' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'video'     => array(
					'type'                 => 'object',
					'description'          => __( 'Video of a core/video from the media library: sets src, poster, controls and preload none, also on a video block that is still empty. caption and attributes with tracks or align go in the same edit. Text tracks the block has stay; send tracks when the new video has other captions.', 'leitvia-studio' ),
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Attachment ID of the video, from leitvia/list-media or leitvia/add-media.', 'leitvia-studio' ),
						),
						'poster' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Attachment ID of the image that shows before the video plays. Left out, the poster the video got in leitvia/add-media.', 'leitvia-studio' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'block' ),
			'additionalProperties' => false,
		);
		if ( ! $single ) {
			$schema['properties']['remove']   = array(
				'type'        => 'boolean',
				'description' => __( 'Removes the block from its parent, for a section with fewer entries than the pattern. Later edits of the list count from the result, so work from the last block to the first.', 'leitvia-studio' ),
			);
			$schema['properties']['repeat']   = array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 20,
				'description' => __( 'Number of copies to add right after the block, for a section with more entries than the pattern. Later edits reach the copies by the IDs they get.', 'leitvia-studio' ),
			);
			$schema['properties']['move']     = array(
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => 200,
				'description' => __( 'Block ID or path ID of a block with the same parent: the block moves right after it, or before it with position before, such as a button above a heading. Later edits count from the new order.', 'leitvia-studio' ),
			);
			$schema['properties']['position'] = array(
				'type'        => 'string',
				'enum'        => array( 'after', 'before' ),
				'description' => __( 'With insert or move only: after (the default) or before the block of the edit, or of move.', 'leitvia-studio' ),
			);
			$schema['properties']['insert']   = array( 'description' => __( 'A new block right after the block (or before it with position before), in the same parent, such as leitvia/form-errors with gravityforms/form inside or leitvia/consent-embed; a static block starts from the example of the catalog. Later edits reach it and its inner blocks by the IDs they get.', 'leitvia-studio' ) ) + self::block_schema( 2 );
		}
		$schema['properties']['attributes'] = array(
			'type'        => 'object',
			'description' => __( 'Attributes from leitvia/list-blocks; for a core/heading its level, 1 to 6; for a core/query query with perPage, pages, offset, postType, order, orderBy, sticky and inherit (perPage counts with inherit false); for a core/video tracks, each with id, kind, srclang and label (its file, poster, controls and preload come from the field video); for a leitvia/timeline-item date (YYYY, YYYY-MM or YYYY-MM-DD, empty for a free label), dateLabel and heading; leitviaAnimation and leitviaMotion on every block that lists them, also next to other attributes (null removes either); className (is-style-<name>, null removes the style and keeps other classes; up to three styles that combine space separated, such as is-style-sticky-header-desktop is-style-overlay-header) on every core block with registered block styles; textColor (ink, ink-muted or accent) and fontSize on a core/heading or core/paragraph; fontSize on a core/quote, so the quotes of a row match (null for the size of the theme); textColor with a color role and fontSize on a block of Studio or an add-on whose supports in leitvia/list-blocks name color.text or typography.fontSize, such as leitvia/icon and leitvia/icon-list (null removes either); on a core/cover minHeight in vh, 30 to 100, and focalPoint, x and y from 0 to 1, the part of the photo that stays in view where the cover crops it, as on phones (null for the middle); align on every block whose supports.align in leitvia/list-blocks names the value (null removes it); on core/image also width, height, aspectRatio, scale, sizeSlug, linkDestination, href, lightbox (null removes one). null for an attribute the block does not have changes nothing.', 'leitvia-studio' ),
		);
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
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 500,
					'description' => __( 'The exact words of the passage to link, without markup.', 'leitvia-studio' ),
				),
				'url'  => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 2000,
					'description' => __( 'Target of the link (mailto: and tel: work too).', 'leitvia-studio' ),
				),
			),
			'required'             => array( 'text', 'url' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Schema of the cells of a table, for core/table and leitvia/comparison-table, shared by the block edits and insert.
	 *
	 * @return array<string, mixed>
	 */
	public static function table_schema(): array {
		$cells = array(
			'type'     => 'array',
			'minItems' => 1,
			'maxItems' => Block_Edit::TABLE_MAX_COLUMNS,
			'items'    => array(
				'type'      => 'string',
				'maxLength' => 500,
			),
		);
		return array(
			'type'                 => 'object',
			'description'          => __( 'All cells of a core/table or leitvia/comparison-table as plain text: head (the column headers), rows (each as long as head) and for core/table foot (a highlighted closing row, such as a total). The table is rebuilt accessibly: column headers with scope col, the first cell of each row as row header with scope row (row_headers false for none). The caption goes in caption of the same edit. On small screens core tables scroll inside a focusable region, the comparison table turns into cards.', 'leitvia-studio' ),
			'properties'           => array(
				'head'        => array( 'description' => __( 'The column headers in order.', 'leitvia-studio' ) ) + $cells,
				'rows'        => array(
					'type'        => 'array',
					'description' => __( 'The rows in order, each a list of cells as long as head.', 'leitvia-studio' ),
					'minItems'    => 1,
					'maxItems'    => Block_Edit::TABLE_MAX_ROWS,
					'items'       => $cells,
				),
				'foot'        => array(
					'type'     => 'array',
					'maxItems' => 3,
					'items'    => $cells,
				),
				'row_headers' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'required'             => array( 'head', 'rows' ),
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
		$type = 'single' === ( $frame['template'] ?? '' ) ? 'post' : 'page';
		// The frame article reads as well under a page address: a page with the template page-article, which shows
		// title, byline and author box as a post does (the default template where the contract has none).
		$rebuilt = (int) ( $input['post'] ?? 0 );
		$known   = array_column( (array) ( leitvia_studio_contract()->data()['templates'] ?? array() ), 'slug' );
		if ( 'post' === $type && ( 'page' === ( $input['type'] ?? '' ) || ( $rebuilt && 'page' === get_post_type( $rebuilt ) ) ) ) {
			$type              = 'page';
			$frame['template'] = in_array( 'page-article', $known, true ) ? 'page-article' : 'page';
		}
		$template = (string) ( $input['template'] ?? $frame['template'] ?? 'page' );
		if ( isset( $input['template'] ) && ( 'page' !== $type || ! str_starts_with( $template, 'page' ) || ! in_array( $template, $known, true ) ) ) {
			return Block_Edit::invalid( 'template', __( 'Use a page template of the contract, for example page-docs.', 'leitvia-studio' ), array(), 'leitvia/get-contract' );
		}
		$post = (int) ( $input['post'] ?? 0 );
		if ( $post && ( get_post_type( $post ) !== $type || ! Scope::readable( $post ) ) ) {
			/* translators: %s: post type. */
			return Block_Edit::invalid( 'post', sprintf( __( 'Rebuild a %s that agents can read; this frame makes one of that type.', 'leitvia-studio' ), $type ), array( 'post' => $post ), 'leitvia/list-content' );
		}
		$parent = (int) ( $input['parent'] ?? 0 );
		if ( $parent && ( get_post_type( $parent ) !== $type || ! Scope::readable( $parent ) ) ) {
			return Block_Edit::invalid( 'parent', __( 'The parent must be a page agents can read.', 'leitvia-studio' ), array( 'post' => $parent ) );
		}
		$kind = (string) ( $input['kind'] ?? '' );
		$meta = array_filter(
			array(
				'_wp_page_template'     => 'page' === $type && 'page' !== $template ? $template : '',
				'_leitvia_page_kind'    => $kind,
				// Only a page keeps its frame (Content::PAGE_META), an article under a page address too.
				Composition::FRAME_META => 'page' === $type ? (string) $frame['type'] : '',
			)
		);
		if ( $post && 'page' === $type ) {
			// A rebuilt page takes the template of its new frame, also back to the default template.
			$meta['_wp_page_template'] = 'page' !== $template ? $template : '';
		}
		$legal = in_array( $kind, Autonomy::LEGAL_PAGES, true ) || ( $post && in_array( get_post_meta( $post, '_leitvia_page_kind', true ), Autonomy::LEGAL_PAGES, true ) );
		return array(
			'area'   => 'content',
			'target' => array(
				'post'      => $post,
				'post_type' => $type,
			),
			'kinds'  => $legal ? array( 'legal' ) : array(),
			'change' => static function ( array $state ) use ( $sections, $input, $meta, $parent, $post ) {
				$content = self::compose( $sections );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
				$fields = array(
					'post_title'   => (string) $input['title'],
					'post_content' => $content,
				);
				if ( ! $post || isset( $input['slug'] ) ) {
					$fields['post_name'] = (string) ( $input['slug'] ?? '' );
				}
				if ( ! $post || $parent ) {
					$fields['post_parent'] = $parent;
				}
				$fields['meta_input'] = $meta + (array) ( $state['meta_input'] ?? array() );
				return $fields + $state;
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
		$parts   = array();
		$anchors = array();
		foreach ( $sections as $section ) {
			$content = self::edit( (string) $section['content'], (array) $section['edits'], array( 'pattern' => $section['ref'] ) );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$parts[] = self::name_sections( $content, (string) $section['name'] );
			foreach ( (array) $section['edits'] as $edit ) {
				$anchors = array_merge( $anchors, Block_Edit::anchors( (array) $edit ), Block_Edit::anchors( (array) ( $edit['insert'] ?? array() ) ) );
			}
		}
		// Each section checks its own anchors; only the whole page shows one that another section has too.
		return Block_Edit::unique_anchors( implode( "\n\n", $parts ), $anchors );
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
		$change = array_intersect_key( $input, array_flip( self::CHANGES ) );
		if ( ! array_diff_key( $change, array( 'link_text' => true ) ) ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: attributes, text, url, add_link, caption, anchor, columns, image, video or table.', 'leitvia-studio' ), array( 'post' => $post ) );
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
	 * pipeline moves it into the uploads folder only when it writes the attachment. With replace it takes the place of
	 * the file of an attachment instead, see replace_media().
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
		$size = strlen( $file );
		$most = self::max_video_bytes();
		if ( $size > max( self::MAX_MEDIA_BYTES, $most ) ) {
			/* translators: %d: largest video in MB */
			return Block_Edit::invalid( 'size', sprintf( __( 'The file is too large: images, captions and PDF files up to 5 MB, videos up to %d MB.', 'leitvia-studio' ), (int) floor( $most / MB_IN_BYTES ) ) );
		}
		$video = Packages::video_type( $file, $name );
		if ( ! $video && $size > self::MAX_MEDIA_BYTES ) {
			return Block_Edit::invalid( 'size', __( 'The file is larger than 5 MB.', 'leitvia-studio' ) );
		}
		if ( $video && $size > $most ) {
			/* translators: %d: largest video in MB */
			return Block_Edit::invalid( 'size', sprintf( __( 'The video is larger than %d MB.', 'leitvia-studio' ), (int) floor( $most / MB_IN_BYTES ) ) );
		}
		$track = null;
		$type  = $video ?? Packages::image_type( $file, $name );
		if ( ! $type ) {
			// A captions file and a PDF show no picture: neither takes alt text.
			$track = Packages::track_type( $file, $name ) ?? Packages::document_type( $file, $name );
			$type  = $track;
		}
		// An SVG can carry scripts and links that run in the browser of a visitor, and core has no sanitizer for it: the
		// logo goes in as a raster image instead, rendered from the SVG.
		if ( ! $type && ( str_ends_with( strtolower( $name ), '.svg' ) || preg_match( '/^\s*(<\?xml[^>]*>\s*)?<svg\b/i', substr( $file, 0, 512 ) ) ) ) {
			return Block_Edit::invalid( 'type', __( 'SVG files are not added: they can hold scripts that run in the browser of a visitor. Render the SVG as a PNG or WebP with a transparent background at twice the width it shows at (720 px for a logo 360 px wide) and add that file; the site logo then takes its ID with leitvia/set-site-identity.', 'leitvia-studio' ), array(), 'leitvia/set-site-identity' );
		}
		if ( ! $type ) {
			return Block_Edit::invalid( 'type', __( 'Only images of a type this site allows can be added, JPEG, PNG, GIF, WebP or AVIF, WebVTT files that start with WEBVTT in UTF-8, videos as MP4 with H.264 or WebM, and PDF files.', 'leitvia-studio' ) );
		}
		if ( isset( $input['replace'] ) ) {
			return self::replace_media( (int) $input['replace'], $input, $file, $type );
		}
		$extra = array();
		$waits = '';
		if ( isset( $input['poster'] ) || isset( $input['poster_file'] ) || isset( $input['caption'] ) ) {
			if ( ! $video || isset( $input['poster'], $input['poster_file'] ) ) {
				return Block_Edit::invalid( 'poster', __( 'Only a video takes a poster and a caption, and its poster either by ID or by file name.', 'leitvia-studio' ) );
			}
			$waits = self::waiting_poster( $input );
			if ( is_wp_error( $waits ) ) {
				return $waits;
			}
			$poster = (int) ( $input['poster'] ?? 0 );
			if ( isset( $input['poster'] ) && ( ! wp_attachment_is_image( $poster ) || ! Scope::readable( $poster ) ) ) {
				return Block_Edit::invalid( 'poster', __( 'The poster must be an image of the media library. Add it with leitvia/add-media first.', 'leitvia-studio' ), array( 'post' => $poster ), 'leitvia/add-media' );
			}
			// Core shows the featured image of a video attachment as its thumbnail; the caption is its excerpt, as in the media library.
			$extra = array_filter(
				array(
					'post_excerpt' => sanitize_textarea_field( (string) ( $input['caption'] ?? '' ) ),
					'meta_input'   => array_filter( array( '_thumbnail_id' => $poster ) ),
				)
			);
		}
		$name     = $type['name'];
		$findings = $track || $video ? array() : Content_Checks::alt_text( self::image_markup( $name, $decorative ? '' : $alt, $decorative ), array( 'post_type' => 'page' ) );
		if ( $findings ) {
			return Rejection::make( 'leitvia_check_failed', __( 'The image needs alt text that describes it, or mark it as decorative.', 'leitvia-studio' ), 422, 'checks', array(), '', 'once', array( 'findings' => $findings ) );
		}
		$alt   = $video || 'application/pdf' === $type['type'] ? '' : $alt;
		$title = (string) ( $input['title'] ?? '' );
		if ( ! empty( $input['ai_generated'] ) ) {
			if ( $track ) {
				return Block_Edit::invalid( 'ai_generated', __( 'Only an image or a video is marked as made with AI.', 'leitvia-studio' ) );
			}
			$extra['meta_input'] = array( Content::AI_META => '1' ) + (array) ( $extra['meta_input'] ?? array() );
		}
		return array(
			'area'   => 'content',
			// A poster that waits in the package (target media) becomes the featured image of the video when the package
			// is applied, see Packages::apply().
			'target' => array(
				'post'      => 0,
				'post_type' => 'attachment',
			) + ( '' !== $waits ? array( 'media' => $waits ) : array() ),
			'change' => static fn( array $state ) => self::new_media( $state, $name, $file, $type['type'], $alt, $title, $extra ),
		);
	}

	/**
	 * Operation of add-media with replace: the attachment keeps its ID, its address and its texts, only the file
	 * changes, to one of the same type. The pipeline swaps the file after the write (target remove file, see
	 * Packages::swap_file()); the old file is gone then, so a person decides as for delete-media
	 * (Autonomy::person_decides()).
	 *
	 * @param int                                         $id    Attachment ID.
	 * @param array<string, mixed>                        $input Input.
	 * @param string                                      $bytes Content of the new file, checked.
	 * @param array{type: string, name: non-empty-string} $type  Its type and name.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function replace_media( int $id, array $input, string $bytes, array $type ) {
		$target = array( 'post' => $id );
		if ( 'attachment' !== get_post_type( $id ) || ! Scope::readable( $id ) ) {
			return Rejection::make( 'leitvia_not_found', __( 'There is no file with this ID in the media library. List the media for the current IDs.', 'leitvia-studio' ), 404, 'id', $target, 'leitvia/list-media' );
		}
		if ( array_intersect_key( $input, array_flip( array( 'title', 'alt', 'decorative', 'poster', 'poster_file', 'caption' ) ) ) ) {
			return Block_Edit::invalid( 'replace', __( 'With replace only the file changes: send no title, alt text, poster or caption. Alt text changes with leitvia/set-alt-text.', 'leitvia-studio' ), $target, 'leitvia/set-alt-text' );
		}
		$old = (string) get_post_mime_type( $id );
		if ( $old !== $type['type'] ) {
			/* translators: 1: MIME type of the file in the library, 2: MIME type of the new file */
			return Block_Edit::invalid( 'replace', sprintf( __( 'The new file must have the type of the file it replaces: the library holds %1$s, this is %2$s. Add a file of another type as a new one.', 'leitvia-studio' ), $old, $type['type'] ), $target );
		}
		return array(
			'area'      => 'content',
			'target'    => $target + array( 'remove' => 'file' ),
			// The post fields stay as they are, so the hash only guards against a change in between.
			'base_hash' => '' !== (string) ( $input['base_hash'] ?? '' ) ? (string) $input['base_hash'] : \LeitviaStudio\Gateway\Hashes::document( $id ),
			'notice'    => __( 'The address stays the same, so browsers and caches may show the old file for a while. An image of another size gets other file names for its smaller sizes; content that names such a size by its address needs the image set again with leitvia/update-block.', 'leitvia-studio' ),
			'change'    => static function ( array $state ) use ( $bytes, $type ) {
				$path = Packages::stash_file( $type['name'], $bytes );
				return '' !== $path ? array( 'file' => $path ) + $state : Rejection::make( 'leitvia_execution_failed', __( 'The file could not be saved; nothing was changed. Try again later.', 'leitvia-studio' ), 500, 'execution', array(), '', 'never' );
			},
		);
	}

	/**
	 * File name of the image poster_file names, '' when it names none; a rejection when no image of add-media with
	 * that name waits in the package of the key. The same rule as for a featured image that waits, see
	 * Content::waiting_media().
	 *
	 * @param array<string, mixed> $input Input.
	 * @return string|\WP_Error
	 */
	private static function waiting_poster( array $input ) {
		$file = sanitize_file_name( (string) ( $input['poster_file'] ?? '' ) );
		if ( '' === $file ) {
			return '';
		}
		$package = '' !== (string) ( $input['package'] ?? '' ) ? Packages::find( (string) $input['package'] ) : 0;
		foreach ( $package ? Packages::operations( $package ) : array() as $entry ) {
			if ( 'leitvia/add-media' === ( $entry['ability'] ?? '' ) && ! isset( $entry['applied'] ) && str_starts_with( (string) ( $entry['state']['post_mime_type'] ?? '' ), 'image/' ) && wp_basename( (string) ( $entry['state']['file'] ?? '' ) ) === $file ) {
				return $file;
			}
		}
		return Block_Edit::invalid( 'poster_file', __( 'No image with this file name waits in the package of this key. Add it with leitvia/add-media and the same package key first, or name an image of the library by its ID in poster.', 'leitvia-studio' ), array(), 'leitvia/add-media' );
	}

	/**
	 * Largest video add-media takes, in bytes: 50 MB unless the filter leitvia_studio_max_video_bytes changes it.
	 * Images and captions keep 5 MB. The server limits of uploads and request size apply as well.
	 */
	public static function max_video_bytes(): int {
		/**
		 * Filters the largest video file in bytes that leitvia/add-media takes.
		 *
		 * @param int $bytes Bytes, 50 MB by default.
		 */
		return max( 1, (int) apply_filters( 'leitvia_studio_max_video_bytes', self::MAX_VIDEO_BYTES ) );
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
	 * @param array<string, mixed> $extra post_excerpt and meta_input of a video.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function new_media( array $state, string $name, string $bytes, string $type, string $alt, string $title, array $extra = array() ) {
		$path = Packages::stash_file( $name, $bytes );
		if ( '' === $path ) {
			return Rejection::make( 'leitvia_execution_failed', __( 'The file could not be saved; nothing was changed. Try again later.', 'leitvia-studio' ), 500, 'execution', array(), '', 'never' );
		}
		$image = str_starts_with( $type, 'image/' );
		$size  = $image ? wp_getimagesize( $path ) : false;
		// The pipeline sets the file path of the metadata when it moves the file into the uploads folder, and core's
		// metadata (sizes of an image, length of a video) after the write.
		$meta = $image ? array(
			'width'  => (int) ( $size[0] ?? 0 ),
			'height' => (int) ( $size[1] ?? 0 ),
		) : array();
		return array(
			'post_title'     => '' !== $title ? $title : (string) pathinfo( $name, PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
			'post_mime_type' => $type,
			'file'           => $path,
			'meta_input'     => array_filter(
				array(
					'_wp_attachment_image_alt' => $alt,
					'_wp_attachment_metadata'  => $meta,
				) + (array) ( $extra['meta_input'] ?? array() )
			),
		) + array_diff_key( $extra, array( 'meta_input' => true ) ) + $state;
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
			$change    = array_intersect_key( (array) $edit, array_flip( self::CHANGES ) );
			$structure = array_filter( array_intersect_key( (array) $edit, array_flip( array( 'remove', 'repeat', 'insert', 'move' ) ) ) );
			if ( $structure && ( $change || count( $structure ) > 1 ) ) {
				return Block_Edit::invalid( 'structure', __( 'An edit with remove, repeat, insert or move changes nothing else. Send them and text changes as edits of their own.', 'leitvia-studio' ), $target + array( 'block' => $block ) );
			}
			if ( isset( $edit['position'] ) && ! isset( $structure['insert'] ) && ! isset( $structure['move'] ) ) {
				return Block_Edit::invalid( 'structure', __( 'position goes with insert or move only.', 'leitvia-studio' ), $target + array( 'block' => $block ) );
			}
			$content = $structure ? Block_Edit::structure( $content, $block, $structure + array_intersect_key( (array) $edit, array( 'position' => true ) ), $target ) : Block_Edit::apply( $content, $block, $change, $target );
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
