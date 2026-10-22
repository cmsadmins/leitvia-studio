<?php
/**
 * Leitvia Gateway: boot, agent role, settings and installation.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the gateway into WordPress. Agents act as real users with the role leitvia_agent; autonomy narrows further.
 */
final class Gateway {

	public const ROLE = 'leitvia_agent';

	public const OPTION = 'leitvia_studio_gateway';

	/**
	 * Capabilities of the role. No unfiltered_html, manage_options, edit_users or install_plugins. Others' posts and pages
	 * are open because agents maintain what people wrote; autonomy, area and content kind draw the line, not the role.
	 * Products and their categories and tags of WooCommerce likewise; no capability of orders, coupons, reports or
	 * WooCommerce settings.
	 */
	public const CAPS = array(
		'read',
		'edit_posts',
		'edit_pages',
		'edit_others_posts',
		'edit_others_pages',
		'edit_published_posts',
		'edit_published_pages',
		'publish_posts',
		'publish_pages',
		'upload_files',
		'edit_theme_options',
		'edit_products',
		'edit_others_products',
		'edit_published_products',
		'publish_products',
		'edit_product_terms',
		'assign_product_terms',
	);

	/**
	 * Areas, autonomy levels and content kinds the settings know; the pipeline applies them.
	 */
	public const AREAS = array( 'content', 'corrections', 'structure', 'design', 'admin', 'products' );

	public const LEVELS = array( 'locked', 'suggest', 'draft', 'autonomous' );

	public const KINDS = array( 'prices', 'legal', 'slugs', 'organization', 'tax', 'shipping' );

	/**
	 * Every setting with its default.
	 */
	public const DEFAULTS = array(
		'retention_days'        => 90,
		'max_table_mb'          => 50,
		'rate_per_minute'       => 60,
		'verify'                => true,
		'min_composition_score' => 80,
		'profile'               => 'standard',
		'overrides'             => array(
			'areas' => array(),
			'kinds' => array(),
		),
	);

	/**
	 * Hooks of the gateway. Runs from Plugin::boot() on plugins_loaded.
	 */
	public static function boot(): void {
		// On init, not earlier: the role name is translated.
		add_action( 'init', array( self::class, 'maybe_install' ), 1 );
		add_action( 'init', array( self::class, 'role_name' ), 2 );
		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 20 );
		// Deleting a site of a network: core drops only the tables it knows, see Store::drop_tables().
		add_filter( 'wpmu_drop_tables', array( Store::class, 'drop_tables' ) );
		add_action( 'wp_uninitialize_site', array( self::class, 'uninitialize_site' ), 5 );
		// Application passwords of agents are bound to the site that created them (multisite).
		add_action( 'wp_authenticate_application_password_errors', array( \LeitviaStudio\Rest\Gateway_Access_Controller::class, 'other_site' ), 10, 3 );
		add_action( 'wp_delete_application_password', array( \LeitviaStudio\Rest\Gateway_Access_Controller::class, 'forget_password' ), 10, 2 );
		add_action( 'init', array( self::class, 'register_setting' ) );
		add_action( 'init', array( Packages::class, 'register' ) );
		add_action( Store::CRON, array( Store::class, 'prune' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( Store::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( Store::class, 'register_eraser' ) );
		// After Registrar::force_inherit and Token_Sets::force_private (10); IDs only change post_content.
		add_filter( 'wp_insert_post_data', array( Block_Ids::class, 'on_save' ), 20, 2 );
		add_action( 'leitvia_studio_init', array( Abilities\Read_Structure::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Content_Checks::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Abilities\Read_Audit::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Abilities\Read_Design::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Abilities\Build::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Abilities\Setup::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Recipes::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Abilities\Recipes::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Abilities\Packages::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Abilities\Maintain::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Composition::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Abilities\Read_Composition::class, 'register' ) );
		add_action( 'leitvia_studio_init', array( Abilities\Read_Plan::class, 'register' ) );
		// Products area, content kinds tax and shipping and the five commerce abilities, all only with WooCommerce.
		add_action( 'leitvia_studio_init', array( Abilities\Commerce::class, 'register' ) );
		add_filter( 'leitvia_studio_gateway_areas', array( Abilities\Commerce::class, 'areas' ), 10, 2 );
		add_filter( 'leitvia_studio_gateway_content_kind', array( Abilities\Commerce::class, 'kinds' ), 10, 4 );
		add_action( 'rest_api_init', static fn() => ( new \LeitviaStudio\Rest\Packages_Controller() )->register_routes() );
		add_action( 'rest_api_init', array( \LeitviaStudio\Rest\Gateway_Access_Controller::class, 'register_routes' ) );
		// Agents write through the abilities only, see rest_guard() and xmlrpc_guard().
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest_guard' ), 10, 3 );
		add_action( 'xmlrpc_call', array( self::class, 'xmlrpc_guard' ) );
		Preview::boot();
		// Cron hook Verify::CRON (leitvia_studio_verify) checks the front end after each live write.
		Verify::boot();
		// Own MCP server leitvia when the MCP Adapter is active.
		Mcp::boot();
	}

	/**
	 * Whether the current user has the agent role.
	 */
	public static function is_agent(): bool {
		return in_array( self::ROLE, (array) wp_get_current_user()->roles, true );
	}

	/**
	 * Filter rest_pre_dispatch: agents read through any route but write only through the abilities of Leitvia
	 * (/wp-abilities/v1/abilities/leitvia/…/run) and its MCP server. The role holds core capabilities such as
	 * edit_pages and edit_theme_options for the pipeline to check; without this, an application password could write
	 * through /wp/v2 or the Studio routes past autonomy, checks, snapshot and audit log.
	 *
	 * @param mixed            $result  Response so far, null to dispatch.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function rest_guard( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! self::is_agent() || in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $result;
		}
		$route = untrailingslashit( $request->get_route() );
		if ( preg_match( '#^/wp-abilities/v1/abilities/leitvia/[a-z0-9-]+/run$#', $route ) || '/' . Mcp::ROUTE_NAMESPACE . '/' . Mcp::ROUTE === $route ) {
			return $result;
		}
		/* translators: %s: short product name */
		return Rejection::make( 'leitvia_forbidden', sprintf( __( 'Agents change the site through the %s abilities only.', 'leitvia-studio' ), leitvia_studio_brand_short() ), 403, 'identity', array(), '', 'never' );
	}

	/**
	 * Action xmlrpc_call: XML-RPC is no way for agents, it would write past the pipeline. Fires after the login of
	 * every method that needs one.
	 */
	public static function xmlrpc_guard(): void {
		if ( self::is_agent() ) {
			/* translators: %s: short product name */
			wp_die( esc_html( sprintf( __( 'Agents change the site through the %s abilities only.', 'leitvia-studio' ), leitvia_studio_brand_short() ) ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Whether the current user is a person who decides on change packages: manage_options and not the agent role. The
	 * autonomy level binds agents only; such a person may apply and roll back any operation, also over WP-CLI.
	 */
	public static function is_person(): bool {
		return current_user_can( 'manage_options' ) && ! self::is_agent();
	}

	/**
	 * Installs the current site when its schema version is older or the agent role misses a capability of CAPS, for
	 * example the first load of a site after an update.
	 */
	public static function maybe_install(): void {
		$role = get_role( self::ROLE );
		if ( (int) get_option( Store::VERSION_OPTION ) !== Store::VERSION || ( $role && array_diff( self::CAPS, array_keys( array_filter( $role->capabilities ) ) ) ) ) {
			self::install();
		}
	}

	/**
	 * Tables, role and cron event of the current site.
	 */
	public static function install(): void {
		Store::install();
		// Removing first keeps the capabilities current after an update; users keep the role name.
		remove_role( self::ROLE );
		add_role( self::ROLE, __( 'Leitvia Agent', 'leitvia-studio' ), array_fill_keys( self::CAPS, true ) );
		if ( ! wp_next_scheduled( Store::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Store::CRON );
		}
	}

	/**
	 * Shows the agent role under the brand name (filter leitvia_studio_brand); the stored role keeps its name. Runs on
	 * init.
	 */
	public static function role_name(): void {
		$roles = wp_roles();
		if ( isset( $roles->roles[ self::ROLE ] ) ) {
			/* translators: %s: short product name */
			$roles->role_names[ self::ROLE ]    = sprintf( __( '%s Agent', 'leitvia-studio' ), leitvia_studio_brand_short() );
			$roles->roles[ self::ROLE ]['name'] = $roles->role_names[ self::ROLE ];
		}
	}

	/**
	 * Activation hook: installs the current site, or every site when network wide.
	 *
	 * @param bool $network_wide Whether the plugin is activated for the whole network.
	 */
	public static function activate( bool $network_wide = false ): void {
		\LeitviaStudio\Avatars\Avatars::each_site( $network_wide, array( self::class, 'install' ) );
	}

	/**
	 * Deactivation hook: stops the prune event and pending checks after writing; tables, role and settings stay until
	 * uninstall.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		\LeitviaStudio\Avatars\Avatars::each_site(
			$network_wide,
			static function (): void {
				wp_clear_scheduled_hook( Store::CRON );
				wp_unschedule_hook( Verify::CRON );
			}
		);
	}

	/**
	 * Installs a new site of the network when Studio is active for the whole network. Runs on wp_initialize_site
	 * after core created the site's tables.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function initialize_site( \WP_Site $site ): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! is_plugin_active_for_network( plugin_basename( LEITVIA_STUDIO_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Removes what Studio keeps outside the tables of a site that is being deleted: profile pictures of its members
	 * (user meta with the prefix of the site) and the application passwords bound to it. Runs on wp_uninitialize_site
	 * before core drops the tables.
	 *
	 * @param \WP_Site $site Site being deleted.
	 */
	public static function uninitialize_site( \WP_Site $site ): void {
		global $wpdb;
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix( (int) $site->blog_id ) . \LeitviaStudio\Avatars\Avatars::OPTION, '', true );
		\LeitviaStudio\Rest\Gateway_Access_Controller::forget_site( (int) $site->blog_id );
	}

	/**
	 * JSON schema of the settings, used for REST and for sanitizing.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$levels = array(
			'type' => 'string',
			'enum' => self::LEVELS,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'retention_days'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 3650,
				),
				'max_table_mb'          => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10240,
				),
				'rate_per_minute'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 1000,
				),
				'verify'                => array( 'type' => 'boolean' ),
				'min_composition_score' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
				'profile'               => array(
					'type' => 'string',
					'enum' => array( 'standard', 'full' ),
				),
				'overrides'             => array(
					'type'                 => 'object',
					'properties'           => array(
						'areas' => array(
							'type'                 => 'object',
							'properties'           => array_fill_keys( self::AREAS, $levels ),
							'additionalProperties' => false,
						),
						'kinds' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => self::KINDS,
							),
							'uniqueItems' => true,
						),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Registers the option leitvia_studio_gateway. Runs on init. Not in /wp/v2/settings: the Agents screen saves it
	 * through /leitvia-studio/v1/gateway/settings, which asks for confirmation of the profile "full".
	 */
	public static function register_setting(): void {
		register_setting(
			'leitvia_studio',
			self::OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'Leitvia Gateway settings.', 'leitvia-studio' ),
				'default'           => self::DEFAULTS,
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Keeps each known key that matches the schema; an invalid or missing key keeps its saved value, else its default.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $value ): array {
		$value  = is_array( $value ) ? $value : array();
		$saved  = self::settings();
		$clean  = array();
		$schema = self::schema()['properties'];
		foreach ( self::DEFAULTS as $key => $fallback ) {
			$valid         = array_key_exists( $key, $value ) && true === rest_validate_value_from_schema( $value[ $key ], $schema[ $key ], $key );
			$clean[ $key ] = $valid ? rest_sanitize_value_from_schema( $value[ $key ], $schema[ $key ], $key ) : $saved[ $key ];
		}
		$clean['overrides'] += self::DEFAULTS['overrides'];
		return $clean;
	}

	/**
	 * All settings, saved values over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION );
		return array_intersect_key( ( is_array( $saved ) ? $saved : array() ) + self::DEFAULTS, self::DEFAULTS );
	}
}
