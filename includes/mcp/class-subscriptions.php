<?php
/**
 * Subscriptions of MCP clients to changes of the patterns, revision 2026-07-28.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go out as JSON-RPC, never as HTML.

/**
 * The method subscriptions/listen holds one request open for WINDOW seconds as event stream: first
 * notifications/subscriptions/acknowledged with the subscription ID, then notifications/resources/list_changed and
 * notifications/resources/updated (leitvia://patterns) after a change of the patterns, a keep-alive comment every
 * KEEPALIVE seconds without one, at the end the result with resultType complete; the client listens again.
 *
 * The event source is one option with a counter. touch() raises it when a Lab pattern is saved or deleted and when a
 * pattern outside the contract is approved; check() raises it when the fingerprint of the registered patterns (names
 * and markup) differs from the stored one. The loop reads the option once a second past the object cache. Revision
 * 2025-11-25 gets no subscriptions: capabilities resources.subscribe and listChanged are false there.
 *
 * promptsListChanged sends notifications/prompts/list_changed when a plugin was switched on or off (Prompts::touch()).
 */
final class Subscriptions {

	public const OPTION = 'leitvia_studio_mcp_patterns';

	/**
	 * Transient that holds check() back for a minute.
	 */
	public const CHECKED = 'leitvia_mcp_patterns_checked';

	public const WINDOW = 55;

	public const KEEPALIVE = 15;

	public const MAX_STREAMS = 2;

	/**
	 * Transient with the open streams of the whole site (slot => end).
	 */
	private const ALL = 'leitvia_mcp_streams_all';

	private const PATTERNS = 'leitvia://patterns';

	/**
	 * Hooks of the event source. Runs from Server::boot().
	 */
	public static function boot(): void {
		add_action( 'admin_init', array( self::class, 'check' ) );
		add_action( 'leitvia_studio_patterns_changed', array( self::class, 'touch' ), 10, 0 );
		add_action( 'save_post_wp_block', array( self::class, 'touch' ), 10, 0 );
		add_action(
			'deleted_post',
			static function ( $id, $post ): void {
				if ( $post instanceof \WP_Post && 'wp_block' === $post->post_type ) {
					self::touch();
				}
			},
			10,
			2
		);
	}

	/**
	 * Compares the fingerprint of the registered patterns with the stored one, at most once a minute, and raises the
	 * counter when it differs. Runs on admin_init and with every MCP request; never on the front end, where loading
	 * the markup of every pattern would slow down each visit.
	 */
	public static function check(): void {
		if ( get_transient( self::CHECKED ) ) {
			return;
		}
		set_transient( self::CHECKED, 1, MINUTE_IN_SECONDS );
		$hash = self::fingerprint();
		if ( self::state()['fingerprint'] !== $hash ) {
			self::save( $hash );
		}
	}

	/**
	 * Raises the counter, the fingerprint stays.
	 */
	public static function touch(): void {
		self::save( self::state()['fingerprint'] );
	}

	/**
	 * Hash over the names and the markup of all registered patterns.
	 */
	public static function fingerprint(): string {
		$patterns = array();
		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$patterns[ (string) $pattern['name'] ] = md5( (string) ( $pattern['content'] ?? '' ) );
		}
		ksort( $patterns );
		return md5( (string) wp_json_encode( $patterns ) );
	}

	/**
	 * Stored fingerprint and counter, read past the object cache, so a loop that runs for a minute sees the writes of
	 * other requests.
	 *
	 * @return array{fingerprint: string, changed: int}
	 */
	public static function state(): array {
		global $wpdb;
		// A loop of one read per second must see the writes of other requests: past the object cache, one row by name.
		$state = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The loop must not read a cached value.
		return array(
			'fingerprint' => is_array( $state ) ? (string) ( $state['fingerprint'] ?? '' ) : '',
			'changed'     => is_array( $state ) ? (int) ( $state['changed'] ?? 0 ) : 0,
		);
	}

	/**
	 * A stream slot of the current user.
	 *
	 * @return string Slot ID for listen() and release().
	 * @throws Protocol_Error With status 429 when the user has MAX_STREAMS open.
	 */
	public static function acquire(): string {
		$now = time();
		$all = array_filter( (array) get_transient( self::ALL ), static fn( $until ): bool => (int) $until > $now );
		/**
		 * Filters how many subscription streams the whole site holds open at once.
		 *
		 * @param int $limit Default 4.
		 */
		$limit = max( 1, (int) apply_filters( 'leitvia_studio_mcp_stream_limit', 4 ) );
		$open  = array_filter( (array) get_transient( self::streams() ), static fn( $until ): bool => (int) $until > $now );
		if ( count( $open ) >= self::MAX_STREAMS || count( $all ) >= $limit ) {
			throw new Protocol_Error( -32600, 'Too many open subscription streams.', 429, array( 'max' => min( self::MAX_STREAMS, $limit ) ) );
		}
		$slot          = wp_generate_uuid4();
		$open[ $slot ] = $now + self::WINDOW + 15;
		// ponytail: read and write are not atomic; two parallel listens may share the last slot, a lock comes when that matters.
		set_transient( self::streams(), $open, self::WINDOW + 15 );
		$all[ $slot ] = $open[ $slot ];
		set_transient( self::ALL, $all, self::WINDOW + 15 );
		return $slot;
	}

	/**
	 * Frees a stream slot of the current user.
	 *
	 * @param string $slot Slot ID.
	 */
	public static function release( string $slot ): void {
		$open = (array) get_transient( self::streams() );
		$all  = (array) get_transient( self::ALL );
		if ( ! isset( $open[ $slot ] ) && ! isset( $all[ $slot ] ) ) {
			return;
		}
		unset( $open[ $slot ], $all[ $slot ] );
		set_transient( self::streams(), $open, self::WINDOW + 15 );
		set_transient( self::ALL, $all, self::WINDOW + 15 );
	}

	/**
	 * Runs one listen window and frees the slot at its end.
	 *
	 * @param array<string, mixed> $params Params of subscriptions/listen; the filter sits in params.notifications:
	 *                                     resourcesListChanged, promptsListChanged and resourceSubscriptions (URIs).
	 *                                     toolsListChanged is accepted; tools change only with a plugin update.
	 * @param mixed                $id     ID of the request.
	 * @param string               $slot   Slot from acquire().
	 * @param callable             $send   Writes one chunk of the stream: function ( string $chunk ): void.
	 * @param callable             $tick   Waits a second, false when the client is gone: function (): bool.
	 */
	public static function listen( array $params, $id, string $slot, callable $send, callable $tick ): void {
		try {
			// The subscription ID is the ID of the listen request (schema 2026-07-28).
			$meta    = array( 'io.modelcontextprotocol/subscriptionId' => $id );
			$filter  = is_array( $params['notifications'] ?? null ) ? $params['notifications'] : array();
			$list    = ! empty( $filter['resourcesListChanged'] );
			$updates = in_array( self::PATTERNS, (array) ( $filter['resourceSubscriptions'] ?? array() ), true );
			$prompts = ! empty( $filter['promptsListChanged'] );
			// The acknowledgment names the subset the server honors.
			$granted = array_filter(
				array(
					'resourcesListChanged'  => $list ? true : null,
					'resourceSubscriptions' => $updates ? array( self::PATTERNS ) : null,
					'promptsListChanged'    => $prompts ? true : null,
				),
				static fn( $value ): bool => null !== $value
			);
			$send(
				self::event(
					'notifications/subscriptions/acknowledged',
					array(
						'_meta'         => $meta,
						'notifications' => (object) $granted,
					)
				)
			);
			$seen         = self::state()['changed'];
			$seen_prompts = $prompts ? Prompts::changed() : 0;
			for ( $second = 1; $second <= self::WINDOW; $second++ ) {
				if ( ! $tick() ) {
					return;
				}
				if ( $prompts && Prompts::changed() !== $seen_prompts ) {
					$seen_prompts = Prompts::changed();
					$send( self::event( 'notifications/prompts/list_changed', array( '_meta' => $meta ) ) );
					continue;
				}
				$changed = self::state()['changed'];
				if ( $changed !== $seen ) {
					$seen = $changed;
					if ( $list ) {
						$send( self::event( 'notifications/resources/list_changed', array( '_meta' => $meta ) ) );
					}
					if ( $updates ) {
						$send(
							self::event(
								'notifications/resources/updated',
								array(
									'uri'   => self::PATTERNS,
									'_meta' => $meta,
								)
							)
						);
					}
				} elseif ( 0 === $second % self::KEEPALIVE ) {
					$send( ": keep-alive\n\n" );
				}
			}
			$send(
				self::data(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'result'  => array(
							'resultType' => 'complete',
							'_meta'      => array( 'io.modelcontextprotocol/serverInfo' => Server::info() ) + $meta,
						),
					)
				)
			);
		} finally {
			self::release( $slot );
		}
	}

	/**
	 * A notification as event.
	 *
	 * @param string               $method Method.
	 * @param array<string, mixed> $params Params.
	 */
	private static function event( string $method, array $params ): string {
		return self::data(
			array(
				'jsonrpc' => '2.0',
				'method'  => $method,
				'params'  => $params,
			)
		);
	}

	/**
	 * A JSON-RPC message as one data line of the stream.
	 *
	 * @param array<string, mixed> $message Message.
	 */
	private static function data( array $message ): string {
		return 'data: ' . wp_json_encode( $message ) . "\n\n";
	}

	/**
	 * Increases the counter.
	 *
	 * @param string $hash Fingerprint to keep.
	 */
	private static function save( string $hash ): void {
		update_option(
			self::OPTION,
			array(
				'fingerprint' => $hash,
				'changed'     => self::state()['changed'] + 1,
			),
			false
		);
	}

	/**
	 * Transient of the open streams of the current user.
	 */
	private static function streams(): string {
		return 'leitvia_mcp_streams_' . get_current_user_id();
	}
}
