<?php
/**
 * REST routes /leitvia-studio/v1/gateway/access and /gateway/settings for the Agents screen.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Rest;

use LeitviaStudio\Gateway\Abilities\Commerce;
use LeitviaStudio\Gateway\Autonomy;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Gateway\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Agent users and their access, and the gateway settings, for people with manage_options who are no agent. An agent
 * gets an application password of core; its plain text is in the one response that created it and nowhere else.
 * Revoking deletes every application password of the agent. Each change gets an audit entry without the password.
 * Cookie requests need the REST nonce as always.
 *
 * In a multisite network users belong to the network, application passwords to the user. So a new agent needs
 * create_users, as any new user of the network; a site administrator manages only agents that work on no other site;
 * and every password created here is bound to its site and refused on the others (SITES_META, other_site()).
 */
final class Gateway_Access_Controller {

	/**
	 * MCP Adapter versions the MCP server of Studio is built for: from this version up to the next minor version.
	 */
	public const MCP_ADAPTER = '0.7.1';

	/**
	 * User meta of an agent in a network: UUID of an application password => ID of the site it works on.
	 */
	public const SITES_META = 'leitvia_agent_password_sites';

	/**
	 * Registers the routes. Runs on rest_api_init.
	 */
	public static function register_routes(): void {
		$can  = static fn(): bool => Gateway::is_person();
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
			'/gateway/access',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'access' ),
					'permission_callback' => $can,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create' ),
					'permission_callback' => $can,
					'args'                => array(
						'name' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 60,
							'required'  => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$base,
			'/gateway/access/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'revoke' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);
		register_rest_route(
			$base,
			'/gateway/access/(?P<id>\d+)/password',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'password' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);
		register_rest_route(
			$base,
			'/gateway/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( self::settings() ),
					'permission_callback' => $can,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'save_settings' ),
					'permission_callback' => $can,
				),
			)
		);
	}

	/**
	 * Agents, whether application passwords work here, and the state of the MCP Adapter.
	 */
	public static function access(): \WP_REST_Response {
		$version = defined( 'WP_MCP_VERSION' ) ? (string) constant( 'WP_MCP_VERSION' ) : '';
		return rest_ensure_response(
			array(
				'agents'    => array_map( array( self::class, 'agent' ), get_users( array( 'role' => Gateway::ROLE ) ) ),
				'passwords' => wp_is_application_passwords_available(),
				'mcp'       => array(
					'active'     => '' !== $version,
					'version'    => $version,
					'required'   => self::MCP_ADAPTER,
					'compatible' => '' !== $version && version_compare( $version, self::MCP_ADAPTER, '>=' ) && version_compare( $version, '0.8', '<' ),
					'url'        => 'https://wordpress.org/plugins/mcp-adapter/',
				),
			)
		);
	}

	/**
	 * Creates an agent user with the role leitvia_agent and its first application password.
	 *
	 * @param \WP_REST_Request $request Request with name.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error Agent and the password, shown once.
	 */
	public static function create( \WP_REST_Request $request ) {
		$name = sanitize_text_field( (string) $request['name'] );
		if ( '' === $name ) {
			return new \WP_Error( 'leitvia_agent_name', __( 'Enter a name for the agent.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		if ( ! wp_is_application_passwords_available() ) {
			return self::no_passwords();
		}
		// In a network only create_users may add a user, as on the screen of core (setting "Add New Users").
		if ( ! current_user_can( 'create_users' ) ) {
			return new \WP_Error( 'leitvia_cannot_create_users', __( 'You may not add users to this network. Ask a network administrator to create the agent.', 'leitvia-studio' ), array( 'status' => 403 ) );
		}
		$base  = sanitize_user( 'agent-' . sanitize_title( $name ), true );
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . '-' . ( ++$i );
		}
		$user = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'display_name' => $name,
				'nickname'     => $name,
				'role'         => Gateway::ROLE,
			)
		);
		if ( is_wp_error( $user ) ) {
			$user->add_data( array( 'status' => 400 ) );
			return $user;
		}
		self::log( 'access/create', $user );
		return self::new_password( $user );
	}

	/**
	 * A further application password for an agent, for example after a revoke.
	 *
	 * @param \WP_REST_Request $request Request with id.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error Agent and the password, shown once.
	 */
	public static function password( \WP_REST_Request $request ) {
		$user = self::find( (int) $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! wp_is_application_passwords_available() ) {
			return self::no_passwords();
		}
		return self::new_password( $user->ID );
	}

	/**
	 * Revokes the access of an agent: deletes all its application passwords. The user and its content stay.
	 *
	 * @param \WP_REST_Request $request Request with id.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error Agent and the number of deleted passwords.
	 */
	public static function revoke( \WP_REST_Request $request ) {
		$user = self::find( (int) $request['id'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		// Only the passwords of this site; unbound ones too when the agent works here alone.
		$alone   = self::sites( $user->ID ) === array( get_current_blog_id() );
		$deleted = 0;
		foreach ( self::passwords( $user->ID ) as $item ) {
			if ( ( $alone || isset( self::bound( $user->ID )[ $item['uuid'] ] ) ) && true === \WP_Application_Passwords::delete_application_password( $user->ID, $item['uuid'] ) ) {
				++$deleted;
			}
		}
		self::log( 'access/revoke', $user->ID, array( 'passwords' => $deleted ) );
		return rest_ensure_response(
			array(
				'agent'   => self::agent( $user ),
				'revoked' => $deleted,
			)
		);
	}

	/**
	 * Settings with the schema parts the screen needs: areas, levels, content kinds and the default level per area.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		return array(
			'settings' => Gateway::settings(),
			// The products area and its kinds exist only with WooCommerce.
			'areas'    => Scope::commerce() ? Gateway::AREAS : array_values( array_diff( Gateway::AREAS, array( 'products' ) ) ),
			'levels'   => Gateway::LEVELS,
			'kinds'    => Scope::commerce() ? Gateway::KINDS : array_values( array_diff( Gateway::KINDS, array( 'tax', 'shipping' ) ) ),
			'defaults' => Autonomy::DEFAULTS + ( Scope::commerce() ? array( 'products' => Commerce::LEVEL ) : array() ),
		);
	}

	/**
	 * Saves the settings sent, validated against Gateway::schema(); keys not sent keep their value. Switching to the
	 * profile "full" needs confirm: true. The area admin stays locked and is never stored as an override.
	 *
	 * @param \WP_REST_Request $request Request with settings keys and confirm.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_settings( \WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$value  = array_intersect_key( $params, Gateway::DEFAULTS );
		$valid  = rest_validate_value_from_schema( $value, Gateway::schema(), 'settings' );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( array( 'status' => 400 ) );
			return $valid;
		}
		$saved = Gateway::settings();
		$next  = array_replace( $saved, $value );
		if ( 'full' === $next['profile'] && 'full' !== $saved['profile'] && true !== ( $params['confirm'] ?? false ) ) {
			return new \WP_Error( 'leitvia_confirm_required', __( 'Confirm that agents may change content without review before you switch to the profile "Fully autonomous".', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		unset( $next['overrides']['areas']['admin'] );
		update_option( Gateway::OPTION, $next );
		self::log( 'access/settings', 0, array( 'profile' => Gateway::settings()['profile'] ) );
		return rest_ensure_response( self::settings() );
	}

	/**
	 * An agent for the list: id, name, login, number of application passwords and when one was last used (UTC).
	 *
	 * @param \WP_User $user Agent.
	 * @return array<string, mixed>
	 */
	private static function agent( \WP_User $user ): array {
		$passwords = self::passwords( $user->ID );
		$used      = array_filter( array_map( static fn( array $item ): int => (int) ( $item['last_used'] ?? 0 ), $passwords ) );
		return array(
			'id'        => $user->ID,
			'name'      => $user->display_name,
			'login'     => $user->user_login,
			'passwords' => count( $passwords ),
			'last_used' => $used ? gmdate( 'Y-m-d H:i:s', max( $used ) ) : '',
		);
	}

	/**
	 * Creates an application password for an agent and answers with it.
	 *
	 * @param int $user_id Agent.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function new_password( int $user_id ) {
		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => leitvia_studio_brand()['name'] ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( is_multisite() ) {
			update_user_meta( $user_id, self::SITES_META, array( $created[1]['uuid'] => get_current_blog_id() ) + self::bound( $user_id, false ) );
		}
		self::log( 'access/password', $user_id );
		$response = rest_ensure_response(
			array(
				'agent'    => self::agent( new \WP_User( $user_id ) ),
				'password' => \WP_Application_Passwords::chunk_password( $created[0] ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The agent user with this ID, or leitvia_unknown_agent for any other user.
	 *
	 * @param int $id User ID.
	 * @return \WP_User|\WP_Error
	 */
	private static function find( int $id ) {
		$user = get_userdata( $id );
		if ( ! $user || ! in_array( Gateway::ROLE, (array) $user->roles, true ) ) {
			return new \WP_Error( 'leitvia_unknown_agent', __( 'There is no agent with this ID.', 'leitvia-studio' ), array( 'status' => 404 ) );
		}
		if ( ! is_super_admin() && self::sites( $user->ID ) !== array( get_current_blog_id() ) ) {
			return new \WP_Error( 'leitvia_agent_other_sites', __( 'This agent also works on other sites of the network. Only a network administrator may change its access.', 'leitvia-studio' ), array( 'status' => 403 ) );
		}
		return $user;
	}

	/**
	 * IDs of the sites the user belongs to; on a single site this site.
	 *
	 * @param int $user_id User.
	 * @return list<int>
	 */
	private static function sites( int $user_id ): array {
		return is_multisite() ? array_map( 'intval', array_keys( get_blogs_of_user( $user_id, true ) ) ) : array( get_current_blog_id() );
	}

	/**
	 * Bound application passwords of an agent: UUID => site ID, only those of this site unless $here is false.
	 *
	 * @param int  $user_id User.
	 * @param bool $here    Only the passwords bound to the current site.
	 * @return array<string, int>
	 */
	private static function bound( int $user_id, bool $here = true ): array {
		$meta  = get_user_meta( $user_id, self::SITES_META, true );
		$bound = is_array( $meta ) ? array_map( 'intval', $meta ) : array();
		return $here ? array_filter( $bound, static fn( int $site ): bool => get_current_blog_id() === $site ) : $bound;
	}

	/**
	 * Application passwords of a user that work on this site: all but those bound to another site of the network.
	 *
	 * @param int $user_id User.
	 * @return list<array<string, mixed>>
	 */
	private static function passwords( int $user_id ): array {
		$elsewhere = array_diff_key( self::bound( $user_id, false ), self::bound( $user_id ) );
		return array_values( array_filter( \WP_Application_Passwords::get_user_application_passwords( $user_id ), static fn( array $item ): bool => ! isset( $elsewhere[ $item['uuid'] ] ) ) );
	}

	/**
	 * Action wp_authenticate_application_password_errors: refuses an application password bound to another site of
	 * the network, so a site administrator cannot act through an agent on a site they do not administer.
	 *
	 * @param \WP_Error            $error Errors so far.
	 * @param \WP_User             $user  User of the password.
	 * @param array<string, mixed> $item  The application password.
	 */
	public static function other_site( \WP_Error $error, \WP_User $user, array $item ): void {
		$site = self::bound( $user->ID, false )[ (string) ( $item['uuid'] ?? '' ) ] ?? 0;
		if ( $site && get_current_blog_id() !== $site ) {
			$error->add( 'leitvia_other_site', __( 'This application password belongs to another site of the network.', 'leitvia-studio' ), array( 'status' => 401 ) );
		}
	}

	/**
	 * Action wp_delete_application_password: forgets the site of a deleted password.
	 *
	 * @param int                  $user_id User.
	 * @param array<string, mixed> $item    The deleted application password.
	 */
	public static function forget_password( int $user_id, array $item ): void {
		$bound = self::bound( $user_id, false );
		if ( isset( $bound[ $item['uuid'] ] ) ) {
			unset( $bound[ $item['uuid'] ] );
			$bound ? update_user_meta( $user_id, self::SITES_META, $bound ) : delete_user_meta( $user_id, self::SITES_META );
		}
	}

	/**
	 * Deletes the application passwords bound to a site that is being deleted.
	 *
	 * @param int $site_id Site.
	 */
	public static function forget_site( int $site_id ): void {
		foreach ( get_users(
			array(
				'blog_id'  => 0,
				'meta_key' => self::SITES_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only on deleting a site.
				'fields'   => 'ids',
			)
		) as $user_id ) {
			foreach ( array_keys( self::bound( (int) $user_id, false ), $site_id, true ) as $uuid ) {
				\WP_Application_Passwords::delete_application_password( (int) $user_id, (string) $uuid );
			}
		}
	}

	/**
	 * Error when application passwords are off: they need HTTPS outside local environments.
	 */
	private static function no_passwords(): \WP_Error {
		return new \WP_Error( 'leitvia_no_app_passwords', __( 'Application passwords are not available on this site. They need HTTPS, and no plugin may turn them off.', 'leitvia-studio' ), array( 'status' => 501 ) );
	}

	/**
	 * Audit entry of a change on this screen, never with a password.
	 *
	 * @param string               $action  Action, for example access/create.
	 * @param int                  $user_id Agent concerned, 0 for none.
	 * @param array<string, mixed> $details Details.
	 */
	private static function log( string $action, int $user_id, array $details = array() ): void {
		Store::audit(
			array(
				'ability'     => $action,
				'result'      => 'ok',
				'object_type' => $user_id ? 'user' : 'option',
				'object_id'   => $user_id,
				'details'     => $details,
			)
		);
	}
}
