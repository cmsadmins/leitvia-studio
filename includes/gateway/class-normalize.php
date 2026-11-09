<?php
/**
 * Markup repairs of the pipeline.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Blocks\Catalog;
use LeitviaStudio\Blocks\Table_Of_Contents;

defined( 'ABSPATH' ) || exit;

/**
 * Repairs of block markup that agents often get wrong, made before the checks so the stored and the queued content are
 * the repaired one (a package shows it as it will land):
 * - core/columns with verticalAlignment passes it to its columns without a value of their own; core sets
 *   align-items: normal on the columns, so only the alignment of each column takes effect.
 * - A block of an add-on whose inner blocks the saved HTML of its catalog example wraps in more elements than the
 *   written block (the box of a scroll gallery) gets the missing wrappers from the example. The catalog example of a
 *   block is its save output for this purpose; a block without one stays as written.
 * - In a post split into parts (core/nextpage) a link to #anchor whose anchor lies in another part leads nowhere; it
 *   gets the path of that part plus the anchor, see part_address(). Only a published post with pretty permalinks has
 *   a lasting address for its parts; a draft or plain permalinks (?p=1&page=2) keep the link, and the check
 *   leitvia/anchors warns.
 */
final class Normalize {

	/**
	 * Content with the repairs; the content as it was, byte for byte, when nothing needed one. Only blocks the write
	 * changes are repaired: a block whose own markup and attributes the content before holds (see Pipeline::own_part())
	 * stays as it is, and a top-level block without a repair keeps its markup byte for byte, so a stored block written by a
	 * person is not rewritten by a write elsewhere in the post.
	 *
	 * @param string $content Block markup.
	 * @param string $before  Content before the write, '' for none.
	 * @param int    $post    Post the content belongs to, 0 for a new one (its parts have no address yet).
	 */
	public static function markup( string $content, string $before = '', int $post = 0 ): string {
		if ( ! has_blocks( $content ) ) {
			return $content;
		}
		$known   = '' !== $before && has_blocks( $before ) ? array_fill_keys( Pipeline::own_parts( parse_blocks( $before ) ), true ) : array();
		$hulls   = self::hulls();
		$changed = false;
		$out     = '';
		$part    = 1;
		$target  = self::parts_post( $content, $post );
		foreach ( self::top_level( $content ) as [ $piece, $is_block ] ) {
			$block   = $is_block ? parse_blocks( $piece )[0] ?? null : null;
			$anchors = $target ? self::anchors( $target, $part ) : null;
			if ( is_array( $block ) && self::block( $block, $hulls, $known, $anchors ) ) {
				$piece   = serialize_block( $block );
				$changed = true;
			}
			$part += substr_count( $piece, '<!--nextpage-->' );
			$out  .= $piece;
		}
		return $changed ? $out : $content;
	}

	/**
	 * The content cut into its top-level blocks and the text between them, as written.
	 *
	 * @param string $content Block markup.
	 * @return list<array{0: string, 1: bool}> Piece and whether it is a block.
	 */
	private static function top_level( string $content ): array {
		$parser           = new \WP_Block_Parser();
		$parser->document = $content;
		$parser->offset   = 0;
		$pieces           = array();
		$depth            = 0;
		$start            = 0;
		$text             = 0;
		while ( true ) {
			[ $type, , , $at, $length ] = $parser->next_token();
			if ( 'no-more-tokens' === $type ) {
				break;
			}
			$parser->offset = $at + $length;
			if ( 0 === $depth && 'block-closer' === $type ) {
				continue; // A closer without opener stays in the text around it.
			}
			if ( 0 === $depth ) {
				$pieces[] = array( substr( $content, $text, $at - $text ), false );
				$start    = $at;
			}
			$depth += match ( $type ) {
				'block-opener' => 1,
				'block-closer' => -1,
				default        => 0,
			};
			if ( 0 === $depth ) {
				$pieces[] = array( substr( $content, $start, $parser->offset - $start ), true );
				$text     = $parser->offset;
			}
		}
		$pieces[] = array( substr( $content, $text ), false );
		return $pieces;
	}

	/**
	 * Repairs one parsed block and its children in place.
	 *
	 * @param array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<int, mixed>, innerHTML: string, innerContent: array<int, string|null>} $block   Parsed block.
	 * @param array<string, list<array{name: string, tag: string}>>                                                                                                $hulls   Wrappers by block name, see hulls().
	 * @param array<string, true>                                                                                                                                  $known   Keys of stored blocks, which stay as they are.
	 * @param (callable(string): string)|null                                                                                                                      $anchors Rewrites the anchor links of a piece of HTML, see anchors().
	 * @return bool Whether anything changed.
	 */
	private static function block( array &$block, array $hulls, array $known = array(), ?callable $anchors = null ): bool {
		$changed = false;
		$stored  = isset( $known[ Pipeline::own_part( $block ) ] );
		foreach ( $block['innerContent'] as $i => $chunk ) {
			$fixed = is_string( $chunk ) && ! $stored && null !== $anchors ? $anchors( $chunk ) : $chunk;
			if ( is_string( $fixed ) && $fixed !== $chunk ) {
				$block['innerContent'][ $i ] = $fixed;
				$changed                     = true;
			}
		}
		$align = 'core/columns' === $block['blockName'] && ! $stored ? (string) ( $block['attrs']['verticalAlignment'] ?? '' ) : '';
		foreach ( $block['innerBlocks'] as &$inner ) {
			if ( '' !== $align && 'core/column' === $inner['blockName'] && ! isset( $inner['attrs']['verticalAlignment'] ) ) {
				$inner['attrs']['verticalAlignment'] = $align;
				$inner['innerContent'][0]            = self::add_class( (string) ( $inner['innerContent'][0] ?? '' ), 'is-vertically-aligned-' . $align );
				$changed                             = true;
			}
			$changed = self::block( $inner, $hulls, $known, $anchors ) || $changed;
		}
		unset( $inner );
		return ( ! $stored && self::hull( $block, $hulls ) ) || $changed;
	}

	/**
	 * The post with the content of the write, when the content has parts and anchor links and the post an address;
	 * null otherwise.
	 *
	 * @param string $content Block markup.
	 * @param int    $post    Post ID, 0 for none.
	 */
	public static function parts_post( string $content, int $post ): ?\WP_Post {
		$saved = $post > 0 && str_contains( $content, '<!--nextpage-->' ) && str_contains( $content, 'href="#' ) ? get_post( $post ) : null;
		if ( ! $saved ) {
			return null;
		}
		$target               = clone $saved;
		$target->post_content = $content;
		return $target;
	}

	/**
	 * Lasting address of an anchor in another part of a post: the path of the part plus #anchor, without the host, so
	 * it survives a move of the site; '' while the post is not published or the site has plain permalinks, where the
	 * address of a part is a query (?p=1&page=2) that changes with the next permalink setting.
	 *
	 * @param \WP_Post $post   Post with the content of the write.
	 * @param string   $anchor Id of the target, with or without #.
	 */
	public static function part_address( \WP_Post $post, string $anchor ): string {
		if ( 'publish' !== $post->post_status || '' === (string) get_option( 'permalink_structure' ) ) {
			return '';
		}
		$url = Table_Of_Contents::anchor_url( $post, $anchor );
		return str_starts_with( $url, '#' ) ? '' : wp_make_link_relative( $url );
	}

	/**
	 * Rewrites the links to #anchor of a piece of HTML in a part whose anchor lies in another part.
	 *
	 * @param \WP_Post $post Post with the content of the write.
	 * @param int      $part Part the HTML is in, from 1.
	 * @return callable(string): string
	 */
	private static function anchors( \WP_Post $post, int $part ): callable {
		return static function ( string $html ) use ( $post, $part ): string {
			if ( ! str_contains( $html, 'href="#' ) ) {
				return $html;
			}
			$tags    = new \WP_HTML_Tag_Processor( $html );
			$changed = false;
			while ( $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
				$href  = (string) $tags->get_attribute( 'href' );
				$found = str_starts_with( $href, '#' ) ? Table_Of_Contents::anchor_part( $post, $href ) : 0;
				$url   = $found > 0 && $found !== $part ? self::part_address( $post, $href ) : '';
				if ( '' !== $url ) {
					$tags->set_attribute( 'href', $url );
					$changed = true;
				}
			}
			return $changed ? $tags->get_updated_html() : $html;
		};
	}

	/**
	 * Wraps the inner blocks in the wrappers the catalog example has and the block lacks.
	 *
	 * @param array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<int, mixed>, innerHTML: string, innerContent: array<int, string|null>} $block Parsed block.
	 * @param array<string, list<array{name: string, tag: string}>>                                                                                                $hulls Wrappers by block name, see hulls().
	 */
	private static function hull( array &$block, array $hulls ): bool {
		$want = $hulls[ (string) $block['blockName'] ] ?? null;
		$last = count( $block['innerContent'] ) - 1;
		if ( ! $want || ! $block['innerBlocks'] || $last < 1 || ! is_string( $block['innerContent'][0] ) || ! is_string( $block['innerContent'][ $last ] ) ) {
			return false;
		}
		// Only inner blocks one after the other, nothing but white space between them.
		foreach ( array_slice( $block['innerContent'], 1, -1 ) as $chunk ) {
			if ( null !== $chunk && '' !== trim( $chunk ) ) {
				return false;
			}
		}
		$have = self::open_tags( $block['innerContent'][0] );
		if ( ! $have || count( $have ) >= count( $want ) || array_column( $have, 'name' ) !== array_slice( array_column( $want, 'name' ), 0, count( $have ) ) ) {
			return false;
		}
		$missing                        = array_slice( $want, count( $have ) );
		$block['innerContent'][0]      .= implode( '', array_column( $missing, 'tag' ) );
		$block['innerContent'][ $last ] = implode( '', array_map( static fn( array $tag ): string => '</' . $tag['name'] . '>', array_reverse( $missing ) ) ) . $block['innerContent'][ $last ];
		return true;
	}

	/**
	 * The wrappers of the inner blocks in the catalog examples of add-on blocks, by block name; only blocks whose
	 * example has inner blocks.
	 *
	 * @return array<string, list<array{name: string, tag: string}>>
	 */
	private static function hulls(): array {
		$hulls = array();
		foreach ( Catalog::get() as $entry ) {
			if ( 'core' === ( $entry['owner'] ?? 'core' ) || ! is_string( $entry['example'] ?? null ) ) {
				continue;
			}
			foreach ( parse_blocks( $entry['example'] ) as $example ) {
				$opening = $entry['name'] === $example['blockName'] && $example['innerBlocks'] ? $example['innerContent'][0] ?? null : null;
				if ( ! is_string( $opening ) ) {
					continue;
				}
				// Only a hull of nothing but opening tags whose inner wrappers are elements of the block, such as
				// wp-block-<namespace>-<name>__box; a hull with text or closed elements (a heading, a fieldset)
				// cannot be repaired by adding tags.
				$tags    = self::open_tags( $opening );
				$element = 'wp-block-' . str_replace( '/', '-', (string) $entry['name'] ) . '__';
				$inner   = array_slice( $tags, 1 );
				$pure    = '' === trim( (string) preg_replace( '#<[^>]*>#', '', $opening ) ) && preg_match_all( '#<[a-z]#i', $opening ) === count( $tags );
				if ( $pure && $inner && count( array_filter( $inner, static fn( array $tag ): bool => str_contains( $tag['tag'], $element ) ) ) === count( $inner ) ) {
					$hulls[ $entry['name'] ] = $tags;
				}
				break;
			}
		}
		return $hulls;
	}

	/**
	 * The elements a piece of HTML leaves open, outermost first, with tag name and the opening tag as written.
	 *
	 * @param string $html Opening part of a block.
	 * @return list<array{name: string, tag: string}>
	 */
	private static function open_tags( string $html ): array {
		$void  = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );
		$stack = array();
		preg_match_all( '#<(/?)([a-z][a-z0-9-]*)\b[^>]*>#i', $html, $tags, PREG_SET_ORDER );
		foreach ( $tags as [ $tag, $closing, $name ] ) {
			$name = strtolower( $name );
			if ( '' !== $closing ) {
				array_pop( $stack );
			} elseif ( ! in_array( $name, $void, true ) && ! str_ends_with( $tag, '/>' ) ) {
				$stack[] = array(
					'name' => $name,
					'tag'  => $tag,
				);
			}
		}
		return $stack;
	}

	/**
	 * HTML with a class added to its first tag.
	 *
	 * @param string $html       HTML.
	 * @param string $class_name Class.
	 */
	private static function add_class( string $html, string $class_name ): string {
		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag() ) {
			return $html;
		}
		$tags->add_class( $class_name );
		return $tags->get_updated_html();
	}
}
