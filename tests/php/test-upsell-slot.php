<?php
/**
 * Upsell slot on Studio screens.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Admin\Admin;

/**
 * The slot prints only on Studio screens, respects the global switch and the per-user dismissal, and Free leaves it empty.
 */
class Test_Upsell_Slot extends WP_UnitTestCase {

	/**
	 * Registers the menu as an administrator, so Admin knows the Studio screen IDs.
	 */
	public function set_up(): void {
		parent::set_up();
		global $menu, $submenu;
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu per test.
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh admin menu per test.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Admin::register_menu();
	}

	/**
	 * Slot only on Studio screens, off with hide_product_notes and after dismissing.
	 */
	public function test_slot_renders_only_on_studio_screens_and_respects_switches(): void {
		$admin = get_current_user_id();
		add_action(
			'leitvia_studio_upsell_slot',
			static function (): void {
				echo '<p>note</p>';
			}
		);

		$html = Admin::render_slot( 'toplevel_page_leitvia-studio' );
		$this->assertStringContainsString( 'note', $html );
		$this->assertStringContainsString( '<aside class="leitvia-studio-notes" aria-label="Product notes">', $html );
		$this->assertStringContainsString( 'aria-label="Dismiss product notes">Dismiss</button>', $html );
		$this->assertSame( '', Admin::render_slot( 'dashboard' ) );

		update_user_meta( $admin, 'leitvia_studio_notes_dismissed', 1 );
		$this->assertSame( '', Admin::render_slot( 'toplevel_page_leitvia-studio' ) );

		delete_user_meta( $admin, 'leitvia_studio_notes_dismissed' );
		update_option( 'leitvia_studio_settings', array( 'hide_product_notes' => true ) );
		$this->assertSame( '', Admin::render_slot( 'toplevel_page_leitvia-studio' ) );
	}

	/**
	 * Without add-ons nothing hooks into the slot, so Studio prints no aside.
	 */
	public function test_free_adds_nothing_to_the_slot(): void {
		$this->assertFalse( has_action( 'leitvia_studio_upsell_slot' ) );
		$this->assertSame( '', Admin::render_slot( 'toplevel_page_leitvia-studio' ) );
	}

	/**
	 * Any logged-in user dismisses the notes for themselves; visitors cannot.
	 */
	public function test_dismiss_route(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/leitvia-studio/v1/notes/dismiss' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', get_user_meta( $subscriber, 'leitvia_studio_notes_dismissed', true ) );

		$request = new WP_REST_Request( 'DELETE', '/leitvia-studio/v1/notes/dismiss' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( '', get_user_meta( $subscriber, 'leitvia_studio_notes_dismissed', true ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'POST', '/leitvia-studio/v1/notes/dismiss' ) )->get_status() );
	}
}
