<?php
/**
 * REST route listing add-ons and registry entries.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Rest;

use LeitviaStudio\Extension\Studio;

defined( 'ABSPATH' ) || exit;

/**
 * GET /leitvia-studio/v1/extensions for administrators.
 */
final class Extensions_Controller {

	/**
	 * Creates the controller.
	 *
	 * @param Studio $studio The Extension API.
	 */
	public function __construct( private Studio $studio ) {}

	/**
	 * Registers the route. Runs on rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			'leitvia-studio/v1',
			'/extensions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				),
				'schema' => array( self::class, 'schema' ),
			)
		);
	}

	/**
	 * Lists all add-ons, compatible or not, and per registry the entry names with their owner. Callables stay out.
	 */
	public function get_items(): \WP_REST_Response {
		$registries = array();
		foreach ( array_keys( Studio::FIELDS ) as $type ) {
			$registries[ $type ] = array_values(
				array_map(
					static fn( array $item ): array => array(
						'name'  => $item['name'],
						'owner' => $item['owner'],
					),
					$this->studio->registry( $type )->all()
				)
			);
		}
		return new \WP_REST_Response(
			array(
				'extensions' => array_values( $this->studio->extensions()->all() ),
				'registries' => $registries,
			)
		);
	}

	/**
	 * Schema of the answer.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'leitvia-studio-extensions',
			'type'       => 'object',
			'properties' => array(
				'extensions' => array(
					'description' => __( 'Every registered add-on, compatible or not.', 'leitvia-studio' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'readonly'    => true,
				),
				'registries' => array(
					'description' => __( 'Per registry the entry names with their owner.', 'leitvia-studio' ),
					'type'        => 'object',
					'readonly'    => true,
				),
			),
		);
	}
}
