<?php
/**
 * Read abilities for the design system: contract, tokens, patterns, content model and block catalog.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway;
use LeitviaStudio\Lab\Status;
use LeitviaStudio\Lab\Usage_Index;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Contrast;
use LeitviaStudio\Tokens\Dtcg;
use LeitviaStudio\Tokens\Token_Sets;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/get-contract, leitvia/get-tokens, leitvia/list-patterns, leitvia/get-pattern,
 * leitvia/get-content-model and leitvia/list-blocks: what only Leitvia knows, read from the existing classes.
 */
final class Read_Design {

	public const MAX_PER_PAGE = 50;

	public const MAX_USAGE = 20;

	/**
	 * Contract keys left out of get-contract; list-patterns returns the patterns.
	 */
	private const CONTRACT_SKIP = array( '$comment', 'patterns', 'studioPatterns', 'addonPatterns' );

	/**
	 * Registers the abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$string  = array( 'type' => 'string' );
		$objects = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		$none    = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
		$slug    = array(
			'type'        => 'string',
			'maxLength'   => 100,
			'description' => __( 'Filter by this value of the composition metadata.', 'leitvia-studio' ),
		);

		Gateway\Abilities::read(
			'leitvia/get-contract',
			__( 'Get contract', 'leitvia-studio' ),
			__( 'Returns the site this connection works on (address, REST base, name, language, whether it is part of a multisite network) and the design contract of the active theme: version, rules, color roles, contrast pairs, composition rules, page frames, template parts, templates, block styles and style variations. Compare site.url with the address of your task before the first write. Patterns come from list-patterns, blocks from list-blocks.', 'leitvia-studio' ),
			$none,
			array(
				'type'       => 'object',
				'required'   => array( 'site', 'version', 'contract' ),
				'properties' => array(
					'site'     => array(
						'type'       => 'object',
						'required'   => array( 'url', 'rest', 'name', 'language', 'multisite' ),
						'properties' => array(
							'url'       => $string,
							'rest'      => $string,
							'name'      => $string,
							'language'  => $string,
							'multisite' => array( 'type' => 'boolean' ),
						),
					),
					'version'  => $string,
					'contract' => array(
						'type'       => 'object',
						'properties' => array(
							'rules'            => array( 'type' => 'array' ),
							'roles'            => array( 'type' => 'object' ),
							'pairs'            => $objects,
							'compositionRules' => array( 'type' => 'object' ),
							'pageFrames'       => $objects,
							'templateParts'    => $objects,
							'templates'        => $objects,
							'blockStyles'      => $objects,
							'variations'       => $objects,
						),
					),
				),
			),
			static fn() => self::contract()
		);

		Gateway\Abilities::read(
			'leitvia/get-tokens',
			__( 'Get tokens', 'leitvia-studio' ),
			__( 'Returns the active design tokens as a DTCG document (of the active token set, else of the current global styles), the token sets with their status, the active style variations, whether the global styles drifted from the active set and the contrast findings of the current palette.', 'leitvia-studio' ),
			$none,
			array(
				'type'       => 'object',
				'required'   => array( 'active', 'source', 'document', 'sets', 'variations', 'drift', 'contrast' ),
				'properties' => array(
					'active'     => array( 'type' => 'integer' ),
					'source'     => array(
						'type' => 'string',
						'enum' => array( 'set', 'global_styles' ),
					),
					'document'   => array( 'type' => 'object' ),
					'sets'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'title'    => $string,
								'status'   => array(
									'type' => 'string',
									'enum' => array( 'active', 'inactive' ),
								),
								'modified' => $string,
							),
						),
					),
					'variations' => array(
						'type'  => 'array',
						'items' => $string,
					),
					'drift'      => array( 'type' => 'boolean' ),
					'contrast'   => $objects,
				),
			),
			static fn() => self::tokens()
		);

		Gateway\Abilities::read(
			'leitvia/list-patterns',
			__( 'List patterns', 'leitvia-studio' ),
			/* translators: %s: product name */
			sprintf( __( 'Lists the patterns of the theme, of %s and of its add-ons (source addon) with composition metadata, image slots, when to use and avoid them, lab status and the replacement of deprecated ones, followed by the Lab patterns (slug block:<ID>, source lab) with their lab status, version and document hash, and the composition of the theme pattern they vary. Only approved Lab patterns count as known in check-composition. Filter by role, tone, layout or category; at most 50 per page. Markup and usage come from get-pattern.', 'leitvia-studio' ), leitvia_studio_brand()['name'] ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'role'     => $slug,
					'tone'     => $slug,
					'layout'   => $slug,
					'category' => array(
						'type'        => 'string',
						'maxLength'   => 100,
						'description' => __( 'Filter by pattern category.', 'leitvia-studio' ),
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'required'   => array( 'items', 'total', 'pages' ),
				'properties' => array(
					'items' => $objects,
					'total' => array( 'type' => 'integer' ),
					'pages' => array( 'type' => 'integer' ),
				),
			),
			static fn( $input ) => self::list_patterns( is_array( $input ) ? $input : array() )
		);

		Gateway\Abilities::read(
			'leitvia/get-pattern',
			__( 'Get pattern', 'leitvia-studio' ),
			/* translators: %s: product name */
			sprintf( __( 'Returns one pattern of the theme or of %s with its markup, metadata and the first 20 posts and templates that use it (more is true when there are further ones).', 'leitvia-studio' ), leitvia_studio_brand()['name'] ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'slug' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => __( 'Pattern slug from list-patterns, for example leitvia/hero-split-image, or block:<ID> for a Lab pattern.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'slug' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'required'   => array( 'slug', 'content', 'usage' ),
				'properties' => array(
					'slug'    => $string,
					'content' => $string,
					'usage'   => array(
						'type'       => 'object',
						'properties' => array(
							'items' => $objects,
							'more'  => array( 'type' => 'boolean' ),
						),
					),
				),
			),
			static fn( $input ) => self::pattern( (string) ( $input['slug'] ?? '' ) )
		);

		Gateway\Abilities::read(
			'leitvia/get-content-model',
			__( 'Get content model', 'leitvia-studio' ),
			__( 'Returns the content model declaration: post types, taxonomies, fields, relations and site settings with their definitions, never content. valid is false when the declaration fails the schema and nothing of it is registered.', 'leitvia-studio' ),
			$none,
			array(
				'type'       => 'object',
				'required'   => array( 'declaration', 'valid', 'error' ),
				'properties' => array(
					'declaration' => array( 'type' => 'object' ),
					'valid'       => array( 'type' => 'boolean' ),
					'error'       => $string,
				),
			),
			static fn() => self::content_model()
		);

		Gateway\Abilities::read(
			'leitvia/list-blocks',
			__( 'List blocks', 'leitvia-studio' ),
			/* translators: %s: product name */
			sprintf( __( 'Returns the block catalog of %s and its add-ons with attribute schema and usage rules. With post_type only the blocks the editor allows for that post type.', 'leitvia-studio' ), leitvia_studio_brand()['name'] ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'maxLength'   => 20,
						'description' => __( 'Limit to the blocks allowed for this post type.', 'leitvia-studio' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'required'   => array( 'blocks' ),
				'properties' => array(
					'blocks' => array(
						'type'  => 'array',
						'items' => \LeitviaStudio\Blocks\Catalog::schema()['items'] ?? array( 'type' => 'object' ),
					),
				),
			),
			static fn( $input ) => self::blocks( (string) ( $input['post_type'] ?? '' ) )
		);
	}

	/**
	 * The contract without patterns, or a rejection when the active theme has no valid contract.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function contract() {
		$contract = leitvia_studio_contract();
		if ( ! $contract->is_valid() ) {
			return Gateway\Rejection::make( 'leitvia_no_contract', __( 'The active theme has no valid Leitvia contract. Activate the Leitvia theme or fix its contract file.', 'leitvia-studio' ), 409, 'contract', array(), '', 'never', array( 'error' => $contract->error() ) );
		}
		return array(
			// Paths such as /wp-json/ and /services/ start at this address; in a subdirectory network it has a path.
			'site'     => array(
				'url'       => home_url( '/' ),
				'rest'      => rest_url(),
				'name'      => get_bloginfo( 'name' ),
				'language'  => get_bloginfo( 'language' ),
				'multisite' => is_multisite(),
			),
			'version'  => $contract->version(),
			'contract' => array_diff_key( $contract->data(), array_flip( self::CONTRACT_SKIP ) ),
		);
	}

	/**
	 * Active tokens, token sets, active variations, drift and contrast findings.
	 *
	 * @return array<string, mixed>
	 */
	public static function tokens(): array {
		$contract = leitvia_studio_contract();
		$active   = (int) get_option( Compiler::ACTIVE_OPTION, 0 );
		$document = $active && current_user_can( 'read_post', $active ) ? Compiler::document( $active ) : null;
		$sets     = array();
		// Sets are private and need edit_theme_options; get_posts() keeps out what the user may not read.
		$posts = current_user_can( 'edit_theme_options' ) ? get_posts(
			array(
				'post_type'      => Token_Sets::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		) : array();
		foreach ( $posts as $post ) {
			$sets[] = array(
				'id'       => $post->ID,
				// The title as saved: get_the_title() adds "Private:" and entities to a set, which is always private.
				'title'    => html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'status'   => $post->ID === $active ? 'active' : 'inactive',
				'modified' => (string) get_post_modified_time( 'c', true, $post ),
			);
		}
		$is_set = is_array( $document );
		return array(
			'active'     => $active,
			'source'     => $is_set ? 'set' : 'global_styles',
			'document'   => $is_set ? $document : Dtcg::from_settings( wp_get_global_settings(), $contract ),
			'sets'       => $sets,
			'variations' => self::active_variations(),
			'drift'      => Compiler::drift(),
			'contrast'   => Contrast::findings( array_values( Dtcg::effective( wp_get_global_settings(), 'color', 'palette' ) ), $contract->pairs() ),
		);
	}

	/**
	 * Slugs of the contract variations whose data the user global styles contain, full and partial ones.
	 *
	 * @return list<string>
	 */
	private static function active_variations(): array {
		$styles = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$user   = json_decode( (string) ( $styles['post_content'] ?? '' ), true );
		$known  = array_column( leitvia_studio_contract()->variations(), 'slug' );
		$active = array();
		if ( ! is_array( $user ) ) {
			return $active;
		}
		foreach ( \WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			// Same slug rule as set-variation.
			$slug = $variation['slug'] ?? sanitize_title( (string) ( $variation['title'] ?? '' ) );
			$data = array_intersect_key( $variation, array_flip( array( 'settings', 'styles' ) ) );
			if ( $data && in_array( $slug, $known, true ) && self::contains( $user, $data ) ) {
				$active[] = (string) $slug;
			}
		}
		return $active;
	}

	/**
	 * Whether $data holds every value of $part; lists compare whole, as set-variation replaces them whole.
	 *
	 * @param array<mixed> $data Data.
	 * @param array<mixed> $part Part.
	 */
	private static function contains( array $data, array $part ): bool {
		foreach ( $part as $key => $value ) {
			if ( ! array_key_exists( $key, $data ) ) {
				return false;
			}
			$nested = is_array( $value ) && ! array_is_list( $value ) && is_array( $data[ $key ] );
			// Loose on scalars: the JSON round trip turns 1.0 into 1.
			if ( $nested ? ! self::contains( $data[ $key ], $value ) : $data[ $key ] != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- See above.
				return false;
			}
		}
		return true;
	}

	/**
	 * Registered patterns of the theme, of Studio and of add-ons as described in the contract, then the Lab patterns
	 * agents may read, by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function patterns(): array {
		$data     = leitvia_studio_contract()->data();
		$registry = \WP_Block_Patterns_Registry::get_instance();
		$items    = array();
		foreach ( array(
			'patterns'       => 'theme',
			'studioPatterns' => 'studio',
			'addonPatterns'  => 'addon',
		) as $key => $source ) {
			foreach ( (array) ( $data[ $key ] ?? array() ) as $meta ) {
				$slug       = (string) ( $meta['slug'] ?? '' );
				$registered = '' !== $slug ? $registry->get_registered( $slug ) : null;
				if ( ! $registered ) {
					continue;
				}
				$ref            = 'pattern:' . $slug;
				$replaced       = (string) ( $meta['docs']['replacedBy'] ?? '' );
				$items[ $slug ] = array(
					'slug'        => $slug,
					'title'       => (string) ( $meta['title'] ?? $registered['title'] ),
					'description' => (string) ( $meta['description'] ?? '' ),
					'source'      => $source,
					'area'        => (string) ( $meta['area'] ?? '' ),
					'categories'  => array_values( (array) ( $registered['categories'] ?? array() ) ),
					'composition' => (array) ( $meta['composition'] ?? array() ),
					'images'      => array_values( (array) ( $meta['images'] ?? array() ) ),
					'useWhen'     => (string) ( $meta['useWhen'] ?? '' ),
					'avoidWhen'   => (string) ( $meta['docs']['avoidWhen'] ?? '' ),
					'status'      => Status::effective( $ref ),
					'replacedBy'  => '' === $replaced ? null : $replaced,
				);
			}
		}
		// ponytail: all Lab patterns per call; a Lab holds dozens, page in the query when it holds thousands.
		$lab = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		foreach ( array_filter( $lab, array( Gateway\Scope::class, 'readable' ) ) as $post ) {
			$ref           = 'block:' . $post->ID;
			$replaced      = (string) get_post_meta( $post->ID, '_leitvia_lab_replaced_by', true );
			$from          = Gateway\Composition::source( $post->ID, $data );
			$categories    = wp_get_post_terms( $post->ID, 'wp_pattern_category', array( 'fields' => 'slugs' ) );
			$items[ $ref ] = array(
				'slug'        => $ref,
				'title'       => $post->post_title,
				'description' => '',
				'source'      => 'lab',
				'area'        => (string) ( $from['area'] ?? '' ),
				'categories'  => is_array( $categories ) ? $categories : array(),
				'composition' => (array) ( $from['composition'] ?? array() ),
				'images'      => array(),
				'useWhen'     => '',
				'avoidWhen'   => '',
				'status'      => Status::effective( $ref ),
				'replacedBy'  => '' === $replaced ? null : (string) preg_replace( '/^pattern:/', '', $replaced ),
				'version'     => (string) get_post_meta( $post->ID, '_leitvia_lab_version', true ),
				'hash'        => Gateway\Hashes::document( $post->ID ),
			);
		}
		return $items;
	}

	/**
	 * One page of patterns after the filters.
	 *
	 * @param array<string, mixed> $input Input of list-patterns.
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int}
	 */
	public static function list_patterns( array $input ): array {
		$items = array_values(
			array_filter(
				self::patterns(),
				static function ( array $item ) use ( $input ): bool {
					foreach ( array( 'role', 'tone', 'layout' ) as $key ) {
						if ( '' !== (string) ( $input[ $key ] ?? '' ) && ( $item['composition'][ $key ] ?? null ) !== $input[ $key ] ) {
							return false;
						}
					}
					return '' === (string) ( $input['category'] ?? '' ) || in_array( $input['category'], $item['categories'], true );
				}
			)
		);
		// Core does not cast input outside REST.
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $input['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		return array(
			'items' => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total' => count( $items ),
			'pages' => (int) ceil( count( $items ) / $per_page ),
		);
	}

	/**
	 * One pattern with markup and usage, or a rejection for an unknown slug.
	 *
	 * @param string $slug Pattern slug.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function pattern( string $slug ) {
		$item = self::patterns()[ $slug ] ?? null;
		if ( ! $item ) {
			return Gateway\Rejection::make( 'leitvia_not_found', __( 'There is no pattern with this slug. List the patterns for the current slugs.', 'leitvia-studio' ), 404, 'pattern', array( 'pattern' => $slug ), 'leitvia/list-patterns' );
		}
		$more  = false;
		$lab   = 'lab' === $item['source'];
		$usage = Usage_Index::used_by( $lab ? $slug : 'pattern:' . $slug, $more );
		return $item + array(
			'content' => $lab ? (string) get_post_field( 'post_content', (int) substr( $slug, 6 ), 'raw' ) : (string) ( \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug )['content'] ?? '' ),
			'usage'   => array(
				'items' => array_slice( $usage, 0, self::MAX_USAGE ),
				'more'  => $more || count( $usage ) > self::MAX_USAGE,
			),
		);
	}

	/**
	 * The content model declaration and whether it passes the schema.
	 *
	 * @return array{declaration: array<string, mixed>, valid: bool, error: string}
	 */
	public static function content_model(): array {
		$declaration = Model::current();
		$valid       = Model::validate( $declaration );
		return array(
			'declaration' => $declaration,
			'valid'       => true === $valid,
			'error'       => is_wp_error( $valid ) ? $valid->get_error_message() : '',
		);
	}

	/**
	 * The block catalog, limited to the blocks the editor allows for a post type when one is given.
	 *
	 * @param string $post_type Post type, '' for all blocks.
	 * @return array{blocks: list<array<string, mixed>>}|\WP_Error
	 */
	public static function blocks( string $post_type ) {
		$blocks = \LeitviaStudio\Blocks\Catalog::get();
		if ( '' === $post_type ) {
			return array( 'blocks' => $blocks );
		}
		if ( ! in_array( $post_type, Gateway\Scope::post_types(), true ) ) {
			return Gateway\Block_Edit::invalid( 'post_type', __( 'This post type is not open to agents. Leave post_type empty or use an allowed post type.', 'leitvia-studio' ), array( 'post_type' => $post_type ) );
		}
		// A new post of the type stands in for the post being edited, as filters of allowed_block_types_all expect one.
		$post    = new \WP_Post(
			(object) array(
				'ID'          => 0,
				'post_type'   => $post_type,
				'post_status' => 'auto-draft',
				'filter'      => 'raw',
			)
		);
		$allowed = get_allowed_block_types(
			new \WP_Block_Editor_Context(
				array(
					'name' => 'core/edit-post',
					'post' => $post,
				)
			)
		);
		if ( true === $allowed ) {
			return array( 'blocks' => $blocks );
		}
		$allowed = is_array( $allowed ) ? $allowed : array();
		return array( 'blocks' => array_values( array_filter( $blocks, static fn( array $block ): bool => in_array( $block['name'], $allowed, true ) ) ) );
	}
}
