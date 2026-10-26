<?php
/**
 * REST route /leitvia-studio/v1/recipes for the Recipes screen.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Rest;

use LeitviaStudio\Gateway\Recipes;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every registered recipe as leitvia/list-recipes does, plus its source and the plan read from its recipe file:
 * what applying it sets up. apply-recipe has no dry run, so the plan is the manifest; pages that exist and parts changed
 * since a recipe wrote them are kept when it runs. Applying goes through the ability itself
 * (POST /wp-abilities/v1/abilities/leitvia/apply-recipe/run), so autonomy, change package, rate limit and audit apply as
 * for an agent.
 */
final class Recipes_Controller {

	/**
	 * Registers the route. Runs on rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			'leitvia-studio/v1',
			'/recipes',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_theme_options' ),
			)
		);
	}

	/**
	 * Every recipe with source (core or add-on, with the add-on label) and plan.
	 */
	public function get_items(): \WP_REST_Response {
		$addons = leitvia_studio()->extensions()->all();
		$items  = array();
		foreach ( Recipes::all() as $item ) {
			$entry   = leitvia_studio()->registry( 'recipes' )->get( $item['name'] );
			$path    = (string) ( $entry['path'] ?? '' );
			$recipe  = Recipes::load( $path );
			$core    = 'leitvia-studio' === $item['owner'];
			$items[] = $item + array(
				'source'      => $core ? 'core' : 'addon',
				'sourceLabel' => $core ? '' : (string) ( $addons[ $item['owner'] ]['label'] ?? $item['owner'] ),
				'plan'        => is_wp_error( $recipe ) ? null : self::plan( $recipe, dirname( $path ) ),
			);
		}
		return new \WP_REST_Response( $items );
	}

	/**
	 * What a recipe sets up, in the order apply-recipe runs its steps.
	 *
	 * @param array<string, mixed> $recipe Recipe checked against the schema.
	 * @param string               $dir    Recipe folder.
	 * @return array<string, mixed>
	 */
	public static function plan( array $recipe, string $dir ): array {
		$titles = array_column( (array) $recipe['pages'], 'title', 'slug' );
		$labels = static fn( array $slugs ): array => array_values( array_map( static fn( $slug ): string => (string) ( $titles[ $slug ] ?? $slug ), $slugs ) );
		$model  = isset( $recipe['contentModel'] ) ? Recipes::file( $dir, (string) $recipe['contentModel'] ) : array();
		$types  = array();
		foreach ( array( 'postTypes', 'taxonomies' ) as $section ) {
			foreach ( (array) ( $model[ $section ] ?? array() ) as $key => $declared ) {
				$types[] = (string) ( $declared['label'] ?? $key );
			}
		}
		return array(
			'contentModel'     => $types,
			'tokenSet'         => isset( $recipe['tokenSet'] ) ? (string) $recipe['title'] : '',
			'variation'        => (string) ( $recipe['variation'] ?? '' ),
			'templateParts'    => (object) array_map( static fn( $part ): string => (string) ( is_array( $part ) ? ( $part['pattern'] ?? '' ) : $part ), (array) ( $recipe['templateParts'] ?? array() ) ),
			'pages'            => array_map(
				static fn( array $page ): array => array(
					'slug'     => (string) $page['slug'],
					'title'    => (string) $page['title'],
					'parent'   => (string) ( $titles[ $page['parent'] ?? '' ] ?? '' ),
					'sections' => count( (array) $page['sections'] ),
				),
				(array) $recipe['pages']
			),
			'navigation'       => $labels( (array) ( $recipe['navigation'] ?? array() ) ),
			'footerNavigation' => $labels( (array) ( $recipe['footerNavigation'] ?? array() ) ),
			'settings'         => array_keys( (array) ( $recipe['reading'] ?? array() ) + (array) ( $recipe['connect'] ?? array() ) + (array) ( $recipe['options'] ?? array() ) ),
		);
	}
}
