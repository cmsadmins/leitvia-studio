<?php
/**
 * Setup abilities: token sets, style variations, content types, navigation and template parts.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Gateway\Rejection;
use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Token_Sets;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/create-token-set, leitvia/activate-token-set, leitvia/set-variation, leitvia/define-content-type,
 * leitvia/update-navigation and leitvia/update-template-part. Each one describes an operation for Pipeline::run() with
 * the Studio building blocks: token sets with the contrast lock, the compiler, the content model. Token sets and
 * global styles belong to the area design, the rest to structure.
 */
final class Setup {

	/**
	 * Blocks a navigation may hold at its own level and inside submenus.
	 */
	public const NAVIGATION_BLOCKS = array( 'core/navigation-link', 'core/navigation-submenu', 'core/page-list', 'leitvia/mega-menu' );

	/**
	 * Registers the abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$replace = array(
			'destructive' => true,
			'idempotent'  => false,
		);
		$add     = array(
			'destructive' => false,
			'idempotent'  => false,
		);
		$object  = array(
			'type'                 => 'object',
			'additionalProperties' => false,
		);
		Abilities::write(
			'leitvia/create-token-set',
			__( 'Create token set', 'leitvia-studio' ),
			__( 'Creates a token set from a DTCG document. Start from the document leitvia/get-tokens returns; base_hash is empty for the new set. It must compile against the contract and pass every token set check; contrast findings name the failing pair and the ratio it needs. Activate it with leitvia/activate-token-set.', 'leitvia-studio' ),
			'setup',
			$add,
			array(
				'properties' => array(
					'title'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'document' => array(
						'type'        => 'object',
						'description' => __( 'DTCG token document, as leitvia/get-tokens returns it.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'title', 'document' ),
			) + $object,
			array( self::class, 'describe_create_token_set' )
		);
		Abilities::write(
			'leitvia/activate-token-set',
			__( 'Activate token set', 'leitvia-studio' ),
			__( 'Writes the presets of a token set into the global styles of the active theme. The set ID comes from leitvia/create-token-set or the sets of leitvia/get-tokens. The base hash is the hash of the global styles. No ability returns this hash: send base_hash empty and repeat the call once with current_hash from leitvia_stale_hash.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'set' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'   => array( 'set' ),
			) + $object,
			array( self::class, 'describe_activate_token_set' )
		);
		Abilities::write(
			'leitvia/set-variation',
			__( 'Set style variation', 'leitvia-studio' ),
			__( 'Applies a style variation of the contract to the global styles of the active theme: a full variation replaces them, a colors or typography variation replaces only its part. Names come from the style variations of leitvia/get-contract. The base hash is the hash of the global styles. No ability returns this hash: send base_hash empty and repeat the call once with current_hash from leitvia_stale_hash.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'variation' => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]+$',
					),
				),
				'required'   => array( 'variation' ),
			) + $object,
			array( self::class, 'describe_set_variation' )
		);
		Abilities::write(
			'leitvia/define-content-type',
			__( 'Define content type', 'leitvia-studio' ),
			__( 'Adds or replaces entries of the content model (postTypes, taxonomies, relations, settings) and keeps the others. Read the declaration with leitvia/get-content-model first. The base hash is the hash of the content model. No ability returns this hash: send base_hash empty and repeat the call once with current_hash from leitvia_stale_hash. showInAbilities stays a decision of a person: agents may not set it.', 'leitvia-studio' ),
			'setup',
			$add,
			array(
				'properties' => array(
					'declaration' => array(
						'type'        => 'object',
						'description' => __( 'Content model declaration with the entries to add or replace, see leitvia/get-content-model.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'declaration' ),
			) + $object,
			array( self::class, 'describe_define_content_type' )
		);
		$item = array(
			'type'                 => 'object',
			'properties'           => array(
				'label' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100,
				),
				'url'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 2000,
				),
				'items' => array(
					'type'     => 'array',
					'maxItems' => 30,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'label' => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 100,
							),
							'url'   => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 2000,
							),
						),
						'required'             => array( 'label', 'url' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'label', 'url' ),
			'additionalProperties' => false,
		);
		Abilities::write(
			'leitvia/update-navigation',
			__( 'Update navigation', 'leitvia-studio' ),
			__( 'Replaces the links of a navigation menu, by default the one published last, which headers without a chosen menu show. Send items (with one level of sub items) or block markup of navigation links, submenus, page lists and mega menus. Links point to this site, use https, mailto: or tel:, and need a text that names their target. The base hash is the hash of the menu, empty when the site has none. No ability returns this hash: send base_hash empty and repeat the call once with current_hash from leitvia_stale_hash.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'navigation' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'items'      => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 30,
						'items'    => $item,
					),
					'content'    => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
			) + $object,
			array( self::class, 'describe_update_navigation' )
		);
		Abilities::write(
			'leitvia/update-template-part',
			__( 'Update template part', 'leitvia-studio' ),
			__( 'Replaces a template part of the contract, such as header, footer or sidebar, with a pattern made for its area or with changed block markup. Part names come from leitvia/get-contract, patterns from leitvia/list-patterns. The base hash is the hash of the template part. No ability returns this hash: send base_hash empty and repeat the call once with current_hash from leitvia_stale_hash.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'part'    => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]+$',
					),
					'pattern' => array( 'type' => 'string' ),
					'content' => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
				'required'   => array( 'part' ),
			) + $object,
			array( self::class, 'describe_update_template_part' )
		);
	}

	/**
	 * Operation of create-token-set.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function describe_create_token_set( array $input ): array {
		$json = (string) wp_json_encode( $input['document'] ?? array() );
		// The same title and document again (a recipe applied twice) target the set that exists; nothing is added.
		$same = array_filter(
			get_posts(
				array(
					'post_type'      => Token_Sets::POST_TYPE,
					'post_status'    => 'private',
					'title'          => (string) ( $input['title'] ?? '' ),
					'fields'         => 'ids',
					'posts_per_page' => 20,
					'no_found_rows'  => true,
				)
			),
			static fn( int $id ): bool => json_decode( (string) get_post_meta( $id, Token_Sets::META, true ), true ) == ( $input['document'] ?? array() ) // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Key order of the stored JSON may differ.
		);
		if ( $same ) {
			return array(
				'area'   => 'design',
				'target' => array( 'post' => (int) reset( $same ) ),
				'change' => static fn( array $state ): array => $state,
			);
		}
		return array(
			'area'   => 'design',
			'target' => array(
				'post'      => 0,
				'post_type' => Token_Sets::POST_TYPE,
			),
			'change' => static function ( array $state ) use ( $json, $input ) {
				$valid = Token_Sets::validate( $json );
				if ( is_wp_error( $valid ) ) {
					return self::token_findings( $valid );
				}
				return array(
					'post_title'  => (string) $input['title'],
					'post_status' => 'private',
					'meta_input'  => array( Token_Sets::META => $json ),
				) + $state;
			},
		);
	}

	/**
	 * Operation of activate-token-set.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_activate_token_set( array $input ) {
		$set = (int) ( $input['set'] ?? 0 );
		if ( Token_Sets::POST_TYPE !== get_post_type( $set ) || ! Scope::readable( $set ) ) {
			return Block_Edit::invalid( 'set', __( 'There is no token set with this ID.', 'leitvia-studio' ), array( 'post' => $set ), 'leitvia/get-tokens' );
		}
		return self::styles(
			array( 'token_set' => $set ),
			static function ( array $data ) use ( $set ) {
				$styles = Compiler::styles( $set, $data );
				if ( is_wp_error( $styles ) && 'leitvia_studio_token_set_invalid' === $styles->get_error_code() ) {
					return self::token_findings( $styles );
				}
				return is_wp_error( $styles ) ? Block_Edit::invalid( 'set', $styles->get_error_message(), array( 'post' => $set ), 'leitvia/get-tokens' ) : $styles;
			}
		);
	}

	/**
	 * Operation of set-variation.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_variation( array $input ) {
		$slug = (string) ( $input['variation'] ?? '' );
		$kind = '';
		foreach ( leitvia_studio_contract()->variations() as $variation ) {
			if ( ( $variation['slug'] ?? null ) === $slug ) {
				$kind = (string) ( $variation['kind'] ?? 'full' );
			}
		}
		$data = null;
		foreach ( \WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			if ( ( $variation['slug'] ?? sanitize_title( (string) ( $variation['title'] ?? '' ) ) ) === $slug ) {
				$data = array_intersect_key( $variation, array_flip( array( 'settings', 'styles' ) ) );
			}
		}
		if ( '' === $kind || null === $data ) {
			return Block_Edit::invalid( 'variation', __( 'The contract has no style variation with this slug.', 'leitvia-studio' ), array( 'variation' => $slug ), 'leitvia/get-contract' );
		}
		return self::styles(
			array(),
			static fn( array $current ): array => array(
				'version'                     => \WP_Theme_JSON::LATEST_SCHEMA,
				'isGlobalStylesUserThemeJSON' => true,
			) + ( 'full' === $kind ? $data : self::merge( $current, $data ) )
		);
	}

	/**
	 * Operation of define-content-type: the entries of the input over the stored declaration. A replaced entry keeps
	 * the showInAbilities a person set.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_define_content_type( array $input ) {
		$decl = (array) ( $input['declaration'] ?? array() );
		if ( self::has_key( $decl, 'showInAbilities' ) ) {
			return Rejection::make( 'leitvia_forbidden_field', __( 'Agents may not set showInAbilities. Whether content is open to AI abilities is the decision of a person; remove the field and try again.', 'leitvia-studio' ), 403, 'show_in_abilities', array( 'option' => Pipeline::MODEL_OPTION ) );
		}
		return array(
			'area'   => 'structure',
			'target' => array( 'option' => Pipeline::MODEL_OPTION ),
			'change' => static function ( array $state ) use ( $decl ) {
				$stored = is_array( $state['value'] ) ? $state['value'] : array();
				$merged = array( 'version' => 1 );
				foreach ( Model::SECTIONS as $section ) {
					$kept    = (array) ( $stored[ $section ] ?? array() );
					$entries = (array) ( $decl[ $section ] ?? array() );
					foreach ( $entries as $key => $entry ) {
						if ( is_array( $entry ) && isset( $kept[ $key ]['showInAbilities'] ) ) {
							$entries[ $key ]['showInAbilities'] = $kept[ $key ]['showInAbilities'];
						}
					}
					if ( $entries + $kept ) {
						$merged[ $section ] = $entries + $kept;
					}
				}
				$valid = Model::save( $merged, true );
				if ( is_wp_error( $valid ) ) {
					$findings = array();
					foreach ( (array) ( $valid->get_error_data()['errors'] ?? array() ) as $error ) {
						$findings[] = array(
							'check'    => 'leitvia/content-model',
							'severity' => 'error',
							'block'    => (string) ( $error['path'] ?? '' ),
							'message'  => (string) ( $error['message'] ?? $valid->get_error_message() ),
							'fix'      => __( 'Change the entry at this path of the declaration.', 'leitvia-studio' ),
						);
					}
					return self::check_failed( array( 'option' => Pipeline::MODEL_OPTION ), $findings, 'leitvia/get-content-model' );
				}
				return array( 'value' => $merged );
			},
		);
	}

	/**
	 * Operation of update-navigation.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_navigation( array $input ) {
		if ( isset( $input['items'] ) === isset( $input['content'] ) ) {
			return Block_Edit::invalid( 'change', __( 'Send either items or content.', 'leitvia-studio' ) );
		}
		$id = (int) ( $input['navigation'] ?? 0 );
		if ( $id && 'wp_navigation' !== get_post_type( $id ) ) {
			return Block_Edit::invalid( 'navigation', __( 'There is no navigation menu with this ID.', 'leitvia-studio' ), array( 'post' => $id ) );
		}
		if ( ! $id ) {
			$latest = get_posts(
				array(
					'post_type'      => 'wp_navigation',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$id     = (int) ( $latest[0] ?? 0 );
		}
		$content = isset( $input['content'] ) ? (string) $input['content'] : serialize_blocks( self::links( (array) $input['items'] ) );
		$target  = $id ? array( 'post' => $id ) : array(
			'post'      => 0,
			'post_type' => 'wp_navigation',
		);
		return array(
			'area'   => 'structure',
			'target' => $target,
			'change' => static function ( array $state ) use ( $content, $target ) {
				$findings = self::navigation_findings( parse_blocks( $content ), 'p:' );
				if ( $findings ) {
					return self::check_failed( $target, $findings, 'leitvia/list-blocks' );
				}
				$new = $state['post_title'] ? array() : array( 'post_title' => __( 'Navigation', 'leitvia-studio' ) );
				return array( 'post_content' => $content ) + $new + $state;
			},
		);
	}

	/**
	 * Operation of update-template-part.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_template_part( array $input ) {
		$name = (string) ( $input['part'] ?? '' );
		$area = null;
		foreach ( (array) ( leitvia_studio_contract()->data()['templateParts'] ?? array() ) as $part ) {
			if ( ( $part['name'] ?? null ) === $name ) {
				$area = (string) ( $part['area'] ?? 'uncategorized' );
			}
		}
		if ( null === $area ) {
			return Block_Edit::invalid( 'part', __( 'The contract has no template part with this name.', 'leitvia-studio' ), array( 'part' => $name ), 'leitvia/get-contract' );
		}
		if ( isset( $input['pattern'] ) === isset( $input['content'] ) ) {
			return Block_Edit::invalid( 'change', __( 'Send either a pattern or content.', 'leitvia-studio' ), array( 'part' => $name ) );
		}
		$content = (string) ( $input['content'] ?? '' );
		if ( isset( $input['pattern'] ) ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $input['pattern'] );
			$fits    = $pattern && ( in_array( 'core/template-part/' . $area, (array) ( $pattern['blockTypes'] ?? array() ), true ) || array_intersect( array( $name, $area ), (array) ( $pattern['categories'] ?? array() ) ) );
			if ( ! $fits ) {
				return Rejection::make( 'leitvia_unknown_pattern', __( 'There is no pattern with this name for this template part. List the patterns of its area and use one of them.', 'leitvia-studio' ), 404, 'pattern', array( 'pattern' => (string) $input['pattern'] ), 'leitvia/list-patterns' );
			}
			$content = (string) $pattern['content'];
		}
		$template = get_block_template( get_stylesheet() . '//' . $name, 'wp_template_part' );
		$id       = $template && $template->wp_id ? (int) $template->wp_id : 0;
		$target   = $id ? array( 'post' => $id ) : array(
			'post'      => 0,
			'post_type' => 'wp_template_part',
		);
		// A part the theme ships as a file only gets a post with the terms core finds it by.
		$new = $id ? array() : array(
			'post_title' => $template ? (string) $template->title : $name,
			'post_name'  => $name,
			'tax_input'  => array(
				'wp_theme'              => array( get_stylesheet() ),
				'wp_template_part_area' => array( $area ),
			),
		);
		return array(
			'area'   => 'structure',
			'target' => $target,
			'change' => static fn( array $state ): array => array( 'post_content' => $content ) + $new + $state,
		);
	}

	/**
	 * Operation on the user global styles of the active theme, a new post when there is none yet.
	 *
	 * @param array<string, mixed> $target Further target keys, token_set for an activation.
	 * @param callable             $build  Gets the current data, returns the new data or a WP_Error.
	 * @return array<string, mixed>
	 */
	private static function styles( array $target, callable $build ): array {
		$post = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$id   = (int) ( $post['ID'] ?? 0 );
		$new  = $id ? array() : array(
			'post_title'  => 'Custom Styles',
			'post_name'   => 'wp-global-styles-' . rawurlencode( get_stylesheet() ),
			'post_status' => 'publish',
			'tax_input'   => array( 'wp_theme' => array( get_stylesheet() ) ),
		);
		return array(
			'area'   => 'design',
			'target' => ( $id ? array( 'post' => $id ) : array(
				'post'      => 0,
				'post_type' => 'wp_global_styles',
			) ) + $target,
			'change' => static function ( array $state ) use ( $build, $new ) {
				$current = json_decode( (string) $state['post_content'], true );
				$data    = $build( is_array( $current ) ? $current : array() );
				return is_wp_error( $data ) ? $data : array( 'post_content' => (string) wp_json_encode( $data ) ) + $new + $state;
			},
		);
	}

	/**
	 * The data with each section of the partial variation laid over it; lists such as palettes are replaced whole.
	 *
	 * @param array<mixed> $data    Current data.
	 * @param array<mixed> $partial Variation data.
	 * @return array<mixed>
	 */
	private static function merge( array $data, array $partial ): array {
		foreach ( $partial as $key => $value ) {
			$data[ $key ] = is_array( $value ) && ! array_is_list( $value ) && is_array( $data[ $key ] ?? null ) ? self::merge( $data[ $key ], $value ) : $value;
		}
		return $data;
	}

	/**
	 * Navigation link blocks of items.
	 *
	 * @param array<mixed> $items Items with label, url and sub items.
	 * @return list<array{blockName: string, attrs: array<string, string>, innerBlocks: list<array<string, mixed>>, innerHTML: string, innerContent: list<null>}>
	 */
	private static function links( array $items ): array {
		$blocks = array();
		foreach ( $items as $item ) {
			$inner    = self::links( (array) ( $item['items'] ?? array() ) );
			$blocks[] = array(
				'blockName'    => $inner ? 'core/navigation-submenu' : 'core/navigation-link',
				'attrs'        => array(
					'label' => (string) $item['label'],
					'url'   => (string) $item['url'],
					'kind'  => 'custom',
				),
				'innerBlocks'  => $inner,
				'innerHTML'    => '',
				'innerContent' => $inner ? array_fill( 0, count( $inner ), null ) : array(),
			);
		}
		return $blocks;
	}

	/**
	 * Findings for navigation markup: only NAVIGATION_BLOCKS at the navigation level and in submenus (a mega menu holds
	 * what it likes), link targets on this site, https, mailto: or tel:, and the link text check of the content checks.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                                  $path   Path prefix.
	 * @return list<array<string, mixed>>
	 */
	private static function navigation_findings( array $blocks, string $path ): array {
		$findings = array();
		$index    = 0;
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' === $name && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}
			$id = (string) ( $block['attrs']['metadata']['leitviaId'] ?? $path . $index );
			++$index;
			if ( ! in_array( $name, self::NAVIGATION_BLOCKS, true ) ) {
				$findings[] = self::finding( $id, __( 'A navigation holds only navigation links, submenus, page lists and mega menus.', 'leitvia-studio' ), __( 'Remove the block or turn it into a navigation link.', 'leitvia-studio' ) );
				continue;
			}
			if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
				$url = (string) ( $block['attrs']['url'] ?? '' );
				if ( ! self::link_target( $url ) ) {
					$findings[] = self::finding( $id, __( 'The link goes to another site without https, or to no page.', 'leitvia-studio' ), __( 'Link to a page of this site, an https address, an email address (mailto:) or a phone number (tel:).', 'leitvia-studio' ) );
				}
				$link = '<!-- wp:paragraph --><p><a href="' . esc_url( $url ) . '">' . esc_html( wp_strip_all_tags( (string) ( $block['attrs']['label'] ?? '' ) ) ) . '</a></p><!-- /wp:paragraph -->';
				foreach ( Content_Checks::link_text( $link, array( 'post_type' => 'page' ) ) as $finding ) {
					$findings[] = array( 'block' => $id ) + $finding;
				}
			}
			if ( 'core/navigation-submenu' === $name ) {
				array_push( $findings, ...self::navigation_findings( (array) $block['innerBlocks'], $path . ( $index - 1 ) . '.' ) );
			}
		}
		return $findings;
	}

	/**
	 * Whether a link target is on this site (a path or the own host), uses https, or is an email address (mailto:) or a
	 * phone number (tel:). A backslash never: browsers read /\host as another host.
	 *
	 * @param string $url URL.
	 */
	private static function link_target( string $url ): bool {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( str_contains( $url, '\\' ) ) {
			return false;
		}
		if ( 'mailto' === $scheme ) {
			return (bool) is_email( rawurldecode( explode( '?', substr( $url, 7 ) )[0] ) );
		}
		if ( 'tel' === $scheme ) {
			return (bool) preg_match( '#^\+?[0-9][0-9 ().\-/]{2,30}$#D', rawurldecode( substr( $url, 4 ) ) );
		}
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		return 'https' === $scheme || strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host;
	}

	/**
	 * A navigation finding.
	 *
	 * @param string $block   Block ID.
	 * @param string $message Message.
	 * @param string $fix     Fix.
	 * @return array<string, string>
	 */
	private static function finding( string $block, string $message, string $fix ): array {
		return array(
			'check'    => 'leitvia/navigation',
			'severity' => 'error',
			'block'    => $block,
			'message'  => $message,
			'fix'      => $fix,
		);
	}

	/**
	 * Rejection leitvia_check_failed for the findings of a token set, each with a fix; contrast findings name the
	 * colors to change and the ratio the pair needs.
	 *
	 * @param \WP_Error $error Error of Token_Sets::validate().
	 */
	private static function token_findings( \WP_Error $error ): \WP_Error {
		$findings = array();
		foreach ( (array) ( $error->get_error_data()['findings'] ?? array() ) as $finding ) {
			$data       = (array) ( $finding['data'] ?? array() );
			$findings[] = $finding + array(
				'fix' => 'contrast' === ( $finding['code'] ?? '' )
					/* translators: 1: foreground role, 2: background role, 3: required ratio. */
					? sprintf( __( 'Darken or lighten %1$s or %2$s until the pair reaches %3$s:1.', 'leitvia-studio' ), (string) ( $data['fg'] ?? '' ), (string) ( $data['bg'] ?? '' ), (string) ( $data['min'] ?? '' ) )
					: __( 'Correct the token document as the message says.', 'leitvia-studio' ),
			);
		}
		return self::check_failed( array(), $findings, 'leitvia/get-tokens' );
	}

	/**
	 * Rejection leitvia_check_failed in the format of the pipeline.
	 *
	 * @param array<string, mixed>       $target       Target.
	 * @param list<array<string, mixed>> $findings     Findings.
	 * @param string                     $next_ability Next ability.
	 */
	private static function check_failed( array $target, array $findings, string $next_ability ): \WP_Error {
		return Rejection::make( 'leitvia_check_failed', __( 'Checks found problems. Fix them as each finding suggests and try again.', 'leitvia-studio' ), 422, 'checks', $target, $next_ability, 'once', array( 'findings' => $findings ) );
	}

	/**
	 * Whether a key occurs anywhere in nested arrays.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $key  Key.
	 */
	private static function has_key( array $data, string $key ): bool {
		foreach ( $data as $name => $value ) {
			if ( $key === $name || ( is_array( $value ) && self::has_key( $value, $key ) ) ) {
				return true;
			}
		}
		return false;
	}
}
