<?php
/**
 * Single view of entries of the content model.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Model\Entry_View;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * Template single-<type> without byline and reading time, the section "More <type>", the facts list and a byline that
 * hides without an author.
 */
class Test_Entry_View extends WP_UnitTestCase {

	/**
	 * Registers the model fixture and the templates; renders as an anonymous visitor.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Model::OPTION, json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/model/events.json' ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		Model::forget();
		Registrar::register();
		Entry_View::register();
		wp_set_current_user( 0 );
	}

	/**
	 * Unregisters the templates and drops the cached model.
	 */
	public function tear_down(): void {
		foreach ( array( 'event', 'person' ) as $type ) {
			if ( WP_Block_Templates_Registry::get_instance()->is_registered( 'leitvia-studio//single-' . $type ) ) {
				unregister_block_template( 'leitvia-studio//single-' . $type );
			}
		}
		unset( $GLOBALS['post'] );
		Model::forget();
		parent::tear_down();
	}

	/**
	 * A published entry of a type.
	 *
	 * @param string               $type  Post type.
	 * @param string               $title Title.
	 * @param array<string, mixed> $meta  Meta values.
	 */
	private function entry( string $type, string $title, array $meta = array() ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => $type,
				'post_title'  => $title,
				'post_status' => 'publish',
				'meta_input'  => $meta,
			)
		);
	}

	/**
	 * Every public type of the model gets single-<type> with the title as h1 and without byline and reading time;
	 * repeater types get none.
	 */
	public function test_template_per_public_type(): void {
		$registry = WP_Block_Templates_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( 'leitvia-studio//single-person' ) );
		$this->assertFalse( $registry->is_registered( 'leitvia-studio//single-event_session' ) );
		$template = $registry->get_registered( 'leitvia-studio//single-event' );
		$this->assertInstanceOf( WP_Block_Template::class, $template );
		$this->assertSame( 'single-event', $template->slug );
		$this->assertSame( array( 'event' ), $template->post_types );
		$this->assertStringContainsString( '<!-- wp:post-title {"level":1,', $template->content );
		$this->assertStringContainsString( '"postType":"event"', $template->content );
		$this->assertStringContainsString( '>More events</h2>', $template->content );
		$this->assertStringContainsString( '<!-- wp:post-content {"align":"wide",', $template->content, 'wide and full blocks of the content reach the width of the entry' );
		foreach ( array( 'post-author-name', 'post-time-to-read', 'post-date', 'hidden-page-post-byline' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $template->content, $absent );
		}
		$this->assertSame( $template->content, serialize_blocks( parse_blocks( $template->content ) ), 'markup as the serializer writes it' );
	}

	/**
	 * "More <type>" lists other entries of the type without the current one, and disappears with its heading when
	 * there is no other entry.
	 */
	public function test_more_entries(): void {
		$template = WP_Block_Templates_Registry::get_instance()->get_registered( 'leitvia-studio//single-event' );
		preg_match( '#<!-- wp:query .*<!-- /wp:query -->#s', $template->content, $query );
		$this->assertNotEmpty( $query );

		$current         = $this->entry( 'event', 'Spring walk' );
		$GLOBALS['post'] = get_post( $current ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The single view sets the current entry.
		$this->assertSame( '', trim( do_blocks( $query[0] ) ), 'no other entry, no section' );

		$this->entry( 'event', 'Autumn walk' );
		$this->entry( 'person', 'Ada Brandt' );
		$html = do_blocks( $query[0] );
		$this->assertStringContainsString( 'More events', $html );
		$this->assertStringContainsString( 'Autumn walk', $html );
		$this->assertStringNotContainsString( 'Spring walk', $html );
		$this->assertStringNotContainsString( 'Ada Brandt', $html );
	}

	/**
	 * The single page as the front end renders it, with the query nested in main: "More events" leaves the current
	 * entry out there too. A copy of the query attribute in render_block_data did not reach a nested post template.
	 */
	public function test_rendered_single_page_leaves_the_current_entry_out(): void {
		global $_wp_current_template_id, $_wp_current_template_content;
		$current = $this->entry( 'event', 'Spring walk' );
		$this->entry( 'event', 'Autumn walk' );
		$this->go_to( get_permalink( $current ) );
		$this->assertTrue( is_singular( 'event' ) );
		$template                     = WP_Block_Templates_Registry::get_instance()->get_registered( 'leitvia-studio//single-event' );
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;
		$html                         = get_the_block_template_html();
		$_wp_current_template_id      = null;
		$_wp_current_template_content = null;
		$more                         = (string) strstr( $html, 'More events' );
		$this->assertNotSame( '', $more, 'section More events rendered' );
		$this->assertStringContainsString( 'Autumn walk', $more );
		$this->assertStringNotContainsString( 'Spring walk', $more );
	}

	/**
	 * The pattern Entry facts renders as a description list with the label as dt and the bound value as dd; the
	 * suffix adds the currency to a value and stays away from an empty one.
	 */
	public function test_facts_pattern_is_a_description_list(): void {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia-studio/content-entry-facts' );
		$this->assertIsArray( $pattern );
		$event  = $this->entry(
			'event',
			'Spring walk',
			array(
				'event_start' => '2026-11-05T18:30:00',
				'event_price' => 12.5,
			)
		);
		$markup = str_replace( array( '"entry_date"', '"entry_price"', '"entry_places"' ), array( '"event_start"', '"event_price"', '"event_venue"' ), $pattern['content'] );
		$block  = new WP_Block(
			parse_blocks( $markup )[0],
			array(
				'postId'   => $event,
				'postType' => 'event',
			)
		);
		$html   = $block->render();
		$this->assertMatchesRegularExpression( '#<dl\b[^>]*class="[^"]*is-style-leitvia-facts#', $html );
		$this->assertSame( 3, substr_count( $html, '<dt' ) );
		$this->assertSame( 3, substr_count( $html, '<dd' ) );
		$this->assertStringNotContainsString( '<p', $html );
		$this->assertMatchesRegularExpression( '#<dt[^>]*>Price</dt>\s*<dd[^>]*>12.5 €</dd>#', $html );
		$this->assertMatchesRegularExpression( '#<dt[^>]*>Free places</dt>\s*<dd[^>]*></dd>#', $html, 'empty field, no suffix' );
		$this->assertSame( substr_count( $html, '<div' ), substr_count( $html, '</div>' ) );
		$this->assertSame( 1, substr_count( $html, '</dl>' ) );
	}

	/**
	 * A byline group with the author block disappears when the post type has no author support; with an author it stays.
	 */
	public function test_byline_without_author(): void {
		$byline = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>By</p><!-- /wp:paragraph --><!-- wp:post-author-name /--></div><!-- /wp:group -->';
		$render = static function ( int $id ) use ( $byline ): string {
			$block = new WP_Block(
				parse_blocks( $byline )[0],
				array(
					'postId'   => $id,
					'postType' => (string) get_post_type( $id ),
				)
			);
			return $block->render();
		};
		$author = self::factory()->user->create( array( 'display_name' => 'Ada Brandt' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );
		$this->assertStringContainsString( 'Ada Brandt', $render( $post ) );
		$this->assertStringContainsString( '>By</p>', $render( $post ) );
		$this->assertSame( '', $render( $this->entry( 'event', 'Spring walk' ) ) );
	}
}
