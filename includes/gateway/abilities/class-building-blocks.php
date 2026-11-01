<?php
/**
 * Building block abilities: list building blocks and add one to the site.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/list-building-blocks and leitvia/add-building-block. add-building-block writes nothing itself:
 * the model goes through leitvia/define-content-type, settings and sample entries through Pipeline::run(), see
 * Gateway\Building_Blocks.
 */
final class Building_Blocks {

	/**
	 * Registers both abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$string  = array( 'type' => 'string' );
		$strings = array(
			'type'  => 'array',
			'items' => $string,
		);
		Gateway\Abilities::read(
			'leitvia/list-building-blocks',
			__( 'List building blocks', 'leitvia-studio' ),
			__( 'Lists the building blocks of add-ons: name, title, description, whether the site has them, their post types and taxonomies, the patterns that show them and the number of sample entries. Add one with leitvia/add-building-block.', 'leitvia-studio' ),
			array(),
			array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => $string,
						'owner'       => $string,
						'title'       => $string,
						'description' => $string,
						'icon'        => $string,
						'installed'   => array( 'type' => 'boolean' ),
						'postTypes'   => $strings,
						'taxonomies'  => $strings,
						'patterns'    => $strings,
						'sampleCount' => array( 'type' => 'integer' ),
					),
				),
			),
			static fn() => Gateway\Building_Blocks::all()
		);
		$name = Gateway\Building_Blocks::ABILITY;
		leitvia_studio()->registry( 'abilities' )->register(
			$name,
			array(
				'args'  => array(
					'label'               => __( 'Add building block', 'leitvia-studio' ),
					'description'         => __( 'Adds a building block of leitvia/list-building-blocks to the site: its content types, taxonomies, relations and site settings through leitvia/define-content-type, merged with the entries that exist so fields a person added stay; the default values of its site settings that are empty; and its sample entries with their terms as drafts. Design, header, footer, pages and front page stay as they are. What the autonomy does not let go live waits in one change package. With settle, a person (manage_options, not the agent role) in the admin, neither signed in with an application password nor calling over MCP, gets that package applied right away: applied lists it, partial names a package applied in part with its errors. Entries that exist are kept, so the block can be added again to fill in what is missing.', 'leitvia-studio' ),
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'    => array(
								'type'        => 'string',
								'pattern'     => '^[a-z0-9-]+/[a-z0-9-]+$',
								'description' => __( 'Name of the building block, for example my-addon/events.', 'leitvia-studio' ),
							),
							'samples' => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => __( 'Whether to add the sample entries as drafts.', 'leitvia-studio' ),
							),
							'package' => array(
								'type'        => 'string',
								'maxLength'   => 64,
								'description' => __( 'Key of the task: every part goes into the change package of that key, nothing goes live.', 'leitvia-studio' ),
							),
							'settle'  => Gateway\Building_Blocks::settle_schema(),
						),
						'required'             => array( 'name' ),
						'additionalProperties' => false,
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'buildingBlock' => array( 'type' => 'string' ),
							'package'       => array( 'type' => 'integer' ),
							'steps'         => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'applied'       => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'partial'       => Gateway\Building_Blocks::partial_schema(),
						),
					),
					'permission_callback' => array( Gateway\Building_Blocks::class, 'can_set_up' ),
					'execute_callback'    => static fn( $input = null ) => Gateway\Abilities::run(
						$name,
						static fn( $input ) => Gateway\Building_Blocks::add( (string) ( $input['name'] ?? '' ), (bool) ( $input['samples'] ?? true ), (string) ( $input['package'] ?? '' ), ! empty( $input['settle'] ) ),
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
