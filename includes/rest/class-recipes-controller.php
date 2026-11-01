<?php
/**
 * REST route /leitvia-studio/v1/recipes for the Recipes screen.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Rest;

use LeitviaStudio\Gateway\Building_Blocks;
use LeitviaStudio\Gateway\Recipes;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every registered recipe as leitvia/list-recipes does, plus its source and the plan read from its recipe file:
 * what applying it sets up, limited to the parts, pages and building blocks of the request. With dryRun each recipe
 * that can be applied also gets the dry run of leitvia/apply-recipe: every step with new, replace, exists, package or
 * waiting and the parts it replaces, written nowhere. Applying goes through the ability itself
 * (POST /wp-abilities/v1/abilities/leitvia/apply-recipe/run), so autonomy, change package, rate limit and audit apply as
 * for an agent.
 */
final class Recipes_Controller {

	/**
	 * Registers the route. Runs on rest_api_init.
	 */
	public function register_routes(): void {
		$names = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/recipes',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_theme_options' ),
				'args'                => array(
					'recipe'         => array(
						'type'        => 'string',
						'description' => __( 'Only this recipe.', 'leitvia-studio' ),
					),
					'parts'          => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
							'enum' => Recipes::PARTS,
						),
					),
					'pages'          => $names,
					'buildingBlocks' => $names,
					'dryRun'         => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Every recipe (or the one asked for) with source (core or add-on, with the add-on label), plan and, with dryRun,
	 * the dry run. A dry run walks every step of a recipe, so it needs the one recipe: 400 without it.
	 *
	 * @param \WP_REST_Request|null $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( ?\WP_REST_Request $request = null ) {
		$addons = leitvia_studio()->extensions()->all();
		$only   = $request ? (string) $request->get_param( 'recipe' ) : '';
		if ( '' === $only && $request && $request->get_param( 'dryRun' ) ) {
			return new \WP_Error( 'rest_missing_callback_param', __( 'A dry run needs the name of one recipe.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		$input = array();
		foreach ( array( 'parts', 'pages', 'buildingBlocks' ) as $param ) {
			if ( $request && null !== $request->get_param( $param ) ) {
				$input[ $param ] = (array) $request->get_param( $param );
			}
		}
		$items = array();
		foreach ( Recipes::all() as $item ) {
			if ( '' !== $only && $only !== $item['name'] ) {
				continue;
			}
			$entry  = leitvia_studio()->registry( 'recipes' )->get( $item['name'] );
			$path   = (string) ( $entry['path'] ?? '' );
			$recipe = Recipes::document( $item['name'], $path );
			$core   = 'leitvia-studio' === $item['owner'];
			$row    = $item + array(
				'source'      => $core ? 'core' : 'addon',
				'sourceLabel' => $core ? '' : (string) ( $addons[ $item['owner'] ]['label'] ?? $item['owner'] ),
				'plan'        => is_wp_error( $recipe ) ? null : self::plan( $recipe, dirname( $path ), Recipes::selection( $input, $recipe ), $item['name'] ),
			);
			if ( $request && $request->get_param( 'dryRun' ) ) {
				$dry           = $item['available'] ? Recipes::apply( $item['name'], '', $input + array( 'dryRun' => true ) ) : null;
				$row['dryRun'] = is_array( $dry ) ? $dry : null;
			}
			$items[] = $row;
		}
		return new \WP_REST_Response( $items );
	}

	/**
	 * What a recipe sets up, in the order apply-recipe runs its steps, limited to a selection of Recipes::selection().
	 *
	 * @param array<string, mixed>      $recipe Recipe checked against the schema.
	 * @param string                    $dir    Recipe folder.
	 * @param array<string, mixed>|null $sel    Selection, null for everything.
	 * @param string                    $name   Registry name of the recipe, for leitvia_studio_recipe_model.
	 * @return array<string, mixed>
	 */
	public static function plan( array $recipe, string $dir, ?array $sel = null, string $name = '' ): array {
		$sel    = $sel ?? Recipes::selection( array(), $recipe );
		$has    = static fn( string $part ): bool => in_array( $part, (array) $sel['parts'], true );
		$titles = array_column( (array) $recipe['pages'], 'title', 'slug' );
		$labels = static fn( array $slugs ): array => array_values( array_map( static fn( $slug ): string => (string) ( $titles[ $slug ] ?? $slug ), $slugs ) );
		$model  = isset( $recipe['contentModel'] ) && $has( 'buildingBlocks' ) && $sel['contentModel'] ? Recipes::model( $dir, (string) $recipe['contentModel'], $name ) : array();
		$types  = array();
		foreach ( array( 'postTypes', 'taxonomies' ) as $section ) {
			foreach ( (array) ( $model[ $section ] ?? array() ) as $key => $declared ) {
				$types[] = (string) ( $declared['label'] ?? $key );
			}
		}
		$blocks = array();
		foreach ( $has( 'buildingBlocks' ) ? (array) $sel['buildingBlocks'] : array() as $name ) {
			$block    = leitvia_studio()->registry( 'building_blocks' )->get( (string) $name );
			$blocks[] = array(
				'name'      => (string) $name,
				'title'     => (string) ( $block['title'] ?? $name ),
				'installed' => $block && Building_Blocks::installed( $block ),
			);
		}
		$settings = array();
		foreach ( (array) ( $recipe['reading'] ?? array() ) + (array) ( $recipe['connect'] ?? array() ) + (array) ( $recipe['options'] ?? array() ) as $option => $value ) {
			if ( $has( in_array( $option, Recipes::READING, true ) ? 'frontPage' : 'options' ) ) {
				$settings[] = (string) $option;
			}
		}
		$pages = array_filter( (array) $recipe['pages'], static fn( array $page ): bool => $has( 'pages' ) && in_array( (string) $page['slug'], (array) $sel['pages'], true ) );
		$parts = array_filter( (array) ( $recipe['templateParts'] ?? array() ), static fn( $part ): bool => $has( (string) $part ), ARRAY_FILTER_USE_KEY );
		return array(
			'contentModel'     => $types,
			'buildingBlocks'   => $blocks,
			'tokenSet'         => isset( $recipe['tokenSet'] ) && $has( 'design' ) ? (string) $recipe['title'] : '',
			'variation'        => $has( 'design' ) ? (string) ( $recipe['variation'] ?? '' ) : '',
			'templateParts'    => (object) array_map( static fn( $part ): string => (string) ( is_array( $part ) ? ( $part['pattern'] ?? '' ) : $part ), $parts ),
			'pages'            => array_values(
				array_map(
					static fn( array $page ): array => array(
						'slug'     => (string) $page['slug'],
						'title'    => (string) $page['title'],
						'parent'   => (string) ( $titles[ $page['parent'] ?? '' ] ?? '' ),
						'sections' => count( (array) $page['sections'] ),
					),
					$pages
				)
			),
			'navigation'       => $has( 'navigation' ) ? $labels( (array) ( $recipe['navigation'] ?? array() ) ) : array(),
			'footerNavigation' => $has( 'footer' ) ? $labels( (array) ( $recipe['footerNavigation'] ?? array() ) ) : array(),
			'settings'         => $settings,
		);
	}
}
