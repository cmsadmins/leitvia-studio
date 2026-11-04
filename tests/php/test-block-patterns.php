<?php
/**
 * Studio patterns built on the FAQ, icon list and share blocks: they render the blocks as the patterns promise.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Icon;
use LeitviaStudio\Blocks\Schema_Nodes;

/**
 * Renders the registered patterns content-faq-schema, content-icon-benefits and content-share-post on a single post.
 */
class Test_Block_Patterns extends WP_UnitTestCase {

	/**
	 * Forgets the structured data of the test.
	 */
	public function tear_down(): void {
		Schema_Nodes::reset();
		parent::tear_down();
	}

	/**
	 * Renders a registered pattern on the permalink of a new post, as the post content would.
	 *
	 * @param string $slug Pattern slug.
	 */
	private function render( string $slug ): string {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
		$this->assertIsArray( $pattern, $slug );
		Schema_Nodes::reset();
		$id = self::factory()->post->create( array( 'post_content' => $pattern['content'] ) );
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a single template.
		return do_blocks( $pattern['content'] );
	}

	/**
	 * FAQ with structured data: one h2, five questions with one shared name (one answer open at a time), the first
	 * open, and one FAQPage node with all five.
	 */
	public function test_faq_pattern_adds_one_faq_page_node(): void {
		$html  = $this->render( 'leitvia-studio/content-faq-schema' );
		$tags  = new WP_HTML_Tag_Processor( $html );
		$names = array();
		$open  = array();
		while ( $tags->next_tag( 'details' ) ) {
			$names[] = $tags->get_attribute( 'name' );
			$open[]  = null !== $tags->get_attribute( 'open' );
		}
		$this->assertSame( 1, substr_count( $html, '<h2' ) );
		$this->assertCount( 5, $names );
		$this->assertIsString( $names[0] );
		$this->assertCount( 1, array_unique( $names ) );
		$this->assertSame( array( true, false, false, false, false ), $open );
		$nodes = Schema_Nodes::current()['@graph'] ?? array();
		$this->assertCount( 1, $nodes );
		$this->assertSame( 'FAQPage', $nodes[0]['@type'] );
		$this->assertCount( 5, $nodes[0]['mainEntity'] );
	}

	/**
	 * Benefits with icons: one h2 and a list of five items, each with its own bundled icon hidden from screen readers.
	 */
	public function test_benefits_pattern_renders_five_icons(): void {
		$html = $this->render( 'leitvia-studio/content-icon-benefits' );
		$this->assertSame( 1, substr_count( $html, '<h2' ) );
		$this->assertSame( 1, substr_count( $html, '<ul role="list" class="wp-block-leitvia-icon-list' ) );
		$this->assertSame( 5, substr_count( $html, '<li class="wp-block-leitvia-icon-list-item"><svg class="leitvia-icon ' ) );
		$this->assertSame( 5, substr_count( $html, 'aria-hidden="true" focusable="false"' ) );
		foreach ( array( 'truck', 'recycle', 'credit-card', 'leaf', 'headphones' ) as $icon ) {
			$this->assertStringContainsString( Icon::icons()[ $icon ] . '</svg>', $html, $icon );
		}
	}

	/**
	 * Share at the end of a post: the invitation, then one named navigation that shares the post it sits in.
	 */
	public function test_share_pattern_shares_its_post(): void {
		$html = $this->render( 'leitvia-studio/content-share-post' );
		$this->assertSame( 1, substr_count( $html, 'aria-label="Share this post"' ) );
		$this->assertStringContainsString( rawurlencode( get_permalink() ), $html );
		$this->assertLessThan( strpos( $html, '<nav ' ), strpos( $html, '<p class="has-ink-muted-color' ) );
	}
}
