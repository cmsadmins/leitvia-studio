<?php
/**
 * Page structure for agents.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * A post as a compact tree of blocks with IDs and hashes, and single blocks with their full attributes. Templates,
 * template parts, patterns and synced patterns appear as references, never inline.
 */
final class Outline {

	/**
	 * Characters of text per block in the outline.
	 */
	public const TEXT_LENGTH = 80;

	/**
	 * Outline of a post: id, hash, title, status, template, kind and the block tree.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function page( int $post_id ) {
		$post = self::post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return array(
			'id'       => $post->ID,
			'type'     => $post->post_type,
			'hash'     => Hashes::document( $post->ID ),
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'template' => (string) get_page_template_slug( $post ),
			'kind'     => (string) get_post_meta( $post->ID, '_leitvia_page_kind', true ),
			'blocks'   => self::tree( Block_Ids::blocks( parse_blocks( $post->post_content ) ), 'p:' ),
		);
	}

	/**
	 * One block by leitviaId or path ID, with attributes, own markup, hash, child IDs and the document hash.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $id      leitviaId or path ID such as p:0.2.1.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function block( int $post_id, string $id ) {
		$post = self::post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$found = self::find( Block_Ids::blocks( parse_blocks( $post->post_content ) ), $id );
		if ( ! $found ) {
			return Rejection::make(
				'leitvia_not_found',
				__( 'The page has no block with this ID. Read the page outline for the current IDs.', 'leitvia-studio' ),
				404,
				'block',
				array(
					'post'  => $post->ID,
					'block' => $id,
				),
				'leitvia/get-page-outline'
			);
		}
		[ $block, $path ] = $found;
		$children         = array();
		foreach ( Block_Ids::blocks( (array) $block['innerBlocks'] ) as $index => $child ) {
			$children[] = self::key( $child, $path . '.' . $index );
		}
		return array(
			'id'            => self::key( $block, $path ),
			'id_stable'     => '' !== Block_Ids::id( $block ),
			'name'          => self::name( $block ),
			'attributes'    => (object) $block['attrs'],
			'inner_html'    => (string) $block['innerHTML'],
			'attachments'   => self::attachments( (string) $block['innerHTML'] ),
			'hash'          => Hashes::block( $block ),
			'children'      => $children,
			'document_hash' => Hashes::document( $post->ID ),
		);
	}

	/**
	 * A parsed block and its path ID, found by leitviaId or path ID; null when there is none.
	 *
	 * @param list<array<string, mixed>> $blocks Parsed blocks without whitespace, see Block_Ids::blocks().
	 * @param string                     $id     leitviaId or path ID.
	 * @param string                     $path   Path prefix.
	 * @return array{0: array<string, mixed>, 1: string}|null
	 */
	public static function find( array $blocks, string $id, string $path = 'p:' ): ?array {
		foreach ( $blocks as $index => $block ) {
			$here = $path . $index;
			if ( Block_Ids::id( $block ) === $id || $here === $id ) {
				return array( $block, $here );
			}
			$inner = self::find( Block_Ids::blocks( (array) $block['innerBlocks'] ), $id, $here . '.' );
			if ( $inner ) {
				return $inner;
			}
		}
		return null;
	}

	/**
	 * The post when the current user may read it through the gateway, else leitvia_out_of_scope. A missing post gets
	 * the same answer, so private posts cannot be told apart from missing ones.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|\WP_Error
	 */
	private static function post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! Scope::readable( $post ) ) {
			return Rejection::make( 'leitvia_out_of_scope', __( 'Agents may not read this object. Only published or draft content of the allowed post types is open to them.', 'leitvia-studio' ), 403, 'scope', array( 'post' => $post_id ), '', 'never' );
		}
		return $post;
	}

	/**
	 * Tree nodes of a block list.
	 *
	 * @param list<array<string, mixed>> $blocks Parsed blocks without whitespace.
	 * @param string                     $path   Path prefix.
	 * @return list<array<string, mixed>>
	 */
	private static function tree( array $blocks, string $path ): array {
		$nodes = array();
		foreach ( $blocks as $index => $block ) {
			$here       = $path . $index;
			$serialized = serialize_block( $block );
			$attrs      = (array) $block['attrs'];
			$node       = array(
				'id'        => self::key( $block, $here ),
				'id_stable' => '' !== Block_Ids::id( $block ),
				'name'      => self::name( $block ),
				'pattern'   => self::pattern( $block ),
				'text'      => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $block['innerHTML'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ), 0, self::TEXT_LENGTH ),
				'bytes'     => strlen( $serialized ),
				'hash'      => Hashes::of( $serialized ),
				'children'  => self::tree( Block_Ids::blocks( (array) $block['innerBlocks'] ), $here . '.' ),
			);
			$links      = self::links( $block );
			if ( $links ) {
				$node['links'] = $links;
			}
			if ( 'core/template-part' === $block['blockName'] && ! empty( $attrs['slug'] ) ) {
				$node['ref'] = ( $attrs['theme'] ?? get_stylesheet() ) . '//' . $attrs['slug'];
			}
			$nodes[] = $node;
		}
		return $nodes;
	}

	/**
	 * Link targets of a block itself, in order: the url of a navigation link or submenu, and every href in its own
	 * markup. Links of inner blocks belong to those blocks.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return list<string>
	 */
	private static function links( array $block ): array {
		$links = array();
		if ( in_array( $block['blockName'], array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && isset( $block['attrs']['url'] ) ) {
			$links[] = (string) $block['attrs']['url'];
		}
		$tags = new \WP_HTML_Tag_Processor( (string) $block['innerHTML'] );
		while ( $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$href = $tags->get_attribute( 'href' );
			if ( null !== $href ) {
				$links[] = is_string( $href ) ? $href : '';
			}
		}
		return $links;
	}

	/**
	 * Block name; classic content without block delimiters is core/freeform, as the editor shows it.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private static function name( array $block ): string {
		return (string) ( $block['blockName'] ?? 'core/freeform' );
	}

	/**
	 * Images in a block's own markup that are attachments of this site, in order: found by URL, else by the class
	 * wp-image-<id> that the editor and classic content set (sized URLs such as -1024x683 have no direct match).
	 *
	 * @param string $html Markup.
	 * @return list<array{id: int, url: string}>
	 */
	private static function attachments( string $html ): array {
		$found = array();
		$tags  = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
			$url = $tags->get_attribute( 'src' );
			$url = is_string( $url ) ? $url : '';
			$id  = '' !== $url ? attachment_url_to_postid( $url ) : 0;
			if ( ! $id && preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', (string) $tags->get_attribute( 'class' ), $match ) && 'attachment' === get_post_type( (int) $match[1] ) ) {
				$id = (int) $match[1];
			}
			if ( $id ) {
				$found[] = array(
					'id'  => $id,
					'url' => $url,
				);
			}
		}
		return $found;
	}

	/**
	 * The leitviaId of a block, else its path ID.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param string               $path  Path ID.
	 */
	private static function key( array $block, string $path ): string {
		$id = Block_Ids::id( $block );
		return '' !== $id ? $id : $path;
	}

	/**
	 * Pattern the block comes from: metadata.patternName that core sets on insert, else the Lab reference of a synced
	 * pattern (block:<ID>) or a pattern block (pattern:<slug>); null for none.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private static function pattern( array $block ): ?string {
		$attrs = (array) $block['attrs'];
		return match ( true ) {
			! empty( $attrs['metadata']['patternName'] ) => \LeitviaStudio\Lab\Status::of_name( (string) $attrs['metadata']['patternName'] ),
			'core/block' === $block['blockName'] && ! empty( $attrs['ref'] ) => 'block:' . (int) $attrs['ref'],
			'core/pattern' === $block['blockName'] && ! empty( $attrs['slug'] ) => 'pattern:' . $attrs['slug'],
			default => null,
		};
	}
}
