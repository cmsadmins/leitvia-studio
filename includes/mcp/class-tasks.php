<?php
/**
 * Tasks extension io.modelcontextprotocol/tasks of revision 2026-07-28 for long-running abilities.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

use LeitviaStudio\Mcp\OAuth\Bearer;
use LeitviaStudio\Mcp\OAuth\Tokens;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go out as JSON-RPC, never as HTML.

/**
 * A tools/call of an ability with meta.long_running from a client that declares the extension gets a task handle
 * (resultType task) at once. Action Scheduler, or else WP-Cron, runs the ability as the user who called, and
 * tasks/get returns the state and at the end the tool result. Every other call runs in the request as before.
 *
 * A task lives as option leitvia_studio_mcp_task_<id> for TTL seconds and belongs to its user and, when an OAuth token
 * started it, to the connection (family) of that token: another user, another connection or a client without the
 * extension learns nothing about it, and a run whose connection was revoked fails. A user holds at most MAX_OPEN working
 * tasks, further calls run in the request. An hourly event deletes expired tasks. The lock of a run holds the time it
 * was taken, refreshed with each progress report; a lock older than the time limit of PHP was left by a run that died,
 * and its task fails. No tasks/list, the extension defines none. Tasks never wait
 * for input, questions come before the task as input_required, so tasks/update only acknowledges. tasks/cancel stops a
 * task that has not started; a running ability ends on its own. Progress of the ability goes into statusMessage,
 * since notifications/progress is not part of tasks. While a task runs, Gateway\Mcp::serving() is true, so a run
 * never settles packages as a person in the admin would.
 */
final class Tasks {

	public const EXTENSION = 'io.modelcontextprotocol/tasks';

	/**
	 * Hook of the runner, argument the task ID.
	 */
	public const HOOK = 'leitvia_studio_mcp_task';

	/**
	 * Hourly hook that deletes expired tasks.
	 */
	public const EXPIRE = 'leitvia_studio_mcp_task_expire';

	/**
	 * Working tasks a user may hold at once; another call runs in the request.
	 */
	public const MAX_OPEN = 3;

	/**
	 * Seconds a task is kept.
	 */
	public const TTL = HOUR_IN_SECONDS;

	private const POLL_MS = 2000;

	private const PREFIX = 'leitvia_studio_mcp_task_';

	/**
	 * ID of the task this request runs, '' outside a task.
	 *
	 * @var string
	 */
	private static string $current = '';

	/**
	 * Hooks. Runs from Server::boot().
	 */
	public static function boot(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( self::EXPIRE, array( self::class, 'prune' ) );
		add_action( Progress::ACTION, array( self::class, 'progress' ), 10, 3 );
	}

	/**
	 * Whether the client declared the extension on this request.
	 *
	 * @param Context $context Protocol state.
	 */
	public static function offered( Context $context ): bool {
		$extensions = $context->capabilities['extensions'] ?? null;
		return $context->modern && is_array( $extensions ) && isset( $extensions[ self::EXTENSION ] );
	}

	/**
	 * Whether a call of this ability becomes a task.
	 *
	 * @param \WP_Ability $ability Ability.
	 * @param Context     $context Protocol state.
	 */
	public static function wanted( \WP_Ability $ability, Context $context ): bool {
		return self::offered( $context ) && true === $ability->get_meta_item( 'long_running', false ) && self::open() < self::MAX_OPEN;
	}

	/**
	 * Whether the current request runs a task.
	 */
	public static function running(): bool {
		return '' !== self::$current;
	}

	/**
	 * Stores a task, plans its run and returns the task handle.
	 *
	 * @param string $tool      Tool name.
	 * @param mixed  $arguments Arguments of the call.
	 * @return array<string, mixed>
	 */
	public static function start( string $tool, $arguments ): array {
		// Unguessable: the ID is the handle of the stored result (random_bytes, not wp_generate_uuid4()).
		$id   = bin2hex( random_bytes( 16 ) );
		$now  = time();
		$task = array(
			'owner'     => get_current_user_id(),
			'family'    => (string) ( Bearer::token()['family'] ?? '' ),
			'tool'      => $tool,
			'arguments' => $arguments,
			'status'    => 'working',
			'message'   => '',
			'created'   => $now,
			'updated'   => $now,
			'started'   => false,
		);
		add_option( self::PREFIX . $id, $task, '', false );
		if ( ! wp_next_scheduled( self::EXPIRE ) ) {
			wp_schedule_event( $now + self::TTL, 'hourly', self::EXPIRE );
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $id ), 'leitvia-studio' );
		} else {
			wp_schedule_single_event( $now, self::HOOK, array( $id ) );
			// A request to wp-cron.php of this site only; without it the next visit starts the run. A site with
			// DISABLE_WP_CRON runs cron itself.
			if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
				spawn_cron();
			}
		}
		return array( 'resultType' => 'task' ) + self::view( $id, $task );
	}

	/**
	 * Runs a task as its user. Hook HOOK.
	 *
	 * @param string $id Task ID.
	 */
	public static function run( string $id ): void {
		// add_option() fails while the lock row exists, so two runners never start the same task. The record is read
		// again under the lock: a runner that waited for it must see started, or the cancellation.
		if ( null === self::load( $id ) || ! add_option( self::PREFIX . $id . '_lock', time(), '', false ) ) {
			return;
		}
		$task = self::load( $id );
		if ( null === $task || 'working' !== $task['status'] || ! empty( $task['started'] ) ) {
			delete_option( self::PREFIX . $id . '_lock' );
			return;
		}
		if ( '' !== (string) ( $task['family'] ?? '' ) && ! Tokens::active_family( (string) $task['family'] ) ) {
			// The person revoked the connection that started the task before it ran.
			self::finish(
				$id,
				array(
					'status' => 'failed',
					'error'  => array(
						'code'    => -32603,
						'message' => 'The connection that started the task was revoked.',
					),
				)
			);
			delete_option( self::PREFIX . $id . '_lock' );
			return;
		}
		self::save( $id, array( 'started' => true ) + $task );
		$previous = get_current_user_id();
		wp_set_current_user( (int) $task['owner'] );
		self::$current = $id;
		try {
			$ability = Tools::abilities()[ (string) $task['tool'] ] ?? null;
			// The add-on of the tool may have gone since the call.
			$fields = null === $ability
				? array(
					'status'  => 'failed',
					'message' => sprintf( 'Unknown tool: %s', (string) $task['tool'] ),
					'error'   => array(
						'code'    => -32602,
						'message' => sprintf( 'Unknown tool: %s', (string) $task['tool'] ),
					),
				)
				: array(
					'status' => 'completed',
					'result' => Tools::execute( $ability, $task['arguments'], new Context( Server::LATEST, true ) ) + array( 'resultType' => 'complete' ),
				);
			self::finish( $id, $fields );
		} catch ( \Throwable $e ) {
			// No details of the failure leave the site.
			self::finish(
				$id,
				array(
					'status' => 'failed',
					'error'  => array(
						'code'    => -32603,
						'message' => 'Internal error',
					),
				)
			);
		} finally {
			self::$current = '';
			wp_set_current_user( $previous );
			delete_option( self::PREFIX . $id . '_lock' );
		}
	}

	/**
	 * Result of tasks/get.
	 *
	 * @param array<string, mixed> $params  Params with taskId.
	 * @param Context              $context Protocol state.
	 * @return array<string, mixed>
	 * @throws Protocol_Error Without the extension or for a task that is not the user's.
	 */
	public static function get( array $params, Context $context ): array {
		[ $id, $task ] = self::owned( $params, $context );
		return self::view( $id, $task );
	}

	/**
	 * Result of tasks/update: no task of this server waits for input, so there is nothing to take.
	 *
	 * @param array<string, mixed> $params  Params with taskId and inputResponses.
	 * @param Context              $context Protocol state.
	 * @return array{}
	 * @throws Protocol_Error Without the extension or for a task that is not the user's.
	 */
	public static function update( array $params, Context $context ): array {
		self::owned( $params, $context );
		return array();
	}

	/**
	 * Result of tasks/cancel: a task that has not started is cancelled and never runs.
	 *
	 * @param array<string, mixed> $params  Params with taskId.
	 * @param Context              $context Protocol state.
	 * @return array{}
	 * @throws Protocol_Error Without the extension or for a task that is not the user's.
	 */
	public static function cancel( array $params, Context $context ): array {
		[ $id, $task ] = self::owned( $params, $context );
		if ( 'working' === $task['status'] && empty( $task['started'] ) ) {
			// The lock of run() makes queued to cancelled atomic: while a runner holds it, the task is not cancelled,
			// and a runner that finds the task cancelled under the lock leaves.
			if ( add_option( self::PREFIX . $id . '_lock', time(), '', false ) ) {
				try {
					$fresh = self::load( $id );
					if ( null !== $fresh && 'working' === $fresh['status'] && empty( $fresh['started'] ) ) {
						wp_clear_scheduled_hook( self::HOOK, array( $id ) );
						if ( function_exists( 'as_unschedule_action' ) ) {
							as_unschedule_action( self::HOOK, array( $id ), 'leitvia-studio' );
						}
						self::finish(
							$id,
							array(
								'status'  => 'cancelled',
								'message' => __( 'Cancelled before it started.', 'leitvia-studio' ),
							)
						);
					}
				} finally {
					delete_option( self::PREFIX . $id . '_lock' );
				}
			}
		}
		return array();
	}

	/**
	 * Notes the progress of the running ability as status message. Action leitvia_studio_progress.
	 *
	 * @param int|float $done    Steps done.
	 * @param int|float $total   Steps in all, 0 when unknown.
	 * @param string    $message What happens.
	 */
	public static function progress( $done, $total = 0, $message = '' ): void {
		if ( '' === self::$current ) {
			return;
		}
		$text = '' !== (string) $message ? (string) $message : ( $total > 0 ? sprintf( '%s / %s', $done, $total ) : (string) $done );
		// The run is alive, its lock stays fresh.
		update_option( self::PREFIX . self::$current . '_lock', time(), false );
		self::finish( self::$current, array( 'message' => $text ) );
	}

	/**
	 * Deletes the expired tasks. Hook EXPIRE, hourly.
	 */
	public static function prune(): void {
		foreach ( self::ids() as $id ) {
			self::load( $id );
		}
	}

	/**
	 * Deletes a task.
	 *
	 * @param string $id Task ID.
	 */
	public static function forget( string $id ): void {
		delete_option( self::PREFIX . $id );
		delete_option( self::PREFIX . $id . '_lock' );
	}

	/**
	 * ID and record of a task of the current user.
	 *
	 * @param array<string, mixed> $params  Params with taskId.
	 * @param Context              $context Protocol state.
	 * @return array{0: string, 1: array<string, mixed>}
	 * @throws Protocol_Error Without the extension, or for an unknown, expired or foreign task.
	 */
	private static function owned( array $params, Context $context ): array {
		if ( ! self::offered( $context ) ) {
			throw new Protocol_Error( Server::MISSING_CAPABILITY, 'Missing required client capability', 400, array( 'requiredCapabilities' => array( 'extensions' => array( self::EXTENSION => new \stdClass() ) ) ) );
		}
		$id     = is_string( $params['taskId'] ?? null ) ? $params['taskId'] : '';
		$task   = self::load( $id );
		$family = (string) ( $task['family'] ?? '' );
		if ( null === $task || get_current_user_id() !== (int) $task['owner'] || ( '' !== $family && (string) ( Bearer::token()['family'] ?? '' ) !== $family ) ) {
			throw new Protocol_Error( -32602, 'Failed to retrieve task: Task not found', 404 );
		}
		return array( $id, $task );
	}

	/**
	 * Task as the extension shows it.
	 *
	 * @param string               $id   Task ID.
	 * @param array<string, mixed> $task Record.
	 * @return array<string, mixed>
	 */
	private static function view( string $id, array $task ): array {
		$view = array(
			'taskId'         => $id,
			'status'         => (string) $task['status'],
			'createdAt'      => gmdate( 'Y-m-d\TH:i:s\Z', (int) $task['created'] ),
			'lastUpdatedAt'  => gmdate( 'Y-m-d\TH:i:s\Z', (int) $task['updated'] ),
			'ttlMs'          => self::TTL * 1000,
			'pollIntervalMs' => self::POLL_MS,
		);
		if ( '' !== (string) $task['message'] ) {
			$view['statusMessage'] = (string) $task['message'];
		}
		if ( 'completed' === $task['status'] ) {
			$view['result'] = $task['result'];
		} elseif ( 'failed' === $task['status'] ) {
			$view['error'] = $task['error'];
		}
		return $view;
	}

	/**
	 * Record of a task, read past the object cache; null for an invalid ID, a missing or an expired task.
	 *
	 * @phpstan-impure
	 *
	 * @param string $id Task ID.
	 * @return array<string, mixed>|null
	 */
	private static function load( string $id ): ?array {
		if ( ! preg_match( '/^[0-9a-f]{32}$/D', $id ) ) {
			return null;
		}
		wp_cache_delete( self::PREFIX . $id, 'options' );
		$task = get_option( self::PREFIX . $id );
		if ( ! is_array( $task ) ) {
			return null;
		}
		$age  = self::lock_age( $id );
		$runs = 'working' === $task['status'] && ! empty( $task['started'] );
		if ( $runs && null !== $age && $age > self::stale() ) {
			// The run died (fatal error, timeout) before its finally.
			$task = array(
				'status'  => 'failed',
				'updated' => time(),
				'error'   => array(
					'code'    => -32603,
					'message' => 'Internal error',
				),
			) + $task;
			self::save( $id, $task );
			delete_option( self::PREFIX . $id . '_lock' );
			$runs = false;
		}
		// A run with a fresh lock keeps its task past TTL.
		if ( (int) $task['created'] + self::TTL < time() && ! ( $runs && null !== $age ) ) {
			self::forget( $id );
			return null;
		}
		return $task;
	}

	/**
	 * Seconds since the lock of a task was taken or refreshed, null without a lock. Read past the object cache.
	 *
	 * @param string $id Task ID.
	 */
	private static function lock_age( string $id ): ?int {
		global $wpdb;
		$lock = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . $id . '_lock' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Another process holds the lock, a cached value would be stale.
		return null === $lock ? null : time() - (int) $lock;
	}

	/**
	 * Seconds after which a lock was left by a run that died: the time limit of PHP, at least 300, plus 60.
	 */
	private static function stale(): int {
		return max( (int) ini_get( 'max_execution_time' ), 300 ) + 60;
	}

	/**
	 * IDs of all stored tasks.
	 *
	 * @return list<string>
	 */
	private static function ids(): array {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- No API lists options by prefix.
		$ids   = array();
		foreach ( $names as $name ) {
			$id = substr( (string) $name, strlen( self::PREFIX ) );
			if ( preg_match( '/^[0-9a-f]{32}$/D', $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Working tasks of the current user.
	 */
	private static function open(): int {
		$user = get_current_user_id();
		$open = 0;
		// ponytail: reads every task row of the site; an index per user comes when sites hold many tasks.
		foreach ( self::ids() as $id ) {
			$task  = self::load( $id );
			$open += null !== $task && $user === (int) $task['owner'] && 'working' === $task['status'] ? 1 : 0;
		}
		return $open;
	}

	/**
	 * Writes fields into a task and its update time.
	 *
	 * @param string               $id     Task ID.
	 * @param array<string, mixed> $fields Fields.
	 */
	private static function finish( string $id, array $fields ): void {
		$task = self::load( $id );
		if ( null !== $task ) {
			self::save( $id, $fields + array( 'updated' => time() ) + $task );
		}
	}

	/**
	 * Stores a task.
	 *
	 * @param string               $id   Task ID.
	 * @param array<string, mixed> $task Record.
	 */
	private static function save( string $id, array $task ): void {
		update_option( self::PREFIX . $id, $task, false );
	}
}
