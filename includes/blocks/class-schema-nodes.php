<?php
/**
 * JSON-LD nodes that blocks add while they render.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Collects schema.org nodes from blocks (FAQPage from leitvia/faq, Review from leitvia/rating) and prints them as one
 * graph in the head. Block themes render the template before wp_head, so the nodes are known by then. Separate from
 * News\Schema and its setting news_schema; off whenever an SEO plugin is active, so a page never carries a node twice.
 */
final class Schema_Nodes {

	/**
	 * Nodes of the current request.
	 *
	 * @var list<array<string, mixed>>
	 */
	private static array $nodes = array();

	/**
	 * Adds a node for the current request.
	 *
	 * @param array<string, mixed> $node Node with @type.
	 */
	public static function add( array $node ): void {
		self::$nodes[] = $node;
	}

	/**
	 * The graph of the current request, null when there is nothing to print.
	 *
	 * @return array{"@context": string, "@graph": list<array<string, mixed>>}|null
	 */
	public static function current(): ?array {
		if ( leitvia_studio_seo_plugin_active() ) {
			return null;
		}
		/**
		 * Filters the JSON-LD nodes that blocks added on this request.
		 *
		 * @param list<array<string, mixed>> $nodes Nodes.
		 */
		$nodes = array_values( array_filter( (array) apply_filters( 'leitvia_studio_schema_nodes', self::$nodes ), 'is_array' ) );
		return $nodes ? array(
			'@context' => 'https://schema.org',
			'@graph'   => $nodes,
		) : null;
	}

	/**
	 * Prints the graph. Runs on wp_head, after the template has rendered.
	 */
	public static function print(): void {
		$data = self::current();
		if ( $data ) {
			// JSON_HEX_TAG writes < and > as unicode escapes, so no text can close the script element.
			wp_print_inline_script_tag( (string) wp_json_encode( $data, JSON_HEX_TAG ), array( 'type' => 'application/ld+json' ) );
		}
	}

	/**
	 * Forgets the collected nodes, for tests and for a second render in the same request.
	 */
	public static function reset(): void {
		self::$nodes = array();
	}
}
