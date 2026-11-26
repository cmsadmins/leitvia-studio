<?php
/**
 * Codes, access and refresh tokens of OAuth.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp\OAuth;

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Store;

defined( 'ABSPATH' ) || exit;

/**
 * One table per site ({prefix}leitvia_oauth_tokens, created by Store::install()). Each consent starts a family: its code
 * (60 seconds, once), then access tokens (1 hour) and refresh tokens (30 days, once each, rotated), for at most MAX_AGE
 * since the consent; the family ID starts with that time in hex. leitvia:approve, the scope of a person, gets no refresh
 * token: after an hour the person agrees again. A token is a prefix
 * and 32 random bytes; only its SHA-256 is stored. A code or refresh token used a second time closes its whole family,
 * since someone else may hold it (OAuth 2.1, section 4.3.1). A token counts only for the MCP server of the site it was
 * made on and only while its user keeps the role it was given for: the agent role, or manage_options without it for
 * leitvia:approve. Error texts are OAuth error descriptions, ASCII and untranslated.
 */
final class Tokens {

	public const CODE_TTL = 60;

	public const ACCESS_TTL = HOUR_IN_SECONDS;

	public const REFRESH_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Longest life of a family from its consent, refreshes included; filter leitvia_studio_oauth_max_age.
	 */
	public const MAX_AGE = 90 * DAY_IN_SECONDS;

	private const PREFIX = array(
		'code'    => 'lvac_',
		'access'  => 'lvat_',
		'refresh' => 'lvrt_',
	);

	/**
	 * Table of the current site.
	 */
	public static function table(): string {
		return Store::table( 'oauth_tokens' );
	}

	/**
	 * A new code for a consent; it starts a family.
	 *
	 * @param array<string, mixed> $grant user_id, client_id, client_name, scope, resource, redirect_uri, challenge.
	 */
	public static function issue_code( array $grant ): string {
		return self::insert( 'code', array( 'family' => sprintf( '%08x', time() ) . bin2hex( random_bytes( 12 ) ) ) + $grant, self::CODE_TTL );
	}

	/**
	 * Grant authorization_code: access and refresh token for a code.
	 *
	 * @param string $code         Code.
	 * @param string $client_id    client_id of the request.
	 * @param string $redirect_uri redirect_uri of the request; must equal the one of the authorization request, which
	 *                             always carries one (OAuth 2.1, section 4.1.3).
	 * @param string $verifier     code_verifier.
	 * @param string $target       resource of the request; empty means this server.
	 * @return array<string, mixed>|\WP_Error Token response or OAuth error.
	 */
	public static function exchange( string $code, string $client_id, string $redirect_uri, string $verifier, string $target ) {
		$row = self::find( $code, 'code' );
		if ( null === $row || (int) $row['revoked'] ) {
			return self::error( 'invalid_grant', 'The code is unknown or no longer valid.' );
		}
		if ( null !== $row['used_at'] || ! self::consume( (int) $row['id'] ) ) {
			self::revoke_family( (string) $row['family'], 'oauth/code-replay', 'rejected' );
			return self::error( 'invalid_grant', 'The code was already used; every token of this consent is revoked.' );
		}
		if ( self::expired( $row ) ) {
			return self::error( 'invalid_grant', 'The code has expired; codes are valid for 60 seconds.' );
		}
		if ( $client_id !== $row['client_id'] || $redirect_uri !== $row['redirect_uri'] ) {
			return self::error( 'invalid_grant', 'client_id or redirect_uri differ from the authorization request.' );
		}
		if ( ! self::pkce( $verifier, (string) $row['challenge'] ) ) {
			return self::error( 'invalid_grant', 'code_verifier does not match code_challenge.' );
		}
		if ( '' !== $target && ! OAuth::same_resource( $target ) ) {
			return self::error( 'invalid_target', 'resource is not the MCP server of this site.' );
		}
		if ( ! OAuth::same_resource( (string) $row['resource'] ) || ! self::subject_valid( $row ) ) {
			return self::error( 'invalid_grant', 'The user of this consent may not connect.' );
		}
		self::log( 'oauth/token', $row );
		return self::pair( $row );
	}

	/**
	 * Grant refresh_token: a new pair, the refresh token used up. Every check runs before the token is used up, so a
	 * request with a wrong scope or resource can be repeated with the right one.
	 *
	 * @param string $token     Refresh token.
	 * @param string $client_id client_id of the request.
	 * @param string $scope     Scope of the request; empty keeps the granted one.
	 * @param string $target    resource of the request; empty means this server.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function refresh( string $token, string $client_id, string $scope, string $target ) {
		$row = self::find( $token, 'refresh' );
		if ( null === $row || (int) $row['revoked'] || self::expired( $row ) || $client_id !== $row['client_id'] ) {
			return self::error( 'invalid_grant', 'The refresh token is unknown, expired, revoked or of another client.' );
		}
		if ( self::too_old( $row ) ) {
			self::revoke_family( (string) $row['family'] );
			return self::error( 'invalid_grant', 'The connection is older than its maximum age; connect again.' );
		}
		if ( null !== $row['used_at'] ) {
			self::revoke_family( (string) $row['family'], 'oauth/refresh-replay', 'rejected' );
			return self::error( 'invalid_grant', 'The refresh token was already used; every token of this connection is revoked.' );
		}
		if ( '' !== $target && ! OAuth::same_resource( $target ) ) {
			return self::error( 'invalid_target', 'resource is not the MCP server of this site.' );
		}
		$granted = explode( ' ', (string) $row['scope'] );
		$asked   = '' === trim( $scope ) ? $granted : array_values( array_unique( (array) preg_split( '/\s+/', trim( $scope ) ) ) );
		if ( array_diff( $asked, $granted ) ) {
			return self::error( 'invalid_scope', 'A refresh may narrow the scope, never widen it.' );
		}
		if ( ! OAuth::same_resource( (string) $row['resource'] ) || ! self::subject_valid( $row ) ) {
			self::revoke_family( (string) $row['family'] );
			return self::error( 'invalid_grant', 'The user of this connection may not connect any more.' );
		}
		if ( ! self::consume( (int) $row['id'] ) ) {
			self::revoke_family( (string) $row['family'], 'oauth/refresh-replay', 'rejected' );
			return self::error( 'invalid_grant', 'The refresh token was already used; every token of this connection is revoked.' );
		}
		$row['scope'] = implode( ' ', $asked );
		self::log( 'oauth/refresh', $row );
		return self::pair( $row );
	}

	/**
	 * The row of a valid access token for the MCP server of this site, null otherwise.
	 *
	 * @param string $token Access token.
	 * @return array<string, mixed>|null
	 */
	public static function access( string $token ): ?array {
		$row = self::find( $token, 'access' );
		if ( null === $row || (int) $row['revoked'] || self::expired( $row ) || self::too_old( $row ) || ! OAuth::same_resource( (string) $row['resource'] ) || ! self::subject_valid( $row ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * Notes the last use and the shortened IP address on the row, at most once a minute.
	 *
	 * @param array<string, mixed> $row Access token row.
	 */
	public static function touch( array $row ): void {
		global $wpdb;
		if ( null !== $row['last_used'] && strtotime( $row['last_used'] . ' UTC' ) > time() - MINUTE_IN_SECONDS ) {
			return;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_privacy_anonymize_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, no API exists.
			self::table(),
			array(
				'last_used' => gmdate( 'Y-m-d H:i:s' ),
				'ip'        => $ip,
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Revocation (RFC 7009): an access or refresh token of this client closes its family. Unknown tokens are no error.
	 *
	 * @param string $token     Token.
	 * @param string $client_id client_id of the request.
	 */
	public static function revoke_token( string $token, string $client_id ): void {
		$row = self::find( $token, 'refresh' ) ?? self::find( $token, 'access' );
		if ( null !== $row && $client_id === $row['client_id'] ) {
			self::revoke_family( (string) $row['family'] );
		}
	}

	/**
	 * Revokes every token of a family.
	 *
	 * @param string $family Family.
	 * @param string $action Audit action.
	 * @param string $result Audit result.
	 * @return int Rows revoked.
	 */
	public static function revoke_family( string $family, string $action = 'oauth/revoke', string $result = 'ok' ): int {
		global $wpdb;
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id, client_id, scope FROM %i WHERE family = %s LIMIT 1', self::table(), $family ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, no API exists.
		$count = (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked = 1 WHERE family = %s AND revoked = 0', self::table(), $family ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, no API exists.
		if ( $count && is_array( $row ) ) {
			self::log( $action, $row, $result );
		}
		return $count;
	}

	/**
	 * Whether a family still holds a valid access or refresh token: not revoked, not expired, not too old, its user
	 * still allowed.
	 *
	 * @param string $family Family.
	 */
	public static function active_family( string $family ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE family = %s AND revoked = 0 AND kind IN ('access', 'refresh') AND expires_at > %s LIMIT 1", self::table(), $family, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, a revocation must show at once.
		return is_array( $row ) && ! self::too_old( $row ) && self::subject_valid( $row );
	}

	/**
	 * Revokes every connection of a user, for example when the access of an agent is revoked.
	 *
	 * @param int $user_id User.
	 * @return int Connections closed.
	 */
	public static function revoke_user( int $user_id ): int {
		global $wpdb;
		$families = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT family FROM %i WHERE user_id = %d AND revoked = 0', self::table(), $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, no API exists.
		$closed   = 0;
		foreach ( $families as $family ) {
			$closed += self::revoke_family( (string) $family ) ? 1 : 0;
		}
		return $closed;
	}

	/**
	 * Open connections of this site, newest first: one per family with a valid access or refresh token.
	 *
	 * @return list<array<string, mixed>> id (family), client, host, user, scopes, created, last_used, ip.
	 */
	public static function connections(): array {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// Plain SQL that SQLite (Playground) runs as well: the address of the last call comes from a second query.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT family, MAX(user_id) AS user_id, MAX(client_id) AS client_id, MAX(client_name) AS client_name, MAX(scope) AS scope, MIN(created_at) AS created, MAX(last_used) AS last_used FROM %i WHERE revoked = 0 AND kind IN ('access', 'refresh') AND expires_at > %s GROUP BY family ORDER BY created DESC LIMIT 200", self::table(), $now ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read for the Agents screen only.
		$ips  = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT family, ip FROM %i WHERE revoked = 0 AND kind IN ('access', 'refresh') AND expires_at > %s AND last_used IS NOT NULL ORDER BY last_used DESC", self::table(), $now ), ARRAY_A ) as $hit ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read for the Agents screen only.
			$ips[ $hit['family'] ] ??= (string) $hit['ip'];
		}
		// Tokens of a user deleted before deleted_user closed them never work again (subject_valid()); they are not listed.
		$rows = array_filter( is_array( $rows ) ? $rows : array(), static fn( array $row ): bool => false !== get_userdata( (int) $row['user_id'] ) );
		return array_values(
			array_map(
				static function ( array $row ) use ( $ips ): array {
					$user = get_userdata( (int) $row['user_id'] );
					return array(
						'id'        => (string) $row['family'],
						'client'    => (string) $row['client_name'],
						'host'      => self::host( (string) $row['client_id'] ),
						'user'      => array(
							'id'   => (int) $row['user_id'],
							'name' => $user ? $user->display_name : '',
						),
						'scopes'    => explode( ' ', (string) $row['scope'] ),
						'created'   => (string) $row['created'],
						'last_used' => (string) ( $row['last_used'] ?? '' ),
						'ip'        => $ips[ $row['family'] ] ?? '',
					);
				},
				$rows
			)
		);
	}

	/**
	 * Clients with an open connection on this site, for the consent page and the registrations of Clients.
	 *
	 * @return list<string>
	 */
	public static function clients(): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT client_id FROM %i WHERE revoked = 0 AND kind IN ('access', 'refresh') AND expires_at > %s", self::table(), gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, a connection is never cached.
		return array_values( array_map( 'strval', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * Deletes rows a day after they expired. Runs on Store::CRON.
	 */
	public static function prune(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Audit entry of an OAuth event, without token: the user it concerns and the client.
	 *
	 * @param string               $action Action, for example oauth/grant.
	 * @param array<string, mixed> $row    Row or grant with user_id, client_id and scope.
	 * @param string               $result Result, ok or rejected.
	 */
	public static function log( string $action, array $row, string $result = 'ok' ): void {
		$host = self::host( (string) $row['client_id'] );
		Store::audit(
			array(
				'ability'     => $action,
				'result'      => $result,
				'code'        => 'rejected' === $result ? 'leitvia_oauth_replay' : '',
				'object_type' => 'user',
				'object_id'   => (int) $row['user_id'],
				'details'     => array(
					'client' => '' !== $host ? $host : (string) $row['client_id'],
					'scope'  => (string) ( $row['scope'] ?? '' ),
				),
			)
		);
	}

	/**
	 * Access and refresh token of a family.
	 *
	 * @param array<string, mixed> $row Row the pair follows.
	 * @return array<string, mixed>
	 */
	private static function pair( array $row ): array {
		$pair = array(
			'access_token' => self::insert( 'access', $row, self::ACCESS_TTL ),
			'token_type'   => 'Bearer',
			'expires_in'   => self::ACCESS_TTL,
			'scope'        => (string) $row['scope'],
		);
		if ( ! in_array( OAuth::APPROVE, explode( ' ', (string) $row['scope'] ), true ) ) {
			$pair['refresh_token'] = self::insert( 'refresh', $row, self::REFRESH_TTL );
		}
		return $pair;
	}

	/**
	 * Stores a new token of a family and returns it; only its hash stays.
	 *
	 * @param string               $kind  code, access or refresh.
	 * @param array<string, mixed> $grant family, user_id, client_id, client_name, scope, resource, redirect_uri, challenge.
	 * @param int                  $ttl   Seconds it is valid.
	 */
	private static function insert( string $kind, array $grant, int $ttl ): string {
		global $wpdb;
		$token = self::PREFIX[ $kind ] . bin2hex( random_bytes( 32 ) );
		$code  = 'code' === $kind;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, no API exists.
			self::table(),
			array(
				'token_hash'   => hash( 'sha256', $token ),
				'kind'         => $kind,
				'family'       => (string) $grant['family'],
				'user_id'      => (int) $grant['user_id'],
				'client_id'    => (string) $grant['client_id'],
				'client_name'  => (string) $grant['client_name'],
				'scope'        => (string) $grant['scope'],
				'resource'     => (string) $grant['resource'],
				'redirect_uri' => $code ? (string) $grant['redirect_uri'] : '',
				'challenge'    => $code ? (string) $grant['challenge'] : '',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $token;
	}

	/**
	 * Row of a token of a kind, null when there is none.
	 *
	 * @param string $token Token.
	 * @param string $kind  code, access or refresh.
	 * @return array<string, mixed>|null
	 */
	private static function find( string $token, string $kind ): ?array {
		global $wpdb;
		if ( ! str_starts_with( $token, self::PREFIX[ $kind ] ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s AND kind = %s', self::table(), hash( 'sha256', $token ), $kind ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, a token is never cached.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Uses a code or refresh token up; false when another request was first.
	 *
	 * @param int $id Row.
	 */
	private static function consume( int $id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_at = %s WHERE id = %d AND used_at IS NULL', self::table(), gmdate( 'Y-m-d H:i:s' ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table; the condition makes the use atomic.
	}

	/**
	 * Whether the family of a row is older than MAX_AGE since its consent.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function too_old( array $row ): bool {
		/**
		 * Filters the longest life of an OAuth connection from its consent, in seconds.
		 *
		 * @param int    $max_age   MAX_AGE, 90 days.
		 * @param string $client_id Client of the connection.
		 */
		$max = (int) apply_filters( 'leitvia_studio_oauth_max_age', self::MAX_AGE, (string) $row['client_id'] );
		return time() - (int) hexdec( substr( (string) $row['family'], 0, 8 ) ) > $max;
	}

	/**
	 * Whether a row has expired.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function expired( array $row ): bool {
		return (int) strtotime( $row['expires_at'] . ' UTC' ) <= time();
	}

	/**
	 * PKCE S256 (RFC 7636): the verifier of 43 to 128 characters whose SHA-256 in base64url is the challenge.
	 *
	 * @param string $verifier  code_verifier.
	 * @param string $challenge code_challenge of the grant.
	 */
	private static function pkce( string $verifier, string $challenge ): bool {
		if ( '' === $challenge || ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
			return false;
		}
		$hash = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of RFC 7636, no obfuscation.
		return hash_equals( $challenge, $hash );
	}

	/**
	 * Whether the user of a row may still work with it: leitvia:approve only a person with manage_options who is no
	 * agent, every other token only a user with the agent role.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function subject_valid( array $row ): bool {
		$user = get_userdata( (int) $row['user_id'] );
		if ( ! $user ) {
			return false;
		}
		$agent = in_array( Gateway::ROLE, (array) $user->roles, true );
		return in_array( OAuth::APPROVE, explode( ' ', (string) $row['scope'] ), true ) ? ! $agent && user_can( $user, 'manage_options' ) : $agent;
	}

	/**
	 * Host of a client document address, empty for a registered client.
	 *
	 * @param string $client_id client_id.
	 */
	private static function host( string $client_id ): string {
		return str_contains( $client_id, '://' ) ? (string) wp_parse_url( $client_id, PHP_URL_HOST ) : '';
	}

	/**
	 * OAuth error of the token endpoint.
	 *
	 * @param string $code        OAuth error code.
	 * @param string $description error_description, ASCII.
	 */
	private static function error( string $code, string $description ): \WP_Error {
		return new \WP_Error( $code, $description, array( 'status' => 400 ) );
	}
}
