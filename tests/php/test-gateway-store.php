<?php
/**
 * Gateway tables, settings, retention and privacy tools.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Store;

/**
 * Tables per site, audit entries, snapshots, pruning by age and size, exporter and eraser, settings.
 */
class Test_Gateway_Store extends WP_UnitTestCase {

	/**
	 * Whether a table of the current site exists; temporary tables of the test suite count.
	 *
	 * @param string $name 'audit' or 'snapshots'.
	 */
	private static function table_exists( string $name ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$exists   = false !== $wpdb->query( 'SELECT 1 FROM ' . Store::table( $name ) . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test.
		$wpdb->suppress_errors( $suppress );
		return $exists;
	}

	/**
	 * Activation installs tables, schema version, role and the prune event.
	 */
	public function test_activation_installs_the_site(): void {
		global $wpdb;
		delete_option( Store::VERSION_OPTION );
		remove_role( Gateway::ROLE );
		wp_clear_scheduled_hook( Store::CRON );

		do_action( 'activate_' . plugin_basename( LEITVIA_STUDIO_FILE ), false );

		$this->assertSame( Store::VERSION, (int) get_option( Store::VERSION_OPTION ) );
		$this->assertNotNull( get_role( Gateway::ROLE ) );
		$this->assertNotFalse( wp_next_scheduled( Store::CRON ) );
		$this->assertTrue( has_action( Store::CRON, array( Store::class, 'prune' ) ) > 0 );
		foreach ( array( 'audit', 'snapshots' ) as $name ) {
			$this->assertTrue( self::table_exists( $name ), $name );
			$keys = wp_list_pluck( $wpdb->get_results( 'SHOW INDEX FROM ' . Store::table( $name ) ), 'Column_name', 'Key_name' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test.
			$this->assertArrayHasKey( 'user_created', $keys, $name );
			$this->assertArrayHasKey( 'object', $keys, $name );
		}

		do_action( 'deactivate_' . plugin_basename( LEITVIA_STUDIO_FILE ), false );
		$this->assertFalse( wp_next_scheduled( Store::CRON ) );
		$this->assertNotNull( get_role( Gateway::ROLE ), 'the role stays after deactivation' );
	}

	/**
	 * A site with an older schema version is installed on the next load.
	 */
	public function test_old_schema_version_installs_on_load(): void {
		update_option( Store::VERSION_OPTION, 0 );
		remove_role( Gateway::ROLE );
		Gateway::maybe_install();
		$this->assertSame( Store::VERSION, (int) get_option( Store::VERSION_OPTION ) );
		$this->assertNotNull( get_role( Gateway::ROLE ) );
	}

	/**
	 * Network wide activation installs every site; a new site is installed while Studio is active for the network.
	 *
	 * @group ms-required
	 */
	public function test_multisite_installs_each_site(): void {
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		$this->assertFalse( self::table_exists( 'audit' ), 'not network active, not installed' );
		restore_current_blog();

		do_action( 'activate_' . plugin_basename( LEITVIA_STUDIO_FILE ), true );
		switch_to_blog( $site );
		$this->assertTrue( self::table_exists( 'audit' ) );
		$this->assertTrue( self::table_exists( 'snapshots' ) );
		$this->assertSame( Store::VERSION, (int) get_option( Store::VERSION_OPTION ) );
		$this->assertNotNull( get_role( Gateway::ROLE ) );
		restore_current_blog();

		update_site_option( 'active_sitewide_plugins', array( plugin_basename( LEITVIA_STUDIO_FILE ) => time() ) );
		$new = self::factory()->blog->create();
		switch_to_blog( $new );
		$this->assertTrue( self::table_exists( 'audit' ) );
		$this->assertSame( Store::VERSION, (int) get_option( Store::VERSION_OPTION ) );
		$this->assertNotNull( get_role( Gateway::ROLE ) );
		restore_current_blog();
	}

	/**
	 * An audit entry holds time, user, ability, object, package, result, code and short details.
	 */
	public function test_audit_entry(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$id  = Store::audit(
			array(
				'ability'     => 'leitvia/update-block',
				'object_type' => 'page',
				'object_id'   => 12,
				'package_id'  => 7,
				'result'      => 'rolled_back',
				'code'        => 'leitvia_verify',
				'details'     => array( 'blocks' => 2 ),
			)
		);
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Store::table( 'audit' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test.
		$this->assertSame( (string) $user, $row['user_id'] );
		$this->assertSame( 'leitvia/update-block', $row['ability'] );
		$this->assertSame( array( 'page', '12', '7', 'rolled_back', 'leitvia_verify', '{"blocks":2}' ), array( $row['object_type'], $row['object_id'], $row['package_id'], $row['result'], $row['code'], $row['details'] ) );
		$this->assertLessThanOrEqual( 2, abs( time() - strtotime( $row['created_at'] . ' UTC' ) ) );

		$big = Store::audit(
			array(
				'result'  => 'surprise',
				'details' => array( 'list' => range( 1, 500 ) ),
			)
		);
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT result, details FROM ' . Store::table( 'audit' ) . ' WHERE id = %d', $big ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test.
		$this->assertSame( 'failed', $row['result'], 'unknown results count as failed' );
		$this->assertSame( '{"truncated":true}', $row['details'], 'details stay under 1 KB' );
	}

	/**
	 * A snapshot keeps the previous state with its base hash.
	 */
	public function test_snapshot(): void {
		$id   = Store::snapshot( 'page', 5, 'abc123', array( 'post_content' => '<!-- wp:paragraph --><p>"a" \\ b</p><!-- /wp:paragraph -->' ), 3 );
		$snap = Store::get_snapshot( $id );
		$this->assertSame( 'abc123', $snap['base_hash'] );
		$this->assertSame( '<!-- wp:paragraph --><p>"a" \\ b</p><!-- /wp:paragraph -->', $snap['state']['post_content'] );
		$this->assertSame( '3', $snap['package_id'] );
		$this->assertNull( Store::get_snapshot( $id + 1000 ) );
	}

	/**
	 * Entries older than the retention period go, newer ones stay.
	 */
	public function test_prune_by_age(): void {
		global $wpdb;
		$old      = Store::audit( array( 'result' => 'ok' ) );
		$new      = Store::audit( array( 'result' => 'ok' ) );
		$old_snap = Store::snapshot( 'page', 1, 'a', array() );
		$new_snap = Store::snapshot( 'page', 1, 'b', array() );
		$past     = gmdate( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS );
		$wpdb->update( Store::table( 'audit' ), array( 'created_at' => $past ), array( 'id' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test.
		$wpdb->update( Store::table( 'snapshots' ), array( 'created_at' => $past ), array( 'id' => $old_snap ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test.

		Store::prune();

		$ids = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . Store::table( 'audit' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test.
		$this->assertNotContains( $old, $ids );
		$this->assertContains( $new, $ids );
		$this->assertNull( Store::get_snapshot( $old_snap ) );
		$this->assertNotNull( Store::get_snapshot( $new_snap ) );
	}

	/**
	 * Above the size limit the oldest entries go until the table fits.
	 */
	public function test_prune_by_size(): void {
		update_option( Gateway::OPTION, array( 'max_table_mb' => 1 ) );
		$ids = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$ids[] = Store::snapshot( 'page', $i, 'h', array( 'post_content' => str_repeat( 'x', 400 * KB_IN_BYTES ) ) );
		}
		Store::prune();
		$this->assertNull( Store::get_snapshot( $ids[0] ) );
		$this->assertNull( Store::get_snapshot( $ids[1] ) );
		$this->assertNotNull( Store::get_snapshot( $ids[2] ) );
		$this->assertNotNull( Store::get_snapshot( $ids[3] ) );
	}

	/**
	 * The exporter lists the entries of a user; the eraser removes the user from them.
	 */
	public function test_exporter_and_eraser(): void {
		$user  = self::factory()->user->create( array( 'user_email' => 'agent@example.org' ) );
		$other = self::factory()->user->create();
		Store::audit(
			array(
				'user_id' => $user,
				'ability' => 'leitvia/get-contract',
				'result'  => 'ok',
			)
		);
		Store::audit(
			array(
				'user_id' => $other,
				'ability' => 'leitvia/get-tokens',
				'result'  => 'ok',
			)
		);
		Store::snapshot( 'page', 1, 'h', array() );
		$this->assertArrayHasKey( 'leitvia-studio-gateway', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'leitvia-studio-gateway', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );

		$export = Store::export( 'agent@example.org' );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'leitvia/get-contract', $export['data'][0]['data'][1]['value'] );

		$erased = Store::erase( 'agent@example.org' );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertSame( array(), Store::export( 'agent@example.org' )['data'] );
		$this->assertSame( 0, Store::calls_since( $user, '2000-01-01 00:00:00' )[0] );
		$this->assertSame( 1, Store::calls_since( $other, '2000-01-01 00:00:00' )[0], 'entries of other users keep their user' );
		$this->assertSame( array(), Store::export( 'nobody@example.org' )['data'] );
	}

	/**
	 * Settings have defaults; valid values are kept, invalid ones keep the saved value.
	 */
	public function test_settings(): void {
		$this->assertSame( Gateway::DEFAULTS, Gateway::settings() );
		$this->assertSame( 90, Gateway::DEFAULTS['retention_days'] );
		$this->assertSame( 50, Gateway::DEFAULTS['max_table_mb'] );
		$this->assertSame( 60, Gateway::DEFAULTS['rate_per_minute'] );

		update_option(
			Gateway::OPTION,
			array(
				'retention_days'  => '30',
				'rate_per_minute' => 0,
				'profile'         => 'full',
				'overrides'       => array(
					'areas' => array( 'design' => 'autonomous' ),
					'kinds' => array( 'prices' ),
				),
				'unknown'         => 1,
			)
		);
		$settings = Gateway::settings();
		$this->assertSame( 30, $settings['retention_days'] );
		$this->assertSame( 60, $settings['rate_per_minute'], 'out of range keeps the saved value' );
		$this->assertSame( 'full', $settings['profile'] );
		$this->assertSame(
			array(
				'areas' => array( 'design' => 'autonomous' ),
				'kinds' => array( 'prices' ),
			),
			$settings['overrides']
		);
		$this->assertArrayNotHasKey( 'unknown', get_option( Gateway::OPTION ) );

		update_option(
			Gateway::OPTION,
			array(
				'profile'   => 'reckless',
				'overrides' => array( 'areas' => array( 'admin' => 'whatever' ) ),
			)
		);
		$settings = Gateway::settings();
		$this->assertSame( 'full', $settings['profile'] );
		$this->assertSame( array( 'design' => 'autonomous' ), $settings['overrides']['areas'] );
		$this->assertSame( 30, $settings['retention_days'], 'missing keys keep the saved value' );
	}
}
