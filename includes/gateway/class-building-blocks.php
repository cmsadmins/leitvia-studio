<?php
/**
 * Building blocks: a part of the content model with its patterns, settings and sample entries.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

defined( 'ABSPATH' ) || exit;

/**
 * Building blocks live in the Studio registry building_blocks (see schemas/building-block.schema.json): title,
 * description, icon, model (content model entries without version), patterns, settings (default values by key) and
 * samples (a callable or a path that gives sample entries in the shape { postType, terms, entries }, one or a list).
 * Studio registers none itself; add-ons and developers do. A block is installed when every key of its model is in the
 * current content model.
 *
 * Adding a block (add(), ability leitvia/add-building-block) writes through the pipeline only, in this order: the model
 * through leitvia/define-content-type (merged at field level, entries from code are left out), the default settings
 * that are empty, the terms of the samples and the sample entries as drafts. Sample terms are added only in a run that
 * settles and by a user who may manage them; elsewhere an entry whose terms are missing waits (terms_pending), so
 * nothing reaches the site past the package. Without a key from the caller the writes
 * collect in the package building-block-<owner>-<name> as a recipe run does. A person who asks to settle (input settle,
 * see Recipes::may_settle()) decides on packages anyway, so that package is applied for them right away, after the
 * model, before an entry whose relation points to an entry in the package, and at the end: a person adds a block in
 * one go, every other run gets what the autonomy allows and the rest waits in the Inbox. A second run adds only what
 * is missing.
 */
final class Building_Blocks {

	/**
	 * Name of the ability, also in the audit log of the writes without an ability of their own.
	 */
	public const ABILITY = 'leitvia/add-building-block';

	/**
	 * The JSON schema of a building block.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		static $schema = null;
		$schema      ??= (array) json_decode( (string) file_get_contents( dirname( LEITVIA_STUDIO_FILE ) . '/schemas/building-block.schema.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin.
		return $schema;
	}

	/**
	 * Whether the current user may set up building blocks: edit_theme_options. One check for the ability
	 * add-building-block, the REST list and canSetup of the editor.
	 */
	public static function can_set_up(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Input schema of settle in add-building-block and apply-recipe.
	 *
	 * @return array<string, mixed>
	 */
	public static function settle_schema(): array {
		return array(
			'type'        => 'boolean',
			'default'     => false,
			'description' => __( 'Apply what collects at once instead of leaving it in the Inbox. Only for a person in the admin; agents, application passwords and MCP clients get the autonomy.', 'leitvia-studio' ),
		);
	}

	/**
	 * Output schema of partial in add-building-block and apply-recipe: the packages a run applied in part.
	 *
	 * @return array<string, mixed>
	 */
	public static function partial_schema(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'package' => array( 'type' => 'integer' ),
					'errors'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
		);
	}

	/**
	 * Why a registry entry is no valid building block, or ''. Runs when the entry is registered: the fields against the
	 * schema, the model against the content model schema. References between entries are checked when it is added.
	 *
	 * @param array<string, mixed> $item Entry.
	 */
	public static function invalid( array $item ): string {
		$fields = $item;
		// A callable is no JSON; a string is a path.
		if ( isset( $fields['samples'] ) && ! is_string( $fields['samples'] ) ) {
			if ( ! is_callable( $fields['samples'] ) ) {
				return 'samples must be a callable or a path';
			}
			unset( $fields['samples'] );
		}
		$valid = rest_validate_value_from_schema( $fields, self::schema(), 'building block' );
		if ( is_wp_error( $valid ) ) {
			return $valid->get_error_message();
		}
		$model = rest_validate_value_from_schema( array( 'version' => 1 ) + (array) $item['model'], Model::schema(), 'model' );
		return is_wp_error( $model ) ? $model->get_error_message() : '';
	}

	/**
	 * Why the needs of a pattern (field needs of the registry patterns) are invalid, or ''. Shape: buildingBlock (a
	 * registry name), postTypes, taxonomies and settings (keys of site settings of the content model), each optional.
	 *
	 * @param mixed $needs Value of the field.
	 */
	public static function invalid_needs( $needs ): string {
		$names = array(
			'type'  => 'array',
			'items' => array(
				'type'      => 'string',
				'minLength' => 1,
			),
		);
		$valid = rest_validate_value_from_schema(
			$needs,
			array(
				'type'                 => 'object',
				'properties'           => array(
					'buildingBlock' => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$',
					),
					'postTypes'     => $names,
					'taxonomies'    => $names,
					'settings'      => array(
						'type'  => 'array',
						'items' => array(
							'type'    => 'string',
							'pattern' => '^[a-z][a-z0-9_]{2,63}$',
						),
					),
				),
				'additionalProperties' => false,
			),
			'needs'
		);
		return is_wp_error( $valid ) ? $valid->get_error_message() : '';
	}

	/**
	 * What a pattern needs to show its content: the field needs of its entry in the registry patterns and, unless they
	 * name one, the building block that lists the pattern in its patterns or declares a post type the needs name.
	 * Empty for a pattern without needs.
	 *
	 * @param string $name Pattern name.
	 * @return array<string, mixed> buildingBlock, postTypes, taxonomies and settings, each optional.
	 */
	public static function needs_of_pattern( string $name ): array {
		$entry = leitvia_studio()->registry( 'patterns' )->get( $name );
		$needs = is_array( $entry['needs'] ?? null ) ? $entry['needs'] : array();
		if ( empty( $needs['buildingBlock'] ) ) {
			foreach ( leitvia_studio()->registry( 'building_blocks' )->all() as $block_name => $block ) {
				if ( in_array( $name, (array) ( $block['patterns'] ?? array() ), true ) || array_intersect( (array) ( $needs['postTypes'] ?? array() ), array_keys( (array) ( $block['model']['postTypes'] ?? array() ) ) ) ) {
					$needs['buildingBlock'] = (string) $block_name;
					break;
				}
			}
		}
		return $needs;
	}

	/**
	 * Name of the registered block whose model declares a post type, '' for none.
	 *
	 * @param string $type Post type.
	 */
	public static function of_post_type( string $type ): string {
		foreach ( leitvia_studio()->registry( 'building_blocks' )->all() as $name => $block ) {
			if ( isset( $block['model']['postTypes'][ $type ] ) ) {
				return (string) $name;
			}
		}
		return '';
	}

	/**
	 * What of the needs is missing on the site: the building block when it is registered and not installed, post types
	 * and taxonomies that are not registered, site settings that are not in the content model or are empty. Empty when
	 * nothing is missing. A block that is not registered (its add-on is gone or its kit locked) counts as met: nothing
	 * could set it up, and what it put on the site keeps rendering.
	 *
	 * @param array<string, mixed> $needs Needs, see needs_of_pattern().
	 * @return array<string, mixed> buildingBlock, postTypes, taxonomies and settings, each only when missing.
	 */
	public static function missing( array $needs ): array {
		$missing = array();
		$name    = (string) ( $needs['buildingBlock'] ?? '' );
		if ( '' !== $name ) {
			$block = leitvia_studio()->registry( 'building_blocks' )->get( $name );
			if ( $block && ! self::installed( $block ) ) {
				$missing['buildingBlock'] = $name;
			}
		}
		$absent = array(
			'postTypes'  => static fn( string $type ): bool => ! post_type_exists( $type ),
			'taxonomies' => static fn( string $taxonomy ): bool => ! taxonomy_exists( $taxonomy ),
			'settings'   => static fn( string $key ): bool => ! Pipeline::model_setting( Registrar::SETTING_PREFIX . $key ) || in_array( get_option( Registrar::SETTING_PREFIX . $key ), array( false, '', null ), true ),
		);
		foreach ( $absent as $field => $test ) {
			$gone = array_values( array_filter( array_map( 'strval', (array) ( $needs[ $field ] ?? array() ) ), $test ) );
			if ( $gone ) {
				$missing[ $field ] = $gone;
			}
		}
		return $missing;
	}

	/**
	 * Every registered block for listing: name, owner, title, description, icon, installed, the post types and
	 * taxonomies of its model, patterns and the number of sample entries.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		$list = array();
		foreach ( leitvia_studio()->registry( 'building_blocks' )->all() as $name => $block ) {
			$list[] = array(
				'name'        => $name,
				'owner'       => (string) $block['owner'],
				'title'       => (string) $block['title'],
				'description' => (string) $block['description'],
				'icon'        => (string) ( $block['icon'] ?? '' ),
				'installed'   => self::installed( $block ),
				'postTypes'   => array_keys( (array) ( $block['model']['postTypes'] ?? array() ) ),
				'taxonomies'  => array_keys( (array) ( $block['model']['taxonomies'] ?? array() ) ),
				'patterns'    => array_values( (array) ( $block['patterns'] ?? array() ) ),
				'sampleCount' => array_sum( array_map( static fn( array $doc ): int => count( (array) ( $doc['entries'] ?? array() ) ), self::samples( $block ) ) ),
			);
		}
		return $list;
	}

	/**
	 * Whether every key of the model of a block is in the current content model.
	 *
	 * @param array<string, mixed> $block Registry entry.
	 */
	public static function installed( array $block ): bool {
		$current = Model::current();
		foreach ( Model::SECTIONS as $section ) {
			if ( array_diff_key( (array) ( $block['model'][ $section ] ?? array() ), (array) ( $current[ $section ] ?? array() ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The sample entries of a block: a list of { postType, terms, entries }, empty without samples.
	 *
	 * @param array<string, mixed> $block Registry entry.
	 * @return list<array<string, mixed>>
	 */
	public static function samples( array $block ): array {
		$samples = $block['samples'] ?? null;
		if ( is_string( $samples ) ) {
			$samples = is_readable( $samples ) ? json_decode( (string) file_get_contents( $samples ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of an add-on.
		} elseif ( is_callable( $samples ) ) {
			$samples = $samples();
		}
		if ( ! is_array( $samples ) ) {
			return array();
		}
		$docs = isset( $samples['postType'] ) ? array( $samples ) : $samples;
		return array_values( array_filter( $docs, static fn( $doc ): bool => is_array( $doc ) && is_string( $doc['postType'] ?? null ) ) );
	}

	/**
	 * Adds a registered block to the site.
	 *
	 * @param string $name    Registry name of the block.
	 * @param bool   $samples Whether to add the sample entries.
	 * @param string $package Package key, '' for building-block-<owner>-<name>.
	 * @param bool   $settle  Apply what collects at once where Recipes::may_settle() allows it.
	 * @return array<string, mixed>|\WP_Error Block name, package ID (0 when nothing waits) and the steps with their
	 *                                        status: live, draft, package, unchanged, exists, waiting or rejected; a
	 *                                        settled run adds applied and partial, see Recipes::outcome().
	 */
	public static function add( string $name, bool $samples = true, string $package = '', bool $settle = false ) {
		$block = leitvia_studio()->registry( 'building_blocks' )->get( $name );
		if ( ! $block ) {
			return Rejection::make( 'leitvia_unknown_building_block', __( 'There is no building block with this name.', 'leitvia-studio' ), 404, 'building_block', array( 'building_block' => $name ), '', 'never' );
		}
		$key = '' !== $package ? $package : substr( 'building-block-' . str_replace( '/', '-', $name ), 0, 64 );
		$run = static function () use ( $name, $block, $samples, $key ): array {
			// The entries need the types the model step registers; a run that settles gets them now, not after the Inbox.
			$steps = self::settle( array( self::model_step( $name, $block, $key ) ), $key );
			$steps = array_merge( $steps, self::content_steps( $block, $samples, $key ) );
			$steps = self::settle( $steps, $key );
			$ids   = array_filter( array_column( $steps, 'package' ) );
			return array(
				'buildingBlock' => $name,
				'package'       => $ids && ! Recipes::settles( $key ) ? (int) max( $ids ) : Packages::find( $key ),
				'steps'         => $steps,
			) + Recipes::outcome();
		};
		return '' !== $package ? $run() : Recipes::collecting( $key, $run, $settle );
	}

	/**
	 * The step that adds the model of a block through leitvia/define-content-type. Entries declared in code are on the
	 * site already and left out. In a dry run the step reports new or exists and writes nothing.
	 *
	 * @param string               $name  Registry name of the block.
	 * @param array<string, mixed> $block Registry entry.
	 * @param string               $key   Package key.
	 * @param bool                 $dry   Dry run.
	 * @return array<string, mixed>
	 */
	public static function model_step( string $name, array $block, string $key, bool $dry = false ): array {
		$ability = 'leitvia/define-content-type';
		$target  = array( 'building_block' => $name );
		$code    = Model::code();
		$model   = array( 'version' => 1 );
		foreach ( Model::SECTIONS as $section ) {
			$entries = array_diff_key( (array) ( $block['model'][ $section ] ?? array() ), (array) ( $code[ $section ] ?? array() ) );
			if ( $entries ) {
				$model[ $section ] = $entries;
			}
		}
		if ( 1 === count( $model ) || ( $dry && self::installed( $block ) ) ) {
			return Recipes::step( 'contentModel', $ability, 'exists', $target );
		}
		if ( $dry ) {
			return Recipes::step( 'contentModel', $ability, 'new', $target );
		}
		$step           = Recipes::call( 'contentModel', $ability, array( 'declaration' => $model ) + ( '' !== $key ? array( 'package' => $key ) : array() ) );
		$step['target'] = (array) $step['target'] + $target;
		return $step;
	}

	/**
	 * The steps after the model: the default settings that are empty and, with samples, their terms and entries.
	 *
	 * @param array<string, mixed> $block   Registry entry.
	 * @param bool                 $samples Whether to add the sample entries.
	 * @param string               $key     Package key.
	 * @param bool                 $dry     Dry run: report new, exists or waiting, write nothing.
	 * @return list<array<string, mixed>>
	 */
	public static function content_steps( array $block, bool $samples, string $key, bool $dry = false ): array {
		$id    = $dry ? 0 : Packages::find( $key );
		$queue = $id ? Packages::operations( $id ) : array();
		$steps = self::settings_steps( $block, $key, $queue, $dry );
		return $samples ? array_merge( $steps, self::entry_steps( $block, $key, $queue, $dry ) ) : $steps;
	}

	/**
	 * Writes the default value of each site setting of the block that is empty, as an option operation.
	 *
	 * @param array<string, mixed>             $block Registry entry.
	 * @param string                           $key   Package key.
	 * @param array<int, array<string, mixed>> $queue Operations of the open package.
	 * @param bool                             $dry   Dry run.
	 * @return list<array<string, mixed>>
	 */
	private static function settings_steps( array $block, string $key, array $queue, bool $dry ): array {
		$steps = array();
		foreach ( (array) ( $block['settings'] ?? array() ) as $setting => $value ) {
			$option = Registrar::SETTING_PREFIX . $setting;
			$target = array( 'option' => $option );
			$known  = Pipeline::model_setting( $option );
			if ( $known && ! in_array( get_option( $option ), array( false, '', null ), true ) ) {
				$steps[] = Recipes::step( 'blockSettings', self::ABILITY, 'exists', $target );
			} elseif ( $dry ) {
				$steps[] = Recipes::step( 'blockSettings', self::ABILITY, 'new', $target );
			} elseif ( ! $known ) {
				$steps[] = Recipes::step( 'blockSettings', self::ABILITY, 'waiting', $target, 'model_pending' );
			} elseif ( array_filter( $queue, static fn( array $op ): bool => ( $op['target']['option'] ?? '' ) === $option ) ) {
				$steps[] = Recipes::step( 'blockSettings', self::ABILITY, 'package', $target );
			} else {
				$op      = new Operation( self::ABILITY, 'structure', $target, Hashes::of( (string) wp_json_encode( get_option( $option ) ) ), static fn(): array => array( 'value' => $value ), array(), $key );
				$steps[] = Recipes::result( 'blockSettings', self::ABILITY, Pipeline::run( $op ), $target );
			}
		}
		return $steps;
	}

	/**
	 * Creates the sample entries that are not on the site, as drafts with fields, terms and relations.
	 *
	 * @param array<string, mixed>             $block Registry entry.
	 * @param string                           $key   Package key.
	 * @param array<int, array<string, mixed>> $queue Operations of the open package.
	 * @param bool                             $dry   Dry run.
	 * @return list<array<string, mixed>>
	 */
	private static function entry_steps( array $block, string $key, array $queue, bool $dry ): array {
		$steps     = array();
		$relations = (array) ( $block['model']['relations'] ?? array() ) + (array) ( Model::current()['relations'] ?? array() );
		foreach ( self::samples( $block ) as $doc ) {
			$type = (string) $doc['postType'];
			if ( ! post_type_exists( $type ) ) {
				$steps[] = Recipes::step( 'blockEntries', self::ABILITY, $dry ? 'new' : 'waiting', array( 'post_type' => $type ), $dry ? '' : 'model_pending' );
				continue;
			}
			$declared = array_map( static fn( $listed ): array => array_column( (array) $listed, 'slug' ), (array) ( $doc['terms'] ?? array() ) );
			$terms    = $dry ? array() : self::terms( (array) ( $doc['terms'] ?? array() ), Recipes::settles( $key ) );
			foreach ( array_values( (array) ( $doc['entries'] ?? array() ) ) as $order => $entry ) {
				$entry  = (array) $entry;
				$slug   = sanitize_title( (string) ( $entry['slug'] ?? '' ) );
				$target = array(
					'post_type' => $type,
					'slug'      => $slug,
				);
				$found  = '' !== $slug ? self::entry( $type, $slug ) : 0;
				if ( '' === $slug || $found ) {
					$steps[] = Recipes::step( 'blockEntries', self::ABILITY, 'exists', $target + array( 'post' => $found ) );
					continue;
				}
				if ( $dry ) {
					$steps[] = Recipes::step( 'blockEntries', self::ABILITY, 'new', $target );
					continue;
				}
				if ( array_filter( $queue, static fn( array $op ): bool => ( $op['state']['post_type'] ?? '' ) === $type && ( $op['state']['post_name'] ?? '' ) === $slug ) ) {
					$steps[] = Recipes::step( 'blockEntries', self::ABILITY, 'package', $target );
					continue;
				}
				// A sample term that is not on the site yet: the entry waits for it rather than losing it.
				foreach ( (array) ( $entry['terms'] ?? array() ) as $taxonomy => $slugs ) {
					if ( array_diff( array_intersect( (array) $slugs, $declared[ $taxonomy ] ?? array() ), array_keys( $terms[ $taxonomy ] ?? array() ) ) ) {
						$steps[] = Recipes::step( 'blockEntries', self::ABILITY, 'waiting', $target, 'terms_pending' );
						continue 2;
					}
				}
				$meta = (array) ( $entry['fields'] ?? array() );
				foreach ( (array) ( $entry['relations'] ?? array() ) as $relation => $slugs ) {
					$to                = (string) ( $relations[ $relation ]['to'] ?? '' );
					$linked            = static fn(): array => array_map( static fn( $other ): int => self::entry( $to, (string) $other ), (array) $slugs );
					$meta[ $relation ] = $linked();
					if ( in_array( 0, $meta[ $relation ], true ) && Recipes::settles( $key ) ) {
						// The entry it links to may wait in the package (content at "suggest"); a run that settles applies it first.
						$steps             = self::settle( $steps, $key );
						$meta[ $relation ] = $linked();
					}
					if ( in_array( 0, $meta[ $relation ], true ) ) {
						$steps[] = Recipes::step( 'blockEntries', self::ABILITY, 'waiting', $target, 'relation_pending' );
						continue 2;
					}
				}
				$tax = array();
				foreach ( (array) ( $entry['terms'] ?? array() ) as $taxonomy => $slugs ) {
					$tax[ $taxonomy ] = array_values( array_filter( array_map( static fn( $term ): int => $terms[ $taxonomy ][ $term ] ?? 0, (array) $slugs ) ) );
				}
				// Samples are drafts: a person checks and publishes them, whatever the autonomy would allow.
				$state   = array(
					'post_title'   => (string) ( $entry['title'] ?? $slug ),
					'post_name'    => $slug,
					'post_excerpt' => (string) ( $entry['excerpt'] ?? '' ),
					'post_content' => (string) ( $entry['content'] ?? '' ),
					'post_status'  => 'draft',
					'menu_order'   => $order,
					'meta_input'   => $meta,
					'terms'        => array_filter( $tax ),
				) + ( isset( $entry['date'] ) ? array( 'post_date' => (string) $entry['date'] ) : array() );
				$op      = new Operation(
					self::ABILITY,
					'content',
					array(
						'post'      => 0,
						'post_type' => $type,
					),
					'',
					static fn( array $before ): array => $state + $before,
					array(),
					$key
				);
				$steps[] = Recipes::result( 'blockEntries', self::ABILITY, Pipeline::run( $op ), $target );
			}
		}
		return $steps;
	}

	/**
	 * Applies the open package of the key when the run settles (Recipes::settles()), else does nothing. The report goes
	 * to Recipes::outcome(); steps that waited in a package the run applied in full are live. Recipe runs that settle
	 * use the same (Recipes::apply()), as add-on steps of such a run may.
	 *
	 * @param list<array<string, mixed>> $steps Steps so far.
	 * @param string                     $key   Package key.
	 * @return list<array<string, mixed>>
	 */
	public static function settle( array $steps, string $key ): array {
		if ( ! Recipes::settles( $key ) ) {
			return $steps;
		}
		$id     = Packages::find( $key );
		$report = $id ? Packages::apply( $id ) : null;
		if ( is_array( $report ) ) {
			Recipes::settled( $id, $report );
		}
		// ponytail: a package applied in part leaves all its steps at package; outcome() names its errors.
		$applied = (array) ( Recipes::outcome()['applied'] ?? array() );
		foreach ( $steps as $index => $step ) {
			if ( 'package' === $step['status'] && in_array( (int) $step['package'], $applied, true ) ) {
				$steps[ $index ]['status'] = 'live';
			}
		}
		return $steps;
	}

	/**
	 * Term IDs of the sample terms by taxonomy and slug. Missing terms are added only when the run settles and the user
	 * may manage the terms of the taxonomy; otherwise they stay missing.
	 *
	 * @param array<string, mixed> $declared Terms by taxonomy: lists of slug and name.
	 * @param bool                 $create   Whether the run settles, see Recipes::settles().
	 * @return array<string, array<string, int>>
	 */
	private static function terms( array $declared, bool $create ): array {
		$ids = array();
		foreach ( $declared as $taxonomy => $terms ) {
			$object = get_taxonomy( (string) $taxonomy );
			if ( ! $object ) {
				continue;
			}
			$may = $create && current_user_can( $object->cap->manage_terms );
			foreach ( (array) $terms as $term ) {
				$slug  = (string) ( $term['slug'] ?? '' );
				$found = get_term_by( 'slug', $slug, (string) $taxonomy );
				if ( ! $found && ! $may ) {
					continue;
				}
				// ponytail: the pipeline changes terms of WooCommerce only, so sample terms are added here, by a person in a run
				// that applies its package at once; one that exists stays.
				$made = $found ? $found->term_id : wp_insert_term( (string) ( $term['name'] ?? $slug ), (string) $taxonomy, array( 'slug' => $slug ) );
				if ( is_int( $made ) || is_array( $made ) ) {
					$ids[ (string) $taxonomy ][ $slug ] = is_int( $made ) ? $made : (int) $made['term_id'];
				}
			}
		}
		return $ids;
	}

	/**
	 * ID of an entry of a post type by slug in any status agents see, 0 for none.
	 *
	 * @param string $type Post type.
	 * @param string $slug Slug.
	 */
	private static function entry( string $type, string $slug ): int {
		if ( '' === $type ) {
			return 0;
		}
		$found = get_posts(
			array(
				'post_type'   => $type,
				'name'        => $slug,
				'post_status' => array( 'publish', 'future', 'draft', 'pending' ),
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		return (int) ( $found[0] ?? 0 );
	}
}
