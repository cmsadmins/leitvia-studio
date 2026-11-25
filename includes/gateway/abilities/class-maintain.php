<?php
/**
 * Maintenance abilities: content, internal links, alt text, patterns of sections, trash and media deletion.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Block_Ids;
use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Outline;
use LeitviaStudio\Gateway\Rejection;
use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Lab\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/update-content, leitvia/repair-links, leitvia/set-alt-text, leitvia/replace-pattern,
 * leitvia/insert-section, leitvia/remove-section, leitvia/trash-content and leitvia/delete-media. Each one changes
 * one post through Pipeline::run(). Link repairs and alt texts are corrections (autonomous by default); a live write
 * of any of them but delete-media gets a change package, so leitvia/rollback can undo it. Trashing and deleting
 * always wait for a person (target remove, see Autonomy::person_decides()).
 */
final class Maintain {

	/**
	 * Most items of one call of set-alt-text.
	 */
	public const MAX_ALT_TEXTS = 100;

	/**
	 * Most posts one usage search of trash-content and delete-media reads.
	 */
	private const MAX_USAGE = 100;

	/**
	 * Statuses of posts that count as places of use.
	 */
	private const USE_STATUSES = array( 'publish', 'future', 'draft', 'pending' );

	/**
	 * Statuses visitors see.
	 */
	private const PUBLIC_STATUSES = array( 'publish', 'future' );

	/**
	 * Grounds of the section that wrap of leitvia/insert-section gives a block: the attributes of its group, as the
	 * patterns set them.
	 */
	private const GROUNDS = array(
		'surface'     => array( 'backgroundColor' => 'surface' ),
		'surface-alt' => array( 'backgroundColor' => 'surface-alt' ),
		'inverse'     => array( 'className' => 'is-style-inverse' ),
	);

	/**
	 * Registers the abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$post      = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Post ID from leitvia/list-content.', 'leitvia-studio' ),
		);
		$overwrite = array(
			'destructive' => true,
			'idempotent'  => false,
		);
		Abilities::write(
			'leitvia/update-content',
			__( 'Update content', 'leitvia-studio' ),
			__( 'Updates title, excerpt, slug, date, status, author, terms, fields, rows of repeaters, featured image, comment status and the texts and button links of several blocks of one post in one operation, blocks found by their ID from the outline. Per block, url changes the one link of a text too, link_text names the part that stays linked, add_link sets a new link on a passage and caption the caption of a figure, as in leitvia/update-block. status publishes a draft, schedules it (future, with a date) or takes a post back to draft or pending; like every change it follows the autonomy level, so at a lower level it waits in a change package, and publishing needs the right to publish when it is applied. Terms, fields, rows, featured image and template follow the rules of leitvia/create-content; terms replace those of their taxonomy, and template switches the post to another template of the contract (page-landing for a page). Instead of blocks, content replaces the whole content with block markup, for example to turn classic content into blocks; the new content may hold no classic block. parent moves a page below another page of the same type, 0 to the top level; a page cannot go below itself or one of its subpages. Read the post with leitvia/get-page-outline first and send its hash as base_hash. A slug or parent change is a URL change and lands at most as a suggestion; after a slug change leitvia/repair-links with auto points the links of other posts to the new address, after a parent change leitvia/repair-links with from and to. For attributes or images of a block use leitvia/update-block.', 'leitvia-studio' ),
			'maintain',
			$overwrite,
			array(
				'properties'           => array(
					'post'           => $post,
					'title'          => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'excerpt'        => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
					'slug'           => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]+$',
					),
					'blocks'         => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => array(
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
									'description' => __( 'Plain text of a paragraph, heading, list item or button.', 'leitvia-studio' ),
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
									'description' => __( 'Link of a button, or of the one link in a paragraph, heading or list item (mailto: and tel: work too).', 'leitvia-studio' ),
								),
								'add_link'  => Build::add_link_schema(),
								'caption'   => Build::caption_schema(),
							),
							'required'             => array( 'block' ),
							'additionalProperties' => false,
						),
					),
					'content'        => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Block markup that replaces the whole content; not together with blocks.', 'leitvia-studio' ),
					),
					'date'           => Content::date_schema(),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'pending', 'publish', 'future' ),
						'description' => __( 'New status of the post; future needs a date in the future.', 'leitvia-studio' ),
					),
					'author'         => Content::author_schema(),
					'author_name'    => Content::author_name_schema(),
					'author_page'    => Content::author_page_schema(),
					'terms'          => Content::terms_schema(),
					'fields'         => Content::fields_schema(),
					'rows'           => Content::rows_schema(),
					'featured_image' => Content::image_schema( false ),
					'comment_status' => Content::comment_status_schema(),
					'template'       => array(
						'type'        => 'string',
						'pattern'     => '^[a-z0-9-]*$',
						'description' => __( 'Template of the contract: a page template for pages, a single template for the other types. Empty or default: the standard template.', 'leitvia-studio' ),
					),
					'parent'         => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'ID of the new parent page from leitvia/list-content, 0 for the top level; only for pages and other hierarchical types.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_update_content' )
		);
		$link  = array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => 2000,
			'description' => __( 'A link as it stands in the href of the post (from), or its new target (to).', 'leitvia-studio' ),
		);
		$image = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Attachment ID of the image.', 'leitvia-studio' ),
		);
		$alt   = array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => 500,
			'description' => __( 'Alt text that says what the image shows.', 'leitvia-studio' ),
		);
		$block = array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => 200,
			'description' => __( 'Block ID or path ID of the image or cover block from the outline.', 'leitvia-studio' ),
		);
		Abilities::write(
			'leitvia/repair-links',
			__( 'Repair links', 'leitvia-studio' ),
			__( 'Repairs internal links of one post: from pairs of old and new link, or with auto through the old slugs WordPress keeps for redirects. The base hash is the hash of the post from leitvia/get-page-outline. Only links of this site change. External links are never requested, only listed in the report, as are internal links that lead nowhere and cannot be resolved. Links with an empty href or a # without target are listed under empty, with in (content or page) and their class.', 'leitvia-studio' ),
			'maintain',
			$overwrite,
			array(
				'properties'           => array(
					'post'  => $post,
					'links' => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'from' => $link,
								'to'   => $link,
							),
							'required'             => array( 'from', 'to' ),
							'additionalProperties' => false,
						),
					),
					'auto'  => array(
						'type'        => 'boolean',
						'description' => __( 'Find links to posts whose slug changed and point them to the current address.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_repair_links' )
		);
		Abilities::write(
			'leitvia/set-alt-text',
			__( 'Set alt text', 'leitvia-studio' ),
			__( 'Sets the alt text of an image: without post and block on the attachment in the media library (base_hash of the attachment), with post and block on one image or cover block that shows it (base_hash of the post). leitvia/get-page-outline returns both hashes, for the attachment with its ID as post. Many images: items, one call and package. The alt text check applies.', 'leitvia-studio' ),
			'maintain',
			$overwrite,
			array(
				'properties'           => array(
					'image' => $image,
					'alt'   => $alt,
					'post'  => $post,
					'block' => $block,
					'items' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => self::MAX_ALT_TEXTS,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'image'     => $image,
								'alt'       => $alt,
								'post'      => $post,
								'block'     => $block,
								'base_hash' => array(
									'type'        => 'string',
									'description' => __( 'Hash of the attachment, or of the post with post and block; items on the same post send the hash read before the call.', 'leitvia-studio' ),
								),
							),
							'required'             => array( 'image', 'alt', 'base_hash' ),
							'additionalProperties' => false,
						),
					),
				),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_set_alt_text' ),
			false
		);
		Abilities::write(
			'leitvia/replace-pattern',
			__( 'Replace pattern', 'leitvia-studio' ),
			__( 'Replaces top-level sections of one post with another pattern. Without pattern the sections that use a deprecated pattern (metadata.patternName) get the replacement the contract names, all of them or the one given by block; leitvia/audit-site reports such posts, and a section without replacement is rejected with leitvia_no_replacement. With pattern the one section given by block gets that pattern, any approved one of leitvia/list-patterns, with edits at block IDs of its outline as in leitvia/compose-page (text, link, image, caption, catalog attributes such as the level of a heading, anchor, columns of a grid, remove, repeat and insert): so a section of an existing page changes its pattern. Texts and button links of the old section move over in order per block type (carry false keeps the texts of the pattern), then the edits apply and win; the HTML anchor of the old section does not move over, set it again with an anchor edit. The base hash is the hash of the post from leitvia/get-page-outline.', 'leitvia-studio' ),
			'maintain',
			$overwrite,
			array(
				'properties'           => array(
					'post'    => $post,
					'block'   => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => __( 'Block ID or path ID of a top-level section from the outline.', 'leitvia-studio' ),
					),
					'pattern' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Pattern slug such as leitvia/cta-split, or block:<ID> for a Lab pattern, that replaces the section given by block.', 'leitvia-studio' ),
					),
					'edits'   => Build::sections_schema()['items']['properties']['edits'],
					'carry'   => array(
						'type'        => 'boolean',
						'description' => __( 'With pattern: whether texts and button links of the old section move over before the edits; true by default.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_replace_pattern' )
		);
		$insert = array(
			'destructive' => false,
			'idempotent'  => false,
		);
		$place  = static fn( string $description ): array => array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => 200,
			'description' => $description,
		);
		Abilities::write(
			'leitvia/insert-section',
			__( 'Insert section', 'leitvia-studio' ),
			__( 'Inserts sections into the content of an existing post, page or entry, or moves one of its top-level sections; the rest of the content stays as it is. sections takes patterns with edits as in leitvia/compose-page, with the same checks; move takes the ID of a top-level section of the post instead. The place is after or before a top-level section (block ID or path ID from the outline) or position start or end; without a place the sections go to the end. wrap takes the ID of a top-level block instead, for example a shortcode or a block of another plugin, and puts it unchanged into a new section at its place: full width with the spacing of a section, the ground surface, surface-alt or inverse, and with heading an h2 above it. template switches the post to another template of the contract in the same operation, so a page with the template page gets a hero with its h1 and page-landing, which prints no title, at once and keeps its other blocks as they are. An anchor of a new section must be unique on the whole page. Read the post with leitvia/get-page-outline first and send its hash as base_hash. leitvia/remove-section takes a section out, leitvia/replace-pattern gives one another pattern.', 'leitvia-studio' ),
			'maintain',
			$insert,
			array(
				'properties'           => array(
					'post'     => $post,
					'sections' => Build::sections_schema(),
					'move'     => $place( __( 'Block ID or path ID of a top-level section of the post that moves to the place, instead of sections.', 'leitvia-studio' ) ),
					'wrap'     => array(
						'type'                 => 'object',
						'description'          => __( 'A top-level block that goes unchanged into a new section at its place, instead of sections or move.', 'leitvia-studio' ),
						'properties'           => array(
							'block'   => $place( __( 'Block ID or path ID of the top-level block from the outline.', 'leitvia-studio' ) ),
							'ground'  => array(
								'type'        => 'string',
								'enum'        => array_keys( self::GROUNDS ),
								'description' => __( 'Ground of the section: surface (default), surface-alt or inverse, the dark band.', 'leitvia-studio' ),
							),
							'heading' => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 200,
								'description' => __( 'Text of an h2 above the block.', 'leitvia-studio' ),
							),
						),
						'required'             => array( 'block' ),
						'additionalProperties' => false,
					),
					'after'    => $place( __( 'Block ID or path ID of the top-level section the new sections follow.', 'leitvia-studio' ) ),
					'before'   => $place( __( 'Block ID or path ID of the top-level section the new sections precede.', 'leitvia-studio' ) ),
					'position' => array(
						'type'        => 'string',
						'enum'        => array( 'start', 'end' ),
						'description' => __( 'Start or end of the content, instead of after or before.', 'leitvia-studio' ),
					),
					'template' => array(
						'type'        => 'string',
						'description' => __( 'Template of the contract the post switches to in the same operation, such as page-landing for a page that gets a hero. Empty or default: the standard template.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_insert_section' )
		);
		Abilities::write(
			'leitvia/remove-section',
			__( 'Remove section', 'leitvia-studio' ),
			__( 'Takes one top-level section out of the content of a post, page or entry; the rest of the content stays as it is. block is its block ID or path ID from the outline. The last section stays: a whole post goes with leitvia/trash-content. leitvia/rollback of the package brings the section back. Read the post with leitvia/get-page-outline first and send its hash as base_hash.', 'leitvia-studio' ),
			'maintain',
			$overwrite,
			array(
				'properties'           => array(
					'post'  => $post,
					'block' => $place( __( 'Block ID or path ID of a top-level section from the outline.', 'leitvia-studio' ) ),
				),
				'required'             => array( 'post', 'block' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_remove_section' )
		);
		$remove = array(
			'destructive' => true,
			'idempotent'  => false,
		);
		Abilities::write(
			'leitvia/trash-content',
			__( 'Trash content', 'leitvia-studio' ),
			__( 'Moves a post, page or entry to the trash. It stays restorable with its address: leitvia/rollback of the applied package or Restore in the trash of the admin bring it back, until WordPress empties the trash (after 30 days unless the site changed that). Removing content is the decision of a person: the change always waits in a change package, and applying it needs the right to delete the post. Read the post with leitvia/get-page-outline first and send its hash as base_hash. Refused for the front page, the posts page and the privacy policy page (point the setting to another page first) and for a page with subpages (move them with parent of leitvia/update-content, or trash them first). notice and report.links name the posts whose links lead nowhere afterwards. Files go with leitvia/delete-media.', 'leitvia-studio' ),
			'maintain',
			$remove,
			array(
				'properties'           => array(
					'post' => $post,
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_trash_content' )
		);
		Abilities::write(
			'leitvia/delete-media',
			__( 'Delete media', 'leitvia-studio' ),
			__( 'Deletes a file of the media library for good, with all its sizes; there is no way back. First it looks where the file is used: in blocks and links of content, templates, patterns and navigation, as featured image, as site logo or site icon. While published content or the site uses it, the call is refused with leitvia_media_in_use and the places in data.usage: replace the image there with leitvia/update-block, or send force to delete it anyway, and those places show no image. Places in drafts come as notice and report.usage. Removing content is the decision of a person: the change always waits in a change package, and applying it needs the right to delete the file. base_hash is the hash of the attachment from leitvia/get-page-outline with its ID as post.', 'leitvia-studio' ),
			'maintain',
			$remove,
			array(
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the file in the media library, from leitvia/list-media.', 'leitvia-studio' ),
					),
					'force' => array(
						'type'        => 'boolean',
						'description' => __( 'Delete the file although published content or the site still uses it.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_delete_media' )
		);
	}

	/**
	 * Operation of update-content. With content the target carries replace, so the pipeline allows no classic block in
	 * it even where the old content was classic.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_content( array $input ) {
		$post     = (int) ( $input['post'] ?? 0 );
		$edits    = (array) ( $input['blocks'] ?? array() );
		$replace  = isset( $input['content'] ) ? (string) $input['content'] : null;
		$fields   = array_filter(
			array(
				'post_title'     => $input['title'] ?? null,
				'post_excerpt'   => $input['excerpt'] ?? null,
				'post_name'      => $input['slug'] ?? null,
				'comment_status' => $input['comment_status'] ?? null,
			),
			'is_string'
		);
		$extra    = array_intersect_key( $input, array_flip( array( 'date', 'author', 'author_name', 'author_page', 'terms', 'fields', 'rows', 'featured_image' ) ) );
		$status   = isset( $input['status'] ) ? (string) $input['status'] : null;
		$template = isset( $input['template'] ) ? (string) $input['template'] : null;
		$parent   = isset( $input['parent'] ) ? (int) $input['parent'] : null;
		if ( null !== $replace && $edits ) {
			return Block_Edit::invalid( 'change', __( 'Send either content or blocks, not both.', 'leitvia-studio' ), array( 'post' => $post ) );
		}
		if ( ! $fields && ! $edits && null === $replace && ! $extra && null === $status && null === $template && null === $parent ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: title, excerpt, slug, date, status, author, terms, fields, rows, featured image, comment status, template, parent, blocks or content.', 'leitvia-studio' ), array( 'post' => $post ) );
		}
		if ( null !== $parent ) {
			$invalid = self::invalid_parent( $post, $parent );
			if ( $invalid ) {
				return $invalid;
			}
			$fields['post_parent'] = $parent;
		}
		return array(
			'area'   => 'content',
			'target' => array( 'post' => $post ) + ( null === $replace ? array() : array( 'replace' => true ) ),
			'record' => true,
			'change' => static function ( array $state ) use ( $post, $edits, $fields, $replace, $extra, $status, $template ) {
				$state = null === $template ? $state : self::with_template( $state, $template, $post );
				if ( is_wp_error( $state ) ) {
					return $state;
				}
				$content = $replace ?? Build::edit( (string) $state['post_content'], $edits, array( 'post' => $post ) );
				$state   = is_wp_error( $content ) ? $content : Content::change( array( 'post_content' => $content ) + $fields + $state, $extra, array( 'post' => $post ) );
				return is_wp_error( $state ) || null === $status ? $state : Content::status( $state, $status, isset( $extra['date'] ), array( 'post' => $post ) );
			},
		);
	}

	/**
	 * The state of a post with another template of the contract; '' or default is the standard template. Shared by
	 * update-content and insert-section, so a new hero and the template without a printed title land in one operation.
	 *
	 * @param array<string, mixed> $state    State of the post.
	 * @param string               $template Template slug.
	 * @param int                  $post     Post ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function with_template( array $state, string $template, int $post ) {
		// The state of an earlier operation of the same package from before Studio 1.3.31 has no template either.
		if ( ! array_key_exists( '_wp_page_template', (array) ( $state['meta_input'] ?? array() ) ) ) {
			return Block_Edit::invalid( 'template', __( 'Agents cannot set a template on this post: its post type has none, or an earlier operation of the same change package was made by an older version. Apply or discard that package first.', 'leitvia-studio' ), array( 'post' => $post ) );
		}
		$reset   = in_array( $template, array( '', 'default' ), true );
		$invalid = $reset ? null : Content::invalid_template( (string) $state['post_type'], $template, array( 'post' => $post ) );
		if ( $invalid ) {
			return $invalid;
		}
		$state['meta_input']['_wp_page_template'] = $reset ? '' : $template;
		return $state;
	}

	/**
	 * Operation of repair-links. Links are read and rewritten in the markup with the HTML API; block comments stay.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_repair_links( array $input ) {
		$post   = (int) ( $input['post'] ?? 0 );
		$target = array( 'post' => $post );
		$pairs  = array();
		foreach ( (array) ( $input['links'] ?? array() ) as $link ) {
			$from = trim( (string) ( $link['from'] ?? '' ) );
			$to   = trim( (string) ( $link['to'] ?? '' ) );
			if ( ! self::internal( $from ) || ! self::internal( $to ) ) {
				return Block_Edit::invalid( 'external_link', __( 'Only links of this site can be repaired. External links are never requested; change them by hand.', 'leitvia-studio' ), $target );
			}
			$pairs[ self::normal( $from ) ] = $to;
		}
		$auto = ! empty( $input['auto'] );
		if ( ! $pairs && ! $auto ) {
			return Block_Edit::invalid( 'change', __( 'Send pairs of old and new links, or set auto.', 'leitvia-studio' ), $target );
		}
		$report = array(
			'repaired'   => array(),
			'external'   => array(),
			'unresolved' => array(),
			'empty'      => array(),
		);
		return array(
			'area'   => 'corrections',
			'target' => $target,
			'record' => true,
			'report' => static function () use ( &$report ): array {
				return $report;
			},
			'change' => static function ( array $state ) use ( $pairs, $auto, $target, &$report ) {
				$tags = new \WP_HTML_Tag_Processor( (string) $state['post_content'] );
				while ( $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
					$href = $tags->get_attribute( 'href' );
					$href = is_string( $href ) ? trim( $href ) : '';
					if ( '' === $href ) {
						continue;
					}
					if ( ! self::internal( $href ) ) {
						if ( '' !== (string) wp_parse_url( $href, PHP_URL_HOST ) ) {
							$report['external'][] = $href;
						}
						continue;
					}
					$new = $pairs[ self::normal( $href ) ] ?? ( $auto ? self::resolve( $href ) : '' );
					if ( null === $new ) {
						$report['unresolved'][] = $href;
						continue;
					}
					if ( '' === $new || $new === $href ) {
						continue;
					}
					$fragment = (string) wp_parse_url( $href, PHP_URL_FRAGMENT );
					$new     .= '' !== $fragment && ! str_contains( $new, '#' ) ? '#' . $fragment : '';
					$tags->set_attribute( 'href', esc_url_raw( $new ) );
					$report['repaired'][] = array(
						'from' => $href,
						'to'   => $new,
					);
				}
				$report['external']   = array_values( array_unique( $report['external'] ) );
				$report['unresolved'] = array_values( array_unique( $report['unresolved'] ) );
				$saved                = get_post( (int) $target['post'] );
				$report['empty']      = $saved ? Read_Audit::empty_links( $saved ) : array();
				if ( ! $report['repaired'] ) {
					return Rejection::make( 'leitvia_invalid_input', __( 'No link needs or allows a repair. The report lists external links, internal links that could not be resolved and links that lead nowhere.', 'leitvia-studio' ), 422, 'nothing_to_repair', $target, '', 'never', array( 'report' => $report ) );
				}
				return array( 'post_content' => $tags->get_updated_html() ) + $state;
			},
		);
	}

	/**
	 * Operation of set-alt-text: on the attachment, or on one block of a post that shows the image; with items one per
	 * item as a batch (Abilities::execute_batch()).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_alt_text( array $input ) {
		if ( isset( $input['items'] ) ) {
			if ( isset( $input['image'] ) || isset( $input['alt'] ) || isset( $input['post'] ) || isset( $input['block'] ) ) {
				return Block_Edit::invalid( 'items', __( 'Send either items or one image with its alt text, not both.', 'leitvia-studio' ) );
			}
			return array(
				'batch' => array_map(
					static function ( $item ) {
						$item  = (array) $item;
						$parts = self::describe_set_alt_text( array_diff_key( $item, array( 'items' => true ) ) );
						return is_wp_error( $parts ) ? $parts : array( 'base_hash' => (string) ( $item['base_hash'] ?? '' ) ) + $parts;
					},
					array_slice( array_values( (array) $input['items'] ), 0, self::MAX_ALT_TEXTS )
				),
			);
		}
		if ( ! isset( $input['image'] ) ) {
			return Block_Edit::invalid( 'image', __( 'Send the image and its alt text, or items for many images.', 'leitvia-studio' ) );
		}
		$image = (int) $input['image'];
		$alt   = trim( (string) ( $input['alt'] ?? '' ) );
		if ( '' === $alt ) {
			return Block_Edit::invalid( 'alt_text', __( 'Give the image an alt text that describes it.', 'leitvia-studio' ), array( 'post' => $image ) );
		}
		if ( isset( $input['post'] ) !== isset( $input['block'] ) ) {
			return Block_Edit::invalid( 'target', __( 'Send post and block together for a block, or neither for the attachment.', 'leitvia-studio' ), array( 'post' => $image ) );
		}
		if ( ! isset( $input['post'] ) ) {
			return array(
				'area'   => 'corrections',
				'target' => array( 'post' => $image ),
				'record' => true,
				'change' => static function ( array $state ) use ( $image, $alt ) {
					if ( 'attachment' !== $state['post_type'] || ! wp_attachment_is_image( $image ) ) {
						return Block_Edit::invalid( 'image', __( 'The ID is no image of the media library.', 'leitvia-studio' ), array( 'post' => $image ) );
					}
					$findings = Content_Checks::alt_text( Build::image_markup( wp_basename( (string) get_attached_file( $image ) ), $alt, false ), array( 'post_type' => 'page' ) );
					if ( $findings ) {
						return Rejection::make( 'leitvia_check_failed', __( 'Checks found problems. Fix them as each finding suggests and try again.', 'leitvia-studio' ), 422, 'checks', array( 'post' => $image ), '', 'once', array( 'findings' => $findings ) );
					}
					$state['meta_input']['_wp_attachment_image_alt'] = $alt;
					return $state;
				},
			);
		}
		$post   = (int) $input['post'];
		$block  = (string) $input['block'];
		$target = array(
			'post'  => $post,
			'block' => $block,
		);
		return array(
			'area'   => 'corrections',
			'target' => $target,
			'record' => true,
			'change' => static function ( array $state ) use ( $post, $block, $image, $alt, $target ) {
				$found = Outline::find( Block_Ids::blocks( parse_blocks( (string) $state['post_content'] ) ), $block );
				if ( $found && (int) ( $found[0]['attrs']['id'] ?? 0 ) !== $image ) {
					return Block_Edit::invalid( 'image', __( 'The block does not show this image. Read the outline for the block that does.', 'leitvia-studio' ), $target, 'leitvia/get-page-outline' );
				}
				$content = Block_Edit::apply(
					(string) $state['post_content'],
					$block,
					array(
						'image' => array(
							'id'  => $image,
							'alt' => $alt,
						),
					),
					array( 'post' => $post )
				);
				return is_wp_error( $content ) ? $content : array( 'post_content' => $content ) + $state;
			},
		);
	}

	/**
	 * Operation of replace-pattern: with pattern the one section gets that pattern with its edits (the checks of
	 * compose-page, Build::sections()), without it deprecated patterns get their replacement.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_replace_pattern( array $input ) {
		$post    = (int) ( $input['post'] ?? 0 );
		$block   = (string) ( $input['block'] ?? '' );
		$target  = array( 'post' => $post ) + ( '' !== $block ? array( 'block' => $block ) : array() );
		$ref     = (string) ( $input['pattern'] ?? '' );
		$section = null;
		if ( '' === $ref && ( isset( $input['edits'] ) || isset( $input['carry'] ) ) ) {
			return Block_Edit::invalid( 'pattern', __( 'edits and carry belong to a new pattern; send pattern as well.', 'leitvia-studio' ), $target, 'leitvia/list-patterns' );
		}
		if ( '' !== $ref ) {
			if ( '' === $block ) {
				return Block_Edit::invalid( 'block', __( 'Name the section the pattern replaces with block, a top-level block ID or path ID from the outline.', 'leitvia-studio' ), $target, 'leitvia/get-page-outline' );
			}
			$found = Build::sections(
				array(
					array(
						'pattern' => $ref,
						'edits'   => (array) ( $input['edits'] ?? array() ),
					),
				)
			);
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			$section = $found[0] + array( 'carry' => ! isset( $input['carry'] ) || ! empty( $input['carry'] ) );
		}
		return array(
			'area'   => 'structure',
			'target' => $target,
			'record' => true,
			'change' => static function ( array $state ) use ( $post, $block, $section ) {
				$content = self::replace( (string) $state['post_content'], $block, array( 'post' => $post ), $section );
				return is_wp_error( $content ) ? $content : array( 'post_content' => $content ) + $state;
			},
		);
	}

	/**
	 * Markup with top-level sections replaced: the sections of deprecated patterns, all or the one with the ID; or
	 * with a section from Build::sections() the one with the ID.
	 *
	 * @param string                    $content Block markup.
	 * @param string                    $only    leitviaId or path ID of one section, '' for all.
	 * @param array<string, mixed>      $target  Target for rejections.
	 * @param array<string, mixed>|null $section Pattern with edits and carry that replaces the section, null for the
	 *                                           replacement of a deprecated pattern.
	 * @return string|\WP_Error
	 */
	private static function replace( string $content, string $only, array $target, ?array $section = null ) {
		// ponytail: top-level sections only, as compose-page writes them; nested ones would need placeholder bookkeeping.
		$blocks   = array();
		$index    = 0;
		$replaced = 0;
		$found    = false;
		foreach ( parse_blocks( $content ) as $block ) {
			if ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) ) {
				$blocks[] = $block;
				continue;
			}
			$path   = 'p:' . ( $index++ );
			$id     = '' !== Block_Ids::id( $block ) ? Block_Ids::id( $block ) : $path;
			$chosen = '' === $only || $id === $only || $path === $only;
			$found  = $found || ( '' !== $only && $chosen );
			$name   = (string) ( $block['attrs']['metadata']['patternName'] ?? '' );
			if ( ! $chosen || ( '' === $only && ( '' === $name || 'deprecated' !== Status::own( 'pattern:' . $name ) ) ) ) {
				$blocks[] = $block;
				continue;
			}
			if ( $section ) {
				$here   = $target + array( 'block' => $id );
				$markup = $section['carry'] ? self::carry( $block, (string) $section['content'], $here ) : (string) $section['content'];
				$markup = is_wp_error( $markup ) ? $markup : Build::edit( $markup, (array) $section['edits'], $here );
				if ( is_wp_error( $markup ) ) {
					return $markup;
				}
				$blocks = array_merge( $blocks, parse_blocks( Build::name_sections( $markup, (string) $section['name'] ) ) );
				++$replaced;
				continue;
			}
			$ref     = '' !== $name ? (string) preg_replace( '/^pattern:/', '', (string) ( leitvia_studio_contract()->pattern( $name )['docs']['replacedBy'] ?? '' ) ) : '';
			$pattern = '' !== $ref ? Build::pattern( $ref ) : null;
			if ( ! $pattern || ( is_wp_error( $pattern ) && 'leitvia_unknown_pattern' === $pattern->get_error_code() ) ) {
				return self::no_replacement( $target + array( 'block' => $id ), $name );
			}
			if ( is_wp_error( $pattern ) ) {
				return $pattern;
			}
			$markup = self::carry( $block, Build::name_sections( $pattern['content'], $pattern['name'] ), $target );
			if ( is_wp_error( $markup ) ) {
				return $markup;
			}
			$blocks = array_merge( $blocks, parse_blocks( $markup ) );
			++$replaced;
		}
		if ( '' !== $only && ! $found ) {
			return Rejection::make( 'leitvia_not_found', __( 'There is no top-level section with this ID. Read the outline for the current IDs.', 'leitvia-studio' ), 404, 'block', $target + array( 'block' => $only ), 'leitvia/get-page-outline' );
		}
		if ( ! $replaced ) {
			return self::no_replacement( $target, '' );
		}
		$anchors = array();
		foreach ( (array) ( $section['edits'] ?? array() ) as $edit ) {
			$anchors = array_merge( $anchors, Block_Edit::anchors( (array) $edit ), Block_Edit::anchors( (array) ( $edit['insert'] ?? array() ) ) );
		}
		// An anchor of the new section must be unique on the whole page, as in compose-page.
		return Block_Edit::unique_anchors( serialize_blocks( $blocks ), $anchors, $target );
	}

	/**
	 * Rejection leitvia_no_replacement.
	 *
	 * @param array<string, mixed> $target  Target.
	 * @param string               $pattern Pattern of the section, '' when no section uses a deprecated pattern.
	 */
	private static function no_replacement( array $target, string $pattern ): \WP_Error {
		return Rejection::make( 'leitvia_no_replacement', __( 'There is no deprecated pattern with an available replacement here. Choose a pattern and change the section by hand or with leitvia/compose-page.', 'leitvia-studio' ), 422, 'no_replacement', $target, 'leitvia/list-patterns', 'never', array( 'pattern' => $pattern ) );
	}

	/**
	 * Operation of insert-section: new sections (the checks of compose-page, Build::sections()) or one top-level
	 * section of the post go to a place among its top-level sections.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_insert_section( array $input ) {
		$post     = (int) ( $input['post'] ?? 0 );
		$target   = array( 'post' => $post );
		$move     = (string) ( $input['move'] ?? '' );
		$wrap     = isset( $input['wrap'] ) ? (array) $input['wrap'] : null;
		$template = isset( $input['template'] ) ? (string) $input['template'] : null;
		$place    = array_intersect_key( $input, array_flip( array( 'after', 'before', 'position' ) ) );
		if ( 1 !== (int) isset( $input['sections'] ) + (int) ( '' !== $move ) + (int) ( null !== $wrap ) ) {
			return Block_Edit::invalid( 'change', __( 'Send one of sections, move or wrap.', 'leitvia-studio' ), $target );
		}
		if ( count( $place ) > 1 || ( $wrap && $place ) ) {
			return Block_Edit::invalid( 'place', __( 'Name one place: after, before or position. wrap takes none, the section stays where the block is.', 'leitvia-studio' ), $target, 'leitvia/get-page-outline' );
		}
		$sections = isset( $input['sections'] ) ? Build::sections( (array) $input['sections'] ) : array();
		if ( is_wp_error( $sections ) ) {
			return $sections;
		}
		$place = $place ? $place : array( 'position' => 'end' );
		return array(
			'area'   => 'content',
			'target' => $target,
			'record' => true,
			'change' => static function ( array $state ) use ( $sections, $move, $wrap, $template, $place, $target, $post ) {
				$state = null === $template ? $state : self::with_template( $state, $template, $post );
				if ( is_wp_error( $state ) ) {
					return $state;
				}
				[ $markup, $at ] = self::top_level( (string) $state['post_content'] );
				if ( $wrap ) {
					$id = (string) $wrap['block'];
					if ( ! isset( $at[ $id ] ) ) {
						return self::no_section( $target, $id );
					}
					$markup[ $at[ $id ] ] = self::wrapped( $markup[ $at[ $id ] ], (string) ( $wrap['ground'] ?? 'surface' ), (string) ( $wrap['heading'] ?? '' ) );
					return array( 'post_content' => implode( "\n\n", $markup ) ) + $state;
				}
				$new = $sections ? Build::compose( $sections ) : '';
				if ( is_wp_error( $new ) ) {
					return $new;
				}
				$ref = (string) current( $place );
				foreach ( array_filter( array( $move, isset( $place['position'] ) ? '' : $ref ) ) as $id ) {
					if ( ! isset( $at[ $id ] ) ) {
						return self::no_section( $target, $id );
					}
				}
				$offset = match ( key( $place ) ) {
					'position' => 'start' === $ref ? 0 : count( $markup ),
					'after'    => $at[ $ref ] + 1,
					default    => $at[ $ref ],
				};
				array_splice( $markup, $offset, 0, array( '' !== $move ? $markup[ $at[ $move ] ] : $new ) );
				if ( '' !== $move ) {
					// The old place of the moved section, one further down when its new place is above it.
					unset( $markup[ $at[ $move ] + ( $offset <= $at[ $move ] ? 1 : 0 ) ] );
				}
				$anchors = array();
				foreach ( $sections as $section ) {
					foreach ( (array) $section['edits'] as $edit ) {
						$anchors = array_merge( $anchors, Block_Edit::anchors( (array) $edit ), Block_Edit::anchors( (array) ( $edit['insert'] ?? array() ) ) );
					}
				}
				// An anchor of a new section must be unique on the whole page, as in compose-page.
				$content = Block_Edit::unique_anchors( implode( "\n\n", $markup ), $anchors, $target );
				return is_wp_error( $content ) ? $content : array( 'post_content' => $content ) + $state;
			},
		);
	}

	/**
	 * A top-level block inside a new section as the text patterns build one (leitvia/content-text): a full-width
	 * constrained group with the spacing xl of a section of regular density, its ground and an optional h2 at the wide
	 * edge, where the headings of the patterns start. The block markup goes in as it is, so a shortcode or a block of another plugin stays byte for byte.
	 *
	 * @param string $block   Serialized top-level block.
	 * @param string $ground  Key of GROUNDS.
	 * @param string $heading Text of the h2, '' for none.
	 */
	private static function wrapped( string $block, string $ground, string $heading ): string {
		$ground = self::GROUNDS[ $ground ] ?? self::GROUNDS['surface'];
		// Attributes in the order of the serializer of the editor: metadata, align, className, style, backgroundColor.
		$attrs = array(
			'metadata' => array( 'name' => __( 'Section', 'leitvia-studio' ) ),
			'align'    => 'full',
		) + array_intersect_key( $ground, array( 'className' => true ) ) + array(
			'style' => array(
				'spacing' => array(
					'padding' => array(
						'top'    => 'var:preset|spacing|xl',
						'bottom' => 'var:preset|spacing|xl',
					),
				),
			),
		) + array_intersect_key( $ground, array( 'backgroundColor' => true ) ) + array( 'layout' => array( 'type' => 'constrained' ) );
		$class = 'wp-block-group alignfull' . ( isset( $attrs['className'] ) ? ' ' . $attrs['className'] : '' ) . ( isset( $attrs['backgroundColor'] ) ? ' has-' . $attrs['backgroundColor'] . '-background-color has-background' : '' );
		$h2    = '' === $heading ? '' : "<!-- wp:heading {\"align\":\"wide\",\"fontSize\":\"heading-l\"} -->\n<h2 class=\"wp-block-heading alignwide has-heading-l-font-size\">" . esc_html( $heading ) . "</h2>\n<!-- /wp:heading -->\n\n";
		return '<!-- wp:group ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . " -->\n"
			. '<div class="' . esc_attr( $class ) . '" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">' . "\n"
			. $h2 . $block . "\n</div>\n<!-- /wp:group -->";
	}

	/**
	 * Operation of remove-section: the content without one top-level section.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function describe_remove_section( array $input ): array {
		$block  = (string) ( $input['block'] ?? '' );
		$target = array(
			'post'  => (int) ( $input['post'] ?? 0 ),
			'block' => $block,
		);
		return array(
			'area'   => 'content',
			'target' => $target,
			'record' => true,
			'change' => static function ( array $state ) use ( $block, $target ) {
				[ $markup, $at ] = self::top_level( (string) $state['post_content'] );
				if ( ! isset( $at[ $block ] ) ) {
					return self::no_section( $target, $block );
				}
				if ( 1 === count( $markup ) ) {
					return Block_Edit::invalid( 'block', __( 'This is the last section of the post. Replace it with leitvia/replace-pattern, or trash the post with leitvia/trash-content.', 'leitvia-studio' ), $target, 'leitvia/trash-content' );
				}
				unset( $markup[ $at[ $block ] ] );
				return array( 'post_content' => implode( "\n\n", $markup ) ) + $state;
			},
		);
	}

	/**
	 * Top-level sections of a content: their markup in order, and the index of each by its leitviaId and its path ID.
	 *
	 * @param string $content Block markup.
	 * @return array{0: list<string>, 1: array<string, int>}
	 */
	private static function top_level( string $content ): array {
		$markup = array();
		$at     = array();
		foreach ( Block_Ids::blocks( parse_blocks( $content ) ) as $index => $block ) {
			$markup[]            = serialize_block( $block );
			$at[ 'p:' . $index ] = $index;
			if ( '' !== Block_Ids::id( $block ) ) {
				$at[ Block_Ids::id( $block ) ] = $index;
			}
		}
		return array( $markup, $at );
	}

	/**
	 * Rejection for a section ID the post does not have at the top level.
	 *
	 * @param array<string, mixed> $target Target.
	 * @param string               $id     Block ID or path ID.
	 */
	private static function no_section( array $target, string $id ): \WP_Error {
		return Rejection::make( 'leitvia_not_found', __( 'There is no top-level section with this ID. Read the outline for the current IDs.', 'leitvia-studio' ), 404, 'block', array( 'block' => $id ) + $target, 'leitvia/get-page-outline' );
	}

	/**
	 * Replacement markup with the texts and button links of the old section, in order per block type.
	 *
	 * @param array<string, mixed> $old    Old section.
	 * @param string               $markup Replacement markup.
	 * @param array<string, mixed> $target Target for rejections.
	 * @return string|\WP_Error
	 */
	private static function carry( array $old, string $markup, array $target ) {
		// ponytail: plain text only; inline formatting and links inside paragraphs do not move over.
		$texts = array();
		foreach ( self::texts( array( $old ), 'p:' ) as $item ) {
			$texts[ $item['name'] ][] = $item;
		}
		foreach ( self::texts( parse_blocks( $markup ), 'p:' ) as $item ) {
			$from = array_shift( $texts[ $item['name'] ] );
			if ( ! $from || '' === $from['text'] ) {
				continue;
			}
			$change = array( 'text' => $from['text'] ) + ( 'core/button' === $item['name'] && '' !== $from['url'] ? array( 'url' => $from['url'] ) : array() );
			$markup = Block_Edit::apply( $markup, $item['path'], $change, $target );
			if ( is_wp_error( $markup ) ) {
				return $markup;
			}
		}
		return $markup;
	}

	/**
	 * Text blocks in document order with name, path ID (counted as Block_Edit counts), plain text and link.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @param string       $path   Path prefix.
	 * @return list<array{name: string, path: string, text: string, url: string}>
	 */
	private static function texts( array $blocks, string $path ): array {
		$found = array();
		$index = 0;
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) ) {
				continue;
			}
			$here = $path . ( $index++ );
			$name = (string) $block['blockName'];
			if ( isset( Block_Edit::TEXT_BLOCKS[ $name ] ) ) {
				$html    = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
				$tags    = new \WP_HTML_Tag_Processor( $html );
				$url     = $tags->next_tag( array( 'tag_name' => 'a' ) ) ? $tags->get_attribute( 'href' ) : '';
				$found[] = array(
					'name' => $name,
					'path' => $here,
					'text' => trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
					'url'  => is_string( $url ) ? $url : '',
				);
			}
			$found = array_merge( $found, self::texts( (array) $block['innerBlocks'], $here . '.' ) );
		}
		return $found;
	}

	/**
	 * Rejection when a post cannot get this parent: its type has no hierarchy, the parent is no post of the same type
	 * agents can read, or it is the post itself or one of its descendants; null when it can. 0 is always fine.
	 *
	 * @param int $post  Post ID.
	 * @param int $above ID of the new parent, 0 for the top level.
	 */
	private static function invalid_parent( int $post, int $above ): ?\WP_Error {
		$type   = (string) get_post_type( $post );
		$target = array( 'post' => $post );
		if ( '' !== $type && ! is_post_type_hierarchical( $type ) ) {
			return Block_Edit::invalid( 'parent', __( 'Only pages and other hierarchical types have a parent.', 'leitvia-studio' ), $target );
		}
		if ( ! $above ) {
			return null;
		}
		if ( get_post_type( $above ) !== $type || ! Scope::readable( $above ) ) {
			return Block_Edit::invalid( 'parent', __( 'The parent must be a post of the same type that agents can read. Take its ID from leitvia/list-content.', 'leitvia-studio' ), $target + array( 'parent' => $above ), 'leitvia/list-content' );
		}
		if ( $above === $post || in_array( $post, array_map( 'intval', get_post_ancestors( $above ) ), true ) ) {
			return Block_Edit::invalid( 'parent', __( 'A page cannot go below itself or one of its subpages. Move the subpage first.', 'leitvia-studio' ), $target + array( 'parent' => $above ) );
		}
		return null;
	}

	/**
	 * Operation of trash-content: the post with the status trash, which the pipeline writes through wp_trash_post()
	 * and a rollback through wp_untrash_post() (target remove). Refused while a setting points to the page, while it
	 * has subpages whose addresses would break, and on a site without trash, where core would delete the post at once.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_trash_content( array $input ) {
		$post   = (int) ( $input['post'] ?? 0 );
		$target = array( 'post' => $post );
		$type   = (string) get_post_type( $post );
		if ( ! $post || 'attachment' === $type || ! is_post_type_viewable( $type ) || ! Scope::readable( $post ) ) {
			return Rejection::make( 'leitvia_not_found', __( 'There is no post, page or entry with this ID that agents can read. Take the ID from leitvia/list-content; files go with leitvia/delete-media.', 'leitvia-studio' ), 404, 'post', $target, 'leitvia/list-content' );
		}
		if ( ! EMPTY_TRASH_DAYS ) {
			return Block_Edit::invalid( 'trash', __( 'This site keeps no trash, so WordPress would delete the post for good. A person can delete it in the admin.', 'leitvia-studio' ), $target );
		}
		$settings = array(
			'page_on_front'              => 'leitvia/set-front-page',
			'page_for_posts'             => 'leitvia/set-posts-page',
			'wp_page_for_privacy_policy' => 'leitvia/set-privacy-page',
		);
		foreach ( $settings as $option => $next ) {
			if ( (int) get_option( $option ) === $post ) {
				/* translators: 1: option name, 2: ability name */
				return Block_Edit::invalid( 'setting', sprintf( __( 'The site uses this page in its settings (%1$s). Point the setting to another page with %2$s first.', 'leitvia-studio' ), $option, $next ), $target + array( 'option' => $option ), $next );
			}
		}
		$children = get_posts(
			array(
				'post_type'      => $type,
				'post_parent'    => $post,
				'post_status'    => self::USE_STATUSES,
				'fields'         => 'ids',
				'posts_per_page' => 20,
				'no_found_rows'  => true,
			)
		);
		if ( $children ) {
			return Block_Edit::invalid(
				'children',
				/* translators: %s: list of post IDs */
				sprintf( __( 'The page has subpages, whose addresses would break in the trash: %s. Move them with parent of leitvia/update-content, or trash them first.', 'leitvia-studio' ), implode( ', ', array_map( 'intval', $children ) ) ),
				$target + array( 'children' => array_map( 'intval', $children ) ),
				'leitvia/update-content'
			);
		}
		$links = self::linked( $post );
		return array(
			'area'   => 'content',
			'target' => $target + array( 'remove' => 'trash' ),
			'record' => true,
			/* translators: %s: list of places such as 12 (Page, Published) */
			'notice' => $links ? sprintf( __( 'These posts link to it, and their links lead nowhere once it is in the trash: %s. Change them with leitvia/update-content.', 'leitvia-studio' ), self::places( $links ) ) : '',
			'report' => static fn(): array => array( 'links' => $links ),
			'change' => static fn( array $state ): array => array( 'post_status' => 'trash' ) + $state,
		);
	}

	/**
	 * Operation of delete-media: the attachment with the status trash as the mark, which the pipeline writes as a
	 * forced deletion with its files (target remove). Refused while published content or the site uses the file,
	 * unless force.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_delete_media( array $input ) {
		$id     = (int) ( $input['id'] ?? 0 );
		$target = array( 'post' => $id );
		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! Scope::readable( $id ) ) {
			return Rejection::make( 'leitvia_not_found', __( 'There is no file with this ID in the media library. List the media for the current IDs.', 'leitvia-studio' ), 404, 'id', $target, 'leitvia/list-media' );
		}
		$usage = self::media_usage( $id );
		$shown = array_filter( $usage, static fn( array $place ): bool => $place['public'] );
		if ( $shown && empty( $input['force'] ) ) {
			return Rejection::make(
				'leitvia_media_in_use',
				/* translators: %s: list of places such as 12 (Page, Published) or site_logo */
				sprintf( __( 'Published content or the site still uses this file: %s. Replace it there with leitvia/update-block first, or send force to delete it anyway; those places then show no image.', 'leitvia-studio' ), self::places( $shown ) ),
				409,
				'in_use',
				$target,
				'leitvia/update-block',
				'never',
				array( 'usage' => $usage )
			);
		}
		return array(
			'area'   => 'content',
			'target' => $target + array( 'remove' => 'delete' ),
			/* translators: %s: list of places such as 12 (Page, Draft) */
			'notice' => $usage ? sprintf( __( 'These places still use the file and show no image once it is deleted: %s.', 'leitvia-studio' ), self::places( $usage ) ) : '',
			'report' => static fn(): array => array( 'usage' => $usage ),
			'change' => static fn( array $state ): array => array( 'post_status' => 'trash' ) + $state,
		);
	}

	/**
	 * Posts whose content links to the post: links to its address and, in navigation menus, links by its ID.
	 *
	 * @param int $post Post ID.
	 * @return list<array<string, mixed>>
	 */
	private static function linked( int $post ): array {
		$url    = (string) get_permalink( $post );
		$needle = untrailingslashit( wp_make_link_relative( $url ) );
		$found  = array();
		foreach ( self::containing( array_filter( array( $needle, '"id":' . $post ) ), $post ) as $other ) {
			$links = 'wp_navigation' === $other->post_type && preg_match( '/"id":' . $post . '\b/', $other->post_content );
			$tags  = new \WP_HTML_Tag_Processor( $other->post_content );
			while ( ! $links && $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
				$href  = $tags->get_attribute( 'href' );
				$links = is_string( $href ) && self::internal( trim( $href ) ) && self::normal( trim( $href ) ) === self::normal( $url );
			}
			if ( $links ) {
				$found[] = self::place( $other );
			}
		}
		return $found;
	}

	/**
	 * Places that use an attachment: content with a block of it (wp-image-<ID>, "id" or "mediaId") or a link to one of
	 * its files, posts with it as featured image, and the site logo and icon. public says whether visitors see it.
	 * ponytail: file names with the size suffix or -scaled only; edited copies (-e<time>) and WooCommerce galleries
	 * are not searched.
	 *
	 * @param int $id Attachment ID.
	 * @return list<array<string, mixed>>
	 */
	private static function media_usage( int $id ): array {
		$file    = (string) get_post_meta( $id, '_wp_attached_file', true );
		$stem    = '' !== $file ? (string) preg_replace( '/\.[^.\/]+$/', '', $file ) : '';
		$pattern = '/wp-image-' . $id . '\b|"(?:id|mediaId)":' . $id . '\b' . ( '' !== $stem ? '|' . preg_quote( $stem, '/' ) . '(?:-\d+x\d+|-scaled)?\.' : '' ) . '/';
		$usage   = array();
		foreach ( self::containing( array_filter( array( 'wp-image-' . $id, '"id":' . $id, '"mediaId":' . $id, $stem ) ), $id ) as $post ) {
			if ( preg_match( $pattern, $post->post_content ) ) {
				$usage[] = self::place( $post ) + array( 'where' => 'content' );
			}
		}
		$featured = get_posts(
			array(
				'post_type'      => array_values( array_diff( Scope::post_types(), array( 'attachment' ) ) ),
				'post_status'    => self::USE_STATUSES,
				'posts_per_page' => self::MAX_USAGE,
				'no_found_rows'  => true,
				'meta_key'       => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup before a deletion.
				'meta_value'     => (string) $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);
		foreach ( $featured as $post ) {
			$usage[] = self::place( $post ) + array( 'where' => 'featured_image' );
		}
		foreach ( array( 'site_logo', 'site_icon' ) as $option ) {
			if ( (int) get_option( $option ) === $id ) {
				$usage[] = array(
					'option' => $option,
					'where'  => 'setting',
					'public' => true,
				);
			}
		}
		return $usage;
	}

	/**
	 * Posts in scope but the given one, in a status of USE_STATUSES, whose content holds one of the strings; at most
	 * MAX_USAGE. Callers check each hit, LIKE only narrows the search.
	 *
	 * @param string[] $needles Strings to search for.
	 * @param int      $skip    Post ID to leave out.
	 * @return list<\WP_Post>
	 */
	private static function containing( array $needles, int $skip ): array {
		global $wpdb;
		$types = array_values( array_diff( Scope::post_types(), array( 'attachment', 'leitvia_tokens', 'wp_global_styles' ) ) );
		if ( ! $needles || ! $types ) {
			return array();
		}
		$likes = array_map( static fn( string $needle ): string => '%' . $wpdb->esc_like( $needle ) . '%', array_values( $needles ) );
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One search before a removal, posts are read through get_post().
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID <> %d AND post_status IN (" . implode( ',', array_fill( 0, count( self::USE_STATUSES ), '%s' ) ) . ') AND post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND (' . implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) ) . ') ORDER BY ID ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built per status, type and string.
				array_merge( array( $skip ), self::USE_STATUSES, $types, $likes, array( self::MAX_USAGE ) )
			)
		);
		return array_values( array_filter( array_map( static fn( $post ) => get_post( (int) $post ), $ids ), static fn( $post ): bool => $post instanceof \WP_Post && '' === $post->post_password ) );
	}

	/**
	 * A post as place of use: ID, type, title, status and whether visitors see it.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private static function place( \WP_Post $post ): array {
		return array(
			'post'      => $post->ID,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'public'    => in_array( $post->post_status, self::PUBLIC_STATUSES, true ),
		);
	}

	/**
	 * Places as text for a message, such as 12 (Page, Published), site_logo.
	 *
	 * @param array<array<string, mixed>> $places Places from place() or media_usage().
	 */
	private static function places( array $places ): string {
		return implode(
			', ',
			// Names of type and status in the language of the site, as the admin shows them.
			array_map( static fn( array $place ): string => isset( $place['option'] ) ? (string) $place['option'] : $place['post'] . ' (' . ( get_post_type_object( (string) $place['post_type'] )->labels->singular_name ?? $place['post_type'] ) . ', ' . ( get_post_status_object( (string) $place['status'] )->label ?? $place['status'] ) . ')', $places )
		);
	}

	/**
	 * Whether a link points to this site: a root-relative path or the host of home_url(). Nothing is requested.
	 *
	 * @param string $url Link.
	 */
	public static function internal( string $url ): bool {
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return true;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& strtolower( (string) ( $parts['host'] ?? '' ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Path and query of an internal link without trailing slash, to compare links however they are written.
	 *
	 * @param string $url Internal link.
	 */
	private static function normal( string $url ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Current address of an internal link: '' when it leads to a post or is no post link (home, files, archives, admin),
	 * the permalink of the published post that had its last path segment as old slug (what core's
	 * wp_old_slug_redirect() follows), null when nothing resolves it.
	 *
	 * @param string $href Internal link.
	 */
	public static function resolve( string $href ): ?string {
		// The link on the address of home, port included, so url_to_postid() can strip home from it.
		$home     = (array) wp_parse_url( home_url() );
		$query    = (string) wp_parse_url( $href, PHP_URL_QUERY );
		$absolute = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . wp_parse_url( $href, PHP_URL_PATH ) . ( '' !== $query ? '?' . $query : '' );
		$path     = trim( (string) wp_parse_url( $absolute, PHP_URL_PATH ), '/' );
		$home_dir = trim( (string) ( $home['path'] ?? '' ), '/' );
		$below    = ltrim( substr( $path, strlen( $home_dir ) ), '/' );
		if ( $path === $home_dir || str_contains( wp_basename( $path ), '.' ) || str_starts_with( $below . '/', 'wp-admin/' ) || url_to_postid( $absolute ) || self::posts_page( $below ) || self::archive( $below ) ) {
			return '';
		}
		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => '_wp_old_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The lookup core's wp_old_slug_redirect() makes too.
				'meta_value'     => sanitize_title( wp_basename( $path ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);
		return $ids ? (string) get_permalink( (int) $ids[0] ) : null;
	}

	/**
	 * Whether a path below home is the posts page (page_for_posts) or one of its further pages. url_to_postid() gives 0
	 * for it, since its query is the blog and no single page, and its rewrite rule is the one of any page. With Polylang
	 * the posts page of each language counts, with or without the language in the path.
	 *
	 * @param string $path Path below home, without slashes around it.
	 */
	private static function posts_page( string $path ): bool {
		$page = (int) get_option( 'page_for_posts' );
		if ( ! $page ) {
			return false;
		}
		$path  = (string) preg_replace( '#/page/\d+$#', '', $path );
		$pages = function_exists( 'pll_get_post_translations' ) ? (array) pll_get_post_translations( $page ) : array();
		foreach ( array( '' => $page ) + $pages as $language => $id ) {
			$uri = (string) get_page_uri( (int) $id );
			if ( '' !== $uri && in_array( $path, array( $uri, $language . '/' . $uri ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the first rewrite rule that matches a path below home asks for no single post (a term, archive, feed,
	 * author or date), as url_to_postid() matches it; such a link leads somewhere without a post behind it.
	 *
	 * @param string $path Path below home, without slashes around it.
	 */
	private static function archive( string $path ): bool {
		global $wp_rewrite;
		$single = array( 'p', 'page_id', 'name', 'pagename', 'attachment', 'attachment_id', 'subpost', 'subpost_id' );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$single[] = is_string( $type->query_var ) ? $type->query_var : '';
		}
		foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $match => $query ) {
			if ( preg_match( "#^{$match}#", $path, $matches ) ) {
				parse_str( \WP_MatchesMapRegex::apply( (string) preg_replace( '!^.+\?!', '', $query ), $matches ), $vars );
				return ! array_intersect_key( $vars, array_flip( array_filter( $single ) ) );
			}
		}
		return false;
	}
}
