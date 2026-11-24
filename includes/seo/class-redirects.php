<?php
/**
 * Permanent redirects of moved addresses.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * The setting redirects holds rows of from (a path of the site) and to (a path of the site or an https address); a
 * request of a from path answers 301 to its target, before the template and before canonical redirects. Paths compare
 * without the query and without a trailing slash, case-insensitive. sanitize() keeps valid rows only and loops() finds
 * rows that lead back to their own path, so leitvia/set-redirects refuses them. Inactive while an SEO plugin is active,
 * which usually brings a redirect manager of its own, so a request never meets two.
 */
final class Redirects {

	/**
	 * Most rows.
	 */
	public const MAX = 500;

	/**
	 * Most steps followed when checking a chain of redirects.
	 */
	private const MAX_HOPS = 10;

	/**
	 * Valid rows: from a path starting with /, not the start page, to a different path or an https address, no
	 * spaces or angle brackets; repeats of a from path keep the first row. At most MAX.
	 *
	 * @param mixed $rows Submitted rows.
	 * @return list<array{from: string, to: string}>
	 */
	public static function sanitize( $rows ): array {
		$clean = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$from = self::path( is_array( $row ) ? (string) ( $row['from'] ?? '' ) : '' );
			$to   = is_array( $row ) ? trim( (string) ( $row['to'] ?? '' ) ) : '';
			$to   = str_starts_with( $to, '/' ) ? '/' . ltrim( $to, '/' ) : $to;
			$ok   = '' !== $from && '/' !== $from && ! isset( $clean[ $from ] ) && preg_match( '#^(/|https://)[^\s<>"\']*$#D', $to ) && self::key( $to ) !== $from;
			if ( $ok ) {
				$clean[ $from ] = array(
					'from' => $from,
					'to'   => $to,
				);
			}
		}
		return array_slice( array_values( $clean ), 0, self::MAX );
	}

	/**
	 * From paths whose chain of redirects comes back to a path it passed.
	 *
	 * @param list<array{from: string, to: string}> $rows Rows after sanitize().
	 * @return list<string>
	 */
	public static function loops( array $rows ): array {
		$next = array();
		foreach ( $rows as $row ) {
			$next[ $row['from'] ] = self::key( $row['to'] );
		}
		$loops = array();
		foreach ( array_keys( $next ) as $start ) {
			$seen = array( $start => true );
			$at   = $next[ $start ];
			for ( $hop = 0; '' !== $at && $hop < self::MAX_HOPS; $hop++ ) {
				if ( isset( $seen[ $at ] ) ) {
					$loops[] = $start;
					break;
				}
				$seen[ $at ] = true;
				$at          = $next[ $at ] ?? '';
			}
		}
		return $loops;
	}

	/**
	 * Answers a request of a from path with 301 to its target. Runs on template_redirect, priority 0.
	 */
	public static function maybe_redirect(): void {
		if ( leitvia_studio_seo_plugin_active() ) {
			return;
		}
		$rows = (array) leitvia_studio_setting( 'redirects' );
		if ( ! $rows ) {
			return;
		}
		$path = self::path( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared only.
		$home = self::path( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		// A site in a subdirectory stores paths below its address.
		$path = '/' !== $home && str_starts_with( $path, $home . '/' ) ? substr( $path, strlen( $home ) ) : $path;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ( $row['from'] ?? '' ) === $path ) {
				$to = (string) $row['to'];
				wp_safe_redirect( str_starts_with( $to, '/' ) ? home_url( $to ) : $to, 301, 'Leitvia Studio' );
				exit;
			}
		}
	}

	/**
	 * A path as rows compare it: decoded, lowercase, one leading slash, no trailing slash ('/' for the root), '' for
	 * anything that is no path.
	 *
	 * @param string $path Path.
	 */
	public static function path( string $path ): string {
		$path = strtolower( rawurldecode( trim( $path ) ) );
		if ( ! str_starts_with( $path, '/' ) || preg_match( '#[\s<>"\'?\#]#', $path ) ) {
			return '';
		}
		$path = '/' . trim( (string) preg_replace( '#/+#', '/', $path ), '/' );
		return $path;
	}

	/**
	 * The from key of a target on this site: its path for a path or an address of the site, '' for another host.
	 *
	 * @param string $to Target.
	 */
	private static function key( string $to ): string {
		if ( preg_match( '#^https?://#i', $to ) ) {
			$host = (string) wp_parse_url( $to, PHP_URL_HOST );
			if ( strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
				return '';
			}
			$to = (string) wp_parse_url( $to, PHP_URL_PATH );
			$to = '' !== $to ? $to : '/';
		}
		return self::path( $to );
	}
}
