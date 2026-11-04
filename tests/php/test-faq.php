<?php
/**
 * FAQ block: FAQPage node, merging, schema switch, shared details name.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Schema_Nodes;

/**
 * Renders saved FAQ markup on a single post, the way a template renders the content.
 */
class Test_Faq extends WP_UnitTestCase {

	/**
	 * Saved details block.
	 *
	 * @param string $question Summary.
	 * @param string $answer   Inner block markup.
	 */
	private static function details( string $question, string $answer ): string {
		return "<!-- wp:details -->\n<details class=\"wp-block-details\"><summary>{$question}</summary>{$answer}</details>\n<!-- /wp:details -->";
	}

	/**
	 * Saved paragraph block.
	 *
	 * @param string $html Paragraph HTML.
	 */
	private static function paragraph( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Saved FAQ block.
	 *
	 * @param string[]             $items Saved details blocks.
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private static function faq( array $items, array $attrs = array() ): string {
		return '<!-- wp:leitvia/faq' . ( $attrs ? ' ' . wp_json_encode( $attrs ) : '' ) . " -->\n<div class=\"wp-block-leitvia-faq\">" . implode( "\n\n", $items ) . "</div>\n<!-- /wp:leitvia/faq -->\n";
	}

	/**
	 * The questions of tests/fixtures/faq/faq-page.jsonld, with one empty question in between.
	 */
	private static function reference(): string {
		$list = "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Old links keep working.</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>Search rankings stay.</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->";
		return self::faq(
			array(
				self::details( 'How long does a typical project take?', self::paragraph( 'Most relaunches take twelve to sixteen weeks from the first workshop to going live.' ) ),
				self::details( 'Can we keep our existing content?', self::paragraph( 'Yes. We move <strong>posts, pages and media</strong> and check the redirects.' ) . "\n\n" . $list ),
				self::details( '', self::paragraph( 'An answer without a question.' ) ),
				self::details( 'Who maintains the site after launch?', self::paragraph( 'Your team edits the content; we take care of updates. <a href="/maintenance/" class="x">Read the maintenance terms</a>.' ) ),
			)
		);
	}

	/**
	 * Renders content on the permalink of a new post.
	 *
	 * @param string $content Post content.
	 */
	private function render( string $content ): string {
		Schema_Nodes::reset();
		$id = self::factory()->post->create( array( 'post_content' => $content ) );
		$this->go_to( get_permalink( $id ) );
		return do_blocks( $content );
	}

	/**
	 * Nodes of the request.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function nodes(): array {
		return Schema_Nodes::current()['@graph'] ?? array();
	}

	/**
	 * Forgets the nodes of the test.
	 */
	public function tear_down(): void {
		Schema_Nodes::reset();
		parent::tear_down();
	}

	/**
	 * The block is registered from the build with details as its only child.
	 */
	public function test_registered_with_details_only(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'leitvia/faq' );
		$this->assertNotNull( $type );
		$this->assertSame( array( 'core/details' ), $type->allowed_blocks );
	}

	/**
	 * One FAQPage node as in the reference file: empty questions left out, answers only with Google's tags.
	 */
	public function test_faq_page_matches_reference(): void {
		$this->render( self::reference() );
		$expected = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/faq/faq-page.jsonld' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$this->assertSame( $expected, json_decode( (string) wp_json_encode( Schema_Nodes::current() ), true ) );
	}

	/**
	 * Two FAQ blocks give one FAQPage node; a block rendered twice adds no question twice.
	 */
	public function test_one_node_per_page(): void {
		$second = self::faq( array( self::details( 'What does it cost?', self::paragraph( 'From 18,000 euros.' ) ) ), array( 'exclusive' => true ) );
		$this->render( self::reference() . $second );
		do_blocks( $second );
		$nodes = self::nodes();
		$this->assertCount( 1, $nodes );
		$this->assertSame( 'FAQPage', $nodes[0]['@type'] );
		$this->assertCount( 4, $nodes[0]['mainEntity'] );
		$this->assertSame( 'What does it cost?', $nodes[0]['mainEntity'][3]['name'] );
	}

	/**
	 * Schema off, a block without answers and an archive page add no node.
	 */
	public function test_no_node(): void {
		$this->render( self::faq( array( self::details( 'Question?', self::paragraph( 'Answer.' ) ) ), array( 'schema' => false ) ) );
		$this->assertSame( array(), self::nodes() );

		$this->render( self::faq( array( self::details( 'Question without answer?', '' ) ) ) );
		$this->assertSame( array(), self::nodes() );

		Schema_Nodes::reset();
		$this->go_to( home_url( '/?s=faq' ) );
		do_blocks( self::reference() );
		$this->assertSame( array(), self::nodes() );
	}

	/**
	 * With one answer open at a time, the questions of a block share a name of their own; details inside an answer and
	 * the questions of another block do not get it.
	 */
	public function test_exclusive_shares_a_name_per_block(): void {
		$nested = self::details( 'Inner', self::paragraph( 'Nested.' ) );
		$items  = array( self::details( 'One?', self::paragraph( 'A.' ) . "\n\n" . $nested ), self::details( 'Two?', self::paragraph( 'B.' ) ) );
		$html   = $this->render( self::faq( $items, array( 'exclusive' => true ) ) . self::faq( $items, array( 'exclusive' => true ) ) . self::faq( $items ) );
		$tags   = new WP_HTML_Tag_Processor( $html );
		$names  = array();
		while ( $tags->next_tag( 'details' ) ) {
			$names[] = $tags->get_attribute( 'name' );
		}
		$this->assertCount( 9, $names );
		list( $a, $nested_a, $a2, $b, $nested_b, $b2, $c ) = $names;
		$this->assertIsString( $a );
		$this->assertSame( $a, $a2 );
		$this->assertNull( $nested_a );
		$this->assertIsString( $b );
		$this->assertSame( $b, $b2 );
		$this->assertNotSame( $a, $b );
		$this->assertNull( $nested_b );
		$this->assertNull( $c );
	}
}
