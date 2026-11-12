<?php
/**
 * Setup abilities: token sets, style variations, content types, navigation, template parts and templates.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Admin\Settings;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Gateway\Recipes;
use LeitviaStudio\Gateway\Rejection;
use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;
use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Token_Sets;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/create-token-set, leitvia/activate-token-set, leitvia/set-variation, leitvia/define-content-type,
 * leitvia/update-site-values, leitvia/update-navigation, leitvia/update-template-part and leitvia/update-template.
 * Each one describes an operation for Pipeline::run() with the Studio building blocks: token sets with the contrast
 * lock, the compiler, the content model. Token sets and global styles belong to the area design, the rest to structure.
 */
final class Setup {

	/**
	 * Templates of WooCommerce for product categories and tags, open to update-template while WooCommerce is active.
	 */
	private const SHOP_TERM_TEMPLATES = array( 'taxonomy-product_cat', 'taxonomy-product_tag' );

	/**
	 * Settings of a core/navigation in a template part that update-template-part keeps, see keep_navigation().
	 */
	public const NAVIGATION_KEEP = array( 'submenuVisibility', 'overlay', 'overlayMenu', 'overlayBackgroundColor', 'overlayTextColor', 'ariaLabel' );

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
			__( 'Creates a token set from a DTCG document. Start from the document leitvia/get-tokens returns; base_hash is empty for the new set. It must compile against the contract and pass every token set check; contrast findings name the failing pair and the ratio it needs. Optional group size: page widths. Look at it with leitvia/preview-token-set, activate it with leitvia/activate-token-set.', 'leitvia-studio' ),
			'setup',
			$add,
			array(
				'properties' => array(
					'title'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => __( 'Name of the new token set.', 'leitvia-studio' ),
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
			__( 'Writes the presets of a token set into the global styles of the active theme. The set ID comes from leitvia/create-token-set or the sets of leitvia/get-tokens. The base hash is the hash of the global styles. leitvia/get-tokens returns it as hash.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'set' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Token set ID from the sets of leitvia/get-tokens or from leitvia/create-token-set.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'set' ),
			) + $object,
			array( self::class, 'describe_activate_token_set' )
		);
		Abilities::write(
			'leitvia/set-variation',
			__( 'Set style variation', 'leitvia-studio' ),
			__( 'Applies a style variation of the contract to the global styles of the active theme: a full variation replaces them, a colors or typography variation replaces only its part. Names come from the style variations of leitvia/get-contract. The base hash is the hash of the global styles. leitvia/get-tokens returns it as hash.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'variation' => array(
						'type'        => 'string',
						'pattern'     => '^[a-z0-9-]+$',
						'description' => __( 'Slug of a style variation from leitvia/get-contract.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'variation' ),
			) + $object,
			array( self::class, 'describe_set_variation' )
		);
		Abilities::write(
			'leitvia/set-posts-page',
			__( 'Set posts page', 'leitvia-studio' ),
			__( 'Makes an existing page the posts page of the reading settings, the address that lists all posts; 0 clears it. base_hash is empty. The page must exist, so create it first and wait until a package with it is applied. Like every setting it waits in a change package for a person. It takes effect with a static front page; notice says so while the site has none, or while the page is not published.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'page' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'ID of a page from leitvia/list-content, 0 to clear the setting.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'page' ),
			) + $object,
			array( self::class, 'describe_set_posts_page' )
		);
		Abilities::write(
			'leitvia/set-privacy-page',
			__( 'Set privacy policy page', 'leitvia-studio' ),
			__( 'Makes an existing page the privacy policy page of the site; 0 clears it. Contact forms, comment forms and the footer link to it. base_hash is empty. The page must exist, so create it first and wait until a package with it is applied. Like every setting it waits in a change package for a person. notice says so while the page is not published, and leitvia/audit-site reports contact forms without such a page.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'page' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'ID of a page from leitvia/list-content, 0 to clear the setting.', 'leitvia-studio' ),
					),
				),
				'required'   => array( 'page' ),
			) + $object,
			array( self::class, 'describe_set_privacy_page' )
		);
		Abilities::write(
			'leitvia/set-organization',
			__( 'Set organization details', 'leitvia-studio' ),
			__( 'Sets address, phone and email of the organization. Leitvia Studio adds them to its structured data while no SEO plugin is active; the name and the logo stay those of the site. Only the fields you send change, an empty string clears one. leitvia/get-seo returns the current values. base_hash is empty. Use only facts the person gave or the site shows. Like every setting it waits in a change package for a person.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'street'      => array( 'type' => 'string' ),
					'postal_code' => array( 'type' => 'string' ),
					'city'        => array( 'type' => 'string' ),
					'country'     => array(
						'type'    => 'string',
						'pattern' => '^([A-Z]{2})?$',
					),
					'phone'       => array( 'type' => 'string' ),
					'email'       => array( 'type' => 'string' ),
				),
			) + $object,
			array( self::class, 'describe_set_organization' )
		);
		Abilities::write(
			'leitvia/define-content-type',
			__( 'Define content type', 'leitvia-studio' ),
			__( 'Adds entries to the content model (postTypes, taxonomies, relations, settings) and keeps the others. An entry that exists is merged: the input sets its own keys and fields, fields, repeaters and supports it does not name stay, and the postTypes of a taxonomy are joined; relations and settings are replaced whole. Nothing is removed through this ability: an entry, field, repeater or supports value can only be removed by a person on the Model screen. Read the declaration with leitvia/get-content-model first. The base hash is the hash of the content model. leitvia/get-content-model returns it as hash. showInAbilities stays a decision of a person: agents may not set it.', 'leitvia-studio' ),
			'setup',
			$replace,
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
		Abilities::write(
			'leitvia/update-site-values',
			__( 'Update site values', 'leitvia-studio' ),
			__( 'Sets values of site settings of the content model declared with bindable true, the values blocks show on every page through the binding source leitvia/setting, such as opening hours or a phone number. values names them by key; the settings you leave out stay. Each value follows the field type of its setting, an empty string empties a text. leitvia/get-content-model returns the values as settings_values and their hash as settings_hash, the base_hash. Like every setting it waits in a change package for a person, and leitvia/rollback undoes it once applied. Declare a new setting with leitvia/define-content-type first.', 'leitvia-studio' ),
			'setup',
			array(
				'destructive' => true,
				'idempotent'  => false,
			),
			array(
				'properties' => array(
					'values' => array(
						'type'                 => 'object',
						'minProperties'        => 1,
						'maxProperties'        => 50,
						'description'          => __( 'New values by setting key, for example {"opening_hours": "Mon to Fri 8 to 18"}; only settings with bindable true.', 'leitvia-studio' ),
						'additionalProperties' => array( 'type' => array( 'string', 'number', 'boolean' ) ),
					),
				),
				'required'   => array( 'values' ),
			) + $object,
			array( self::class, 'describe_update_site_values' )
		);
		$item = array(
			'type'                 => 'object',
			'properties'           => array(
				'label' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 100,
					'description' => __( 'Text of the link; it names the target.', 'leitvia-studio' ),
				),
				'url'   => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 2000,
					'description' => __( 'Address of this site, https, mailto: or tel:.', 'leitvia-studio' ),
				),
				'items' => array(
					'type'     => 'array',
					'maxItems' => 30,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'label' => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 100,
								'description' => __( 'Text of the link; it names the target.', 'leitvia-studio' ),
							),
							'url'   => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 2000,
								'description' => __( 'Address of this site, https, mailto: or tel:.', 'leitvia-studio' ),
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
			__( 'Replaces the links of a navigation menu, by default the one published last, which headers without a chosen menu show. Send items (with one level of sub items) or block markup of navigation links, submenus, page lists and mega menus. Links point to this site, use https, mailto: or tel:, and need a text that names their target. The change publishes the menu, a draft one too, since core shows no draft menu. The base hash is the hash of the menu, empty when the site has none. leitvia/get-contract returns it per published menu under hashes.navigation.', 'leitvia-studio' ),
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
			__( 'Replaces a template part of the contract, such as header, footer or sidebar, with a pattern made for its area or with changed block markup. Part names come from leitvia/get-contract; with Polylang <part>-<language> (header-de) is the part the theme shows for that language and is created in the area of its part. Patterns come from leitvia/list-patterns. The change publishes the part, a draft one too: core shows no draft part, so where the area level is draft it waits in a change package. The base hash is the hash of the template part. leitvia/get-contract returns it per part under hashes.templateParts.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					'part'    => array(
						'type'        => 'string',
						'pattern'     => '^[a-z0-9-]+$',
						'description' => __( 'Name of a template part from leitvia/get-contract, such as header or footer.', 'leitvia-studio' ),
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
		Abilities::write(
			'leitvia/update-template',
			__( 'Update template', 'leitvia-studio' ),
			__( 'Replaces a template with a template pattern made for it or with changed block markup. Slugs, such as single, single-product or taxonomy-product_cat, come from the templates of leitvia/get-contract and the templates the theme ships; WooCommerce templates need WooCommerce. A pattern fits when its templateTypes hold the slug; the rejection leitvia_unknown_pattern lists the patterns that fit in patterns. Templates for single posts and pages keep a core/post-content block. The change publishes the template; where the area level is draft a new one waits in a change package. A rollback restores the template as it was, the one of the theme when there was no saved one. The base hash is the hash of the saved template, empty when the theme template has not been saved yet. leitvia/get-contract returns it per template under hashes.templates.', 'leitvia-studio' ),
			'setup',
			$replace,
			array(
				'properties' => array(
					// Template slugs of core and WooCommerce may hold an underscore: taxonomy-product_cat, taxonomy-product_tag.
					'template' => array(
						'type'        => 'string',
						'pattern'     => '^[a-z0-9_-]+$',
						'description' => __( 'Template slug from leitvia/get-contract or of the theme, such as single or taxonomy-product_cat.', 'leitvia-studio' ),
					),
					'pattern'  => array( 'type' => 'string' ),
					'content'  => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
				'required'   => array( 'template' ),
			) + $object,
			array( self::class, 'describe_update_template' )
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
	 * Operation of set-posts-page: the option page_for_posts, a page of the site or 0.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_posts_page( array $input ) {
		$page   = (int) ( $input['page'] ?? 0 );
		$target = array( 'option' => 'page_for_posts' );
		if ( $page && ( 'page' !== get_post_type( $page ) || ! Scope::readable( $page ) ) ) {
			return Block_Edit::invalid( 'page', __( 'There is no page with this ID that agents can read. Take the ID from leitvia/list-content.', 'leitvia-studio' ), $target + array( 'page' => $page ), 'leitvia/list-content' );
		}
		if ( $page && (int) get_option( 'page_on_front' ) === $page ) {
			return Block_Edit::invalid( 'page', __( 'The front page cannot be the posts page as well. Use another page.', 'leitvia-studio' ), $target + array( 'page' => $page ) );
		}
		$notices = array(
			$page && 'page' !== get_option( 'show_on_front' ) ? __( 'The posts page takes effect once the site has a static front page.', 'leitvia-studio' ) : '',
			$page && 'publish' !== get_post_status( $page ) ? __( 'The page is not published yet, so its address shows nothing to visitors.', 'leitvia-studio' ) : '',
		);
		return array(
			'area'      => 'structure',
			'target'    => $target,
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( 'page_for_posts' ) ) ),
			'notice'    => implode( ' ', array_filter( $notices ) ),
			'change'    => static fn(): array => array( 'value' => (string) $page ),
		);
	}

	/**
	 * Operation of set-privacy-page: the option wp_page_for_privacy_policy, a page of the site or 0.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_privacy_page( array $input ) {
		$page   = (int) ( $input['page'] ?? 0 );
		$target = array( 'option' => 'wp_page_for_privacy_policy' );
		if ( $page && ( 'page' !== get_post_type( $page ) || ! Scope::readable( $page ) ) ) {
			return Block_Edit::invalid( 'page', __( 'There is no page with this ID that agents can read. Take the ID from leitvia/list-content.', 'leitvia-studio' ), $target + array( 'page' => $page ), 'leitvia/list-content' );
		}
		return array(
			'area'      => 'structure',
			'target'    => $target,
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( 'wp_page_for_privacy_policy' ) ) ),
			'notice'    => $page && 'publish' !== get_post_status( $page ) ? __( 'The page is not published yet, so forms show no link to it.', 'leitvia-studio' ) : '',
			'change'    => static fn(): array => array( 'value' => (string) $page ),
		);
	}

	/**
	 * Operation of set-organization: the fields of the input over the saved Studio settings, which sanitize them.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_organization( array $input ) {
		$fields = array();
		foreach ( array( 'street', 'postal_code', 'city', 'country', 'phone', 'email' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$fields[ 'organization_' . $field ] = (string) $input[ $field ];
			}
		}
		$target = array( 'option' => Settings::OPTION );
		if ( ! $fields ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: street, postal_code, city, country, phone or email.', 'leitvia-studio' ), $target );
		}
		return array(
			'area'      => 'structure',
			'target'    => $target,
			'base_hash' => Hashes::of( (string) wp_json_encode( get_option( Settings::OPTION ) ) ),
			'change'    => static fn( array $state ): array => array( 'value' => $fields + (array) $state['value'] ),
		);
	}

	/**
	 * Operation of define-content-type: the entries of the input over the stored declaration, see merge_entry(). An
	 * entry keeps the showInAbilities a person set.
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
						if ( is_array( $entry ) && isset( $kept[ $key ] ) && is_array( $kept[ $key ] ) ) {
							$entries[ $key ] = self::merge_entry( $section, $kept[ $key ], $entry );
						}
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
	 * Stored values of the site settings of the content model with bindable true, by key; null for a value never set.
	 * The binding source leitvia/setting shows them to every visitor, so reading them reveals nothing.
	 *
	 * @return array<string, mixed>
	 */
	public static function site_settings(): array {
		$values = array();
		foreach ( (array) ( Model::current()['settings'] ?? array() ) as $key => $setting ) {
			if ( ! empty( $setting['bindable'] ) ) {
				$values[ (string) $key ] = get_option( Registrar::SETTING_PREFIX . $key, null );
			}
		}
		return $values;
	}

	/**
	 * Hash of the values of the bindable site settings, the base hash of update-site-values.
	 *
	 * @param array<string, mixed>|null $values Values from site_settings(), read again when null.
	 */
	public static function settings_hash( ?array $values = null ): string {
		return Hashes::of( (string) wp_json_encode( (object) ( $values ?? self::site_settings() ) ) );
	}

	/**
	 * Operations of update-site-values: one option operation per value, checked against the field type of its
	 * setting before anything runs, so a call changes all its settings or none. The base hash covers all bindable
	 * settings; each operation then carries the hash of its option, as the pipeline checks it.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_site_values( array $input ) {
		$settings = (array) ( Model::current()['settings'] ?? array() );
		$values   = (array) ( $input['values'] ?? array() );
		if ( ! $values ) {
			return Block_Edit::invalid( 'values', __( 'Name at least one setting in values.', 'leitvia-studio' ), array(), 'leitvia/get-content-model' );
		}
		$current = self::settings_hash();
		if ( ! hash_equals( $current, (string) ( $input['base_hash'] ?? '' ) ) ) {
			return Rejection::make(
				'leitvia_stale_hash',
				/* translators: %s: current hash of the site settings */
				sprintf( __( 'The site settings changed since they were read. Read them with leitvia/get-content-model and repeat the change on the current values with base_hash %s.', 'leitvia-studio' ), $current ),
				409,
				'stale_hash',
				array(),
				'leitvia/get-content-model',
				'once',
				array( 'current_hash' => $current )
			);
		}
		$batch = array();
		foreach ( $values as $key => $value ) {
			$key    = (string) $key;
			$option = Registrar::SETTING_PREFIX . $key;
			$target = array(
				'option'  => $option,
				'setting' => $key,
			);
			$field  = $settings[ $key ] ?? null;
			if ( ! is_array( $field ) || empty( $field['bindable'] ) ) {
				$bindable = array_map( 'strval', array_keys( self::site_settings() ) );
				return Block_Edit::invalid(
					'setting',
					$bindable
						/* translators: %s: list of setting keys */
						? sprintf( __( 'The content model has no bindable site setting with this key. Use one of these: %s.', 'leitvia-studio' ), implode( ', ', $bindable ) )
						: __( 'The content model has no bindable site setting. Declare one with bindable true through leitvia/define-content-type first.', 'leitvia-studio' ),
					$target,
					'leitvia/get-content-model'
				);
			}
			$schema = Registrar::schema_for( $field )[1];
			$valid  = rest_validate_value_from_schema( $value, $schema, $key );
			if ( is_wp_error( $valid ) ) {
				return Block_Edit::invalid( 'value', $valid->get_error_message(), $target, 'leitvia/get-content-model' );
			}
			$value = rest_sanitize_value_from_schema( $value, $schema, $key );
			if ( 'image' === $field['type'] && $value && ( ! wp_attachment_is_image( (int) $value ) || ! Scope::readable( (int) $value ) ) ) {
				return Block_Edit::invalid( 'value', __( 'The ID is no image of the media library.', 'leitvia-studio' ), $target, 'leitvia/list-media' );
			}
			$batch[] = array(
				'area'      => 'structure',
				'target'    => array( 'option' => $option ),
				'base_hash' => Hashes::of( (string) wp_json_encode( get_option( $option ) ) ),
				'change'    => static fn(): array => array( 'value' => $value ),
			);
		}
		return array( 'batch' => $batch );
	}

	/**
	 * A stored entry of the content model with the entry of the input over it. Post types and taxonomies merge at field
	 * level, so a declaration that is added again (a recipe or building block) keeps what a person added: the input sets
	 * its own keys and fields, every field, repeater and supports value it does not name stays, and the postTypes of a
	 * taxonomy are joined. Relations and settings are replaced whole, as they have no parts of their own.
	 *
	 * @param string               $section Section of the content model.
	 * @param array<string, mixed> $kept    Stored entry.
	 * @param array<string, mixed> $entry   Entry of the input.
	 * @return array<string, mixed>
	 */
	public static function merge_entry( string $section, array $kept, array $entry ): array {
		if ( ! in_array( $section, array( 'postTypes', 'taxonomies' ), true ) ) {
			return $entry;
		}
		$merged = $entry + $kept;
		$join   = static fn( string $key ): array => array_values( array_unique( array_merge( (array) ( $kept[ $key ] ?? array() ), (array) ( $entry[ $key ] ?? array() ) ) ) );
		if ( 'taxonomies' === $section ) {
			$merged['postTypes'] = $join( 'postTypes' );
			return $merged;
		}
		if ( isset( $kept['supports'], $entry['supports'] ) ) {
			$merged['supports'] = $join( 'supports' );
		}
		if ( isset( $kept['fields'] ) || isset( $entry['fields'] ) ) {
			$merged['fields'] = array_merge( (array) ( $kept['fields'] ?? array() ), (array) ( $entry['fields'] ?? array() ) );
		}
		if ( isset( $kept['repeaters'] ) || isset( $entry['repeaters'] ) ) {
			$repeaters = (array) ( $kept['repeaters'] ?? array() );
			foreach ( (array) ( $entry['repeaters'] ?? array() ) as $key => $repeater ) {
				$old               = (array) ( $repeaters[ $key ] ?? array() );
				$repeaters[ $key ] = (array) $repeater + $old;
				if ( isset( $old['fields'] ) || isset( $repeater['fields'] ) ) {
					$repeaters[ $key ]['fields'] = array_merge( (array) ( $old['fields'] ?? array() ), (array) ( $repeater['fields'] ?? array() ) );
				}
			}
			$merged['repeaters'] = $repeaters;
		}
		return $merged;
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
				// Core renders only a published menu: a draft one (from an earlier run) is published with the change.
				return array(
					'post_content' => $content,
					'post_status'  => 'publish',
				) + $new + $state;
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
		$name  = (string) ( $input['part'] ?? '' );
		$area  = null;
		$title = '';
		// With Polylang the theme shows <part>-<language> (header-de) in place of <part> while that language is shown.
		$languages = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( array( 'fields' => 'slug' ) ) : array();
		foreach ( (array) ( leitvia_studio_contract()->data()['templateParts'] ?? array() ) as $part ) {
			$base = (string) ( $part['name'] ?? '' );
			if ( $base === $name || ( str_starts_with( $name, $base . '-' ) && in_array( substr( $name, strlen( $base ) + 1 ), $languages, true ) ) ) {
				$area  = (string) ( $part['area'] ?? 'uncategorized' );
				$title = $base === $name ? '' : ucfirst( $base ) . ' (' . substr( $name, strlen( $base ) + 1 ) . ')';
			}
			if ( $base === $name ) {
				break;
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
		if ( isset( $input['content'] ) && $template ) {
			$content = self::keep_navigation( $content, (string) $template->content );
		}
		$id     = $template && $template->wp_id ? (int) $template->wp_id : 0;
		$target = $id ? array( 'post' => $id ) : array(
			'post'      => 0,
			'post_type' => 'wp_template_part',
		);
		// A part the theme ships as a file only gets a post with the terms core finds it by.
		$new = $id ? array() : array(
			'post_title' => $template ? (string) $template->title : ( '' !== $title ? $title : $name ),
			'post_name'  => $name,
			'tax_input'  => array(
				'wp_theme'              => array( get_stylesheet() ),
				'wp_template_part_area' => array( $area ),
			),
		);
		return array(
			'area'   => 'structure',
			'target' => $target,
			// Core renders only a published part: a draft one (from an earlier run) is published with the change.
			'change' => static fn( array $state ): array => array(
				'post_content' => $content,
				'post_status'  => 'publish',
			) + $new + $state,
		);
	}

	/**
	 * Content of a template part with the settings of its navigation kept: a core/navigation in the new content gets
	 * each of NAVIGATION_KEEP that the first navigation of the part had and it lacks (agents often send only the ref or
	 * the links, and the menu would lose its overlay, its submenus on click and its name). A value it sets stays.
	 * Patterns in the part are read through, since the theme ships its header as a pattern.
	 *
	 * @param string $content New content.
	 * @param string $old     Current content of the part.
	 */
	public static function keep_navigation( string $content, string $old ): string {
		$keep = array_intersect_key( self::first_navigation( parse_blocks( $old ) ), array_flip( self::NAVIGATION_KEEP ) );
		if ( ! $keep || ! str_contains( $content, 'wp:navigation' ) ) {
			return $content;
		}
		$changed = false;
		$walk    = static function ( array $blocks ) use ( &$walk, $keep, &$changed ): array {
			foreach ( $blocks as &$block ) {
				if ( 'core/navigation' === $block['blockName'] && array_diff_key( $keep, (array) $block['attrs'] ) ) {
					$block['attrs'] = (array) $block['attrs'] + $keep;
					$changed        = true;
				}
				$block['innerBlocks'] = $walk( (array) $block['innerBlocks'] );
			}
			return $blocks;
		};
		$blocks  = $walk( parse_blocks( $content ) );
		return $changed ? serialize_blocks( $blocks ) : $content;
	}

	/**
	 * Attributes of the first core/navigation of blocks, core/pattern blocks read through; empty without one.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @param int                                     $depth  Patterns read through so far.
	 * @return array<string, mixed>
	 */
	private static function first_navigation( array $blocks, int $depth = 0 ): array {
		foreach ( $blocks as $block ) {
			if ( 'core/navigation' === $block['blockName'] ) {
				return (array) $block['attrs'];
			}
			$inner = (array) $block['innerBlocks'];
			if ( 'core/pattern' === $block['blockName'] && $depth < 3 ) {
				$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) ( $block['attrs']['slug'] ?? '' ) );
				$inner   = $pattern ? parse_blocks( (string) $pattern['content'] ) : array();
			}
			$found = self::first_navigation( $inner, $depth + ( 'core/pattern' === $block['blockName'] ? 1 : 0 ) );
			if ( $found ) {
				return $found;
			}
		}
		return array();
	}

	/**
	 * Operation of update-template. Like update-template-part for wp_template: slugs of the contract (shop templates
	 * only while their plugins are active) and of the templates the theme or a plugin registers, patterns whose
	 * templateTypes hold the slug.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_template( array $input ) {
		$slug     = (string) ( $input['template'] ?? '' );
		$contract = array_column( (array) ( leitvia_studio_contract()->data()['templates'] ?? array() ), null, 'slug' );
		$known    = isset( $contract[ $slug ] ) ? '' === Recipes::missing( (array) $contract[ $slug ] ) : in_array( $slug, wp_list_pluck( get_block_templates(), 'slug' ), true );
		// The templates of product categories and tags are in the hierarchy of WooCommerce, which falls back to
		// archive-product; WooCommerce may not list them before one is saved.
		$known = $known || ( in_array( $slug, self::SHOP_TERM_TEMPLATES, true ) && '' === Recipes::missing( array( 'requires' => array( 'woocommerce' ) ) ) );
		if ( ! $known ) {
			return Block_Edit::invalid( 'template', __( 'Use a template of the contract or of the theme. WooCommerce templates need WooCommerce.', 'leitvia-studio' ), array( 'template' => $slug ), 'leitvia/get-contract' );
		}
		if ( isset( $input['pattern'] ) === isset( $input['content'] ) ) {
			return Block_Edit::invalid( 'change', __( 'Send either a pattern or content.', 'leitvia-studio' ), array( 'template' => $slug ) );
		}
		$content = (string) ( $input['content'] ?? '' );
		if ( isset( $input['pattern'] ) ) {
			$registry = \WP_Block_Patterns_Registry::get_instance();
			$fits     = static fn( $pattern ): bool => is_array( $pattern ) && in_array( $slug, (array) ( $pattern['templateTypes'] ?? array() ), true );
			$pattern  = $registry->get_registered( (string) $input['pattern'] );
			if ( ! is_array( $pattern ) || ! $fits( $pattern ) ) {
				// leitvia/list-patterns lists the patterns of the contract, template patterns of add-ons are not among them.
				$names = array_values( wp_list_pluck( array_filter( $registry->get_all_registered(), $fits ), 'name' ) );
				return Rejection::make( 'leitvia_unknown_pattern', __( 'There is no pattern with this name for this template. Use one of the patterns the rejection lists in patterns.', 'leitvia-studio' ), 404, 'pattern', array( 'pattern' => (string) $input['pattern'] ), '', 'once', array( 'patterns' => $names ) );
			}
			$content = (string) $pattern['content'];
		}
		// A template for single posts and pages that drops the content block shows every post without its text.
		$file = get_block_file_template( get_stylesheet() . '//' . $slug );
		$show = preg_match( '/^(page|single)(-|$)/', $slug ) && ( ! $file || self::shows_content( parse_blocks( (string) $file->content ) ) );
		if ( $show && ! self::shows_content( parse_blocks( $content ) ) ) {
			return Block_Edit::invalid( 'post_content', __( 'This template shows a single post or page: keep a core/post-content block in it, also inside a group or pattern.', 'leitvia-studio' ), array( 'template' => $slug ) );
		}
		$trashed = get_posts(
			array(
				'post_type'      => 'wp_template',
				'post_status'    => 'trash',
				// Core adds __trashed to the slug of a post it moves to the trash.
				'post_name__in'  => array( $slug, $slug . '__trashed' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One template.
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => get_stylesheet(),
					),
				),
			)
		);
		if ( $trashed ) {
			return Block_Edit::invalid( 'trashed', __( 'The saved version of this template is in the trash. A person restores it or empties the trash in the Site Editor first.', 'leitvia-studio' ), array( 'template' => $slug ) );
		}
		$template = get_block_template( get_stylesheet() . '//' . $slug );
		$id       = $template && $template->wp_id ? (int) $template->wp_id : 0;
		$target   = $id ? array( 'post' => $id ) : array(
			'post'      => 0,
			'post_type' => 'wp_template',
		);
		// A template the theme ships as a file only gets a post with the term core finds it by.
		$new = $id ? array() : array(
			'post_title' => $template ? (string) $template->title : $slug,
			'post_name'  => $slug,
			'tax_input'  => array( 'wp_theme' => array( get_stylesheet() ) ),
		);
		return array(
			'area'   => 'structure',
			'target' => $target,
			// A live write gets a package too, so leitvia/rollback undoes it and the template of the theme comes back.
			'record' => true,
			'change' => static fn( array $state ): array => array(
				'post_content' => $content,
				'post_status'  => 'publish',
			) + $new + $state,
		);
	}

	/**
	 * Whether blocks hold core/post-content, also inside other blocks and registered patterns (core/pattern).
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @param int          $depth  Patterns resolved so far, against patterns that include themselves.
	 */
	private static function shows_content( array $blocks, int $depth = 0 ): bool {
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( 'core/post-content' === $name || self::shows_content( (array) ( $block['innerBlocks'] ?? array() ), $depth ) ) {
				return true;
			}
			$pattern = 'core/pattern' === $name && $depth < 5 ? \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) ( $block['attrs']['slug'] ?? '' ) ) : null;
			if ( is_array( $pattern ) && self::shows_content( parse_blocks( (string) ( $pattern['content'] ?? '' ) ), $depth + 1 ) ) {
				return true;
			}
		}
		return false;
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
