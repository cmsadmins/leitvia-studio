<?php
/**
 * OAuth 2.1 of the MCP server: discovery, tokens, consent, bearer and the negative cases of the spec.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Store;
use LeitviaStudio\Mcp\OAuth\Bearer;
use LeitviaStudio\Mcp\OAuth\Clients;
use LeitviaStudio\Mcp\OAuth\Consent;
use LeitviaStudio\Mcp\OAuth\OAuth;
use LeitviaStudio\Mcp\OAuth\Tokens;

/**
 * Every case of the row "OAuth negativ" of the spec, and the way a token works on the MCP route.
 */
class Test_Mcp_OAuth extends WP_UnitTestCase {

	/**
	 * Verifier and challenge of RFC 7636, appendix B.
	 */
	private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

	private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	private const REDIRECT = 'https://client.example/cb';

	/**
	 * Administrator of the site.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Agent user.
	 *
	 * @var int
	 */
	private int $agent;

	/**
	 * The client_id of the registered test client, empty until client() runs.
	 *
	 * @var string
	 */
	private string $client = '';

	/**
	 * $_SERVER before the test, restored after it: REMOTE_ADDR and REQUEST_URI belong to the next test class too.
	 *
	 * @var array<string, mixed>
	 */
	private array $server = array();

	/**
	 * Abilities handed to core, OAuth available, an administrator signed in.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
		wp_get_abilities();
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
		add_filter( 'leitvia_studio_oauth_available', '__return_true' );
		Bearer::reset();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Request globals and Studio state of the next test.
	 */
	public function tear_down(): void {
		Bearer::reset();
		$_SERVER  = $this->server;
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * The client_id of a client registered on this site, registered on first use.
	 */
	private function client(): string {
		if ( '' === $this->client ) {
			$this->client = Clients::register(
				array(
					'client_name'   => 'Test Client',
					'redirect_uris' => array( self::REDIRECT ),
				)
			)['client_id'];
		}
		return $this->client;
	}

	/**
	 * Query of an authorization request; a change to null leaves the parameter out.
	 *
	 * @param array<string, string|null> $changes Parameters to change.
	 * @return array<string, string>
	 */
	private function authorize( array $changes = array() ): array {
		$params = array_merge(
			array(
				'response_type'         => 'code',
				'client_id'             => $this->client(),
				'redirect_uri'          => self::REDIRECT,
				'code_challenge'        => self::CHALLENGE,
				'code_challenge_method' => 'S256',
				'state'                 => 'xyz',
				'scope'                 => 'leitvia:read leitvia:write',
				'resource'              => OAuth::resource(),
			),
			$changes
		);
		return array_filter( $params, static fn( $value ): bool => null !== $value );
	}

	/**
	 * Decision of the consent form for the agent with write, as the signed-in person.
	 *
	 * @param array<string, string|null> $changes Changes of the authorization request.
	 * @param array<string, mixed>       $form    Changes of the form fields.
	 * @return string|WP_Error
	 */
	private function consent( array $changes = array(), array $form = array() ) {
		return Consent::decide(
			array_merge(
				$this->authorize( $changes ),
				array(
					'decision' => 'allow',
					'subject'  => (string) $this->agent,
					'scopes'   => array( OAuth::WRITE ),
				),
				$form
			)
		);
	}

	/**
	 * Query parameters of an address.
	 *
	 * @param string $url Address.
	 * @return array<string, string>
	 */
	private static function query( string $url ): array {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return $args;
	}

	/**
	 * A code issued directly, for the agent with read and write unless changed.
	 *
	 * @param array<string, mixed> $changes Fields of the grant to change.
	 */
	private function grant( array $changes = array() ): string {
		return Tokens::issue_code(
			array_merge(
				array(
					'user_id'      => $this->agent,
					'client_id'    => $this->client(),
					'client_name'  => 'Test Client',
					'scope'        => 'leitvia:read leitvia:write',
					'resource'     => OAuth::resource(),
					'redirect_uri' => self::REDIRECT,
					'challenge'    => self::CHALLENGE,
				),
				$changes
			)
		);
	}

	/**
	 * Tokens::exchange() with the values of the grant unless changed.
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $changes client_id, redirect_uri, verifier or resource to change.
	 * @return array<string, mixed>|WP_Error
	 */
	private function swap( string $code, array $changes = array() ) {
		$p = array_merge(
			array(
				'client_id'    => $this->client(),
				'redirect_uri' => self::REDIRECT,
				'verifier'     => self::VERIFIER,
				'resource'     => OAuth::resource(),
			),
			$changes
		);
		return Tokens::exchange( $code, $p['client_id'], $p['redirect_uri'], $p['verifier'], $p['resource'] );
	}

	/**
	 * POST to the token endpoint.
	 *
	 * @param array<string, string> $params Form fields.
	 */
	private static function token( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/oauth/token' );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Code exchange at the token endpoint.
	 *
	 * @param string                $code    Code.
	 * @param array<string, string> $changes Fields to change.
	 */
	private function exchange( string $code, array $changes = array() ): WP_REST_Response {
		return self::token(
			array_merge(
				array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $this->client(),
					'redirect_uri'  => self::REDIRECT,
					'code_verifier' => self::VERIFIER,
					'resource'      => OAuth::resource(),
				),
				$changes
			)
		);
	}

	/**
	 * Refresh at the token endpoint.
	 *
	 * @param string                $token   Refresh token.
	 * @param array<string, string> $changes Fields to change.
	 */
	private function refresh( string $token, array $changes = array() ): WP_REST_Response {
		return self::token(
			array_merge(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $token,
					'client_id'     => $this->client(),
				),
				$changes
			)
		);
	}

	/**
	 * Signs in through Bearer::determine() as a request to $path with this token would.
	 *
	 * @param string $token  Access token.
	 * @param string $path   Request path.
	 * @param string $script Script below ABSPATH that runs the request.
	 * @return int User the token signed in, 0 for none.
	 */
	private static function bearer( string $token, string $path = '/wp-json/leitvia-studio/v1/mcp', string $script = 'index.php' ): int {
		$_SERVER['SCRIPT_FILENAME']    = ABSPATH . $script;
		$_SERVER['REQUEST_URI']        = $path;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		Bearer::reset();
		$user = (int) Bearer::determine( 0 );
		wp_set_current_user( $user );
		return $user;
	}

	/**
	 * A 2026-07-28 request to the MCP server.
	 *
	 * @param string               $method Method.
	 * @param array<string, mixed> $params Params without _meta.
	 */
	private static function mcp( string $method, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/mcp' );
		$headers = array(
			'Content-Type'         => 'application/json',
			'Accept'               => 'application/json, text/event-stream',
			'MCP-Protocol-Version' => '2026-07-28',
			'Mcp-Method'           => $method,
			'Mcp-Name'             => (string) ( $params['name'] ?? '' ),
		);
		foreach ( array_filter( $headers, 'strlen' ) as $header => $value ) {
			$request->set_header( $header, $value );
		}
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 7,
					'method'  => $method,
					'params'  => $params + array(
						'_meta' => array(
							'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
							'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
							'io.modelcontextprotocol/clientInfo' => array(
								'name'    => 'test',
								'version' => '1.0.0',
							),
						),
					),
				)
			)
		);
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Rows of the token table, of one kind or all.
	 *
	 * @param string $kind code, access, refresh or empty for all.
	 */
	private static function rows( string $kind = '' ): int {
		global $wpdb;
		return '' === $kind
			? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tokens::table() ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
			: (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE kind = %s', Tokens::table(), $kind ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
	}

	/**
	 * Audit entries of an action.
	 *
	 * @param string $action Action, for example oauth/grant.
	 */
	private static function audited( string $action ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ability = %s', Store::table( 'audit' ), $action ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
	}

	/**
	 * Lets every row of a kind expire.
	 *
	 * @param string $kind code, access or refresh.
	 */
	private static function expire( string $kind ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %s WHERE kind = %s', Tokens::table(), gmdate( 'Y-m-d H:i:s', time() - 1 ), $kind ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
	}

	/**
	 * Settings of the gateway with these changes.
	 *
	 * @param array<string, mixed> $changes Settings to change.
	 */
	private static function settings( array $changes ): void {
		update_option( Gateway::OPTION, $changes + Gateway::DEFAULTS );
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * RFC 9728 and RFC 8414: resource, issuer, PKCE S256, CIMD and iss; both well-known paths of the resource and the
	 * OAuth path of an issuer without path; nothing without OAuth.
	 */
	public function test_discovery_documents_name_resource_issuer_and_pkce(): void {
		$resource = OAuth::protected_resource();
		$this->assertSame( OAuth::resource(), $resource['resource'] );
		$this->assertSame( array( OAuth::issuer() ), $resource['authorization_servers'] );
		$this->assertSame( array( 'leitvia:read', 'leitvia:write' ), $resource['scopes_supported'] );
		$this->assertSame( array( 'header' ), $resource['bearer_methods_supported'] );
		$server = OAuth::authorization_server();
		$this->assertSame( OAuth::issuer(), $server['issuer'] );
		$this->assertSame( array( 'S256' ), $server['code_challenge_methods_supported'] );
		$this->assertTrue( $server['client_id_metadata_document_supported'] );
		$this->assertTrue( $server['authorization_response_iss_parameter_supported'] );
		$this->assertSame( array( 'none' ), $server['token_endpoint_auth_methods_supported'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $server['grant_types_supported'] );
		$this->assertSame( OAuth::endpoint( 'register' ), $server['registration_endpoint'] );
		$this->assertStringContainsString( 'page=leitvia-studio-connect', $server['authorization_endpoint'] );
		$path = untrailingslashit( (string) wp_parse_url( OAuth::resource(), PHP_URL_PATH ) );
		$this->assertSame( $resource, OAuth::document( '/.well-known/oauth-protected-resource' . $path ) );
		$this->assertSame( $resource, OAuth::document( '/.well-known/oauth-protected-resource' ) );
		$this->assertSame( $server, OAuth::document( '/.well-known/oauth-authorization-server' ) );
		$this->assertSame( $server, OAuth::document( '/.well-known/oauth-authorization-server/' ) );
		$this->assertNull( OAuth::document( '/.well-known/openid-configuration' ), 'an issuer without path needs no OpenID path' );
		$this->assertNull( OAuth::document( '/.well-known/oauth-authorization-server/other' ) );
		$this->assertNull( OAuth::document( '/.well-known/acme-challenge/x' ) );
		self::settings( array( 'oauth_dcr' => false ) );
		$this->assertArrayNotHasKey( 'registration_endpoint', OAuth::authorization_server() );
		self::settings( array( 'oauth' => false ) );
		$this->assertFalse( OAuth::enabled() );
		$this->assertNull( OAuth::document( '/.well-known/oauth-authorization-server' ) );
		remove_all_filters( 'leitvia_studio_oauth_available' );
		self::settings( array() );
		$this->assertSame( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) || 'local' === wp_get_environment_type(), OAuth::available(), 'https or local, as application passwords' );
	}

	/**
	 * A site in a subdirectory: the issuer has a path, the OAuth path with path insertion and the OpenID path appended
	 * to the issuer answer; the root of the host belongs to someone else.
	 */
	public function test_issuer_with_path_answers_insertion_and_appending(): void {
		add_filter( 'home_url', static fn( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://example.org/sub', $url ) );
		$this->assertSame( 'https://example.org/sub', OAuth::issuer() );
		$this->assertNotNull( OAuth::document( '/.well-known/oauth-authorization-server/sub' ) );
		$this->assertSame( 'https://example.org/sub', OAuth::document( '/sub/.well-known/openid-configuration' )['issuer'] );
		$this->assertNull( OAuth::document( '/.well-known/oauth-authorization-server' ) );
		$this->assertNull( OAuth::document( '/.well-known/oauth-protected-resource' ) );
	}

	/**
	 * RFC 8707: the resource in any case of scheme and host, without trailing slash; never with fragment or another
	 * path.
	 */
	public function test_same_resource(): void {
		$resource = OAuth::resource();
		$this->assertTrue( OAuth::same_resource( $resource ) );
		$this->assertTrue( OAuth::same_resource( $resource . '/' ) );
		$this->assertTrue( OAuth::same_resource( strtoupper( substr( $resource, 0, 18 ) ) . substr( $resource, 18 ) ) );
		$this->assertFalse( OAuth::same_resource( $resource . '#x' ) );
		$this->assertFalse( OAuth::same_resource( $resource . '-other' ) );
		$this->assertFalse( OAuth::same_resource( '' ) );
		$this->assertFalse( OAuth::same_resource( 'https://other.example/wp-json/leitvia-studio/v1/mcp' ) );
	}

	/**
	 * A code works once; its second use closes every token of the grant and is audited.
	 */
	public function test_code_is_single_use_and_a_second_use_closes_the_grant(): void {
		$code = $this->grant();
		$this->assertMatchesRegularExpression( '/^lvac_[0-9a-f]{64}$/', $code );
		$pair = $this->swap( $code );
		$this->assertIsArray( $pair );
		$this->assertSame( 'Bearer', $pair['token_type'] );
		$this->assertSame( Tokens::ACCESS_TTL, $pair['expires_in'] );
		$this->assertSame( 'leitvia:read leitvia:write', $pair['scope'] );
		$this->assertNotNull( Tokens::access( $pair['access_token'] ) );
		$again = $this->swap( $code );
		$this->assertSame( 'invalid_grant', $again->get_error_code() );
		$this->assertNull( Tokens::access( $pair['access_token'] ), 'a code used twice closes every token of its grant' );
		$this->assertWPError( Tokens::refresh( $pair['refresh_token'], $this->client(), '', '' ) );
		$this->assertSame( 1, self::audited( 'oauth/code-replay' ) );
	}

	/**
	 * Only the SHA-256 of a token is stored.
	 */
	public function test_tokens_are_stored_as_hash_only(): void {
		global $wpdb;
		$pair = $this->swap( $this->grant() );
		$all  = (string) wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', Tokens::table() ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
		$this->assertStringNotContainsString( $pair['access_token'], $all );
		$this->assertStringNotContainsString( $pair['refresh_token'], $all );
		$this->assertStringContainsString( hash( 'sha256', $pair['access_token'] ), $all );
		$this->assertMatchesRegularExpression( '/^lvat_[0-9a-f]{64}$/', $pair['access_token'] );
		$this->assertMatchesRegularExpression( '/^lvrt_[0-9a-f]{64}$/', $pair['refresh_token'] );
	}

	/**
	 * A code is valid for 60 seconds.
	 */
	public function test_code_expires_after_60_seconds(): void {
		$this->assertSame( 60, Tokens::CODE_TTL );
		$code = $this->grant();
		self::expire( 'code' );
		$this->assertSame( 'invalid_grant', $this->swap( $code )->get_error_code() );
		$this->assertSame( 0, self::rows( 'access' ) );
	}

	/**
	 * PKCE verifier, client, redirect URI and resource of the exchange must match the grant.
	 */
	public function test_code_needs_its_verifier_client_redirect_and_resource(): void {
		$this->assertSame( 'invalid_grant', $this->swap( $this->grant(), array( 'verifier' => str_repeat( 'a', 43 ) ) )->get_error_code(), 'wrong verifier' );
		$this->assertSame( 'invalid_grant', $this->swap( $this->grant(), array( 'verifier' => '' ) )->get_error_code(), 'no verifier' );
		$this->assertSame( 'invalid_grant', $this->swap( $this->grant(), array( 'verifier' => self::CHALLENGE ) )->get_error_code(), 'plain: the challenge as verifier' );
		$this->assertSame( 'invalid_grant', $this->swap( $this->grant(), array( 'client_id' => 'lv-other' ) )->get_error_code() );
		$this->assertSame( 'invalid_grant', $this->swap( $this->grant(), array( 'redirect_uri' => 'https://client.example/other' ) )->get_error_code() );
		$this->assertSame( 'invalid_grant', $this->swap( $this->grant(), array( 'redirect_uri' => '' ) )->get_error_code(), 'redirect_uri is required, the grant has one' );
		$this->assertSame( 'invalid_target', $this->swap( $this->grant(), array( 'resource' => 'https://other.example/wp-json/leitvia-studio/v1/mcp' ) )->get_error_code() );
		$this->assertSame( 0, self::rows( 'access' ), 'no token for any of them' );
	}

	/**
	 * Refresh rotates; a refresh token used again closes the whole chain, the newer tokens included.
	 */
	public function test_refresh_rotates_and_a_replay_closes_the_chain(): void {
		$first  = $this->swap( $this->grant() );
		$second = Tokens::refresh( $first['refresh_token'], $this->client(), '', OAuth::resource() );
		$this->assertIsArray( $second );
		$this->assertNotSame( $first['refresh_token'], $second['refresh_token'] );
		$this->assertNotNull( Tokens::access( $second['access_token'] ) );
		$replay = Tokens::refresh( $first['refresh_token'], $this->client(), '', '' );
		$this->assertSame( 'invalid_grant', $replay->get_error_code() );
		$this->assertNull( Tokens::access( $second['access_token'] ) );
		$this->assertNull( Tokens::access( $first['access_token'] ) );
		$this->assertSame( 'invalid_grant', Tokens::refresh( $second['refresh_token'], $this->client(), '', '' )->get_error_code(), 'the whole chain is closed' );
		$this->assertSame( 1, self::audited( 'oauth/refresh-replay' ) );
	}

	/**
	 * A refresh keeps client and resource, may narrow the scope and never widen it; failed tries do not use the token up.
	 */
	public function test_refresh_keeps_client_resource_and_scope(): void {
		$pair = $this->swap( $this->grant() );
		$this->assertSame( 'invalid_grant', Tokens::refresh( $pair['refresh_token'], 'lv-other', '', '' )->get_error_code() );
		$this->assertSame( 'invalid_target', Tokens::refresh( $pair['refresh_token'], $this->client(), '', 'https://other.example/mcp' )->get_error_code() );
		$this->assertSame( 'invalid_scope', Tokens::refresh( $pair['refresh_token'], $this->client(), 'leitvia:read leitvia:approve', '' )->get_error_code() );
		$narrow = Tokens::refresh( $pair['refresh_token'], $this->client(), 'leitvia:read', '' );
		$this->assertSame( 'leitvia:read', $narrow['scope'], 'the failed tries did not use the token up' );
		$this->assertSame( 'invalid_scope', Tokens::refresh( $narrow['refresh_token'], $this->client(), 'leitvia:write', '' )->get_error_code() );
		self::expire( 'refresh' );
		$this->assertSame( 'invalid_grant', Tokens::refresh( $narrow['refresh_token'], $this->client(), '', '' )->get_error_code() );
	}

	/**
	 * A token counts while its user keeps the role it was given for: the agent role, or for leitvia:approve
	 * manage_options without the agent role.
	 */
	public function test_tokens_follow_the_role_of_their_user(): void {
		$pair = $this->swap( $this->grant() );
		( new WP_User( $this->agent ) )->set_role( 'editor' );
		$this->assertNull( Tokens::access( $pair['access_token'] ), 'no agent any more' );
		$agent = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		$this->assertSame(
			'invalid_grant',
			$this->swap(
				$this->grant(
					array(
						'user_id' => $agent,
						'scope'   => 'leitvia:read leitvia:approve',
					)
				)
			)->get_error_code(),
			'approve never for an agent'
		);
		$person = $this->swap(
			$this->grant(
				array(
					'user_id' => $this->admin,
					'scope'   => 'leitvia:read leitvia:approve',
				)
			)
		);
		$this->assertNotNull( Tokens::access( $person['access_token'] ) );
		$this->assertSame( 'invalid_grant', $this->swap( $this->grant( array( 'user_id' => $this->admin ) ) )->get_error_code(), 'a person works as themself with approve only' );
		( new WP_User( $this->admin ) )->set_role( 'editor' );
		$this->assertNull( Tokens::access( $person['access_token'] ), 'no manage_options any more' );
	}

	/**
	 * Revocation needs the own client; prune removes rows a day after they expired.
	 */
	public function test_revoke_and_prune(): void {
		global $wpdb;
		$pair = $this->swap( $this->grant() );
		Tokens::revoke_token( $pair['refresh_token'], 'lv-other' );
		$this->assertNotNull( Tokens::access( $pair['access_token'] ), 'only the own client revokes' );
		Tokens::revoke_token( $pair['refresh_token'], $this->client() );
		$this->assertNull( Tokens::access( $pair['access_token'] ) );
		$this->assertSame( 1, self::audited( 'oauth/revoke' ) );
		$this->grant();
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %s', Tokens::table(), gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
		Tokens::prune();
		$this->assertSame( 0, self::rows() );
	}

	/**
	 * Deleting the user closes its connections, so the Agents screen lists none without a user.
	 */
	public function test_deleting_the_user_closes_its_connections(): void {
		$pair  = $this->swap( $this->grant() );
		$users = static fn(): array => array_column( array_column( Tokens::connections(), 'user' ), 'id' );
		$this->assertContains( $this->agent, $users() );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->agent );
		$this->assertNull( Tokens::access( $pair['access_token'] ) );
		$this->assertNotContains( $this->agent, $users() );
	}

	/**
	 * Token endpoint: JSON with no-store, errors as {error, error_description}, only the two grants.
	 */
	public function test_token_endpoint_answers_as_oauth(): void {
		$response = $this->exchange( $this->grant() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'Bearer', $response->get_data()['token_type'] );
		$this->assertSame( 200, $this->refresh( $response->get_data()['refresh_token'] )->get_status() );
		$error = $this->exchange( 'lvac_nope' );
		$this->assertSame( 400, $error->get_status() );
		$this->assertSame( array( 'error', 'error_description' ), array_keys( $error->get_data() ) );
		$this->assertSame( 'invalid_grant', $error->get_data()['error'] );
		$this->assertSame( 'no-store', $error->get_headers()['Cache-Control'] );
		foreach ( array( 'password', 'client_credentials', 'implicit', '' ) as $grant ) {
			$this->assertSame( 'unsupported_grant_type', self::token( array( 'grant_type' => $grant ) )->get_data()['error'], $grant );
		}
	}

	/**
	 * Revocation endpoint (RFC 7009): 200 for known and unknown tokens.
	 */
	public function test_revocation_endpoint(): void {
		$pair    = $this->exchange( $this->grant() )->get_data();
		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/oauth/revoke' );
		$request->set_body_params(
			array(
				'token'     => $pair['access_token'],
				'client_id' => $this->client(),
			)
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertNull( Tokens::access( $pair['access_token'] ) );
		$request->set_body_params(
			array(
				'token'     => 'unknown',
				'client_id' => $this->client(),
			)
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status(), 'unknown tokens too' );
	}

	/**
	 * Metadata over REST, registration with 201 and RFC 7591 errors; without DCR no registration, without OAuth no
	 * route.
	 */
	public function test_metadata_and_registration_routes(): void {
		$resource = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/leitvia-studio/v1/oauth/protected-resource' ) );
		$this->assertSame( OAuth::protected_resource(), $resource->get_data() );
		$server = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/leitvia-studio/v1/oauth/authorization-server' ) );
		$this->assertSame( OAuth::issuer(), $server->get_data()['issuer'] );
		$register = new WP_REST_Request( 'POST', '/leitvia-studio/v1/oauth/register' );
		$register->set_header( 'Content-Type', 'application/json' );
		$register->set_body(
			(string) wp_json_encode(
				array(
					'client_name'                => 'Inspector',
					'redirect_uris'              => array( 'http://localhost:6274/oauth/callback' ),
					'token_endpoint_auth_method' => 'none',
				)
			)
		);
		$created = rest_get_server()->dispatch( $register );
		$this->assertSame( 201, $created->get_status() );
		$this->assertStringStartsWith( 'lv-', $created->get_data()['client_id'] );
		$register->set_body(
			(string) wp_json_encode(
				array(
					'client_name'   => 'Bad',
					'redirect_uris' => array( 'http://evil.example/cb' ),
				)
			)
		);
		$this->assertSame( 'invalid_redirect_uri', rest_get_server()->dispatch( $register )->get_data()['error'] );
		self::settings( array( 'oauth_dcr' => false ) );
		$this->assertSame( 404, rest_get_server()->dispatch( $register )->get_status(), 'DCR switched off' );
		self::settings( array( 'oauth' => false ) );
		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/leitvia-studio/v1/oauth/protected-resource' ) )->get_status() );
		$this->assertSame( 404, self::token( array( 'grant_type' => 'refresh_token' ) )->get_status() );
	}

	/**
	 * Registration and token endpoint count per shortened IP address: beyond the limit 429 with Retry-After in the
	 * OAuth form, another address still passes, the filter sets or lifts the limit per route.
	 */
	public function test_anonymous_routes_are_throttled_per_address(): void {
		$routes = array();
		add_filter(
			'leitvia_studio_oauth_throttle',
			static function ( int $limit, string $route ) use ( &$routes ): int {
				$routes[ $route ] = $limit;
				return 2;
			},
			10,
			2
		);
		$register = new WP_REST_Request( 'POST', '/leitvia-studio/v1/oauth/register' );
		$register->set_header( 'Content-Type', 'application/json' );
		$register->set_body(
			(string) wp_json_encode(
				array(
					'client_name'   => 'Many',
					'redirect_uris' => array( 'http://localhost:6274/cb' ),
				)
			)
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->assertSame( 201, rest_get_server()->dispatch( $register )->get_status() );
		$this->assertSame( 201, rest_get_server()->dispatch( $register )->get_status() );
		$busy = rest_get_server()->dispatch( $register );
		$this->assertSame( 429, $busy->get_status() );
		$this->assertSame( array( 'error', 'error_description' ), array_keys( $busy->get_data() ) );
		$this->assertSame( 'temporarily_unavailable', $busy->get_data()['error'] );
		$this->assertSame( 'no-store', $busy->get_headers()['Cache-Control'] );
		$this->assertGreaterThan( 0, (int) $busy->get_headers()['Retry-After'] );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS, (int) $busy->get_headers()['Retry-After'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
		$this->assertSame( 429, rest_get_server()->dispatch( $register )->get_status(), 'the same /24 counts as one address' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 201, rest_get_server()->dispatch( $register )->get_status(), 'another address passes' );
		$this->assertSame( 400, self::token( array( 'grant_type' => 'password' ) )->get_status() );
		$this->assertSame( 400, self::token( array( 'grant_type' => 'password' ) )->get_status() );
		$this->assertSame( 429, self::token( array( 'grant_type' => 'password' ) )->get_status(), 'the token endpoint counts on its own' );
		$this->assertSame(
			array(
				'register' => 10,
				'token'    => 60,
			),
			$routes
		);
		remove_all_filters( 'leitvia_studio_oauth_throttle' );
		add_filter( 'leitvia_studio_oauth_throttle', '__return_zero' );
		$this->assertSame( 400, self::token( array( 'grant_type' => 'password' ) )->get_status(), '0 lifts the limit' );
	}

	/**
	 * Consent: code, state and iss back to the client; read only without the write box; Cancel sends access_denied.
	 */
	public function test_consent_issues_a_code_with_state_and_iss(): void {
		$url = $this->consent();
		$this->assertIsString( $url );
		$this->assertStringStartsWith( self::REDIRECT . '?', $url );
		$args = self::query( $url );
		$this->assertSame( 'xyz', $args['state'] );
		$this->assertSame( OAuth::issuer(), $args['iss'] );
		$this->assertStringStartsWith( 'lvac_', $args['code'] );
		$pair = $this->exchange( $args['code'] )->get_data();
		$this->assertSame( 'leitvia:read leitvia:write', $pair['scope'] );
		$this->assertSame( $this->agent, (int) Tokens::access( $pair['access_token'] )['user_id'] );
		$this->assertSame( 1, self::audited( 'oauth/grant' ) );
		$read = self::query( $this->consent( array(), array( 'scopes' => array() ) ) );
		$this->assertSame( 'leitvia:read', $this->exchange( $read['code'] )->get_data()['scope'], 'read only without the write box' );
		$denied = self::query( $this->consent( array(), array( 'decision' => 'deny' ) ) );
		$this->assertSame( 'access_denied', $denied['error'] );
		$this->assertSame( OAuth::issuer(), $denied['iss'] );
		$this->assertSame( 'xyz', $denied['state'] );
	}

	/**
	 * Without PKCE, with plain, without method, with a malformed challenge, with another response type: back to the
	 * client with the error, state and iss, no code.
	 */
	public function test_authorization_without_pkce_s256_is_refused(): void {
		foreach ( array(
			'no PKCE'         => array(
				'code_challenge'        => null,
				'code_challenge_method' => null,
			),
			'plain'           => array(
				'code_challenge_method' => 'plain',
				'code_challenge'        => self::VERIFIER,
			),
			'no method'       => array( 'code_challenge_method' => null ),
			'short challenge' => array( 'code_challenge' => 'abc' ),
		) as $case => $changes ) {
			$error = Consent::request( $this->authorize( $changes ) );
			$this->assertWPError( $error, $case );
			$args = self::query( Consent::redirect_of( $error ) );
			$this->assertSame( 'invalid_request', $args['error'], $case );
			$this->assertSame( 'xyz', $args['state'], $case );
			$this->assertSame( OAuth::issuer(), $args['iss'], $case );
			$this->assertWPError( $this->consent( $changes ), 'the form checks again: ' . $case );
		}
		$this->assertSame( 'unsupported_response_type', self::query( Consent::redirect_of( Consent::request( $this->authorize( array( 'response_type' => 'token' ) ) ) ) )['error'] );
		$this->assertSame( 0, self::rows( 'code' ) );
	}

	/**
	 * A redirect URI the client did not name, or an unknown client: an error on the page, never a redirect.
	 */
	public function test_wrong_redirect_uri_or_client_is_never_followed(): void {
		foreach ( array( 'https://client.example/other', 'https://evil.example/cb', 'https://client.example/cb/', '' ) as $redirect ) {
			$error = $this->consent( array( 'redirect_uri' => $redirect ) );
			$this->assertWPError( $error, $redirect );
			$this->assertSame( '', Consent::redirect_of( $error ), 'no redirect to an unchecked address: ' . $redirect );
		}
		$unknown = $this->consent( array( 'client_id' => 'lv-unknown' ) );
		$this->assertWPError( $unknown );
		$this->assertSame( '', Consent::redirect_of( $unknown ) );
		$this->assertSame( 0, self::rows( 'code' ) );
	}

	/**
	 * A resource other than this server goes back as invalid_target; scheme in upper case or a missing resource mean
	 * this server.
	 */
	public function test_wrong_resource_is_refused(): void {
		$error = Consent::request( $this->authorize( array( 'resource' => 'https://other.example/wp-json/leitvia-studio/v1/mcp' ) ) );
		$this->assertSame( 'invalid_target', self::query( Consent::redirect_of( $error ) )['error'] );
		$this->assertIsArray( Consent::request( $this->authorize( array( 'resource' => strtoupper( substr( OAuth::resource(), 0, 4 ) ) . substr( OAuth::resource(), 4 ) ) ) ) );
		$this->assertIsArray( Consent::request( $this->authorize( array( 'resource' => null ) ) ), 'missing resource means this server' );
		$this->assertSame( 'invalid_target', $this->exchange( $this->grant(), array( 'resource' => 'https://other.example/mcp' ) )->get_data()['error'] );
	}

	/**
	 * Scope leitvia:approve: never for an agent, only for the person themself, and then read and approve, never write.
	 * A person is no agent to work as.
	 */
	public function test_approve_only_for_a_person_for_themselves(): void {
		$error = $this->consent( array( 'scope' => 'leitvia:read leitvia:approve' ), array( 'scopes' => array( OAuth::APPROVE ) ) );
		$this->assertWPError( $error );
		$this->assertSame( 'invalid_scope', $error->get_error_code() );
		$this->assertSame( 0, self::rows( 'code' ) );
		$self = self::query(
			$this->consent(
				array( 'scope' => 'leitvia:read leitvia:approve' ),
				array(
					'subject' => 'self',
					'scopes'  => array( OAuth::WRITE ),
				)
			)
		);
		$pair = $this->exchange( $self['code'] )->get_data();
		$this->assertSame( 'leitvia:read leitvia:approve', $pair['scope'], 'yourself: read and approve, never write' );
		$this->assertArrayNotHasKey( 'refresh_token', $pair, 'approve is renewed by the person, never by refresh' );
		$this->assertSame( $this->admin, (int) Tokens::access( $pair['access_token'] )['user_id'] );
		$this->assertSame( 'leitvia_unknown_agent', $this->consent( array(), array( 'subject' => (string) $this->admin ) )->get_error_code() );
		$this->assertSame( 'leitvia_unknown_agent', $this->consent( array(), array( 'subject' => '0' ) )->get_error_code() );
	}

	/**
	 * The form needs its nonce, and a person who is no agent; neither way issues a code.
	 */
	public function test_consent_form_needs_nonce_and_a_person(): void {
		$form = array_merge(
			$this->authorize(),
			array(
				'decision' => 'allow',
				'subject'  => (string) $this->agent,
				'action'   => Consent::ACTION,
			)
		);
		foreach ( array(
			'no nonce' => array( $this->admin, '' ),
			'editor'   => array( self::factory()->user->create( array( 'role' => 'editor' ) ), Consent::ACTION ),
			'agent'    => array( $this->agent, Consent::ACTION ),
		) as $case => [ $user, $nonce ] ) {
			wp_set_current_user( $user );
			$_POST    = $form + ( '' === $nonce ? array() : array( '_wpnonce' => wp_create_nonce( $nonce ) ) );
			$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The test sets the form, with and without nonce.
			try {
				Consent::handle();
				$this->fail( 'Consent went through: ' . $case );
			} catch ( WPDieException $e ) {
				$this->assertSame( 0, self::rows( 'code' ), $case );
			}
		}
	}

	/**
	 * The page names client, origin and return host, warns for loopback, offers the agents and the person as radio
	 * buttons in a fieldset with legend, write as checkbox, the nonce, no script; texts of the client are escaped.
	 */
	public function test_consent_page_shows_real_form_elements(): void {
		$client = Clients::register(
			array(
				'client_name'   => '<b>Loop</b> Client',
				'redirect_uris' => array( 'http://127.0.0.1:33418/callback' ),
			)
		)['client_id'];
		$_GET   = $this->authorize(
			array(
				'client_id'    => $client,
				'redirect_uri' => 'http://127.0.0.1:50000/callback',
			)
		);
		Consent::load();
		ob_start();
		Consent::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Loop Client', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '<p>127.0.0.1 asks to work on this site', $html, 'a registered client is named by its host' );
		$this->assertStringContainsString( 'Not verified: this client registered itself on this site', $html );
		$this->assertStringContainsString( '<code>http://127.0.0.1:50000/callback</code>', $html, 'the full redirect URI' );
		$this->assertStringContainsString( 'notice-warning', $html, 'loopback warning' );
		$this->assertSame( 2, substr_count( $html, '<fieldset>' ) );
		$this->assertSame( 2, substr_count( $html, '<legend>' ) );
		$this->assertStringContainsString( 'type="radio" name="subject" value="' . $this->agent . '" required', $html );
		$this->assertStringContainsString( 'type="radio" name="subject" value="self" required', $html );
		$this->assertStringContainsString( 'type="checkbox" name="scopes[]" value="leitvia:write"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'formnovalidate', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * The page sends no frame of any origin, offers write unticked even when the client asks for it, and stops an agent
	 * or an editor before it checks anything.
	 */
	public function test_consent_page_refuses_agents_frames_and_preselection(): void {
		$this->assertSame(
			array(
				'X-Frame-Options'         => 'DENY',
				'Content-Security-Policy' => "frame-ancestors 'none'",
			),
			Consent::HEADERS
		);
		$_GET = $this->authorize();
		Consent::load();
		ob_start();
		Consent::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="leitvia:write">', $html, 'write is never ticked in advance' );
		$this->assertStringNotContainsString( 'checked', $html );
		foreach ( array(
			'agent'  => $this->agent,
			'editor' => self::factory()->user->create( array( 'role' => 'editor' ) ),
		) as $case => $user ) {
			wp_set_current_user( $user );
			try {
				Consent::load();
				$this->fail( 'The page opened for: ' . $case );
			} catch ( WPDieException $e ) {
				$this->assertSame( 0, self::rows( 'code' ), $case );
			}
		}
	}

	/**
	 * Myself only reads without tools for decisions; with the tools of an add-on the label says the person approves
	 * change packages where the client shows them.
	 */
	public function test_consent_names_what_myself_may_do(): void {
		$_GET = $this->authorize();
		Consent::load();
		ob_start();
		Consent::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'reads only, never writes', $html );
		$this->assertStringNotContainsString( 'approves', $html );
		add_filter( 'leitvia_studio_mcp_approve_tools', static fn(): array => array( 'my-addon-decide' ) );
		try {
			ob_start();
			Consent::render();
			$html = (string) ob_get_clean();
		} finally {
			remove_all_filters( 'leitvia_studio_mcp_approve_tools' );
		}
		$this->assertStringContainsString( 'approves change packages where the client shows them', $html );
		$this->assertStringNotContainsString( 'reads only', $html );
	}

	/**
	 * The Agents screen lists the connection with client, user, rights, last use and shortened address, closes it, and
	 * closes every connection of an agent whose access is revoked.
	 */
	public function test_agents_screen_lists_and_closes_connections(): void {
		$pair                   = $this->exchange( $this->grant() )->get_data();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		self::bearer( $pair['access_token'] );
		Bearer::reset();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		wp_set_current_user( $this->admin );
		$access = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/leitvia-studio/v1/gateway/access' ) )->get_data();
		$this->assertTrue( $access['oauth']['active'] );
		$this->assertCount( 1, $access['connections'] );
		$connection = $access['connections'][0];
		$this->assertSame( 'Test Client', $connection['client'] );
		$this->assertSame( '', $connection['host'], 'registered client, no document' );
		$this->assertSame( $this->agent, $connection['user']['id'] );
		$this->assertSame( array( 'leitvia:read', 'leitvia:write' ), $connection['scopes'] );
		$this->assertSame( '203.0.113.0', $connection['ip'] );
		$this->assertNotSame( '', $connection['last_used'] );
		$close = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/leitvia-studio/v1/gateway/access/connections/' . $connection['id'] ) );
		$this->assertSame( 200, $close->get_status() );
		$this->assertNull( Tokens::access( $pair['access_token'] ) );
		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/leitvia-studio/v1/gateway/access/connections/' . $connection['id'] ) )->get_status() );
		$again  = $this->exchange( $this->grant() )->get_data();
		$revoke = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/leitvia-studio/v1/gateway/access/' . $this->agent ) );
		$this->assertSame( 1, $revoke->get_data()['connections'] );
		$this->assertNull( Tokens::access( $again['access_token'] ), 'revoking an agent closes its connections too' );
		wp_set_current_user( $this->agent );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/leitvia-studio/v1/gateway/access' ) )->get_status(), 'agents never see the screen' );
	}

	/**
	 * A bearer token signs in on the MCP route only, by path or by rest_route as core reads it; never a refresh token,
	 * never over a user found earlier, never with OAuth off. Its use is noted with the shortened address.
	 */
	public function test_bearer_works_on_the_mcp_route_only(): void {
		global $wpdb;
		$pair                   = $this->exchange( $this->grant() )->get_data();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$this->assertSame( $this->agent, self::bearer( $pair['access_token'] ) );
		$this->assertSame( 200, self::mcp( 'tools/list' )->get_status() );
		$this->assertSame( '203.0.113.0', $wpdb->get_var( $wpdb->prepare( 'SELECT ip FROM %i WHERE kind = %s', Tokens::table(), 'access' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
		$this->assertSame( $this->agent, self::bearer( $pair['access_token'], '/wp-json/leitvia-studio/v1/mcp/' ) );
		$this->assertSame( 0, self::bearer( $pair['access_token'], '/wp-json/wp/v2/posts' ), 'no other route' );
		$this->assertSame( 0, self::bearer( $pair['access_token'], '/wp-json/leitvia-studio/v1/gateway/access' ) );
		$this->assertSame( 0, self::bearer( $pair['access_token'], '/wp-admin/admin-ajax.php' ) );
		$this->assertSame( 0, self::bearer( $pair['access_token'], '/wp-json/leitvia-studio/v1/mcp-other' ) );
		$_GET['rest_route'] = '/leitvia-studio/v1/mcp';
		$this->assertSame( $this->agent, self::bearer( $pair['access_token'], '/' ) );
		$_GET['rest_route'] = '/wp/v2/posts';
		$this->assertSame( 0, self::bearer( $pair['access_token'] ), 'rest_route wins, as in core' );
		unset( $_GET['rest_route'] );
		$this->assertSame( 0, self::bearer( 'lvat_' . str_repeat( '0', 64 ) ) );
		$this->assertSame( 0, self::bearer( $pair['refresh_token'] ), 'a refresh token is no access token' );
		$this->assertSame( 7, Bearer::determine( 7 ), 'a user found earlier stays' );
		self::settings( array( 'oauth' => false ) );
		$this->assertSame( 0, self::bearer( $pair['access_token'] ), 'OAuth switched off' );
	}

	/**
	 * 401 without sign-in names the metadata and the scopes, with an invalid token also invalid_token; without OAuth
	 * only Basic.
	 */
	public function test_unauthenticated_and_invalid_tokens_get_a_bearer_challenge(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 'Bearer resource_metadata="' . OAuth::metadata_url() . '", scope="leitvia:read leitvia:write", Basic realm="leitvia"', self::mcp( 'tools/list' )->get_headers()['WWW-Authenticate'] );
		self::bearer( 'lvat_' . str_repeat( '1', 64 ) );
		$invalid = self::mcp( 'tools/list' );
		$this->assertSame( 401, $invalid->get_status() );
		$this->assertStringStartsWith( 'Bearer error="invalid_token", resource_metadata=', $invalid->get_headers()['WWW-Authenticate'] );
		self::settings( array( 'oauth' => false ) );
		Bearer::reset();
		wp_set_current_user( 0 );
		$this->assertSame( 'Basic realm="leitvia"', self::mcp( 'tools/list' )->get_headers()['WWW-Authenticate'] );
	}

	/**
	 * Revoked, expired and foreign tokens (another resource: the audience check) sign nobody in.
	 */
	public function test_revoked_expired_and_foreign_tokens_get_401(): void {
		global $wpdb;
		$pair = $this->exchange( $this->grant() )->get_data();
		Tokens::revoke_token( $pair['access_token'], $this->client() );
		$this->assertSame( 0, self::bearer( $pair['access_token'] ) );
		$this->assertSame( 401, self::mcp( 'tools/list' )->get_status() );
		$expired = $this->exchange( $this->grant() )->get_data();
		self::expire( 'access' );
		$this->assertSame( 0, self::bearer( $expired['access_token'] ) );
		$other = $this->exchange( $this->grant() )->get_data();
		$wpdb->update( Tokens::table(), array( 'resource' => 'https://other.example/wp-json/leitvia-studio/v1/mcp' ), array( 'token_hash' => hash( 'sha256', $other['access_token'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
		$this->assertSame( 0, self::bearer( $other['access_token'] ), 'a token of another resource' );
	}

	/**
	 * Scopes per tool: read calls tools that only read; write is missing for the rest, with 403 insufficient_scope; the
	 * person with approve decides only in tools an add-on names for it, never in the tools of the model, and never writes.
	 */
	public function test_scopes_decide_which_tools_a_token_may_call(): void {
		$read = $this->exchange( $this->grant( array( 'scope' => 'leitvia:read' ) ) )->get_data();
		self::bearer( $read['access_token'] );
		$this->assertSame( 200, self::mcp( 'tools/call', array( 'name' => 'leitvia-get-contract' ) )->get_status() );
		$this->assertSame( 200, self::mcp( 'tools/list' )->get_status() );
		$denied = self::mcp(
			'tools/call',
			array(
				'name'      => 'leitvia-create-content',
				'arguments' => array(),
			)
		);
		$this->assertSame( 403, $denied->get_status() );
		$this->assertSame( Bearer::SCOPE_ERROR, $denied->get_data()['error']['code'] );
		$this->assertSame( 'Bearer error="insufficient_scope", scope="leitvia:write", resource_metadata="' . OAuth::metadata_url() . '"', $denied->get_headers()['WWW-Authenticate'] );
		// The token endpoint is anonymous; a bearer token signs nobody in there.
		Bearer::reset();
		wp_set_current_user( 0 );
		$person = $this->exchange(
			$this->grant(
				array(
					'user_id' => $this->admin,
					'scope'   => 'leitvia:read leitvia:approve',
				)
			)
		)->get_data();
		self::bearer( $person['access_token'] );
		$this->assertSame( $this->admin, get_current_user_id() );
		$apply  = static fn(): WP_REST_Response => self::mcp(
			'tools/call',
			array(
				'name'      => 'leitvia-apply-package',
				'arguments' => array( 'id' => 999999 ),
			)
		);
		$denied = $apply();
		$this->assertSame( 403, $denied->get_status(), 'without an add-on approve names no tool, the model decides nothing' );
		$this->assertSame( array( 'scope' => 'leitvia:write' ), $denied->get_data()['error']['data'] );
		add_filter( 'leitvia_studio_mcp_approve_tools', static fn(): array => array( 'leitvia-apply-package' ) );
		$this->assertSame( 200, $apply()->get_status(), 'a tool an add-on names lets the person decide; the ability answers for the unknown package' );
		$this->assertSame(
			403,
			self::mcp(
				'tools/call',
				array(
					'name'      => 'leitvia-create-content',
					'arguments' => array(),
				)
			)->get_status(),
			'never write as the person'
		);
	}

	/**
	 * Rate limit per token family and tool: the next call after the limit gets 429 with Retry-After; another tool
	 * counts on its own.
	 */
	public function test_rate_limit_per_token_and_tool(): void {
		$this->assertSame( array( 120, 30 ), array( Bearer::READ_LIMIT, Bearer::WRITE_LIMIT ) );
		add_filter( 'leitvia_studio_oauth_rate_limit', static fn(): int => 2 );
		$pair = $this->exchange( $this->grant() )->get_data();
		self::bearer( $pair['access_token'] );
		$call = static fn( string $tool ): WP_REST_Response => self::mcp( 'tools/call', array( 'name' => $tool ) );
		$this->assertSame( 200, $call( 'leitvia-get-contract' )->get_status() );
		$this->assertSame( 200, $call( 'leitvia-get-contract' )->get_status() );
		$limited = $call( 'leitvia-get-contract' );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( Bearer::RATE_ERROR, $limited->get_data()['error']['code'] );
		$this->assertGreaterThan( 0, (int) $limited->get_headers()['Retry-After'] );
		$this->assertSame( 200, $call( 'leitvia-get-site-health' )->get_status(), 'each tool counts on its own' );
	}

	/**
	 * A client document as fetched, kept in its transient.
	 */
	private static function document_client(): string {
		$url = 'https://client.example/oauth/client.json';
		set_transient(
			'leitvia_oauth_client_' . md5( $url ),
			array(
				'client_id'     => $url,
				'kind'          => 'cimd',
				'client_name'   => 'Doc Client',
				'redirect_uris' => array( self::REDIRECT ),
			),
			DAY_IN_SECONDS
		);
		return $url;
	}

	/**
	 * RFC 9700, section 4.11.2: an error after the redirect check goes back at once only to a client document with an
	 * open connection; a registered client or one never connected sees it on the page with a link back. A client
	 * document gets no warning and keeps its name.
	 */
	public function test_errors_go_back_at_once_only_to_a_connected_client_document(): void {
		$bad   = array( 'code_challenge_method' => 'plain' );
		$error = Consent::request( $this->authorize( $bad ) );
		$this->assertNotSame( '', Consent::redirect_of( $error ) );
		$this->assertSame( '', Consent::follow_of( $error ), 'a registered client' );
		$_GET = $this->authorize( $bad );
		Consent::load();
		ob_start();
		Consent::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( Consent::redirect_of( $error ) ) . '">Back to client.example</a>', $html );
		$url = self::document_client();
		$this->assertSame( '', Consent::follow_of( Consent::request( $this->authorize( $bad + array( 'client_id' => $url ) ) ) ), 'never connected' );
		$this->exchange( $this->grant( array( 'client_id' => $url ) ), array( 'client_id' => $url ) );
		$this->assertStringStartsWith( self::REDIRECT . '?', Consent::follow_of( Consent::request( $this->authorize( $bad + array( 'client_id' => $url ) ) ) ), 'connected' );
		$_GET = $this->authorize( array( 'client_id' => $url ) );
		Consent::load();
		ob_start();
		Consent::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<p>Doc Client asks to work on this site', $html );
		$this->assertStringNotContainsString( 'Not verified', $html );
	}

	/**
	 * A family ends MAX_AGE after its consent, whose time its ID starts with: access and refresh tokens stop, the
	 * family is closed.
	 */
	public function test_families_end_after_their_maximum_age(): void {
		global $wpdb;
		$this->assertSame( 90 * DAY_IN_SECONDS, Tokens::MAX_AGE );
		$pair   = $this->swap( $this->grant() );
		$family = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT family FROM %i WHERE kind = %s', Tokens::table(), 'access' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
		$this->assertEqualsWithDelta( time(), hexdec( substr( $family, 0, 8 ) ), 5 );
		$next = Tokens::refresh( $pair['refresh_token'], $this->client(), '', '' );
		$this->assertIsArray( $next );
		add_filter( 'leitvia_studio_oauth_max_age', static fn(): int => -1 );
		$this->assertNull( Tokens::access( $next['access_token'] ) );
		$this->assertSame( 'invalid_grant', Tokens::refresh( $next['refresh_token'], $this->client(), '', '' )->get_error_code() );
		remove_all_filters( 'leitvia_studio_oauth_max_age' );
		$this->assertNull( Tokens::access( $next['access_token'] ), 'the family is closed' );
	}

	/**
	 * Code, verifier and the other fields of the token endpoint count from the body only, never from the query.
	 */
	public function test_token_endpoint_reads_the_body_only(): void {
		$code    = $this->grant();
		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/oauth/token' );
		$request->set_query_params(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $this->client(),
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => self::VERIFIER,
			)
		);
		$this->assertSame( 'unsupported_grant_type', rest_get_server()->dispatch( $request )->get_data()['error'] );
		$request->set_body_params( array( 'grant_type' => 'authorization_code' ) );
		$this->assertSame( 'invalid_grant', rest_get_server()->dispatch( $request )->get_data()['error'], 'the code in the query does not count' );
		$this->assertSame( 0, self::rows( 'access' ) );
		$this->assertSame( 200, $this->exchange( $code )->get_status(), 'the code is still unused' );
	}

	/**
	 * Core prefers rest_route in the body; that, any script but index.php and a route that is not exactly the MCP
	 * route sign nobody in.
	 */
	public function test_bearer_ignores_body_routes_other_scripts_and_near_routes(): void {
		$pair                = $this->exchange( $this->grant() )->get_data();
		$_POST['rest_route'] = '/wp/v2/users';
		$this->assertSame( 0, self::bearer( $pair['access_token'] ), 'rest_route in the body' );
		$_POST['rest_route'] = '/leitvia-studio/v1/mcp';
		$this->assertSame( 0, self::bearer( $pair['access_token'] ), 'any rest_route in the body' );
		$_POST              = array();
		$_GET['rest_route'] = '/leitvia-studio/v1/mcp';
		foreach ( array( 'wp-admin/admin-ajax.php', 'wp-admin/admin-post.php', 'wp-comments-post.php', 'xmlrpc.php' ) as $script ) {
			$this->assertSame( 0, self::bearer( $pair['access_token'], '/' . $script, $script ), $script );
			$this->assertSame( 0, self::bearer( $pair['access_token'], '/wp-json/leitvia-studio/v1/mcp', $script ), $script . ' with the path' );
		}
		$this->assertSame( $this->agent, self::bearer( $pair['access_token'], '/' ) );
		foreach ( array( ' /leitvia-studio/v1/mcp', '/LEITVIA-STUDIO/v1/mcp', 'leitvia-studio/v1/mcp' ) as $route ) {
			$_GET['rest_route'] = $route;
			$this->assertSame( 0, self::bearer( $pair['access_token'], '/' ), 'compared as sent: ' . $route );
		}
		$_GET['rest_route'] = array( '/leitvia-studio/v1/mcp' );
		$this->assertSame( 0, self::bearer( $pair['access_token'], '/' ), 'no array' );
	}

	/**
	 * Second gate: a request signed in by a token reaches no other REST route, the sign-in is taken back with 401;
	 * admin, comments and templates stop it with wp_die. Other ways of signing in pass.
	 */
	public function test_gates_take_a_token_back_outside_the_mcp_route(): void {
		$this->assertSame( 0, has_filter( 'rest_pre_dispatch', array( Bearer::class, 'gate' ) ) );
		foreach ( array( 'admin_init', 'pre_comment_on_post', 'template_redirect' ) as $hook ) {
			$this->assertSame( 0, has_action( $hook, array( Bearer::class, 'outside' ) ), $hook );
		}
		$pair = $this->exchange( $this->grant() )->get_data();
		self::bearer( $pair['access_token'] );
		$this->assertSame( 200, self::mcp( 'tools/list' )->get_status() );
		$users = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/users' ) );
		$this->assertSame( 401, $users->get_status() );
		$this->assertSame( 'leitvia_oauth_route', $users->get_data()['code'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertNull( Bearer::token() );
		wp_set_current_user( $this->admin );
		$this->assertSame( 200, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/users' ) )->get_status(), 'a cookie user passes' );
		self::bearer( $pair['access_token'] );
		try {
			Bearer::outside();
			$this->fail( 'A token passed outside the REST API.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 0, get_current_user_id() );
			$this->assertNull( Bearer::token() );
		}
		wp_set_current_user( $this->admin );
		Bearer::outside();
		$this->assertSame( $this->admin, get_current_user_id(), 'without token nothing stops' );
	}

	/**
	 * A task started over OAuth belongs to that connection: the same user without the token learns nothing of it, and
	 * a run after the connection was revoked fails.
	 */
	public function test_task_belongs_to_its_connection(): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
				'headers'  => array(),
			)
		);
		$pair    = $this->swap( $this->grant() );
		$context = new \LeitviaStudio\Mcp\Context( '2026-07-28', true, array( 'extensions' => array( \LeitviaStudio\Mcp\Tasks::EXTENSION => array() ) ) );
		$this->assertSame( $this->agent, self::bearer( $pair['access_token'] ) );
		$task = \LeitviaStudio\Mcp\Tools::call(
			array(
				'name'      => 'leitvia-apply-recipe',
				'arguments' => array(
					'recipe' => 'leitvia/blog',
					'dryRun' => true,
				),
			),
			$context
		);
		$this->assertSame( 'task', $task['resultType'] );
		$get = static fn() => \LeitviaStudio\Mcp\Tasks::get( array( 'taskId' => $task['taskId'] ), $context );
		$this->assertSame( 'working', $get()['status'] );
		Bearer::reset();
		try {
			$get();
			$this->fail( 'The same user without the token found the task.' );
		} catch ( \LeitviaStudio\Mcp\Protocol_Error $error ) {
			$this->assertSame( -32602, $error->rpc_code );
		}
		self::bearer( $pair['access_token'] );
		Tokens::revoke_family( (string) Bearer::token()['family'] );
		Bearer::reset();
		wp_set_current_user( 0 );
		do_action( \LeitviaStudio\Mcp\Tasks::HOOK, $task['taskId'] );
		$record = get_option( 'leitvia_studio_mcp_task_' . $task['taskId'] );
		$this->assertSame( array( 'failed', false ), array( $record['status'], $record['started'] ), 'The ability never ran.' );
	}

	/**
	 * The Agents screen asks the site at the address a client tries for the issuer: 200 with this issuer is ok, 403 as
	 * from an nginx rule location ~ /\. or another issuer is not, with the directives to copy. The answer is kept,
	 * nobody but a person gets it, and without OAuth the site asks nothing.
	 */
	public function test_agents_screen_checks_the_discovery_of_the_site(): void {
		$asked  = array();
		$answer = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$asked, &$answer ) {
				unset( $pre );
				$asked[] = array( $url, $args['timeout'] );
				return $answer;
			},
			10,
			3
		);
		$discovery = static fn() => rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/leitvia-studio/v1/gateway/access/discovery' ) );
		$answer    = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( OAuth::authorization_server() ),
			'headers'  => array(),
		);
		$ok        = $discovery()->get_data()['discovery'];
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( array( array( OAuth::issuer() . '/.well-known/oauth-authorization-server', 3 ) ), $asked );
		$this->assertStringContainsString( 'location ^~ /.well-known/oauth-authorization-server { try_files $uri /index.php?$args; }', $ok['directives'] );
		$this->assertSame( OAuth::HELP, $ok['help'], 'the screen links the troubleshooting from the answer' );
		$discovery();
		$this->assertCount( 1, $asked, 'the answer is kept' );
		delete_transient( OAuth::DISCOVERY_CACHE );
		$answer  = array(
			'response' => array( 'code' => 403 ),
			'body'     => '<html><body>403 Forbidden</body></html>',
			'headers'  => array(),
		);
		$blocked = $discovery()->get_data()['discovery'];
		$this->assertSame( array( false, 403 ), array( $blocked['ok'], $blocked['status'] ) );
		delete_transient( OAuth::DISCOVERY_CACHE );
		$answer = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"issuer":"https://attacker.example"}',
			'headers'  => array(),
		);
		$this->assertFalse( OAuth::discovery()['ok'], 'another issuer does not count' );
		wp_set_current_user( $this->agent );
		$this->assertSame( 403, $discovery()->get_status(), 'agents never see it' );
		wp_set_current_user( $this->admin );
		delete_transient( OAuth::DISCOVERY_CACHE );
		self::settings( array( 'oauth' => false ) );
		$asked = array();
		$this->assertNull( $discovery()->get_data()['discovery'] );
		$this->assertSame( array(), $asked, 'without OAuth the site asks nothing' );
	}
}
