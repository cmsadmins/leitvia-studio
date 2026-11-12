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
	 * Blocks whose text can change, with the query for the element that holds the text.
	 */
	public const TEXT_BLOCKS = array(
		'core/paragraph' => array(),
		'core/heading'   => array(),
		'core/list-item' => array(),
		'core/button'    => array( 'class_name' => 'wp-block-button__link' ),
		'core/quote'     => array( 'tag_name' => 'cite' ),
		'core/details'   => array( 'tag_name' => 'summary' ),
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
	 * Blocks whose image can change.
	 */
	public const IMAGE_BLOCKS = array( 'core/image', 'core/cover' );

	/**
	 * Attributes of core blocks outside the block catalog that may change, as JSON schema; their markup is rebuilt
	 * from the new value. The level of a heading is the tag name of its element, the tracks of a video are its track
	 * elements. A query keeps its markup: its settings live in the block comment, and query merges into what is set.
	 */
	public const CORE_ATTRIBUTES = array(
		'core/heading' => array(
			'level' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 6,
			),
		),
		'core/query'   => array(
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
		'core/video'   => array(
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
	 * them, size, crop, link and lightbox on an image as well.
	 */
	public const MEDIA_BLOCKS = array( 'core/image', 'core/gallery', 'core/cover', 'core/media-text' );

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
		return $error ?? serialize_blocks( $blocks );
	}

	/**
	 * Content with one block removed from its parent or repeated right after itself, for sections with fewer or more
	 * entries than the pattern. IDs of the blocks after it shift, so edits in one list build on each other: work from
	 * the last block to the first. Copies lose their leitviaId; core gives them a new one on save.
	 *
	 * @param string               $content Block markup.
	 * @param string               $id      leitviaId or path ID.
	 * @param array<string, mixed> $change  remove (true) or repeat (number of copies).
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
		$index  = (int) substr( $path, $dot + 1 );
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
		return $error ?? serialize_blocks( $blocks );
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
		$gap = static fn( $chunk, int $key ): bool => is_string( $chunk ) && '' === trim( $chunk ) && $key > 0 && $key < count( $content ) - 1;
		if ( empty( $change['remove'] ) ) {
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
		if ( isset( $change['attributes'] ) ) {
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
			if ( '' === $url ) {
				return self::invalid( 'url', __( 'The link is not a valid URL.', 'leitvia-studio' ), $target );
			}
			$tags->set_attribute( 'href', $url );
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
		$text     = array(
			'core/button'  => 'text',
			'core/quote'   => 'citation',
			'core/details' => 'summary',
		)[ (string) $block['blockName'] ] ?? 'content';
		$touched  = array_keys( (array) ( $change['attributes'] ?? array() ) );
		$touched += isset( $change['text'] ) ? array( 'text' => $text ) : array();
		$touched += isset( $change['url'] ) && 'core/button' === $block['blockName'] ? array( 'url' => 'url' ) : array();
		$touched += isset( $change['caption'] ) ? array( 'caption' => 'caption' ) : array();
		$touched  = array_merge( array_values( $touched ), isset( $change['image'] ) ? array( 'id', 'url', 'alt' ) : array() );
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
				/* translators: 1: attribute, for example content; 2: binding source, for example leitvia/meta; 3: field key */
				sprintf( __( 'The attribute %1$s of this block shows "%3$s" from %2$s, so visitors would not see the change. Change the value there.', 'leitvia-studio' ), $attribute, $source, $key ),
				$target,
				'leitvia/setting' === $source ? 'leitvia/update-site-values' : ( str_starts_with( $source, 'leitvia/' ) || 'core/post-meta' === $source ? 'leitvia/update-content' : '' )
			);
		}
		return null;
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
		$found = self::element( $block, self::TEXT_BLOCKS[ (string) $block['blockName'] ] ?? null );
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
	 * New content of a text element: the escaped text, with its one link around the linked part; the opening tag of the
	 * link stays as it was, only href changes with url. Plain text may replace a sample link to "#".
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
			return isset( $change['url'] ) ? $rebuild : esc_html( $text );
		}
		if ( 1 !== $links || '' === $open ) {
			return $rebuild;
		}
		$label = (string) ( $change['link_text'] ?? $old );
		$at    = '' !== $label ? strpos( $text, $label ) : false;
		$href  = new \WP_HTML_Tag_Processor( $open );
		$href->next_tag();
		// A sample link to "#" leads nowhere; plain text may replace it.
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
	 * classes on render, the saved markup stays (animation()). Anything else would leave the saved markup behind.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param array<string, mixed> $attrs  New attribute values.
	 * @param array<string, mixed> $target Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function attributes( array $block, array $attrs, array $target, \WP_Error $rebuild ) {
		$name = (string) $block['blockName'];
		if ( $attrs && ! array_diff_key( $attrs, array_flip( array( 'leitviaAnimation', 'leitviaMotion' ) ) ) ) {
			return self::animation( $block, $attrs, $target );
		}
		if ( in_array( $name, self::MEDIA_BLOCKS, true ) ) {
			return self::media( $block, $attrs, $target, $rebuild );
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
				if ( ! in_array( $schema['source'], array( 'text', 'rich-text', 'html' ), true ) || ! is_string( $value ) ) {
					return $rebuild;
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
				default                   => $block,
			};
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
				'pattern' => '^(is-style-[a-z0-9-]+)?$',
			),
		);
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
		$style = (string) ( $attrs['className'] ?? '' );
		if ( '' !== $style && ! \WP_Block_Styles_Registry::get_instance()->is_registered( $name, substr( $style, 9 ) ) ) {
			/* translators: %s: class of a block style. */
			return self::invalid( 'attributes', sprintf( __( 'The block has no block style %s.', 'leitvia-studio' ), $style ), $target, 'leitvia/list-blocks' );
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
			$classes = (array) preg_split( '/\s+/', trim( (string) ( $block['attrs']['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( array_merge( $classes, iterator_to_array( $tags->class_list(), false ) ) as $class ) {
				if ( str_starts_with( (string) $class, 'is-style-' ) ) {
					$tags->remove_class( (string) $class );
				}
			}
			$kept = array_values( array_filter( $classes, static fn( $name ): bool => ! str_starts_with( (string) $name, 'is-style-' ) ) );
			if ( '' !== $style ) {
				$tags->add_class( $style );
				$kept[] = $style;
			}
			$new['className'] = implode( ' ', $kept );
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
		// remove_attribute() leaves the space before it (<img >), which KSES would close up.
		$html = (string) preg_replace( '#<([a-z][^\s/>]*)\s+(/?)>#i', '<$1$2>', $tags->get_updated_html() );
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
		unset( $block['attrs']['tracks'] );
		$block['attrs'] += $stored ? array( 'tracks' => $stored ) : array();
		return $block;
	}

	/**
	 * Block with new text attributes in their elements. The counter rebuilds its number, whose parts come and go; any
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
		$types = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $block['blockName'] )->attributes ?? array();
		foreach ( $texts as $key => $text ) {
			$selector = (string) ( $types[ $key ]['selector'] ?? '' );
			$query    = match ( true ) {
				(bool) preg_match( '/^\.[\w-]+$/', $selector ) => array( 'class_name' => substr( $selector, 1 ) ),
				(bool) preg_match( '/^[a-z][a-z0-9]*$/', $selector ) => array( 'tag_name' => $selector ),
				default => null,
			};
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
	 * Block with a new effect on scroll: leitviaAnimation (none removes it) and leitviaMotion (null removes it) in the
	 * block comment, in the order the editor saves them; the markup stays as it is.
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
			if ( 'leitviaMotion' === $key && null === $value ) {
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
		$alt        = $decorative ? '' : trim( (string) ( $image['alt'] ?? '' ) );
		if ( ! wp_attachment_is_image( $id ) || ! Scope::readable( $id ) ) {
			return self::invalid( 'image', __( 'The image is not in the media library. Add it with leitvia/add-media first.', 'leitvia-studio' ), $target, 'leitvia/add-media' );
		}
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
	private static function processor( string $html ) {
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
