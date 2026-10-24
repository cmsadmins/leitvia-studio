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
	 * Registers a read ability in the group read. Call on leitvia_studio_init.
	 *
	 * @param string               $name          Ability name, leitvia/<name>.
	 * @param string               $label         Translated label.
	 * @param string               $description   Translated description.
	 * @param array<string, mixed> $input_schema  JSON schema of the input; core does not cast it outside REST.
	 * @param array<string, mixed> $output_schema JSON schema of the output.
	 * @param callable             $callback      Receives the input, returns the output or a WP_Error.
	 * @return bool Whether the registry accepted the ability.
	 * @phpstan-param lowercase-string&non-falsy-string $name
	 */
	public static function read( string $name, string $label, string $description, array $input_schema, array $output_schema, callable $callback ): bool {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'output_schema'       => $output_schema,
			'permission_callback' => array( self::class, 'can_use' ),
			'execute_callback'    => static fn( $input = null ) => self::run( $name, $callback, $input ),
			'meta'                => array(
				// The MCP Adapter reads openWorldHint, not open_world.
				'annotations'  => array(
					'readonly'      => true,
					'destructive'   => false,
					'idempotent'    => true,
					'openWorldHint' => false,
				),
				'show_in_rest' => true,
			),
		);
		if ( $input_schema ) {
			// Without a required input a call without any (GET with no query arguments) reads with the defaults.
			$args['input_schema'] = $input_schema + ( empty( $input_schema['required'] ) && 'object' === ( $input_schema['type'] ?? '' ) ? array( 'default' => array() ) : array() );
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
	 * can undo it; 'report' => callable adds what it returns after the write as report to the result.
	 *
	 * @param string               $name         Ability name, leitvia/<name>.
	 * @param string               $label        Translated label.
	 * @param string               $description  Translated description.
	 * @param string               $group        setup, build or maintain.
	 * @param array<string, bool>  $annotations  destructive and idempotent; readonly is always false.
	 * @param array<string, mixed> $input_schema JSON schema of the input, an object.
	 * @param callable             $describe     Describes the operation.
	 * @return bool Whether the registry accepted the ability.
	 * @phpstan-param lowercase-string&non-falsy-string $name
	 */
	public static function write( string $name, string $label, string $description, string $group, array $annotations, array $input_schema, callable $describe ): bool {
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
		$schema['required']                = array_values( array_unique( array_merge( (array) ( $schema['required'] ?? array() ), array( 'base_hash' ) ) ) );
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
						'annotations'  => array( 'readonly' => false ) + $annotations,
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
		$op     = new Operation(
			$name,
			(string) ( $parts['area'] ?? '' ),
			(array) ( $parts['target'] ?? array() ),
			(string) ( $input['base_hash'] ?? '' ),
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
		if ( isset( $parts['report'] ) ) {
			$result['report'] = (array) ( $parts['report'] )();
		}
		return $result;
	}

	/**
	 * Output schema of write abilities: the result of Pipeline::run().
	 *
	 * @return array<string, mixed>
	 */
	private static function write_output(): array {
		$strings = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'mode'     => array(
					'type' => 'string',
					'enum' => array( 'live', 'draft', 'package', 'unchanged' ),
				),
				'level'    => array(
					'type' => 'string',
					'enum' => Gateway::LEVELS,
				),
				'kinds'    => $strings,
				'target'   => array( 'type' => 'object' ),
				'hash'     => array( 'type' => 'string' ),
				'package'  => array( 'type' => 'integer' ),
				'snapshot' => array( 'type' => 'integer' ),
				'findings' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'report'   => array( 'type' => 'object' ),
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
		$limited = self::rate_limit( get_current_user_id() );
		$result  = $limited ?? $callback( $input );
		$error   = is_wp_error( $result );
		Store::audit(
			array(
				'ability' => $name,
				'result'  => $error ? 'rejected' : 'ok',
				'code'    => $error ? $result->get_error_code() : '',
			)
		);
		return $result;
	}

	/**
	 * The rejection leitvia_rate_limited when the user made rate_per_minute calls in the last 60 seconds, else null.
	 * Call before any expensive work.
	 *
	 * @param int $user_id User ID.
	 */
	public static function rate_limit( int $user_id ): ?\WP_Error {
		// ponytail: one indexed COUNT on the audit log per call; move to an object cache counter when load demands it.
		$now                = time();
		[ $calls, $oldest ] = Store::calls_since( $user_id, gmdate( 'Y-m-d H:i:s', $now - MINUTE_IN_SECONDS ) );
		if ( $calls < (int) Gateway::settings()['rate_per_minute'] ) {
			return null;
		}
		$wait = max( 1, MINUTE_IN_SECONDS - ( $now - (int) strtotime( $oldest . ' UTC' ) ) );
		/* translators: %d: seconds */
		return Rejection::make( 'leitvia_rate_limited', sprintf( __( 'Too many calls. Try again in %d seconds.', 'leitvia-studio' ), $wait ), 429, 'rate_limit', array(), '', 'after:' . $wait );
	}
}
