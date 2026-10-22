<?php
/**
 * Table of contents: the headings of the current post as nested links, and the ids those links point to.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list from the parsed blocks of the post, headings inside theme patterns and synced patterns included.
 * Headings without an id get one while the post content renders (render_block_core/heading), only on posts with a
 * table of contents; ids an author set stay. The id comes from the heading text and gets a number when it repeats,
 * so the same content gives the same ids on every request.
 */
final class Table_Of_Contents {

	public const BLOCK = 'leitvia/table-of-contents';

	/**
	 * Post whose content renders right now (core/post-content), 0 outside of it.
	 *
	 * @var int
	 */
	private static int $rendering = 0;

	/**
	 * Ids handed out per heading text during the current content render.
	 *
	 * @var array<string, int>
	 */
	private static array $handed = array();

	/**
	 * Entries per post and levels.
	 *
	 * @var array<string, array<int, array{level: int, text: string, id: string, own: bool}>>
	 */
	private static array $cache = array();

	/**
	 * Renders the block. Fewer than two entries render nothing.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param \WP_Block|null       $block      Block instance.
	 */
	public static function render( array $attributes, ?\WP_Block $block = null ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$levels  = self::levels( $attributes['levels'] ?? array( 2, 3 ) );
		$entries = self::entries( $post_id, $levels );
		if ( count( $entries ) < 2 ) {
			return '';
		}

		$title_id = 'leitvia-toc-title'; // The block allows one instance per post (supports.multiple false).
		$list     = '';
		$stack    = array();
		foreach ( $entries as $entry ) {
			$level = $entry['level'];
			while ( isset( $stack[1] ) && $level < end( $stack ) ) {
				$list .= '</li></ol>';
				array_pop( $stack );
			}
			if ( ! $stack || $level > end( $stack ) ) {
				$list   .= '<ol>';
				$stack[] = $level;
			} else {
				$list .= '</li>';
			}
			$list .= sprintf( '<li><a href="%1$s">%2$s</a>', esc_attr( '#' . $entry['id'] ), esc_html( $entry['text'] ) );
		}
		$list .= str_repeat( '</li></ol>', count( $stack ) );

		return sprintf(
			'<nav %1$s><h2 id="%2$s" class="wp-block-leitvia-table-of-contents__title">%3$s</h2>%4$s</nav>',
			get_block_wrapper_attributes( array( 'aria-labelledby' => $title_id ) ),
			esc_attr( $title_id ),
			esc_html__( 'On this page', 'leitvia-studio' ),
			$list
		);
	}

	/**
	 * Marks the start of a post content render. Runs on render_block_data.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	public static function enter_content( array $block ): array {
		if ( 'core/post-content' === ( $block['blockName'] ?? '' ) ) {
			self::$rendering = (int) get_the_ID();
			self::$handed    = array();
		}
		return $block;
	}

	/**
	 * Marks the end of a post content render. Runs on render_block_core/post-content.
	 *
	 * @param string $content Rendered block HTML.
	 */
	public static function leave_content( string $content ): string {
		self::$rendering = 0;
		return $content;
	}

	/**
	 * Gives a listed heading without an id the id the table of contents links to. Runs on render_block_core/heading.
	 *
	 * @param string               $content Rendered block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function add_id( string $content, array $block ): string {
		$post = 0 === self::$rendering ? null : get_post( self::$rendering );
		if ( ! $post || ! has_block( self::BLOCK, $post ) ) {
			return $content;
		}
		$tags = new \WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag() || null !== $tags->get_attribute( 'id' ) ) {
			return $content;
		}
		$text = self::text( (string) ( $block['innerHTML'] ?? '' ) );
		$seen = self::$handed[ $text ] ?? 0;
		foreach ( self::entries( $post->ID, self::post_levels( $post ) ) as $entry ) {
			if ( ! $entry['own'] && $entry['text'] === $text && 0 === $seen-- ) {
				self::$handed[ $text ] = ( self::$handed[ $text ] ?? 0 ) + 1;
				$tags->set_attribute( 'id', $entry['id'] );
				return $tags->get_updated_html();
			}
		}
		return $content;
	}

	/**
	 * Headings of a post at the given levels, in document order, each with an id: its own or a generated one.
	 * Own ids of headings at every level are reserved, so a generated id never repeats one.
	 *
	 * @param int        $post_id Post ID.
	 * @param array<int> $levels  Heading levels.
	 * @return array<int, array{level: int, text: string, id: string, own: bool}>
	 */
	public static function entries( int $post_id, array $levels ): array {
		$key = $post_id . ':' . implode( ',', $levels );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$post     = get_post( $post_id );
		$headings = array();
		if ( $post ) {
			self::collect( parse_blocks( $post->post_content ), $headings, array() );
		}
		$used    = array_fill_keys( array_filter( array_column( $headings, 'id' ) ), true );
		$entries = array();
		foreach ( $headings as $heading ) {
			if ( ! in_array( $heading['level'], $levels, true ) || '' === $heading['text'] ) {
				continue;
			}
			$own = '' !== $heading['id'];
			$id  = $heading['id'];
			if ( ! $own ) {
				$base = sanitize_title( $heading['text'] );
				$base = ( '' === $base || str_contains( $base, '%' ) ) ? 'section' : $base;
				$id   = $base;
				for ( $n = 2; isset( $used[ $id ] ); $n++ ) {
					$id = $base . '-' . $n;
				}
				$used[ $id ] = true;
			}
			$entries[] = array(
				'level' => $heading['level'],
				'text'  => $heading['text'],
				'id'    => $id,
				'own'   => $own,
			);
		}
		self::$cache[ $key ] = $entries;
		return $entries;
	}

	/**
	 * Forgets the computed entries, for tests and after a post changes within one request.
	 */
	public static function forget(): void {
		self::$cache = array();
	}

	/**
	 * Collects core/heading blocks, walking inner blocks, theme patterns (core/pattern) and synced patterns (core/block).
	 *
	 * @param array<int|string, array<string, mixed>>                 $blocks   Parsed blocks.
	 * @param array<int, array{level: int, text: string, id: string}> $headings Collected headings.
	 * @param array<string, bool>                                     $seen     Patterns on the current path, against loops.
	 */
	private static function collect( array $blocks, array &$headings, array $seen ): void {
		foreach ( $blocks as $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = (array) ( $block['attrs'] ?? array() );
			if ( 'core/heading' === $name ) {
				$html       = (string) ( $block['innerHTML'] ?? '' );
				$tags       = new \WP_HTML_Tag_Processor( $html );
				$id         = $tags->next_tag() ? (string) $tags->get_attribute( 'id' ) : '';
				$headings[] = array(
					'level' => (int) ( $attrs['level'] ?? 2 ),
					'text'  => self::text( $html ),
					'id'    => $id,
				);
			}
			$inner = (array) ( $block['innerBlocks'] ?? array() );
			if ( 'core/pattern' === $name && ! isset( $seen[ 'p:' . ( $attrs['slug'] ?? '' ) ] ) ) {
				$pattern                                 = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) ( $attrs['slug'] ?? '' ) );
				$inner                                   = $pattern ? parse_blocks( (string) ( $pattern['content'] ?? '' ) ) : array();
				$seen[ 'p:' . ( $attrs['slug'] ?? '' ) ] = true;
			} elseif ( 'core/block' === $name && ! isset( $seen[ 'b:' . ( $attrs['ref'] ?? 0 ) ] ) ) {
				$synced                                = get_post( (int) ( $attrs['ref'] ?? 0 ) );
				$inner                                 = ( $synced && 'wp_block' === $synced->post_type && 'publish' === $synced->post_status ) ? parse_blocks( $synced->post_content ) : array();
				$seen[ 'b:' . ( $attrs['ref'] ?? 0 ) ] = true;
			}
			self::collect( $inner, $headings, $seen );
		}
	}

	/**
	 * Plain text of a heading's saved HTML.
	 *
	 * @param string $html Heading HTML.
	 */
	private static function text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Valid heading levels from an attribute value.
	 *
	 * @param mixed $levels Attribute value.
	 * @return array<int>
	 */
	private static function levels( $levels ): array {
		$levels = array_values( array_intersect( array( 2, 3, 4, 5, 6 ), array_map( 'intval', (array) $levels ) ) );
		return $levels ? $levels : array( 2, 3 );
	}

	/**
	 * Levels of the first table of contents in a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int>
	 */
	private static function post_levels( \WP_Post $post ): array {
		$find = static function ( array $blocks ) use ( &$find ) {
			foreach ( $blocks as $block ) {
				if ( self::BLOCK === ( $block['blockName'] ?? '' ) ) {
					return (array) ( $block['attrs']['levels'] ?? array( 2, 3 ) );
				}
				$found = $find( (array) ( $block['innerBlocks'] ?? array() ) );
				if ( null !== $found ) {
					return $found;
				}
			}
			return null;
		};
		return self::levels( $find( parse_blocks( $post->post_content ) ) );
	}
}
