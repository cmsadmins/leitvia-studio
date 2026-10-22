<?php
/**
 * Repeater rows on the front end and their life cycle with the entry.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * The "Child entries" query variation lists the rows of the current entry; rows follow the entry into the trash.
 */
class Test_Children_Query extends WP_UnitTestCase {

	/**
	 * Stores the fixture and registers it.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Model::OPTION, json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/model/events.json' ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		Model::forget();
		Registrar::register();
	}

	/**
	 * Drops the cached model.
	 */
	public function tear_down(): void {
		Model::forget();
		parent::tear_down();
	}

	/**
	 * Creates a repeater row.
	 *
	 * @param int    $event Entry ID.
	 * @param string $title Title.
	 * @param int    $order Menu order.
	 */
	private function session( int $event, string $title, int $order ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'event_session',
				'post_parent' => $event,
				'post_title'  => $title,
				'menu_order'  => $order,
			)
		);
	}

	/**
	 * Rendering the variation on an event shows only its sessions, in menu order, also to visitors.
	 */
	public function test_query_lists_rows_of_current_entry_in_order(): void {
		$event = self::factory()->post->create( array( 'post_type' => 'event' ) );
		$other = self::factory()->post->create( array( 'post_type' => 'event' ) );
		$this->session( $event, 'Closing talk', 2 );
		$this->session( $event, 'Opening talk', 1 );
		$this->session( $other, 'Foreign talk', 1 );

		// The variation stores the entry it was inserted in (here another one) as parents; the current entry counts.
		$markup = '<!-- wp:query {"queryId":1,"namespace":"leitvia/children","query":{"perPage":100,"pages":0,"offset":0,"postType":"event_session","order":"desc","orderBy":"date","inherit":false,"parents":[' . $other . ']}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div>'
			. '<!-- /wp:query -->';

		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $event ) );
		$this->assertTrue( have_posts() );
		the_post();
		$html = do_blocks( $markup );
		wp_reset_postdata();

		$this->assertStringContainsString( 'Opening talk', $html );
		$this->assertStringContainsString( 'Closing talk', $html );
		$this->assertStringNotContainsString( 'Foreign talk', $html );
		$this->assertLessThan( strpos( $html, 'Closing talk' ), strpos( $html, 'Opening talk' ) );

		// Nothing while the password of the entry is missing.
		wp_update_post(
			array(
				'ID'            => $event,
				'post_password' => 'secret',
			)
		);
		$this->go_to( get_permalink( $event ) );
		the_post();
		$this->assertStringNotContainsString( 'Opening talk', do_blocks( $markup ) );
		wp_reset_postdata();

		// A query without the variation stays untouched.
		$this->assertStringNotContainsString( 'Opening talk', do_blocks( str_replace( '"namespace":"leitvia/children",', '', $markup ) ) );
	}

	/**
	 * Rows go to the trash with the entry, come back with it, and are trashed, not deleted, when the entry is deleted.
	 */
	public function test_rows_follow_entry_into_trash(): void {
		$event = self::factory()->post->create( array( 'post_type' => 'event' ) );
		$row   = $this->session( $event, 'Talk', 0 );
		$this->assertSame( 'inherit', get_post_status( $row ) );

		$deleted = $this->session( $event, 'Deleted before', 1 );
		wp_trash_post( $deleted );

		wp_trash_post( $event );
		$this->assertSame( 'trash', get_post_status( $row ) );
		wp_untrash_post( $event );
		$this->assertSame( 'inherit', get_post_status( $row ) );
		$this->assertSame( 'trash', get_post_status( $deleted ), 'A row deleted on its own stays in the trash.' );
		$this->assertFalse( metadata_exists( 'post', $row, Registrar::TRASHED_WITH_ENTRY ) );

		wp_delete_post( $event, true );
		$this->assertSame( 'trash', get_post_status( $row ) );

		// Publishing state needs nothing: rows read through their entry.
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'draft',
			)
		);
		$child = $this->session( $draft, 'Draft talk', 0 );
		wp_publish_post( $draft );
		$this->assertSame( 'inherit', get_post_status( $child ) );
	}
}
