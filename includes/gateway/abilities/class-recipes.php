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
					'description'         => __( 'Sets up the site from a recipe of leitvia/list-recipes: content model and building blocks, token set, style variation, header and footer, pages from patterns, navigation, reading settings and options that save data. Each part runs through its own ability with the autonomy level of its area; what may not go live lands in one change package, and with a package key everything does. Settings that point to a page waiting in that package (front page, privacy policy page) are set when the package is applied. Pages that exist, and variation, header, footer and navigation changed since a recipe wrote them, are kept, as is the variation while a token set is active, so the recipe can run again after the package was applied. parts, pages and buildingBlocks limit the run to a selection, and slugs gives recipe pages other addresses; navigation and footer then link only the pages that are on the site or come in this run, and a navigation without any of them waits. dryRun reports what would change without writing. Review the package with leitvia/get-package. With settle, a person (manage_options, not the agent role) in the admin, neither signed in with an application password nor calling over MCP, who runs it without a package key applies what collects at once: applied lists the packages applied in full, which can be rolled back in the Inbox, and partial the packages applied in part, with their errors. Without settle the package waits.', 'leitvia-studio' ),
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'recipe'         => array(
								'type'        => 'string',
								'description' => __( 'Name from leitvia/list-recipes, for example leitvia/blog.', 'leitvia-studio' ),
							),
							'package'        => array(
								'type'        => 'string',
								'maxLength'   => 64,
								'description' => __( 'Key of the task: every part goes into the change package of that key, nothing goes live.', 'leitvia-studio' ),
							),
							'parts'          => array(
								'type'        => 'array',
								'items'       => array(
									'type' => 'string',
									'enum' => Gateway\Recipes::PARTS,
								),
								'uniqueItems' => true,
								'description' => __( 'Parts to apply, all by default: buildingBlocks (content model and building blocks), samples (their sample entries), design (token set and style variation), header, footer, pages, navigation, frontPage (reading settings) and options (page settings and data-saving options).', 'leitvia-studio' ),
							),
							'pages'          => array(
								'type'        => 'array',
								'items'       => array(
									'type'    => 'string',
									'pattern' => '^[a-z0-9-]+$',
								),
								'description' => __( 'Slugs of the recipe pages to create, all by default.', 'leitvia-studio' ),
							),
							'slugs'          => array(
								'type'                 => 'object',
								'additionalProperties' => array(
									'type'    => 'string',
									'pattern' => '^[a-z0-9-]+$',
								),
								'description'          => __( 'Other slugs for recipe pages, by the slug of the recipe, such as {"imprint": "impressum"}. Links, navigation and settings follow. Send the same slugs on a later run; pages without a kind are found by their slug.', 'leitvia-studio' ),
							),
							'buildingBlocks' => array(
								'type'        => 'array',
								'items'       => array(
									'type'    => 'string',
									'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$',
								),
								'description' => __( 'Names of the building blocks of the recipe to add, all by default. With a list only these blocks add content types, not the whole content model of the recipe.', 'leitvia-studio' ),
							),
							'dryRun'         => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Report what each step would do (new, replace, exists, package, waiting) and the parts it replaces, without writing anything.', 'leitvia-studio' ),
							),
							'settle'         => Gateway\Building_Blocks::settle_schema(),
						),
						'required'             => array( 'recipe' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'recipe'   => $string,
							'package'  => array( 'type' => 'integer' ),
							'steps'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'dryRun'   => array( 'type' => 'boolean' ),
							'applied'  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'partial'  => Gateway\Building_Blocks::partial_schema(),
							'replaces' => array(
								'type'  => 'array',
								'items' => $string,
							),
						),
					),
					'permission_callback' => array( Gateway\Abilities::class, 'can_use' ),
					'execute_callback'    => static fn( $input = null ) => Gateway\Abilities::run(
						$name,
						static fn( $input ) => Gateway\Recipes::apply( (string) ( $input['recipe'] ?? '' ), (string) ( $input['package'] ?? '' ), array_intersect_key( $input, array_flip( array( 'parts', 'pages', 'slugs', 'buildingBlocks', 'dryRun' ) ) ), ! empty( $input['settle'] ) ),
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
