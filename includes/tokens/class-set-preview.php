<?php
/**
 * Front-end preview of a token set on any page of the site.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * ?leitvia_token_preview=<set ID>&leitvia_preview_key=<expiry>-<user ID>-<signature> renders any front-end address
 * with the token set laid over the global styles for this request only, as an activation would (colors, fonts and
 * their faces, sizes, widths, the inverse style). Nothing is saved; other visitors and other requests see the site as
 * it is. The key is an HMAC of set, expiry, user and site with a salt of the site, issued by url() to a user with
 * edit_theme_options and valid for at most a day. It works without a session, so an agent or a screenshot tool can
 * open it; it stops working when it expires, when the user loses edit_theme_options or when the set no longer passes
 * the checks. The page carries noindex, no-cache headers and DONOTCACHEPAGE, and links on it lead back to the site.
 */
final class Set_Preview {

	public const ARG = 'leitvia_token_preview';

	public const KEY = 'leitvia_preview_key';

	public const TTL = HOUR_IN_SECONDS;

	public const MAX_TTL = DAY_IN_SECONDS;

	/**
	 * Settings and document of the requested set, false before the first look, null when the request has none.
	 *
	 * @var array{settings: array<string, mixed>, doc: array<string, mixed>}|null|false
	 */
	private static $requested = false;

	/**
	 * Hooks of the preview. Runs from Plugin.
	 */
	public static function boot(): void {
		add_action( 'parse_request', array( self::class, 'prime' ), PHP_INT_MAX );
		add_filter( 'wp_theme_json_data_user', array( self::class, 'apply' ), 15 );
		add_filter( 'wp_headers', array( self::class, 'headers' ), 5 );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	/**
	 * Signed preview address of a set for the current user and its expiry, null when the user may not edit theme
	 * options.
	 *
	 * @param int    $set  Token set ID.
	 * @param string $path Path of an address of the site such as /about/, '' for the front page.
	 * @param int    $ttl  Seconds the address stays valid, at most MAX_TTL.
	 * @return array{url: string, expires: int}|null
	 */
	public static function url( int $set, string $path = '', int $ttl = self::TTL ): ?array {
		$user = get_current_user_id();
		if ( ! $user || ! current_user_can( 'edit_theme_options' ) ) {
			return null;
		}
		$expires = time() + max( 60, min( $ttl, self::MAX_TTL ) );
		$base    = home_url( '/' . ltrim( $path, '/' ) );
		return array(
			'url'     => add_query_arg(
				array(
					self::ARG => $set,
					self::KEY => $expires . '-' . $user . '-' . self::sign( $set, $expires, $user ),
				),
				$base
			),
			'expires' => $expires,
		);
	}

	/**
	 * Checks the request once it is parsed and, for a valid preview, sets DONOTCACHEPAGE for page cache plugins and
	 * empties the theme.json caches of the request, so global styles resolved before (or while the set was checked) get
	 * the set. Runs on parse_request, last.
	 */
	public static function prime(): void {
		if ( self::requested() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant of page cache plugins.
			}
			\WP_Theme_JSON_Resolver::clean_cached_data();
			wp_clean_theme_json_cache();
			Compiler::forget_weak();
		}
	}

	/**
	 * Lays the requested set over the user data, like Compiler::styles() does for an activation. Filter
	 * wp_theme_json_data_user, priority 15; overlays stay off during a preview (Overlays::applies()).
	 *
	 * @param \WP_Theme_JSON_Data $data User theme.json data.
	 * @return \WP_Theme_JSON_Data
	 */
	public static function apply( \WP_Theme_JSON_Data $data ): \WP_Theme_JSON_Data {
		// Before prime() the request is unchecked: checking here would resolve global styles inside this filter.
		$set = false === self::$requested ? null : self::$requested;
		// Checked again here: a request checked during parse_request may not have been known as REST yet.
		if ( ! $set || is_admin() || wp_is_serving_rest_request() ) {
			return $data;
		}
		return new \WP_Theme_JSON_Data( Compiler::inverse( Compiler::lay_over( $data->get_data(), $set['settings'] ), $set['doc'] ), 'custom' );
	}

	/**
	 * No-cache headers, noindex and no referrer during a preview. Filter wp_headers, before Overlays::headers(), which
	 * leaves a Cache-Control header alone. A shared cache that ignores both the query string and no-store (a CDN rule
	 * "cache everything, ignore query string") could still keep the page; such a rule breaks every preview of WordPress.
	 *
	 * @param array<string, string> $headers Response headers.
	 * @return array<string, string>
	 */
	public static function headers( array $headers ): array {
		if ( ! self::requested() ) {
			return $headers;
		}
		// The key in the address must not travel to other sites in the Referer header.
		return array_merge(
			$headers,
			array_filter( wp_get_nocache_headers() ),
			array(
				'X-Robots-Tag'    => 'noindex',
				'Referrer-Policy' => 'no-referrer',
			)
		);
	}

	/**
	 * Adds noindex during a preview. Filter wp_robots.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public static function robots( array $robots ): array {
		return self::requested() ? wp_robots_no_robots( $robots ) : $robots;
	}

	/**
	 * Whether this request is a valid preview, see requested().
	 */
	public static function active(): bool {
		return null !== self::requested();
	}

	/**
	 * Settings and document of the set this front-end request previews, null when it previews none or the key does
	 * not hold. Checked once per request.
	 *
	 * @return array{settings: array<string, mixed>, doc: array<string, mixed>}|null
	 */
	public static function requested(): ?array {
		if ( false !== self::$requested ) {
			return self::$requested;
		}
		self::$requested = null;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The signed key stands in for a nonce.
		if ( is_admin() || ! isset( $_GET[ self::ARG ], $_GET[ self::KEY ] ) || ! is_string( $_GET[ self::ARG ] ) || ! is_string( $_GET[ self::KEY ] ) || wp_is_serving_rest_request() ) {
			return null;
		}
		$set = absint( $_GET[ self::ARG ] );
		$key = sanitize_text_field( wp_unslash( $_GET[ self::KEY ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		self::$requested = self::check( $set, $key );
		return self::$requested;
	}

	/**
	 * Settings and document of a set for a key, null when the key is malformed, expired, issued too far ahead, signed
	 * for another set, user or site, or its user may no longer edit theme options, or when the set is no saved set that
	 * passes the checks.
	 *
	 * @param int    $set Token set ID.
	 * @param string $key Key from url().
	 * @return array{settings: array<string, mixed>, doc: array<string, mixed>}|null
	 */
	public static function check( int $set, string $key ): ?array {
		if ( ! $set || ! preg_match( '/^([1-9]\d{0,11})-([1-9]\d{0,19})-([a-f0-9]{64})$/D', $key, $match ) ) {
			return null;
		}
		list( , $expires, $user, $signature ) = $match;
		$now                                  = time();
		if ( (int) $expires < $now || (int) $expires > $now + self::MAX_TTL || ! hash_equals( self::sign( $set, (int) $expires, (int) $user ), $signature ) ) {
			return null;
		}
		if ( ! user_can( (int) $user, 'edit_theme_options' ) || Token_Sets::POST_TYPE !== get_post_type( $set ) || 'private' !== get_post_status( $set ) ) {
			return null;
		}
		$doc = Compiler::document( $set );
		if ( is_wp_error( $doc ) || true !== Token_Sets::validate( (string) get_post_meta( $set, Token_Sets::META, true ) ) ) {
			return null;
		}
		$settings = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		return is_array( $settings ) ? array(
			'settings' => $settings,
			'doc'      => $doc,
		) : null;
	}

	/**
	 * Forgets the request state, for tests that check several requests in one process.
	 */
	public static function forget(): void {
		self::$requested = false;
	}

	/**
	 * Signature of a key: HMAC-SHA256 over set, expiry, user and site.
	 *
	 * @param int $set     Token set ID.
	 * @param int $expires Expiry, Unix time.
	 * @param int $user    User ID.
	 */
	private static function sign( int $set, int $expires, int $user ): string {
		return hash_hmac( 'sha256', implode( '|', array( self::ARG, $set, $expires, $user, get_current_blog_id() ) ), wp_salt( 'nonce' ) );
	}
}
