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
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$base,
			'/packages/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Packages::detail( (int) $request['id'] ) ),
					'permission_callback' => $can,
					'args'                => $id,
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$base,
			'/packages/(?P<id>\d+)/apply',
			array(
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
				),
				'schema' => array( self::class, 'decision_schema' ),
			)
		);
		foreach ( array( 'reject', 'rollback' ) as $action ) {
			register_rest_route(
				$base,
				'/packages/(?P<id>\d+)/' . $action,
				array(
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Packages::$action( (int) $request['id'] ) ),
						'permission_callback' => $can,
						'args'                => $id,
					),
					'schema' => array( self::class, 'decision_schema' ),
				)
			);
		}
	}

	/**
	 * Schema of the answers of apply, reject and rollback: the package, its new state and what happened per operation.
	 *
	 * @return array<string, mixed>
	 */
	public static function decision_schema(): array {
		$ids  = array(
			'type'     => 'array',
			'items'    => array( 'type' => 'integer' ),
			'readonly' => true,
		);
		$list = array(
			'type'     => 'array',
			'items'    => array( 'type' => 'object' ),
			'readonly' => true,
		);
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'leitvia-studio-package-decision',
			'type'       => 'object',
			'properties' => array(
				'package'     => array(
					'description' => __( 'ID of the package.', 'leitvia-studio' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'state'       => array(
					'type'     => 'string',
					'enum'     => Packages::STATES,
					'readonly' => true,
				),
				'applied'     => array( 'description' => __( 'IDs of the operations written now, after apply.', 'leitvia-studio' ) ) + $ids,
				'conflicts'   => array( 'description' => __( 'Operations whose target changed since the package was made, with both hashes, after apply.', 'leitvia-studio' ) ) + $list,
				'errors'      => array( 'description' => __( 'Operations the pipeline refused, with code, message and findings, after apply.', 'leitvia-studio' ) ) + $list,
				'rolled_back' => array( 'description' => __( 'IDs of the operations rolled back, after rollback.', 'leitvia-studio' ) ) + $ids,
			),
		);
	}

	/**
	 * Schema of a change package in the list and in the detail view; only the detail has items.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		$integer = array(
			'type'     => 'integer',
			'readonly' => true,
		);
		$string  = array(
			'type'     => 'string',
			'readonly' => true,
		);
		if ( ! $this->schema ) {
			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'leitvia-studio-package',
				'type'       => 'object',
				'properties' => array(
					'id'         => $integer,
					'title'      => $string,
					'key'        => array( 'description' => __( 'Key the agent gave the package, empty without one.', 'leitvia-studio' ) ) + $string,
					'author'     => $integer,
					'agent'      => $string,
					'created'    => array( 'description' => __( 'Creation time in UTC, Y-m-d H:i:s.', 'leitvia-studio' ) ) + $string,
					'state'      => array( 'enum' => Packages::STATES ) + $string,
					'operations' => array(
						'description' => __( 'Number of operations per state.', 'leitvia-studio' ),
						'type'        => 'object',
						'readonly'    => true,
					),
					'preview'    => $string,
					'checks'     => array(
						'description' => __( 'Number of findings and the lowest composition score, null without one.', 'leitvia-studio' ),
						'type'        => 'object',
						'readonly'    => true,
					),
					'verify'     => array(
						'type'     => array( 'object', 'null' ),
						'readonly' => true,
					),
					'items'      => array(
						'description' => __( 'The operations, in the detail view only.', 'leitvia-studio' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'object' ),
						'readonly'    => true,
					),
				),
			);
		}
		return $this->add_additional_fields_schema( $this->schema );
	}
}
