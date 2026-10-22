<?php
/**
 * Own and effective lab status of patterns.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Lab;

defined( 'ABSPATH' ) || exit;

/**
 * A pattern is at most as mature as its least mature part. References are "block:<ID>" for wp_block posts and
 * "pattern:<slug>" for registered patterns.
 */
final class Status {

	/**
	 * Maturity per status. A deprecated part makes its users count as draft.
	 */
	public const RANK = array(
		'draft'      => 0,
		'review'     => 1,
		'approved'   => 2,
		'deprecated' => 0,
	);

	/**
	 * Effective status per reference, for the current request.
	 *
	 * @var array<string, string>
	 */
	private static array $cache = array();

	/**
	 * Own status, without the parts. Theme patterns are approved unless the contract names a replacement.
	 *
	 * @param string $ref Pattern reference.
	 */
	public static function own( string $ref ): string {
		if ( str_starts_with( $ref, 'pattern:' ) ) {
			$pattern = leitvia_studio_contract()->pattern( substr( $ref, 8 ) );
			return empty( $pattern['docs']['replacedBy'] ) ? 'approved' : 'deprecated';
		}
		$id     = self::block_id( $ref );
		$status = $id ? (string) get_post_meta( $id, '_leitvia_lab_status', true ) : '';
		return isset( self::RANK[ $status ] ) ? $status : 'draft';
	}

	/**
	 * Effective status: the own status, lowered to the least mature part found anywhere below it.
	 *
	 * @param string $ref Pattern reference.
	 */
	public static function effective( string $ref ): string {
		if ( ! isset( self::$cache[ $ref ] ) ) {
			$seen                = array();
			self::$cache[ $ref ] = self::resolve( $ref, $seen );
		}
		return self::$cache[ $ref ];
	}

	/**
	 * Patterns used directly in the pattern, without duplicates.
	 *
	 * WordPress 7.1.3 sets metadata.patternName (plus metadata.name and metadata.categories) on the root block when a
	 * registered pattern with exactly one root block is inserted (checked in the editor on 2026-10-17 with
	 * leitvia/hero-split-image). Patterns with several root blocks and older content carry no name and are not found.
	 *
	 * @param string $ref Pattern reference.
	 * @return list<string>
	 */
	public static function parts( string $ref ): array {
		return array_values( array_diff( self::refs( self::content( $ref ) ), array( $ref ) ) );
	}

	/**
	 * Patterns used directly in block markup, without duplicates. See parts() for what is found.
	 *
	 * @param string $content Block markup.
	 * @return list<string>
	 */
	public static function refs( string $content ): array {
		return array_values( array_unique( self::collect( parse_blocks( $content ) ) ) );
	}

	/**
	 * Reference of a metadata.patternName: block:<ID> for core/block/<ID>, the name core and compose-page give a Lab
	 * pattern, else pattern:<name>.
	 *
	 * @param string $name Pattern name.
	 */
	public static function of_name( string $name ): string {
		return preg_match( '#^core/block/([1-9]\d*)$#D', $name, $match ) ? 'block:' . $match[1] : 'pattern:' . $name;
	}

	/**
	 * Drops the cached results. Runs when a pattern or a status changes.
	 */
	public static function flush(): void {
		self::$cache = array();
	}

	/**
	 * Flushes the cache when a status meta changes. Runs on added_post_meta, updated_post_meta and deleted_post_meta.
	 *
	 * @param mixed  $meta_ids  Meta ID or IDs.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function flush_on_meta( $meta_ids, $object_id, $meta_key ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		if ( '_leitvia_lab_status' === $meta_key ) {
			self::flush();
		}
	}

	/**
	 * Depth-first walk; every reachable pattern counts once, so cycles end.
	 *
	 * @param string              $ref  Pattern reference.
	 * @param array<string, true> $seen Visited references.
	 */
	private static function resolve( string $ref, array &$seen ): string {
		$seen[ $ref ] = true;
		$status       = self::own( $ref );
		foreach ( self::parts( $ref ) as $part ) {
			if ( isset( $seen[ $part ] ) ) {
				continue;
			}
			$sub = self::resolve( $part, $seen );
			$sub = 'deprecated' === $sub ? 'draft' : $sub;
			if ( self::RANK[ $sub ] < self::RANK[ $status ] ) {
				$status = $sub;
			}
		}
		return $status;
	}

	/**
	 * References in a block list. The inner blocks of an inserted pattern belong to that pattern.
	 *
	 * @param array<array<string, mixed>> $blocks Parsed blocks.
	 * @return list<string>
	 */
	private static function collect( array $blocks ): array {
		$refs = array();
		foreach ( $blocks as $block ) {
			$attrs = (array) ( $block['attrs'] ?? array() );
			if ( 'core/block' === $block['blockName'] && ! empty( $attrs['ref'] ) ) {
				$refs[] = 'block:' . (int) $attrs['ref'];
			} elseif ( 'core/pattern' === $block['blockName'] && ! empty( $attrs['slug'] ) ) {
				$refs[] = 'pattern:' . $attrs['slug'];
			} elseif ( ! empty( $attrs['metadata']['patternName'] ) ) {
				$refs[] = self::of_name( (string) $attrs['metadata']['patternName'] );
			} else {
				$refs = array_merge( $refs, self::collect( (array) ( $block['innerBlocks'] ?? array() ) ) );
			}
		}
		return $refs;
	}

	/**
	 * Block markup of a pattern, '' when it does not exist.
	 *
	 * @param string $ref Pattern reference.
	 */
	private static function content( string $ref ): string {
		if ( str_starts_with( $ref, 'pattern:' ) ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( substr( $ref, 8 ) );
			return (string) ( $pattern['content'] ?? '' );
		}
		$post = get_post( self::block_id( $ref ) );
		return $post && 'wp_block' === $post->post_type ? $post->post_content : '';
	}

	/**
	 * Post ID of a "block:<ID>" reference, 0 otherwise.
	 *
	 * @param string $ref Pattern reference.
	 */
	private static function block_id( string $ref ): int {
		return preg_match( '/^block:([1-9]\d*)$/D', $ref, $match ) ? (int) $match[1] : 0;
	}
}
