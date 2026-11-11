<?php
/**
 * Registration helpers for gateway abilities.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Registers gateway abilities through the Studio registry abilities: read() for reading, write() for everything that
 * changes the site. Core turns a WP_Error of permission_callback into a generic error outside REST, so the permission
 * callback only answers yes or no; rate limit and every other rejection come from the execute callback with their
 * data intact.
 */
final class Abilities {

	/**
	 * Whether the operations of a batch run, see execute_batch(): the call counted once, so they skip the rate limit.
	 *
	 * @var bool
	 */
	private static bool $batching = false;

	/**
	 * Registers a read ability in the group read. Call on leitvia_studio_init.
	 *
	 * @param string               $name          Ability name, leitvia/<name>.
	 * @param string               $label         Translated label.
	 * @param string               $description   Translated description.
	 * @param array<string, mixed> $input_schema  JSON schema of the input; core does not cast it outside REST.
	 * @param array<string, mixed> $output_schema JSON schema of the output.
	 * @param callable             $callback      Receives the input, returns the output or a WP_Error.
	 * @param bool                 $open_world    Whether the ability may reach a service outside the site.
	 * @param callable|null        $permission    Permission callback for an ability that needs more than can_use(); it
	 *                                            answers yes or no like can_use().
	 * @return bool Whether the registry accepted the ability.
	 * @phpstan-param lowercase-string&non-falsy-string $name
	 */
	public static function read( string $name, string $label, string $description, array $input_schema, array $output_schema, callable $callback, bool $open_world = false, ?callable $permission = null ): bool {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'output_schema'       => $output_schema,
			'permission_callback' => $permission ?? array( self::class, 'can_use' ),
			'execute_callback'    => static fn( $input = null ) => self::run( $name, $callback, $input ),
			'meta'                => array(
				// The MCP Adapter reads openWorldHint, not open_world.
				'annotations'  => array(
					'readonly'      => true,
					'destructive'   => false,
					'idempotent'    => true,
					'openWorldHint' => $open_world,
				),
				'show_in_rest' => true,
			),
		);
		if ( $input_schema ) {
			// Without a required input a call without any (GET with no query arguments) reads with the defaults. An empty
			// object, not an empty array: core validates the default against type object, and [] encodes as a list.
			$args['input_schema'] = $input_schema + ( empty( $input_schema['required'] ) && 'object' === ( $input_schema['type'] ?? '' ) ? array( 'default' => (object) array() ) : array() );
		}
		return leitvia_studio()->registry( 'abilities' )->register(
			$name,
			array(
				'args'  => $args,
				'group' => 'read',
			),
			'leitvia-studio'
		);
	}

	/**
	 * Registers a write ability, the only way to do so. Its execute callback describes the operation and hands it to
	 * Pipeline::run(); the input schema gets the required base_hash and the optional package.
	 *
	 * The describe callback gets the input and returns array( 'area' => …, 'target' => …, 'change' => callable,
	 * 'kinds' => list ) or a WP_Error, see Operation. Keep it cheap: it runs before the rate limit, the change runs
	 * after scope and hash checks. Optional: 'record' => true gives a live write a change package so leitvia/rollback
	 * can undo it; 'report' => callable adds what it returns after the write as report to the result; 'base_hash' stands
	 * in for the input where the target is a single value no read ability returns a hash of. Instead of one operation it
	 * may return array( 'batch' => list ) with one such description (or a WP_Error) per item, each with its base_hash,
	 * see execute_batch().
	 *
	 * @param string               $name         Ability name, leitvia/<name>.
	 * @param string               $label        Translated label.
	 * @param string               $description  Translated description.
	 * @param string               $group        setup, build or maintain.
	 * @param array<string, bool>  $annotations  destructive and idempotent; readonly is always false, openWorldHint false
	 *                                           unless given.
	 * @param array<string, mixed> $input_schema JSON schema of the input, an object.
	 * @param callable             $describe     Describes the operation.
	 * @param bool                 $hash_needed  Whether base_hash is required; false for an ability whose list of items
	 *                                           carries a hash per item.
	 * @return bool Whether the registry accepted the ability.
	 * @phpstan-param lowercase-string&non-falsy-string $name
	 */
	public static function write( string $name, string $label, string $description, string $group, array $annotations, array $input_schema, callable $describe, bool $hash_needed = true ): bool {
		$schema                            = $input_schema + array(
			'type'       => 'object',
			'properties' => array(),
		);
		$schema['properties']['base_hash'] = array(
			'type'        => 'string',
			'description' => __( 'Hash of the target as last read; empty for a new object.', 'leitvia-studio' ),
		);
		$schema['properties']['package']   = array(
			'type'        => 'string',
			'maxLength'   => 64,
			'description' => __( 'Key of the task: a write with a key always goes into the change package of that key, even where the autonomy would allow it live, and operations with the same key share that package.', 'leitvia-studio' ),
		);
		$schema['required']                = array_values( array_unique( array_merge( (array) ( $schema['required'] ?? array() ), $hash_needed ? array( 'base_hash' ) : array() ) ) );
		return leitvia_studio()->registry( 'abilities' )->register(
			$name,
			array(
				'args'  => array(
					'label'               => $label,
					'description'         => $description,
					'input_schema'        => $schema,
					'output_schema'       => self::write_output(),
					'permission_callback' => array( self::class, 'can_use' ),
					'execute_callback'    => static fn( $input = null ) => self::execute_write( $name, $describe, is_array( $input ) ? $input : array() ),
					'meta'                => array(
						'annotations'  => array( 'readonly' => false ) + $annotations + array( 'openWorldHint' => false ),
						'show_in_rest' => true,
					),
				),
				'group' => $group,
			),
			'leitvia-studio'
		);
	}

	/**
	 * Describes the operation and runs it through the pipeline. A rejection of the description is audited like a read.
	 *
	 * @param string               $name     Ability name.
	 * @param callable             $describe Describe callback.
	 * @param array<string, mixed> $input    Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute_write( string $name, callable $describe, array $input ) {
		$parts = $describe( $input );
		if ( is_wp_error( $parts ) ) {
			return self::run( $name, static fn() => $parts, $input );
		}
		if ( isset( $parts['batch'] ) ) {
			return self::execute_batch( $name, array_values( (array) $parts['batch'] ), (string) ( $input['package'] ?? '' ) );
		}
		$op     = new Operation(
			$name,
			(string) ( $parts['area'] ?? '' ),
			(array) ( $parts['target'] ?? array() ),
			(string) ( $parts['base_hash'] ?? $input['base_hash'] ?? '' ),
			$parts['change'],
			array_values( (array) ( $parts['kinds'] ?? array() ) ),
			(string) ( $input['package'] ?? '' )
		);
		$result = Pipeline::run( $op );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $parts['record'] ) && 'live' === $result['mode'] && ! $result['package'] ) {
			$result['package'] = Packages::record( $op, $result );
		}
		if ( ! empty( $parts['notice'] ) ) {
			$result['notice'] = (string) $parts['notice'];
		}
		if ( isset( $parts['report'] ) ) {
			$result['report'] = (array) ( $parts['report'] )();
		}
		return $result;
	}

	/**
	 * Runs the operations of a batch as one call: one rate limit check and one audit entry for the call (the entries of
	 * the operations are marked batched and do not count, Store::calls_since()). With a package key from the caller
	 * every operation waits in that package. Without one the autonomy decides per operation as in a recipe run
	 * (Recipes::collecting()): what may go live goes live and lands in one applied package that leitvia/rollback undoes
	 * as a whole, what may not waits in one open package. An operation on a target an earlier one of the batch wrote
	 * builds on the hash that one left, so items on the same post send the hash read before the call. Items fail one by
	 * one; when every item fails, the first rejection is the answer.
	 *
	 * @param string                               $name  Ability name.
	 * @param list<array<string, mixed>|\WP_Error> $batch Descriptions per item, see write().
	 * @param string                               $key   Package key of the caller, '' for none.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function execute_batch( string $name, array $batch, string $key ) {
		$limited = self::rate_limit( get_current_user_id() );
		if ( $limited ) {
			return $limited;
		}
		$collect        = '' === $key;
		$key            = $collect ? substr( str_replace( '/', '-', $name ) . '-' . wp_generate_password( 12, false ), 0, 64 ) : $key;
		$applied        = 0;
		$failed         = null;
		$run            = static function () use ( $name, $batch, $key, &$applied, &$failed ): array {
			$items  = array();
			$hashes = array();
			foreach ( $batch as $parts ) {
				if ( is_wp_error( $parts ) ) {
					$failed  = $failed ?? $parts;
					$items[] = self::item_error( $parts );
					continue;
				}
				$base   = (string) ( $parts['base_hash'] ?? '' );
				$seen   = (int) ( $parts['target']['post'] ?? 0 ) . ':' . $base;
				$op     = new Operation( $name, (string) ( $parts['area'] ?? '' ), (array) ( $parts['target'] ?? array() ), $hashes[ $seen ] ?? $base, $parts['change'], array_values( (array) ( $parts['kinds'] ?? array() ) ), $key );
				$result = Pipeline::run( $op );
				if ( is_wp_error( $result ) ) {
					$failed  = $failed ?? $result;
					$items[] = self::item_error( $result );
					continue;
				}
				if ( ! empty( $parts['record'] ) && 'live' === $result['mode'] && ! $result['package'] ) {
					$applied           = Packages::record( $op, $result, $applied );
					$result['package'] = $applied;
				}
				// Live and draft writes leave a new hash; a queued one leaves the target as it is.
				if ( '' !== (string) $result['hash'] ) {
					$hashes[ $seen ] = (string) $result['hash'];
				}
				$items[] = $result;
			}
			return $items;
		};
		self::$batching = true;
		try {
			$items = $collect ? Recipes::collecting( $key, $run ) : $run();
		} finally {
			self::$batching = false;
		}
		$done  = array_values( array_filter( $items, static fn( array $item ): bool => ! isset( $item['code'] ) ) );
		$modes = array_column( $done, 'mode' );
		$queue = in_array( 'package', $modes, true ) ? Packages::find( $key ) : 0;
		Store::audit(
			array(
				'ability'    => $name,
				'result'     => $done ? 'ok' : 'rejected',
				'code'       => $done ? '' : (string) $items[0]['code'],
				'package_id' => $queue ? $queue : $applied,
				'details'    => array(
					'items' => count( $items ),
					'done'  => count( $done ),
				),
			)
		);
		if ( ! $done && $failed instanceof \WP_Error ) {
			return $failed;
		}
		return array(
			'mode'     => match ( true ) {
				$queue > 0 => 'package',
				in_array( 'live', $modes, true ) => 'live',
				in_array( 'draft', $modes, true ) => 'draft',
				default => 'unchanged',
			},
			'package'  => $queue ? $queue : $applied,
			'applied'  => $applied,
			'findings' => array(),
			'items'    => $items,
		);
	}

	/**
	 * A failed item of a batch: code, message, reason and target of the rejection.
	 *
	 * @param \WP_Error $error Rejection.
	 * @return array<string, mixed>
	 */
	private static function item_error( \WP_Error $error ): array {
		$data = (array) $error->get_error_data();
		return array(
			'code'    => (string) $error->get_error_code(),
			'message' => $error->get_error_message(),
			'reason'  => (string) ( $data['reason'] ?? '' ),
			'target'  => (array) ( $data['target'] ?? array() ),
		) + array_intersect_key( $data, array( 'findings' => true ) );
	}

	/**
	 * Whether the operations of a batch run right now, see execute_batch().
	 */
	public static function batching(): bool {
		return self::$batching;
	}

	/**
	 * Output schema of write abilities: the result of Pipeline::run(). Public for add-ons whose write abilities hand
	 * their operation to execute_write().
	 *
	 * @return array<string, mixed>
	 */
	public static function write_output(): array {
		$strings = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		return array(
			'type'       => 'object',
			'required'   => array( 'mode' ),
			'properties' => array(
				'mode'         => array(
					'type'        => 'string',
					'enum'        => array( 'live', 'draft', 'package', 'unchanged' ),
					'description' => __( 'What happened: live (written and visible), draft (written as a draft), package (nothing written yet: the change waits in a change package until a person applies it in the inbox; leitvia/get-package shows it), unchanged (the target already had this state).', 'leitvia-studio' ),
				),
				'level'        => array(
					'type'        => 'string',
					'enum'        => Gateway::LEVELS,
					'description' => __( 'Autonomy level of the area that decided the mode.', 'leitvia-studio' ),
				),
				'kinds'        => $strings,
				'target'       => array( 'type' => 'object' ),
				'hash'         => array(
					'type'        => 'string',
					'description' => __( 'Hash of the target after the write, the base_hash of the next change; empty when the change waits in a package.', 'leitvia-studio' ),
				),
				'package'      => array(
					'type'        => 'integer',
					'description' => __( 'ID of the change package the change waits in, or that leitvia/rollback undoes a live write with; 0 for none.', 'leitvia-studio' ),
				),
				'snapshot'     => array(
					'type'        => 'integer',
					'description' => __( 'ID of the snapshot of the state before the write; 0 for none.', 'leitvia-studio' ),
				),
				'findings'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'report'       => array( 'type' => 'object' ),
				'notice'       => array( 'type' => 'string' ),
				'slug_changed' => array(
					'type'        => 'object',
					'description' => __( 'The slug asked for (requested) is taken or reserved, so the address uses slug instead, on publishing at the latest.', 'leitvia-studio' ),
				),
				'applied'      => array(
					'type'        => 'integer',
					'description' => __( 'For a list of items: the package that holds what went live, for leitvia/rollback; 0 for none.', 'leitvia-studio' ),
				),
				'items'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => __( 'For a list of items: the result per item in their order, or code, message, reason and target of its rejection.', 'leitvia-studio' ),
				),
			),
		);
	}

	/**
	 * Permission callback of gateway abilities: a logged-in user with edit_posts, never user 0. Ignores the input,
	 * which the MCP Adapter passes before validating it.
	 */
	public static function can_use(): bool {
		return get_current_user_id() > 0 && current_user_can( 'edit_posts' );
	}

	/**
	 * Runs an ability callback after the rate limit and writes an audit entry without payload.
	 *
	 * @param string   $name     Ability name.
	 * @param callable $callback Ability callback.
	 * @param mixed    $input    Input.
	 * @return mixed Output or WP_Error.
	 */
	public static function run( string $name, callable $callback, $input ) {
		// The default of an input schema is an empty object, see read(); callbacks read arrays.
		$input = $input instanceof \stdClass ? (array) $input : $input;
		// verify-ignore: idempotent -- the rate limit counts the audit entries of the last minute; a read changes no content.
		$limited = self::rate_limit( get_current_user_id(), true );
		$result  = $limited ?? $callback( $input );
		$error   = is_wp_error( $result );
		// verify-ignore: readonly -- one audit entry per call without payload, the log of the gateway; no content or setting changes.
		Store::audit(
			array(
				'ability'     => $name,
				'result'      => $error ? 'rejected' : 'ok',
				'code'        => $error ? $result->get_error_code() : '',
				'object_type' => Store::READ,
			)
		);
		return $result;
	}

	/**
	 * The rejection leitvia_rate_limited when the user reached the limit in the last 60 seconds, else null. Reads and
	 * writes count apart: a build reads far more than it writes, and reading must not use up the writes.
	 * Call before any expensive work.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $read    Whether the call only reads.
	 */
	public static function rate_limit( int $user_id, bool $read = false ): ?\WP_Error {
		if ( self::$batching && ! $read ) {
			return null;
		}
		// ponytail: one indexed COUNT on the audit log per call; move to an object cache counter when load demands it.
		$now                = time();
		[ $calls, $oldest ] = Store::calls_since( $user_id, gmdate( 'Y-m-d H:i:s', $now - MINUTE_IN_SECONDS ), $read );
		$limit              = (int) Gateway::settings()[ $read ? 'read_rate_per_minute' : 'rate_per_minute' ];
		/**
		 * Filters the calls per minute of one user, for example to give a build agent more reads.
		 *
		 * @param int  $limit   Reads or writes per minute from the settings.
		 * @param bool $read    Whether the limit is the one for reads.
		 * @param int  $user_id User ID.
		 */
		if ( $calls < (int) apply_filters( 'leitvia_studio_rate_limit', $limit, $read, $user_id ) ) {
			return null;
		}
		$wait = max( 1, MINUTE_IN_SECONDS - ( $now - (int) strtotime( $oldest . ' UTC' ) ) );
		/* translators: %d: seconds */
		return Rejection::make( 'leitvia_rate_limited', sprintf( __( 'Too many calls. Try again in %d seconds.', 'leitvia-studio' ), $wait ), 429, 'rate_limit', array(), '', 'after:' . $wait );
	}

	/**
	 * Adds the header Retry-After to a REST answer 429 whose rejection carries the retry rule after:<seconds>, so HTTP
	 * clients wait without reading the body. Runs on rest_post_dispatch.
	 *
	 * @param mixed $response REST response.
	 * @return mixed
	 */
	public static function retry_after( $response ) {
		if ( ! $response instanceof \WP_HTTP_Response || 429 !== $response->get_status() ) {
			return $response;
		}
		$data  = $response->get_data();
		$retry = is_array( $data ) && is_array( $data['data'] ?? null ) ? (string) ( $data['data']['retry'] ?? '' ) : '';
		if ( str_starts_with( $retry, 'after:' ) ) {
			$response->header( 'Retry-After', (string) max( 1, (int) substr( $retry, 6 ) ) );
		}
		return $response;
	}
}
