<?php
/**
 * Client ID Metadata Documents and Dynamic Client Registration of the MCP server: the checks against SSRF.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Mcp\OAuth\Clients;
use LeitviaStudio\Mcp\OAuth\Tokens;

/**
 * Every case of the row "CIMD und SSRF" of the spec. HTTP is answered by pre_http_request, DNS by Clients::$resolver.
 */
class Test_Mcp_OAuth_Clients extends WP_UnitTestCase {

	private const URL = 'https://client.example/oauth/client.json';

	/**
	 * Requests that reached HTTP, with the pin in force while they ran.
	 *
	 * @var list<array{url: string, args: array<string, mixed>, pin: string}>
	 */
	private array $requests = array();

	/**
	 * Hosts looked up.
	 *
	 * @var list<string>
	 */
	private array $lookups = array();

	/**
	 * Answer of HTTP: body, status, headers.
	 *
	 * @var array{0: string, 1: int, 2: array<string, string>}
	 */
	private array $answer = array( '', 200, array( 'content-type' => 'application/json' ) );

	/**
	 * OAuth available, DNS answers a public address, HTTP is answered by the test.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'leitvia_studio_oauth_available', '__return_true' );
		$this->resolve( array( '93.184.216.34' ) );
		add_filter(
			'pre_http_request',
			function ( $pre, array $args, string $url ): array {
				unset( $pre );
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
					'pin'  => Clients::pinned( $url ),
				);
				return array(
					'headers'  => $this->answer[2],
					'body'     => $this->answer[0],
					'response' => array(
						'code'    => $this->answer[1],
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * The real lookup of the next test.
	 */
	public function tear_down(): void {
		Clients::$resolver = null;
		Clients::$proxy    = null;
		parent::tear_down();
	}

	/**
	 * DNS answers these lists of addresses, one per lookup, the last one repeating; lookups are counted.
	 *
	 * @param string[] ...$answers Addresses per lookup.
	 */
	private function resolve( array ...$answers ): void {
		$this->lookups     = array();
		Clients::$resolver = function ( string $host ) use ( $answers ): array {
			$this->lookups[] = $host;
			return $answers[ min( count( $this->lookups ), count( $answers ) ) - 1 ];
		};
	}

	/**
	 * HTTP answers this document or text.
	 *
	 * @param array<mixed>|string   $body    Document or text.
	 * @param int                   $status  HTTP status.
	 * @param array<string, string> $headers Headers.
	 */
	private function serve( $body, int $status = 200, array $headers = array( 'content-type' => 'application/json' ) ): void {
		$this->answer = array( is_string( $body ) ? $body : (string) wp_json_encode( $body ), $status, $headers );
	}

	/**
	 * The document of a valid client, with changes.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed>
	 */
	private static function document( array $changes = array() ): array {
		return array_merge(
			array(
				'client_id'                  => self::URL,
				'client_name'                => 'Example Client',
				'client_uri'                 => 'https://client.example',
				'redirect_uris'              => array( 'https://client.example/callback', 'http://127.0.0.1:33418/callback' ),
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
			),
			$changes
		);
	}

	/**
	 * Asserts that the client is refused with invalid_client.
	 *
	 * @param string $client_id client_id.
	 * @param string $message   Case.
	 */
	private function assertRefused( string $client_id, string $message = '' ): void {
		$client = Clients::find( $client_id );
		$this->assertWPError( $client, $message );
		$this->assertSame( 'invalid_client', $client->get_error_code(), $message );
	}

	/**
	 * A valid document: fetched once, over wp_safe_remote_get without redirect and with a size limit, pinned to the
	 * address the check saw, kept for a day.
	 */
	public function test_document_is_fetched_once_pinned_and_cached(): void {
		$this->serve( self::document() );
		$client = Clients::find( self::URL );
		$this->assertIsArray( $client );
		$this->assertSame( 'cimd', $client['kind'] );
		$this->assertSame( self::URL, $client['client_id'] );
		$this->assertSame( 'Example Client', $client['client_name'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );
		$this->assertSame( Clients::MAX_BYTES + 1, $this->requests[0]['args']['limit_response_size'] );
		$this->assertTrue( $this->requests[0]['args']['reject_unsafe_urls'], 'wp_safe_remote_get' );
		$this->assertSame( 'client.example:443:93.184.216.34', $this->requests[0]['pin'] );
		$this->assertSame( '', Clients::pinned( self::URL ), 'the pin ends with the request' );
		$this->assertSame( $client, Clients::find( self::URL ) );
		$this->assertCount( 1, $this->requests, 'kept for a day' );
		$this->assertSame( array( 'client.example' ), $this->lookups );
	}

	/**
	 * Http, no path, user name, other port, fragment, query, dot segments, too long: refused without a request.
	 */
	public function test_client_ids_other_than_https_with_a_path_are_refused(): void {
		$this->serve( self::document() );
		foreach ( array(
			'http://client.example/oauth/client.json',
			'https://client.example',
			'https://client.example/',
			'https://user:secret@client.example/c.json',
			'https://client.example:8443/c.json',
			'https://client.example/c.json#part',
			'https://client.example/c.json?v=1',
			'https://client.example/a/../c.json',
			'https://client.example/./c.json',
			'https://client.example/' . str_repeat( 'a', 500 ),
		) as $url ) {
			$this->assertRefused( $url, $url );
		}
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Loopback, private, shared, link-local (cloud metadata), this network, multicast and reserved addresses, by name
	 * and as literal: refused without a request.
	 */
	public function test_private_loopback_and_link_local_addresses_are_refused(): void {
		$this->serve( self::document() );
		foreach ( array( '127.0.0.1', '127.8.8.8', '10.0.0.5', '172.16.3.4', '172.31.255.255', '192.168.1.10', '169.254.169.254', '100.64.0.1', '0.0.0.0', '192.0.0.8', '198.18.0.1', '224.0.0.251', '255.255.255.255' ) as $ip ) {
			$this->resolve( array( $ip ) );
			$this->assertRefused( self::URL, $ip );
			$this->assertRefused( 'https://' . $ip . '/c.json', 'literal ' . $ip );
		}
		$this->resolve( array() );
		$this->assertRefused( self::URL, 'a host without address' );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * IPv6: loopback, unspecified, IPv4-mapped and -compatible, ULA, link-local, site-local, multicast, NAT64, 6to4,
	 * documentation, discard; by name and as literal. A public IPv6 address passes and is pinned in brackets.
	 */
	public function test_ipv6_variants_are_refused(): void {
		$this->serve( self::document() );
		foreach ( array( '::1', '::', '::ffff:127.0.0.1', '::ffff:7f00:1', '::127.0.0.1', 'fc00::1', 'fd12:3456::1', 'fe80::1', 'fec0::1', 'ff02::1', '64:ff9b::7f00:1', '2002:7f00:1::1', '2001:db8::1', '100::1' ) as $ip ) {
			$this->resolve( array( $ip ) );
			$this->assertRefused( self::URL, $ip );
			$this->assertRefused( 'https://[' . $ip . ']/c.json', 'literal ' . $ip );
		}
		$this->assertSame( array(), $this->requests );
		$this->resolve( array( '2606:4700:4700::1111' ) );
		$this->assertIsArray( Clients::find( self::URL ) );
		$this->assertSame( 'client.example:443:[2606:4700:4700::1111]', $this->requests[0]['pin'] );
	}

	/**
	 * DNS rebinding: one lookup per fetch, and cURL connects to the address it returned; a second lookup that would
	 * point elsewhere never happens. One private record among public ones is enough to refuse.
	 */
	public function test_dns_rebinding_cannot_move_the_request(): void {
		$this->serve( self::document() );
		$this->resolve( array( '93.184.216.34' ), array( '127.0.0.1' ) );
		$this->assertIsArray( Clients::find( self::URL ) );
		$this->assertCount( 1, $this->lookups, 'one lookup per fetch' );
		$this->assertSame( 'client.example:443:93.184.216.34', $this->requests[0]['pin'], 'cURL connects to the checked address' );
		$this->resolve( array( '93.184.216.34', '10.0.0.1' ) );
		$this->assertRefused( 'https://client.example/oauth/second.json', 'one private record refuses' );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * A redirect is an answer other than 200 and is not followed.
	 */
	public function test_redirects_are_not_followed(): void {
		$this->serve( '', 302, array( 'location' => 'https://127.0.0.1/c.json' ) );
		$this->assertRefused( self::URL );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );
		$this->serve( self::document(), 404 );
		$this->assertRefused( self::URL, 'not found' );
	}

	/**
	 * A document over 64 KB is refused.
	 */
	public function test_document_over_64_kb_is_refused(): void {
		$this->serve( self::document( array( 'padding' => str_repeat( 'a', Clients::MAX_BYTES ) ) ) );
		$this->assertRefused( self::URL );
	}

	/**
	 * The client_id in the document equals the address exactly: no other path, host case, trailing slash or host.
	 */
	public function test_client_id_must_equal_the_address(): void {
		foreach ( array( 'https://client.example/oauth/other.json', 'https://CLIENT.example/oauth/client.json', self::URL . '/', 'https://evil.example/oauth/client.json' ) as $id ) {
			$this->serve( self::document( array( 'client_id' => $id ) ) );
			$this->assertRefused( self::URL, $id );
		}
	}

	/**
	 * Name and redirect URIs are required, redirect URIs use https or loopback http without fragment, only public
	 * clients with the code flow; a failed fetch is not kept.
	 */
	public function test_document_fields_are_checked(): void {
		foreach ( array(
			'no name'        => self::document( array( 'client_name' => '' ) ),
			'no redirect'    => self::document( array( 'redirect_uris' => array() ) ),
			'http elsewhere' => self::document( array( 'redirect_uris' => array( 'http://evil.example/cb' ) ) ),
			'fragment'       => self::document( array( 'redirect_uris' => array( 'https://client.example/cb#x' ) ) ),
			'custom scheme'  => self::document( array( 'redirect_uris' => array( 'cursor://callback' ) ) ),
			'secret'         => self::document( array( 'token_endpoint_auth_method' => 'client_secret_basic' ) ),
			'implicit'       => self::document( array( 'grant_types' => array( 'implicit' ) ) ),
			'list'           => array( self::document() ),
		) as $case => $document ) {
			$this->serve( $document );
			$this->assertRefused( self::URL, $case );
		}
		$this->serve( 'not json' );
		$this->assertRefused( self::URL, 'not JSON' );
		$this->serve( self::document() );
		$this->assertIsArray( Clients::find( self::URL ), 'nothing of the failed fetches was kept' );
	}

	/**
	 * Redirect URIs match exactly; loopback http on any port (RFC 8252).
	 */
	public function test_redirect_uris_match_exactly_loopback_on_any_port(): void {
		$client = array( 'redirect_uris' => array( 'https://client.example/callback', 'http://127.0.0.1:33418/callback', 'http://localhost/cb' ) );
		$this->assertTrue( Clients::redirect_matches( 'https://client.example/callback', $client ) );
		$this->assertTrue( Clients::redirect_matches( 'http://127.0.0.1:50123/callback', $client ), 'loopback on any port' );
		$this->assertTrue( Clients::redirect_matches( 'http://localhost:4000/cb', $client ) );
		$this->assertFalse( Clients::redirect_matches( 'https://client.example/callback/', $client ) );
		$this->assertFalse( Clients::redirect_matches( 'https://client.example:444/callback', $client ) );
		$this->assertFalse( Clients::redirect_matches( 'http://127.0.0.1:50123/other', $client ) );
		$this->assertFalse( Clients::redirect_matches( 'https://evil.example/callback', $client ) );
		$this->assertFalse( Clients::redirect_matches( 'http://127.0.0.1.evil.example/callback', $client ) );
		$this->assertFalse( Clients::redirect_matches( '', $client ) );
	}

	/**
	 * A document comes as application/json, parameters allowed, and never through a proxy, which would look the host
	 * up itself past the pin; a host the proxy bypasses is fetched.
	 */
	public function test_documents_need_json_and_no_proxy(): void {
		foreach ( array( 'text/html', 'application/jsonp', '' ) as $type ) {
			$this->serve( self::document(), 200, '' === $type ? array() : array( 'content-type' => $type ) );
			$this->assertRefused( self::URL, 'Content-Type ' . $type );
		}
		$this->serve( self::document(), 200, array( 'content-type' => 'Application/JSON; charset=utf-8' ) );
		$this->assertIsArray( Clients::find( self::URL ), 'a parameter is allowed' );
		delete_transient( 'leitvia_oauth_client_' . md5( self::URL ) );
		Clients::$proxy = new class() extends WP_HTTP_Proxy {
			/**
			 * A proxy is set.
			 */
			public function is_enabled() {
				return true;
			}
		};
		$sent           = count( $this->requests );
		$this->assertRefused( self::URL, 'through a proxy' );
		$this->assertCount( $sent, $this->requests, 'nothing is sent' );
		add_filter( 'pre_http_send_through_proxy', '__return_false' );
		$this->assertIsArray( Clients::find( self::URL ), 'a host the proxy bypasses' );
	}

	/**
	 * An open connection of a registered client, as a refresh token valid for a day.
	 *
	 * @param string $client_id client_id.
	 */
	private static function connect( string $client_id ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table in a test.
			Tokens::table(),
			array(
				'token_hash' => hash( 'sha256', $client_id ),
				'kind'       => 'refresh',
				'family'     => md5( $client_id ),
				'client_id'  => $client_id,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
	}

	/**
	 * DCR: public clients only, the same checks, no request; at most MAX_REGISTERED, the oldest without a connection drops
	 * out, a client with one never; with every one connected a new registration is refused.
	 */
	public function test_dynamic_registration_keeps_public_clients_only(): void {
		$client = Clients::register(
			array(
				'client_name'   => 'Old Client',
				'redirect_uris' => array( 'http://localhost:6274/oauth/callback' ),
			)
		);
		$this->assertIsArray( $client );
		$this->assertMatchesRegularExpression( '/^lv-[0-9a-f]{32}$/', $client['client_id'] );
		$this->assertSame( 'none', $client['token_endpoint_auth_method'] );
		$this->assertSame( 'dcr', Clients::find( $client['client_id'] )['kind'] );
		$bad = Clients::register(
			array(
				'client_name'   => 'X',
				'redirect_uris' => array( 'http://evil.example/cb' ),
			)
		);
		$this->assertSame( 'invalid_redirect_uri', $bad->get_error_code() );
		$secret = Clients::register(
			array(
				'client_name'                => 'X',
				'redirect_uris'              => array( 'https://x.example/cb' ),
				'token_endpoint_auth_method' => 'client_secret_post',
			)
		);
		$this->assertSame( 'invalid_client_metadata', $secret->get_error_code() );
		$large = Clients::register(
			array(
				'client_name'   => 'X',
				'redirect_uris' => array_map( static fn( int $i ): string => 'https://x.example/' . $i . str_repeat( 'a', 1000 ), range( 1, 5 ) ),
			)
		);
		$this->assertSame( 'invalid_client_metadata', $large->get_error_code(), 'an anonymous registration stays small' );
		$this->assertSame( 'invalid_client', Clients::find( 'lv-' . str_repeat( '0', 32 ) )->get_error_code() );
		$this->assertSame( array(), $this->requests, 'registration fetches nothing' );
		self::connect( $client['client_id'] );
		$spare = Clients::register(
			array(
				'client_name'   => 'Spare Client',
				'redirect_uris' => array( 'https://x.example/cb' ),
			)
		);
		for ( $i = 0; $i < Clients::MAX_REGISTERED; $i++ ) {
			Clients::register(
				array(
					'client_name'   => 'C' . $i,
					'redirect_uris' => array( 'https://x.example/cb' ),
				)
			);
		}
		$this->assertCount( Clients::MAX_REGISTERED, get_option( Clients::OPTION ) );
		$this->assertIsArray( Clients::find( $client['client_id'] ), 'a client with a connection stays' );
		$this->assertWPError( Clients::find( $spare['client_id'] ), 'the oldest without a connection dropped out' );
		array_map( array( self::class, 'connect' ), array_diff( array_keys( (array) get_option( Clients::OPTION ) ), array( $client['client_id'] ) ) );
		$full = Clients::register(
			array(
				'client_name'   => 'One Too Many',
				'redirect_uris' => array( 'https://x.example/cb' ),
			)
		);
		$this->assertSame( 'invalid_client_metadata', $full->get_error_code(), 'no connected client is pushed out' );
		$this->assertCount( Clients::MAX_REGISTERED, get_option( Clients::OPTION ) );
	}
}
