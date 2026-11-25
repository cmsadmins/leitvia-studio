<?php
/**
 * Targeted changes to one block in block markup.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Changes one block found by leitviaId or path ID: attributes from the block catalog and the level of a heading, the
 * text of paragraphs, headings, list items and buttons, the citation of a quote, the summary of a details block, the
 * link of a button or the one link in a text, a new link on a passage of a text, the caption of a figure, the text
 * attributes of static blocks, the captions file of a video and the image of an image or cover block. Static markup is
 * rebuilt from the new values with the HTML API on the block's own markup, never patched as a string (the HTML API cannot rename a
 * tag, so the level of a heading swaps the tag name of its one element); inner blocks and their placeholders in
 * innerContent stay as they are. What cannot be rebuilt safely is rejected with
 * leitvia_static_markup.
 */
final class Block_Edit {

	/**
	 * Blocks whose text can change, with the query for the element that holds the text. The icon of an icon list item
	 * renders on the server, so its text is the span of the saved markup.
	 */
	public const TEXT_BLOCKS = array(
		'core/paragraph'         => array(),
		'core/heading'           => array(),
		'core/list-item'         => array(),
		'core/button'            => array( 'class_name' => 'wp-block-button__link' ),
		'core/quote'             => array( 'tag_name' => 'cite' ),
		'core/details'           => array( 'tag_name' => 'summary' ),
		'leitvia/icon-list-item' => array( 'class_name' => 'wp-block-leitvia-icon-list-item__text' ),
	);

	/**
	 * Blocks with a caption in a figcaption at the end of their figure, with the classes core gives a new one.
	 */
	public const CAPTION_BLOCKS = array(
		'core/audio'   => 'wp-element-caption',
		'core/embed'   => 'wp-element-caption',
		'core/gallery' => 'blocks-gallery-caption wp-element-caption',
		'core/image'   => 'wp-element-caption',
		'core/table'   => 'wp-element-caption',
		'core/video'   => 'wp-element-caption',
	);

	/**
	 * Blocks whose image can change. The video of a core/video changes the same way, see video().
	 */
	public const IMAGE_BLOCKS = array( 'core/image', 'core/cover' );

	/**
	 * Attributes of core blocks outside the block catalog that may change, as JSON schema; their markup is rebuilt
	 * from the new value. The level of a heading is the tag name of its element, the tracks of a video are its track
	 * elements. A query keeps its markup: its settings live in the block comment, and query merges into what is set.
	 * The width of a site logo lives in its block comment only (the block renders on the server); its block style goes
	 * through className like that of every core block, also in the edits of a template part.
	 */
	public const CORE_ATTRIBUTES = array(
		'core/heading'   => array(
			'level' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 6,
			),
		),
		'core/query'     => array(
			'query'   => array(
				'type'                 => 'object',
				'properties'           => array(
					'perPage'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
					'pages'    => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'offset'   => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'postType' => array( 'type' => 'string' ),
					'order'    => array(
						'type' => 'string',
						'enum' => array( 'asc', 'desc' ),
					),
					'orderBy'  => array( 'type' => 'string' ),
					'sticky'   => array( 'type' => 'string' ),
					'inherit'  => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			'queryId' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		),
		'core/site-logo' => array(
			'width' => array(
				'type'    => 'integer',
				'minimum' => 20,
				'maximum' => 600,
			),
		),
		'core/video'     => array(
			'tracks' => array(
				'type'     => 'array',
				'maxItems' => 10,
				'items'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'kind'    => array(
							'type' => 'string',
							'enum' => array( 'captions', 'subtitles', 'descriptions', 'chapters' ),
						),
						'srclang' => array(
							'type'    => 'string',
							'pattern' => '^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$',
						),
						'label'   => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
						),
					),
					'required'             => array( 'id', 'srclang', 'label' ),
					'additionalProperties' => false,
				),
			),
		),
	);

	/**
	 * Core media blocks whose layout attributes may change, see media(): align and a block style (className) on all of
	 * them, size, crop, link and lightbox on an image as well. Video, audio and embed change their other attributes
	 * as before (tracks of a video, the catalog entry of an embed), see PLAYER_BLOCKS.
	 */
	public const MEDIA_BLOCKS = array( 'core/image', 'core/gallery', 'core/cover', 'core/media-text', 'core/video', 'core/audio', 'core/embed' );

	/**
	 * Media blocks of MEDIA_BLOCKS that take only align and className through media(); their other attributes go on.
	 */
	private const PLAYER_BLOCKS = array( 'core/video', 'core/audio', 'core/embed' );

	/**
	 * Attributes of Studio blocks that save as a custom property in percent in the style of their element, rebuilt from
	 * the new value: the position of a spotlight point on its image.
	 */
	public const POSITION_ATTRIBUTES = array(
		'leitvia/spotlight-hotspot' => array(
			'x' => '--x',
			'y' => '--y',
		),
	);

	/**
	 * Core blocks outside the catalog that insert builds from this markup, empty: a text block a pattern lacks, such as an
	 * eyebrow above a heading (text and the block style className in the same insert), a rule; '' for a block that renders
	 * on the server, such as the site logo, whose attributes live in its comment.
	 */
	public const CORE_INSERTS = array(
		'core/paragraph' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
		'core/heading'   => '<!-- wp:heading --><h2 class="wp-block-heading"></h2><!-- /wp:heading -->',
		'core/separator' => '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->',
		'core/site-logo' => '',
	);

	/**
	 * Most columns and rows of a table an edit writes.
	 */
	public const TABLE_MAX_COLUMNS = 8;
	public const TABLE_MAX_ROWS    = 50;

	/**
	 * Attributes of leitvia/comparison-table whose markup table() rebuilds as its save function does.
	 */
	private const COMPARISON_ATTRIBUTES = array( 'caption', 'columns', 'rows', 'highlightRow', 'source', 'sourceDate' );

	/**
	 * A valid HTML anchor: a letter, then letters, digits, - and _, so it works in a URL fragment and a CSS selector.
	 */
	public const ANCHOR = '/^[A-Za-z][A-Za-z0-9_-]{0,99}$/D';

	/**
	 * A valid date of a timeline entry, as save.js of leitvia/timeline-item checks it: year, year and month, or a date.
	 */
	private const TIMELINE_DATE = '/^\d{4}(-\d{2}(-\d{2})?)?$/D';

	/**
	 * Content with one block changed.
	 *
	 * @param string               $content Block markup.
	 * @param string               $id      leitviaId or path ID such as p:0.2.1.
	 * @param array<string, mixed> $change  attributes, text, url, add_link (text, url), caption and image (id, alt,
	 *                                      decorative); each optional.
	 * @param array<string, mixed> $target  Target for rejections, for example array( 'post' => 12 ).
	 * @return string|\WP_Error
	 */
	public static function apply( string $content, string $id, array $change, array $target = array() ) {
		$found  = false;
		$error  = null;
		$blocks = self::map(
			parse_blocks( $content ),
			$id,
			'p:',
			static function ( array $block, string $path ) use ( $change, $target, &$error ): array {
				$changed = self::change( $block, $change, $target + array( 'block' => '' !== Block_Ids::id( $block ) ? Block_Ids::id( $block ) : $path ) );
				if ( is_wp_error( $changed ) ) {
					$error = $changed;
					return $block;
				}
				return $changed;
			},
			$found
		);
		if ( ! $found ) {
			return Rejection::make( 'leitvia_not_found', __( 'There is no block with this ID. Read the outline for the current IDs.', 'leitvia-studio' ), 404, 'block', $target + array( 'block' => $id ), 'leitvia/get-page-outline' );
		}
		if ( $error ) {
			return $error;
		}
		$content = serialize_blocks( $blocks );
		$anchors = self::anchors( $change );
		return $anchors ? self::unique_anchors( $content, $anchors, $target + array( 'block' => $id ) ) : $content;
	}

	/**
	 * The content when each anchor names exactly one element in it, else the rejection for the first one that does not.
	 *
	 * @param string               $content Block markup.
	 * @param array<int, string>   $anchors HTML anchors set by edits.
	 * @param array<string, mixed> $target  Target for rejections.
	 * @return string|\WP_Error
	 */
	public static function unique_anchors( string $content, array $anchors, array $target = array() ) {
		$count = array_count_values( self::ids( parse_blocks( $content ) ) );
		foreach ( $anchors as $anchor ) {
			if ( 1 !== ( $count[ $anchor ] ?? 0 ) ) {
				/* translators: %s: HTML anchor. */
				return self::invalid( 'anchor', sprintf( __( 'The anchor %s is on the page already. Choose an anchor the page has once.', 'leitvia-studio' ), $anchor ), $target, 'leitvia/get-page-outline' );
			}
		}
		return $content;
	}

	/**
	 * HTML IDs of the blocks, repeats included: the id attributes of their saved markup and the attribute anchor of
	 * blocks without markup, which their render adds.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @return list<string>
	 */
	private static function ids( array $blocks ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			$own  = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
			$tags = new \WP_HTML_Tag_Processor( $own );
			while ( $tags->next_tag() ) {
				$id = $tags->get_attribute( 'id' );
				if ( is_string( $id ) && '' !== $id ) {
					$ids[] = $id;
				}
			}
			if ( '' === trim( $own ) && is_string( $block['attrs']['anchor'] ?? null ) ) {
				$ids[] = $block['attrs']['anchor'];
			}
			$ids = array_merge( $ids, self::ids( (array) $block['innerBlocks'] ) );
		}
		return $ids;
	}

	/**
	 * A block built from a structured description, never from markup: a block of the catalog from its example (the
	 * saved markup of a static block) or empty when it renders on the server, with new inner blocks in place of those
	 * of the example, an anchor and attributes, checked as in apply(). Inner blocks may also be other registered blocks
	 * that render on the server, such as the form block of a form plugin. Parent and allowed blocks count as in the
	 * editor. A static block without example is refused: it needs a pattern.
	 *
	 * @param array<string, mixed> $spec        block (name), attributes, anchor and inner (list of such descriptions).
	 * @param array<string, mixed> $target      Target for rejections.
	 * @param string               $parent_name Name of the parent block.
	 * @param bool                 $listed      Whether the block must be in the catalog, false for inner blocks.
	 * @return array<string, mixed>|\WP_Error Parsed block.
	 */
	public static function build( array $spec, array $target, string $parent_name, bool $listed = true ) {
		$name     = (string) ( $spec['block'] ?? '' );
		$target  += array( 'block' => $name );
		$registry = \WP_Block_Type_Registry::get_instance();
		$type     = $registry->get_registered( $name );
		$entry    = array_column( \LeitviaStudio\Blocks\Catalog::get(), null, 'name' )[ $name ] ?? ( '' !== ( self::CORE_INSERTS[ $name ] ?? '' ) ? array( 'example' => self::CORE_INSERTS[ $name ] ) : null );
		if ( ! $type || ( $listed && ! $entry && ! isset( self::CORE_INSERTS[ $name ] ) ) ) {
			return self::invalid( 'block', __( 'This block is not in the block catalog. Use a block of leitvia/list-blocks, or a pattern.', 'leitvia-studio' ), $target, 'leitvia/list-blocks' );
		}
		$allowed = $registry->get_registered( $parent_name )->allowed_blocks ?? null;
		if ( ( $type->parent && ! in_array( $parent_name, (array) $type->parent, true ) ) || ( is_array( $allowed ) && ! in_array( $name, $allowed, true ) ) ) {
			return self::invalid( 'block', __( 'This block cannot stand here: its parent or the allowed inner blocks of the block around it rule it out (parent and innerBlocks in leitvia/list-blocks).', 'leitvia-studio' ), $target, 'leitvia/list-blocks' );
		}
		$example = array_values( array_filter( parse_blocks( (string) ( $entry['example'] ?? '' ) ), static fn( array $block ): bool => $name === $block['blockName'] ) );
		if ( $example ) {
			$block = $example[0];
			// The caption of the example is English sample text: a new comparison table has none until the call names one.
			if ( 'leitvia/comparison-table' === $name && ! isset( $spec['caption'] ) && ! isset( $spec['table']['caption'] ) && ! isset( $spec['attributes']['caption'] ) ) {
				$spec['caption'] = '';
			}
		} elseif ( $type->is_dynamic() ) {
			$block = array(
				'blockName'    => $name,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		} else {
			return self::invalid( 'block', __( 'This block keeps its content in its markup and has no example to build it from. Use a pattern that holds it.', 'leitvia-studio' ), $target, 'leitvia/list-patterns' );
		}
		if ( isset( $spec['inner'] ) ) {
			$children = array();
			foreach ( (array) $spec['inner'] as $child ) {
				$child = self::build( (array) $child, $target, $name, false );
				if ( is_wp_error( $child ) ) {
					return $child;
				}
				$children[] = $child;
			}
			$content = (array) $block['innerContent'];
			$slots   = array_keys( $content, null, true );
			if ( ! $slots && '' !== trim( implode( '', $content ) ) ) {
				return self::invalid( 'inner', __( 'This block takes no inner blocks.', 'leitvia-studio' ), $target, 'leitvia/list-blocks' );
			}
			// The markup before the first and after the last inner block of the example stays, the inner blocks change.
			$block['innerContent'] = array_merge(
				$slots ? array_slice( $content, 0, $slots[0] ) : array(),
				array_fill( 0, count( $children ), null ),
				$slots ? array_slice( $content, (int) end( $slots ) + 1 ) : array()
			);
			$block['innerBlocks']  = $children;
		}
		$change = array_intersect_key(
			$spec,
			array(
				'anchor'    => true,
				'link_text' => true,
				'url'       => true,
				'table'     => true,
				'caption'   => true,
			)
		);
		$attrs  = (array) ( $spec['attributes'] ?? array() );
		if ( isset( $spec['text'] ) ) {
			if ( ! isset( self::TEXT_BLOCKS[ $name ] ) ) {
				return self::invalid( 'text', __( 'text goes with a paragraph, heading, list item or button only.', 'leitvia-studio' ), $target );
			}
			$change['text'] = (string) $spec['text'];
		}
		if ( ! $entry && $attrs ) {
			// A block outside the catalog renders on the server: its attributes live in the comment only.
			foreach ( $attrs as $key => $value ) {
				$schema = $type->attributes[ $key ] ?? null;
				if ( ! is_array( $schema ) || isset( $schema['source'] ) ) {
					/* translators: %s: attribute name. */
					return self::invalid( 'attributes', sprintf( __( 'The block has no attribute %s.', 'leitvia-studio' ), $key ), $target );
				}
				$valid = rest_validate_value_from_schema( $value, array_diff_key( $schema, array( 'default' => true ) ), (string) $key );
				if ( is_wp_error( $valid ) ) {
					return self::invalid( 'attributes', $valid->get_error_message(), $target );
				}
			}
			$block['attrs'] = $attrs + (array) $block['attrs'];
		} elseif ( $attrs ) {
			$change['attributes'] = $attrs;
		}
		$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
		return $change ? self::change( $block, $change, $target ) : $block;
	}

	/**
	 * Content with one block removed from its parent, repeated right after itself, with a new block from build() after
	 * or before it (insert, position), or moved after or before a sibling (move, position), for sections with fewer or
	 * more entries than the pattern, a block the pattern lacks or another order. IDs of the blocks after it shift, so
	 * edits in one list build on each other: work from the last block to the first. Copies lose their leitviaId; core
	 * gives them a new one on save.
	 *
	 * @param string               $content Block markup.
	 * @param string               $id      leitviaId or path ID.
	 * @param array<string, mixed> $change  remove (true), repeat (number of copies), insert (a block for build()) or
	 *                                      move (ID of a sibling), insert and move with position (after or before).
	 * @param array<string, mixed> $target  Target for rejections.
	 * @return string|\WP_Error
	 */
	public static function structure( string $content, string $id, array $change, array $target = array() ) {
		$blocks  = parse_blocks( $content );
		$path    = self::path( $blocks, $id, 'p:' );
		$target += array( 'block' => $id );
		if ( '' === $path ) {
			return Rejection::make( 'leitvia_not_found', __( 'There is no block with this ID. Read the outline for the current IDs.', 'leitvia-studio' ), 404, 'block', $target, 'leitvia/get-page-outline' );
		}
		$dot = strrpos( $path, '.' );
		if ( false === $dot ) {
			return self::invalid( 'structure', __( 'The outer block of a pattern stays. Leave a section out, or list it twice, in sections.', 'leitvia-studio' ), $target );
		}
		$index = (int) substr( $path, $dot + 1 );
		if ( isset( $change['move'] ) ) {
			$other = self::path( $blocks, (string) $change['move'], 'p:' );
			$odot  = strrpos( $other, '.' );
			if ( '' === $other || $other === $path || false === $odot || substr( $other, 0, $odot ) !== substr( $path, 0, $dot ) ) {
				return self::invalid( 'structure', __( 'A block moves only next to another block of the same parent. Read the outline for the IDs.', 'leitvia-studio' ), $target, 'leitvia/get-page-outline' );
			}
			$change['to'] = (int) substr( $other, $odot + 1 );
		}
		$found  = false;
		$error  = null;
		$blocks = self::map(
			$blocks,
			substr( $path, 0, $dot ),
			'p:',
			static function ( array $parent_block ) use ( $index, $change, $target, &$error ): array {
				$changed = self::children( $parent_block, $index, $change, $target );
				if ( is_wp_error( $changed ) ) {
					$error = $changed;
					return $parent_block;
				}
				return $changed;
			},
			$found
		);
		if ( $error ) {
			return $error;
		}
		$content = serialize_blocks( $blocks );
		$anchors = self::anchors( (array) ( $change['insert'] ?? array() ) );
		return $anchors ? self::unique_anchors( $content, $anchors, $target ) : $content;
	}

	/**
	 * The anchors a block edit or a block for build() sets, its inner blocks included; empty ones remove an anchor.
	 *
	 * @param array<string, mixed> $spec Edit or block with anchor (or attributes.anchor) and inner.
	 * @return array<int, string>
	 */
	public static function anchors( array $spec ): array {
		$anchor = $spec['anchor'] ?? $spec['attributes']['anchor'] ?? null;
		$found  = is_string( $anchor ) && '' !== $anchor ? array( $anchor ) : array();
		foreach ( (array) ( $spec['inner'] ?? array() ) as $child ) {
			$found = array_merge( $found, self::anchors( (array) $child ) );
		}
		return $found;
	}

	/**
	 * Path ID of the block with the ID, '' when there is none.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @param string       $id     leitviaId or path ID.
	 * @param string       $path   Path prefix.
	 */
	private static function path( array $blocks, string $id, string $path ): string {
		$index = 0;
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) ) {
				continue;
			}
			$here = $path . ( $index++ );
			if ( Block_Ids::id( $block ) === $id || $here === $id ) {
				return $here;
			}
			$inner = self::path( (array) $block['innerBlocks'], $id, $here . '.' );
			if ( '' !== $inner ) {
				return $inner;
			}
		}
		return '';
	}

	/**
	 * Parent block with one child removed or repeated, inner blocks and their placeholders in innerContent in step. A
	 * template lock, a lock against removing, the support multiple and the last child of a parent refuse, as in the
	 * editor; so does a copy that would double an HTML ID.
	 *
	 * @param array<string, mixed> $parent_block Parsed parent block.
	 * @param int                  $index        Index of the child.
	 * @param array<string, mixed> $change       remove or repeat.
	 * @param array<string, mixed> $target       Target with the block ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function children( array $parent_block, int $index, array $change, array $target ) {
		$child = (array) $parent_block['innerBlocks'][ $index ];
		if ( ! empty( $parent_block['attrs']['templateLock'] ) ) {
			return self::invalid( 'structure', __( 'The parent block is locked; its blocks cannot be removed or repeated.', 'leitvia-studio' ), $target );
		}
		$slots   = array_keys( (array) $parent_block['innerContent'], null, true );
		$at      = (int) $slots[ $index ];
		$content = (array) $parent_block['innerContent'];
		$prev    = $content[ $at - 1 ] ?? null;
		$next    = $content[ $at + 1 ] ?? null;
		// White space between two inner blocks, not the opening or closing markup of the parent.
		$gap    = static fn( $chunk, int $key ): bool => is_string( $chunk ) && '' === trim( $chunk ) && $key > 0 && $key < count( $content ) - 1;
		$before = 'before' === ( $change['position'] ?? 'after' );
		if ( isset( $change['to'] ) ) {
			if ( ! empty( $child['attrs']['lock']['move'] ) ) {
				return self::invalid( 'structure', __( 'This block is locked against moving.', 'leitvia-studio' ), $target );
			}
			// Every inner block has a null in innerContent, so the order of innerBlocks alone changes.
			$moved = array_splice( $parent_block['innerBlocks'], $index, 1 );
			$to    = (int) $change['to'] - ( $change['to'] > $index ? 1 : 0 );
			array_splice( $parent_block['innerBlocks'], $to + ( $before ? 0 : 1 ), 0, $moved );
		} elseif ( isset( $change['insert'] ) ) {
			$new = self::build( (array) $change['insert'], $target, (string) $parent_block['blockName'] );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$space = $gap( $next, $at + 1 ) ? $next : ( $gap( $prev, $at - 1 ) ? $prev : '' );
			if ( $before ) {
				array_splice( $content, $at, 0, '' !== $space ? array( null, $space ) : array( null ) );
				array_splice( $parent_block['innerBlocks'], $index, 0, array( $new ) );
			} else {
				array_splice( $content, $at + 1, 0, '' !== $space ? array( $space, null ) : array( null ) );
				array_splice( $parent_block['innerBlocks'], $index + 1, 0, array( $new ) );
			}
		} elseif ( empty( $change['remove'] ) ) {
			$type = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $child['blockName'] );
			if ( false === ( $type->supports['multiple'] ?? true ) ) {
				return self::invalid( 'structure', __( 'This block may appear only once.', 'leitvia-studio' ), $target );
			}
			if ( self::has_html_id( $child ) ) {
				return self::invalid( 'structure', __( 'This block has an HTML anchor, and a copy would double it. Choose a pattern with enough blocks.', 'leitvia-studio' ), $target );
			}
			$copies = (int) $change['repeat'];
			$space  = $gap( $next, $at + 1 ) ? $next : ( $gap( $prev, $at - 1 ) ? $prev : '' );
			array_splice( $content, $at + 1, 0, array_merge( ...array_fill( 0, $copies, '' !== $space ? array( $space, null ) : array( null ) ) ) );
			array_splice( $parent_block['innerBlocks'], $index + 1, 0, array_fill( 0, $copies, self::copy( $child ) ) );
		} else {
			if ( ! empty( $child['attrs']['lock']['remove'] ) ) {
				return self::invalid( 'structure', __( 'This block is locked against removing.', 'leitvia-studio' ), $target );
			}
			if ( 1 === count( $slots ) ) {
				return self::invalid( 'structure', __( 'This is the only block of its parent. Remove the parent block instead.', 'leitvia-studio' ), $target );
			}
			array_splice( $parent_block['innerBlocks'], $index, 1 );
			if ( $gap( $next, $at + 1 ) ) {
				array_splice( $content, $at, 2 );
			} elseif ( $gap( $prev, $at - 1 ) ) {
				array_splice( $content, $at - 1, 2 );
			} elseif ( is_string( $prev ) && is_string( $next ) ) {
				array_splice( $content, $at - 1, 3, array( $prev . $next ) );
			} else {
				array_splice( $content, $at, 1 );
			}
		}
		$parent_block['innerContent'] = $content;
		return $parent_block;
	}

	/**
	 * Whether a block or one of its inner blocks has an HTML ID.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private static function has_html_id( array $block ): bool {
		$tags = new \WP_HTML_Tag_Processor( implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) ) );
		while ( $tags->next_tag() ) {
			if ( null !== $tags->get_attribute( 'id' ) ) {
				return true;
			}
		}
		return ! empty( $block['attrs']['anchor'] ) || array_filter( (array) $block['innerBlocks'], array( self::class, 'has_html_id' ) );
	}

	/**
	 * Copy of a block without the leitviaId of the block and of its inner blocks.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	private static function copy( array $block ): array {
		unset( $block['attrs']['metadata']['leitviaId'] );
		if ( array() === ( $block['attrs']['metadata'] ?? null ) ) {
			unset( $block['attrs']['metadata'] );
		}
		$block['innerBlocks'] = array_map( array( self::class, 'copy' ), (array) $block['innerBlocks'] );
		return $block;
	}

	/**
	 * Replaces the block with the ID by the result of the callback; paths count without whitespace blocks, as in
	 * Block_Ids::blocks(). Inner block lists keep their length, so the placeholders in innerContent stay in sync.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @param string       $id     leitviaId or path ID.
	 * @param string       $path   Path prefix.
	 * @param callable     $edit   Gets the block and its path ID, returns the new block.
	 * @param bool         $found  Set to true once the block is found.
	 * @return array<mixed>
	 */
	private static function map( array $blocks, string $id, string $path, callable $edit, bool &$found ): array {
		$index = 0;
		foreach ( $blocks as $key => $block ) {
			if ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) ) {
				continue;
			}
			$here = $path . ( $index++ );
			if ( Block_Ids::id( $block ) === $id || $here === $id ) {
				$found          = true;
				$blocks[ $key ] = $edit( $block, $here );
				break;
			}
			$blocks[ $key ]['innerBlocks'] = self::map( (array) $block['innerBlocks'], $id, $here . '.', $edit, $found );
			if ( $found ) {
				break;
			}
		}
		return $blocks;
	}

	/**
	 * One block with the change applied.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param array<string, mixed> $change Change.
	 * @param array<string, mixed> $target Target with the block ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function change( array $block, array $change, array $target ) {
		$name  = (string) $block['blockName'];
		$bound = self::bound( $block, $change, $target );
		if ( $bound ) {
			return $bound;
		}
		$rebuild = Rejection::make( 'leitvia_static_markup', __( 'This change needs markup that cannot be rebuilt safely. Insert the pattern again with leitvia/compose-page, or change a block that supports it.', 'leitvia-studio' ), 422, 'static_markup', $target, 'leitvia/compose-page' );
		// The anchor is a support of the block, no catalog attribute; it changes the same way from either key.
		if ( is_array( $change['attributes'] ?? null ) && array_key_exists( 'anchor', $change['attributes'] ) ) {
			$change['anchor'] = $change['attributes']['anchor'];
			unset( $change['attributes']['anchor'] );
			if ( ! $change['attributes'] ) {
				unset( $change['attributes'] );
			}
		}
		if ( array_key_exists( 'anchor', $change ) ) {
			$block = self::anchor( $block, $change['anchor'], $target, $rebuild );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		// The video comes first: tracks and caption of the same call need its element.
		if ( isset( $change['video'] ) ) {
			$block = 'core/video' === $name ? self::video( $block, (array) $change['video'], $target, $rebuild ) : $rebuild;
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		if ( isset( $change['columns'] ) ) {
			$layout = (array) ( $block['attrs']['layout'] ?? array() );
			if ( 'core/gallery' !== $name && 'grid' !== ( $layout['type'] ?? '' ) ) {
				return self::invalid( 'columns', __( 'Only a grid or a gallery takes a number of columns: a group or post template with the layout grid, or a gallery block. A columns block has one column block per column; take one out with remove or add one with repeat.', 'leitvia-studio' ), $target );
			}
			$columns = (int) $change['columns'];
			if ( $columns < 1 || $columns > 6 ) {
				return self::invalid( 'columns', __( 'A grid has 1 to 6 columns.', 'leitvia-studio' ), $target );
			}
			if ( 'core/gallery' !== $name ) {
				// Core renders the grid from the layout in the comment; the saved markup holds none of it.
				$block['attrs']['layout'] = array(
					'type'        => 'grid',
					'columnCount' => $columns,
				) + $layout;
			}
		}
		if ( isset( $change['columns'] ) && 'core/gallery' === $name ) {
			// A gallery saves the count as attribute and as class columns-<n> of its figure (columns-default without).
			$tags = new \WP_HTML_Tag_Processor( (string) ( $block['innerContent'][0] ?? '' ) );
			if ( ! $tags->next_tag() ) {
				return $rebuild;
			}
			foreach ( iterator_to_array( $tags->class_list(), false ) as $class ) {
				if ( str_starts_with( (string) $class, 'columns-' ) ) {
					$tags->remove_class( (string) $class );
				}
			}
			$tags->add_class( 'columns-' . (int) $change['columns'] );
			$block['innerContent'][0] = $tags->get_updated_html();
			$block['attrs']           = self::place( $name, (array) $block['attrs'], 'columns', (int) $change['columns'] );
		}
		// The text of a map is its heading.
		if ( 'leitvia/map' === $name && isset( $change['text'] ) ) {
			$change['attributes'] = array( 'heading' => (string) $change['text'] ) + (array) ( $change['attributes'] ?? array() );
			unset( $change['text'] );
		}
		// The saved link of a button whose url a binding fills goes with url "" or attributes url null; the binding stays.
		if ( 'core/button' === $name && '' !== self::binding( $block, 'url' ) && is_array( $change['attributes'] ?? null ) && array_key_exists( 'url', $change['attributes'] ) && in_array( $change['attributes']['url'], array( null, '' ), true ) ) {
			$change['url'] = '';
			unset( $change['attributes']['url'] );
		}
		if ( 'leitvia/comparison-table' === $name && ( isset( $change['table'] ) || array_intersect_key( (array) ( $change['attributes'] ?? array() ), array_flip( self::COMPARISON_ATTRIBUTES ) ) || isset( $change['caption'] ) ) ) {
			$own = array_intersect_key( (array) ( $change['attributes'] ?? array() ), array_flip( self::COMPARISON_ATTRIBUTES ) );
			if ( isset( $change['table'] ) ) {
				$own = array(
					'columns' => array_values( (array) ( $change['table']['head'] ?? array() ) ),
					'rows'    => array_values( (array) ( $change['table']['rows'] ?? array() ) ),
				) + ( isset( $change['table']['caption'] ) ? array( 'caption' => (string) $change['table']['caption'] ) : array() ) + $own;
			}
			if ( isset( $change['caption'] ) ) {
				$own['caption'] = (string) $change['caption'];
			}
			$block = self::comparison( $block, $own, $target, $rebuild );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
			$change['attributes'] = array_diff_key( (array) ( $change['attributes'] ?? array() ), $own );
			unset( $change['caption'] );
		} elseif ( isset( $change['table'] ) ) {
			$block = 'core/table' === $name ? self::table( $block, (array) $change['table'], $target, $rebuild ) : self::invalid( 'table', __( 'table goes with a core/table or a leitvia/comparison-table only.', 'leitvia-studio' ), $target, 'leitvia/list-blocks' );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		if ( ! empty( $change['attributes'] ) ) {
			$block = self::attributes( $block, (array) $change['attributes'], $target, $rebuild );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		$link = isset( $change['url'] ) && 'core/button' !== $name;
		if ( isset( $change['text'] ) || $link ) {
			// The link of a button is the text element itself; it follows below.
			$block = self::text( $block, $link ? $change : array_diff_key( $change, array( 'url' => true ) ), $target, $rebuild );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		if ( isset( $change['add_link'] ) ) {
			$block = self::add_link( $block, (array) $change['add_link'], $target, $rebuild );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		if ( isset( $change['caption'] ) ) {
			$block = self::caption( $block, (string) $change['caption'], $rebuild );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		$html = is_string( $block['innerContent'][0] ?? null ) ? $block['innerContent'][0] : '';
		if ( isset( $change['url'] ) && ! $link ) {
			$url  = self::url( (string) $change['url'] );
			$tags = self::processor( $html );
			if ( ! $tags->next_tag( self::TEXT_BLOCKS[ $name ] ) || 'A' !== $tags->get_tag() ) {
				return $rebuild;
			}
			if ( '' === trim( (string) $change['url'] ) && '' !== self::binding( $block, 'url' ) ) {
				$tags->remove_attribute( 'href' );
			} elseif ( '' === $url ) {
				return self::invalid( 'url', __( 'The link is not a valid URL.', 'leitvia-studio' ), $target );
			} else {
				$tags->set_attribute( 'href', $url );
			}
			$html = $tags->get_updated_html();
		}
		if ( isset( $change['image'] ) ) {
			if ( ! in_array( $name, self::IMAGE_BLOCKS, true ) ) {
				return $rebuild;
			}
			$image = self::image( $block, $html, (array) $change['image'], $target, $rebuild );
			if ( is_wp_error( $image ) ) {
				return $image;
			}
			[ $block, $html ] = $image;
		}
		if ( '' !== $html ) {
			$block['innerContent'][0] = $html;
		}
		$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
		return $block;
	}

	/**
	 * Rejection for a change of an attribute a binding fills: visitors see the bound value, not the edit. Pattern
	 * overrides stay editable, since a copy shows its own text.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param array<string, mixed> $change Change.
	 * @param array<string, mixed> $target Target.
	 */
	private static function bound( array $block, array $change, array $target ): ?\WP_Error {
		$bindings = $block['attrs']['metadata']['bindings'] ?? null;
		if ( ! is_array( $bindings ) ) {
			return null;
		}
		// Removing the saved value of a bound attribute hides nothing: visitors see the bound value either way.
		$change['attributes'] = array_filter( (array) ( $change['attributes'] ?? array() ), static fn( $value ): bool => null !== $value && '' !== $value );
		if ( '' === ( $change['url'] ?? null ) ) {
			unset( $change['url'] );
		}
		$text     = array(
			'core/button'  => 'text',
			'core/quote'   => 'citation',
			'core/details' => 'summary',
		)[ (string) $block['blockName'] ] ?? 'content';
		$touched  = array_keys( $change['attributes'] );
		$touched += isset( $change['text'] ) ? array( 'text' => $text ) : array();
		$touched += isset( $change['url'] ) && 'core/button' === $block['blockName'] ? array( 'url' => 'url' ) : array();
		$touched += isset( $change['caption'] ) ? array( 'caption' => 'caption' ) : array();
		$touched  = array_merge( array_values( $touched ), isset( $change['image'] ) ? array( 'id', 'url', 'alt' ) : array(), isset( $change['video'] ) ? array( 'id', 'src', 'poster' ) : array() );
		foreach ( $touched as $attribute ) {
			$binding = $bindings[ $attribute ] ?? null;
			$source  = is_array( $binding ) ? (string) ( $binding['source'] ?? '' ) : '';
			if ( '' === $source || 'core/pattern-overrides' === $source ) {
				continue;
			}
			$args = (array) ( $binding['args'] ?? array() );
			$key  = (string) ( $args['key'] ?? $args['relation'] ?? '' );
			return self::invalid(
				'bound',
				'' === $key
					/* translators: 1: attribute, for example url; 2: binding source, for example leitvia/relation */
					? sprintf( __( 'The attribute %1$s of this block takes its value from %2$s, so visitors would not see the change. Change the value there; an empty value removes only what the block saved.', 'leitvia-studio' ), $attribute, $source )
					/* translators: 1: attribute, for example content; 2: binding source, for example leitvia/meta; 3: field key */
					: sprintf( __( 'The attribute %1$s of this block shows "%3$s" from %2$s, so visitors would not see the change. Change the value there.', 'leitvia-studio' ), $attribute, $source, $key ),
				$target,
				'leitvia/setting' === $source ? 'leitvia/update-site-values' : ( str_starts_with( $source, 'leitvia/' ) || 'core/post-meta' === $source ? 'leitvia/update-content' : '' )
			);
		}
		return null;
	}

	/**
	 * Source of the binding that fills an attribute of the block, '' for none. Pattern overrides count as none: a copy
	 * shows its own value.
	 *
	 * @param array<string, mixed> $block     Parsed block.
	 * @param string               $attribute Attribute, for example url.
	 */
	public static function binding( array $block, string $attribute ): string {
		$source = $block['attrs']['metadata']['bindings'][ $attribute ]['source'] ?? '';
		return is_string( $source ) && 'core/pattern-overrides' !== $source ? $source : '';
	}

	/**
	 * Block with new text in its element of TEXT_BLOCKS, found in any part of its own markup (the citation of a quote
	 * follows its inner blocks). One link in the element stays: around link_text, else around its old text when the
	 * new text still contains it; url changes where it points. Text that would drop the link is refused.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param array<string, mixed> $change  text, link_text and url.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function text( array $block, array $change, array $target, \WP_Error $rebuild ) {
		$name = (string) $block['blockName'];
		if ( ! isset( self::TEXT_BLOCKS[ $name ] ) ) {
			// Mostly an ID that moved: after a remove the blocks behind it move up one place (edits apply in order).
			/* translators: %s: block name, such as core/buttons. */
			return self::invalid( 'text', sprintf( __( 'The block %s has no text or link of its own to change. Edits apply in order: after a remove the blocks behind it move up one place, so a path ID such as p:0.0.1 then names the next block. Count from the new order, or edit the later blocks first.', 'leitvia-studio' ), $name ), $target );
		}
		$found = self::element( $block, self::TEXT_BLOCKS[ $name ] );
		if ( ! $found ) {
			return $rebuild;
		}
		[ $key, $start, $length ] = $found;
		$html                     = (string) $block['innerContent'][ $key ];
		$inner                    = self::inner( substr( $html, $start, $length ), $change, $target, $rebuild );
		if ( is_wp_error( $inner ) ) {
			return $inner;
		}
		$block['innerContent'][ $key ] = substr_replace( $html, $inner, $start, $length );
		return $block;
	}

	/**
	 * Where the content of the first element of a query lies in the block's own markup.
	 *
	 * @param array<string, mixed>      $block Parsed block.
	 * @param array<string, mixed>|null $query Query of the tag processor.
	 * @return array{0: int|string, 1: int, 2: int}|null Key in innerContent, byte offset and length of the content.
	 */
	private static function element( array $block, ?array $query ): ?array {
		foreach ( (array) $block['innerContent'] as $key => $html ) {
			$tags  = is_string( $html ) && null !== $query ? self::processor( $html ) : null;
			$range = $tags && $tags->next_tag( $query ) ? $tags->inner_range() : null;
			if ( $range ) {
				return array( $key, $range[0], $range[1] );
			}
		}
		return null;
	}

	/**
	 * Block with a new link around a passage of its text: other links and inline formatting stay. The first place
	 * outside a link counts, and the passage must lie in one run of text.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param array<string, mixed> $link    text and url.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function add_link( array $block, array $link, array $target, \WP_Error $rebuild ) {
		$found = 'core/button' === $block['blockName'] ? null : self::element( $block, self::TEXT_BLOCKS[ (string) $block['blockName'] ] ?? null );
		if ( ! $found ) {
			return $rebuild;
		}
		$url = self::url( (string) ( $link['url'] ?? '' ) );
		if ( '' === $url ) {
			return self::invalid( 'url', __( 'The link is not a valid URL.', 'leitvia-studio' ), $target );
		}
		[ $key, $start, $length ] = $found;
		$html                     = (string) $block['innerContent'][ $key ];
		$text                     = (string) ( $link['text'] ?? '' );
		// Saved text keeps quotes as they are, text this class wrote has them as entities.
		$forms = array_unique( array( htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' ), esc_html( $text ) ) );
		$parts = (array) preg_split( '/(<[^>]*>)/', substr( $html, $start, $length ), -1, PREG_SPLIT_DELIM_CAPTURE );
		$open  = false;
		foreach ( $parts as $index => $part ) {
			$part = (string) $part;
			if ( 1 === $index % 2 ) {
				$open = preg_match( '#^<a[\s>]#i', $part ) ? true : ( preg_match( '#^</a\s*>#i', $part ) ? false : $open );
				continue;
			}
			foreach ( $open ? array() : $forms as $form ) {
				$at = strpos( $part, $form );
				if ( false !== $at ) {
					$parts[ $index ]               = substr_replace( $part, '<a href="' . esc_url( $url ) . '">' . $form . '</a>', $at, strlen( $form ) );
					$block['innerContent'][ $key ] = substr_replace( $html, implode( '', $parts ), $start, $length );
					return $block;
				}
			}
		}
		return self::invalid( 'add_link', __( 'The block has no such passage outside a link. Send the exact words of one run of text, without markup.', 'leitvia-studio' ), $target, 'leitvia/get-block' );
	}

	/**
	 * Checks the cells of a table edit: head and every row of the same length, within the limits.
	 *
	 * @param array<mixed>         $head   Column headers.
	 * @param array<mixed>         $rows   Rows.
	 * @param array<string, mixed> $target Target.
	 */
	private static function cells( array $head, array $rows, array $target ): ?\WP_Error {
		$width = count( $head );
		if ( $width < 1 || $width > self::TABLE_MAX_COLUMNS || ! $rows || count( $rows ) > self::TABLE_MAX_ROWS ) {
			/* translators: 1: most columns, 2: most rows. */
			return self::invalid( 'table', sprintf( __( 'A table has 1 to %1$d columns in head and 1 to %2$d rows.', 'leitvia-studio' ), self::TABLE_MAX_COLUMNS, self::TABLE_MAX_ROWS ), $target );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== $width || array_filter( $row, static fn( $cell ): bool => ! is_scalar( $cell ) ) ) {
				return self::invalid( 'table', __( 'Every row has as many cells as head, each as plain text; an empty cell is "".', 'leitvia-studio' ), $target );
			}
		}
		return null;
	}

	/**
	 * A core/table with new cells: thead with column headers (scope col), tbody whose first cell is a row header
	 * (scope row, unless row_headers is false) and an optional tfoot, as plain text, in the markup core saves. The
	 * figure, the table element with its classes and the caption stay.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param array<string, mixed> $table   head, rows, foot and row_headers.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function table( array $block, array $table, array $target, \WP_Error $rebuild ) {
		$head  = array_values( (array) ( $table['head'] ?? array() ) );
		$rows  = array_values( (array) ( $table['rows'] ?? array() ) );
		$foot  = array_values( (array) ( $table['foot'] ?? array() ) );
		$error = self::cells( $head, $rows, $target ) ?? ( $foot ? self::cells( $head, $foot, $target ) : null );
		if ( $error ) {
			return $error;
		}
		$html  = (string) ( $block['innerContent'][0] ?? '' );
		$start = preg_match( '#<table\b[^>]*>#', $html, $open, PREG_OFFSET_CAPTURE ) ? (int) $open[0][1] + strlen( $open[0][0] ) : -1;
		$end   = strrpos( $html, '</table>' );
		if ( $start < 0 || false === $end || $end < $start || count( $block['innerContent'] ) > 1 ) {
			return $rebuild;
		}
		$headers = false !== ( $table['row_headers'] ?? true );
		$row     = static function ( array $cells, bool $header ) use ( $headers ): string {
			$out = '<tr>';
			foreach ( array_values( $cells ) as $index => $cell ) {
				$cell = esc_html( (string) $cell );
				$out .= $header ? '<th scope="col">' . $cell . '</th>' : ( 0 === $index && $headers ? '<th scope="row">' . $cell . '</th>' : '<td>' . $cell . '</td>' );
			}
			return $out . '</tr>';
		};
		$body    = '<thead>' . $row( $head, true ) . '</thead><tbody>';
		foreach ( $rows as $cells ) {
			$body .= $row( (array) $cells, false );
		}
		$body .= '</tbody>';
		if ( $foot ) {
			$body .= '<tfoot>';
			foreach ( $foot as $cells ) {
				$body .= $row( (array) $cells, false );
			}
			$body .= '</tfoot>';
		}
		$block['innerContent'][0] = substr_replace( $html, $body, $start, $end - $start );
		return $block;
	}

	/**
	 * A leitvia/comparison-table with new attributes and its markup rebuilt as save.js builds it: caption, column
	 * headers with scope col, the first cell of a row as row header with scope row, every cell with its column name as
	 * label for the card view, the highlighted row and the source line. The figure keeps its own attributes; role and
	 * aria-label follow the caption. A computed column needs the editor.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param array<string, mixed> $attrs   New values of COMPARISON_ATTRIBUTES.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function comparison( array $block, array $attrs, array $target, \WP_Error $rebuild ) {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'leitvia/comparison-table' );
		foreach ( $attrs as $key => $value ) {
			$schema = array_diff_key( (array) ( $type->attributes[ $key ] ?? array() ), array( 'default' => true ) );
			$valid  = rest_validate_value_from_schema( $value, $schema, (string) $key );
			if ( is_wp_error( $valid ) ) {
				return self::invalid( 'attributes', $valid->get_error_message(), $target );
			}
		}
		$all = $attrs + (array) $block['attrs'] + array(
			'caption'      => '',
			'columns'      => array( '', '', '' ),
			'rows'         => array( array( '', '', '' ), array( '', '', '' ) ),
			'highlightRow' => -1,
			'source'       => '',
			'sourceDate'   => '',
		);
		if ( ! empty( $all['computed']['label'] ) ) {
			return $rebuild;
		}
		$head  = array_map( 'strval', array_values( (array) $all['columns'] ) );
		$error = self::cells( $head, array_values( (array) $all['rows'] ), $target );
		if ( $error ) {
			return $error;
		}
		$html = (string) ( $block['innerContent'][0] ?? '' );
		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( count( (array) $block['innerContent'] ) > 1 || ! $tags->next_tag( array( 'tag_name' => 'figure' ) ) ) {
			return $rebuild;
		}
		$caption = (string) $all['caption'];
		if ( '' === $caption ) {
			$tags->remove_attribute( 'role' );
			$tags->remove_attribute( 'aria-label' );
		} else {
			$tags->set_attribute( 'role', 'region' );
			$tags->set_attribute( 'aria-label', $caption );
		}
		$figure = (string) preg_replace( '#^(\s*<[^>]*?)\s+(/?>)#', '$1$2', substr( $tags->get_updated_html(), 0, (int) strpos( $tags->get_updated_html(), '>' ) + 1 ), 1 );
		$out    = $figure . '<table>' . ( '' !== $caption ? '<caption>' . esc_html( $caption ) . '</caption>' : '' ) . '<thead><tr>';
		foreach ( $head as $text ) {
			$out .= '<th scope="col">' . esc_html( $text ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( array_values( (array) $all['rows'] ) as $r => $cells ) {
			$out .= (int) $all['highlightRow'] === $r ? '<tr class="is-highlighted">' : '<tr>';
			foreach ( array_values( (array) $cells ) as $c => $text ) {
				$label = '' !== $head[ $c ] ? ' data-label="' . esc_attr( $head[ $c ] ) . '"' : '';
				$span  = '' !== $head[ $c ] ? '<span class="wp-block-leitvia-comparison-table__label">' . esc_html( $head[ $c ] ) . ': </span>' : '';
				$out  .= 0 === $c ? '<th scope="row"' . $label . '>' : '<td' . $label . '>';
				$out  .= $span . esc_html( (string) $text ) . ( 0 === $c ? '</th>' : '</td>' );
			}
			$out .= '</tr>';
		}
		$out   .= '</tbody></table>';
		$source = (string) $all['source'];
		if ( '' !== $source ) {
			// Without labels of its own the source line takes those of the language of the site, as the pattern does.
			$labels = (array) ( $all['labels'] ?? array() );
			$date   = (string) $all['sourceDate'];
			$out   .= '<figcaption class="wp-block-leitvia-comparison-table__source">' . esc_html( (string) ( $labels['source'] ?? __( 'Source:', 'leitvia-studio' ) ) . ' ' . $source );
			$out   .= preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ? esc_html( ', ' . (string) ( $labels['asOf'] ?? __( 'as of', 'leitvia-studio' ) ) . ' ' ) . '<time datetime="' . esc_attr( $date ) . '">' . esc_html( $date ) . '</time>' : '';
			$out   .= '</figcaption>';
		}
		$block['innerContent'][0] = $out . '</figure>';
		foreach ( $attrs as $key => $value ) {
			$block['attrs'] = self::place( 'leitvia/comparison-table', (array) $block['attrs'], $key, $value );
		}
		return $block;
	}

	/**
	 * Block with a new caption, as plain text; an empty one removes the figcaption, as the editor saves it.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param string               $caption Caption.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function caption( array $block, string $caption, \WP_Error $rebuild ) {
		$classes = self::CAPTION_BLOCKS[ (string) $block['blockName'] ] ?? '';
		$key     = array_key_last( (array) $block['innerContent'] );
		$html    = $block['innerContent'][ $key ] ?? null;
		$end     = is_string( $html ) ? strrpos( $html, '</figure>' ) : false;
		if ( '' === $classes || false === $end ) {
			return $rebuild;
		}
		$text  = esc_html( trim( $caption ) );
		$tags  = self::processor( (string) $html );
		$range = $tags->next_tag( 'figcaption' ) ? $tags->inner_range() : null;
		if ( $range ) {
			$block['innerContent'][ $key ] = '' !== $text
				? substr_replace( (string) $html, $text, $range[0], $range[1] )
				: substr_replace( (string) $html, '', $range[2], $range[0] + $range[1] + strlen( '</figcaption>' ) - $range[2] );
		} elseif ( '' !== $text ) {
			$block['innerContent'][ $key ] = substr_replace( (string) $html, '<figcaption class="' . $classes . '">' . $text . '</figcaption>', $end, 0 );
		}
		return $block;
	}

	/**
	 * Block with a new HTML anchor, '' or null removes it: the id of the first element of its saved markup, as the
	 * editor saves it, or the attribute anchor of a block without markup, which its render adds. Only blocks with the
	 * support anchor take one; ids the template gives every page are refused. Whether the page has it once is checked
	 * on the whole content (unique_anchors()).
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param mixed                $anchor  New anchor.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function anchor( array $block, $anchor, array $target, \WP_Error $rebuild ) {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $block['blockName'] );
		if ( ! $type || empty( $type->supports['anchor'] ) ) {
			return self::invalid( 'anchor', __( 'This block takes no HTML anchor. Set it on a block around it, such as the group of the section.', 'leitvia-studio' ), $target, 'leitvia/list-blocks' );
		}
		$anchor = is_string( $anchor ) ? $anchor : '';
		if ( '' !== $anchor && ( ! preg_match( self::ANCHOR, $anchor ) || in_array( $anchor, Content_Checks::PAGE_IDS, true ) ) ) {
			return self::invalid( 'anchor', __( 'An anchor starts with a letter and holds only letters, digits, - and _, such as contact-form; top, main, content, comments and respond belong to the template.', 'leitvia-studio' ), $target );
		}
		unset( $block['attrs']['anchor'] );
		if ( '' === trim( implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) ) ) ) {
			$block['attrs'] += '' !== $anchor ? array( 'anchor' => $anchor ) : array();
			return $block;
		}
		$tags = new \WP_HTML_Tag_Processor( (string) ( $block['innerContent'][0] ?? '' ) );
		if ( ! $tags->next_tag() ) {
			return $rebuild;
		}
		if ( '' === $anchor ) {
			$tags->remove_attribute( 'id' );
		} else {
			$tags->set_attribute( 'id', $anchor );
		}
		// remove_attribute() leaves the space before it (<div class="a" >), which KSES would close up.
		$block['innerContent'][0] = (string) preg_replace( '#^(\s*<[^>]*?)\s+(/?>)#', '$1$2', $tags->get_updated_html(), 1 );
		return $block;
	}

	/**
	 * Timeline entry with a new date, as its save function builds it: a time element with datetime for a valid date,
	 * a span for a free label (an empty date). The label inside stays.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param string               $date    YYYY, YYYY-MM, YYYY-MM-DD or ''.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function timeline_date( array $block, string $date, \WP_Error $rebuild ) {
		$class = 'wp-block-leitvia-timeline-item__date';
		$html  = (string) ( $block['innerContent'][0] ?? '' );
		$tags  = self::processor( $html );
		$range = $tags->next_tag( array( 'class_name' => $class ) ) ? $tags->inner_range() : null;
		$end   = $range ? strpos( $html, '>', $range[0] + $range[1] ) : false;
		if ( false === $end ) {
			return $rebuild;
		}
		$tag  = preg_match( self::TIMELINE_DATE, $date ) ? 'time' : 'span';
		$open = '<' . $tag . ' class="' . $class . '"' . ( 'time' === $tag ? ' datetime="' . esc_attr( $date ) . '"' : '' ) . '>';

		$block['innerContent'][0] = substr_replace( $html, $open . substr( $html, $range[0], $range[1] ) . '</' . $tag . '>', $range[2], $end + 1 - $range[2] );
		return $block;
	}

	/**
	 * New content of a text element: the escaped text, with its one link around the linked part; the opening tag of the
	 * link stays as it was, only href changes with url. Plain text may replace a sample link without an address or to "#".
	 *
	 * @param string               $inner   Old content of the element.
	 * @param array<string, mixed> $change  text, link_text and url.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return string|\WP_Error
	 */
	private static function inner( string $inner, array $change, array $target, \WP_Error $rebuild ) {
		$plain = static fn( string $html ): string => html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$tags  = self::processor( $inner );
		$links = 0;
		$open  = '';
		$old   = '';
		while ( $tags->next_tag( 'a' ) ) {
			$range = 1 === ++$links ? $tags->inner_range() : null;
			if ( $range ) {
				$open = substr( $inner, $range[2], $range[0] - $range[2] );
				$old  = $plain( substr( $inner, $range[0], $range[1] ) );
			}
		}
		$text = isset( $change['text'] ) ? (string) $change['text'] : $plain( $inner );
		if ( 0 === $links ) {
			if ( ! isset( $change['url'] ) ) {
				return esc_html( $text );
			}
			if ( ! isset( $change['link_text'] ) ) {
				return $rebuild;
			}
			// Text with a link it did not have yet: link_text names the linked part, as add_link does for a passage.
			$label = (string) $change['link_text'];
			$at    = strpos( $text, $label );
			$url   = self::url( (string) $change['url'] );
			if ( false === $at ) {
				return self::invalid( 'link_text', __( 'link_text is the part of text that becomes the link; text does not contain it.', 'leitvia-studio' ), $target );
			}
			if ( '' === $url ) {
				return self::invalid( 'url', __( 'The link is not a valid URL.', 'leitvia-studio' ), $target );
			}
			return esc_html( substr( $text, 0, $at ) ) . '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' . esc_html( substr( $text, $at + strlen( $label ) ) );
		}
		if ( 1 !== $links || '' === $open ) {
			return $rebuild;
		}
		$label = (string) ( $change['link_text'] ?? $old );
		$at    = '' !== $label ? strpos( $text, $label ) : false;
		$href  = new \WP_HTML_Tag_Processor( $open );
		$href->next_tag();
		// A sample link without an address (or to "#") leads nowhere; plain text may replace it.
		if ( false === $at && ! isset( $change['link_text'] ) && ! isset( $change['url'] ) && in_array( trim( (string) $href->get_attribute( 'href' ) ), array( '', '#' ), true ) ) {
			return esc_html( $text );
		}
		if ( false === $at ) {
			return self::invalid( 'link_text', __( 'The text has a link that the new text would drop. Send link_text, the part of the text that stays linked, and url for where it points.', 'leitvia-studio' ), $target );
		}
		if ( isset( $change['url'] ) ) {
			$url = self::url( (string) $change['url'] );
			if ( '' === $url ) {
				return self::invalid( 'url', __( 'The link is not a valid URL.', 'leitvia-studio' ), $target );
			}
			$tag = new \WP_HTML_Tag_Processor( $open );
			$tag->next_tag();
			$tag->set_attribute( 'href', $url );
			$open = $tag->get_updated_html();
		}
		return esc_html( substr( $text, 0, $at ) ) . $open . esc_html( $label ) . '</a>' . esc_html( substr( $text, $at + strlen( $label ) ) );
	}

	/**
	 * Block with new attributes. Allowed are attributes of the block catalog that live in the block comment, on blocks
	 * that render on the server or save no markup of their own, the CORE_ATTRIBUTES whose markup is rebuilt here, text
	 * attributes whose element the block has (sourced()), and leitviaAnimation and leitviaMotion on every block that has them, static markup included: the server adds their
	 * classes on render, the saved markup stays (animation()). className takes a registered block style on every core
	 * block that has one (style()), a heading, paragraph or light group a preset of presets(). Anything else would leave the saved markup behind.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param array<string, mixed> $attrs  New attribute values.
	 * @param array<string, mixed> $target Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function attributes( array $block, array $attrs, array $target, \WP_Error $rebuild ) {
		$name = (string) $block['blockName'];
		// null removes an attribute. One the block neither has nor registers is gone already, so its null changes
		// nothing instead of failing the call; any other value of an unknown attribute is still refused below.
		$known = (array) ( \WP_Block_Type_Registry::get_instance()->get_registered( $name )->attributes ?? array() );
		$attrs = array_filter( $attrs, static fn( $value, $key ): bool => null !== $value || array_key_exists( $key, (array) $block['attrs'] ) || isset( $known[ $key ] ), ARRAY_FILTER_USE_BOTH );
		if ( ! $attrs ) {
			return $block;
		}
		if ( array_key_exists( 'className', $attrs ) && str_starts_with( $name, 'core/' ) && ! in_array( $name, self::MEDIA_BLOCKS, true ) && self::styles( $name ) ) {
			$block = self::style( $block, $attrs['className'], $target, $rebuild );
			$attrs = array_diff_key( $attrs, array( 'className' => true ) );
			if ( is_wp_error( $block ) || ! $attrs ) {
				return $block;
			}
		}
		// The effect on scroll lives in the block comment only, so it goes with any other change of the same call.
		$motion = array_intersect_key( $attrs, array_flip( array( 'leitviaAnimation', 'leitviaMotion' ) ) );
		if ( $motion ) {
			$block = self::animation( $block, $motion, $target );
			$attrs = array_diff_key( $attrs, $motion );
			if ( is_wp_error( $block ) || ! $attrs ) {
				return $block;
			}
		}
		if ( in_array( $name, self::MEDIA_BLOCKS, true ) ) {
			$layout = in_array( $name, self::PLAYER_BLOCKS, true ) ? array_intersect_key( $attrs, self::media_schema( $name ) ) : $attrs;
			$block  = $layout ? self::media( $block, $layout, $target, $rebuild ) : $block;
			$attrs  = array_diff_key( $attrs, $layout );
			if ( is_wp_error( $block ) || ! $attrs ) {
				return $block;
			}
		}
		// Every other block with supports.align takes align with the values it supports, see align().
		if ( array_key_exists( 'align', $attrs ) && self::aligns( $name ) ) {
			$block = self::align( $block, $attrs['align'], $target, $rebuild );
			$attrs = array_diff_key( $attrs, array( 'align' => true ) );
			if ( is_wp_error( $block ) || ! $attrs ) {
				return $block;
			}
		}
		// Presets of a support with a class on the element of the block, see preset().
		foreach ( array_intersect_key( self::presets( $block ), $attrs ) as $key => $allowed ) {
			$block = self::preset( $block, $key, $attrs[ $key ], $allowed, $target, $rebuild );
			$attrs = array_diff_key( $attrs, array( $key => true ) );
			if ( is_wp_error( $block ) || ! $attrs ) {
				return $block;
			}
		}
		$core  = self::CORE_ATTRIBUTES[ $name ] ?? null;
		$entry = $core ? array( 'attributes' => array( 'properties' => $core ) ) : array_column( \LeitviaStudio\Blocks\Catalog::get(), null, 'name' )[ $name ] ?? null;
		if ( ! $entry ) {
			return self::invalid( 'attributes', __( 'This block is not in the block catalog; its attributes cannot be set.', 'leitvia-studio' ), $target, 'leitvia/list-blocks' );
		}
		$texts = array();
		foreach ( $attrs as $key => $value ) {
			$schema = $entry['attributes']['properties'][ $key ] ?? null;
			if ( ! is_array( $schema ) ) {
				/* translators: %s: attribute name. */
				return self::invalid( 'attributes', sprintf( __( 'The block has no attribute %s in the catalog.', 'leitvia-studio' ), $key ), $target, 'leitvia/list-blocks' );
			}
			if ( isset( $schema['source'] ) ) {
				$date = 'leitvia/timeline-item' === $name && 'date' === $key;
				if ( ( ! $date && ! in_array( $schema['source'], array( 'text', 'rich-text', 'html', 'attribute' ), true ) ) || ! is_string( $value ) ) {
					return $rebuild;
				}
				if ( $date && '' !== $value && ! preg_match( self::TIMELINE_DATE, $value ) ) {
					return self::invalid( 'attributes', __( 'The date of a timeline entry is YYYY, YYYY-MM or YYYY-MM-DD, or empty for an entry with a free label.', 'leitvia-studio' ), $target );
				}
				$texts[ $key ] = $value;
				continue;
			}
			$valid = rest_validate_value_from_schema( $value, array_diff_key( $schema, array( 'default' => true ) ), (string) $key );
			if ( is_wp_error( $valid ) ) {
				return self::invalid( 'attributes', $valid->get_error_message(), $target, 'leitvia/list-blocks' );
			}
		}
		if ( $core ) {
			return match ( true ) {
				isset( $attrs['level'] )  => self::level( $block, (int) $attrs['level'], $rebuild ),
				isset( $attrs['tracks'] ) => self::tracks( $block, array_values( (array) $attrs['tracks'] ), $target, $rebuild ),
				'core/query' === $name    => self::query( $block, $attrs ),
				'core/site-logo' === $name => self::logo_width( $block, (int) $attrs['width'] ),
				default                   => $block,
			};
		}
		if ( 'leitvia/map' === $name ) {
			return self::map_block( $block, $attrs, $target, $rebuild );
		}
		if ( $texts ) {
			$block = self::sourced( $block, $texts, $rebuild );
			$attrs = array_diff_key( $attrs, $texts );
			if ( is_wp_error( $block ) || ! $attrs ) {
				return $block;
			}
		}
		$position = self::POSITION_ATTRIBUTES[ $name ] ?? array();
		if ( $position && ! array_diff_key( $attrs, $position ) ) {
			return self::position( $block, $attrs, $position, $target, $rebuild );
		}
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$own  = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
		if ( ! $type || ( ! $type->is_dynamic() && '' !== trim( $own ) ) ) {
			return $rebuild;
		}
		$block['attrs'] = $attrs + (array) $block['attrs'];
		return $block;
	}

	/**
	 * Map with new attributes: the comment takes them, and the saved list of places is built again as save.js builds
	 * it (\LeitviaStudio\Blocks\Map::places()); the wrapper keeps its attributes, only its height class follows.
	 * Every marker needs a title and lat and lng on the earth; heading "" removes the heading.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param array<string, mixed> $attrs   New attributes, checked against the catalog.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function map_block( array $block, array $attrs, array $target, \WP_Error $rebuild ) {
		foreach ( (array) ( $attrs['markers'] ?? array() ) as $marker ) {
			$marker = (array) $marker;
			if ( '' === trim( (string) ( $marker['title'] ?? '' ) ) || ! is_numeric( $marker['lat'] ?? null ) || ! is_numeric( $marker['lng'] ?? null ) || abs( (float) $marker['lat'] ) > 90 || abs( (float) $marker['lng'] ) > 180 ) {
				return self::invalid( 'attributes', __( 'Each marker of a map needs a title, lat from -90 to 90 and lng from -180 to 180; address is the line below the title.', 'leitvia-studio' ), $target );
			}
		}
		if ( ( isset( $attrs['zoom'] ) && ( $attrs['zoom'] < 1 || $attrs['zoom'] > 19 ) ) || ( isset( $attrs['lat'] ) && abs( (float) $attrs['lat'] ) > 90 ) || ( isset( $attrs['lng'] ) && abs( (float) $attrs['lng'] ) > 180 ) ) {
			return self::invalid( 'attributes', __( 'A map takes zoom from 1 to 19, lat from -90 to 90 and lng from -180 to 180.', 'leitvia-studio' ), $target );
		}
		$html = (string) ( $block['innerContent'][0] ?? '' );
		$tags = self::processor( $html );
		if ( 1 !== count( (array) $block['innerContent'] ) || ! $tags->next_tag( array( 'class_name' => 'wp-block-leitvia-map' ) ) ) {
			return $rebuild;
		}
		$range = $tags->inner_range();
		if ( ! $range ) {
			return $rebuild;
		}
		$heading = self::element( $block, array( 'class_name' => 'wp-block-leitvia-map__heading' ) );
		$heading = array_key_exists( 'heading', $attrs ) ? esc_html( (string) $attrs['heading'] ) : ( $heading ? substr( $html, $heading[1], $heading[2] ) : '' );
		$saved   = array_diff_key( $attrs, array( 'heading' => true ) ) + (array) $block['attrs'];
		$inner   = \LeitviaStudio\Blocks\Map::places( $heading, $saved );
		$html    = substr_replace( $html, $inner, $range[0], $range[1] );
		$tags    = new \WP_HTML_Tag_Processor( $html );
		$tags->next_tag();
		foreach ( array( 'small', 'medium', 'large' ) as $height ) {
			$tags->remove_class( 'is-height-' . $height );
		}
		$tags->add_class( 'is-height-' . ( $saved['height'] ?? 'medium' ) );

		$block['attrs']        = $saved;
		$block['innerContent'] = array( $tags->get_updated_html() );
		return $block;
	}

	/**
	 * Names of the block styles of a block: registered with register_block_style() or as a style variation of the
	 * theme, and those of its block.json.
	 *
	 * @param string $name Block name.
	 * @return list<string>
	 */
	private static function styles( string $name ): array {
		$type  = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$names = array_keys( \WP_Block_Styles_Registry::get_instance()->get_registered_styles_for_block( $name ) );
		foreach ( $type ? (array) $type->styles : array() as $style ) {
			$names[] = is_array( $style ) ? ( $style['name'] ?? '' ) : '';
		}
		return array_values( array_filter( array_map( 'strval', $names ) ) );
	}

	/**
	 * Site logo with a new width in its block comment, first of its attributes as the editor saves it.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param int                  $width Width in pixels.
	 * @return array<string, mixed>
	 */
	private static function logo_width( array $block, int $width ): array {
		$block['attrs'] = array( 'width' => $width ) + (array) $block['attrs'];
		return $block;
	}

	/**
	 * Core block outside MEDIA_BLOCKS with a new block style: is-style-<name> from styles(), or null or an empty
	 * string, which removes it. Several styles of the block that combine go space separated (is-style-sticky-header-desktop
	 * is-style-overlay-header on a header group); the editor shows the first as the active style and keeps the others.
	 * Only the is-style-* classes change, in className and on the first element of the saved markup; other classes stay.
	 * A block that saves no markup of its own keeps the classes in its comment only.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param mixed                $value   New className.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function style( array $block, $value, array $target, \WP_Error $rebuild ) {
		$name  = (string) $block['blockName'];
		$valid = rest_validate_value_from_schema( $value, self::media_schema( $name )['className'], 'className' );
		if ( is_wp_error( $valid ) ) {
			return self::invalid( 'attributes', $valid->get_error_message(), $target, 'leitvia/list-blocks' );
		}
		$style = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
		foreach ( '' === $style ? array() : explode( ' ', $style ) as $class ) {
			if ( ! in_array( substr( $class, 9 ), self::styles( $name ), true ) ) {
				/* translators: %s: class of a block style. */
				return self::invalid( 'attributes', sprintf( __( 'The block has no block style %s.', 'leitvia-studio' ), $class ), $target, 'leitvia/list-blocks' );
			}
		}
		$tags = null;
		if ( '' !== trim( implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) ) ) ) {
			$tags = new \WP_HTML_Tag_Processor( (string) ( $block['innerContent'][0] ?? '' ) );
			if ( ! $tags->next_tag() ) {
				return $rebuild;
			}
		}
		$class = self::restyle( $block, $style, $tags );
		if ( $tags ) {
			$block['innerContent'][0] = $tags->get_updated_html();
		}
		$attrs = (array) $block['attrs'];
		if ( '' === $class ) {
			unset( $attrs['className'] );
		} elseif ( isset( $attrs['className'] ) ) {
			$attrs['className'] = $class;
		} else {
			// The editor saves className after align and before style, the colors, fontSize, fontFamily, layout and anchor.
			$after = array( 'style', 'backgroundColor', 'textColor', 'gradient', 'borderColor', 'fontSize', 'fontFamily', 'layout', 'anchor' );
			$at    = array_search( true, array_map( static fn( $key ): bool => in_array( $key, $after, true ), array_keys( $attrs ) ), true );
			$at    = false === $at ? count( $attrs ) : (int) $at;
			$attrs = array_slice( $attrs, 0, $at, true ) + array( 'className' => $class ) + array_slice( $attrs, $at, null, true );
		}
		$block['attrs'] = $attrs;
		return $block;
	}

	/**
	 * The className of a block with its is-style-* classes swapped for new ones (space separated, '' for none), the same
	 * swap made on the element of the tag processor when there is one.
	 *
	 * @param array<string, mixed>        $block Parsed block.
	 * @param string                      $style is-style-<name>, several space separated, or ''.
	 * @param \WP_HTML_Tag_Processor|null $tags  Processor at the element of the block.
	 */
	private static function restyle( array $block, string $style, ?\WP_HTML_Tag_Processor $tags ): string {
		$classes = (array) preg_split( '/\s+/', trim( (string) ( $block['attrs']['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( $tags ) {
			foreach ( array_merge( $classes, iterator_to_array( $tags->class_list(), false ) ) as $class ) {
				if ( str_starts_with( (string) $class, 'is-style-' ) ) {
					$tags->remove_class( (string) $class );
				}
			}
		}
		$kept = array_values( array_filter( $classes, static fn( $name ): bool => ! str_starts_with( (string) $name, 'is-style-' ) ) );
		foreach ( '' === $style ? array() : explode( ' ', $style ) as $class ) {
			$tags?->add_class( $class );
			$kept[] = $class;
		}
		return implode( ' ', $kept );
	}

	/**
	 * Schema of the attributes media() changes on a block of MEDIA_BLOCKS. null removes an attribute.
	 *
	 * @param string $name Block name.
	 * @return array<string, array<string, mixed>>
	 */
	public static function media_schema( string $name ): array {
		$size   = array(
			'type'    => array( 'string', 'null' ),
			'pattern' => '^(auto|\d+(\.\d+)?(px|%|em|rem|vw|vh))$',
		);
		$schema = array(
			'align'     => array(
				'type' => array( 'string', 'null' ),
				'enum' => array( 'left', 'center', 'right', 'wide', 'full', null ),
			),
			'className' => array(
				'type'    => array( 'string', 'null' ),
				'pattern' => '^(is-style-[a-z0-9-]+(\\s+is-style-[a-z0-9-]+){0,2})?$',
			),
		);
		if ( 'core/cover' === $name ) {
			// The height of a cover in percent of the window, for a stage that fills most of the first screen; the focal
			// point of its photo (x and y from 0 to 1), the part that stays in view where the cover crops it.
			$share = array(
				'type'    => 'number',
				'minimum' => 0,
				'maximum' => 1,
			);
			return $schema + array(
				'minHeight'  => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 30,
					'maximum' => 100,
				),
				'focalPoint' => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'x' => $share,
						'y' => $share,
					),
					'required'             => array( 'x', 'y' ),
					'additionalProperties' => false,
				),
			);
		}
		if ( 'core/image' !== $name ) {
			return $schema;
		}
		return $schema + array(
			'width'           => $size,
			'height'          => $size,
			'aspectRatio'     => array(
				'type'    => array( 'string', 'null' ),
				'pattern' => '^(auto|\d+(\.\d+)?(/\d+(\.\d+)?)?)$',
			),
			'scale'           => array(
				'type' => array( 'string', 'null' ),
				'enum' => array( 'cover', 'contain', 'fill', null ),
			),
			'sizeSlug'        => array(
				'type' => 'string',
				'enum' => array_values( array_merge( get_intermediate_image_sizes(), array( 'full' ) ) ),
			),
			'linkDestination' => array(
				'type' => 'string',
				'enum' => array( 'none', 'media', 'attachment', 'custom' ),
			),
			'href'            => array( 'type' => array( 'string', 'null' ) ),
			'lightbox'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => array( 'enabled' => array( 'type' => 'boolean' ) ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Media block with new layout attributes (MEDIA_BLOCKS, media_schema()), its markup rebuilt as the editor saves it:
	 * the classes align<value>, is-style-<name>, size-<slug> and is-resized on the element of the block, aspect-ratio,
	 * object-fit, width and height in the style of the image, the src of the size and the link around the image.
	 * null removes an attribute, so a fixed height without aspect ratio goes with height null.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param array<string, mixed> $attrs   New attribute values.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function media( array $block, array $attrs, array $target, \WP_Error $rebuild ) {
		$name   = (string) $block['blockName'];
		$schema = self::media_schema( $name );
		foreach ( $attrs as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				/* translators: %s: attribute name. */
				return self::invalid( 'attributes', sprintf( __( 'The block has no attribute %s in the catalog.', 'leitvia-studio' ), $key ), $target, 'leitvia/list-blocks' );
			}
			$valid = rest_validate_value_from_schema( $value, $schema[ $key ], (string) $key );
			if ( is_wp_error( $valid ) ) {
				return self::invalid( 'attributes', $valid->get_error_message(), $target );
			}
		}
		$style = trim( (string) preg_replace( '/\s+/', ' ', (string) ( $attrs['className'] ?? '' ) ) );
		foreach ( '' === $style ? array() : explode( ' ', $style ) as $class ) {
			if ( ! in_array( substr( $class, 9 ), self::styles( $name ), true ) ) {
				/* translators: %s: class of a block style. */
				return self::invalid( 'attributes', sprintf( __( 'The block has no block style %s.', 'leitvia-studio' ), $class ), $target, 'leitvia/list-blocks' );
			}
		}
		$tags = new \WP_HTML_Tag_Processor( (string) ( $block['innerContent'][0] ?? '' ) );
		if ( ! $tags->next_tag() ) {
			return $rebuild;
		}
		$new = array_merge( (array) $block['attrs'], $attrs );
		if ( array_key_exists( 'align', $attrs ) ) {
			foreach ( array( 'left', 'center', 'right', 'wide', 'full' ) as $align ) {
				$tags->remove_class( 'align' . $align );
			}
			if ( null !== $attrs['align'] ) {
				$tags->add_class( 'align' . $attrs['align'] );
			}
		}
		if ( array_key_exists( 'className', $attrs ) ) {
			$new['className'] = self::restyle( $block, $style, $tags );
		}
		if ( array_key_exists( 'minHeight', $attrs ) ) {
			// The editor saves min-height first in the style of the cover, the unit in minHeightUnit.
			$rules = array_filter(
				array_map( 'trim', explode( ';', (string) $tags->get_attribute( 'style' ) ) ),
				static fn( string $rule ): bool => '' !== $rule && ! preg_match( '/^min-height\s*:/i', $rule )
			);
			if ( null !== $attrs['minHeight'] ) {
				array_unshift( $rules, 'min-height:' . (int) $attrs['minHeight'] . 'vh' );
			}
			if ( $rules ) {
				$tags->set_attribute( 'style', implode( ';', $rules ) );
			} else {
				$tags->remove_attribute( 'style' );
			}
			$new = self::place( $name, self::place( $name, $new, 'minHeight', $attrs['minHeight'] ), 'minHeightUnit', null === $attrs['minHeight'] ? null : 'vh' );
		}
		if ( array_key_exists( 'focalPoint', $attrs ) ) {
			// The editor saves the point as object-position of the background image, in whole percent, in its style and
			// in data-object-position; null puts the photo back in the middle.
			$point    = null === $attrs['focalPoint'] ? null : array(
				'x' => (float) $attrs['focalPoint']['x'],
				'y' => (float) $attrs['focalPoint']['y'],
			);
			$position = null === $point ? '' : round( $point['x'] * 100 ) . '% ' . round( $point['y'] * 100 ) . '%';
			if ( ! $tags->next_tag(
				array(
					'tag_name'   => 'img',
					'class_name' => 'wp-block-cover__image-background',
				)
			) ) {
				return $rebuild;
			}
			$rules = array_filter(
				array_map( 'trim', explode( ';', (string) $tags->get_attribute( 'style' ) ) ),
				static fn( string $rule ): bool => '' !== $rule && ! preg_match( '/^object-position\s*:/i', $rule )
			);
			if ( '' !== $position ) {
				$rules[] = 'object-position:' . $position;
				$tags->set_attribute( 'data-object-position', $position );
			} elseif ( null !== $tags->get_attribute( 'data-object-position' ) ) {
				$tags->remove_attribute( 'data-object-position' );
			}
			if ( $rules ) {
				$tags->set_attribute( 'style', implode( ';', $rules ) );
			} elseif ( null !== $tags->get_attribute( 'style' ) ) {
				$tags->remove_attribute( 'style' );
			}
			$new = self::place( $name, $new, 'focalPoint', $point );
		}
		if ( isset( $attrs['sizeSlug'] ) ) {
			foreach ( iterator_to_array( $tags->class_list(), false ) as $class ) {
				if ( str_starts_with( (string) $class, 'size-' ) ) {
					$tags->remove_class( (string) $class );
				}
			}
			$tags->add_class( 'size-' . $attrs['sizeSlug'] );
		}
		$resize = array_intersect_key( $attrs, array_flip( array( 'width', 'height', 'aspectRatio', 'scale' ) ) );
		if ( $resize && ( empty( $new['width'] ) && empty( $new['height'] ) ) ) {
			$tags->remove_class( 'is-resized' );
		} elseif ( $resize ) {
			$tags->add_class( 'is-resized' );
		}
		if ( 'core/image' === $name && ( $resize || isset( $attrs['sizeSlug'] ) ) ) {
			if ( ! $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
				return $rebuild;
			}
			if ( isset( $attrs['sizeSlug'] ) ) {
				$url = wp_get_attachment_image_url( (int) ( $block['attrs']['id'] ?? 0 ), (string) $attrs['sizeSlug'] );
				if ( ! $url ) {
					return $rebuild;
				}
				$tags->set_attribute( 'src', $url );
			}
			if ( $resize ) {
				// The editor saves the four as style of the image, in this order, after the border styles.
				$rules = array_filter(
					array_map( 'trim', explode( ';', (string) $tags->get_attribute( 'style' ) ) ),
					static fn( string $rule ): bool => '' !== $rule && ! preg_match( '/^(aspect-ratio|object-fit|width|height)\s*:/i', $rule )
				);
				$css   = array(
					'aspect-ratio' => $new['aspectRatio'] ?? null,
					'object-fit'   => $new['scale'] ?? null,
					'width'        => $new['width'] ?? null,
					'height'       => $new['height'] ?? null,
				);
				foreach ( array_filter( $css, static fn( $value ): bool => is_string( $value ) && '' !== $value ) as $property => $value ) {
					$rules[] = $property . ':' . $value;
				}
				$tags->remove_attribute( 'width' );
				$tags->remove_attribute( 'height' );
				if ( $rules ) {
					$tags->set_attribute( 'style', implode( ';', $rules ) );
				} else {
					$tags->remove_attribute( 'style' );
				}
			}
		}
		// remove_attribute() leaves the space before it (<img >, <img   class>), which KSES would close up.
		$html = (string) preg_replace( array( '#<([a-z][^\s/>]*)\s+(/?)>#i', '#<([a-z][^\s/>]*)\s{2,}#i' ), array( '<$1$2>', '<$1 ' ), $tags->get_updated_html() );
		if ( array_key_exists( 'href', $attrs ) ) {
			$url = null === $attrs['href'] ? '' : self::url( (string) $attrs['href'] );
			if ( null !== $attrs['href'] && '' === $url ) {
				return self::invalid( 'url', __( 'The link is not a valid URL.', 'leitvia-studio' ), $target );
			}
			$linked = preg_match( '#<a\b[^>]*>\s*(<img\b[^>]*>)\s*</a>#i', $html, $match );
			$image  = $linked ? $match[1] : ( preg_match( '#<img\b[^>]*>#i', $html, $found ) ? $found[0] : '' );
			if ( 'core/image' !== $name || '' === $image ) {
				return $rebuild;
			}
			$html                   = str_replace( $linked ? $match[0] : $image, '' === $url ? $image : '<a href="' . esc_url( $url ) . '">' . $image . '</a>', $html );
			$new['href']            = '' === $url ? null : $url;
			$new['linkDestination'] = $attrs['linkDestination'] ?? ( '' === $url ? 'none' : 'custom' );
		}
		$block['innerContent'][0] = $html;
		$block['attrs']           = array_filter( $new, static fn( $value ): bool => null !== $value && '' !== $value );
		return $block;
	}

	/**
	 * Query with new settings: query merges into the settings the block has, from the default of core, which
	 * inherits, when it has none, because a query attribute replaces that default as a whole.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param array<string, mixed> $attrs query and/or queryId.
	 * @return array<string, mixed>
	 */
	private static function query( array $block, array $attrs ): array {
		if ( isset( $attrs['query'] ) ) {
			$default         = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/query' )->attributes['query']['default'] ?? array();
			$attrs['query'] += (array) ( $block['attrs']['query'] ?? $default );
		}
		$block['attrs'] = $attrs + (array) $block['attrs'];
		return $block;
	}

	/**
	 * Video with new text tracks from WebVTT files of the media library: the track elements in the video and the
	 * attribute tracks, as the editor saves them. An empty list removes all.
	 *
	 * @param array<string, mixed>       $block   Parsed block.
	 * @param list<array<string, mixed>> $tracks  id, kind, srclang, label.
	 * @param array<string, mixed>       $target  Target.
	 * @param \WP_Error                  $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function tracks( array $block, array $tracks, array $target, \WP_Error $rebuild ) {
		$stored = array();
		$markup = '';
		foreach ( $tracks as $track ) {
			$id  = (int) $track['id'];
			$url = 'text/vtt' === get_post_mime_type( $id ) && Scope::readable( $id ) ? (string) wp_get_attachment_url( $id ) : '';
			if ( '' === $url ) {
				return self::invalid( 'tracks', __( 'A track needs a WebVTT file of the media library. Add it with leitvia/add-media first.', 'leitvia-studio' ), $target, 'leitvia/add-media' );
			}
			$kind     = (string) ( $track['kind'] ?? 'captions' );
			$stored[] = array(
				'src'     => $url,
				'label'   => (string) $track['label'],
				'srcLang' => (string) $track['srclang'],
				'kind'    => $kind,
			);
			$markup  .= sprintf( '<track src="%s" label="%s" srclang="%s" kind="%s"/>', esc_url( $url ), esc_attr( (string) $track['label'] ), esc_attr( (string) $track['srclang'] ), esc_attr( $kind ) );
		}
		$html  = (string) ( $block['innerContent'][0] ?? '' );
		$tags  = self::processor( $html );
		$range = $tags->next_tag( 'video' ) ? $tags->inner_range() : null;
		if ( ! $range ) {
			return $rebuild;
		}
		$block['innerContent'][0] = substr_replace( $html, $markup, $range[0], $range[1] );
		$block['attrs']           = self::place( 'core/video', (array) $block['attrs'], 'tracks', $stored ? $stored : null );
		return $block;
	}

	/**
	 * Block with new text attributes in their elements or in an attribute of an element. The counter rebuilds its number, whose parts come and go; any
	 * other block needs the element already, because what its save function leaves out cannot be rebuilt here.
	 *
	 * @param array<string, mixed>  $block   Parsed block.
	 * @param array<string, string> $texts   New values by attribute.
	 * @param \WP_Error             $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function sourced( array $block, array $texts, \WP_Error $rebuild ) {
		if ( 'leitvia/counter' === $block['blockName'] ) {
			$number = array_intersect_key( $texts, array_flip( array( 'prefix', 'value', 'suffix' ) ) );
			$block  = $number ? self::counter( $block, $number, $rebuild ) : $block;
			$texts  = array_diff_key( $texts, $number );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		if ( 'leitvia/timeline-item' === $block['blockName'] && isset( $texts['date'] ) ) {
			$block = self::timeline_date( $block, $texts['date'], $rebuild );
			unset( $texts['date'] );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		$types = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $block['blockName'] )->attributes ?? array();
		foreach ( $texts as $key => $text ) {
			$selector = (string) ( $types[ $key ]['selector'] ?? '' );
			$query    = match ( true ) {
				(bool) preg_match( '/^\.[\w-]+$/', $selector ) => array( 'class_name' => substr( $selector, 1 ) ),
				(bool) preg_match( '/^[a-z][a-z0-9]*$/', $selector ) => array( 'tag_name' => $selector ),
				default => null,
			};
			if ( 'attribute' === ( $types[ $key ]['source'] ?? '' ) ) {
				// An attribute of an element in the markup before the first inner block, such as the aria-label of a slider.
				$tags = new \WP_HTML_Tag_Processor( (string) ( $block['innerContent'][0] ?? '' ) );
				// Only the name for screen readers; a link or form target has rules of its own and stays with the markup.
				if ( '' === trim( $text ) || ! $query || 'aria-label' !== ( $types[ $key ]['attribute'] ?? '' ) || ! $tags->next_tag( $query ) ) {
					return $rebuild;
				}
				$tags->set_attribute( 'aria-label', $text );
				$block['innerContent'][0] = $tags->get_updated_html();
				continue;
			}
			$found = '' !== trim( $text ) ? self::element( $block, $query ) : null;
			if ( ! $found ) {
				return $rebuild;
			}
			$block['innerContent'][ $found[0] ] = substr_replace( (string) $block['innerContent'][ $found[0] ], esc_html( $text ), $found[1], $found[2] );
		}
		return $block;
	}

	/**
	 * Counter with a new number, built like its save function: prefix and suffix only with a value, the value with a
	 * minimum width of its length, so the counting copy of view.js moves nothing.
	 *
	 * @param array<string, mixed>  $block   Parsed block.
	 * @param array<string, string> $values  New prefix, value or suffix.
	 * @param \WP_Error             $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function counter( array $block, array $values, \WP_Error $rebuild ) {
		$class = 'wp-block-leitvia-counter__';
		$html  = (string) ( $block['innerContent'][0] ?? '' );
		$parts = array();
		foreach ( array( 'prefix', 'value', 'suffix' ) as $part ) {
			$found          = self::element( $block, array( 'class_name' => $class . $part ) );
			$parts[ $part ] = isset( $values[ $part ] ) ? esc_html( $values[ $part ] ) : ( $found ? substr( $html, $found[1], $found[2] ) : '' );
		}
		$number = self::element( $block, array( 'class_name' => $class . 'number' ) );
		if ( ! $number || '' === $parts['value'] ) {
			return $rebuild;
		}
		$span  = static fn( string $part, string $style = '' ): string => '' === $parts[ $part ] ? '' : '<span class="' . $class . $part . '"' . $style . '>' . $parts[ $part ] . '</span>';
		$width = mb_strlen( html_entity_decode( $parts['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		$block['innerContent'][0] = substr_replace( $html, $span( 'prefix' ) . $span( 'value', ' style="min-inline-size:' . $width . 'ch"' ) . $span( 'suffix' ), $number[1], $number[2] );
		return $block;
	}

	/**
	 * Alignments a block takes by supports.align: all five for true, the listed ones for a list, none without.
	 *
	 * @param string $name Block name.
	 * @return list<string>
	 */
	public static function aligns( string $name ): array {
		$align = \WP_Block_Type_Registry::get_instance()->get_registered( $name )->supports['align'] ?? false;
		return true === $align ? array( 'left', 'center', 'right', 'wide', 'full' ) : array_values( array_filter( (array) $align, 'is_string' ) );
	}

	/**
	 * Block with a new align of aligns() (null removes it) in its comment. Saved markup of a static block takes the
	 * class align<value> on its outer element as the editor saves it; a block that renders on the server adds the class
	 * itself (block supports).
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param mixed                $align   New align or null.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function align( array $block, $align, array $target, \WP_Error $rebuild ) {
		$name   = (string) $block['blockName'];
		$aligns = self::aligns( $name );
		if ( null !== $align && ! in_array( $align, $aligns, true ) ) {
			/* translators: %s: list of alignments, for example wide, full. */
			return self::invalid( 'attributes', sprintf( __( 'This block takes align %s, or null to remove it.', 'leitvia-studio' ), implode( ', ', $aligns ) ), $target, 'leitvia/list-blocks' );
		}
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$own  = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
		if ( $type && ! $type->is_dynamic() && '' !== trim( $own ) ) {
			$tags = new \WP_HTML_Tag_Processor( (string) ( $block['innerContent'][0] ?? '' ) );
			if ( ! $tags->next_tag() ) {
				return $rebuild;
			}
			foreach ( array( 'left', 'center', 'right', 'wide', 'full' ) as $old ) {
				$tags->remove_class( 'align' . $old );
			}
			if ( null !== $align ) {
				$tags->add_class( 'align' . $align );
			}
			$block['innerContent'][0] = $tags->get_updated_html();
		}
		$block['attrs'] = self::place( $name, (array) $block['attrs'], 'align', $align );
		return $block;
	}

	/**
	 * Attributes of a block comment with one attribute set (null removes it) where the editor saves it: in the order
	 * core registers the attributes in, so after the attributes of the block, lock and metadata and among the supports
	 * (align, className, style, colors, fontSize, layout).
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Attributes of the comment.
	 * @param string               $key   Attribute.
	 * @param mixed                $value New value or null.
	 * @return array<string, mixed>
	 */
	private static function place( string $name, array $attrs, string $key, $value ): array {
		$order  = array_flip( array_keys( (array) ( \WP_Block_Type_Registry::get_instance()->get_registered( $name )->attributes ?? array() ) ) );
		$at     = $order[ $key ] ?? PHP_INT_MAX;
		$rest   = array_diff_key( $attrs, array( $key => true ) );
		$before = array_filter( $rest, static fn( $other ): bool => isset( $order[ $other ] ) && $order[ $other ] < $at, ARRAY_FILTER_USE_KEY );
		return $before + ( null === $value ? array() : array( $key => $value ) ) + array_diff_key( $rest, $before );
	}

	/**
	 * Text color roles a heading or paragraph takes as textColor: the text roles that pass on surface and surface-alt.
	 */
	public const TEXT_COLORS = array( 'ink', 'ink-muted', 'accent' );

	/**
	 * Presets a block takes as attribute, with the values allowed: fontSize with the font sizes of the theme and
	 * textColor with the text roles of TEXT_COLORS on a heading or paragraph, fontSize on a quote, backgroundColor surface or surface-alt on a
	 * group whose ground is light already. A dark or accent ground carries text colors of its own; such a band comes
	 * from the block style Inverse or from another pattern. A block of Studio or an add-on whose supports name
	 * color.text or typography.fontSize, such as leitvia/icon and leitvia/icon-list, takes textColor with the color roles
	 * of the contract and fontSize with the font sizes.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, list<string>>
	 */
	public static function presets( array $block ): array {
		$name  = (string) $block['blockName'];
		$sizes = static function (): array {
			$sizes = (array) wp_get_global_settings( array( 'typography', 'fontSizes' ) );
			// By origin (default, theme, custom) or one flat list, depending on the theme.json.
			$sizes = isset( $sizes[0] ) ? $sizes : array_merge( array(), ...array_values( array_map( 'array_values', array_filter( $sizes, 'is_array' ) ) ) );
			return array_values( array_unique( array_map( 'strval', array_column( $sizes, 'slug' ) ) ) );
		};
		if ( in_array( $name, array( 'core/heading', 'core/paragraph' ), true ) ) {
			return array(
				'textColor' => self::TEXT_COLORS,
				'fontSize'  => $sizes(),
			);
		}
		// A quote of a row of quotes takes the size of the others, or none (null) for the size of the theme.
		if ( 'core/quote' === $name ) {
			return array( 'fontSize' => $sizes() );
		}
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( $type && ! str_starts_with( $name, 'core/' ) ) {
			$roles = array_map( 'strval', array_column( (array) ( leitvia_studio_contract()->data()['roles']['color'] ?? array() ), 'slug' ) );
			return array_filter(
				array(
					'textColor' => ! empty( $type->supports['color']['text'] ) ? $roles : array(),
					'fontSize'  => ! empty( $type->supports['typography']['fontSize'] ) ? $sizes() : array(),
				)
			);
		}
		$light = array( 'surface', 'surface-alt' );
		$color = (array) ( $block['attrs']['style']['color'] ?? array() );
		if ( 'core/group' === $name && in_array( $block['attrs']['backgroundColor'] ?? 'surface', $light, true ) && ! isset( $block['attrs']['gradient'] ) && ! isset( $color['background'] ) && ! isset( $color['gradient'] ) ) {
			return array( 'backgroundColor' => $light );
		}
		return array();
	}

	/**
	 * Block with a new preset of presets() (null removes it): the attribute in its comment and the class
	 * has-<slug>-font-size, has-<slug>-color with has-text-color or has-<slug>-background-color with has-background on
	 * its element, as the editor saves them. A block that renders on the server and saves no markup of its own (the
	 * icon) keeps the attribute in its comment only.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param string               $key     fontSize or backgroundColor.
	 * @param mixed                $value   Slug of the preset or null.
	 * @param string[]             $allowed Slugs the block takes.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function preset( array $block, string $key, $value, array $allowed, array $target, \WP_Error $rebuild ) {
		if ( null !== $value && ! in_array( $value, $allowed, true ) ) {
			/* translators: 1: attribute name, 2: list of values. */
			return self::invalid( 'attributes', sprintf( __( 'The attribute %1$s takes one of %2$s here, or null to remove it.', 'leitvia-studio' ), $key, implode( ', ', $allowed ) ), $target, 'leitvia/get-tokens' );
		}
		$suffix = array(
			'fontSize'        => '-font-size',
			'backgroundColor' => '-background-color',
			'textColor'       => '-color',
		)[ $key ];
		$flag   = array(
			'backgroundColor' => 'has-background',
			'textColor'       => 'has-text-color',
		)[ $key ] ?? '';
		$tags   = new \WP_HTML_Tag_Processor( (string) ( $block['innerContent'][0] ?? '' ) );
		if ( ! $tags->next_tag() ) {
			$type = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $block['blockName'] );
			if ( ! $type || ! $type->is_dynamic() || '' !== trim( implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) ) ) ) {
				return $rebuild;
			}
			$block['attrs'] = self::place( (string) $block['blockName'], (array) $block['attrs'], $key, $value );
			return $block;
		}
		// has-<slug>-color also ends background and link classes; a text color class is one of a role.
		$own = 'textColor' === $key
			? static fn( string $name ): bool => in_array( $name, array_map( static fn( string $slug ): string => 'has-' . $slug . '-color', $allowed ), true )
			: static fn( string $name ): bool => str_starts_with( $name, 'has-' ) && str_ends_with( $name, $suffix );
		foreach ( iterator_to_array( $tags->class_list(), false ) as $class ) {
			if ( ( '' !== $flag && $flag === $class ) || $own( (string) $class ) ) {
				$tags->remove_class( (string) $class );
			}
		}
		if ( null !== $value ) {
			$tags->add_class( 'has-' . _wp_to_kebab_case( (string) $value ) . $suffix );
		}
		if ( null !== $value && '' !== $flag ) {
			$tags->add_class( $flag );
		}
		$block['innerContent'][0] = $tags->get_updated_html();
		$block['attrs']           = self::place( (string) $block['blockName'], (array) $block['attrs'], $key, $value );
		return $block;
	}

	/**
	 * Block with a new effect on scroll: leitviaAnimation (none or null removes it) and leitviaMotion (null removes it)
	 * in the block comment, in the order the editor saves them; the markup stays as it is.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param array<string, mixed> $attrs  leitviaAnimation and/or leitviaMotion.
	 * @param array<string, mixed> $target Target.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function animation( array $block, array $attrs, array $target ) {
		$name   = (string) $block['blockName'];
		$schema = \WP_Block_Type_Registry::get_instance()->get_registered( $name )->attributes ?? array();
		if ( ! isset( $schema['leitviaAnimation'] ) ) {
			return self::invalid( 'attributes', __( 'This block takes no effect on scroll.', 'leitvia-studio' ), $target, 'leitvia/list-blocks' );
		}
		foreach ( $attrs as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			$valid = rest_validate_value_from_schema( $value, array_diff_key( (array) $schema[ $key ], array( 'default' => true ) ), (string) $key );
			if ( is_wp_error( $valid ) ) {
				return self::invalid( 'attributes', $valid->get_error_message(), $target, 'leitvia/list-blocks' );
			}
		}
		$block['attrs'] = \LeitviaStudio\Blocks\Animation::with_attrs( $name, (array) $block['attrs'], $attrs );
		return $block;
	}

	/**
	 * Block with a new position (POSITION_ATTRIBUTES): the attributes in the comment and the custom properties in the
	 * style of its first element, as the editor saves them.
	 *
	 * @param array<string, mixed>  $block    Parsed block.
	 * @param array<string, mixed>  $attrs    New attribute values, numbers from 0 to 100.
	 * @param array<string, string> $position Custom property by attribute.
	 * @param array<string, mixed>  $target   Target.
	 * @param \WP_Error             $rebuild  Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function position( array $block, array $attrs, array $position, array $target, \WP_Error $rebuild ) {
		$tags  = new \WP_HTML_Tag_Processor( (string) ( $block['innerContent'][0] ?? '' ) );
		$style = $tags->next_tag() ? $tags->get_attribute( 'style' ) : null;
		if ( ! is_string( $style ) ) {
			return $rebuild;
		}
		foreach ( $attrs as $key => $value ) {
			if ( $value < 0 || $value > 100 ) {
				/* translators: %s: attribute name. */
				return self::invalid( 'attributes', sprintf( __( 'The attribute %s is a position in percent from 0 to 100.', 'leitvia-studio' ), $key ), $target );
			}
			$style = preg_replace( '/(^|;)\s*' . preg_quote( $position[ $key ], '/' ) . ':[^;]*/', '${1}' . $position[ $key ] . ':' . $value . '%', $style, 1, $count );
			if ( null === $style || 1 !== $count ) {
				return $rebuild;
			}
		}
		$tags->set_attribute( 'style', (string) $style );
		$block['innerContent'][0] = $tags->get_updated_html();
		$block['attrs']           = $attrs + (array) $block['attrs'];
		return $block;
	}

	/**
	 * Heading with a new level: the tag name of its element and the attribute level, left out at 2 as the editor saves
	 * it.
	 *
	 * @param array<string, mixed> $block   Parsed heading block.
	 * @param int                  $level   Level 1 to 6.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function level( array $block, int $level, \WP_Error $rebuild ) {
		$html = (string) ( $block['innerContent'][0] ?? '' );
		$new  = preg_replace( '#^(\s*<h)[1-6](\b.*</h)[1-6](>\s*)$#s', '${1}' . $level . '${2}' . $level . '${3}', $html, 1, $count );
		if ( 1 !== count( (array) $block['innerContent'] ) || 1 !== $count ) {
			return $rebuild;
		}
		$block['innerContent'][0] = $new;
		unset( $block['attrs']['level'] );
		$block['attrs'] += 2 === $level ? array() : array( 'level' => $level );
		return $block;
	}

	/**
	 * Block and its own markup with a new image from the media library: attachment ID, URL, alt text and the class
	 * wp-image-<ID> as the editor saves them; metadata.leitviaDecorative marks an image with empty alt on purpose.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param string               $html   Own markup before the inner blocks.
	 * @param array<string, mixed> $image  id, alt, decorative.
	 * @param array<string, mixed> $target Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array{0: array<string, mixed>, 1: string}|\WP_Error
	 */
	private static function image( array $block, string $html, array $image, array $target, \WP_Error $rebuild ) {
		$id         = (int) ( $image['id'] ?? 0 );
		$decorative = ! empty( $image['decorative'] );
		if ( ! wp_attachment_is_image( $id ) || ! Scope::readable( $id ) ) {
			return self::invalid( 'image', __( 'The image is not in the media library. Add it with leitvia/add-media first.', 'leitvia-studio' ), $target, 'leitvia/add-media' );
		}
		// Without alt the image keeps the alt text of the media library; an empty alt counts only as decorative.
		$alt = $decorative ? '' : trim( (string) ( $image['alt'] ?? get_post_meta( $id, '_wp_attachment_image_alt', true ) ) );
		if ( '' === $alt && ! $decorative ) {
			return self::invalid( 'alt_text', __( 'Give the image an alt text that describes it, or mark it as decorative.', 'leitvia-studio' ), $target );
		}
		$cover = 'core/cover' === $block['blockName'];
		$url   = $cover ? wp_get_attachment_url( $id ) : wp_get_attachment_image_url( $id, (string) ( $block['attrs']['sizeSlug'] ?? 'full' ) );
		$tags  = self::processor( $html );
		if ( ! $url || ! $tags->next_tag( 'img' ) ) {
			return $rebuild;
		}
		foreach ( $tags->class_list() as $class ) {
			if ( preg_match( '/^wp-image-\d+$/', $class ) ) {
				$tags->remove_class( $class );
			}
		}
		$tags->add_class( 'wp-image-' . $id );
		$tags->set_attribute( 'src', $url );
		$tags->set_attribute( 'alt', $alt );
		$attrs       = array( 'id' => $id ) + (array) $block['attrs'];
		$attrs['id'] = $id;
		if ( $cover ) {
			// The cover keeps url and alt in the comment; alt is left out at its default, as the editor does.
			$attrs['url'] = $url;
			unset( $attrs['alt'] );
			$attrs += '' !== $alt ? array( 'alt' => $alt ) : array();
		}
		$meta = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
		unset( $meta['leitviaDecorative'] );
		$meta += $decorative ? array( 'leitviaDecorative' => true ) : array();
		unset( $attrs['metadata'] );
		$attrs         += $meta ? array( 'metadata' => $meta ) : array();
		$block['attrs'] = $attrs;
		return array( $block, $tags->get_updated_html() );
	}

	/**
	 * Video block with a video of the media library: the video element as the editor saves it, with controls, preload
	 * none, the URL as src and the poster, and the attachment ID in the comment. The poster is an image of the library,
	 * without one the featured image of the video; a video without either shows none. A block without a video element,
	 * as patterns ship it, gets one at the start of its figure. Playback flags and text tracks of the element stay.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param array<string, mixed> $video   id, poster.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function video( array $block, array $video, array $target, \WP_Error $rebuild ) {
		$id  = (int) ( $video['id'] ?? 0 );
		$src = wp_attachment_is( 'video', $id ) && Scope::readable( $id ) ? (string) wp_get_attachment_url( $id ) : '';
		if ( '' === $src ) {
			return self::invalid( 'video', __( 'The video is not in the media library. Add it with leitvia/add-media first.', 'leitvia-studio' ), $target, 'leitvia/add-media' );
		}
		$image = (int) ( $video['poster'] ?? get_post_thumbnail_id( $id ) );
		if ( isset( $video['poster'] ) && ( ! wp_attachment_is_image( $image ) || ! Scope::readable( $image ) ) ) {
			return self::invalid( 'poster', __( 'The poster must be the ID of an image of the media library.', 'leitvia-studio' ), $target, 'leitvia/list-media' );
		}
		$poster = $image ? (string) wp_get_attachment_url( $image ) : '';
		$html   = (string) ( $block['innerContent'][0] ?? '' );
		$tags   = self::processor( $html );
		$flags  = array();
		$inner  = '';
		if ( $tags->next_tag( 'video' ) ) {
			foreach ( array( 'autoplay', 'loop', 'muted', 'playsinline' ) as $flag ) {
				$flags[ $flag ] = null !== $tags->get_attribute( $flag ) ? ' ' . $flag : '';
			}
			$range = $tags->inner_range();
			if ( ! $range || '</video>' !== substr( $html, $range[0] + $range[1], 8 ) ) {
				return $rebuild;
			}
			$inner = substr( $html, $range[0], $range[1] );
			$from  = $range[2];
			$html  = substr_replace( $html, '', $from, $range[0] + $range[1] + 8 - $from );
		} else {
			$tags  = self::processor( $html );
			$range = $tags->next_tag( 'figure' ) ? $tags->inner_range() : null;
			if ( ! $range ) {
				return $rebuild;
			}
			$from = $range[0];
		}
		// The order of the attributes is the one the editor saves.
		$element                  = '<video' . ( $flags['autoplay'] ?? '' ) . ' controls' . ( $flags['loop'] ?? '' ) . ( $flags['muted'] ?? '' )
			. ( '' !== $poster ? ' poster="' . esc_url( $poster ) . '"' : '' ) . ' preload="none" src="' . esc_url( $src ) . '"' . ( $flags['playsinline'] ?? '' ) . '>' . $inner . '</video>';
		$block['innerContent'][0] = substr_replace( $html, $element, $from, 0 );
		$block['attrs']           = self::place( 'core/video', (array) $block['attrs'], 'id', $id );
		return $block;
	}

	/**
	 * A link sent by an agent or a recipe, sanitized. A root-relative path such as /services/ is relative to the site:
	 * on a site below a path (a subdirectory network, an installation in a folder) it gets that path in front, unless
	 * it starts with it already. Empty when the URL is not valid.
	 *
	 * @param string $url URL.
	 */
	public static function url( string $url ): string {
		$url  = esc_url_raw( trim( $url ) );
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' === $home || ! str_starts_with( $url, '/' ) || str_starts_with( $url, '//' ) || str_starts_with( $url . '/', $home . '/' ) ) {
			return $url;
		}
		return $home . $url;
	}

	/**
	 * Rejection leitvia_invalid_input.
	 *
	 * @param string               $reason       Reason.
	 * @param string               $message      Message with the fix.
	 * @param array<string, mixed> $target       Target.
	 * @param string               $next_ability Next ability.
	 */
	public static function invalid( string $reason, string $message, array $target = array(), string $next_ability = '' ): \WP_Error {
		return Rejection::make( 'leitvia_invalid_input', $message, 422, $reason, $target, $next_ability );
	}

	/**
	 * A tag processor that can tell where the content of the current element lies.
	 *
	 * @param string $html Markup.
	 * @return mixed A WP_HTML_Tag_Processor with inner_range(); an anonymous class has no name to declare.
	 */
	public static function processor( string $html ) {
		return new class( $html ) extends \WP_HTML_Tag_Processor {

			/**
			 * Byte offset and length of everything between the current opener and its closer, and the offset of the
			 * opener, found with bookmarks as core's directives processor does; the cursor ends on the closer.
			 *
			 * @return array{0: int, 1: int, 2: int}|null Null when the cursor is not on an opener or the closer is not
			 *                                            in this markup.
			 */
			public function inner_range(): ?array {
				$tag = $this->get_tag();
				if ( null === $tag || $this->is_tag_closer() || ! $this->set_bookmark( 'leitvia-open' ) ) {
					return null;
				}
				$depth = 1;
				while ( $depth && $this->next_tag(
					array(
						'tag_name'    => $tag,
						'tag_closers' => 'visit',
					)
				) ) {
					$depth += $this->is_tag_closer() ? -1 : 1;
				}
				if ( $depth || ! $this->set_bookmark( 'leitvia-close' ) ) {
					return null;
				}
				$start = $this->bookmarks['leitvia-open']->start + $this->bookmarks['leitvia-open']->length;
				return array( $start, $this->bookmarks['leitvia-close']->start - $start, $this->bookmarks['leitvia-open']->start );
			}
		};
	}
}
