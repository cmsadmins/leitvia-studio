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
 *
 * In a post split into parts (core/nextpage) every entry knows its part: entries of another part link to the address
 * of that part plus the anchor (anchor_url(), also for add-ons), scope all groups them by part, scope part lists the
 * current part alone. A table of contents in one part shows at the top of the parts without one.
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
	 * @var array<string, array<int, array{level: int, text: string, id: string, own: bool, part: int}>>
	 */
	private static array $cache = array();

	/**
	 * Renders the block. Fewer than two entries render nothing. Display companion adds the chapter button, see companion().
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param \WP_Block|null       $block      Block instance.
	 */
	public static function render( array $attributes, ?\WP_Block $block = null ): string {
		$post = get_post( (int) ( $block->context['postId'] ?? get_the_ID() ) );
		if ( ! $post ) {
			return '';
		}
		$entries = self::entries( $post->ID, self::levels( $attributes['levels'] ?? array( 2, 3 ) ) );
		$parts   = count( self::pages( $post ) );
		$current = self::current_part( $post );
		$grouped = $parts > 1 && 'part' !== ( $attributes['scope'] ?? 'all' );
		if ( $parts > 1 && ! $grouped ) {
			$entries = wp_list_filter( $entries, array( 'part' => max( 1, $current ) ) );
		}
		if ( count( $entries ) < 2 ) {
			return '';
		}

		$list = $grouped ? '<ol>' : self::nested( $post, $entries );
		for ( $part = 1; $grouped && $part <= $parts; $part++ ) {
			$list .= sprintf(
				'<li><a href="%1$s"%2$s>%3$s</a>%4$s</li>',
				esc_url( self::part_url( $post, $part ) ),
				$part === $current ? ' aria-current="page"' : '',
				/* translators: %d: number of the part of a post split into pages. */
				esc_html( sprintf( __( 'Part %d', 'leitvia-studio' ), $part ) ),
				self::nested( $post, wp_list_filter( $entries, array( 'part' => $part ) ) )
			);
		}
		$list .= $grouped ? '</ol>' : '';

		$title_id  = 'leitvia-toc-title'; // One instance per page: supports.multiple false, and one part renders at a time.
		$companion = 'companion' === ( $attributes['display'] ?? 'inline' );
		return sprintf(
			'<nav %1$s><h2 id="%2$s" class="wp-block-leitvia-table-of-contents__title">%3$s</h2>%4$s%5$s</nav>',
			get_block_wrapper_attributes(
				array(
					'aria-labelledby' => $title_id,
					'class'           => $companion ? 'is-display-companion' : '',
				)
			),
			esc_attr( $title_id ),
			esc_html__( 'On this page', 'leitvia-studio' ),
			$list,
			$companion ? self::companion( $list ) : ''
		);
	}

	/**
	 * Entries as nested lists by level, empty without entries.
	 *
	 * @param \WP_Post                                                                      $post    Post.
	 * @param array<int, array{level: int, text: string, id: string, own: bool, part: int}> $entries Entries.
	 */
	private static function nested( \WP_Post $post, array $entries ): string {
		$list  = '';
		$stack = array();
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
			$url   = self::link( $post, $entry['part'], $entry['id'] );
			$list .= sprintf( '<li><a href="%1$s">%2$s</a>', '#' === $url[0] ? esc_attr( $url ) : esc_url( $url ), esc_html( $entry['text'] ) );
		}
		return $list . str_repeat( '</li></ol>', count( $stack ) );
	}

	/**
	 * Address of an anchor in a post: #anchor when it lies in the part being viewed or the post has no parts,
	 * otherwise the address of its part (as wp_link_pages() links it) plus #anchor. Knows the ids the table of contents
	 * gives headings and the ids saved in the content; an unknown anchor stays #anchor. For add-ons that link into
	 * posts with parts, for example the stops of a route. The result is not escaped.
	 *
	 * @param \WP_Post|int $post   Post or post ID.
	 * @param string       $anchor Id of the target, with or without #.
	 */
	public static function anchor_url( $post, string $anchor ): string {
		$post   = get_post( $post );
		$anchor = ltrim( $anchor, '#' );
		if ( ! $post || '' === $anchor ) {
			return '#' . $anchor;
		}
		$part = 0;
		foreach ( self::entries( $post->ID, self::post_levels( $post ) ) as $entry ) {
			if ( $entry['id'] === $anchor ) {
				$part = $entry['part'];
				break;
			}
		}
		foreach ( 0 === $part ? self::pages( $post ) : array() as $i => $page ) {
			if ( str_contains( $page, 'id="' . esc_attr( $anchor ) . '"' ) ) {
				$part = $i + 1;
				break;
			}
		}
		return 0 === $part ? '#' . $anchor : self::link( $post, $part, $anchor );
	}

	/**
	 * Address of an anchor in a known part.
	 *
	 * @param \WP_Post $post   Post.
	 * @param int      $part   Part, from 1.
	 * @param string   $anchor Id of the target.
	 */
	private static function link( \WP_Post $post, int $part, string $anchor ): string {
		return ( self::current_part( $post ) === $part || count( self::pages( $post ) ) < 2 ) ? '#' . $anchor : self::part_url( $post, $part ) . '#' . $anchor;
	}

	/**
	 * Address of a part as wp_link_pages() links it: plain and pretty permalinks, front page, previews.
	 *
	 * @param \WP_Post $post Post.
	 * @param int      $part Part, from 1.
	 */
	private static function part_url( \WP_Post $post, int $part ): string {
		$saved           = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- _wp_link_page() reads the global post; restored below.
		$tags            = new \WP_HTML_Tag_Processor( _wp_link_page( $part ) );
		$url             = $tags->next_tag() ? (string) $tags->get_attribute( 'href' ) : (string) get_permalink( $post );
		$GLOBALS['post'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the global post.
		return $url;
	}

	/**
	 * Part of the post being viewed, 0 while another post is viewed.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function current_part( \WP_Post $post ): int {
		return get_queried_object_id() === $post->ID ? min( max( 1, (int) get_query_var( 'page' ) ), count( self::pages( $post ) ) ) : 0;
	}

	/**
	 * Content of the parts of a post, split as generate_postdata() splits it.
	 *
	 * @param \WP_Post $post Post.
	 * @return list<string>
	 */
	private static function pages( \WP_Post $post ): array {
		$content = str_replace( array( "\n<!--nextpage-->\n", "\n<!--nextpage-->", "<!--nextpage-->\n" ), '<!--nextpage-->', $post->post_content );
		$content = str_replace( array( '<!-- wp:nextpage -->', '<!-- /wp:nextpage -->' ), '', $content );
		$content = str_starts_with( $content, '<!--nextpage-->' ) ? substr( $content, 15 ) : $content;
		return explode( '<!--nextpage-->', $content );
	}

	/**
	 * The companion of display companion: a button that stays at the bottom edge while the list is out of view, with
	 * the section being read and the reading progress, and a modal dialog with the same list. Hidden until view.js
	 * takes it over, so without JavaScript only the list in the flow remains.
	 *
	 * @param string $links The list of links.
	 */
	private static function companion( string $links ): string {
		return sprintf(
			'<div class="wp-block-leitvia-table-of-contents__companion" hidden data-count="%1$s">'
			. '<button type="button" class="wp-block-leitvia-table-of-contents__open" aria-haspopup="dialog">'
			. '<span class="wp-block-leitvia-table-of-contents__label">%2$s</span>'
			. ' <span class="wp-block-leitvia-table-of-contents__current"></span>'
			. ' <span class="wp-block-leitvia-table-of-contents__count"></span>'
			. '</button>'
			. '<span class="wp-block-leitvia-table-of-contents__progress" role="progressbar" aria-label="%3$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></span>'
			. '<dialog class="wp-block-leitvia-table-of-contents__dialog" aria-labelledby="%7$s">'
			. '<div class="wp-block-leitvia-table-of-contents__sheet">'
			. '<h2 id="%7$s" class="wp-block-leitvia-table-of-contents__title">%4$s</h2>%5$s'
			. '<button type="button" class="wp-block-leitvia-table-of-contents__close">%6$s</button>'
			. '</div></dialog></div>',
			/* translators: 1: number of the section being read, 2: number of sections. Keep %1$d and %2$d. */
			esc_attr__( 'Section %1$d of %2$d', 'leitvia-studio' ),
			esc_html__( 'Chapters', 'leitvia-studio' ),
			esc_attr__( 'Reading progress', 'leitvia-studio' ),
			esc_html__( 'On this page', 'leitvia-studio' ),
			$links,
			esc_html__( 'Close', 'leitvia-studio' ),
			esc_attr( wp_unique_id( 'leitvia-toc-dialog-' ) )
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
	 * Marks the end of a post content render. Runs on render_block_core/post-content. In a post with parts, a part
	 * without a table of contents gets the one of another part at its top, so every part has the list.
	 *
	 * @param string $content Rendered block HTML.
	 */
	public static function leave_content( string $content ): string {
		$post            = 0 === self::$rendering ? null : get_post( self::$rendering );
		self::$rendering = 0;
		if ( ! $post ) {
			return $content;
		}
		$pages = self::pages( $post );
		$block = isset( $pages[1] ) && ! str_contains( $pages[ self::rendered_part( $pages ) - 1 ], '<!-- wp:' . self::BLOCK ) ? self::find( parse_blocks( $post->post_content ) ) : null;
		$at    = strpos( $content, '>' );
		return null === $block || false === $at ? $content : substr_replace( $content, render_block( $block ), $at + 1, 0 );
	}

	/**
	 * Part that renders now, as get_the_content() picks it from the global page.
	 *
	 * @param array<int, string> $pages Parts of the post.
	 */
	private static function rendered_part( array $pages ): int {
		return min( max( 1, (int) ( $GLOBALS['page'] ?? 1 ) ), max( 1, count( $pages ) ) );
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
		$part = self::rendered_part( self::pages( $post ) );
		foreach ( self::entries( $post->ID, self::post_levels( $post ) ) as $entry ) {
			if ( $entry['part'] === $part && ! $entry['own'] && $entry['text'] === $text && 0 === $seen-- ) {
				self::$handed[ $text ] = ( self::$handed[ $text ] ?? 0 ) + 1;
				$tags->set_attribute( 'id', $entry['id'] );
				return $tags->get_updated_html();
			}
		}
		return $content;
	}

	/**
	 * Headings of a post at the given levels, in document order, each with an id (its own or a generated one) and its
	 * part (1 without core/nextpage). Own ids of headings at every level are reserved, so a generated id never repeats
	 * one; generated ids are unique across all parts.
	 *
	 * @param int        $post_id Post ID.
	 * @param array<int> $levels  Heading levels.
	 * @return array<int, array{level: int, text: string, id: string, own: bool, part: int}>
	 */
	public static function entries( int $post_id, array $levels ): array {
		$key = $post_id . ':' . implode( ',', $levels );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$post     = get_post( $post_id );
		$headings = array();
		foreach ( $post ? self::pages( $post ) : array() as $i => $page ) {
			self::collect( parse_blocks( $page ), $headings, array(), $i + 1 );
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
				'part'  => $heading['part'],
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
	 * @param array<int|string, array<string, mixed>>                            $blocks   Parsed blocks.
	 * @param array<int, array{level: int, text: string, id: string, part: int}> $headings Collected headings.
	 * @param array<string, bool>                                                $seen     Patterns on the current path, against loops.
	 * @param int                                                                $part     Part of the post the blocks are in.
	 */
	private static function collect( array $blocks, array &$headings, array $seen, int $part ): void {
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
					'part'  => $part,
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
			self::collect( $inner, $headings, $seen, $part );
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
		return self::levels( self::find( parse_blocks( $post->post_content ) )['attrs']['levels'] ?? null );
	}

	/**
	 * The first table of contents among parsed blocks, inner blocks included.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null
	 */
	private static function find( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			$found = self::BLOCK === ( $block['blockName'] ?? '' ) ? $block : self::find( (array) ( $block['innerBlocks'] ?? array() ) );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}
}
