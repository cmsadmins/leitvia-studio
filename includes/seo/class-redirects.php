<?php
/**
 * Permanent redirects of moved addresses.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * The setting redirects holds rows of from (a path of the site), status (301, 302 or 410) and to (a path of the site or
 * an https address, none for 410); a request of a from path answers with its status, before the template and before
 * canonical redirects: 301 and 302 lead to the target, 410 says the page is gone for good and shows the 404 template
 * of the theme. Paths compare without a trailing slash, case-insensitive. A from without a query matches its path
 * with any query; a from with a query (such as /?p=45 or /?attachment_id=123 of an old site) matches only that exact
 * query, in any order of its parameters, and wins over the row of the bare path. Rows are looked up by their key. sanitize() keeps valid
 * rows only and loops() finds rows that lead back to their own path, so leitvia/set-redirects refuses them. Inactive while an SEO plugin is active,
 * which usually brings a redirect manager of its own, so a request never meets two.
 */
final class Redirects {

	/**
	 * Most rows: enough for the old query addresses of a migrated site (?p=, ?page_id=, ?attachment_id=) beside its
	 * moved paths, small enough for the autoloaded setting (about 100 KB at most).
	 */
	public const MAX = 1000;

	/**
	 * Most steps followed when checking a chain of redirects.
	 */
	private const MAX_HOPS = 10;

	/**
	 * Statuses a row may answer with; the first is the default.
	 */
	public const STATUSES = array( 301, 302, 410 );

	/**
	 * Valid rows: from a path starting with /, with or without a query, not the bare start page; status 301 (when missing), 302 or 410; for 301 and
	 * 302 to a different path or an https address, no spaces or angle brackets; for 410 no target. Repeats of a from
	 * path keep the first row. At most MAX.
	 *
	 * @param mixed $rows Submitted rows.
	 * @return list<array{from: string, to?: string, status: int}>
	 */
	public static function sanitize( $rows ): array {
		$clean = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$from   = self::from( is_array( $row ) ? (string) ( $row['from'] ?? '' ) : '' );
			$to     = is_array( $row ) ? trim( (string) ( $row['to'] ?? '' ) ) : '';
			$to     = str_starts_with( $to, '/' ) ? '/' . ltrim( $to, '/' ) : $to;
			$status = is_array( $row ) && isset( $row['status'] ) ? $row['status'] : self::STATUSES[0];
			$status = is_numeric( $status ) && in_array( (int) $status, self::STATUSES, true ) ? (int) $status : 0;
			$target = 410 === $status ? '' === $to : preg_match( '#^(/|https://)[^\s<>"\']*$#D', $to ) && self::key( $to ) !== $from;
			$ok     = '' !== $from && '/' !== $from && ! isset( $clean[ $from ] ) && $status && $target;
			if ( $ok ) {
				$clean[ $from ] = 410 === $status ? array(
					'from'   => $from,
					'status' => $status,
				) : array(
					'from'   => $from,
					'to'     => $to,
					'status' => $status,
				);
			}
		}
		return array_slice( array_values( $clean ), 0, self::MAX );
	}

	/**
	 * From paths whose chain of redirects comes back to a path it passed. A row with 410 ends a chain.
	 *
	 * @param list<array{from: string, to?: string, status?: int}> $rows Rows after sanitize().
	 * @return list<string>
	 */
	public static function loops( array $rows ): array {
		$next = array();
		foreach ( $rows as $row ) {
			$next[ $row['from'] ] = 410 === ( $row['status'] ?? 301 ) ? '' : self::key( (string) ( $row['to'] ?? '' ) );
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
	 * Answers a request of a from path (with its exact query first, then the bare path) with the status of its row: 301 or 302 to its target, 410 with the 404 template
	 * of the theme. Runs on template_redirect, priority 0.
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
		$path  = '/' !== $home && ( $path === $home || str_starts_with( $path, $home . '/' ) ) ? '/' . ltrim( substr( $path, strlen( $home ) ), '/' ) : $path;
		$query = self::query( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared only.
		// Index by from: one array_column per request over at most MAX rows, then a hash lookup per candidate.
		// ponytail: built per request; a stored index (option keyed by from) if MAX grows well past a few thousand.
		$index = array_column( array_filter( $rows, 'is_array' ), null, 'from' );
		$row   = ( '' !== $query ? $index[ $path . '?' . $query ] ?? null : null ) ?? $index[ $path ] ?? null;
		if ( is_array( $row ) ) {
			$status = (int) ( $row['status'] ?? 301 );
			if ( 410 === $status ) {
				self::gone();
				return;
			}
			$to = (string) ( $row['to'] ?? '' );
			wp_safe_redirect( str_starts_with( $to, '/' ) ? home_url( $to ) : $to, 302 === $status ? 302 : 301, 'Leitvia Studio' );
			exit;
		}
	}

	/**
	 * A from as rows compare it: the path as path() makes it, and its query, if any, as query() makes it after a ?;
	 * '' for no valid from. The bare start page is no from, the start page with a query is.
	 *
	 * @param string $from From.
	 */
	public static function from( string $from ): string {
		$parts = explode( '?', trim( $from ), 2 );
		$path  = self::path( $parts[0] );
		$query = isset( $parts[1] ) ? self::query( $parts[1] ) : '';
		if ( '' === $path || ( isset( $parts[1] ) && '' === $query ) || ( '/' === $path && '' === $query ) ) {
			return '';
		}
		return '' === $query ? $path : $path . '?' . $query;
	}

	/**
	 * A query as rows compare it: parameters sorted by name, encoded the same way, '' for none or for one with spaces,
	 * angle brackets, quotes or a fragment.
	 *
	 * @param string $query Query without the ?.
	 */
	public static function query( string $query ): string {
		if ( '' === $query || preg_match( '#[\s<>"\'\#]#', rawurldecode( $query ) ) ) {
			return '';
		}
		parse_str( $query, $params );
		ksort( $params );
		return http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Marks the request as gone: the query becomes a 404, so the theme renders its 404 template, and the status line
	 * says 410. Search engines drop a 410 faster than a 404.
	 */
	public static function gone(): void {
		global $wp_query;
		$wp_query->set_404();
		add_filter( 'status_header', array( self::class, 'status_gone' ), 10, 2 );
		status_header( 410 );
		nocache_headers();
	}

	/**
	 * Filter status_header: a 404 that core sends after gone() becomes 410 again.
	 *
	 * @param string $header Status line.
	 * @param int    $code   Status code.
	 */
	public static function status_gone( $header, $code ): string {
		return 404 === (int) $code ? (string) str_replace( '404 ' . get_status_header_desc( 404 ), '410 ' . get_status_header_desc( 410 ), (string) $header ) : (string) $header;
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
	 * The from key of a target on this site: its path and query for a path or an address of the site, '' for another
	 * host.
	 *
	 * @param string $to Target.
	 */
	private static function key( string $to ): string {
		if ( preg_match( '#^https?://#i', $to ) ) {
			$host = (string) wp_parse_url( $to, PHP_URL_HOST );
			if ( strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
				return '';
			}
			$query = (string) wp_parse_url( $to, PHP_URL_QUERY );
			$to    = (string) wp_parse_url( $to, PHP_URL_PATH );
			$to    = ( '' !== $to ? $to : '/' ) . ( '' !== $query ? '?' . $query : '' );
		}
		$parts = explode( '?', $to, 2 );
		$query = isset( $parts[1] ) ? self::query( $parts[1] ) : '';
		return self::path( $parts[0] ) . ( '' !== $query ? '?' . $query : '' );
	}
}
