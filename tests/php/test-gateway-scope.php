<?php
/**
 * Gateway scope.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Scope;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * Allowed post types and statuses, content model types, the filter and the block list.
 */
class Test_Gateway_Scope extends WP_UnitTestCase {

	/**
	 * Registers the content model fixture (event, person, repeater event_session).
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Model::OPTION, json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/model/events.json' ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		Model::forget();
		Registrar::register();
	}

	/**
	 * Unregisters the content model.
	 */
	public function tear_down(): void {
		Registrar::deactivate();
		Model::forget();
		parent::tear_down();
	}

	/**
	 * Core types, token sets and content model entries are in scope; repeaters and blocked types are not.
	 */
	public function test_default_post_types(): void {
		$types = Scope::post_types();
		foreach ( array( 'post', 'page', 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'leitvia_tokens', 'event', 'person' ) as $type ) {
			$this->assertContains( $type, $types );
		}
		foreach ( array( 'event_session', 'user_request', 'wp_font_family', 'wp_font_face', 'leitvia_package', 'shop_order', 'revision', 'nav_menu_item' ) as $type ) {
			$this->assertNotContains( $type, $types );
		}
	}

	/**
	 * The filter removes types and adds content model types, never blocked or foreign ones.
	 */
	public function test_filter_cannot_lift_the_block_list(): void {
		add_filter(
			'leitvia_studio_gateway_post_types',
			static fn( array $types ): array => array_merge(
				array_diff( $types, array( 'post' ) ),
				array( 'event_session', 'user_request', 'wp_font_family', 'wp_font_face', 'leitvia_package', 'shop_order', 'shop_order_refund', 'product', 'revision' )
			)
		);
		$types = Scope::post_types();
		$this->assertNotContains( 'post', $types );
		$this->assertContains( 'event_session', $types, 'a repeater of the content model may be added' );
		foreach ( array( 'user_request', 'wp_font_family', 'wp_font_face', 'leitvia_package', 'shop_order', 'shop_order_refund', 'product', 'revision' ) as $type ) {
			$this->assertNotContains( $type, $types );
		}
		$this->assertTrue( Scope::blocked( 'shop_order_placehold' ) );
		$this->assertFalse( Scope::blocked( 'page' ) );
	}

	/**
	 * Published and draft posts are readable; private, password protected, blocked types and posts the user may not
	 * read are not.
	 */
	public function test_readable(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$posts = array(
			'publish'  => self::factory()->post->create(),
			'draft'    => self::factory()->post->create( array( 'post_status' => 'draft' ) ),
			'event'    => self::factory()->post->create( array( 'post_type' => 'event' ) ),
			'private'  => self::factory()->post->create( array( 'post_status' => 'private' ) ),
			'password' => self::factory()->post->create( array( 'post_password' => 'secret' ) ),
			'trash'    => self::factory()->post->create( array( 'post_status' => 'trash' ) ),
			'request'  => self::factory()->post->create(
				array(
					'post_type'   => 'user_request',
					'post_status' => 'request-pending',
				)
			),
			'session'  => self::factory()->post->create( array( 'post_type' => 'event_session' ) ),
			'tokens'   => self::factory()->post->create( array( 'post_type' => 'leitvia_tokens' ) ),
		);
		$this->assertSame( 'private', get_post_status( $posts['tokens'] ) );
		foreach ( array( 'publish', 'draft', 'event', 'tokens' ) as $key ) {
			$this->assertTrue( Scope::readable( $posts[ $key ] ), $key );
		}
		foreach ( array( 'private', 'password', 'trash', 'request', 'session' ) as $key ) {
			$this->assertFalse( Scope::readable( $posts[ $key ] ), $key );
		}
		$this->assertFalse( Scope::readable( 0 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertTrue( Scope::readable( get_post( $posts['publish'] ) ) );
		$this->assertFalse( Scope::readable( $posts['draft'] ), 'read_post decides' );
		$this->assertFalse( Scope::readable( $posts['tokens'] ), 'token sets need edit_theme_options' );
	}
}
