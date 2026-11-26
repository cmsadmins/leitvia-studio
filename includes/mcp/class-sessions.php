<?php
/**
 * Sessions of MCP clients on revision 2025-11-25.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * A session starts with initialize and lives as a transient for 30 minutes after its last request. It belongs to the
 * user who started it; another user gets no session for its ID. Requests under 2026-07-28 never use one.
 */
final class Sessions {

	private const TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Revision the server answers an initialize with.
	 *
	 * @param string $requested Revision the client asks for.
	 */
	public static function negotiate( string $requested ): string {
		return in_array( $requested, Server::LEGACY_ACCEPTED, true ) ? $requested : Server::LEGACY;
	}

	/**
	 * Starts a session of the current user. It keeps only the MIME types of the extension of views (Apps), the one
	 * capability the server reads under 2025-11-25: up to 10 strings of up to 100 characters. Anything else the client
	 * declared stays out of the transient.
	 *
	 * @param string               $version      Negotiated revision.
	 * @param array<string, mixed> $capabilities Client capabilities from initialize.
	 * @return string Session ID for the header Mcp-Session-Id, empty without a signed-in user.
	 */
	public static function start( string $version, array $capabilities = array() ): string {
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return '';
		}
		$b    = random_bytes( 16 );
		$b[6] = chr( ord( $b[6] ) & 0x0f | 0x40 );
		$b[8] = chr( ord( $b[8] ) & 0x3f | 0x80 );
		$id   = vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
		set_transient(
			self::key( $id ),
			array(
				'version'      => $version,
				'user'         => $user,
				'capabilities' => self::kept( $capabilities ),
			),
			self::TTL
		);
		return $id;
	}

	/**
	 * The part of the client capabilities a session keeps: extensions.io.modelcontextprotocol/ui.mimeTypes.
	 *
	 * @param array<string, mixed> $capabilities Client capabilities from initialize.
	 * @return array<string, mixed>
	 */
	private static function kept( array $capabilities ): array {
		$types = $capabilities['extensions'][ Apps::EXTENSION ]['mimeTypes'] ?? null;
		$types = is_array( $types ) ? array_values( array_filter( $types, static fn( $type ): bool => is_string( $type ) && '' !== $type && strlen( $type ) <= 100 ) ) : array();
		if ( ! $types ) {
			return array();
		}
		return array( 'extensions' => array( Apps::EXTENSION => array( 'mimeTypes' => array_slice( array_values( array_unique( $types ) ), 0, 10 ) ) ) );
	}

	/**
	 * The session of the current user with this ID, its lifetime renewed; null when there is none.
	 *
	 * @param string $id Session ID.
	 * @return array{version: string, user: int, capabilities: array<string, mixed>}|null
	 */
	public static function get( string $id ): ?array {
		$session = self::owned( $id );
		if ( null === $session ) {
			return null;
		}
		set_transient( self::key( $id ), $session, self::TTL );
		return array(
			'version'      => (string) $session['version'],
			'user'         => (int) $session['user'],
			'capabilities' => (array) ( $session['capabilities'] ?? array() ),
		);
	}

	/**
	 * Ends a session of the current user (HTTP DELETE).
	 *
	 * @param string $id Session ID.
	 * @return bool Whether there was one.
	 */
	public static function end( string $id ): bool {
		return null !== self::owned( $id ) && delete_transient( self::key( $id ) );
	}

	/**
	 * The stored session when it belongs to the signed-in current user, without renewing it.
	 *
	 * @param string $id Session ID.
	 * @return array<string, mixed>|null
	 */
	private static function owned( string $id ): ?array {
		$session = get_transient( self::key( $id ) );
		$user    = get_current_user_id();
		return is_array( $session ) && $user > 0 && (int) ( $session['user'] ?? 0 ) === $user ? $session : null;
	}

	/**
	 * Transient name of a session; the ID itself is not stored as name.
	 *
	 * @param string $id Session ID.
	 */
	private static function key( string $id ): string {
		return 'leitvia_mcp_' . md5( $id );
	}
}
