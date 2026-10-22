<?php
/**
 * Gateway ability helper, agent role and rate limit.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Store;

/**
 * Abilities::read(), permission, audit without payload, rate limit before the callback, role capabilities.
 */
class Test_Gateway_Abilities extends WP_UnitTestCase {

	private const NAME = 'leitvia/test-read';

	/**
	 * Calls of the ability callback.
	 *
	 * @var int
	 */
	private static int $calls = 0;

	/**
	 * Registers the test ability through the helper and hands it to core.
	 */
	public function set_up(): void {
		parent::set_up();
		self::$calls = 0;
		wp_get_abilities();
		add_action(
			'leitvia_studio_init',
			static function (): void {
				Abilities::read(
					self::NAME,
					'Test read',
					'Reads a test value.',
					array(
						'type'       => 'object',
						'properties' => array( 'secret' => array( 'type' => 'string' ) ),
					),
					array( 'type' => 'object' ),
					static function ( $input ): array {
						++self::$calls;
						return array( 'echo' => $input['secret'] ?? '' );
					}
				);
			}
		);
		leitvia_studio()->reset_for_tests();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Removes the ability and the category.
	 */
	public function tear_down(): void {
		if ( wp_has_ability( self::NAME ) ) {
			wp_unregister_ability( self::NAME );
		}
		if ( wp_has_ability_category( 'leitvia' ) ) {
			wp_unregister_ability_category( 'leitvia' );
		}
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * The helper registers in the group read with read annotations, REST on and never public.
	 */
	public function test_registration(): void {
		$this->assertSame( 'read', leitvia_studio()->registry( 'abilities' )->get( self::NAME )['group'] );
		$ability = wp_get_ability( self::NAME );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame( 'leitvia', $ability->get_category() );
		$this->assertSame(
			array(
				'readonly'      => true,
				'destructive'   => false,
				'idempotent'    => true,
				'openWorldHint' => false,
			),
			array_intersect_key( $ability->get_meta_item( 'annotations' ), array_flip( array( 'readonly', 'destructive', 'idempotent', 'openWorldHint' ) ) )
		);
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
		$this->assertFalse( $ability->get_meta_item( 'public' ) );
	}

	/**
	 * User 0 and users without edit_posts never get through; an agent does.
	 */
	public function test_permission(): void {
		$ability = wp_get_ability( self::NAME );
		wp_set_current_user( 0 );
		$this->assertFalse( $ability->check_permissions( array() ) );
		$this->assertTrue( is_wp_error( $ability->execute( array() ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $ability->check_permissions( array() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assertTrue( $ability->check_permissions( 'not an object' ), 'tolerates invalid input' );
		$this->assertSame( 0, self::$calls );
	}

	/**
	 * Each call writes an audit entry with the ability name and no payload.
	 */
	public function test_audit_without_payload(): void {
		global $wpdb;
		$user = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		wp_set_current_user( $user );
		$this->assertSame( array( 'echo' => 'top secret' ), wp_get_ability( self::NAME )->execute( array( 'secret' => 'top secret' ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Store::table( 'audit' ) . ' WHERE user_id = %d', $user ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test.
		$this->assertCount( 1, $rows );
		$this->assertSame( array( self::NAME, 'ok', '', '{}' ), array( $rows[0]['ability'], $rows[0]['result'], $rows[0]['code'], $rows[0]['details'] ) );
		$this->assertStringNotContainsString( 'top secret', implode( ' ', $rows[0] ) );
	}

	/**
	 * Above rate_per_minute the call is rejected before the callback runs, with a retry time.
	 */
	public function test_rate_limit(): void {
		update_option( Gateway::OPTION, array( 'rate_per_minute' => 2 ) );
		$user = self::factory()->user->create( array( 'role' => Gateway::ROLE ) );
		wp_set_current_user( $user );
		$ability = wp_get_ability( self::NAME );
		$ability->execute( array() );
		$ability->execute( array() );
		$error = $ability->execute( array() );
		$this->assertWPError( $error );
		$this->assertSame( 'leitvia_rate_limited', $error->get_error_code() );
		$this->assertSame( 429, $error->get_error_data()['status'] );
		$this->assertMatchesRegularExpression( '/^after:([1-9]|[1-5][0-9]|60)$/', $error->get_error_data()['retry'] );
		$this->assertSame( 2, self::$calls, 'the callback did not run' );
		$this->assertSame( 2, Store::calls_since( $user, '2000-01-01 00:00:00' )[0], 'rejections do not count' );

		// Another user has their own limit; calls older than a minute do not count.
		wp_set_current_user( self::factory()->user->create( array( 'role' => Gateway::ROLE ) ) );
		$this->assertNotWPError( $ability->execute( array() ) );
		global $wpdb;
		$wpdb->update( Store::table( 'audit' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 61 ) ), array( 'user_id' => $user ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test.
		wp_set_current_user( $user );
		$this->assertNull( Abilities::rate_limit( $user ) );
	}

	/**
	 * The agent role edits and publishes content and theme options, nothing more.
	 */
	public function test_role(): void {
		$role = get_role( Gateway::ROLE );
		$this->assertSame( 'Leitvia Agent', wp_roles()->role_names[ Gateway::ROLE ] );
		foreach ( Gateway::CAPS as $cap ) {
			$this->assertTrue( $role->has_cap( $cap ), $cap );
		}
		foreach ( array( 'unfiltered_html', 'manage_options', 'edit_users', 'install_plugins', 'activate_plugins', 'delete_users' ) as $cap ) {
			$this->assertFalse( $role->has_cap( $cap ), $cap );
		}
	}
}
