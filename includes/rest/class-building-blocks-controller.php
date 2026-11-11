<?php
/**
 * REST route /leitvia-studio/v1/building-blocks.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Rest;

use LeitviaStudio\Gateway\Building_Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the registered building blocks with whether they are installed, for screens of Studio and add-ons. Adding one
 * goes through the ability leitvia/add-building-block (POST /wp-abilities/v1/abilities/leitvia/add-building-block/run).
 */
final class Building_Blocks_Controller {

	/**
	 * Registers the route. Runs on rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			'leitvia-studio/v1',
			'/building-blocks',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn(): \WP_REST_Response => new \WP_REST_Response( Building_Blocks::all() ),
					'permission_callback' => array( Building_Blocks::class, 'can_set_up' ),
				),
				'schema' => array( self::class, 'schema' ),
			)
		);
	}

	/**
	 * Schema of one building block in the list.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$string = array(
			'type'     => 'string',
			'readonly' => true,
		);
		$list   = array(
			'type'     => 'array',
			'items'    => array( 'type' => 'string' ),
			'readonly' => true,
		);
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'leitvia-studio-building-block',
			'type'       => 'object',
			'properties' => array(
				'name'        => $string,
				'owner'       => $string,
				'title'       => $string,
				'description' => $string,
				'icon'        => $string,
				'installed'   => array(
					'description' => __( 'Whether every post type and taxonomy of the block is in the content model.', 'leitvia-studio' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
				'postTypes'   => $list,
				'taxonomies'  => $list,
				'patterns'    => $list,
				'sampleCount' => array(
					'description' => __( 'Number of sample entries the block brings.', 'leitvia-studio' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
			),
		);
	}
}
