<?php
/**
 * Time-limited token overlays: a valid token set laid over the base styles between a start and an end time.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Tokens;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the set of the running overlay at runtime on the front end only. Nothing is written into the global styles,
 * so the overlay ends with its end time or when Studio is deactivated.
 *
 * REST writes go through validate_rest(). Writes past REST (update_post_meta(), writes of add-ons) must call
 * check() first; current() ignores sets that fail the checks either way.
 */
final class Overlays {

	public const META    = '_leitvia_tokens_overlay';
	public const STATE   = 'leitvia_studio_overlay_state';
	public const WINDOWS = 'leitvia_studio_overlay_windows';

	/**
	 * Schema of the meta value: start and end as ISO 8601 date-times, stored in UTC.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'start' => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'required' => true,
				),
				'end'   => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'required' => true,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Normalizes start and end to UTC in the form 2026-10-17T08:00:00Z. Sanitize callback of the meta.
	 *
	 * @param mixed $value Meta value.
	 * @return array{start: string, end: string}|array{}
	 */
	public static function sanitize( $value ): array {
		$range = self::range( $value );
		return $range ? array_map( static fn( int $time ): string => gmdate( 'Y-m-d\TH:i:s\Z', $time ), $range ) : array();
	}

	/**
	 * Start and end of a meta value as timestamps.
	 *
	 * @param mixed $value Meta value.
	 * @return array{start: int, end: int}|null
	 */
	private static function range( $value ): ?array {
		if ( ! is_array( $value ) || ! is_string( $value['start'] ?? null ) || ! is_string( $value['end'] ?? null ) ) {
			return null;
		}
		$start = rest_parse_date( $value['start'] );
		$end   = rest_parse_date( $value['end'] );
		return false === $start || false === $end ? null : array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Time windows of all saved sets with an overlay, kept in a transient until a set or its overlay changes. Without
	 * overlays it holds an empty list, so a front-end request costs no query.
	 *
	 * @return array<int, array{start: int, end: int}> Window by set ID.
	 */
	private static function windows(): array {
		$windows = get_transient( self::WINDOWS );
		if ( is_array( $windows ) ) {
			return $windows;
		}
		$ids     = get_posts(
			array(
				'post_type'      => Token_Sets::POST_TYPE,
				'post_status'    => 'private',
				'fields'         => 'ids',
				// ponytail: ended overlays stay in the list; 100 sets with a schedule is far beyond one site's need.
				'posts_per_page' => 100,
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once per change of a set.
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$windows = array();
		foreach ( $ids as $id ) {
			$range = self::range( get_post_meta( (int) $id, self::META, true ) );
			if ( $range ) {
				$windows[ (int) $id ] = $range;
			}
		}
		set_transient( self::WINDOWS, $windows );
		return $windows;
	}

	/**
	 * Drops the cached windows. Runs on added_post_meta, updated_post_meta and deleted_post_meta.
	 *
	 * @param mixed  $meta_id   Meta ID or IDs.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function forget_on_meta( $meta_id, $object_id, $meta_key ): void {
		if ( self::META === $meta_key ) {
			delete_transient( self::WINDOWS );
		}
	}

	/**
	 * Drops the cached windows when a set changes its status or is deleted. Runs on transition_post_status and
	 * deleted_post.
	 *
	 * @param mixed $first  New status, or the deleted post ID.
	 * @param mixed $second Old status, or the deleted post.
	 * @param mixed $post   Post on a status change.
	 */
	public static function forget( $first = null, $second = null, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : $second;
		if ( $post instanceof \WP_Post && Token_Sets::POST_TYPE === $post->post_type && $first !== $second ) {
			delete_transient( self::WINDOWS );
		}
	}

	/**
	 * ID of the set whose overlay runs now, 0 for none. Fires leitvia_studio_overlay_changed when that changed since
	 * the last call, so page caches can be emptied without a cron job.
	 */
	public static function current(): int {
		$now     = time();
		$current = 0;
		foreach ( self::windows() as $id => $range ) {
			if ( $range['start'] <= $now && $now < $range['end'] && null !== self::settings( $id ) ) {
				$current = $id;
				break;
			}
		}
		// ponytail: two requests at the very same moment can both see the old state and fire twice; a lock in the
		// database would be the fix if a listener cannot take a repeated call.
		if ( (int) get_transient( self::STATE ) !== $current ) {
			set_transient( self::STATE, $current );
			/**
			 * Fires once when an overlay starts or ends, on the first front-end request that notices it.
			 *
			 * @param int $set_id ID of the set now laid over the base styles, 0 when none is.
			 */
			do_action( 'leitvia_studio_overlay_changed', $current );
		}
		return $current;
	}

	/**
	 * Settles the overlay of a front-end request once the request is parsed (REST requests end before). Theme.json data
	 * computed earlier in the request did not include an overlay, so the caches are emptied while one runs and on the
	 * request that ends one. Runs on parse_request, last.
	 */
	public static function refresh(): void {
		if ( ! self::applies() ) {
			return;
		}
		$before  = (int) get_transient( self::STATE );
		$current = self::current();
		// ponytail: a second request at the same transition sees the new state already and does not empty the caches;
		// harmless while core keeps the theme_json group non-persistent, a per-request flush would close it otherwise.
		if ( $current || $current !== $before ) {
			wp_clean_theme_json_cache();
		}
	}

	/**
	 * Whether overlays apply to this request: the front end once the request is parsed, never the admin, the REST API
	 * (the Site Editor reads and saves there, so it never sees overlay values as its base) or a Lab preview of a set.
	 */
	public static function applies(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is checked right here.
		$preview = isset( $_GET['leitvia_studio_preview'], $_GET['_wpnonce'] ) && ! empty( $_GET['set'] )
			&& current_user_can( 'edit_theme_options' )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'leitvia_studio_preview' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$applies = ! is_admin() && did_action( 'parse_request' ) && ! wp_is_serving_rest_request() && ! $preview;
		/**
		 * Filters whether token overlays apply to the current request.
		 *
		 * @param bool $applies True on the front end outside REST requests and token set previews.
		 */
		return (bool) apply_filters( 'leitvia_studio_overlay_applies', $applies );
	}

	/**
	 * Compiled settings of a set that passes the checks, null for an invalid one. Cached per document and theme.
	 *
	 * @param int $id Token set ID.
	 * @return array<string, mixed>|null
	 */
	private static function settings( int $id ): ?array {
		$json   = (string) get_post_meta( $id, Token_Sets::META, true );
		$key    = 'leitvia_studio_overlay_' . md5( get_stylesheet() . wp_get_theme()->get( 'Version' ) . $json );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'settings', $cached ) ) {
			return $cached['settings'];
		}
		$settings = null;
		if ( true === Token_Sets::validate( $json ) ) {
			$compiled = Dtcg::to_settings( (array) json_decode( $json, true ), leitvia_studio_contract() );
			$settings = is_array( $compiled ) ? $compiled : null;
		}
		set_transient( $key, array( 'settings' => $settings ), DAY_IN_SECONDS );
		return $settings;
	}

	/**
	 * Lays the settings of the running overlay over the user data. Filter wp_theme_json_data_user, priority 20.
	 *
	 * @param \WP_Theme_JSON_Data $data User theme.json data.
	 * @return \WP_Theme_JSON_Data
	 */
	public static function apply( \WP_Theme_JSON_Data $data ): \WP_Theme_JSON_Data {
		$id       = self::applies() ? self::current() : 0;
		$settings = $id ? self::settings( $id ) : null;
		if ( ! $settings ) {
			return $data;
		}
		return new \WP_Theme_JSON_Data( Compiler::lay_over( $data->get_data(), $settings ), 'custom' );
	}

	/**
	 * Lets shared caches keep a front-end page at most until the next start or end of a valid overlay, when that is
	 * within the hour. Browsers are not told anything. Filter wp_headers; a Cache-Control header set by others stays.
	 *
	 * @param array<string, string> $headers Response headers.
	 * @return array<string, string>
	 */
	public static function headers( array $headers ): array {
		if ( isset( $headers['Cache-Control'] ) || ! self::applies() ) {
			return $headers;
		}
		$now  = time();
		$next = PHP_INT_MAX;
		foreach ( self::windows() as $id => $range ) {
			foreach ( $range as $time ) {
				if ( $time > $now && $time - $now <= HOUR_IN_SECONDS && $time < $next && null !== self::settings( $id ) ) {
					$next = $time;
				}
			}
		}
		if ( PHP_INT_MAX !== $next ) {
			$headers['Cache-Control'] = 's-maxage=' . ( $next - $now );
		}
		return $headers;
	}

	/**
	 * Checks an overlay before it is stored: the end after the start, no overlap with another set's overlay and a token
	 * document that passes the checks.
	 *
	 * @param int    $id    Token set ID, 0 for a new set.
	 * @param mixed  $value Meta value with start and end.
	 * @param string $json  Token document of the set.
	 * @return true|WP_Error Error with status 422, or 400 for a malformed value.
	 */
	public static function check( int $id, $value, string $json ) {
		$range = self::range( $value );
		if ( ! $range ) {
			return new WP_Error( 'leitvia_studio_overlay_invalid', __( 'Start and end must be dates with a time.', 'leitvia-studio' ), array( 'status' => 400 ) );
		}
		if ( $range['end'] <= $range['start'] ) {
			return new WP_Error( 'leitvia_studio_overlay_range', __( 'The overlay must end after it starts.', 'leitvia-studio' ), array( 'status' => 422 ) );
		}
		foreach ( array_diff_key( self::windows(), array( $id => true ) ) as $other => $window ) {
			if ( $range['start'] < $window['end'] && $window['start'] < $range['end'] ) {
				return new WP_Error(
					'leitvia_studio_overlay_overlap',
					/* translators: %s: title of the other token set. */
					sprintf( __( 'The overlay overlaps the overlay of “%s”. Only one overlay can run at a time.', 'leitvia-studio' ), get_post_field( 'post_title', $other ) ),
					array( 'status' => 422 )
				);
			}
		}
		return Token_Sets::validate( $json );
	}

	/**
	 * Refuses an overlay that fails check(). Filter rest_pre_insert_leitvia_tokens.
	 *
	 * @param \stdClass|WP_Error $prepared Post prepared for the database.
	 * @param \WP_REST_Request   $request  Request.
	 * @return \stdClass|WP_Error
	 */
	public static function validate_rest( $prepared, \WP_REST_Request $request ) {
		$meta = $request['meta'];
		if ( is_wp_error( $prepared ) || ! is_array( $meta ) || empty( $meta[ self::META ] ) ) {
			return $prepared;
		}
		$id    = (int) ( $prepared->ID ?? 0 );
		$json  = isset( $meta[ Token_Sets::META ] ) ? (string) $meta[ Token_Sets::META ] : (string) get_post_meta( $id, Token_Sets::META, true );
		$valid = self::check( $id, $meta[ self::META ], $json );
		return is_wp_error( $valid ) ? $valid : $prepared;
	}
}
