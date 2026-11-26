<?php
/**
 * Completion of template and prompt arguments.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

use LeitviaStudio\Gateway\Abilities\Read_Design;
use LeitviaStudio\Gateway\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Answers completion/complete with pattern slugs for leitvia://pattern/{slug} (the value anywhere in the slug), IDs of pages and
 * posts the agent may read for leitvia://page/{id}/outline (the value as start of the ID). Clients ask while a person
 * types, so suggestions read the lists directly and write no audit entry; a read of the resource itself runs the
 * ability. Prompt arguments complete from the source their entry names (Prompts::source()).
 */
final class Completion {

	/**
	 * Most values of one answer, the limit of the protocol.
	 */
	private const MAX = 100;

	/**
	 * Sources of values, for prompt arguments (complete) and for choices of Input_Required.
	 */
	public const SOURCES = array( 'patterns', 'pages', 'recipes' );

	/**
	 * Result of completion/complete.
	 *
	 * @param array<string, mixed> $params Params with ref and argument.
	 * @return array{completion: array{values: list<string>, total: int, hasMore: bool}}
	 */
	public static function complete( array $params ): array {
		$ref      = is_array( $params['ref'] ?? null ) ? $params['ref'] : array();
		$argument = is_array( $params['argument'] ?? null ) ? $params['argument'] : array();
		$name     = is_string( $argument['name'] ?? null ) ? $argument['name'] : '';
		$value    = is_string( $argument['value'] ?? null ) ? $argument['value'] : '';
		$values   = match ( $ref['type'] ?? null ) {
			'ref/resource' => is_string( $ref['uri'] ?? null ) ? self::resource( $ref['uri'], $name, $value ) : array(),
			'ref/prompt'   => is_string( $ref['name'] ?? null ) ? self::source( Prompts::source( $ref['name'], $name ), $value ) : array(),
			default        => array(),
		};
		return array(
			'completion' => array(
				'values'  => array_slice( $values, 0, self::MAX ),
				'total'   => count( $values ),
				'hasMore' => count( $values ) > self::MAX,
			),
		);
	}

	/**
	 * Values for an argument of a resource template.
	 *
	 * @param string $uri   URI template.
	 * @param string $name  Argument name.
	 * @param string $value What the client has so far.
	 * @return list<string>
	 */
	private static function resource( string $uri, string $name, string $value ): array {
		return match ( true ) {
			Resources::PATTERN === $uri && 'slug' === $name => self::source( 'patterns', $value ),
			Resources::OUTLINE === $uri && 'id' === $name   => self::source( 'pages', $value ),
			default                                          => array(),
		};
	}

	/**
	 * Values of a source that contain the value (pages: that start with it), sorted; empty for an unknown source.
	 *
	 * @param string $source One of SOURCES.
	 * @param string $value  What the client has so far, '' for all.
	 * @return list<string>
	 */
	public static function source( string $source, string $value ): array {
		return match ( $source ) {
			'patterns' => self::matching( Read_Design::slugs(), $value ),
			'recipes'  => self::matching( array_map( 'strval', array_keys( leitvia_studio()->registry( 'recipes' )->all() ) ), $value ),
			'pages'    => self::pages( $value ),
			default    => array(),
		};
	}

	/**
	 * Values that contain the value, case-insensitive, sorted.
	 *
	 * @param string[] $values Values.
	 * @param string   $value  Part to look for, '' for all.
	 * @return list<string>
	 */
	private static function matching( array $values, string $value ): array {
		$values = array_values( array_filter( $values, static fn( string $item ): bool => '' === $value || false !== stripos( $item, $value ) ) );
		sort( $values );
		return $values;
	}

	/**
	 * IDs of the pages and posts changed last that the agent may read and that start with the value.
	 *
	 * @param string $value Start of the ID.
	 * @return list<string>
	 */
	private static function pages( string $value ): array {
		// ponytail: the 100 posts changed last; a search by title comes when agents ask for older ones.
		$ids = get_posts(
			array(
				'post_type'      => Scope::post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => self::MAX + 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$ids = array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => str_starts_with( (string) $id, $value ) && Scope::readable( $id ) );
		return array_values( array_map( 'strval', $ids ) );
	}
}
