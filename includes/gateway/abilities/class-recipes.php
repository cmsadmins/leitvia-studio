<?php
/**
 * Recipe abilities: list and apply site recipes.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/list-recipes and leitvia/apply-recipe. apply-recipe writes nothing itself: it calls the setup and
 * build abilities, and Pipeline::run() for settings, see Gateway\Recipes.
 */
final class Recipes {

	/**
	 * Registers both abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$string = array( 'type' => 'string' );
		Gateway\Abilities::read(
			'leitvia/list-recipes',
			__( 'List recipes', 'leitvia-studio' ),
			/* translators: %s: product name */
			sprintf( __( 'Lists the site recipes of %s and its add-ons with owner, pages and whether the plugins they require are active.', 'leitvia-studio' ), leitvia_studio_brand()['name'] ),
			array(),
			array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => $string,
						'owner'       => $string,
						'label'       => $string,
						'title'       => $string,
						'description' => $string,
						'requires'    => array(
							'type'  => 'array',
							'items' => $string,
						),
						'pages'       => array(
							'type'  => 'array',
							'items' => $string,
						),
						'available'   => array( 'type' => 'boolean' ),
						'reason'      => $string,
					),
				),
			),
			static fn() => Gateway\Recipes::all()
		);
		$name = 'leitvia/apply-recipe';
		leitvia_studio()->registry( 'abilities' )->register(
			$name,
			array(
				'args'  => array(
					'label'               => __( 'Apply recipe', 'leitvia-studio' ),
					'description'         => __( 'Sets up the site from a recipe of leitvia/list-recipes: content model, token set, style variation, header and footer, pages from patterns, navigation, reading settings and options that save data. Each part runs through its own ability with the autonomy level of its area; what may not go live lands in one change package, and with a package key everything does. Settings that point to a page waiting in that package (front page, privacy policy page) are set when the package is applied. Pages that exist, and variation, header, footer and navigation changed since a recipe wrote them, are kept, as is the variation while a token set is active, so the recipe can run again after the package was applied. Review the package with leitvia/get-package.', 'leitvia-studio' ),
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'recipe'  => array(
								'type'        => 'string',
								'description' => __( 'Name from leitvia/list-recipes, for example leitvia/blog.', 'leitvia-studio' ),
							),
							'package' => array(
								'type'        => 'string',
								'maxLength'   => 64,
								'description' => __( 'Key of the task: every part goes into the change package of that key, nothing goes live.', 'leitvia-studio' ),
							),
						),
						'required'             => array( 'recipe' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'recipe'  => $string,
							'package' => array( 'type' => 'integer' ),
							'steps'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'permission_callback' => array( Gateway\Abilities::class, 'can_use' ),
					'execute_callback'    => static fn( $input = null ) => Gateway\Abilities::run(
						$name,
						static fn( $input ) => Gateway\Recipes::apply( (string) ( $input['recipe'] ?? '' ), (string) ( $input['package'] ?? '' ) ),
						is_array( $input ) ? $input : array()
					),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => true,
						),
						'show_in_rest' => true,
					),
				),
				'group' => 'setup',
			),
			'leitvia-studio'
		);
	}
}
