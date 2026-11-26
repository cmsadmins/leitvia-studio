<?php
/**
 * OAuth clients: Client ID Metadata Documents and Dynamic Client Registration.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * A client names itself with an https address (Client ID Metadata Document, the way MCP 2026-07-28 prefers) or with an
 * ID this site gave it through Dynamic Client Registration (RFC 7591, deprecated, setting oauth_dcr). The document is
 * fetched by the server only for a signed-in person on the page "Connect agent": https on port 443, from a host whose
 * every address is public, looked up once and pinned for cURL (CURLOPT_RESOLVE) so a second lookup cannot lead
 * elsewhere (DNS rebinding), never through a proxy (it would look the host up itself), without redirects, as
 * application/json, at most MAX_BYTES, kept for a day. Only public clients connect: no
 * secret, redirect URIs over https or to the loopback address of the computer. Error texts are OAuth error
 * descriptions, ASCII and untranslated (RFC 6749, section 5.2).
 */
final class Clients {

	public const MAX_BYTES = 65536;

	public const TIMEOUT = 5;

	public const OPTION = 'leitvia_studio_oauth_clients';

	public const MAX_REGISTERED = 100;

	/**
	 * Largest stored registration in bytes. Registration is anonymous: with MAX_REGISTERED this keeps the option small.
	 */
	public const MAX_REGISTRATION_BYTES = 4096;

	/**
	 * Request argument that marks the fetch of a client document for pin().
	 */
	private const MARK = 'leitvia_studio_cimd';

	/**
	 * Ranges that are no public address: this network, private, shared, loopback, link-local, protocol assignments,
	 * documentation, 6to4 relay, benchmark, multicast, reserved; for IPv6 also IPv4-compatible and -mapped, NAT64,
	 * discard, Teredo and protocol assignments, documentation, 6to4, ULA, link-local, site-local, multicast.
	 */
	private const BLOCKED = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/96',
		'::ffff:0:0/96',
		'64:ff9b::/96',
		'64:ff9b:1::/48',
		'100::/64',
		'2001::/23',
		'2001:db8::/32',
		'2002::/16',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
	);

	/**
	 * Host lookup, replaceable in tests: host to its IPv4 and IPv6 addresses.
	 *
	 * @var (\Closure(string): list<string>)|null
	 */
	public static ?\Closure $resolver = null;

	/**
	 * Proxy settings, replaceable in tests; null reads WP_PROXY_HOST and the other constants.
	 *
	 * @var \WP_HTTP_Proxy|null
	 */
	public static ?\WP_HTTP_Proxy $proxy = null;

	/**
	 * CURLOPT_RESOLVE entries of the running fetch by address, for pin().
	 *
	 * @var array<string, string>
	 */
	private static array $pins = array();

	/**
	 * The client with this ID: from its document or from the registrations of this site.
	 *
	 * @param string $client_id client_id of the request.
	 * @return array<string, mixed>|\WP_Error client_id, client_name, redirect_uris and kind (cimd or dcr).
	 */
	public static function find( string $client_id ) {
		if ( str_contains( $client_id, '://' ) ) {
			return self::fetch( $client_id );
		}
		$registered = self::registered()[ $client_id ] ?? null;
		return is_array( $registered ) ? $registered : self::error( 'invalid_client', 'Unknown client_id.' );
	}

	/**
	 * Fetches, checks and keeps the document of a client.
	 *
	 * @param string $url client_id, the address of the document.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function fetch( string $url ) {
		if ( ! self::is_document_url( $url ) ) {
			return self::error( 'invalid_client', 'client_id must be an https address with a path, on port 443, without user, query or fragment.' );
		}
		$key    = 'leitvia_oauth_client_' . md5( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$entry = self::resolve( $host );
		if ( null === $entry ) {
			return self::error( 'invalid_client', 'The host of client_id has no public address.' );
		}
		$proxy = self::$proxy ?? new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return self::error( 'invalid_client', 'Client documents are not fetched through a proxy, which would bypass the address check.' );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			return self::error( 'invalid_client', 'Fetching client documents needs the PHP extension cURL.' );
		}
		// A literal address needs no pin; a name is pinned to the address the check saw.
		self::$pins[ $url ] = filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ? '' : $entry;
		$response           = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES + 1,
				'headers'             => array( 'Accept' => 'application/json' ),
				self::MARK            => true,
			)
		);
		unset( self::$pins[ $url ] );
		if ( is_wp_error( $response ) ) {
			return self::error( 'invalid_client', 'The client document could not be fetched.' );
		}
		if ( 200 !== (int) $response['response']['code'] ) {
			return self::error( 'invalid_client', 'The client document must answer with status 200, without redirect.' );
		}
		$type = implode( ',', (array) ( $response['headers']['content-type'] ?? '' ) );
		if ( 'application/json' !== strtolower( trim( explode( ';', $type )[0] ) ) ) {
			return self::error( 'invalid_client', 'The client document must be served as application/json.' );
		}
		$body = (string) $response['body'];
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return self::error( 'invalid_client', 'The client document is larger than 64 KB.' );
		}
		$document = json_decode( $body, true );
		if ( ! is_array( $document ) || array_is_list( $document ) ) {
			return self::error( 'invalid_client', 'The client document is no JSON object.' );
		}
		if ( ( $document['client_id'] ?? null ) !== $url ) {
			return self::error( 'invalid_client', 'client_id in the document must equal its address.' );
		}
		$client = self::validate( $document );
		if ( is_wp_error( $client ) ) {
			return self::error( 'invalid_client', $client->get_error_message() );
		}
		$client = array(
			'client_id' => $url,
			'kind'      => 'cimd',
		) + $client;
		set_transient( $key, $client, DAY_IN_SECONDS );
		return $client;
	}

	/**
	 * Whether an address may name a client document: https, a host, a path other than /, port 443, no user, query,
	 * fragment or dot segment, at most 500 characters.
	 *
	 * @param string $url Address.
	 */
	public static function is_document_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || strlen( $url ) > 500 ) {
			return false;
		}
		$path = (string) ( $parts['path'] ?? '' );
		return 'https' === ( $parts['scheme'] ?? '' ) && '' !== (string) ( $parts['host'] ?? '' ) && 443 === (int) ( $parts['port'] ?? 443 )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] )
			&& '' !== trim( $path, '/' ) && ! preg_match( '#/\.\.?(/|$)#', $path );
	}

	/**
	 * The CURLOPT_RESOLVE entry host:443:address when every address of the host is public, IPv4 preferred; null
	 * otherwise. Looks the host up once.
	 *
	 * @param string $host Host, an IPv6 literal in brackets or not.
	 */
	public static function resolve( string $host ): ?string {
		$host   = strtolower( trim( $host, '[]' ) );
		$lookup = self::$resolver ?? static fn( string $name ): array => self::lookup( $name );
		$ips    = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : $lookup( $host );
		if ( ! $ips ) {
			return null;
		}
		foreach ( $ips as $ip ) {
			if ( ! self::public_ip( (string) $ip ) ) {
				return null;
			}
		}
		$v4 = array_values( array_filter( $ips, static fn( $ip ): bool => ! str_contains( (string) $ip, ':' ) ) );
		$ip = (string) ( $v4 ? $v4[0] : $ips[0] );
		return $host . ':443:' . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip );
	}

	/**
	 * A and AAAA records of a host.
	 *
	 * @param string $host Host name.
	 * @return list<string>
	 */
	private static function lookup( string $host ): array {
		$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup refuses the client; its warning would only fill the log.
		$ips     = array();
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			$ips[] = (string) ( $record['ip'] ?? $record['ipv6'] ?? '' );
		}
		return array_values( array_filter( $ips ) );
	}

	/**
	 * Whether an address lies outside every range of BLOCKED.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 */
	public static function public_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$packed = (string) inet_pton( $ip );
		foreach ( self::BLOCKED as $range ) {
			[ $net, $bits ] = explode( '/', $range );
			$net            = (string) inet_pton( $net );
			if ( strlen( $net ) !== strlen( $packed ) ) {
				continue;
			}
			$bytes = intdiv( (int) $bits, 8 );
			$rest  = (int) $bits % 8;
			if ( substr( $packed, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest || ( ord( $packed[ $bytes ] ) >> ( 8 - $rest ) ) === ( ord( $net[ $bytes ] ) >> ( 8 - $rest ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Lets cURL connect to the address fetch() checked instead of looking the host up again. Runs on http_api_curl.
	 *
	 * @param \CurlHandle          $handle cURL handle.
	 * @param array<string, mixed> $args   Request arguments.
	 * @param string               $url    Address.
	 */
	public static function pin( $handle, $args, $url ): void {
		$entry = self::$pins[ (string) $url ] ?? '';
		if ( ! empty( $args[ self::MARK ] ) && '' !== $entry ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pins the checked address; the HTTP API has no option for it.
		}
	}

	/**
	 * The pin of a running fetch, for tests.
	 *
	 * @param string $url Address.
	 */
	public static function pinned( string $url ): string {
		return self::$pins[ $url ] ?? '';
	}

	/**
	 * Name and redirect URIs of client metadata, or the RFC 7591 error.
	 *
	 * @param array<string, mixed> $meta Client metadata.
	 * @return array{client_name: string, redirect_uris: list<string>}|\WP_Error
	 */
	public static function validate( array $meta ) {
		$name = mb_substr( sanitize_text_field( is_string( $meta['client_name'] ?? null ) ? $meta['client_name'] : '' ), 0, 100 );
		$uris = $meta['redirect_uris'] ?? null;
		if ( '' === $name || ! is_array( $uris ) || ! $uris || ! array_is_list( $uris ) || count( $uris ) > 20 ) {
			return self::error( 'invalid_client_metadata', 'client_name and a list of at most 20 redirect_uris are required.' );
		}
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || strlen( $uri ) > 2000 || ! self::redirect_allowed( $uri ) ) {
				return self::error( 'invalid_redirect_uri', 'Every redirect URI uses https, or http on localhost, 127.0.0.1 or [::1], without fragment.' );
			}
		}
		if ( 'none' !== ( $meta['token_endpoint_auth_method'] ?? 'none' ) ) {
			return self::error( 'invalid_client_metadata', 'Only public clients connect: token_endpoint_auth_method none.' );
		}
		$grants = $meta['grant_types'] ?? array( 'authorization_code' );
		if ( ! is_array( $grants ) || array_filter( $grants, 'is_string' ) !== $grants || ! in_array( 'authorization_code', $grants, true ) || array_diff( $grants, array( 'authorization_code', 'refresh_token' ) ) ) {
			return self::error( 'invalid_client_metadata', 'grant_types holds authorization_code and at most refresh_token.' );
		}
		return array(
			'client_name'   => $name,
			'redirect_uris' => array_values( array_unique( $uris ) ),
		);
	}

	/**
	 * Whether a redirect URI is allowed at all: https, or http to the loopback address; no fragment, no user.
	 *
	 * @param string $uri Redirect URI.
	 */
	public static function redirect_allowed( string $uri ): bool {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		return 'https' === $scheme || ( 'http' === $scheme && self::loopback( (string) ( $parts['host'] ?? '' ) ) );
	}

	/**
	 * Whether a redirect URI of a request is one of the client: exactly, or for loopback http on any port (RFC 8252,
	 * section 7.3).
	 *
	 * @param string               $uri    redirect_uri of the request.
	 * @param array<string, mixed> $client Client from find().
	 */
	public static function redirect_matches( string $uri, array $client ): bool {
		if ( '' === $uri ) {
			return false;
		}
		$asked = wp_parse_url( $uri );
		foreach ( (array) ( $client['redirect_uris'] ?? array() ) as $allowed ) {
			if ( $uri === $allowed ) {
				return true;
			}
			$known = wp_parse_url( (string) $allowed );
			if ( is_array( $known ) && is_array( $asked ) && 'http' === ( $known['scheme'] ?? '' ) && self::loopback( (string) ( $known['host'] ?? '' ) )
				&& array_diff_key( $known, array( 'port' => 0 ) ) === array_diff_key( $asked, array( 'port' => 0 ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a host is the loopback address of the computer.
	 *
	 * @param string $host Host.
	 */
	public static function loopback( string $host ): bool {
		return in_array( strtolower( trim( $host, '[]' ) ), array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * Dynamic Client Registration (RFC 7591): a public client with a new ID. Beyond MAX_REGISTERED the oldest without an
	 * open connection drops out; when every one has a connection, the new one is refused.
	 *
	 * @param array<string, mixed> $meta Client metadata of the request.
	 * @return array<string, mixed>|\WP_Error Registration response.
	 */
	public static function register( array $meta ) {
		$client = self::validate( $meta );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( strlen( (string) wp_json_encode( $client ) ) > self::MAX_REGISTRATION_BYTES ) {
			return self::error( 'invalid_client_metadata', 'The client metadata is larger than 4 KB.' );
		}
		$id         = 'lv-' . bin2hex( random_bytes( 16 ) );
		$all        = self::registered();
		$all[ $id ] = array(
			'client_id' => $id,
			'kind'      => 'dcr',
			'created'   => time(),
		) + $client;
		$connected  = array_flip( Tokens::clients() );
		foreach ( array_keys( $all ) as $key ) {
			if ( count( $all ) <= self::MAX_REGISTERED ) {
				break;
			}
			if ( ! isset( $connected[ $key ] ) ) {
				unset( $all[ $key ] );
			}
		}
		if ( ! isset( $all[ $id ] ) ) {
			return self::error( 'invalid_client_metadata', 'This site keeps no more registrations; use a client document.' );
		}
		update_option( self::OPTION, $all, false );
		return array(
			'client_id'                  => $id,
			'client_id_issued_at'        => time(),
			'client_name'                => $client['client_name'],
			'redirect_uris'              => $client['redirect_uris'],
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * Registrations of this site by client_id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function registered(): array {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * OAuth error with status 400.
	 *
	 * @param string $code        OAuth error code.
	 * @param string $description error_description, ASCII.
	 */
	private static function error( string $code, string $description ): \WP_Error {
		return new \WP_Error( $code, $description, array( 'status' => 400 ) );
	}
}
