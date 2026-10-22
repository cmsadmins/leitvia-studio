<?php
/**
 * Verification of the front end after a live write.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Tokens\Contrast;

defined( 'ABSPATH' ) || exit;

/**
 * After a live write a cron event loads the page as a visitor sees it, on the own host only, and checks the HTTP
 * status, PHP messages of themes and plugins, one h1, the landmarks header, main and footer, raw block comments and
 * visible JSON, and the contract pairs on the active palette. The loaded request reports its PHP messages itself
 * (listen()), so nothing searches the HTML for error text.
 *
 * Result in the audit log: ok, failed or unverifiable (loopback blocked, timeout, no report). A failed write of an
 * agent at the level "autonomous" is rolled back through a change package (Packages::rollback(), with its conflict
 * check); otherwise, and when the rollback is refused, the package carries the result as a note for the inbox.
 * Unverifiable never rolls back.
 *
 * Only findings the write brought count. Contrast pairs that failed on the palette before the write (remember(), called
 * by Pipeline::run()) are known; when a changed post fails, a second request renders it in its snapshot state, and its
 * findings are known as well. A page whose findings are all known passes and lists them under known.
 */
final class Verify {

	public const CRON = 'leitvia_studio_verify';

	/**
	 * Query argument and header of the checked request; both carry the same one-time token.
	 */
	public const ARG = 'leitvia_verify';

	public const HEADER = 'X-Leitvia-Verify';

	/**
	 * Package meta with the last result for the inbox.
	 */
	public const NOTE_META = '_leitvia_package_verify';

	public const TIMEOUT = 10;

	/**
	 * Transient prefix: 'pending' while the request runs, then the list of its PHP messages.
	 */
	private const TRANSIENT = 'leitvia_verify_';

	/**
	 * PHP messages kept per request; deprecations do not count, they change nothing visitors see.
	 */
	private const MAX_MESSAGES = 20;

	/**
	 * PHP messages of the checked request so far.
	 *
	 * @var list<array<string, mixed>>
	 */
	private static array $messages = array();

	/**
	 * Keys of the contrast findings before the current write, see remember().
	 *
	 * @var list<string>
	 */
	private static array $known = array();

	/**
	 * Hooks. Runs from Gateway::boot() on plugins_loaded.
	 */
	public static function boot(): void {
		add_action( 'leitvia_studio_gateway_executed', array( self::class, 'schedule' ), 10, 2 );
		add_action( self::CRON, array( self::class, 'run' ) );
		self::listen();
	}

	/**
	 * Plans the check of a live write as a cron event; never requests anything in the write request. Rollbacks and
	 * the setting verify off plan nothing.
	 *
	 * @param Operation            $op     Operation.
	 * @param array<string, mixed> $result Result of Pipeline::run().
	 */
	public static function schedule( Operation $op, array $result ): void {
		$known       = self::$known;
		self::$known = array();
		if ( 'live' !== ( $result['mode'] ?? '' ) || 'leitvia/rollback' === $op->ability || empty( Gateway::settings()['verify'] ) ) {
			return;
		}
		wp_schedule_single_event(
			time(),
			self::CRON,
			array(
				array(
					'user'     => get_current_user_id(),
					'ability'  => $op->ability,
					'area'     => $op->area,
					'target'   => $op->target,
					'kinds'    => (array) $result['kinds'],
					'level'    => (string) $result['level'],
					'package'  => (int) $result['package'],
					'post'     => (int) ( $result['target']['post'] ?? 0 ),
					'hash'     => (string) $result['hash'],
					'snapshot' => (int) $result['snapshot'],
					'known'    => $known,
				),
			)
		);
	}

	/**
	 * Notes the contract pairs that fail on the palette before a write, for the check after it. Pipeline::run() calls
	 * it before every operation; cheap, no request.
	 */
	public static function remember(): void {
		self::$known = empty( Gateway::settings()['verify'] ) ? array() : array_map( array( self::class, 'key' ), self::contrast() );
	}

	/**
	 * Cron callback, see verify().
	 *
	 * @param array<string, mixed> $job Arguments from schedule().
	 */
	public static function run( array $job ): void {
		self::verify( $job );
	}

	/**
	 * Checks the page of a write and acts on the result as the user who wrote it.
	 *
	 * @param array<string, mixed> $job Arguments from schedule().
	 * @return array<string, mixed> Report: status, url, findings, and action (none, rolled_back, inbox).
	 */
	public static function verify( array $job ): array {
		$previous = get_current_user_id();
		wp_set_current_user( (int) ( $job['user'] ?? 0 ) );
		$post    = (int) ( $job['post'] ?? 0 );
		$url     = $post && is_post_publicly_viewable( $post ) ? (string) get_permalink( $post ) : home_url( '/' );
		$report  = self::only_new( self::check( $url ), $job, $url );
		$package = (int) ( $job['package'] ?? 0 );
		$action  = 'none';
		if ( 'ok' !== $report['status'] ) {
			$package = $package ? $package : self::package( $job );
			$action  = 'inbox';
			if ( 'failed' === $report['status'] && ! (int) $job['package'] && 'autonomous' === $job['level'] && $package ) {
				$rolled = Packages::rollback( $package );
				$action = is_wp_error( $rolled ) ? 'inbox' : 'rolled_back';
				if ( is_wp_error( $rolled ) ) {
					$report['rollback_error'] = $rolled->get_error_code();
				}
			}
			if ( $package ) {
				update_post_meta( $package, self::NOTE_META, wp_slash( $report + array( 'action' => $action ) ) );
			}
		}
		Store::audit(
			array(
				'ability'     => 'leitvia/verify',
				'result'      => $report['status'],
				'code'        => $report['code'],
				'object_type' => isset( $job['target']['option'] ) ? 'option' : (string) get_post_type( $post ),
				'object_id'   => $post,
				'package_id'  => $package,
				// Codes only: messages and texts can quote content, the audit log keeps none.
				'details'     => array(
					'action'   => $action,
					'findings' => array_values( array_unique( array_column( $report['findings'], 'code' ) ) ),
				),
			)
		);
		wp_set_current_user( $previous );
		return $report + array( 'action' => $action );
	}

	/**
	 * A failed report without the findings that existed before the write: the contrast keys of the job and, for a
	 * changed post with a snapshot, the findings of the page rendered in its snapshot state. Passes when nothing new
	 * is left; the known findings stay in the report under known.
	 *
	 * @param array<string, mixed> $report Report of check().
	 * @param array<string, mixed> $job    Arguments from schedule().
	 * @param string               $url    Checked URL.
	 * @return array<string, mixed>
	 */
	private static function only_new( array $report, array $job, string $url ): array {
		if ( 'failed' !== $report['status'] ) {
			return $report;
		}
		$known    = array_map( 'strval', (array) ( $job['known'] ?? array() ) );
		$post     = (int) ( $job['post'] ?? 0 );
		$snapshot = Store::get_snapshot( (int) ( $job['snapshot'] ?? 0 ) );
		if ( $snapshot && $post && (int) $snapshot['object_id'] === $post && 'option' !== $snapshot['object_type'] && isset( $snapshot['state']['post_content'] ) ) {
			$before = self::check( $url, array( $post => (array) $snapshot['state'] ) );
			$known  = array_merge( $known, array_map( array( self::class, 'key' ), $before['findings'] ) );
		}
		$new = array();
		$old = array();
		foreach ( $report['findings'] as $finding ) {
			if ( in_array( self::key( $finding ), $known, true ) ) {
				$old[] = $finding;
			} else {
				$new[] = $finding;
			}
		}
		$result          = $new ? self::report( 'failed', 'leitvia_verify_failed', $url, $new ) : self::report( 'ok', '', $url );
		$result['known'] = $old;
		return $result;
	}

	/**
	 * Key of a finding for the comparison before and after; the contrast ratio may shift without a new failure.
	 *
	 * @param array<string, mixed> $finding Finding.
	 */
	private static function key( array $finding ): string {
		unset( $finding['ratio'] );
		return (string) wp_json_encode( $finding );
	}

	/**
	 * Loads a URL of the own site as a visitor and checks it.
	 *
	 * @param string                           $url    URL; another host than home_url() ends the check without a request.
	 * @param array<int, array<string, mixed>> $states Post ID => post fields the request renders instead of the saved
	 *                                                 ones, for the state before a write; skips the palette check.
	 * @return array{status: string, code: string, url: string, findings: list<array<string, mixed>>}
	 */
	public static function check( string $url, array $states = array() ): array {
		$home = wp_parse_url( home_url() );
		$own  = wp_parse_url( $url );
		$key  = static fn( $parts ): string => is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) . ':' . ( $parts['port'] ?? '' ) : '';
		if ( '' === $key( $own ) || $key( $own ) !== $key( $home ) ) {
			return self::report( 'unverifiable', 'leitvia_verify_foreign_host', $url );
		}
		// Lowercase: listen() reads it with sanitize_key().
		$token = strtolower( wp_generate_password( 32, false ) );
		set_transient( self::TRANSIENT . $token, $states ? array( 'pending' => $states ) : 'pending', 5 * MINUTE_IN_SECONDS );
		$url = add_query_arg( self::ARG, $token, $url );
		// The one remote request of Studio: the own front end, host checked above (scripts/check-standards.mjs).
		$response = wp_safe_remote_get( $url, self::request_args( $token ) );
		// The other request wrote the transient; without an object cache this request still holds 'pending' in memory.
		wp_cache_delete( '_transient_' . self::TRANSIENT . $token, 'options' );
		$messages = get_transient( self::TRANSIENT . $token );
		delete_transient( self::TRANSIENT . $token );
		if ( is_wp_error( $response ) ) {
			return self::report( 'unverifiable', 'leitvia_verify_unreachable', $url );
		}
		$findings = array();
		$status   = (int) $response['response']['code'];
		if ( 200 !== $status ) {
			$findings[] = array(
				'code'   => 'http_status',
				'status' => $status,
			);
		}
		foreach ( is_array( $messages ) ? $messages : array() as $message ) {
			$findings[] = array( 'code' => 'php_message' ) + (array) $message;
		}
		$findings = array_merge( $findings, self::html( (string) $response['body'] ), $states ? array() : self::contrast() );
		if ( $findings ) {
			return self::report( 'failed', 'leitvia_verify_failed', $url, $findings );
		}
		// Without a report the response did not come from PHP, a page cache for example: nothing proves it is fine.
		return is_array( $messages ) ? self::report( 'ok', '', $url ) : self::report( 'unverifiable', 'leitvia_verify_no_report', $url );
	}

	/**
	 * Arguments of the check request: header with the token, 10 seconds, no redirects.
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>
	 */
	private static function request_args( string $token ): array {
		return array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array( self::HEADER => $token ),
		);
	}

	/**
	 * Findings in the HTML: not exactly one h1, a missing landmark, raw block comments, JSON in visible text outside
	 * pre and code.
	 *
	 * @param string $html Response body.
	 * @return list<array<string, mixed>>
	 */
	public static function html( string $html ): array {
		$tags      = new \WP_HTML_Tag_Processor( $html );
		$h1        = 0;
		$code      = 0;
		$landmarks = array();
		$findings  = array();
		$roles     = array(
			'banner'      => 'header',
			'main'        => 'main',
			'contentinfo' => 'footer',
		);
		while ( $tags->next_token() ) {
			$type = $tags->get_token_type();
			if ( '#tag' === $type ) {
				$name = (string) $tags->get_tag();
				if ( in_array( $name, array( 'PRE', 'CODE' ), true ) ) {
					$code += $tags->is_tag_closer() ? -1 : 1;
				}
				if ( $tags->is_tag_closer() ) {
					continue;
				}
				$h1  += 'H1' === $name ? 1 : 0;
				$role = $tags->get_attribute( 'role' );
				if ( in_array( $name, array( 'HEADER', 'MAIN', 'FOOTER' ), true ) ) {
					$landmarks[ strtolower( $name ) ] = true;
				} elseif ( is_string( $role ) && isset( $roles[ $role ] ) ) {
					$landmarks[ $roles[ $role ] ] = true;
				}
			} elseif ( '#comment' === $type && preg_match( '#^\s*/?wp:#', $tags->get_modifiable_text() ) ) {
				$findings['block_comment'] = array( 'code' => 'block_comment' );
			} elseif ( '#text' === $type && $code <= 0 && preg_match( '/[{\[]\s*"[\w-]+"\s*:/', $tags->get_modifiable_text() ) ) {
				$findings['visible_json'] = array( 'code' => 'visible_json' );
			}
		}
		if ( 1 !== $h1 ) {
			$findings[] = array(
				'code'  => 'h1_count',
				'count' => $h1,
			);
		}
		foreach ( array_diff( array( 'header', 'main', 'footer' ), array_keys( $landmarks ) ) as $missing ) {
			$findings[] = array(
				'code'     => 'landmark_missing',
				'landmark' => $missing,
			);
		}
		return array_values( $findings );
	}

	/**
	 * Contract pairs that fail on the active palette; colors that cannot be read are no finding.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function contrast(): array {
		$palette = wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
		$failing = array();
		foreach ( Contrast::check_palette( is_array( $palette ) ? $palette : array(), leitvia_studio_contract()->pairs() ) as $pair ) {
			if ( null !== $pair['ratio'] ) {
				$failing[] = array(
					'code'  => 'contrast',
					'fg'    => $pair['fg'],
					'bg'    => $pair['bg'],
					'ratio' => floor( $pair['ratio'] * 100 ) / 100,
					'min'   => $pair['min'],
				);
			}
		}
		return $failing;
	}

	/**
	 * In the checked request: when header and query argument carry a pending token, collects the PHP messages raised
	 * in files of themes and plugins; store() keeps them under the token at shutdown, fatal errors included. A token
	 * with post states renders those posts in that state, in memory only (filter the_posts).
	 */
	public static function listen(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time token, compared with a pending transient.
		$query   = isset( $_GET[ self::ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG ] ) ) : '';
		$token   = isset( $_SERVER['HTTP_X_LEITVIA_VERIFY'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_X_LEITVIA_VERIFY'] ) ) : '';
		$pending = '' === $token ? false : get_transient( self::TRANSIENT . $token );
		if ( '' === $token || ! hash_equals( $token, $query ) || ( 'pending' !== $pending && ! isset( $pending['pending'] ) ) ) {
			return;
		}
		// One use: a second request with the token, before or after this one ends, renders nothing of the snapshot.
		set_transient( self::TRANSIENT . $token, array(), 5 * MINUTE_IN_SECONDS );
		if ( is_array( $pending ) ) {
			$states = (array) $pending['pending'];
			add_filter( 'the_posts', static fn( $posts ) => self::with_states( (array) $posts, $states ) );
		}
		self::$messages = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Only in the checked request; returns false, so PHP handles every message as before.
		set_error_handler(
			static function ( int $type, string $message, string $file = '', int $line = 0 ): bool {
				// Messages silenced with @ count too; one the page already raised in its previous state is known, see only_new().
				if ( ! in_array( $type, array( E_DEPRECATED, E_USER_DEPRECATED ), true ) ) {
					self::record( $type, $message, $file, $line );
				}
				return false;
			}
		);
		register_shutdown_function( array( self::class, 'store' ), $token );
	}

	/**
	 * Copies of the posts with the fields of their states, see listen().
	 *
	 * @param array<int, \WP_Post|mixed>       $posts  Posts.
	 * @param array<int, array<string, mixed>> $states Post ID => post fields.
	 * @return array<int, \WP_Post|mixed>
	 */
	public static function with_states( array $posts, array $states ): array {
		foreach ( $posts as $index => $post ) {
			if ( ! $post instanceof \WP_Post || ! isset( $states[ $post->ID ] ) ) {
				continue;
			}
			$copy = clone $post;
			foreach ( array_diff( Pipeline::POST_FIELDS, array( 'post_type' ) ) as $field ) {
				if ( array_key_exists( $field, $states[ $post->ID ] ) ) {
					$copy->$field = $states[ $post->ID ][ $field ];
				}
			}
			$posts[ $index ] = $copy;
		}
		return $posts;
	}

	/**
	 * Shutdown of the checked request: adds a fatal error and stores the messages under the token.
	 *
	 * @param string $token Token.
	 */
	public static function store( string $token ): void {
		$last = error_get_last();
		if ( $last && in_array( $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			self::record( $last['type'], $last['message'], $last['file'], $last['line'] );
		}
		set_transient( self::TRANSIENT . $token, self::$messages, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Keeps a message raised in a file of a theme or plugin, path relative to the themes or plugins folder.
	 *
	 * @param int    $type    Error type.
	 * @param string $message Message.
	 * @param string $file    File.
	 * @param int    $line    Line.
	 */
	private static function record( int $type, string $message, string $file, int $line ): void {
		$file = wp_normalize_path( $file );
		foreach ( array( get_theme_root(), WP_PLUGIN_DIR, WPMU_PLUGIN_DIR ) as $dir ) {
			$root = trailingslashit( wp_normalize_path( $dir ) );
			if ( count( self::$messages ) < self::MAX_MESSAGES && str_starts_with( $file, $root ) ) {
				self::$messages[] = array(
					'type'    => $type,
					'message' => substr( $message, 0, 300 ),
					'file'    => substr( $file, strlen( $root ) ),
					'line'    => $line,
				);
				return;
			}
		}
	}

	/**
	 * A report.
	 *
	 * @param string                     $status   ok, failed or unverifiable.
	 * @param string                     $code     Code, '' for ok.
	 * @param string                     $url      Checked URL.
	 * @param list<array<string, mixed>> $findings Findings.
	 * @return array{status: string, code: string, url: string, findings: list<array<string, mixed>>}
	 */
	private static function report( string $status, string $code, string $url, array $findings = array() ): array {
		return array(
			'status'   => $status,
			'code'     => $code,
			'url'      => remove_query_arg( self::ARG, $url ),
			'findings' => $findings,
		);
	}

	/**
	 * The change package of a live write that had none, see Packages::record().
	 *
	 * @param array<string, mixed> $job Arguments from schedule().
	 */
	private static function package( array $job ): int {
		$op = new Operation( (string) $job['ability'], (string) $job['area'], (array) $job['target'], '', static fn( array $state ): array => $state, (array) $job['kinds'] );
		return Packages::record(
			$op,
			array(
				'level'    => (string) $job['level'],
				'kinds'    => (array) $job['kinds'],
				'target'   => array( 'post' => (int) $job['post'] ),
				'hash'     => (string) $job['hash'],
				'snapshot' => (int) $job['snapshot'],
			)
		);
	}
}
