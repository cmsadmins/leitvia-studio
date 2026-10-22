<?php
/**
 * REST routes of the pattern lab.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Rest;

use LeitviaStudio\Admin\Admin;
use LeitviaStudio\Lab\Pattern_IO;
use LeitviaStudio\Lab\Pattern_Meta;
use LeitviaStudio\Lab\Preview;
use LeitviaStudio\Lab\Status;
use LeitviaStudio\Lab\Token_Lint;
use LeitviaStudio\Lab\Usage_Index;

defined( 'ABSPATH' ) || exit;

/**
 * GET /leitvia-studio/v1/patterns, POST /patterns/<id>/variants, POST /patterns/<id>/checks, GET or POST
 * /patterns/states, pattern export and import, annotations, the usage of a pattern and the rebuild of the usage index.
 */
final class Lab_Controller extends \WP_REST_Controller {

	/**
	 * Largest accessibility result stored, in bytes of JSON.
	 */
	private const MAX_CHECKS_BYTES = 262144;

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'leitvia-studio/v1',
			'/patterns',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/patterns/(?P<id>\d+)/variants',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'id'    => array(
							'type'     => 'integer',
							'required' => true,
						),
						'state' => Pattern_Meta::schema()['_leitvia_lab_state'],
						'title' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/patterns/(?P<id>\d+)/checks',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_checks' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'id'  => array(
							'type'     => 'integer',
							'required' => true,
						),
						'axe' => array(
							'required'          => true,
							// Runs before the schema walks the value: an oversized body is refused by its length. Core
							// answers every invalid parameter with 400 and names this error in data.details.
							'validate_callback' => static fn( $value, \WP_REST_Request $request, string $param ) => strlen( $request->get_body() ) > self::MAX_CHECKS_BYTES
								? new \WP_Error( 'leitvia_studio_checks_too_large', __( 'The check results are too large to store.', 'leitvia-studio' ), array( 'status' => 400 ) )
								: rest_validate_request_arg( $value, $request, $param ),
						) + Pattern_Meta::schema()['_leitvia_lab_checks']['properties']['axe'],
					),
				),
			)
		);
		$ref = array(
			'description' => __( 'block:<ID> of an original wp_block or pattern:<slug> of a registered pattern.', 'leitvia-studio' ),
			'type'        => 'string',
			'pattern'     => '^(?:block:[1-9]\d*|pattern:[a-z0-9_.-]+/[a-z0-9_.-]+)$',
			'required'    => true,
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/patterns/states',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_states' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array( 'ref' => $ref ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_state' ),
					'permission_callback' => array( $this, 'create_state_permissions_check' ),
					'args'                => array(
						'ref'   => $ref,
						'state' => array(
							'type'     => 'string',
							'enum'     => array_values( array_filter( Pattern_Meta::schema()['_leitvia_lab_state']['enum'] ) ),
							'required' => true,
						),
					),
				),
			)
		);
		$id = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/patterns/(?P<id>\d+)/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Pattern_IO::export( (int) $request['id'] ) ),
				'permission_callback' => array( $this, 'edit_pattern_permissions_check' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/patterns/(?P<id>\d+)/annotations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response(
					array(
						'names'       => Preview::names( (string) get_post_field( 'post_content', (int) $request['id'] ) ),
						'annotations' => Preview::annotations( (int) $request['id'] ),
					)
				),
				'permission_callback' => array( $this, 'edit_pattern_permissions_check' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/patterns/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => static function (): bool {
					$type = get_post_type_object( 'wp_block' );
					return $type && current_user_can( $type->cap->create_posts );
				},
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/usage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'usage' ),
				// A wp_block reference also needs read rights on that pattern.
				'permission_callback' => static fn( \WP_REST_Request $request ): bool => current_user_can( 'edit_posts' )
					&& ( ! preg_match( '/^block:(\d+)$/D', (string) $request['ref'], $match ) || current_user_can( 'read_post', (int) $match[1] ) ),
				'args'                => array(
					'ref' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^(?:block:[1-9]\d*|pattern:[a-z0-9-]+/[a-z0-9-]+)$',
					),
				),
			)
		);
		register_rest_route(
			'leitvia-studio/v1',
			'/usage/reindex',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( Usage_Index::reindex( (int) $request['after'] ) ),
				'permission_callback' => static fn(): bool => current_user_can( Admin::SCREENS['leitvia-studio']['capability'] ),
				'args'                => array(
					'after' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
		register_rest_field(
			Admin::status_types(),
			'leitvia_pattern_status',
			array(
				'get_callback' => static fn( array $post ): ?string => current_user_can( 'edit_posts' ) ? Usage_Index::post_status( (int) $post['id'] ) : null,
				'schema'       => array(
					'description' => __( 'Least mature effective lab status of the patterns the post uses, empty when it uses none.', 'leitvia-studio' ),
					'type'        => array( 'string', 'null' ),
					'enum'        => array( '', 'draft', 'review', 'approved', null ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * Export and annotations need edit rights on the wp_block.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function edit_pattern_permissions_check( $request ): bool {
		$id = (int) $request['id'];
		return 'wp_block' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Imports a pattern file sent as the request body.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( $request ) {
		$result = Pattern_IO::import( $request->get_body() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response(
			$this->user_item( $result['id'] ) + array(
				'imported_variants' => count( $result['variants'] ),
				'failed_variants'   => $result['failed_variants'],
			)
		);
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( 'wp/v2/blocks/' . $result['id'] ) );
		return $response;
	}

	/**
	 * Where a pattern is used and which patterns it uses.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response
	 */
	public function usage( $request ) {
		$ref  = (string) $request['ref'];
		$uses = array();
		foreach ( Usage_Index::uses( $ref ) as $part ) {
			if ( ! self::readable( $part ) ) {
				// Patterns of others the user may not read stay a bare reference.
				$uses[] = array(
					'ref'               => $part,
					'title'             => $part,
					'status'            => '',
					'effective_status'  => '',
					'replaced_by'       => '',
					'replaced_by_title' => '',
				);
				continue;
			}
			$replaced_by = self::replacement( $part );
			$uses[]      = array(
				'ref'               => $part,
				'title'             => self::title( $part ),
				'status'            => Status::own( $part ),
				'effective_status'  => Status::effective( $part ),
				'replaced_by'       => $replaced_by,
				'replaced_by_title' => '' === $replaced_by ? '' : self::title( $replaced_by ),
			);
		}
		$used_by = Usage_Index::used_by( $ref, $more );
		return rest_ensure_response(
			array(
				'used_by'      => $used_by,
				'used_by_more' => (bool) $more,
				'uses'         => $uses,
				'index_built'  => Usage_Index::is_built(),
			)
		);
	}

	/**
	 * Whether the current user may read a pattern reference; registered patterns are public.
	 *
	 * @param string $ref Pattern reference.
	 */
	private static function readable( string $ref ): bool {
		return str_starts_with( $ref, 'pattern:' ) || current_user_can( 'read_post', (int) substr( $ref, 6 ) );
	}

	/**
	 * Title of a pattern reference, the reference itself when the pattern is unknown or not readable.
	 *
	 * @param string $ref Pattern reference.
	 */
	private static function title( string $ref ): string {
		if ( str_starts_with( $ref, 'pattern:' ) ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( substr( $ref, 8 ) );
			return (string) ( $pattern['title'] ?? $ref );
		}
		$id = (int) substr( $ref, 6 );
		return 'wp_block' === get_post_type( $id ) && self::readable( $ref ) ? get_the_title( $id ) : $ref;
	}

	/**
	 * Replacement of a deprecated pattern, '' otherwise.
	 *
	 * @param string $ref Pattern reference.
	 */
	private static function replacement( string $ref ): string {
		if ( 'deprecated' !== Status::own( $ref ) ) {
			return '';
		}
		if ( str_starts_with( $ref, 'pattern:' ) ) {
			// The contract names the replacing pattern by slug.
			$pattern = leitvia_studio_contract()->pattern( substr( $ref, 8 ) );
			$slug    = (string) ( $pattern['docs']['replacedBy'] ?? '' );
			return '' === $slug || str_contains( $slug, ':' ) ? $slug : 'pattern:' . $slug;
		}
		return (string) get_post_meta( (int) substr( $ref, 6 ), '_leitvia_lab_replaced_by', true );
	}

	/**
	 * Original of a reference: the ID of a wp_block or the slug of a registered pattern.
	 *
	 * @param string $ref block:<ID> or pattern:<slug>.
	 * @return int|string
	 */
	private static function original( string $ref ) {
		return str_starts_with( $ref, 'block:' ) ? (int) substr( $ref, 6 ) : substr( $ref, 8 );
	}

	/**
	 * States of own patterns need edit rights on the original, states of theme patterns the right to create patterns.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	public function create_state_permissions_check( $request ) {
		$original = self::original( (string) $request['ref'] );
		$type     = get_post_type_object( 'wp_block' );
		return is_int( $original ) ? current_user_can( 'edit_post', $original ) : ( $type && current_user_can( $type->cap->create_posts ) );
	}

	/**
	 * States of a pattern the user may edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response
	 */
	public function get_states( $request ) {
		$ids = array_filter( Pattern_Meta::states( self::original( (string) $request['ref'] ) ), static fn( int $id ): bool => current_user_can( 'edit_post', $id ) );
		return rest_ensure_response( array_values( array_map( array( $this, 'user_item' ), $ids ) ) );
	}

	/**
	 * Creates a state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_state( $request ) {
		$id = Pattern_Meta::create_state( self::original( (string) $request['ref'] ), (string) $request['state'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$response = rest_ensure_response( $this->user_item( $id ) );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( 'wp/v2/blocks/' . $id ) );
		return $response;
	}

	/**
	 * Stores the accessibility results of the Lab in _leitvia_lab_checks.axe. They are shown only; the binding check
	 * stays the Playwright suite.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_checks( $request ) {
		$id = (int) $request['id'];
		if ( 'wp_block' !== get_post_type( $id ) ) {
			return new \WP_Error( 'leitvia_studio_no_pattern', __( 'The pattern does not exist.', 'leitvia-studio' ), array( 'status' => 404 ) );
		}
		$axe                      = (array) $request['axe'];
		$checks                   = get_post_meta( $id, '_leitvia_lab_checks', true );
		$checks                   = is_array( $checks ) ? $checks : array();
		$checks['axe']            = $axe;
		$checks['axe_checked_at'] = gmdate( 'c' );
		update_post_meta( $id, '_leitvia_lab_checks', wp_slash( $checks ) );
		return rest_ensure_response( $checks );
	}

	/**
	 * Users who may edit posts see the lab.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature of WP_REST_Controller.
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Variants need edit rights on the original.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	public function create_item_permissions_check( $request ) {
		return current_user_can( 'edit_post', (int) $request['id'] );
	}

	/**
	 * Creates a variant.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$id = Pattern_Meta::create_variant( (int) $request['id'], (string) $request['state'], (string) $request['title'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$response = rest_ensure_response( $this->user_item( $id ) );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( 'wp/v2/blocks/' . $id ) );
		return $response;
	}

	/**
	 * Theme patterns and wp_block originals, filtered and paged.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$search = strtolower( (string) $request['search'] );
		$status = (array) ( $request['status'] ?? array() );
		$source = (string) $request['source'];
		$parent = (int) $request['variant_of'];
		$theme  = 'user' !== $source;
		$user   = 'theme' !== $source;
		// Patterns of the active theme and own patterns come first, patterns of core and plugins last.
		$groups = $theme && ! $parent ? $this->theme_items() : array( array(), array() );
		$all    = $parent ? $this->variant_items( $parent ) : array_merge( $groups[0], $user ? $this->user_items() : array(), $groups[1] );
		$items  = array_filter(
			$all,
			static fn( array $item ): bool => ( '' === $search || str_contains( strtolower( $item['title'] . ' ' . $item['ref'] ), $search ) )
				&& ( ! $status || in_array( $item['status'], $status, true ) )
		);

		$per_page = (int) $request['per_page'];
		$total    = count( $items );
		$response = rest_ensure_response( array_slice( array_values( $items ), ( (int) $request['page'] - 1 ) * $per_page, $per_page ) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );
		return $response;
	}

	/**
	 * Registered patterns: every pattern of the active theme, hidden ones included, and other patterns shown in the
	 * inserter.
	 *
	 * Core drops filePath once a pattern's content is loaded, which may already have happened in this request; a name
	 * prefixed with the theme slug counts as a theme pattern too.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} Patterns of the active theme, then the others.
	 */
	private function theme_items(): array {
		$states = $this->theme_states();
		$dirs   = array( wp_normalize_path( get_stylesheet_directory() ) . '/', wp_normalize_path( get_template_directory() ) . '/' );
		$items  = array( array(), array() );
		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$file     = wp_normalize_path( (string) ( $pattern['filePath'] ?? '' ) );
			$in_theme = str_starts_with( $file, $dirs[0] ) || str_starts_with( $file, $dirs[1] )
				|| str_starts_with( $pattern['name'], get_stylesheet() . '/' ) || str_starts_with( $pattern['name'], get_template() . '/' );
			if ( ! $in_theme && false === ( $pattern['inserter'] ?? true ) ) {
				continue;
			}
			$ref                          = 'pattern:' . $pattern['name'];
			$contract                     = leitvia_studio_contract()->pattern( $pattern['name'] );
			$items[ $in_theme ? 0 : 1 ][] = array(
				'ref'              => $ref,
				'title'            => (string) $pattern['title'],
				'source'           => 'theme',
				'categories'       => array_values( (array) ( $pattern['categories'] ?? array() ) ),
				'status'           => Status::own( $ref ),
				'effective_status' => Status::effective( $ref ),
				'version'          => '',
				'replaced_by'      => (string) ( $contract['docs']['replacedBy'] ?? '' ),
				'variant_count'    => 0,
				'state'            => '',
				'checks'           => array( 'tokens' => Token_Lint::result( (string) ( $pattern['content'] ?? '' ) ) ) + ( $states[ $pattern['name'] ]['checks'] ?? array() ),
				'modified'         => $states[ $pattern['name'] ]['modified'] ?? '',
				'edit_url'         => '',
			);
		}
		return $items;
	}

	/**
	 * Accessibility results and latest change of the states of theme patterns, by pattern slug. The results of all
	 * states are merged; the check date is the oldest one, so one outdated state marks the pattern outdated.
	 *
	 * @return array<string, array{checks: array<string, mixed>, modified: string}>
	 */
	private function theme_states(): array {
		$states  = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => '_leitvia_lab_source_pattern', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- States are few; the key exists only on states of theme patterns.
			)
		);
		$by_slug = array();
		foreach ( $states as $state ) {
			$slug     = (string) get_post_meta( $state->ID, '_leitvia_lab_source_pattern', true );
			$entry    = $by_slug[ $slug ] ?? array(
				'checks'   => array(),
				'modified' => '',
			);
			$modified = self::modified( $state->ID );
			if ( $modified > $entry['modified'] ) {
				$entry['modified'] = $modified;
			}
			$checks = get_post_meta( $state->ID, '_leitvia_lab_checks', true );
			if ( is_array( $checks ) && is_array( $checks['axe'] ?? null ) && is_string( $checks['axe_checked_at'] ?? null ) ) {
				$entry['checks']['axe']            = array_merge( $entry['checks']['axe'] ?? array(), $checks['axe'] );
				$entry['checks']['axe_checked_at'] = min( $entry['checks']['axe_checked_at'] ?? $checks['axe_checked_at'], $checks['axe_checked_at'] );
			}
			$by_slug[ $slug ] = $entry;
		}
		return $by_slug;
	}

	/**
	 * Last change of a post as an ISO 8601 date in UTC, comparable with axe_checked_at.
	 *
	 * @param int $id Post ID.
	 */
	private static function modified( int $id ): string {
		return gmdate( 'c', (int) strtotime( get_post_field( 'post_modified_gmt', $id ) . ' UTC' ) );
	}

	/**
	 * Original wp_block patterns the user may edit.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function user_items(): array {
		// ponytail: loads every pattern and variant at once; move paging into WP_Query when sites reach thousands.
		$query    = array(
			'post_type'      => 'wp_block',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);
		$counts   = array();
		$modified = array();
		foreach ( get_posts( array( 'meta_query' => array( array( 'key' => '_leitvia_lab_variant_of', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ) ) ) + $query ) as $variant ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One query for all variant counts.
			$original            = (int) get_post_meta( $variant->ID, '_leitvia_lab_variant_of', true );
			$counts[ $original ] = ( $counts[ $original ] ?? 0 ) + 1;
			// The accessibility check of an original covers its states.
			if ( '' !== get_post_meta( $variant->ID, '_leitvia_lab_state', true ) ) {
				$modified[ $original ] = max( $modified[ $original ] ?? '', self::modified( $variant->ID ) );
			}
		}

		$items = array();
		foreach ( get_posts( array( 'meta_query' => Pattern_Meta::originals_query() ) + $query ) as $post ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Variants are left out.
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$items[] = $this->user_item( $post->ID, $counts[ $post->ID ] ?? 0, $modified[ $post->ID ] ?? '' );
			}
		}
		return $items;
	}

	/**
	 * Variants of one wp_block the user may edit.
	 *
	 * @param int $original ID of the original pattern.
	 * @return list<array<string, mixed>>
	 */
	private function variant_items( int $original ): array {
		$ids = array_filter(
			Pattern_Meta::variants( $original ),
			static fn( int $id ): bool => in_array( get_post_status( $id ), array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) && current_user_can( 'edit_post', $id )
		);
		return array_values( array_map( array( $this, 'user_item' ), $ids ) );
	}

	/**
	 * One wp_block as a lab item.
	 *
	 * @param int    $id            Post ID.
	 * @param int    $variant_count Number of variants.
	 * @param string $modified      Latest change of its states, '' for none.
	 * @return array<string, mixed>
	 */
	private function user_item( int $id, int $variant_count = 0, string $modified = '' ): array {
		$ref   = 'block:' . $id;
		$terms = wp_get_object_terms( $id, 'wp_pattern_category', array( 'fields' => 'slugs' ) );
		return array(
			'ref'              => $ref,
			'title'            => get_the_title( $id ),
			'source'           => 'user',
			'categories'       => is_array( $terms ) ? $terms : array(),
			'status'           => Status::own( $ref ),
			'effective_status' => Status::effective( $ref ),
			'version'          => (string) get_post_meta( $id, '_leitvia_lab_version', true ),
			'replaced_by'      => (string) get_post_meta( $id, '_leitvia_lab_replaced_by', true ),
			'variant_count'    => $variant_count,
			'state'            => (string) get_post_meta( $id, '_leitvia_lab_state', true ),
			'checks'           => (array) get_post_meta( $id, '_leitvia_lab_checks', true ),
			'modified'         => max( self::modified( $id ), $modified ),
			'edit_url'         => (string) get_edit_post_link( $id, 'raw' ),
		);
	}

	/**
	 * Query parameters of the collection.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params() {
		$params               = parent::get_collection_params();
		$params['status']     = array(
			'description' => __( 'Limit to patterns with one of these own statuses.', 'leitvia-studio' ),
			'type'        => 'array',
			'items'       => Pattern_Meta::schema()['_leitvia_lab_status'],
			'default'     => array(),
		);
		$params['source']     = array(
			'description' => __( 'Limit to theme or user patterns.', 'leitvia-studio' ),
			'type'        => 'string',
			'enum'        => array( '', 'theme', 'user' ),
			'default'     => '',
		);
		$params['variant_of'] = array(
			'description' => __( 'List the variants of this wp_block instead of the patterns.', 'leitvia-studio' ),
			'type'        => 'integer',
			'minimum'     => 0,
			'default'     => 0,
		);
		unset( $params['context'] );
		return $params;
	}

	/**
	 * Schema of one item.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		if ( ! $this->schema ) {
			$meta         = Pattern_Meta::schema();
			$string       = array(
				'type'     => 'string',
				'readonly' => true,
			);
			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'leitvia-studio-pattern',
				'type'       => 'object',
				'properties' => array(
					'ref'              => array( 'description' => __( 'block:<ID> or pattern:<slug>.', 'leitvia-studio' ) ) + $string,
					'title'            => $string,
					'source'           => array( 'enum' => array( 'theme', 'user' ) ) + $string,
					'categories'       => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'readonly' => true,
					),
					'status'           => array( 'enum' => $meta['_leitvia_lab_status']['enum'] ) + $string,
					'effective_status' => array( 'enum' => $meta['_leitvia_lab_status']['enum'] ) + $string,
					'version'          => $string,
					'replaced_by'      => $string,
					'variant_count'    => array(
						'type'     => 'integer',
						'readonly' => true,
					),
					'state'            => array( 'enum' => $meta['_leitvia_lab_state']['enum'] ) + $string,
					'checks'           => array( 'readonly' => true ) + $meta['_leitvia_lab_checks'],
					'modified'         => array( 'description' => __( 'Latest change of the pattern and its states in UTC, empty when unknown. Accessibility results older than this are outdated.', 'leitvia-studio' ) ) + $string,
					'edit_url'         => $string,
				),
			);
		}
		return $this->add_additional_fields_schema( $this->schema );
	}
}
