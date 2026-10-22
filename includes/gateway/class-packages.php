<?php
/**
 * Change packages: what agents propose instead of writing live.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Tokens\Compiler;

defined( 'ABSPATH' ) || exit;

/**
 * Post type leitvia_package. post_content holds the operations as JSON, meta _leitvia_package_state the state, meta
 * _leitvia_package_key the package key of the agent's task. The author is the agent user.
 *
 * Applying replays each stored operation through Pipeline::run() live; the operation carries its complete new state,
 * so no callback is stored. A later operation on the same target builds on the state of the earlier one (follows) and
 * applies on the hash the earlier one left. A setting with target page points to a page the package creates and gets
 * its ID when applied. An applied operation keeps applied (post, hash after the write, snapshot),
 * an operation whose target changed keeps conflict (op, base_hash, current_hash). Rolling back writes the snapshots
 * back through the pipeline, newest first, only while every target still has the hash the package left.
 */
final class Packages {

	public const POST_TYPE = 'leitvia_package';

	public const STATE_META = '_leitvia_package_state';

	public const KEY_META = '_leitvia_package_key';

	/**
	 * Snapshot ID of the live write a package records, see record().
	 */
	public const SNAPSHOT_META = '_leitvia_package_snapshot';

	public const STATES = array( 'open', 'applied', 'partial', 'rejected', 'rolled_back' );

	/**
	 * Folder below uploads for files of operations that are not applied yet, see stash_file().
	 */
	public const PRIVATE_DIR = 'leitvia-private';

	/**
	 * Registers the post type: not public, not in REST, edit_theme_options for every capability. Runs on init.
	 */
	public static function register(): void {
		$caps = array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts', 'create_posts' );
		register_post_type(
			self::POST_TYPE,
			array(
				'label'        => __( 'Change packages', 'leitvia-studio' ),
				'public'       => false,
				'show_in_rest' => false,
				'rewrite'      => false,
				'query_var'    => false,
				'supports'     => array( 'title', 'author' ),
				'map_meta_cap' => false,
				'capabilities' => array_fill_keys( $caps, 'edit_theme_options' ),
			)
		);
	}

	/**
	 * Appends an operation to the open package of the current user with the same key, or to a new package.
	 *
	 * @param string               $key   Package key from the input, '' for a package of its own.
	 * @param array<string, mixed> $entry Operation as stored: ability, area, target, base_hash, level, kinds, state,
	 *                                    findings.
	 * @return int|\WP_Error Package ID.
	 */
	public static function add( string $key, array $entry ) {
		$id         = '' !== $key ? self::find( $key ) : 0;
		$operations = $id ? self::operations( $id ) : array();
		$entry      = array( 'id' => count( $operations ) + 1 ) + $entry;
		if ( $id ) {
			return self::save( $id, array_merge( $operations, array( $entry ) ) );
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'private',
					'post_title'   => '' !== $key ? $key : (string) $entry['ability'],
					'post_content' => self::json( array( $entry ) ),
					'meta_input'   => array(
						self::STATE_META => 'open',
						self::KEY_META   => $key,
					),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		/**
		 * Fires after a change package was created.
		 *
		 * @param int                  $id    Package ID.
		 * @param array<string, mixed> $entry Its first operation.
		 */
		do_action( 'leitvia_studio_package_created', $id, $entry );
		return $id;
	}

	/**
	 * A change package for a live write that had none, with the write as applied operation: the inbox shows it and
	 * rollback() can undo it. The same write gets the same package (found by its snapshot). 0 when it cannot be stored.
	 *
	 * @param Operation            $op     Operation that was written live.
	 * @param array<string, mixed> $result Result of Pipeline::run(): level, kinds, target, hash and snapshot.
	 */
	public static function record( Operation $op, array $result ): int {
		$snapshot = (int) $result['snapshot'];
		$found    = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'author'         => get_current_user_id(),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::SNAPSHOT_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Few packages per user.
				'meta_value'     => (string) $snapshot, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Few packages per user.
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}
		$post   = (int) ( $result['target']['post'] ?? $result['target']['term'] ?? 0 );
		$target = '' !== $op->option() || isset( $op->target['term'] ) ? $op->target : array( 'post' => $post ) + $op->target;
		$id     = self::add(
			'',
			array(
				'ability'   => $op->ability,
				'area'      => $op->area,
				'target'    => $target,
				'base_hash' => '',
				'level'     => (string) $result['level'],
				'kinds'     => (array) $result['kinds'],
				'state'     => Diff::current( new Operation( $op->ability, $op->area, $target, '', static fn( array $state ): array => $state ) ),
				'findings'  => array(),
				'applied'   => array(
					'post'     => $post,
					'hash'     => (string) $result['hash'],
					'snapshot' => $snapshot,
				),
			)
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		self::set_state( $id, 'applied' );
		update_post_meta( $id, self::SNAPSHOT_META, $snapshot );
		return $id;
	}

	/**
	 * The last operation of the open package with the operation's key on the same existing target that is neither
	 * applied nor in conflict, an empty array for none. A new write to that target builds on its state.
	 *
	 * @param Operation $op Operation.
	 * @return array<string, mixed>
	 */
	public static function follows( Operation $op ): array {
		$id   = '' !== $op->package && ! $op->creates() ? self::find( $op->package ) : 0;
		$last = array();
		foreach ( $id ? self::operations( $id ) : array() as $entry ) {
			if ( ! isset( $entry['applied'] ) && ! isset( $entry['conflict'] ) && self::same_target( (array) $entry['target'], $op->target ) ) {
				$last = $entry;
			}
		}
		return $last;
	}

	/**
	 * Whether two operation targets name the same option, term or post.
	 *
	 * @param array<string, mixed> $a Target.
	 * @param array<string, mixed> $b Target.
	 */
	private static function same_target( array $a, array $b ): bool {
		$key = static fn( array $target ): string => match ( true ) {
			isset( $target['option'] ) => 'option:' . $target['option'],
			isset( $target['term'] )   => 'term:' . (int) $target['term'],
			default                    => 'post:' . (int) ( $target['post'] ?? 0 ),
		};
		return $key( $a ) === $key( $b );
	}

	/**
	 * The open package of the current user with this key, 0 for none.
	 *
	 * @param string $key Package key.
	 */
	public static function find( string $key ): int {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'author'         => get_current_user_id(),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Few packages per user.
					array(
						'key'   => self::KEY_META,
						'value' => $key,
					),
					array(
						'key'   => self::STATE_META,
						'value' => 'open',
					),
				),
			)
		);
		return (int) ( $ids[0] ?? 0 );
	}

	/**
	 * Operations of a package.
	 *
	 * @param int $id Package ID.
	 * @return list<array<string, mixed>>
	 */
	public static function operations( int $id ): array {
		$operations = json_decode( (string) get_post_field( 'post_content', $id, 'raw' ), true );
		return is_array( $operations ) ? array_values( $operations ) : array();
	}

	/**
	 * State of a package, '' when it is none.
	 *
	 * @param int $id Package ID.
	 */
	public static function state( int $id ): string {
		return self::POST_TYPE === get_post_type( $id ) ? (string) get_post_meta( $id, self::STATE_META, true ) : '';
	}

	/**
	 * Sets the state of a package.
	 *
	 * @param int    $id    Package ID.
	 * @param string $state One of STATES.
	 */
	public static function set_state( int $id, string $state ): bool {
		if ( self::POST_TYPE !== get_post_type( $id ) || ! in_array( $state, self::STATES, true ) ) {
			return false;
		}
		update_post_meta( $id, self::STATE_META, $state );
		return true;
	}

	/**
	 * Packages for a list, newest first.
	 *
	 * @param int    $author Only packages of this user, 0 for all.
	 * @param string $state  Only packages in this state, '' for all.
	 * @return list<array<string, mixed>> See summary().
	 */
	public static function all( int $author = 0, string $state = '' ): array {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'fields'         => 'ids',
			'posts_per_page' => 100,
			'no_found_rows'  => true,
		);
		if ( $author ) {
			$args['author'] = $author;
		}
		if ( '' !== $state ) {
			$args['meta_key']   = self::STATE_META; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Few packages.
			$args['meta_value'] = $state; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same.
		}
		// ponytail: the newest 100; paging comes with the inbox when a site keeps more.
		return array_values( array_map( array( self::class, 'summary' ), array_map( 'intval', get_posts( $args ) ) ) );
	}

	/**
	 * A package without its operations: id, title, key, author with its name, created (UTC), state, counts per
	 * operation status, the preview link, checks (number of findings and the lowest composition score, null without
	 * one) and verify (the report of the check after writing, see Verify::NOTE_META, null without one).
	 *
	 * @param int $id Package ID.
	 * @return array<string, mixed>
	 */
	public static function summary( int $id ): array {
		$operations = self::operations( $id );
		$counts     = array_count_values( array_map( array( self::class, 'status' ), $operations ) );
		$findings   = array_merge( array(), ...array_map( static fn( array $entry ): array => (array) ( $entry['findings'] ?? array() ), $operations ) );
		$scores     = array_filter( array_column( $findings, 'score' ), 'is_numeric' );
		$author     = (int) get_post_field( 'post_author', $id );
		$verify     = get_post_meta( $id, Verify::NOTE_META, true );
		return array(
			'id'         => $id,
			'title'      => (string) get_post_field( 'post_title', $id, 'raw' ),
			'key'        => (string) get_post_meta( $id, self::KEY_META, true ),
			'author'     => $author,
			'agent'      => (string) get_the_author_meta( 'display_name', $author ),
			'created'    => (string) get_post_field( 'post_date_gmt', $id ),
			'state'      => self::state( $id ),
			'operations' => $counts + array_fill_keys( array( 'open', 'conflict', 'applied', 'rolled_back' ), 0 ),
			'preview'    => Preview::url( $id ),
			'checks'     => array(
				'findings' => count( $findings ),
				'score'    => $scores ? (int) min( $scores ) : null,
			),
			'verify'     => is_array( $verify ) ? $verify : null,
		);
	}

	/**
	 * A package with its operations: summary() plus per operation id, ability, area, target, level, kinds, findings,
	 * status, conflict, modified_by (who saved the target post last) and, for open ones, the block diff against the
	 * current state (or diff_error).
	 *
	 * @param int $id Package ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function detail( int $id ) {
		if ( '' === self::state( $id ) ) {
			return self::unknown( $id );
		}
		$operations = array();
		$states     = array_column( self::operations( $id ), 'state', 'id' );
		foreach ( self::operations( $id ) as $entry ) {
			$item           = array_intersect_key( $entry, array_flip( array( 'id', 'ability', 'area', 'target', 'level', 'kinds', 'findings', 'conflict', 'error' ) ) );
			$item['status'] = self::status( $entry );
			// Who saved the target last, so a conflict can name the person who changed it.
			$post                = (int) ( $entry['applied']['post'] ?? $entry['target']['post'] ?? 0 );
			$editor              = $post ? (int) get_post_meta( $post, '_edit_last', true ) : 0;
			$item['modified_by'] = $editor ? (string) get_the_author_meta( 'display_name', $editor ) : '';
			if ( ! isset( $entry['applied'] ) && ! isset( $entry['rolled_back'] ) ) {
				// An operation that builds on an earlier one shows only its own change.
				$earlier      = $states[ $entry['follows'] ?? 0 ] ?? null;
				$diff         = is_array( $earlier ) ? Diff::states( $earlier, (array) $entry['state'] ) : Diff::operation( $entry );
				$item['diff'] = is_wp_error( $diff ) ? array() : $diff;
				if ( is_wp_error( $diff ) ) {
					$item['diff_error'] = $diff->get_error_code();
				}
			}
			$operations[] = $item;
		}
		return self::summary( $id ) + array( 'items' => $operations );
	}

	/**
	 * Applies the open operations of a package, all or the listed ones. Each runs through Pipeline::run() live and
	 * checks its base hash there; an operation whose target changed stays open with a conflict, one the pipeline
	 * rejects otherwise stays open with the error, the others are written with snapshot and audit entry. The package
	 * becomes applied when nothing is open, partial when something was applied. Fires leitvia_studio_package_applied.
	 *
	 * @param int   $id   Package ID.
	 * @param int[] $only Operation IDs, empty for every open operation.
	 * @return array<string, mixed>|\WP_Error Package, state, applied operation IDs, conflicts and errors.
	 */
	public static function apply( int $id, array $only = array() ) {
		$state = self::state( $id );
		if ( ! in_array( $state, array( 'open', 'partial' ), true ) ) {
			return '' === $state ? self::unknown( $id ) : self::closed( $id, $state );
		}
		$operations = self::operations( $id );
		$report     = array(
			'package'   => $id,
			'state'     => $state,
			'applied'   => array(),
			'conflicts' => array(),
			'errors'    => array(),
		);
		foreach ( $operations as $index => $entry ) {
			if ( isset( $entry['applied'] ) || ( $only && ! in_array( (int) $entry['id'], $only, true ) ) ) {
				continue;
			}
			unset( $operations[ $index ]['conflict'], $operations[ $index ]['error'] );
			$new  = (array) $entry['state'];
			$base = (string) $entry['base_hash'];
			if ( isset( $entry['follows'] ) ) {
				// It applies on the hash the earlier operation left; without that one applied it conflicts.
				$earlier = array_column( $operations, 'applied', 'id' )[ $entry['follows'] ] ?? array();
				$base    = (string) ( $earlier['hash'] ?? '' );
			}
			// A setting that points to a page of this package (target page) gets the ID the page got when it was applied.
			$slug = (string) ( $entry['target']['page'] ?? '' );
			$page = '' !== $slug ? self::created_page( $operations, $slug ) : 0;
			if ( $page ) {
				$new['value'] = (string) $page;
			}
			$result = '' !== $slug && ! $page
				? Rejection::make( 'leitvia_page_missing', __( 'The page this setting points to is not applied yet. Apply the page first.', 'leitvia-studio' ), 409, 'page_missing', array( 'option' => (string) ( $entry['target']['option'] ?? '' ) ), '', 'never', array( 'page' => $slug ) )
				: Pipeline::run( new Operation( (string) $entry['ability'], (string) $entry['area'], (array) $entry['target'], $base, static fn(): array => $new, (array) $entry['kinds'] ), $id );
			if ( is_wp_error( $result ) ) {
				$data = (array) $result->get_error_data();
				if ( 'leitvia_stale_hash' === $result->get_error_code() ) {
					$operations[ $index ]['conflict'] = array(
						'op'           => (int) $entry['id'],
						'base_hash'    => $base,
						'current_hash' => (string) ( $data['current_hash'] ?? '' ),
					);
					$report['conflicts'][]            = $operations[ $index ]['conflict'];
					continue;
				}
				$operations[ $index ]['error'] = array(
					'op'       => (int) $entry['id'],
					'code'     => (string) $result->get_error_code(),
					'message'  => $result->get_error_message(),
					'findings' => (array) ( $data['findings'] ?? array() ),
				);
				$report['errors'][]            = $operations[ $index ]['error'];
				continue;
			}
			$operations[ $index ]['applied'] = array(
				'post'     => (int) ( $result['target']['post'] ?? $result['target']['term'] ?? 0 ),
				'hash'     => (string) $result['hash'],
				'snapshot' => (int) $result['snapshot'],
			);
			$report['applied'][]             = (int) $entry['id'];
		}
		$open            = count( array_filter( $operations, static fn( array $entry ): bool => ! isset( $entry['applied'] ) ) );
		$report['state'] = match ( true ) {
			0 === $open                    => 'applied',
			$open < count( $operations )   => 'partial',
			default                        => $report['state'],
		};
		self::save( $id, $operations );
		self::set_state( $id, $report['state'] );
		if ( $report['applied'] ) {
			/**
			 * Fires after a change package was applied, fully or in part.
			 *
			 * @param int $id Package ID.
			 */
			do_action( 'leitvia_studio_package_applied', $id );
		}
		return $report;
	}

	/**
	 * ID of the page an applied operation of the package created with this slug, 0 for none.
	 *
	 * @param list<array<string, mixed>> $operations Operations of the package.
	 * @param string                     $slug       Page slug as the operation asked for it.
	 */
	private static function created_page( array $operations, string $slug ): int {
		foreach ( $operations as $entry ) {
			if ( empty( $entry['target']['post'] ) && 'page' === ( $entry['state']['post_type'] ?? '' ) && ( $entry['state']['post_name'] ?? '' ) === $slug ) {
				return (int) ( $entry['applied']['post'] ?? 0 );
			}
		}
		return 0;
	}

	/**
	 * Rejects an open or partly applied package: writes nothing, deletes the waiting files of its open operations.
	 *
	 * @param int $id Package ID.
	 * @return array<string, mixed>|\WP_Error Package and state.
	 */
	public static function reject( int $id ) {
		$state = self::state( $id );
		if ( ! in_array( $state, array( 'open', 'partial' ), true ) ) {
			return '' === $state ? self::unknown( $id ) : self::closed( $id, $state );
		}
		foreach ( self::operations( $id ) as $entry ) {
			if ( ! isset( $entry['applied'] ) ) {
				self::discard_file( (array) $entry['state'] );
			}
		}
		self::set_state( $id, 'rejected' );
		return array(
			'package' => $id,
			'state'   => 'rejected',
		);
	}

	/**
	 * Rolls back the applied operations of a package, newest first. Only when every target still has the hash the
	 * package left; otherwise leitvia_rollback_conflict with both hashes and the diff from the applied to the current
	 * state per conflict, and nothing is written. Fires leitvia_studio_package_rolled_back.
	 *
	 * @param int $id Package ID.
	 * @return array<string, mixed>|\WP_Error Package, state and the rolled back operation IDs.
	 */
	public static function rollback( int $id ) {
		$state = self::state( $id );
		if ( ! in_array( $state, array( 'applied', 'partial' ), true ) ) {
			return '' === $state ? self::unknown( $id ) : self::closed( $id, $state );
		}
		$operations = self::operations( $id );
		$applied    = array_filter( $operations, static fn( array $entry ): bool => isset( $entry['applied'] ) );
		$undo       = array();
		$conflicts  = array();
		// A target a later operation wrote again is checked through that one; the pipeline checks it again on the way back.
		$followed = array_column( $applied, 'follows' );
		foreach ( $applied as $index => $entry ) {
			$undo[ $index ] = self::undo( $entry );
			if ( $undo[ $index ] && in_array( $entry['id'], $followed, true ) ) {
				continue;
			}
			$current = $undo[ $index ] ? Pipeline::hash( $undo[ $index ] ) : '';
			if ( ! $undo[ $index ] || ! hash_equals( (string) $entry['applied']['hash'], $current ) ) {
				$diff        = $undo[ $index ] ? Diff::states( (array) $entry['state'], Diff::current( $undo[ $index ] ) ) : array();
				$conflicts[] = array(
					'op'           => (int) $entry['id'],
					'applied_hash' => (string) $entry['applied']['hash'],
					'current_hash' => $current,
					'diff'         => is_wp_error( $diff ) ? array() : $diff,
				);
			}
		}
		if ( $conflicts ) {
			return Rejection::make(
				'leitvia_rollback_conflict',
				__( 'Someone changed the content after the package was applied. Nothing was rolled back; compare the changes and decide by hand.', 'leitvia-studio' ),
				409,
				'rollback_conflict',
				array( 'package' => $id ),
				'leitvia/get-package',
				'never',
				array( 'conflicts' => $conflicts )
			);
		}
		$report = array(
			'package'     => $id,
			'state'       => 'rolled_back',
			'rolled_back' => array(),
		);
		foreach ( array_reverse( array_filter( $undo ), true ) as $index => $op ) {
			$result = Pipeline::run( $op, $id, true );
			if ( is_wp_error( $result ) ) {
				self::save( $id, $operations );
				return $result;
			}
			$operations[ $index ]['rolled_back'] = array( 'snapshot' => (int) $result['snapshot'] );
			unset( $operations[ $index ]['applied'] );
			if ( isset( $operations[ $index ]['target']['token_set'] ) ) {
				// The global styles are back; the marker of the active set must not claim otherwise.
				Compiler::forget( (int) $operations[ $index ]['target']['token_set'] );
			}
			$report['rolled_back'][] = (int) $operations[ $index ]['id'];
		}
		self::save( $id, $operations );
		self::set_state( $id, 'rolled_back' );
		/**
		 * Fires after an applied change package was rolled back.
		 *
		 * @param int $id Package ID.
		 */
		do_action( 'leitvia_studio_package_rolled_back', $id );
		return $report;
	}

	/**
	 * Status of a stored operation: open, conflict, applied or rolled_back.
	 *
	 * @param array<string, mixed> $entry Stored operation.
	 */
	public static function status( array $entry ): string {
		return match ( true ) {
			isset( $entry['applied'] )     => 'applied',
			isset( $entry['rolled_back'] ) => 'rolled_back',
			isset( $entry['conflict'] )    => 'conflict',
			default                        => 'open',
		};
	}

	/**
	 * Writes a file of an operation to the private area: a folder with a random name below uploads/leitvia-private,
	 * which denies web access where the server reads .htaccess. The pipeline moves it into the uploads folder when it
	 * writes the attachment; rejecting the package deletes it.
	 *
	 * @param string $name  File name.
	 * @param string $bytes Content.
	 * @return string Path, '' on failure.
	 */
	public static function stash_file( string $name, string $bytes ): string {
		$root = self::private_root();
		$dir  = '' !== $root ? $root . '/' . wp_generate_password( 32, false ) : '';
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$path = $dir . '/' . sanitize_file_name( $name );
		return false !== file_put_contents( $path, $bytes ) ? $path : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private area of the plugin.
	}

	/**
	 * Copies the private file of a state into the uploads folder: the state with the new path in file and in the
	 * attachment metadata, or null when the file is not in the private area or cannot be copied.
	 *
	 * @param array<string, mixed> $state State of a new attachment.
	 * @return array<string, mixed>|null
	 */
	public static function promote_file( array $state ): ?array {
		$file = self::private_file( (string) ( $state['file'] ?? '' ) );
		$name = wp_basename( $file );
		if ( '' === $name ) {
			return null;
		}
		$upload = wp_upload_bits( $name, null, (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private area of the plugin.
		if ( ! empty( $upload['error'] ) ) {
			return null;
		}
		$state['file'] = $upload['file'];
		if ( is_array( $state['meta_input']['_wp_attachment_metadata'] ?? null ) ) {
			$state['meta_input']['_wp_attachment_metadata']['file'] = _wp_relative_upload_path( $upload['file'] );
		}
		return $state;
	}

	/**
	 * Deletes the private file of a state and its folder; files outside the private area stay.
	 *
	 * @param array<string, mixed> $state State.
	 */
	public static function discard_file( array $state ): void {
		$file = self::private_file( (string) ( $state['file'] ?? '' ) );
		if ( '' !== $file ) {
			wp_delete_file( $file );
			rmdir( dirname( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty folder of the private area.
		}
	}

	/**
	 * The private area, created with its .htaccess on first use; '' when uploads are not writable.
	 */
	private static function private_root(): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}
		$root = $uploads['basedir'] . '/' . self::PRIVATE_DIR;
		if ( ! is_dir( $root ) ) {
			if ( ! wp_mkdir_p( $root ) ) {
				return '';
			}
			// Apache honours the file; elsewhere the random folder names keep the files unguessable.
			file_put_contents( $root . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private area of the plugin.
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same.
		}
		return $root;
	}

	/**
	 * The real path of a file inside the private area, '' for any other path. A stored or imported package can name
	 * any path; only these files are ever moved or deleted.
	 *
	 * @param string $path Path.
	 */
	private static function private_file( string $path ): string {
		$root = '' !== $path ? realpath( self::private_root() ) : false;
		$file = $root ? realpath( $path ) : false;
		return $root && $file && str_starts_with( $file, trailingslashit( $root ) ) && is_file( $file ) ? $file : '';
	}

	/**
	 * The operation that writes the snapshot of an applied operation back, null when the snapshot is gone. Its base
	 * hash is the hash the package left, so the pipeline refuses it after a later change.
	 *
	 * @param array<string, mixed> $entry Applied operation.
	 */
	private static function undo( array $entry ): ?Operation {
		$snapshot = Store::get_snapshot( (int) $entry['applied']['snapshot'] );
		// The snapshot must belong to the object the operation wrote; a package never restores another object's state.
		if ( ! $snapshot || (int) $snapshot['object_id'] !== (int) $entry['applied']['post'] ) {
			return null;
		}
		$before = (array) $snapshot['state'];
		// applied.post holds the ID of a post or a term.
		$target = match ( true ) {
			isset( $entry['target']['option'] ) => array( 'option' => (string) $entry['target']['option'] ),
			isset( $entry['target']['term'] )   => array( 'term' => (int) $entry['applied']['post'] ),
			default                             => array( 'post' => (int) $entry['applied']['post'] ),
		};
		if ( ! $before && isset( $target['post'] ) ) {
			$target['delete'] = true;
		}
		return new Operation( 'leitvia/rollback', (string) $entry['area'], $target, (string) $entry['applied']['hash'], static fn(): array => $before, (array) $entry['kinds'] );
	}

	/**
	 * Stores the operations of a package.
	 *
	 * @param int                              $id         Package ID.
	 * @param array<int, array<string, mixed>> $operations Operations.
	 * @return int|\WP_Error Package ID.
	 */
	private static function save( int $id, array $operations ) {
		$saved = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => self::json( array_values( $operations ) ),
				)
			),
			true
		);
		return is_wp_error( $saved ) ? $saved : $id;
	}

	/**
	 * Operations as JSON. Hex escapes keep markup in the JSON away from KSES, which runs on save for agents.
	 *
	 * @param list<array<string, mixed>> $operations Operations.
	 */
	private static function json( array $operations ): string {
		return (string) wp_json_encode( $operations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Rejection leitvia_unknown_package.
	 *
	 * @param int $id Package ID.
	 */
	public static function unknown( int $id ): \WP_Error {
		return Rejection::make( 'leitvia_unknown_package', __( 'There is no change package with this ID. List the packages and use one of them.', 'leitvia-studio' ), 404, 'package', array( 'package' => $id ), 'leitvia/list-packages', 'never' );
	}

	/**
	 * Rejection leitvia_package_closed.
	 *
	 * @param int    $id    Package ID.
	 * @param string $state Its state.
	 */
	private static function closed( int $id, string $state ): \WP_Error {
		/* translators: %s: state of the change package */
		return Rejection::make( 'leitvia_package_closed', sprintf( __( 'The change package is %s; this step is not possible in that state.', 'leitvia-studio' ), $state ), 409, 'package_state', array( 'package' => $id ), 'leitvia/get-package', 'never', array( 'state' => $state ) );
	}
}
