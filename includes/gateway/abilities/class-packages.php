<?php
/**
 * Abilities for change packages.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Autonomy;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Packages as Store;
use LeitviaStudio\Gateway\Rejection;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/list-packages, leitvia/get-package, leitvia/apply-package and leitvia/rollback. Agents see and
 * change their own packages (users with manage_options every package) and apply or roll back only operations whose
 * autonomy level is "autonomous" now; anything else is leitvia_needs_human with a link to the inbox. A person with
 * manage_options who is no agent decides like in the inbox, whatever the level (Gateway::is_person()). Applying and
 * rolling back replay each operation through Pipeline::run().
 */
final class Packages {

	/**
	 * Registers the four abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$id     = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Change package ID.', 'leitvia-studio' ),
		);
		$object = array( 'type' => 'object' );
		Abilities::read(
			'leitvia/list-packages',
			__( 'List change packages', 'leitvia-studio' ),
			__( 'Lists your change packages, newest first: ID, title, key, state (open, applied, partial, rejected, rolled_back), operations per status and a preview link.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'state' => array(
						'type' => 'string',
						'enum' => Store::STATES,
					),
				),
				'additionalProperties' => false,
			),
			array(
				'type'  => 'array',
				'items' => $object,
			),
			static fn( $input ) => Store::all( current_user_can( 'manage_options' ) ? 0 : get_current_user_id(), (string) ( $input['state'] ?? '' ) )
		);
		Abilities::read(
			'leitvia/get-package',
			__( 'Get change package', 'leitvia-studio' ),
			__( 'Returns a change package with its operations: status, the post each applied operation wrote, conflicts and, for open operations, a block diff against the current state (per block added, removed, moved or changed with text before and after and the changed attributes). applied.post is the ID a new post got, applied.hash its hash after the write.', 'leitvia-studio' ),
			self::input( $id ),
			$object,
			static fn( $input ) => self::own( (int) ( $input['id'] ?? 0 ) ) ?? Store::detail( (int) $input['id'] )
		);
		self::write(
			'leitvia/apply-package',
			__( 'Apply change package', 'leitvia-studio' ),
			__( 'Applies the open operations of a change package, all or the listed ones. Find the package with leitvia/list-packages and check its diff with leitvia/get-package first. Each operation checks its base hash; one whose target changed stays open with a conflict, the others are written with snapshot and audit entry. Agents may only apply operations whose autonomy level is autonomous.', 'leitvia-studio' ),
			false,
			$id,
			static function ( array $input ) {
				$only = array_map( 'intval', (array) ( $input['operations'] ?? array() ) );
				return self::guard( (int) $input['id'], static fn( array $entry ): bool => ! isset( $entry['applied'] ) && ( ! $only || in_array( (int) $entry['id'], $only, true ) ) )
					?? Store::apply( (int) $input['id'], $only );
			}
		);
		self::write(
			'leitvia/rollback',
			__( 'Roll back change package', 'leitvia-studio' ),
			__( 'Rolls back the applied operations of a change package from their snapshots. Package IDs come from leitvia/list-packages. If anyone changed a target since, nothing is rolled back and leitvia_rollback_conflict lists both hashes and the diff. Agents may only roll back operations whose autonomy level is autonomous.', 'leitvia-studio' ),
			true,
			$id,
			static fn( array $input ) => self::guard( (int) $input['id'], static fn( array $entry ): bool => isset( $entry['applied'] ) ) ?? Store::rollback( (int) $input['id'] )
		);
	}

	/**
	 * Registers apply-package or rollback: not read-only, run after the rate limit with an audit entry.
	 *
	 * @param string               $name        Ability name.
	 * @param string               $label       Label.
	 * @param string               $description Description.
	 * @param bool                 $destructive Whether it can undo changes.
	 * @param array<string, mixed> $id          Schema of the package ID.
	 * @param callable             $callback    Receives the input.
	 * @phpstan-param lowercase-string&non-falsy-string $name
	 */
	private static function write( string $name, string $label, string $description, bool $destructive, array $id, callable $callback ): void {
		$input = self::input( $id );
		if ( ! $destructive ) {
			$input['properties']['operations'] = array(
				'type'        => 'array',
				'items'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'description' => __( 'Operation IDs to apply; empty or missing for every open operation.', 'leitvia-studio' ),
			);
		}
		leitvia_studio()->registry( 'abilities' )->register(
			$name,
			array(
				'args'  => array(
					'label'               => $label,
					'description'         => $description,
					'input_schema'        => $input,
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => array( Abilities::class, 'can_use' ),
					'execute_callback'    => static fn( $input = null ) => Abilities::run( $name, static fn( $input ) => $callback( is_array( $input ) ? $input : array() ), $input ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => $destructive,
							'idempotent'  => false,
						),
						'show_in_rest' => true,
					),
				),
				'group' => 'maintain',
			),
			'leitvia-studio'
		);
	}

	/**
	 * Input schema with the package ID.
	 *
	 * @param array<string, mixed> $id Schema of the ID.
	 * @return array<string, mixed>
	 */
	private static function input( array $id ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'id' => $id ),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Rejection when the package is unknown or not the user's own, else null.
	 *
	 * @param int $id Package ID.
	 */
	private static function own( int $id ): ?\WP_Error {
		if ( '' === Store::state( $id ) ) {
			return Store::unknown( $id );
		}
		if ( (int) get_post_field( 'post_author', $id ) !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return Rejection::make( 'leitvia_forbidden', __( 'This change package belongs to someone else.', 'leitvia-studio' ), 403, 'capability', array( 'package' => $id ), 'leitvia/list-packages', 'never' );
		}
		return null;
	}

	/**
	 * Rejection when the package is not the user's own or, for anyone but a person (Gateway::is_person()), one of the
	 * chosen operations is not autonomous; else null.
	 *
	 * @param int      $id     Package ID.
	 * @param callable $chosen Whether a stored operation is part of the call.
	 */
	private static function guard( int $id, callable $chosen ): ?\WP_Error {
		$denied = self::own( $id );
		if ( $denied || Gateway::is_person() ) {
			return $denied;
		}
		$human = array();
		foreach ( Store::operations( $id ) as $entry ) {
			if ( $chosen( $entry ) && 'autonomous' !== Autonomy::level( (string) $entry['area'], (array) $entry['kinds'] ) ) {
				$human[] = (int) $entry['id'];
			}
		}
		if ( ! $human ) {
			return null;
		}
		return Rejection::make(
			'leitvia_needs_human',
			__( 'A person has to decide on these operations in the inbox. Apply only operations whose area is autonomous for you.', 'leitvia-studio' ),
			403,
			'autonomy',
			array( 'package' => $id ),
			'',
			'never',
			array(
				'operations' => $human,
				'inbox'      => admin_url( 'admin.php?page=leitvia-studio-inbox&package=' . $id ),
			)
		);
	}
}
