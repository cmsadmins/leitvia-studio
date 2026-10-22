<?php
/**
 * Gateway tables: audit log and snapshots.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the tables {prefix}leitvia_audit and {prefix}leitvia_snapshots per site, writes to them and prunes them.
 * The audit log holds user, ability, object, package, result and a short code, never content.
 */
final class Store {

	/**
	 * Schema version, stored in the option leitvia_studio_db. Raise it when a table changes.
	 */
	public const VERSION = 1;

	public const VERSION_OPTION = 'leitvia_studio_db';

	/**
	 * Daily cron event that prunes both tables.
	 */
	public const CRON = 'leitvia_studio_gateway_prune';

	/**
	 * Results an audit entry may carry.
	 */
	public const RESULTS = array( 'ok', 'rejected', 'failed', 'rolled_back', 'unverifiable' );

	/**
	 * Largest details JSON in bytes; a larger one is replaced by {"truncated":true}.
	 */
	public const MAX_DETAILS = 1024;

	/**
	 * Bytes counted per row on top of its variable columns when the size limit is checked.
	 */
	private const ROW_BYTES = 128;

	/**
	 * Table name of the current site.
	 *
	 * @param string $name 'audit' or 'snapshots'.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'leitvia_' . $name;
	}

	/**
	 * Filter wpmu_drop_tables: the audit log and the snapshots go with a deleted site of the network. Core has switched
	 * to the site, so table() names its tables.
	 *
	 * @param string[] $tables Tables core drops.
	 * @return string[]
	 */
	public static function drop_tables( array $tables ): array {
		return array_values( array_unique( array_merge( $tables, array( self::table( 'audit' ), self::table( 'snapshots' ) ) ) ) );
	}

	/**
	 * Creates or updates both tables of the current site and stores the schema version.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$audit   = self::table( 'audit' );
		$snaps   = self::table( 'snapshots' );
		dbDelta(
			array(
				"CREATE TABLE {$audit} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ability varchar(191) NOT NULL DEFAULT '',
  object_type varchar(64) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  package_id bigint(20) unsigned NOT NULL DEFAULT 0,
  result varchar(20) NOT NULL DEFAULT '',
  code varchar(64) NOT NULL DEFAULT '',
  details text NOT NULL,
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY object (object_type,object_id)
) {$charset};",
				"CREATE TABLE {$snaps} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_type varchar(64) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  package_id bigint(20) unsigned NOT NULL DEFAULT 0,
  base_hash varchar(64) NOT NULL DEFAULT '',
  state longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY object (object_type,object_id)
) {$charset};",
			)
		);
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Writes one audit entry. Details are metadata only (IDs, counts, codes), never content.
	 *
	 * @param array<string, mixed> $entry Keys ability, result (one of RESULTS), code, object_type, object_id, package_id,
	 *                                    details (array), user_id (defaults to the current user).
	 * @return int Entry ID, 0 when nothing was written.
	 */
	public static function audit( array $entry ): int {
		global $wpdb;
		$result = in_array( $entry['result'] ?? '', self::RESULTS, true ) ? $entry['result'] : 'failed';
		$json   = (string) wp_json_encode( (object) ( $entry['details'] ?? array() ) );
		$ok     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, no API exists.
			self::table( 'audit' ),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => (int) ( $entry['user_id'] ?? get_current_user_id() ),
				'ability'     => substr( (string) ( $entry['ability'] ?? '' ), 0, 191 ),
				'object_type' => substr( (string) ( $entry['object_type'] ?? '' ), 0, 64 ),
				'object_id'   => (int) ( $entry['object_id'] ?? 0 ),
				'package_id'  => (int) ( $entry['package_id'] ?? 0 ),
				'result'      => $result,
				'code'        => substr( (string) ( $entry['code'] ?? '' ), 0, 64 ),
				'details'     => strlen( $json ) > self::MAX_DETAILS ? '{"truncated":true}' : $json,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Stores the state of an object before a change.
	 *
	 * @param string               $object_type Post type or another object type.
	 * @param int                  $object_id   Object ID.
	 * @param string               $base_hash   Hash of the state.
	 * @param array<string, mixed> $state       Previous state.
	 * @param int                  $package_id  Change package, 0 for none.
	 * @return int Snapshot ID, 0 when nothing was written.
	 */
	public static function snapshot( string $object_type, int $object_id, string $base_hash, array $state, int $package_id = 0 ): int {
		global $wpdb;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, no API exists.
			self::table( 'snapshots' ),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => get_current_user_id(),
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'package_id'  => $package_id,
				'base_hash'   => $base_hash,
				'state'       => (string) wp_json_encode( $state ),
			),
			array( '%s', '%d', '%s', '%d', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One snapshot with its state decoded, or null.
	 *
	 * @param int $id Snapshot ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_snapshot( int $id ): ?array {
		global $wpdb;
		$table = self::table( 'snapshots' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		if ( ! $row ) {
			return null;
		}
		$row['state'] = json_decode( (string) $row['state'], true );
		return $row;
	}

	/**
	 * Calls of a user since a time: count and the oldest entry, for the rate limit. Rate limit rejections do not count.
	 *
	 * @param int    $user_id User ID.
	 * @param string $since   UTC time, Y-m-d H:i:s.
	 * @return array{0: int, 1: string|null} Count and the oldest created_at.
	 */
	public static function calls_since( int $user_id, string $since ): array {
		global $wpdb;
		$table = self::table( 'audit' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS calls, MIN(created_at) AS oldest FROM {$table} WHERE user_id = %d AND created_at > %s AND code <> 'leitvia_rate_limited'", $user_id, $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table; an indexed count, never cached.
		return array( (int) ( $row->calls ?? 0 ), $row->oldest ?? null );
	}

	/**
	 * Deletes entries older than the retention period, then the oldest entries above the size limit. Runs daily on
	 * the cron event CRON.
	 */
	public static function prune(): void {
		global $wpdb;
		$settings = Gateway::settings();
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - (int) $settings['retention_days'] * DAY_IN_SECONDS );
		$max      = (int) $settings['max_table_mb'] * MB_IN_BYTES;
		foreach ( array(
			'audit'     => 'LENGTH(ability) + LENGTH(object_type) + LENGTH(code) + LENGTH(details)',
			'snapshots' => 'LENGTH(object_type) + LENGTH(base_hash) + LENGTH(state)',
		) as $name => $bytes ) {
			$table = self::table( $name );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
			self::trim( $table, $bytes, $max );
		}
	}

	/**
	 * Deletes the oldest rows until the table is under the size limit. The size is the sum of the variable columns plus
	 * ROW_BYTES per row, independent of stale table statistics.
	 *
	 * @param string $table Table name.
	 * @param string $bytes SQL expression for the variable bytes of a row.
	 * @param int    $max   Limit in bytes.
	 */
	private static function trim( string $table, string $bytes, int $max ): void {
		global $wpdb;
		$row_bytes = self::ROW_BYTES;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table, constant SQL expressions.
		$excess = (int) $wpdb->get_var( "SELECT COUNT(*) * {$row_bytes} + COALESCE(SUM({$bytes}), 0) FROM {$table}" ) - $max;
		$last   = 0;
		while ( $excess > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, {$row_bytes} + {$bytes} AS bytes FROM {$table} WHERE id > %d ORDER BY id LIMIT 500", $last ) );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$last    = (int) $row->id;
				$excess -= (int) $row->bytes;
				if ( $excess <= 0 ) {
					break;
				}
			}
		}
		if ( $last ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $last ) );
		}
		// phpcs:enable
	}

	/**
	 * Registers the exporter for wp_privacy_personal_data_exporters.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['leitvia-studio-gateway'] = array(
			/* translators: %s: short product name */
			'exporter_friendly_name' => sprintf( __( '%s Gateway audit log', 'leitvia-studio' ), leitvia_studio_brand_short() ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the eraser for wp_privacy_personal_data_erasers.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['leitvia-studio-gateway'] = array(
			/* translators: %s: short product name */
			'eraser_friendly_name' => sprintf( __( '%s Gateway audit log', 'leitvia-studio' ), leitvia_studio_brand_short() ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports the audit entries of the user with this email, 100 per page.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$table = self::table( 'audit' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, ability, object_type, object_id, result, code FROM {$table} WHERE user_id = %d ORDER BY id LIMIT 100 OFFSET %d", $user->ID, ( max( 1, $page ) - 1 ) * 100 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
		$data  = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'leitvia-gateway-audit',
				/* translators: %s: short product name */
				'group_label' => sprintf( __( '%s Gateway audit log', 'leitvia-studio' ), leitvia_studio_brand_short() ),
				'item_id'     => 'leitvia-audit-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Time (UTC)', 'leitvia-studio' ),
						'value' => $row['created_at'],
					),
					array(
						'name'  => __( 'Ability', 'leitvia-studio' ),
						'value' => $row['ability'],
					),
					array(
						'name'  => __( 'Object', 'leitvia-studio' ),
						'value' => trim( $row['object_type'] . ' ' . ( $row['object_id'] ? $row['object_id'] : '' ) ),
					),
					array(
						'name'  => __( 'Result', 'leitvia-studio' ),
						'value' => trim( $row['result'] . ' ' . $row['code'] ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 100,
		);
	}

	/**
	 * Removes the user from the audit log and the snapshots of this site; the entries stay without a user.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase( string $email ): array {
		global $wpdb;
		$user    = get_user_by( 'email', $email );
		$removed = 0;
		if ( $user ) {
			foreach ( array( 'audit', 'snapshots' ) as $name ) {
				$removed += (int) $wpdb->update( self::table( $name ), array( 'user_id' => 0 ), array( 'user_id' => $user->ID ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			}
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
