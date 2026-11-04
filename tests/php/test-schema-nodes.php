<?php
/**
 * JSON-LD nodes from blocks.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Schema_Nodes;

/**
 * Schema_Nodes collects nodes and prints one graph, nothing without nodes or with an SEO plugin.
 */
class Test_Schema_Nodes extends WP_UnitTestCase {

	/**
	 * Forgets the nodes and the SEO plugin filter.
	 */
	public function tear_down(): void {
		Schema_Nodes::reset();
		remove_all_filters( 'leitvia_studio_seo_plugin_active' );
		parent::tear_down();
	}

	/**
	 * No graph without nodes.
	 */
	public function test_nothing_without_nodes(): void {
		$this->assertNull( Schema_Nodes::current() );
	}

	/**
	 * All nodes print as one graph in one script element.
	 */
	public function test_prints_one_graph(): void {
		Schema_Nodes::add( array( '@type' => 'FAQPage' ) );
		Schema_Nodes::add( array( '@type' => 'Review' ) );
		$this->assertSame( array( 'FAQPage', 'Review' ), array_column( Schema_Nodes::current()['@graph'], '@type' ) );
		$out = get_echo( array( Schema_Nodes::class, 'print' ) );
		$this->assertSame( 1, substr_count( $out, 'application/ld+json' ) );
	}

	/**
	 * Nothing while an SEO plugin is active.
	 */
	public function test_off_with_seo_plugin(): void {
		Schema_Nodes::add( array( '@type' => 'FAQPage' ) );
		add_filter( 'leitvia_studio_seo_plugin_active', '__return_true' );
		$this->assertNull( Schema_Nodes::current() );
	}
}
