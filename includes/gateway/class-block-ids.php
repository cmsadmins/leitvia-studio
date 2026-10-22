<?php
/**
 * Stable block IDs in metadata.leitviaId.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Gives every block of a post in scope an ID of 8 base36 characters when the post is saved, and a new one to the
 * second of two blocks with the same ID (the editor copies attributes when a block is duplicated). WordPress 7.1 has
 * no block ID of its own. Only the opening delimiters that change are rewritten; the rest of the content stays byte
 * for byte. Reading never writes; a block without ID has a path ID p:0.2.1 until the next save.
 */
final class Block_Ids {

	/**
	 * Opening or closing block delimiter, as WP_Block_Parser reads it.
	 */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Valid ID.
	 */
	private const PATTERN = '/^[0-9a-z]{8}$/';

	/**
	 * Adds missing and resolves duplicate IDs in the content of a post in scope. Patterns (wp_block) are left alone:
	 * their content is copied into pages and theme files, where IDs would only repeat; outlines of patterns use path
	 * IDs. Runs on wp_insert_post_data, priority 20, where the data is slashed.
	 *
	 * @param array<string, mixed> $data    Slashed post data.
	 * @param array<string, mixed> $postarr Slashed post array.
	 * @return array<string, mixed>
	 */
	public static function on_save( array $data, array $postarr = array() ): array {
		$type = (string) ( $data['post_type'] ?? '' );
		if ( 'wp_block' === $type || ! isset( $data['post_content'] ) || ! in_array( $type, Scope::post_types(), true ) ) {
			return $data;
		}
		$content = wp_unslash( (string) $data['post_content'] );
		$updated = self::assign( $content, (int) ( $postarr['ID'] ?? 0 ) );
		if ( $updated !== $content ) {
			$data['post_content'] = wp_slash( $updated );
		}
		return $data;
	}

	/**
	 * Content with an ID on every block. A new ID derives from the post, the block's own markup and how often that
	 * markup came before, so saving the same content again gives the same IDs. The editor keeps its blocks after a
	 * save without the IDs the server added; the next save from that session then finds the same IDs again.
	 * ponytail: a block edited before the editor reloads gets a new ID; assign IDs in the editor when that matters.
	 *
	 * @param string $content Block markup, unslashed.
	 * @param int    $post_id Post ID, 0 for a new post.
	 */
	public static function assign( string $content, int $post_id = 0 ): string {
		if ( ! str_contains( $content, '<!-- wp:' ) ) {
			return $content;
		}
		$blocks = self::flatten( parse_blocks( $content ) );
		$taken  = array();
		foreach ( $blocks as $block ) {
			$id = self::id( $block );
			if ( '' !== $id ) {
				$taken[ $id ] = ( $taken[ $id ] ?? 0 ) + 1;
			}
		}
		$seen  = array();
		$new   = array();
		$count = array();
		foreach ( $blocks as $index => $block ) {
			$id = self::id( $block );
			if ( '' !== $id && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				continue;
			}
			$seed                    = $post_id . '|' . $block['blockName'] . '|' . wp_json_encode( self::without_id( (array) $block['attrs'] ) ) . '|' . implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
			$count[ $seed ]          = ( $count[ $seed ] ?? 0 ) + 1;
			$new[ $index ]           = self::fresh( $seed . '|' . $count[ $seed ], $taken );
			$taken[ $new[ $index ] ] = 1;
		}
		if ( ! $new ) {
			return $content;
		}
		$index = -1;
		return (string) preg_replace_callback(
			self::DELIMITER,
			static function ( array $found ) use ( &$index, $new ): string {
				if ( '' !== $found['closer'] ) {
					return $found[0];
				}
				++$index;
				if ( ! isset( $new[ $index ] ) ) {
					return $found[0];
				}
				$attrs             = '' !== ( $found['attrs'] ?? '' ) ? (array) json_decode( $found['attrs'], true ) : array();
				$meta              = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
				$attrs['metadata'] = array( 'leitviaId' => $new[ $index ] ) + $meta;
				return '<!-- wp:' . $found['namespace'] . $found['name'] . ' ' . serialize_block_attributes( $attrs ) . ' ' . ( '' !== ( $found['void'] ?? '' ) ? '/' : '' ) . '-->';
			},
			$content
		);
	}

	/**
	 * Valid leitviaId of a parsed block, '' when it has none.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	public static function id( array $block ): string {
		$id = $block['attrs']['metadata']['leitviaId'] ?? '';
		return is_string( $id ) && preg_match( self::PATTERN, $id ) ? $id : '';
	}

	/**
	 * Parsed blocks without the whitespace between them, the list paths count in.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @return list<array<string, mixed>>
	 */
	public static function blocks( array $blocks ): array {
		return array_values( array_filter( $blocks, static fn( array $block ): bool => null !== $block['blockName'] || '' !== trim( (string) $block['innerHTML'] ) ) );
	}

	/**
	 * Named blocks in document order (opening delimiters), inner blocks included.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @return list<array<string, mixed>>
	 */
	private static function flatten( array $blocks ): array {
		$flat = array();
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$flat[] = $block;
			array_push( $flat, ...self::flatten( (array) $block['innerBlocks'] ) );
		}
		return $flat;
	}

	/**
	 * Attributes without metadata.leitviaId.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 * @return array<string, mixed>
	 */
	private static function without_id( array $attrs ): array {
		if ( isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ) {
			unset( $attrs['metadata']['leitviaId'] );
		}
		return $attrs;
	}

	/**
	 * An ID from a seed that is not taken yet.
	 *
	 * @param string             $seed  Seed.
	 * @param array<string, int> $taken IDs in use.
	 */
	private static function fresh( string $seed, array $taken ): string {
		do {
			// 40 bits of the hash, 36^8 is a little above 2^41.
			$id    = str_pad( base_convert( substr( hash( 'sha256', $seed ), 0, 10 ), 16, 36 ), 8, '0', STR_PAD_LEFT );
			$seed .= '+';
		} while ( isset( $taken[ $id ] ) );
		return $id;
	}
}
