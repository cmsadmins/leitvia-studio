<?php
/**
 * Registers the content model through core APIs.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Post types, taxonomies, post meta and settings of the current declaration. No own tables, no own storage.
 */
final class Registrar {

	/**
	 * Option group of the site settings.
	 */
	public const SETTINGS_GROUP = 'leitvia_studio_site';

	/**
	 * Option prefix of the site settings.
	 */
	public const SETTING_PREFIX = 'leitvia_site_';

	/**
	 * Post type objects this class registered, by key, repeaters included.
	 *
	 * @var array<string, \WP_Post_Type>
	 */
	private static array $post_types = array();

	/**
	 * Name of the core/query variation that lists the repeater rows of the current entry.
	 */
	public const CHILDREN = 'leitvia/children';

	/**
	 * Meta key on repeater rows that went to the trash together with their entry.
	 */
	public const TRASHED_WITH_ENTRY = '_leitvia_model_trashed_with_entry';

	/**
	 * Repeater post types registered in this request, mapped to the post type of their entries.
	 *
	 * @var array<string, string>
	 */
	private static array $repeaters = array();

	/**
	 * Taxonomy objects this class registered, by key.
	 *
	 * @var array<string, \WP_Taxonomy>
	 */
	private static array $taxonomies = array();

	/**
	 * Meta keys this class registered, by post type.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $meta = array();

	/**
	 * Post types this class registered, repeaters included.
	 *
	 * @return list<string>
	 */
	public static function post_types(): array {
		return array_keys( self::$post_types );
	}

	/**
	 * Whether the post type is the one this class registered. False when another plugin registered the key again.
	 *
	 * @param string $key Post type.
	 */
	public static function owns_post_type( string $key ): bool {
		return isset( self::$post_types[ $key ] ) && get_post_type_object( $key ) === self::$post_types[ $key ];
	}

	/**
	 * Whether the taxonomy is the one this class registered.
	 *
	 * @param string $key Taxonomy.
	 */
	public static function owns_taxonomy( string $key ): bool {
		return isset( self::$taxonomies[ $key ] ) && get_taxonomy( $key ) === self::$taxonomies[ $key ];
	}

	/**
	 * Whether this class registered the meta key for the post type.
	 *
	 * @param string $post_type Post type.
	 * @param string $key       Meta key.
	 */
	public static function owns_meta( string $post_type, string $key ): bool {
		return in_array( $key, self::$meta[ $post_type ] ?? array(), true );
	}

	/**
	 * Whether the post type is a repeater of the content model.
	 *
	 * @param string $key Post type.
	 */
	public static function is_repeater( string $key ): bool {
		return isset( self::$repeaters[ $key ] );
	}

	/**
	 * Registers everything. Runs on init, priority 9. An invalid declaration (from code) registers nothing.
	 */
	public static function register(): void {
		$model = Model::current();
		if ( true !== Model::validate( $model ) ) {
			return;
		}
		foreach ( $model['postTypes'] ?? array() as $key => $post_type ) {
			$public = $post_type['public'] ?? false;
			$args   = array(
				'labels'            => self::post_type_labels( $post_type['label'], $post_type['singularLabel'] ),
				'description'       => esc_html( $post_type['description'] ?? '' ),
				'public'            => $public,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'hierarchical'      => $post_type['hierarchical'] ?? false,
				'has_archive'       => $public && ( $post_type['hasArchive'] ?? false ),
				'rewrite'           => $public ? array( 'slug' => $post_type['slug'] ?? $key ) : false,
				'supports'          => array_merge( $post_type['supports'] ?? array( 'title', 'editor' ), array( 'custom-fields' ) ),
				'menu_icon'         => $post_type['icon'] ?? 'dashicons-admin-post',
				'show_in_abilities' => $post_type['showInAbilities'] ?? false,
			);
			// Without a public flag only users who may edit the type read it over REST.
			if ( ! $public ) {
				$args['rest_controller_class'] = Posts_Controller::class;
			}
			self::add_post_type( $key, $args );
			self::register_fields( $key, (array) ( $post_type['fields'] ?? array() ) );

			// Repeater rows are child posts of the entry (post_parent, menu_order) with the status inherit, so reading
			// a row follows the entry. Agents read them through the entry.
			foreach ( (array) ( $post_type['repeaters'] ?? array() ) as $repeater ) {
				self::$repeaters[ $repeater['postType'] ] = $key;
				self::add_post_type(
					$repeater['postType'],
					array(
						'labels'                => self::post_type_labels( $repeater['label'], $repeater['singularLabel'] ),
						'public'                => false,
						'show_ui'               => false,
						'show_in_rest'          => true,
						// Hierarchical for the parent field and filter of the REST API; the parent is the entry.
						'hierarchical'          => true,
						'rewrite'               => false,
						'rest_controller_class' => Posts_Controller::class,
						'supports'              => array( 'title', 'custom-fields', 'page-attributes' ),
						'show_in_abilities'     => false,
					)
				);
				self::register_fields( $repeater['postType'], (array) ( $repeater['fields'] ?? array() ) );
			}
		}

		foreach ( $model['taxonomies'] ?? array() as $key => $taxonomy ) {
			$public = $taxonomy['public'] ?? false;
			$args   = array(
				'labels'            => self::taxonomy_labels( $taxonomy['label'], $taxonomy['singularLabel'] ),
				'description'       => esc_html( $taxonomy['description'] ?? '' ),
				'public'            => $public,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'hierarchical'      => $taxonomy['hierarchical'] ?? false,
				'rewrite'           => $public ? array( 'slug' => $taxonomy['slug'] ?? $key ) : false,
				'show_in_abilities' => $taxonomy['showInAbilities'] ?? false,
			);
			if ( ! $public ) {
				$args['rest_controller_class'] = Terms_Controller::class;
			}
			register_taxonomy( $key, $taxonomy['postTypes'], $args );
			$object = get_taxonomy( $key );
			if ( $object ) {
				self::$taxonomies[ $key ] = $object;
			}
		}

		// Relations are forward only: a list of post IDs on the "from" type.
		foreach ( $model['relations'] ?? array() as $key => $relation ) {
			$schema = array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			);
			if ( empty( $relation['multiple'] ) ) {
				$schema['maxItems'] = 1;
			}
			// Targets are checked over REST, where a user picks them (check_rest_meta()); imports keep every ID.
			register_post_meta( $relation['from'], $key, self::meta_args( 'array', $schema, $relation['label'] ) );
			self::$meta[ $relation['from'] ][] = $key;
		}

		foreach ( $model['settings'] ?? array() as $key => $setting ) {
			list( $type, $schema ) = self::schema_for( $setting );
			$name                  = self::SETTING_PREFIX . $key;
			register_setting(
				self::SETTINGS_GROUP,
				$name,
				array(
					'type'              => $type,
					'label'             => $setting['label'],
					'description'       => $setting['description'] ?? '',
					'show_in_rest'      => array( 'schema' => $schema ),
					'sanitize_callback' => static function ( $value ) use ( $schema, $name ) {
						return is_wp_error( rest_validate_value_from_schema( $value, $schema, $name ) )
							? get_option( $name )
							: rest_sanitize_value_from_schema( $value, $schema, $name );
					},
					'show_in_abilities' => $setting['showInAbilities'] ?? false,
				)
			);
		}
	}

	/**
	 * Registers a post type and remembers the object.
	 *
	 * @param lowercase-string&non-empty-string $key Post type, checked by the schema.
	 * @param array<string, mixed>              $args Arguments.
	 */
	private static function add_post_type( string $key, array $args ): void {
		$object = register_post_type( $key, $args );
		if ( $object instanceof \WP_Post_Type ) {
			self::$post_types[ $key ] = $object;
			add_filter( "rest_pre_insert_{$key}", array( self::class, 'check_rest_meta' ), 10, 2 );
		}
	}

	/**
	 * Checks the meta of a REST request before the post is saved. Runs on rest_pre_insert_<post type>.
	 *
	 * - Values equal to the stored ones, and null for values never stored, are left out: the block editor sends every
	 *   registered meta value back, core refuses null for an empty select or date, and an option removed from the
	 *   declaration must not block saving the other fields.
	 * - Relations: newly added IDs must point to posts of the target type the user may read (published ones always).
	 *   IDs stored before stay, also when the current user cannot read them.
	 * - Required fields must have a value when the post is published or scheduled.
	 *
	 * @param \stdClass|\WP_Error $prepared Post prepared for the database.
	 * @param \WP_REST_Request    $request  Request.
	 * @return \stdClass|\WP_Error
	 */
	public static function check_rest_meta( $prepared, \WP_REST_Request $request ) {
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$id    = (int) ( $prepared->ID ?? 0 );
		$type  = $id ? (string) get_post_type( $id ) : (string) ( $prepared->post_type ?? '' );
		$model = Model::current();
		$meta  = is_array( $request['meta'] ) ? $request['meta'] : array();

		foreach ( $meta as $key => $value ) {
			$exists = $id && metadata_exists( 'post', $id, (string) $key );
			$stored = $exists ? get_post_meta( $id, (string) $key, true ) : null;
			// Null comes back for a stored value the schema refuses (an option removed later); core cannot reset it.
			if ( null === $value ? ! $exists || ! self::valid_stored( $type, (string) $key, $stored ) : $exists && self::same( $value, $stored ) ) {
				unset( $meta[ $key ] );
			}
		}

		foreach ( $model['relations'] ?? array() as $key => $relation ) {
			if ( $type !== $relation['from'] || ! is_array( $meta[ $key ] ?? null ) ) {
				continue;
			}
			$stored = $id ? array_map( 'intval', (array) get_post_meta( $id, $key, true ) ) : array();
			foreach ( array_diff( array_map( 'intval', $meta[ $key ] ), $stored ) as $target ) {
				// Published posts of a viewable type are public; others need read_post (types without a public flag).
				$public = 'publish' === get_post_status( $target ) && is_post_type_viewable( $relation['to'] );
				if ( get_post_type( $target ) !== $relation['to'] || ! ( $public || current_user_can( 'read_post', $target ) ) ) {
					return new \WP_Error(
						'rest_invalid_param',
						/* translators: 1: relation label, for example Speakers; 2: post ID. */
						sprintf( __( '%1$s: post %2$d cannot be linked. It does not exist, has another type or you may not read it.', 'leitvia-studio' ), $relation['label'], $target ),
						array( 'status' => 400 )
					);
				}
			}
		}

		// Entries need their required fields once published or scheduled; repeater rows always, they are live with
		// their entry (status inherit).
		$status = (string) ( $prepared->post_status ?? ( $id ? get_post_status( $id ) : 'draft' ) );
		$fields = in_array( $status, array( 'publish', 'future' ), true ) ? ( $model['postTypes'][ $type ]['fields'] ?? array() ) : array();
		// A request that changes no meta of an existing row (a move by menu_order) keeps older rows sortable.
		if ( self::is_repeater( $type ) && ! ( $id && ! $meta ) ) {
			foreach ( $model['postTypes'][ (string) self::entry_type( $type ) ]['repeaters'] ?? array() as $repeater ) {
				$fields = $type === $repeater['postType'] ? ( $repeater['fields'] ?? array() ) : $fields;
			}
		}
		foreach ( $fields as $key => $field ) {
			$value = array_key_exists( $key, $meta ) ? $meta[ $key ] : ( $id ? get_post_meta( $id, $key, true ) : '' );
			if ( ! empty( $field['required'] ) && ( null === $value || '' === $value ) ) {
				return new \WP_Error(
					'rest_missing_param',
					self::is_repeater( $type )
						/* translators: %s: field label, for example Time. */
						? sprintf( __( '%s is required.', 'leitvia-studio' ), $field['label'] )
						/* translators: %s: field label, for example Start. */
						: sprintf( __( '%s is required before publishing.', 'leitvia-studio' ), $field['label'] ),
					array( 'status' => 400 )
				);
			}
		}

		if ( isset( $request['meta'] ) ) {
			$request->set_param( 'meta', $meta );
		}
		return $prepared;
	}

	/**
	 * Whether a stored meta value matches the REST schema it is registered with.
	 *
	 * @param string $type   Post type.
	 * @param string $key    Meta key.
	 * @param mixed  $stored Stored value.
	 */
	private static function valid_stored( string $type, string $key, $stored ): bool {
		$schema = get_registered_meta_keys( 'post', $type )[ $key ]['show_in_rest']['schema'] ?? null;
		return ! is_array( $schema ) || ! is_wp_error( rest_validate_value_from_schema( $stored, $schema ) );
	}

	/**
	 * Whether a sent meta value equals the stored one; numbers and booleans arrive typed, the database holds strings.
	 *
	 * @param mixed $sent   Value of the request.
	 * @param mixed $stored Stored value.
	 */
	private static function same( $sent, $stored ): bool {
		if ( is_array( $sent ) || is_array( $stored ) ) {
			return is_array( $sent ) && is_array( $stored ) && array_map( 'strval', $sent ) === array_map( 'strval', $stored );
		}
		return is_scalar( $sent ) && is_scalar( $stored ) && (string) $sent === (string) $stored;
	}

	/**
	 * Post type of the entries a repeater belongs to.
	 *
	 * @param string $repeater Repeater post type.
	 */
	public static function entry_type( string $repeater ): ?string {
		return self::$repeaters[ $repeater ] ?? null;
	}

	/**
	 * Rows of a repeater follow their entry: editing, deleting or publishing a row needs edit_post on the entry,
	 * reading it read_post. A row whose parent is not an entry of the declaring type allows nothing. Runs on
	 * map_meta_cap.
	 *
	 * @param string[]     $caps    Primitive capabilities.
	 * @param string       $cap     Capability checked.
	 * @param int          $user_id User ID.
	 * @param array<mixed> $args    Arguments, the post ID first.
	 * @return string[]
	 */
	public static function map_row_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post', 'read_post' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$row = get_post( (int) $args[0] );
		if ( ! $row || ! self::is_repeater( $row->post_type ) || ! $row->post_parent ) {
			return $caps;
		}
		$entry = get_post( $row->post_parent );
		if ( ! $entry || self::$repeaters[ $row->post_type ] !== $entry->post_type ) {
			return array( 'do_not_allow' );
		}
		// Reading follows the entry only for live rows; a trashed row is for those who may edit the entry.
		$read = 'read_post' === $cap && 'inherit' === $row->post_status;
		return map_meta_cap( $read ? 'read_post' : 'edit_post', $user_id, $entry->ID );
	}

	/**
	 * Gives repeater rows the status inherit (private without an entry), so reading a row follows its entry. Runs on
	 * wp_insert_post_data.
	 *
	 * @param array<string, mixed> $data Post data, slashed.
	 * @return array<string, mixed>
	 */
	public static function force_inherit( array $data ): array {
		if ( self::is_repeater( (string) $data['post_type'] ) && ! in_array( $data['post_status'], array( 'trash', 'auto-draft' ), true ) ) {
			$data['post_status'] = empty( $data['post_parent'] ) ? 'private' : 'inherit';
		}
		return $data;
	}

	/**
	 * Flushes the rewrite rules once after the declaration changed. Runs on init, late.
	 */
	public static function maybe_flush(): void {
		if ( get_option( Model::FLUSH_OPTION ) ) {
			delete_option( Model::FLUSH_OPTION );
			flush_rewrite_rules();
		}
	}

	/**
	 * Unregisters the own post types and flushes the rewrite rules; network wide, every site rebuilds its rules on the
	 * next request. Content stays in the database.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		foreach ( self::post_types() as $post_type ) {
			unregister_post_type( $post_type );
		}
		foreach ( array_keys( self::$taxonomies ) as $taxonomy ) {
			unregister_taxonomy( $taxonomy );
		}
		self::$post_types = array();
		self::$taxonomies = array();
		self::$repeaters  = array();
		self::$meta       = array();
		if ( ! $network_wide || ! is_multisite() ) {
			flush_rewrite_rules();
			return;
		}
		// The post types of another site are not registered here; an empty option makes each site rebuild its rules.
		\LeitviaStudio\Avatars\Avatars::each_site( true, static fn() => delete_option( 'rewrite_rules' ) );
	}

	/**
	 * Declaration the editor panel of a post type needs: the type with its fields and repeaters, and the relations
	 * that start at it. Null for types without any of them and for types this class did not register.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, mixed>|null
	 */
	public static function editor_declaration( string $post_type ): ?array {
		if ( ! self::owns_post_type( $post_type ) || self::is_repeater( $post_type ) ) {
			return null;
		}
		$model     = Model::current();
		$type      = $model['postTypes'][ $post_type ];
		$relations = array_filter( $model['relations'] ?? array(), static fn( array $relation ): bool => $post_type === $relation['from'] );
		if ( empty( $type['fields'] ) && empty( $type['repeaters'] ) && ! $relations ) {
			return null;
		}
		return array(
			'postType'  => $post_type,
			'postId'    => (int) get_the_ID(),
			'postTypes' => array( $post_type => $type ),
			'relations' => (object) $relations,
		);
	}

	/**
	 * Declaration for the site editor: every post type with repeaters, for the "Child entries" variation in templates
	 * such as single-event. No panel there. Null without repeaters.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function site_editor_declaration(): ?array {
		$types = array_filter(
			Model::current()['postTypes'] ?? array(),
			static fn( array $type, string $key ): bool => ! empty( $type['repeaters'] ) && self::owns_post_type( $key ),
			ARRAY_FILTER_USE_BOTH
		);
		return $types ? array(
			'postType'  => '',
			'postId'    => 0,
			'postTypes' => $types,
			'relations' => (object) array(),
		) : null;
	}

	/**
	 * Loads the editor script in the post and site editor, which always registers the format "Highlight", with the
	 * bindings data while the content model declares something to bind. The model panel gets the declaration of post types with fields, relations or
	 * repeaters; the site editor gets the types with repeaters for the "Child entries" variation. Runs on
	 * enqueue_block_editor_assets.
	 */
	public static function enqueue_editor(): void {
		$screen = get_current_screen();
		// Not in the widget editors, which must not load the editor package.
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'site-editor' ), true ) ) {
			return;
		}
		$declaration = 'site-editor' === $screen->base ? self::site_editor_declaration() : self::editor_declaration( (string) $screen->post_type );
		$bindings    = Bindings::editor_data();
		$asset_file  = dirname( LEITVIA_STUDIO_FILE ) . '/build/editor.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$url   = plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'build/';
		wp_enqueue_script( 'leitvia-studio-editor', $url . 'editor.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'leitvia-studio-editor', $url . 'style-editor.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( 'leitvia-studio-editor', 'rtl', 'replace' );
		wp_set_script_translations( 'leitvia-studio-editor', 'leitvia-studio' );
		foreach ( array_filter(
			array(
				'leitviaStudioModel'    => $declaration,
				'leitviaStudioBindings' => $bindings,
			)
		) as $name => $data ) {
			wp_add_inline_script( 'leitvia-studio-editor', "window.$name = " . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		}
	}

	/**
	 * Copies the variation name of a core/query block into its query, which reaches the post template as context.
	 * Runs on render_block_data.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	public static function mark_children_query( array $block ): array {
		if ( 'core/query' === $block['blockName'] && self::CHILDREN === ( $block['attrs']['namespace'] ?? '' ) && is_array( $block['attrs']['query'] ?? null ) ) {
			$block['attrs']['query']['namespace'] = self::CHILDREN;
		}
		return $block;
	}

	/**
	 * Limits the "Child entries" query to the rows of the current entry in their order. Runs on
	 * query_loop_block_query_vars.
	 *
	 * @param array<string, mixed> $query Query vars.
	 * @param \WP_Block            $block Post template block.
	 * @return array<string, mixed>
	 */
	public static function children_query_vars( array $query, \WP_Block $block ): array {
		if ( self::CHILDREN !== ( $block->context['query']['namespace'] ?? '' ) ) {
			return $query;
		}
		$parent = (int) get_the_ID();
		// Rows are part of the entry: nothing without one, nothing while its password is missing.
		if ( ! $parent || post_password_required( $parent ) ) {
			$query['post__in'] = array( 0 );
		}
		$query['post_parent'] = $parent;
		// The variation stores the entry it was inserted in as query.parents for the editor preview; on the front end
		// the current entry counts, in other entries, templates and patterns too.
		unset( $query['post_parent__in'] );
		$query['orderby'] = 'menu_order';
		$query['order']   = 'ASC';
		// Core only queries viewable post types; repeater rows are not public and read through their entry.
		$post_type = (string) ( $block->context['query']['postType'] ?? '' );
		if ( self::is_repeater( $post_type ) ) {
			$query['post_type']   = $post_type;
			$query['post_status'] = 'inherit';
		}
		return $query;
	}

	/**
	 * Moves the repeater rows of an entry to the trash with it and restores them with it. Publishing or unpublishing
	 * needs nothing: rows have the status inherit and follow the entry. Runs on transition_post_status.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function follow_entry( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status === $old_status || ! in_array( 'trash', array( $new_status, $old_status ), true ) ) {
			return;
		}
		if ( 'trash' === $new_status ) {
			self::trash_children( $post->ID, $post );
			return;
		}
		foreach ( self::children( $post, 'trash', true ) as $child ) {
			delete_post_meta( $child, self::TRASHED_WITH_ENTRY );
			wp_untrash_post( $child );
		}
	}

	/**
	 * Moves the repeater rows of an entry to the trash, marked so that restoring the entry brings back only these and
	 * not rows deleted one by one before. Runs on before_delete_post as well: rows of an entry deleted for good go to
	 * the trash. Without a trash (EMPTY_TRASH_DAYS 0) wp_trash_post() deletes them with the entry.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function trash_children( int $post_id, \WP_Post $post ): void {
		foreach ( self::children( $post, 'inherit' ) as $child ) {
			if ( EMPTY_TRASH_DAYS ) {
				update_post_meta( $child, self::TRASHED_WITH_ENTRY, 1 );
			}
			wp_trash_post( $child );
		}
	}

	/**
	 * IDs of the repeater rows of an entry with the given status.
	 *
	 * @param \WP_Post $post   Entry.
	 * @param string   $status Post status.
	 * @param bool     $marked Only rows trashed together with the entry.
	 * @return list<int>
	 */
	private static function children( \WP_Post $post, string $status, bool $marked = false ): array {
		$types = array_keys( self::$repeaters, $post->post_type, true );
		if ( ! $types ) {
			return array();
		}
		return array_values(
			array_map(
				'intval',
				get_posts(
					array(
						'post_type'   => $types,
						'post_parent' => $post->ID,
						'post_status' => $status,
						'fields'      => 'ids',
						'numberposts' => -1,
						'meta_key'    => $marked ? self::TRASHED_WITH_ENTRY : '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only when an entry leaves the trash.
					)
				)
			)
		);
	}

	/**
	 * Meta type and REST schema of a field.
	 *
	 * @param array<string, mixed> $field Field declaration.
	 * @return array{0: string, 1: array<string, mixed>}
	 */
	public static function schema_for( array $field ): array {
		$range = array_filter(
			array(
				'minimum' => $field['min'] ?? null,
				'maximum' => $field['max'] ?? null,
			),
			'is_numeric'
		);
		switch ( $field['type'] ) {
			case 'email':
				return array(
					'string',
					array(
						'type'   => 'string',
						'format' => 'email',
					),
				);
			case 'url':
				return array(
					'string',
					array(
						'type'   => 'string',
						'format' => 'uri',
					),
				);
			case 'integer':
				return array( 'integer', array( 'type' => 'integer' ) + $range );
			case 'number':
				return array( 'number', array( 'type' => 'number' ) + $range );
			case 'boolean':
				return array( 'boolean', array( 'type' => 'boolean' ) );
			case 'select':
				return array(
					'string',
					array(
						'type' => 'string',
						'enum' => array_map( 'strval', array_keys( $field['options'] ) ),
					),
				);
			case 'date':
				return array(
					'string',
					array(
						'type'    => 'string',
						'format'  => 'date',
						'pattern' => '^\d{4}-\d{2}-\d{2}$',
					),
				);
			case 'datetime':
				return array(
					'string',
					array(
						'type'   => 'string',
						'format' => 'date-time',
					),
				);
			case 'time':
				return array(
					'string',
					array(
						'type'    => 'string',
						'pattern' => '^\d{2}:\d{2}$',
					),
				);
			case 'image':
				return array(
					'integer',
					array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				);
			default:
				return array(
					'string',
					array_filter(
						array(
							'type'      => 'string',
							'maxLength' => $field['maxLength'] ?? null,
						)
					),
				);
		}
	}

	/**
	 * Registers the fields of a post type as post meta.
	 *
	 * @param string                              $post_type Post type.
	 * @param array<string, array<string, mixed>> $fields    Fields by meta key.
	 */
	private static function register_fields( string $post_type, array $fields ): void {
		foreach ( $fields as $key => $field ) {
			list( $type, $schema ) = self::schema_for( $field );
			$args                  = self::meta_args( $type, $schema, $field['label'] );
			if ( 'image' === $field['type'] ) {
				$args['sanitize_callback'] = static fn( $id ): int => wp_attachment_is_image( (int) $id ) ? (int) $id : 0;
			}
			register_post_meta( $post_type, $key, $args );
			self::$meta[ $post_type ][] = $key;
		}
	}

	/**
	 * Arguments of register_post_meta().
	 *
	 * @param string               $type   Meta type.
	 * @param array<string, mixed> $schema REST schema.
	 * @param string               $label  Label.
	 * @return array<string, mixed>
	 */
	private static function meta_args( string $type, array $schema, string $label ): array {
		return array(
			'type'          => $type,
			'label'         => $label,
			'single'        => true,
			'show_in_rest'  => array( 'schema' => $schema ),
			'auth_callback' => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
		);
	}

	/**
	 * Labels of a post type.
	 *
	 * @param string $plural   Plural label.
	 * @param string $singular Singular label.
	 * @return array<string, string>
	 */
	private static function post_type_labels( string $plural, string $singular ): array {
		// Core prints labels as trusted HTML; the declaration refuses < and >, escaping covers the rest.
		$plural   = esc_html( $plural );
		$singular = esc_html( $singular );
		return array(
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => $plural,
			/* translators: %s: plural label of a post type, for example Events. */
			'all_items'             => sprintf( __( 'All %s', 'leitvia-studio' ), $plural ),
			/* translators: %s: singular label of a post type, for example Event. */
			'add_new_item'          => sprintf( __( 'Add new %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a post type. */
			'new_item'              => sprintf( __( 'New %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a post type. */
			'edit_item'             => sprintf( __( 'Edit %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a post type. */
			'view_item'             => sprintf( __( 'View %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: plural label of a post type. */
			'view_items'            => sprintf( __( 'View %s', 'leitvia-studio' ), $plural ),
			/* translators: %s: plural label of a post type. */
			'search_items'          => sprintf( __( 'Search %s', 'leitvia-studio' ), $plural ),
			/* translators: %s: plural label of a post type. */
			'not_found'             => sprintf( __( 'No %s found.', 'leitvia-studio' ), $plural ),
			/* translators: %s: plural label of a post type. */
			'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'leitvia-studio' ), $plural ),
			/* translators: %s: singular label of a post type. */
			'parent_item_colon'     => sprintf( __( 'Parent %s:', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a post type. */
			'archives'              => sprintf( __( '%s archives', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a post type. */
			'attributes'            => sprintf( __( '%s attributes', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a post type. */
			'insert_into_item'      => sprintf( __( 'Insert into %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a post type. */
			'uploaded_to_this_item' => sprintf( __( 'Uploaded to this %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: plural label of a post type. */
			'filter_items_list'     => sprintf( __( 'Filter %s list', 'leitvia-studio' ), $plural ),
			/* translators: %s: plural label of a post type. */
			'items_list_navigation' => sprintf( __( '%s list navigation', 'leitvia-studio' ), $plural ),
			/* translators: %s: plural label of a post type. */
			'items_list'            => sprintf( __( '%s list', 'leitvia-studio' ), $plural ),
			/* translators: %s: singular label of a post type. */
			'item_published'        => sprintf( __( '%s published.', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a post type. */
			'item_updated'          => sprintf( __( '%s updated.', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a post type. */
			'item_link'             => sprintf( __( '%s link', 'leitvia-studio' ), $singular ),
		);
	}

	/**
	 * Labels of a taxonomy.
	 *
	 * @param string $plural   Plural label.
	 * @param string $singular Singular label.
	 * @return array<string, string>
	 */
	private static function taxonomy_labels( string $plural, string $singular ): array {
		$plural   = esc_html( $plural );
		$singular = esc_html( $singular );
		return array(
			'name'              => $plural,
			'singular_name'     => $singular,
			'menu_name'         => $plural,
			/* translators: %s: plural label of a taxonomy, for example Event types. */
			'all_items'         => sprintf( __( 'All %s', 'leitvia-studio' ), $plural ),
			/* translators: %s: singular label of a taxonomy, for example Event type. */
			'add_new_item'      => sprintf( __( 'Add new %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a taxonomy. */
			'new_item_name'     => sprintf( __( 'New %s name', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a taxonomy. */
			'edit_item'         => sprintf( __( 'Edit %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a taxonomy. */
			'view_item'         => sprintf( __( 'View %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a taxonomy. */
			'update_item'       => sprintf( __( 'Update %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: plural label of a taxonomy. */
			'search_items'      => sprintf( __( 'Search %s', 'leitvia-studio' ), $plural ),
			/* translators: %s: plural label of a taxonomy. */
			'not_found'         => sprintf( __( 'No %s found.', 'leitvia-studio' ), $plural ),
			/* translators: %s: singular label of a taxonomy. */
			'parent_item'       => sprintf( __( 'Parent %s', 'leitvia-studio' ), $singular ),
			/* translators: %s: singular label of a taxonomy. */
			'parent_item_colon' => sprintf( __( 'Parent %s:', 'leitvia-studio' ), $singular ),
			/* translators: %s: plural label of a taxonomy. */
			'back_to_items'     => sprintf( __( 'Go to %s', 'leitvia-studio' ), $plural ),
		);
	}
}
