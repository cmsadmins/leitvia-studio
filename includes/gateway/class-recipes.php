<?php
/**
 * Site recipes: bundles of setup and pages, applied as one change package.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Token_Sets;

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
 * links to "#": the footer gets the pages of footerNavigation instead, a part with edits gets them as a page section
 * does), pages (leitvia/compose-page), navigation (leitvia/update-navigation with items of label and url), reading,
 * connect (settings that point to recipe pages) and options. A step whose ability is not registered is reported as unavailable.
 * The building blocks a recipe lists (buildingBlocks) are added after the content model, see Building_Blocks.
 *
 * A run takes a selection of parts (PARTS): buildingBlocks (content model and building blocks), samples (their sample
 * entries), design (token set and style variation), header, footer, pages (all or some slugs), navigation, frontPage
 * (reading settings) and options (connect and options). A dry run reports what each selected step would do and writes
 * nothing. A person who runs a recipe without a package key and asks to settle (input settle, see may_settle()) chose
 * its parts, so what collects in the package is applied at once, audited and with rollback, as for a building block;
 * every other run gets the autonomy and the rest waits in the package.
 *
 * Add-ons localise a recipe through the filters leitvia_studio_recipe_document (the recipe right after its file is
 * read, see document()) and leitvia_studio_recipe_model (its content model declaration before it is written, see
 * model()).
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
	 * Parts a run can be limited to, see selection().
	 */
	public const PARTS = array( 'buildingBlocks', 'samples', 'design', 'header', 'footer', 'pages', 'navigation', 'frontPage', 'options' );

	/**
	 * Part of the steps of Studio; a template part step belongs to the part it writes (header, footer).
	 */
	private const STEP_PARTS = array(
		'contentModel'  => 'buildingBlocks',
		'blockSettings' => 'buildingBlocks',
		'blockEntries'  => 'samples',
		'tokenSet'      => 'design',
		'variation'     => 'design',
		'pages'         => 'pages',
		'navigation'    => 'navigation',
		'reading'       => 'frontPage',
		'connect'       => 'options',
		'options'       => 'options',
	);

	/**
	 * Package key of the recipe run without a key from the caller: it collects what the autonomy puts into a package.
	 *
	 * @var string
	 */
	private static string $collect = '';

	/**
	 * Whether the run that collects settles: a person asked for it, see may_settle().
	 *
	 * @var bool
	 */
	private static bool $settle = false;

	/**
	 * Reports of Packages::apply() by package ID of the packages the run that collects settled so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $settled = array();

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
			$recipe = self::document( (string) $name, (string) $entry['path'] );
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
	 * Labels of add-on steps and codes for the screens that show recipe steps: steps and codes, each by name. Studio
	 * names its own steps in the screen.
	 *
	 * @return array{steps: array<string, string>, codes: array<string, string>}
	 */
	public static function labels(): array {
		/**
		 * Filters the labels of recipe steps and their codes, for example the steps an add-on adds through
		 * leitvia_studio_recipe_steps. Labels are translated, one or a few words for a step, one sentence for a code.
		 *
		 * @param array<string, mixed> $labels Labels: steps and codes, each an array of labels by step name or code.
		 */
		$labels = (array) apply_filters(
			'leitvia_studio_recipe_step_labels',
			array(
				'steps' => array(),
				'codes' => array(),
			)
		);
		$clean  = static fn( $labels_of ): array => array_map( 'strval', array_filter( (array) $labels_of, 'is_scalar' ) );
		return array(
			'steps' => $clean( $labels['steps'] ?? array() ),
			'codes' => $clean( $labels['codes'] ?? array() ),
		);
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
	 * A registered recipe as runs, dry runs, the plan and the list use it: the file checked against the schema, then
	 * passed through leitvia_studio_recipe_document.
	 *
	 * @param string $name Registry name of the recipe.
	 * @param string $path Path to recipe.json.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function document( string $name, string $path ) {
		$recipe = self::load( $path );
		if ( is_wp_error( $recipe ) ) {
			return $recipe;
		}
		/**
		 * Filters a recipe right after its file is read, for runs, dry runs, the plan of the Recipes screen and the list:
		 * an add-on localises page titles, section edits, navigation labels, title and description here. The recipe
		 * keeps the shape of schemas/recipe.schema.json; page slugs stay, since runs find pages by them.
		 *
		 * @param array<string, mixed> $recipe Recipe.
		 * @param string               $name   Registry name of the recipe.
		 */
		return (array) apply_filters( 'leitvia_studio_recipe_document', $recipe, $name );
	}

	/**
	 * The content model declaration of a recipe (its contentModel file) as it is written, planned and dry run: passed
	 * through leitvia_studio_recipe_model.
	 *
	 * @param string $dir  Recipe folder.
	 * @param string $path Path of the declaration, relative to the folder.
	 * @param string $name Registry name of the recipe.
	 * @return array<mixed>
	 */
	public static function model( string $dir, string $path, string $name ): array {
		$declaration = self::file( $dir, $path );
		/**
		 * Filters the content model declaration of a recipe before leitvia/define-content-type writes it, and in the
		 * plan and the dry run: an add-on localises the labels of post types, taxonomies, fields, relations and
		 * settings here. Keys stay as they are.
		 *
		 * @param array<mixed> $declaration Declaration.
		 * @param string       $name        Registry name of the recipe.
		 */
		return (array) apply_filters( 'leitvia_studio_recipe_model', $declaration, $name );
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
	 * @param string               $name      Registry name of the recipe.
	 * @param string               $package   Package key, '' for recipe-<name>.
	 * @param array<string, mixed> $selection Optional parts, pages, buildingBlocks and dryRun, see selection().
	 * @param bool                 $settle    Apply what collects at once where may_settle() allows it.
	 * @return array<string, mixed>|\WP_Error Recipe name, package ID (0 when everything went live) and the steps with
	 *                                        their status (live, draft, package, unchanged, exists, waiting,
	 *                                        unavailable or rejected) and part. A dry run reports new, replace,
	 *                                        exists, package or waiting, with dryRun and the parts it replaces. A
	 *                                        settled run adds applied and partial, see outcome().
	 */
	public static function apply( string $name, string $package = '', array $selection = array(), bool $settle = false ) {
		$entry = leitvia_studio()->registry( 'recipes' )->get( $name );
		if ( ! $entry ) {
			return Rejection::make( 'leitvia_unknown_recipe', __( 'There is no recipe with this name. List the recipes and use one of them.', 'leitvia-studio' ), 404, 'recipe', array( 'recipe' => $name ), 'leitvia/list-recipes' );
		}
		$recipe = self::document( $name, (string) $entry['path'] );
		if ( is_wp_error( $recipe ) ) {
			return $recipe;
		}
		$missing = self::unavailable( $name, $recipe );
		if ( '' !== $missing ) {
			return Rejection::make( 'leitvia_recipe_unavailable', $missing, 422, 'requires', array( 'recipe' => $name ), 'leitvia/list-recipes', 'never' );
		}
		$key = '' !== $package ? $package : substr( 'recipe-' . str_replace( '/', '-', $name ), 0, 64 );
		$sel = self::selection( $selection, $recipe );
		$run = static fn() => self::run( $name, $recipe, $key, dirname( (string) $entry['path'] ), $sel );
		return '' !== $package || $sel['dryRun'] ? $run() : self::collecting( $key, $run, $settle );
	}

	/**
	 * Runs writes that collect in the package of the key: the autonomy decides what goes live, the rest waits in that
	 * one package, see Pipeline. A key from the caller instead puts everything into its package. With settle, and when
	 * may_settle() allows it, Building_Blocks::settle() applies the package during the run.
	 *
	 * @param string   $key    Package key.
	 * @param callable $run    The writes.
	 * @param bool     $settle Whether the caller asked to apply what collects at once.
	 * @return mixed What the writes return.
	 */
	public static function collecting( string $key, callable $run, bool $settle = false ) {
		$previous      = array( self::$collect, self::$settle, self::$settled );
		self::$collect = $key;
		self::$settle  = $settle && self::may_settle();
		self::$settled = array();
		try {
			return $run();
		} finally {
			[ self::$collect, self::$settle, self::$settled ] = $previous;
		}
	}

	/**
	 * Whether a run may apply its package at once: a person (Gateway::is_person()) in the admin, neither signed in with
	 * an application password nor calling through an MCP server. Agents, scripts and MCP clients of an administrator
	 * get the autonomy, and what may not go live waits for a person in the Inbox.
	 */
	public static function may_settle(): bool {
		return Gateway::is_person() && ! rest_get_authenticated_app_password() && ! Mcp::serving();
	}

	/**
	 * Whether the run that collects in the key settles, see collecting(). Add-on steps of leitvia_studio_recipe_steps
	 * ask this before they apply the package or rely on what the run applied.
	 *
	 * @param string $key Package key.
	 */
	public static function settles( string $key ): bool {
		return self::$settle && self::collects( $key );
	}

	/**
	 * Notes the report of Packages::apply() for a package the run settled, see Building_Blocks::settle().
	 *
	 * @param int                  $id     Package ID.
	 * @param array<string, mixed> $report Report.
	 */
	public static function settled( int $id, array $report ): void {
		self::$settled[ $id ] = $report;
	}

	/**
	 * The package of the run that settles in the key and was applied only in part, 0 for none: later writes of the run
	 * join it instead of a second package with the same key, see Packages::find().
	 *
	 * @param string $key Package key.
	 */
	public static function partial( string $key ): int {
		if ( ! self::settles( $key ) ) {
			return 0;
		}
		foreach ( array_keys( self::$settled ) as $id ) {
			if ( 'partial' === Packages::state( $id ) ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * What the run settled so far: applied (IDs of the packages applied in full) and partial (package and errors of
	 * each package that was applied in part or not at all; it is not in applied), each only when not empty.
	 *
	 * @return array{applied?: list<int>, partial?: list<array{package: int, errors: list<array<string, mixed>>}>}
	 */
	public static function outcome(): array {
		$out = array();
		foreach ( self::$settled as $id => $report ) {
			if ( 'applied' === $report['state'] ) {
				$out['applied'][] = $id;
				continue;
			}
			$conflicts        = array_map(
				static fn( $conflict ): array => array(
					'op'      => (int) ( $conflict['op'] ?? 0 ),
					'code'    => 'leitvia_stale_hash',
					'message' => __( 'The target changed since it was read. Read it again and repeat the change on the current state.', 'leitvia-studio' ),
				),
				(array) $report['conflicts']
			);
			$out['partial'][] = array(
				'package' => $id,
				'errors'  => array_values( array_merge( (array) $report['errors'], $conflicts ) ),
			);
		}
		return $out;
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
	 * The steps of apply(): the parts of the selection, each written through its ability, or in a dry run reported with
	 * what it would do: new, replace (something on the site is overwritten), exists (kept), package or waiting.
	 *
	 * @param string               $name   Registry name of the recipe.
	 * @param array<string, mixed> $recipe Recipe.
	 * @param string               $key    Package key.
	 * @param string               $dir    Recipe folder.
	 * @param array<string, mixed> $sel    Selection, see selection().
	 * @return array<string, mixed>
	 */
	private static function run( string $name, array $recipe, string $key, string $dir, array $sel ): array {
		$dry   = (bool) $sel['dryRun'];
		$has   = static fn( string $part ): bool => in_array( $part, (array) $sel['parts'], true );
		$steps = array();
		// A person who asked to settle chose the parts and decides on packages anyway: what collects is applied at once,
		// after the content model (so the entries find their types) and at the end, as Building_Blocks::add() does.
		// settle() does nothing in every other run.
		$settle = static function () use ( &$steps, $key ): void {
			$steps = Building_Blocks::settle( $steps, $key );
		};
		$call   = static function ( string $step, string $ability, array $input, string $plan = 'new' ) use ( &$steps, $key, $dry ): array {
			$steps[] = $dry ? self::step( $step, $ability, $plan, self::target( $input ) ) : self::call( $step, $ability, $input + array( 'package' => $key ) );
			return $steps[ count( $steps ) - 1 ];
		};
		if ( isset( $recipe['contentModel'] ) && $has( 'buildingBlocks' ) && $sel['contentModel'] ) {
			$model = self::model( $dir, (string) $recipe['contentModel'], $name );
			$call( 'contentModel', 'leitvia/define-content-type', array( 'declaration' => $model ), Building_Blocks::installed( array( 'model' => $model ) ) ? 'exists' : 'new' );
			$settle();
		}
		foreach ( $has( 'buildingBlocks' ) ? (array) $sel['buildingBlocks'] : array() as $block_name ) {
			$block = leitvia_studio()->registry( 'building_blocks' )->get( (string) $block_name );
			if ( ! $block ) {
				$steps[] = self::step( 'contentModel', Building_Blocks::ABILITY, 'unavailable', array( 'building_block' => (string) $block_name ), 'building_block_missing' );
				continue;
			}
			$steps[] = Building_Blocks::model_step( (string) $block_name, $block, $key, $dry );
			$settle();
			$steps = array_merge( $steps, Building_Blocks::content_steps( $block, $has( 'samples' ), $key, $dry ) );
		}
		if ( isset( $recipe['tokenSet'] ) && $has( 'design' ) ) {
			$call(
				'tokenSet',
				'leitvia/create-token-set',
				array(
					'title'    => (string) $recipe['title'],
					'document' => self::file( $dir, (string) $recipe['tokenSet'] ),
				),
				self::token_set_exists( (string) $recipe['title'] ) ? 'exists' : 'new'
			);
		}
		if ( isset( $recipe['variation'] ) && $has( 'design' ) ) {
			// An active token set replaces the presets of a variation; setting the variation again would undo the brand.
			if ( (int) get_option( Compiler::ACTIVE_OPTION ) || self::changed( 'variation' ) ) {
				$steps[] = self::step( 'variation', 'leitvia/set-variation', 'exists', array( 'variation' => (string) $recipe['variation'] ) );
			} else {
				self::remember( 'variation', $call( 'variation', 'leitvia/set-variation', array( 'variation' => (string) $recipe['variation'] ), self::planned( 'variation' ) ) );
			}
		}
		// Recipe pages this run creates or that wait in its package. Links of the navigation and the footer to other recipe
		// pages that are not on the site are left out, so a run with some pages links no page it does not create.
		$coming = array_merge(
			$has( 'pages' ) ? array_map( 'strval', (array) $sel['pages'] ) : array(),
			array_map( static fn( string $item ): string => substr( $item, 5 ), (array) preg_grep( '/^page:/', self::queued( $key ) ) )
		);
		foreach ( (array) ( $recipe['templateParts'] ?? array() ) as $part => $pattern ) {
			if ( ! $has( (string) $part ) ) {
				continue;
			}
			// A part names its pattern, or the pattern with edits at block IDs as a page section does: the button and the
			// service bar of a header pattern link to "#" until the recipe gives them targets.
			$edits   = is_array( $pattern ) ? (array) ( $pattern['edits'] ?? array() ) : array();
			$pattern = is_array( $pattern ) ? (string) ( $pattern['pattern'] ?? '' ) : (string) $pattern;
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
				? self::linked_part( $pattern, self::links( (array) $recipe['footerNavigation'], $recipe, array(), $coming ) )
				: '';
			if ( $edits ) {
				$registered   = \WP_Block_Patterns_Registry::get_instance()->get_registered( $pattern );
				$part_content = Abilities\Build::edit( '' !== $part_content ? $part_content : (string) ( $registered['content'] ?? '' ), $edits, array( 'part' => (string) $part ) );
				if ( is_wp_error( $part_content ) ) {
					$steps[] = self::result( 'templateParts', 'leitvia/update-template-part', $part_content, array( 'part' => (string) $part ) );
					continue;
				}
			}
			self::remember(
				'part:' . $part,
				$call(
					'templateParts',
					'leitvia/update-template-part',
					array( 'part' => (string) $part ) + ( '' !== $part_content ? array( 'content' => $part_content ) : array( 'pattern' => $pattern ) ),
					$saved ? self::planned( 'part:' . $part ) : 'new'
				)
			);
		}
		$pages   = array();
		$planned = array();
		$queued  = self::queued( $key );
		foreach ( (array) $recipe['pages'] as $page ) {
			$slug   = (string) $page['slug'];
			$parent = (string) ( $page['parent'] ?? '' );
			$wanted = $has( 'pages' ) && in_array( $slug, (array) $sel['pages'], true );
			// A page left out of the selection is looked up all the same, for its child pages, links and settings.
			if ( '' !== $parent && empty( $pages[ $parent ] ) && ! isset( $planned[ $parent ] ) ) {
				if ( $wanted ) {
					$steps[] = self::step( 'pages', 'leitvia/compose-page', 'waiting', array( 'slug' => $slug ), 'parent_missing' );
				}
				continue;
			}
			$pages[ $slug ] = isset( $planned[ $parent ] ) ? 0 : self::find( $page, (int) ( $pages[ $parent ] ?? 0 ) );
			if ( ! $wanted ) {
				continue;
			}
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
			if ( $dry ) {
				$steps[]          = self::step( 'pages', 'leitvia/compose-page', 'new', array( 'slug' => $slug ) );
				$planned[ $slug ] = true;
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
		if ( ! empty( $recipe['navigation'] ) && $has( 'navigation' ) ) {
			if ( self::changed( 'navigation' ) ) {
				$steps[] = self::step( 'navigation', 'leitvia/update-navigation', 'exists', array() );
			} else {
				$items = self::links( (array) $recipe['navigation'], $recipe, $pages, $coming );
				// Without any of its pages the navigation waits for them; a menu needs at least one link.
				if ( $items ) {
					self::remember( 'navigation', $call( 'navigation', 'leitvia/update-navigation', array( 'items' => $items ), self::planned( 'navigation' ) ) );
				} else {
					$steps[] = self::step( 'navigation', 'leitvia/update-navigation', 'waiting', array(), 'page_missing' );
				}
			}
		}
		// A static front page waits together with its page: show_on_front alone would show an empty front page.
		$front = (string) ( $recipe['reading']['page_on_front'] ?? '' );
		$alone = '' !== $front && empty( $pages[ $front ] ) && ! isset( $planned[ $front ] ) && ! in_array( 'page:' . $front, self::queued( $key ), true );
		foreach ( (array) ( $recipe['reading'] ?? array() ) + (array) ( $recipe['connect'] ?? array() ) + (array) ( $recipe['options'] ?? array() ) as $option => $value ) {
			if ( ! $has( in_array( $option, self::READING, true ) ? 'frontPage' : 'options' ) ) {
				continue;
			}
			$steps[] = 'show_on_front' === $option && 'page' === (string) $value && $alone
				? self::step( 'reading', 'leitvia/apply-recipe', 'waiting', array( 'option' => 'show_on_front' ), 'page_missing' )
				: self::option( (string) $option, (string) $value, $pages, $key, in_array( 'option:' . $option, $queued, true ), $dry ? $planned : null );
		}
		if ( $dry ) {
			/**
			 * Filters the steps of a dry run of a recipe (leitvia/apply-recipe with dryRun) before the report. An add-on
			 * adds what its own steps would do, in the shape of the other steps with the status new, replace, exists,
			 * package or waiting, and writes nothing. Add-ons that do not use this filter add nothing to a dry run.
			 *
			 * @param list<array<string, mixed>> $steps  Steps so far.
			 * @param string                     $name   Registry name of the recipe.
			 * @param array<string, mixed>       $recipe Recipe.
			 * @param string                     $key    Package key of the run.
			 * @param array<string, int>         $pages  Page IDs by recipe slug, 0 for a page that is not on the site yet.
			 * @param array<string, mixed>       $sel    Selection: parts, pages, buildingBlocks, contentModel and dryRun.
			 */
			$steps = (array) apply_filters( 'leitvia_studio_recipe_plan_steps', $steps, $name, $recipe, $key, $pages, $sel );
		} else {
			// A person's run applies what collected so far, so add-on steps find pages, samples and the model live.
			$settle();
			foreach ( self::$settled ? (array) $recipe['pages'] : array() as $page ) {
				$slug = (string) $page['slug'];
				if ( isset( $pages[ $slug ] ) && ! $pages[ $slug ] ) {
					$pages[ $slug ] = self::find( $page, (int) ( $pages[ (string) ( $page['parent'] ?? '' ) ] ?? 0 ) );
				}
			}
			/**
			 * Filters the steps of a recipe run before the report. An add-on adds the steps of its own recipes here, each
			 * written through an ability or Pipeline::run() with the package key, in the shape of the other steps (step,
			 * ability, status, target, code, package), and skips those whose part is not in the selection. In a person's
			 * run what collected before is applied already, and what the add-on collects is applied after the filter.
			 *
			 * @param list<array<string, mixed>> $steps  Steps so far.
			 * @param string                     $name   Registry name of the recipe.
			 * @param array<string, mixed>       $recipe Recipe.
			 * @param string                     $key    Package key of the run.
			 * @param array<string, int>         $pages  Page IDs by recipe slug, 0 for a page that is not on the site yet.
			 * @param array<string, mixed>       $sel    Selection: parts, pages, buildingBlocks, contentModel and dryRun.
			 */
			$steps = (array) apply_filters( 'leitvia_studio_recipe_steps', $steps, $name, $recipe, $key, $pages, $sel );
			$settle();
		}
		$steps = array_map( static fn( $step ): array => (array) $step + array( 'part' => self::part_of( (array) $step ) ), $steps );
		$ids   = array_filter( array_column( $steps, 'package' ) );
		// A settled run lists the packages it applied at once (each rolls back in the Inbox) and those applied in part.
		$out = array(
			'recipe'  => $name,
			'package' => $dry ? 0 : ( $ids && ! self::settles( $key ) ? (int) max( $ids ) : Packages::find( $key ) ),
			'steps'   => $steps,
		) + self::outcome();
		if ( $dry ) {
			$out['dryRun']   = true;
			$out['replaces'] = array_values( array_unique( array_filter( array_column( wp_list_filter( $steps, array( 'status' => 'replace' ) ), 'part' ) ) ) );
		}
		return $out;
	}

	/**
	 * The selection of a run from the input of apply-recipe: parts (PARTS, all by default), pages (slugs of the recipe,
	 * all by default), buildingBlocks (names the recipe lists, all by default), dryRun, and contentModel: whether the
	 * content model file of the recipe is written, only while no subset of building blocks was asked for.
	 *
	 * @param array<string, mixed> $input  Input with parts, pages, buildingBlocks and dryRun, each optional.
	 * @param array<string, mixed> $recipe Recipe.
	 * @return array{parts: list<string>, pages: list<string>, buildingBlocks: list<string>, contentModel: bool, dryRun: bool}
	 */
	public static function selection( array $input, array $recipe ): array {
		$pages  = array_map( 'strval', array_column( (array) $recipe['pages'], 'slug' ) );
		$blocks = array_values( array_map( 'strval', (array) ( $recipe['buildingBlocks'] ?? array() ) ) );
		return array(
			'parts'          => isset( $input['parts'] ) ? array_values( array_intersect( self::PARTS, (array) $input['parts'] ) ) : self::PARTS,
			'pages'          => isset( $input['pages'] ) ? array_values( array_intersect( $pages, (array) $input['pages'] ) ) : $pages,
			'buildingBlocks' => isset( $input['buildingBlocks'] ) ? array_values( array_intersect( $blocks, (array) $input['buildingBlocks'] ) ) : $blocks,
			'contentModel'   => ! isset( $input['buildingBlocks'] ) || ! $blocks,
			'dryRun'         => ! empty( $input['dryRun'] ),
		);
	}

	/**
	 * Part of a step of the report, '' for a step of an add-on that names none.
	 *
	 * @param array<string, mixed> $step Step.
	 */
	private static function part_of( array $step ): string {
		$step_name = (string) ( $step['step'] ?? '' );
		if ( 'templateParts' === $step_name ) {
			return (string) ( $step['target']['part'] ?? '' );
		}
		return self::STEP_PARTS[ $step_name ] ?? '';
	}

	/**
	 * Whether a token set with this title exists, the one a run of the recipe created.
	 *
	 * @param string $title Title of the recipe.
	 */
	private static function token_set_exists( string $title ): bool {
		return (bool) get_posts(
			array(
				'post_type'   => Token_Sets::POST_TYPE,
				'post_status' => 'any',
				'title'       => $title,
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
	}

	/**
	 * Links to pages by slug, label and url each: a recipe page by its title, at its address or, while it is not on the
	 * site yet but comes in this run, at the address its slug gives it; a recipe page that is neither on the site nor
	 * coming is left out. Another slug links to the published page of the site with that path, such as the shop page of
	 * WooCommerce, and is left out without one.
	 *
	 * @param string[]             $slugs  Page slugs in order.
	 * @param array<string, mixed> $recipe Recipe.
	 * @param array<string, int>   $pages  Page IDs by recipe slug, 0 for a page that is not on the site yet; a slug
	 *                                     missing here is looked up.
	 * @param string[]             $coming Slugs of recipe pages this run creates or that wait in its package.
	 * @return list<array{label: string, url: string}>
	 */
	private static function links( array $slugs, array $recipe, array $pages, array $coming ): array {
		$items  = array();
		$titles = array_column( (array) $recipe['pages'], 'title', 'slug' );
		$byslug = array_column( (array) $recipe['pages'], null, 'slug' );
		foreach ( $slugs as $slug ) {
			$slug = (string) $slug;
			if ( isset( $titles[ $slug ] ) ) {
				$id = $pages[ $slug ] ?? self::find( (array) $byslug[ $slug ], 0 );
				if ( ! $id && ! in_array( $slug, $coming, true ) ) {
					continue;
				}
				// The ID only decides whether the page is linked; the address stays as before, so a second run writes
				// the same part and reports it unchanged.
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
		[ $current, $hash ] = self::hashes( $name );
		return '' !== $current && $hash !== $current;
	}

	/**
	 * Plan of a dry run for a part a recipe writes whole: unchanged while it holds what a recipe wrote, else replace.
	 *
	 * @param string $name Part: variation, navigation or part:<name>.
	 */
	private static function planned( string $name ): string {
		[ $current, $hash ] = self::hashes( $name );
		return '' !== $current && $hash === $current ? 'unchanged' : 'replace';
	}

	/**
	 * Hash of the post a recipe wrote for a part now and right after the write, '' each when there is none.
	 *
	 * @param string $name Part: variation, navigation or part:<name>.
	 * @return array{0: string, 1: string}
	 */
	private static function hashes( string $name ): array {
		$record = (array) ( ( (array) get_option( self::WRITES_OPTION, array() ) )[ $name ] ?? array() );
		if ( isset( $record['package'] ) ) {
			$entry  = array_column( Packages::operations( (int) $record['package'] ), null, 'id' )[ (int) $record['op'] ] ?? array();
			$record = (array) ( $entry['applied'] ?? array() );
		}
		return array( empty( $record['post'] ) ? '' : Hashes::document( (int) $record['post'] ), (string) ( $record['hash'] ?? '' ) );
	}

	/**
	 * Runs one ability and turns its answer into a step.
	 *
	 * @param string               $step    Step name.
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $input   Input without base_hash.
	 * @return array<string, mixed>
	 */
	public static function call( string $step, string $ability, array $input ): array {
		$target = self::target( $input );
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
	 * Target of a step for the report: slug, part and variation of the input.
	 *
	 * @param array<string, mixed> $input Input of the ability.
	 * @return array<string, mixed>
	 */
	private static function target( array $input ): array {
		return array_intersect_key( $input, array_flip( array( 'slug', 'part', 'variation' ) ) );
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
	public static function result( string $step, string $ability, $result, array $target ): array {
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
	public static function step( string $step, string $ability, string $status, array $target, string $code = '' ): array {
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
	 * @param string                   $option  Option name.
	 * @param string                   $value   Value, a page slug for the page options.
	 * @param array<string, int>       $pages   Page IDs by recipe slug, 0 when not created yet.
	 * @param string                   $key     Package key.
	 * @param bool                     $queued  Whether the open package already sets the option.
	 * @param array<string, bool>|null $planned Slugs of the pages a dry run would create, null for a run that writes.
	 * @return array<string, mixed>
	 */
	private static function option( string $option, string $value, array $pages, string $key, bool $queued, ?array $planned = null ): array {
		$target = array( 'option' => $option );
		$step   = in_array( $option, self::READING, true ) ? 'reading' : ( in_array( $option, self::CONNECT, true ) ? 'connect' : 'options' );
		if ( in_array( $option, array_merge( array( 'page_on_front', 'page_for_posts' ), self::CONNECT ), true ) ) {
			if ( ! empty( $pages[ $value ] ) ) {
				$value = (string) $pages[ $value ];
			} elseif ( isset( $planned[ $value ] ) ) {
				return self::step( $step, 'leitvia/apply-recipe', 'replace', $target + array( 'page' => $value ) );
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
		if ( null !== $planned ) {
			return self::step( $step, 'leitvia/apply-recipe', 'replace', $target );
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
	public static function file( string $dir, string $path ): array {
		$file = realpath( $dir . '/' . $path );
		$root = realpath( $dir );
		if ( ! $file || ! $root || ! str_starts_with( $file, trailingslashit( $root ) ) ) {
			return array();
		}
		return (array) json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local recipe file.
	}
}
