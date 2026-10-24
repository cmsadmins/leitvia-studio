<?php
/**
 * Site recipes: bundles of setup and pages, applied as one change package.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Tokens\Compiler;

defined( 'ABSPATH' ) || exit;

/**
 * Recipes live in the Studio registry recipes (label, path to a recipe.json, see schemas/recipe.schema.json). Studio
 * registers its base recipes there like an add-on would. Applying a recipe calls the setup and build abilities with
 * one package key, so every operation that does not go live lands in the same change package; with a key from the
 * caller nothing goes live (see Pipeline). Reading settings and the data-saving options run through Pipeline::run() as
 * option operations; one that points to a page waiting in the package is set when the package is applied (see
 * Packages::apply()). Nothing is written past the pipeline.
 *
 * Steps and the abilities they call, in this order: contentModel (leitvia/define-content-type with declaration),
 * tokenSet (leitvia/create-token-set with title and document), variation (leitvia/set-variation with variation),
 * templateParts (leitvia/update-template-part with part and pattern, or with content when the pattern holds sample
 * links to "#": the footer gets the pages of footerNavigation instead), pages (leitvia/compose-page), navigation
 * (leitvia/update-navigation with items of label and url), reading, connect (settings that point to recipe pages)
 * and options. A step whose ability is not registered is reported as unavailable.
 */
final class Recipes {

	/**
	 * Base recipes, folders under recipes/.
	 */
	public const BASE = array( 'blog', 'company', 'publication', 'documentation', 'shop' );

	/**
	 * Options a recipe may set: reading settings and, from the schema, options that can only be turned off.
	 */
	public const READING = array( 'show_on_front', 'page_on_front', 'page_for_posts' );

	/**
	 * Settings that point to a recipe page: the privacy policy page and the terms page of WooCommerce.
	 */
	public const CONNECT = array( 'wp_page_for_privacy_policy', 'woocommerce_terms_page_id' );

	/**
	 * What the recipes wrote, by part (variation, navigation, part:<name>): the post and its hash after the write, or
	 * the package and operation that waits for it. A part changed since then is kept on the next run.
	 */
	public const WRITES_OPTION = 'leitvia_studio_recipe_writes';

	/**
	 * Package key of the recipe run without a key from the caller: it collects what the autonomy puts into a package.
	 *
	 * @var string
	 */
	private static string $collect = '';

	/**
	 * Registers the base recipes in the registry recipes. Runs on leitvia_studio_init.
	 *
	 * @param \LeitviaStudio\Extension\Studio $studio Studio.
	 */
	public static function register( \LeitviaStudio\Extension\Studio $studio ): void {
		foreach ( self::BASE as $name ) {
			$studio->registry( 'recipes' )->register(
				'leitvia/' . $name,
				array(
					'label' => ucfirst( $name ),
					'path'  => dirname( LEITVIA_STUDIO_FILE ) . '/recipes/' . $name . '/recipe.json',
				),
				'leitvia-studio'
			);
		}
	}

	/**
	 * Every registered recipe for listing: name, owner, label, title, description, requires, pages, available and
	 * the reason when it is not.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		$list = array();
		foreach ( leitvia_studio()->registry( 'recipes' )->all() as $name => $entry ) {
			$recipe = self::load( (string) $entry['path'] );
			$error  = is_wp_error( $recipe ) ? $recipe->get_error_message() : self::unavailable( $name, $recipe );
			$list[] = array(
				'name'        => $name,
				'owner'       => (string) $entry['owner'],
				'label'       => (string) $entry['label'],
				'title'       => is_wp_error( $recipe ) ? '' : (string) $recipe['title'],
				'description' => is_wp_error( $recipe ) ? '' : (string) $recipe['description'],
				'requires'    => is_wp_error( $recipe ) ? array() : (array) ( $recipe['requires'] ?? array() ),
				'pages'       => is_wp_error( $recipe ) ? array() : array_column( (array) $recipe['pages'], 'slug' ),
				'available'   => '' === $error,
				'reason'      => $error,
			);
		}
		return $list;
	}

	/**
	 * A recipe file checked against the schema.
	 *
	 * @param string $path Path to recipe.json.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function load( string $path ) {
		$recipe = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local recipe file.
		if ( ! is_array( $recipe ) ) {
			return Block_Edit::invalid( 'recipe', __( 'The recipe file is missing or no valid JSON.', 'leitvia-studio' ) );
		}
		$valid = rest_validate_value_from_schema( $recipe, self::schema(), 'recipe' );
		return is_wp_error( $valid ) ? Block_Edit::invalid( 'recipe', $valid->get_error_message() ) : $recipe;
	}

	/**
	 * The JSON schema of a recipe.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		static $schema = null;
		$schema      ??= (array) json_decode( (string) file_get_contents( dirname( LEITVIA_STUDIO_FILE ) . '/schemas/recipe.schema.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin.
		return $schema;
	}

	/**
	 * Why a recipe cannot be applied here: the required plugins that are not active, or ''.
	 *
	 * @param array<string, mixed> $recipe Recipe.
	 */
	public static function missing( array $recipe ): string {
		$active  = array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		$slugs   = array_map( static fn( $file ): string => '.' === dirname( (string) $file ) ? basename( (string) $file, '.php' ) : dirname( (string) $file ), $active );
		$missing = array_diff( (array) ( $recipe['requires'] ?? array() ), $slugs );
		/* translators: %s: comma-separated plugin slugs. */
		return $missing ? sprintf( __( 'Requires the plugins: %s.', 'leitvia-studio' ), implode( ', ', $missing ) ) : '';
	}

	/**
	 * Why a registered recipe cannot be applied here: the plugins it requires and what an add-on adds, or ''.
	 *
	 * @param string               $name   Registry name of the recipe.
	 * @param array<string, mixed> $recipe Recipe.
	 */
	private static function unavailable( string $name, array $recipe ): string {
		/**
		 * Filters why a recipe cannot be applied on this site; '' lets it apply. list-recipes shows the reason, and
		 * apply-recipe refuses with it.
		 *
		 * @param string               $reason Reason, '' when the recipe can be applied.
		 * @param string               $name   Registry name of the recipe.
		 * @param array<string, mixed> $recipe Recipe.
		 */
		return (string) apply_filters( 'leitvia_studio_recipe_unavailable', self::missing( $recipe ), $name, $recipe );
	}

	/**
	 * Applies a recipe. Pages that exist (same page kind, else same slug and parent) or wait in the open package are
	 * skipped, as are the variation, template parts and navigation changed since a recipe wrote them and the variation
	 * while a token set is active, so a second run adds nothing twice and resets nothing.
	 *
	 * @param string $name    Registry name of the recipe.
	 * @param string $package Package key, '' for recipe-<name>.
	 * @return array<string, mixed>|\WP_Error Recipe name, package ID (0 when everything went live) and the steps with
	 *                                        their status: live, draft, package, unchanged, exists, waiting,
	 *                                        unavailable or rejected.
	 */
	public static function apply( string $name, string $package = '' ) {
		$entry = leitvia_studio()->registry( 'recipes' )->get( $name );
		if ( ! $entry ) {
			return Rejection::make( 'leitvia_unknown_recipe', __( 'There is no recipe with this name. List the recipes and use one of them.', 'leitvia-studio' ), 404, 'recipe', array( 'recipe' => $name ), 'leitvia/list-recipes' );
		}
		$recipe = self::load( (string) $entry['path'] );
		if ( is_wp_error( $recipe ) ) {
			return $recipe;
		}
		$missing = self::unavailable( $name, $recipe );
		if ( '' !== $missing ) {
			return Rejection::make( 'leitvia_recipe_unavailable', $missing, 422, 'requires', array( 'recipe' => $name ), 'leitvia/list-recipes', 'never' );
		}
		$key           = '' !== $package ? $package : substr( 'recipe-' . str_replace( '/', '-', $name ), 0, 64 );
		self::$collect = '' !== $package ? '' : $key;
		try {
			return self::run( $name, $recipe, $key, dirname( (string) $entry['path'] ) );
		} finally {
			self::$collect = '';
		}
	}

	/**
	 * Whether the key is the one a recipe run without a key from the caller collects in, see Pipeline.
	 *
	 * @param string $key Package key.
	 */
	public static function collects( string $key ): bool {
		return '' !== self::$collect && self::$collect === $key;
	}

	/**
	 * The steps of apply().
	 *
	 * @param string               $name   Registry name of the recipe.
	 * @param array<string, mixed> $recipe Recipe.
	 * @param string               $key    Package key.
	 * @param string               $dir    Recipe folder.
	 * @return array<string, mixed>
	 */
	private static function run( string $name, array $recipe, string $key, string $dir ): array {
		$steps = array();
		$call  = static function ( string $step, string $ability, array $input ) use ( &$steps, $key ): array {
			$steps[] = self::call( $step, $ability, $input + array( 'package' => $key ) );
			return $steps[ count( $steps ) - 1 ];
		};
		if ( isset( $recipe['contentModel'] ) ) {
			$call( 'contentModel', 'leitvia/define-content-type', array( 'declaration' => self::file( $dir, (string) $recipe['contentModel'] ) ) );
		}
		if ( isset( $recipe['tokenSet'] ) ) {
			$call(
				'tokenSet',
				'leitvia/create-token-set',
				array(
					'title'    => (string) $recipe['title'],
					'document' => self::file( $dir, (string) $recipe['tokenSet'] ),
				)
			);
		}
		if ( isset( $recipe['variation'] ) ) {
			// An active token set replaces the presets of a variation; setting the variation again would undo the brand.
			if ( (int) get_option( Compiler::ACTIVE_OPTION ) || self::changed( 'variation' ) ) {
				$steps[] = self::step( 'variation', 'leitvia/set-variation', 'exists', array( 'variation' => (string) $recipe['variation'] ) );
			} else {
				self::remember( 'variation', $call( 'variation', 'leitvia/set-variation', array( 'variation' => (string) $recipe['variation'] ) ) );
			}
		}
		foreach ( (array) ( $recipe['templateParts'] ?? array() ) as $part => $pattern ) {
			// A part changed after the recipe wrote it (by hand, by a kit or by an agent) is kept, like a page that exists.
			// _leitvia_recipe_hash: the record of earlier Studio versions.
			$saved = get_block_template( get_stylesheet() . '//' . $part, 'wp_template_part' );
			$wrote = $saved && $saved->wp_id ? (string) get_post_meta( (int) $saved->wp_id, '_leitvia_recipe_hash', true ) : '';
			if ( self::changed( 'part:' . $part ) || ( '' !== $wrote && md5( $saved->content ) !== $wrote ) ) {
				$steps[] = self::step( 'templateParts', 'leitvia/update-template-part', 'exists', array( 'part' => (string) $part ) );
				continue;
			}
			// Only a recipe that names footerNavigation rewrites the links to "#"; without it the pattern stays as it is, so
			// a kit can still put its own pages in place of those links.
			$part_content = 'footer' === $part && ! empty( $recipe['footerNavigation'] )
				? self::linked_part( (string) $pattern, self::links( (array) $recipe['footerNavigation'], $recipe, array() ) )
				: '';
			self::remember(
				'part:' . $part,
				$call(
					'templateParts',
					'leitvia/update-template-part',
					array( 'part' => (string) $part ) + ( '' !== $part_content ? array( 'content' => $part_content ) : array( 'pattern' => (string) $pattern ) )
				)
			);
		}
		$pages  = array();
		$queued = self::queued( $key );
		foreach ( (array) $recipe['pages'] as $page ) {
			$slug   = (string) $page['slug'];
			$parent = (string) ( $page['parent'] ?? '' );
			if ( '' !== $parent && empty( $pages[ $parent ] ) ) {
				$steps[] = self::step( 'pages', 'leitvia/compose-page', 'waiting', array( 'slug' => $slug ), 'parent_missing' );
				continue;
			}
			$pages[ $slug ] = self::find( $page, (int) ( $pages[ $parent ] ?? 0 ) );
			if ( $pages[ $slug ] || in_array( 'page:' . $slug, $queued, true ) ) {
				$steps[] = self::step(
					'pages',
					'leitvia/compose-page',
					$pages[ $slug ] ? 'exists' : 'package',
					array(
						'slug' => $slug,
						'post' => $pages[ $slug ],
					)
				);
				continue;
			}
			$input = array_intersect_key( $page, array_flip( array( 'title', 'frame', 'kind', 'slug', 'template' ) ) ) + array(
				'sections' => array_map( static fn( $section ): array => is_string( $section ) ? array( 'pattern' => $section ) : $section, (array) $page['sections'] ),
			);
			if ( '' !== $parent ) {
				$input['parent'] = $pages[ $parent ];
			}
			$result         = $call( 'pages', 'leitvia/compose-page', $input );
			$pages[ $slug ] = in_array( $result['status'], array( 'live', 'draft' ), true ) ? (int) $result['target']['post'] : 0;
		}
		if ( ! empty( $recipe['navigation'] ) && self::changed( 'navigation' ) ) {
			$steps[] = self::step( 'navigation', 'leitvia/update-navigation', 'exists', array() );
		} elseif ( ! empty( $recipe['navigation'] ) ) {
			self::remember( 'navigation', $call( 'navigation', 'leitvia/update-navigation', array( 'items' => self::links( (array) $recipe['navigation'], $recipe, $pages ) ) ) );
		}
		foreach ( (array) ( $recipe['reading'] ?? array() ) + (array) ( $recipe['connect'] ?? array() ) + (array) ( $recipe['options'] ?? array() ) as $option => $value ) {
			$steps[] = self::option( (string) $option, (string) $value, $pages, $key, in_array( 'option:' . $option, $queued, true ) );
		}
		/**
		 * Filters the steps of a recipe run before the report. An add-on adds the steps of its own recipes here, each
		 * written through an ability or Pipeline::run() with the package key, in the shape of the other steps (step,
		 * ability, status, target, code, package).
		 *
		 * @param list<array<string, mixed>> $steps  Steps so far.
		 * @param string                     $name   Registry name of the recipe.
		 * @param array<string, mixed>       $recipe Recipe.
		 * @param string                     $key    Package key of the run.
		 * @param array<string, int>         $pages  Page IDs by recipe slug, 0 for a page that is not on the site yet.
		 */
		$steps = (array) apply_filters( 'leitvia_studio_recipe_steps', $steps, $name, $recipe, $key, $pages );
		$ids   = array_filter( array_column( $steps, 'package' ) );
		return array(
			'recipe'  => $name,
			'package' => $ids ? (int) max( $ids ) : Packages::find( $key ),
			'steps'   => $steps,
		);
	}

	/**
	 * Links to pages by slug, label and url each: a recipe page by its title, at its address or, while it is not on the
	 * site yet, at the address its slug gives it; another slug links to the published page of the site with that path,
	 * such as the shop page of WooCommerce, and is left out without one.
	 *
	 * @param string[]             $slugs  Page slugs in order.
	 * @param array<string, mixed> $recipe Recipe.
	 * @param array<string, int>   $pages  Page IDs by recipe slug, 0 for a page that is not on the site yet.
	 * @return list<array{label: string, url: string}>
	 */
	private static function links( array $slugs, array $recipe, array $pages ): array {
		$items  = array();
		$titles = array_column( (array) $recipe['pages'], 'title', 'slug' );
		foreach ( $slugs as $slug ) {
			$slug = (string) $slug;
			if ( isset( $titles[ $slug ] ) ) {
				$items[] = array(
					'label' => (string) $titles[ $slug ],
					'url'   => ! empty( $pages[ $slug ] ) ? (string) get_permalink( $pages[ $slug ] ) : home_url( '/' . $slug . '/' ),
				);
				continue;
			}
			$post = get_page_by_path( $slug );
			if ( $post && 'publish' === $post->post_status ) {
				$items[] = array(
					'label' => get_the_title( $post ),
					'url'   => (string) get_permalink( $post ),
				);
			}
		}
		return $items;
	}

	/**
	 * Markup of a template part pattern without its sample links: every navigation link to "#" is dropped, the first
	 * navigation gets the links instead, and a navigation left without links is dropped, since core would fill it with
	 * every page. '' when the pattern has no link to "#", so the part is written from the pattern itself.
	 *
	 * @param string                                  $pattern Pattern name.
	 * @param list<array{label: string, url: string}> $links   Links for the first navigation.
	 */
	private static function linked_part( string $pattern, array $links ): string {
		$registered = \WP_Block_Patterns_Registry::get_instance()->get_registered( $pattern );
		$content    = $registered ? (string) $registered['content'] : '';
		if ( ! str_contains( $content, '"url":"#"' ) ) {
			return '';
		}
		$markup = '';
		foreach ( parse_blocks( $content ) as $block ) {
			$markup .= self::replace_links( $block, $links );
		}
		return $markup;
	}

	/**
	 * Markup of a parsed block with its navigation links to "#" replaced, see linked_part(); '' to drop it. Inner
	 * blocks are serialized into the inner content of their parent.
	 *
	 * @param array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<int, mixed>, innerHTML: string, innerContent: array<int, string|null>} $block Parsed block.
	 * @param list<array{label: string, url: string}>                                                                                                              $links Links for the first navigation; emptied once it got them.
	 */
	private static function replace_links( array $block, array &$links ): string {
		if ( 'core/navigation' === $block['blockName'] ) {
			$kept = array_filter( $block['innerBlocks'], static fn( $inner ): bool => is_array( $inner ) && '#' !== ( $inner['attrs']['url'] ?? '' ) );
			$kept = array_map( 'serialize_block', $kept );
			foreach ( $links as $link ) {
				$kept[] = '<!-- wp:navigation-link ' . serialize_block_attributes(
					array(
						'label' => $link['label'],
						'url'   => $link['url'],
						'kind'  => 'custom',
					)
				) . ' /-->';
			}
			$links = array();
			if ( ! $kept ) {
				return '';
			}
			$block['innerContent'] = array( "\n" . implode( "\n", $kept ) . "\n" );
		} else {
			$next = 0;
			foreach ( $block['innerContent'] as $index => $chunk ) {
				if ( null === $chunk ) {
					$block['innerContent'][ $index ] = self::replace_links( $block['innerBlocks'][ $next++ ], $links );
				}
			}
		}
		$block['innerBlocks'] = array();
		return serialize_block( $block );
	}

	/**
	 * Records what a step of the variation, a template part or the navigation wrote: the post and its hash when it is on
	 * the site (live, unchanged), the package and operation when it waits.
	 *
	 * @param string               $name Part: variation, navigation or part:<name>.
	 * @param array<string, mixed> $step Step of the run.
	 */
	private static function remember( string $name, array $step ): void {
		$post   = (int) ( $step['target']['post'] ?? 0 );
		$record = null;
		if ( in_array( $step['status'], array( 'live', 'unchanged' ), true ) && $post ) {
			$record = array(
				'post' => $post,
				'hash' => Hashes::document( $post ),
			);
		} elseif ( 'package' === $step['status'] && $step['package'] ) {
			$part = str_starts_with( $name, 'part:' ) ? substr( $name, 5 ) : null;
			foreach ( Packages::operations( (int) $step['package'] ) as $op ) {
				if ( $op['ability'] === $step['ability'] && ( null === $part || ( $op['state']['post_name'] ?? '' ) === $part ) ) {
					$record = array(
						'package' => (int) $step['package'],
						'op'      => (int) $op['id'],
					);
				}
			}
		}
		if ( $record ) {
			$writes          = (array) get_option( self::WRITES_OPTION, array() );
			$writes[ $name ] = $record;
			update_option( self::WRITES_OPTION, $writes, false );
		}
	}

	/**
	 * Whether a part changed after a recipe wrote it: its post now has another hash than the write left. A write that
	 * still waits in a package, or was never applied, counts as not written, so the run proposes it again.
	 *
	 * @param string $name Part: variation, navigation or part:<name>.
	 */
	private static function changed( string $name ): bool {
		$record = (array) ( ( (array) get_option( self::WRITES_OPTION, array() ) )[ $name ] ?? array() );
		if ( isset( $record['package'] ) ) {
			$entry  = array_column( Packages::operations( (int) $record['package'] ), null, 'id' )[ (int) $record['op'] ] ?? array();
			$record = (array) ( $entry['applied'] ?? array() );
		}
		$current = empty( $record['post'] ) ? '' : Hashes::document( (int) $record['post'] );
		return '' !== $current && (string) ( $record['hash'] ?? '' ) !== $current;
	}

	/**
	 * Runs one ability and turns its answer into a step.
	 *
	 * @param string               $step    Step name.
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $input   Input without base_hash.
	 * @return array<string, mixed>
	 */
	private static function call( string $step, string $ability, array $input ): array {
		$target = array_intersect_key( $input, array_flip( array( 'slug', 'part', 'variation' ) ) );
		// wp_get_ability() alone would raise a notice for a missing ability.
		$handler = wp_has_ability( $ability ) ? wp_get_ability( $ability ) : null;
		if ( ! $handler ) {
			return self::step( $step, $ability, 'unavailable', $target, 'ability_missing' );
		}
		$result = $handler->execute( $input + array( 'base_hash' => '' ) );
		// A recipe replaces template parts, navigation, styles and model entries as a whole. On a target that exists it
		// repeats once with the hash the rejection reports, as the retry rule "once" lets an agent do.
		if ( is_wp_error( $result ) && 'leitvia_stale_hash' === $result->get_error_code() ) {
			$result = $handler->execute( array( 'base_hash' => (string) ( $result->get_error_data()['current_hash'] ?? '' ) ) + $input );
		}
		return self::result( $step, $ability, $result, $target );
	}

	/**
	 * A step from a pipeline result or rejection.
	 *
	 * @param string               $step    Step name.
	 * @param string               $ability Ability name.
	 * @param mixed                $result  Result of the ability.
	 * @param array<string, mixed> $target  Target for the report.
	 * @return array<string, mixed>
	 */
	private static function result( string $step, string $ability, $result, array $target ): array {
		if ( is_wp_error( $result ) ) {
			return self::step( $step, $ability, 'rejected', $target, (string) $result->get_error_code() ) + array( 'message' => $result->get_error_message() );
		}
		$result = (array) $result;
		return array( 'package' => (int) ( $result['package'] ?? 0 ) ) + self::step( $step, $ability, (string) ( $result['mode'] ?? 'live' ), (array) ( $result['target'] ?? array() ) + $target );
	}

	/**
	 * One step of the report.
	 *
	 * @param string               $step    Step name.
	 * @param string               $ability Ability name.
	 * @param string               $status  Status.
	 * @param array<string, mixed> $target  Target.
	 * @param string               $code    Reason or error code.
	 * @return array<string, mixed>
	 */
	private static function step( string $step, string $ability, string $status, array $target, string $code = '' ): array {
		return array(
			'step'    => $step,
			'ability' => $ability,
			'status'  => $status,
			'target'  => $target,
			'code'    => $code,
			'package' => 0,
		);
	}

	/**
	 * Sets one reading setting, page connection or data-saving option through the pipeline; page slugs become page IDs.
	 *
	 * @param string             $option Option name.
	 * @param string             $value  Value, a page slug for the page options.
	 * @param array<string, int> $pages  Page IDs by recipe slug, 0 when not created yet.
	 * @param string             $key    Package key.
	 * @param bool               $queued Whether the open package already sets the option.
	 * @return array<string, mixed>
	 */
	private static function option( string $option, string $value, array $pages, string $key, bool $queued ): array {
		$target = array( 'option' => $option );
		$step   = in_array( $option, self::READING, true ) ? 'reading' : ( in_array( $option, self::CONNECT, true ) ? 'connect' : 'options' );
		if ( in_array( $option, array_merge( array( 'page_on_front', 'page_for_posts' ), self::CONNECT ), true ) ) {
			if ( ! empty( $pages[ $value ] ) ) {
				$value = (string) $pages[ $value ];
			} elseif ( in_array( 'page:' . $value, self::queued( $key ), true ) ) {
				// The page waits in the package: the stored value is its slug until applying the package sets its ID.
				$target['page'] = $value;
			} else {
				return self::step( $step, 'leitvia/apply-recipe', 'waiting', $target, 'page_missing' );
			}
		}
		if ( $queued || (string) get_option( $option ) === $value ) {
			return self::step( $step, 'leitvia/apply-recipe', $queued ? 'package' : 'exists', $target );
		}
		$op = new Operation( 'leitvia/apply-recipe', 'structure', $target, Hashes::of( (string) wp_json_encode( get_option( $option ) ) ), static fn(): array => array( 'value' => $value ), array(), $key );
		return self::result( $step, 'leitvia/apply-recipe', Pipeline::run( $op ), $target );
	}

	/**
	 * ID of an existing page of the recipe: the page of the same kind, else the one with the same slug and parent.
	 *
	 * @param array<string, mixed> $page      Recipe page.
	 * @param int                  $parent_id Parent page ID.
	 */
	private static function find( array $page, int $parent_id ): int {
		$statuses = array_values( array_diff( Scope::STATUSES, array( 'inherit' ) ) );
		if ( isset( $page['kind'] ) ) {
			$ids = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => $statuses,
					'meta_key'       => '_leitvia_page_kind', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One indexed lookup per recipe page.
					'meta_value'     => (string) $page['kind'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same.
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				)
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		$path = ( $parent_id ? get_page_uri( $parent_id ) . '/' : '' ) . $page['slug'];
		$post = get_page_by_path( $path );
		return $post && in_array( $post->post_status, $statuses, true ) ? $post->ID : 0;
	}

	/**
	 * What waits in the open package with this key: page:<slug> for pages, option:<name> for settings.
	 *
	 * @param string $key Package key.
	 * @return list<string>
	 */
	private static function queued( string $key ): array {
		$id = Packages::find( $key );
		return array_map(
			static fn( array $op ): string => isset( $op['target']['option'] ) ? 'option:' . $op['target']['option'] : 'page:' . ( $op['state']['post_name'] ?? '' ),
			$id ? Packages::operations( $id ) : array()
		);
	}

	/**
	 * A JSON file next to the recipe, never outside its folder.
	 *
	 * @param string $dir  Recipe folder.
	 * @param string $path Relative path.
	 * @return array<mixed>
	 */
	private static function file( string $dir, string $path ): array {
		$file = realpath( $dir . '/' . $path );
		$root = realpath( $dir );
		if ( ! $file || ! $root || ! str_starts_with( $file, trailingslashit( $root ) ) ) {
			return array();
		}
		return (array) json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local recipe file.
	}
}
