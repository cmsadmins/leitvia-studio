<?php
/**
 * Block diff of change package operations.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Compares two states block by block: blocks are matched by metadata.leitviaId, then by the hash of name, attributes
 * and own markup (so IDs assigned on save do not count as changes), then blocks without ID by type and parent. Each
 * change is added, removed, moved or changed with the text before and after and the names of the changed attributes.
 * No character diff: memory grows with the number of blocks, not with their length. Other fields (title, status,
 * slug, an option value, theme.json content) are one entry each with the id field:<name>.
 */
final class Diff {

	/**
	 * Largest operation in bytes (state before plus state after).
	 */
	public const MAX_BYTES = MB_IN_BYTES;

	/**
	 * Characters of text per side and entry.
	 */
	private const TEXT_LENGTH = 300;

	/**
	 * Post fields compared as text besides the content.
	 */
	private const FIELDS = array( 'post_title', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'post_date', 'terms', 'meta_input', 'product', 'term', 'value' );

	/**
	 * Diff of a stored operation against the current state of its target.
	 *
	 * @param array<string, mixed> $entry Stored operation.
	 * @param int                  $max   Size limit in bytes.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	public static function operation( array $entry, int $max = self::MAX_BYTES ) {
		$op = new Operation( (string) $entry['ability'], (string) $entry['area'], (array) $entry['target'], (string) $entry['base_hash'], static fn( array $state ): array => $state );
		return self::states( self::current( $op ), (array) $entry['state'], $max );
	}

	/**
	 * Current state of an operation's target: array( 'value' => … ) for an option, the post fields for a post, an
	 * empty array for a new or missing post.
	 *
	 * @param Operation $op Operation.
	 * @return array<string, mixed>
	 */
	public static function current( Operation $op ): array {
		if ( '' !== $op->option() ) {
			return array( 'value' => get_option( $op->option() ) );
		}
		if ( isset( $op->target['term'] ) ) {
			return Pipeline::term_state( $op->term() );
		}
		return Pipeline::state( $op->post_id() );
	}

	/**
	 * Changes from one state to another: per entry id, change (added, removed, moved, changed), before_text,
	 * after_text and attrs_changed. leitvia_too_large when both states together exceed the limit.
	 *
	 * @param array<string, mixed> $before State before.
	 * @param array<string, mixed> $after  State after.
	 * @param int                  $max    Size limit in bytes.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	public static function states( array $before, array $after, int $max = self::MAX_BYTES ) {
		$size = strlen( (string) wp_json_encode( $before ) ) + strlen( (string) wp_json_encode( $after ) );
		if ( $size > $max ) {
			/* translators: %d: size limit in kilobytes */
			return Rejection::make( 'leitvia_too_large', sprintf( __( 'The change is too large to compare (more than %d KB). Split it into smaller changes.', 'leitvia-studio' ), (int) ( $max / KB_IN_BYTES ) ), 413, 'size', array(), '', 'once', array( 'bytes' => $size ) );
		}
		$changes = array();
		$fields  = self::FIELDS;
		$blocks  = isset( $after['post_content'] ) && ! in_array( $after['post_type'] ?? '', Pipeline::JSON_TYPES, true )
			&& has_blocks( (string) $after['post_content'] . (string) ( $before['post_content'] ?? '' ) );
		if ( isset( $after['post_content'] ) && ! $blocks ) {
			$fields[] = 'post_content';
		}
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $after ) || ( array_key_exists( $field, $before ) && $before[ $field ] == $after[ $field ] ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Stored states went through JSON.
				continue;
			}
			$changes[] = self::entry( 'field:' . $field, array_key_exists( $field, $before ) ? 'changed' : 'added', self::text( $before[ $field ] ?? '' ), self::text( $after[ $field ] ) );
		}
		if ( $blocks ) {
			$changes = array_merge( $changes, self::blocks( (string) ( $before['post_content'] ?? '' ), (string) $after['post_content'] ) );
		}
		return $changes;
	}

	/**
	 * Block changes between two documents.
	 *
	 * @param string $before Markup before.
	 * @param string $after  Markup after.
	 * @return list<array<string, mixed>>
	 */
	private static function blocks( string $before, string $after ): array {
		$old = self::flatten( parse_blocks( $before ), 'p:', '' );
		$new = self::flatten( parse_blocks( $after ), 'p:', '' );
		// New key => old key: first by stable ID, then by equal content among the rest.
		$pairs = array();
		foreach ( $new as $key => $node ) {
			if ( $key === $node['id'] && isset( $old[ $key ] ) ) {
				$pairs[ $key ] = $key;
			}
		}
		$free = array_diff_key( $old, array_flip( $pairs ) );
		foreach ( $new as $key => $node ) {
			if ( isset( $pairs[ $key ] ) ) {
				continue;
			}
			foreach ( $free as $old_key => $candidate ) {
				if ( $candidate['hash'] === $node['hash'] ) {
					$pairs[ $key ] = $old_key;
					unset( $free[ $old_key ] );
					break;
				}
			}
		}
		// A block without ID that kept its type and parent but not its content is the same block, changed.
		foreach ( $new as $key => $node ) {
			if ( isset( $pairs[ $key ] ) || '' !== $node['id'] ) {
				continue;
			}
			$parent = '' === $node['parent'] ? '' : ( $pairs[ $node['parent'] ] ?? null );
			foreach ( $free as $old_key => $candidate ) {
				if ( $candidate['name'] === $node['name'] && $candidate['parent'] === $parent ) {
					$pairs[ $key ] = $old_key;
					unset( $free[ $old_key ] );
					break;
				}
			}
		}
		$old_rank = self::ranks( $old, array_flip( $pairs ), array() );
		$new_rank = self::ranks( $new, $pairs, $pairs );
		$changes  = array();
		foreach ( $new as $key => $node ) {
			if ( ! isset( $pairs[ $key ] ) ) {
				$changes[] = self::entry( self::label( $key, $node ), 'added', '', $node['text'] );
				continue;
			}
			$was = $old[ $pairs[ $key ] ];
			if ( $was['hash'] !== $node['hash'] ) {
				$attrs     = array_keys( array_diff_key( $node['attrs'], $was['attrs'] ) + array_diff_key( $was['attrs'], $node['attrs'] ) );
				$common    = array_keys( array_intersect_key( $node['attrs'], $was['attrs'] ) );
				$attrs     = array_merge( $attrs, array_filter( $common, static fn( $name ): bool => $node['attrs'][ $name ] != $was['attrs'][ $name ] ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Attribute values from JSON.
				$changes[] = self::entry( self::label( $key, $node ), 'changed', $was['text'], $node['text'], array_map( 'strval', $attrs ) );
			} elseif ( $old_rank[ $pairs[ $key ] ] !== $new_rank[ $key ] ) {
				$changes[] = self::entry( self::label( $key, $node ), 'moved', $was['text'], $node['text'] );
			}
		}
		foreach ( array_diff_key( $old, array_flip( $pairs ) ) as $key => $node ) {
			$changes[] = self::entry( self::label( $key, $node ), 'removed', $node['text'], '' );
		}
		return $changes;
	}

	/**
	 * Blocks in document order by key (the stable ID, else the path): id, parent key, attributes without the ID,
	 * own text and the hash of name, attributes and own markup.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                                  $path   Path prefix.
	 * @param string                                  $owner  Key of the parent block.
	 * @return array<string, array<string, mixed>>
	 */
	private static function flatten( array $blocks, string $path, string $owner ): array {
		$nodes = array();
		foreach ( Block_Ids::blocks( $blocks ) as $index => $block ) {
			$id    = Block_Ids::id( $block );
			$attrs = (array) $block['attrs'];
			unset( $attrs['metadata']['leitviaId'] );
			if ( array() === ( $attrs['metadata'] ?? null ) ) {
				unset( $attrs['metadata'] );
			}
			$html          = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
			$key           = '' !== $id && ! isset( $nodes[ $id ] ) ? $id : $path . $index;
			$nodes[ $key ] = array(
				'id'     => $id,
				'name'   => (string) $block['blockName'],
				'parent' => $owner,
				'attrs'  => $attrs,
				'text'   => self::text( $html ),
				'hash'   => Hashes::of( array( $block['blockName'], $attrs, $html ) ),
			);
			$nodes        += self::flatten( (array) $block['innerBlocks'], $path . $index . '.', $key );
		}
		return $nodes;
	}

	/**
	 * Position of each matched block: its parent, as a key of the old side, and its index among the matched blocks of
	 * that parent. Added and removed neighbours do not shift it.
	 *
	 * @param array<string, array<string, mixed>> $nodes     Blocks of one side.
	 * @param array<string, string>               $matched   Keys of matched blocks of this side (as array keys).
	 * @param array<string, string>               $translate New key => old key, empty for the old side.
	 * @return array<string, string>
	 */
	private static function ranks( array $nodes, array $matched, array $translate ): array {
		$ranks = array();
		$seen  = array();
		foreach ( $nodes as $key => $node ) {
			if ( ! isset( $matched[ $key ] ) ) {
				continue;
			}
			$parent = (string) $node['parent'];
			if ( $translate && '' !== $parent ) {
				$parent = $translate[ $parent ] ?? 'new:' . $parent;
			}
			$seen[ $parent ] = ( $seen[ $parent ] ?? -1 ) + 1;
			$ranks[ $key ]   = $parent . '#' . $seen[ $parent ];
		}
		return $ranks;
	}

	/**
	 * One entry.
	 *
	 * @param string   $id     Block or field ID.
	 * @param string   $change added, removed, moved or changed.
	 * @param string   $before Text before.
	 * @param string   $after  Text after.
	 * @param string[] $attrs  Names of changed attributes.
	 * @return array<string, mixed>
	 */
	private static function entry( string $id, string $change, string $before, string $after, array $attrs = array() ): array {
		return array(
			'id'            => $id,
			'change'        => $change,
			'before_text'   => $before,
			'after_text'    => $after,
			'attrs_changed' => $attrs,
		);
	}

	/**
	 * ID shown for a block: the stable ID, else the path.
	 *
	 * @param string               $key  Key.
	 * @param array<string, mixed> $node Block.
	 */
	private static function label( string $key, array $node ): string {
		return '' !== $node['id'] ? (string) $node['id'] : $key;
	}

	/**
	 * Short plain text of markup or a value.
	 *
	 * @param mixed $value Markup, scalar or array.
	 */
	private static function text( $value ): string {
		$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), 0, self::TEXT_LENGTH );
	}
}
