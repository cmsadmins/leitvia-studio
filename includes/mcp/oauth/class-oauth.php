<?php
/**
 * OAuth 2.1 of the MCP server leitvia: settings, addresses and discovery documents.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp\OAuth;

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Mcp;
use LeitviaStudio\Gateway\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Studio is resource server and authorization server in one. The resource is the MCP route, the issuer the home address
 * of the site. Clients find both through Protected Resource Metadata (RFC 9728) and Authorization Server Metadata (RFC
 * 8414), name themselves with a Client ID Metadata Document or, as deprecated fallback, Dynamic Client Registration (RFC
 * 7591), and get a code with PKCE S256 once a person agreed on the page "Connect agent" (Consent). A token acts as an
 * agent user; with leitvia:approve as the person who agreed, for that person only.
 */
final class OAuth {

	public const READ = 'leitvia:read';

	public const WRITE = 'leitvia:write';

	public const APPROVE = 'leitvia:approve';

	/**
	 * Every scope, as the authorization server metadata lists them.
	 */
	public const SCOPES = array( self::READ, self::WRITE, self::APPROVE );

	/**
	 * Scopes for basic work, scopes_supported of the resource and the scope of the challenge; approve comes by step-up.
	 */
	public const BASIC_SCOPES = array( self::READ, self::WRITE );

	private const RESOURCE_DOCUMENT = '/.well-known/oauth-protected-resource';

	private const SERVER_DOCUMENT = '/.well-known/oauth-authorization-server';

	private const OPENID_DOCUMENT = '/.well-known/openid-configuration';

	/**
	 * The nginx directives that let the well-known paths of OAuth past a rule such as location ~ /\. (template of
	 * ISPConfig), which answers 403 to every path with a segment that starts with a dot before WordPress runs.
	 */
	public const DIRECTIVES = "location ^~ /.well-known/oauth-protected-resource { try_files \$uri /index.php?\$args; }\nlocation ^~ /.well-known/oauth-authorization-server { try_files \$uri /index.php?\$args; }";

	/**
	 * Transient of the result of discovery(), kept 12 hours.
	 */
	public const DISCOVERY_CACHE = 'leitvia_studio_oauth_discovery';

	/**
	 * Troubleshooting of the documentation for a blocked discovery. The Agents screen links it from the REST answer,
	 * because the scripts of the plugin hold no external addresses.
	 */
	public const HELP = 'https://leitvia.com/docs/agents/connect/#troubleshooting';

	/**
	 * Hooks of OAuth. Runs from Server::boot().
	 */
	public static function boot(): void {
		// After cookies and application passwords (20): a user they found stays, a bearer token counts on the MCP route only.
		add_filter( 'determine_current_user', array( Bearer::class, 'determine' ), 30 );
		// A second gate: a token found anywhere but the MCP route signs out again.
		add_filter( 'rest_pre_dispatch', array( Bearer::class, 'gate' ), 0, 3 );
		foreach ( array( 'admin_init', 'pre_comment_on_post', 'template_redirect' ) as $hook ) {
			add_action( $hook, array( Bearer::class, 'outside' ), 0 );
		}
		add_filter( 'do_parse_request', array( self::class, 'well_known' ), 1 );
		add_action( 'rest_api_init', array( Routes::class, 'register' ) );
		add_action( 'admin_menu', array( Consent::class, 'register_page' ) );
		add_action( 'admin_post_' . Consent::ACTION, array( Consent::class, 'handle' ) );
		add_action( 'http_api_curl', array( Clients::class, 'pin' ), 10, 3 );
		add_action( Store::CRON, array( Tokens::class, 'prune' ) );
		// A deleted user keeps no open connection: the Agents screen would list it without a user.
		add_action(
			'deleted_user',
			static function ( int $user_id ): void {
				Tokens::revoke_user( $user_id );
			}
		);
		add_action( 'admin_init', array( self::class, 'privacy_policy' ) );
	}

	/**
	 * Whether OAuth can run on this site: with an https home address or in a local environment, as application
	 * passwords.
	 */
	public static function available(): bool {
		$available = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) || 'local' === wp_get_environment_type();
		/**
		 * Filters whether OAuth of the MCP server can run on this site, for example behind a proxy that ends TLS.
		 *
		 * @param bool $available Whether the home address uses https or the environment is local.
		 */
		return (bool) apply_filters( 'leitvia_studio_oauth_available', $available );
	}

	/**
	 * Whether OAuth runs: available and switched on (setting oauth of the gateway).
	 */
	public static function enabled(): bool {
		return self::available() && ! empty( Gateway::settings()['oauth'] );
	}

	/**
	 * Whether clients without a client document may register (setting oauth_dcr).
	 */
	public static function dcr_enabled(): bool {
		return self::enabled() && ! empty( Gateway::settings()['oauth_dcr'] );
	}

	/**
	 * Issuer identifier: the home address without trailing slash.
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * Canonical address of the MCP server, the audience of every token (RFC 8707).
	 */
	public static function resource(): string {
		return untrailingslashit( rest_url( Mcp::ROUTE_NAMESPACE . '/' . Mcp::ROUTE ) );
	}

	/**
	 * Whether an address names the MCP server of this site. Scheme and host may come in upper case, a trailing slash
	 * does not count, an address with fragment never matches.
	 *
	 * @param string $uri Address, for example the parameter resource.
	 */
	public static function same_resource( string $uri ): bool {
		return '' !== $uri && ! str_contains( $uri, '#' ) && self::canonical( $uri ) === self::canonical( self::resource() );
	}

	/**
	 * An address with scheme and host in lower case and without trailing slash.
	 *
	 * @param string $uri Address.
	 */
	private static function canonical( string $uri ): string {
		return untrailingslashit( (string) preg_replace_callback( '#^[a-z][a-z0-9+.-]*://[^/?]+#i', static fn( array $part ): string => strtolower( $part[0] ), $uri ) );
	}

	/**
	 * Address of an OAuth route of Studio.
	 *
	 * @param string $name protected-resource, authorization-server, token, revoke or register.
	 */
	public static function endpoint( string $name ): string {
		return rest_url( Mcp::ROUTE_NAMESPACE . '/oauth/' . $name );
	}

	/**
	 * Address of the Protected Resource Metadata that WWW-Authenticate names. A REST route, so it answers with and
	 * without pretty permalinks and below a subdirectory.
	 */
	public static function metadata_url(): string {
		return self::endpoint( 'protected-resource' );
	}

	/**
	 * Protected Resource Metadata (RFC 9728).
	 *
	 * @return array<string, mixed>
	 */
	public static function protected_resource(): array {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => self::BASIC_SCOPES,
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => leitvia_studio_brand_short(),
		);
	}

	/**
	 * Authorization Server Metadata (RFC 8414) with what MCP asks for: PKCE S256, CIMD and iss (RFC 9207).
	 *
	 * @return array<string, mixed>
	 */
	public static function authorization_server(): array {
		$document = array(
			'issuer'                                     => self::issuer(),
			'authorization_endpoint'                     => admin_url( 'admin.php?page=' . Consent::SLUG ),
			'token_endpoint'                             => self::endpoint( 'token' ),
			'revocation_endpoint'                        => self::endpoint( 'revoke' ),
			'scopes_supported'                           => self::SCOPES,
			'response_types_supported'                   => array( 'code' ),
			'response_modes_supported'                   => array( 'query' ),
			'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported'      => array( 'none' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'code_challenge_methods_supported'           => array( 'S256' ),
			'client_id_metadata_document_supported'      => true,
			'authorization_response_iss_parameter_supported' => true,
		);
		if ( self::dcr_enabled() ) {
			$document['registration_endpoint'] = self::endpoint( 'register' );
		}
		return $document;
	}

	/**
	 * The discovery document of a well-known path, null for any other path. The resource answers with path insertion
	 * and, for a site at the root of its host, at the root; the authorization server with path insertion and, for an
	 * issuer with path, with the OpenID path appended, the one path below a subdirectory that reaches WordPress.
	 *
	 * @param string $path Path of the request.
	 * @return array<string, mixed>|null
	 */
	public static function document( string $path ): ?array {
		if ( ! self::enabled() ) {
			return null;
		}
		$path      = untrailingslashit( $path );
		$resource  = untrailingslashit( (string) wp_parse_url( self::resource(), PHP_URL_PATH ) );
		$issuer    = untrailingslashit( (string) wp_parse_url( self::issuer(), PHP_URL_PATH ) );
		$resources = '' === $issuer ? array( self::RESOURCE_DOCUMENT . $resource, self::RESOURCE_DOCUMENT ) : array( self::RESOURCE_DOCUMENT . $resource );
		if ( in_array( $path, $resources, true ) ) {
			return self::protected_resource();
		}
		return in_array( $path, self::server_paths(), true ) ? self::authorization_server() : null;
	}

	/**
	 * Paths of the authorization server metadata, in the order clients try them: for an issuer without path the OAuth
	 * path, for one with path the path insertion and the OpenID path appended.
	 *
	 * @return list<string>
	 */
	private static function server_paths(): array {
		$issuer = untrailingslashit( (string) wp_parse_url( self::issuer(), PHP_URL_PATH ) );
		return '' === $issuer ? array( self::SERVER_DOCUMENT ) : array( self::SERVER_DOCUMENT . $issuer, $issuer . self::OPENID_DOCUMENT );
	}

	/**
	 * Whether clients reach the authorization server metadata of this site: the site asks itself at the addresses a
	 * client tries, 3 seconds each, and keeps the result 12 hours. Only for the Agents screen, never in the front end
	 * or by cron; null without OAuth. ok needs 200 with this issuer; status is that of the last address asked.
	 *
	 * @return array{ok: bool, status: int, directives: string, help: string}|null
	 */
	public static function discovery(): ?array {
		if ( ! self::enabled() ) {
			return null;
		}
		$result = get_transient( self::DISCOVERY_CACHE );
		if ( ! is_array( $result ) ) {
			$origin = (string) preg_replace( '#^([a-z][a-z0-9+.-]*://[^/?]+).*$#i', '$1', self::issuer() );
			$result = array(
				'ok'     => false,
				'status' => 0,
			);
			foreach ( self::server_paths() as $path ) {
				$response = wp_remote_get(
					$origin . $path,
					array(
						'timeout'   => 3,
						// As the loopback request of core's Site Health: a request of the site to itself.
						'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook of core.
					)
				);
				$failed   = is_wp_error( $response );
				$body     = $failed ? null : json_decode( (string) $response['body'], true );
				$status   = $failed ? 0 : (int) $response['response']['code'];
				$result   = array(
					'ok'     => 200 === $status && is_array( $body ) && self::issuer() === ( $body['issuer'] ?? null ),
					'status' => $status,
				);
				if ( $result['ok'] ) {
					break;
				}
			}
			set_transient( self::DISCOVERY_CACHE, $result, 12 * HOUR_IN_SECONDS );
		}
		return array(
			'ok'         => (bool) ( $result['ok'] ?? false ),
			'status'     => (int) ( $result['status'] ?? 0 ),
			'directives' => self::DIRECTIVES,
			'help'       => self::HELP,
		);
	}

	/**
	 * Filter do_parse_request: answers a well-known path of OAuth before WordPress looks for a post. Public metadata,
	 * so any origin may read it (CORS *); the MCP route itself keeps its origin check.
	 *
	 * @param bool $parse Whether WordPress parses the request.
	 * @return bool
	 */
	public static function well_known( $parse ) {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		if ( ! str_contains( $path, '/.well-known/' ) ) {
			return $parse;
		}
		$document = self::document( $path );
		if ( null === $document ) {
			return $parse;
		}
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Headers: MCP-Protocol-Version' );
		header( 'Cache-Control: max-age=300' );
		if ( 'OPTIONS' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			status_header( 204 );
			exit;
		}
		wp_send_json( $document, 200 );
	}

	/**
	 * Suggests a paragraph for the privacy policy guide. Runs on admin_init.
	 */
	public static function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Administrators can connect AI clients such as claude.ai or ChatGPT to this site over OAuth. When an administrator agrees to a connection, the site fetches the public description of the client (Client ID Metadata Document) from the address the client names, from the server and at most once a day; the request carries no data of visitors.', 'leitvia-studio' ) . '</p>';
		$text .= '<p>' . esc_html__( 'For each connection the site stores the name and address of the client, the user it works as, its rights, when it was created and last used and the shortened IP address of its last call, until an administrator closes it or 30 days after its last renewal.', 'leitvia-studio' ) . '</p>';
		wp_add_privacy_policy_content(
			/* translators: %s: product name */
			sprintf( __( '%s: agent connections', 'leitvia-studio' ), leitvia_studio_brand()['name'] ),
			wp_kses_post( $text )
		);
	}
}
