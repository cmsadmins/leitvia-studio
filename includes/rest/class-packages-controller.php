<?php
/**
 * REST routes /leitvia-studio/v1/packages for the inbox.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Rest;

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Packages;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, shows, applies, rejects and rolls back change packages for people with edit_theme_options, never agents. Cookie requests
 * need the REST nonce as always; applying and rolling back run every operation through the pipeline.
 */
final class Packages_Controller extends \WP_REST_Controller {

	/**
	 * Registers the routes. Runs on rest_api_init.
	 */
	public function register_routes(): void {
		// Agents have edit_theme_options for their own work; deciding on packages belongs to people.
		$can  = static fn(): bool => current_user_can( 'edit_theme_options' ) && ! Gateway::is_agent();
		$id   = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
		$base = 'leitvia-studio/v1';
		register_rest_route(
			$base,
			'/packages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Packages::all( 0, (string) $request['state'] ) ),
				'permission_callback' => $can,
				'args'                => array(
					'state' => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), Packages::STATES ),
					),
				),
			)
		);
		register_rest_route(
			$base,
			'/packages/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Packages::detail( (int) $request['id'] ) ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);
		register_rest_route(
			$base,
			'/packages/(?P<id>\d+)/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Packages::apply( (int) $request['id'], array_map( 'intval', (array) $request['operations'] ) ) ),
				'permission_callback' => $can,
				'args'                => $id + array(
					'operations' => array(
						'type'    => 'array',
						'items'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'default' => array(),
					),
				),
			)
		);
		foreach ( array( 'reject', 'rollback' ) as $action ) {
			register_rest_route(
				$base,
				'/packages/(?P<id>\d+)/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Packages::$action( (int) $request['id'] ) ),
					'permission_callback' => $can,
					'args'                => $id,
				)
			);
		}
	}
}
