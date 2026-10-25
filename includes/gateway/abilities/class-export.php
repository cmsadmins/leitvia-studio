<?php
/**
 * Read ability that exports the content and design of the site as a portable package.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Token_Sets;

defined( 'ABSPATH' ) || exit;

/**
 * Ability leitvia/export: pages, navigation menus and token sets page by page, and on the first page the template
 * parts of the active theme and the content model. Writes nothing.
 */
final class Export {

	public const FORMAT = 'leitvia-export/1';

	/**
	 * Parts of an export and the post type behind the paged ones.
	 */
	public const PARTS = array(
		'pages'          => 'page',
		'navigation'     => 'wp_navigation',
		'token_sets'     => Token_Sets::POST_TYPE,
		'template_parts' => '',
		'content_model'  => '',
	);

	public const MAX_LIMIT = 50;

	/**
	 * Most bytes of the items of one call; the rest follows with next.
	 */
	public const MAX_BYTES = 1048576;

	/**
	 * Registers the ability. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		Abilities::read(
			'leitvia/export',
			__( 'Export site', 'leitvia-studio' ),
			/* translators: %d: most bytes per call */
			sprintf( __( 'Exports the site as a portable JSON package, page by page. Pages (block markup), navigation menus and token sets (DTCG document) come in items; the first call (offset 0) also carries the template parts of the active theme and the content model. Each item carries its hash. Items of one call stay under %d bytes; call again with offset set to next until next is null. Choose parts to export less. Writes nothing; drafts are included, private and password protected content is not.', 'leitvia-studio' ), self::MAX_BYTES ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'parts'  => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => array_keys( self::PARTS ),
						),
						'uniqueItems' => true,
						'description' => __( 'Parts to export; all when empty.', 'leitvia-studio' ),
					),
					'offset' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'limit'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_LIMIT,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'format'         => array( 'type' => 'string' ),
					'site'           => array( 'type' => 'object' ),
					'parts'          => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'content_model'  => array( 'type' => array( 'object', 'null' ) ),
					'template_parts' => $list,
					'items'          => $list,
					'next'           => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
			array( self::class, 'export' )
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>
	 */
	public static function export( $input ): array {
		$input            = is_array( $input ) ? $input : array();
		$parts            = array_values( array_intersect( array_keys( self::PARTS ), (array) ( $input['parts'] ?? array() ) ) );
		$parts            = $parts ? $parts : array_keys( self::PARTS );
		$offset           = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$limit            = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
		$first            = 0 === $offset;
		$types            = array_values( array_filter( array_map( static fn( string $part ): string => self::PARTS[ $part ], $parts ) ) );
		[ $items, $next ] = $types ? self::items( $types, $offset, $limit ) : array( array(), null );
		return array(
			'format'         => self::FORMAT,
			'site'           => array(
				'name'       => (string) get_bloginfo( 'name' ),
				'url'        => home_url( '/' ),
				'theme'      => get_stylesheet(),
				'active_set' => (int) get_option( Compiler::ACTIVE_OPTION, 0 ),
				'generated'  => gmdate( 'c' ),
			),
			'parts'          => $parts,
			'content_model'  => $first && in_array( 'content_model', $parts, true ) ? Model::current() : null,
			'template_parts' => $first && in_array( 'template_parts', $parts, true ) ? self::template_parts() : array(),
			'items'          => $items,
			'next'           => $next,
		);
	}

	/**
	 * One page of posts of the given types the current user may read through the gateway, in type and ID order, and the
	 * offset of the next page (null at the end). A page ends at limit posts or MAX_BYTES, whichever comes first, but
	 * always holds at least one post.
	 *
	 * @param string[] $types  Post types.
	 * @param int      $offset Offset.
	 * @param int      $limit  Posts per page.
	 * @return array{0: list<array<string, mixed>>, 1: int|null}
	 */
	private static function items( array $types, int $offset, int $limit ): array {
		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => array_merge( Scope::STATUSES, array( 'private' ) ),
				'orderby'        => array(
					'type' => 'ASC',
					'ID'   => 'ASC',
				),
				'offset'         => $offset,
				'posts_per_page' => $limit + 1,
				'no_found_rows'  => true,
			)
		);
		$items = array();
		$bytes = 0;
		$used  = 0;
		foreach ( array_slice( $posts, 0, $limit ) as $post ) {
			$item = Scope::readable( $post ) ? self::item( $post ) : null;
			$size = $item ? strlen( (string) wp_json_encode( $item ) ) : 0;
			// ponytail: a single post over MAX_BYTES still goes out whole; split its content if that ever bites.
			if ( $items && $bytes + $size > self::MAX_BYTES ) {
				break;
			}
			++$used;
			$bytes += $size;
			if ( $item ) {
				$items[] = $item;
			}
		}
		return array( $items, count( $posts ) > $used ? $offset + $used : null );
	}

	/**
	 * Export entry of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private static function item( \WP_Post $post ): array {
		$item = array(
			'kind'   => (string) array_search( $post->post_type, self::PARTS, true ),
			'id'     => $post->ID,
			'slug'   => $post->post_name,
			'title'  => $post->post_title,
			'status' => $post->post_status,
			'hash'   => Hashes::document( $post->ID ),
		);
		if ( Token_Sets::POST_TYPE === $post->post_type ) {
			// Objects keep {} and [] apart, so the document stays as stored.
			return $item + array( 'document' => json_decode( (string) get_post_meta( $post->ID, Token_Sets::META, true ) ) );
		}
		if ( 'page' === $post->post_type ) {
			$item += array(
				'parent'   => (int) $post->post_parent,
				'order'    => (int) $post->menu_order,
				'template' => (string) get_page_template_slug( $post ),
				'excerpt'  => $post->post_excerpt,
			);
		}
		return $item + array( 'content' => $post->post_content );
	}

	/**
	 * Template parts of the active theme as the site editor shows them: changed ones from the database, the others
	 * from the theme files.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function template_parts(): array {
		$parts = array();
		foreach ( get_block_templates( array(), 'wp_template_part' ) as $part ) {
			$parts[] = array(
				'slug'    => $part->slug,
				'title'   => $part->title,
				'area'    => $part->area,
				'source'  => $part->source,
				'hash'    => $part->wp_id ? Hashes::document( (int) $part->wp_id ) : '',
				'content' => $part->content,
			);
		}
		return $parts;
	}
}
