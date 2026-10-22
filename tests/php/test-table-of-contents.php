<?php
/**
 * Table of contents: list, generated ids, patterns, escaping.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Table_Of_Contents;

/**
 * Renders posts through core/post-content, like a template does, so the heading filter sees the content render.
 */
class Test_Table_Of_Contents extends WP_UnitTestCase {

	/**
	 * Saved heading markup.
	 *
	 * @param int    $level  Heading level.
	 * @param string $text   Heading HTML.
	 * @param string $anchor Own id, empty for none.
	 */
	private static function heading( int $level, string $text, string $anchor = '' ): string {
		$attrs = array_filter(
			array(
				'level'  => 2 === $level ? null : $level,
				'anchor' => '' === $anchor ? null : $anchor,
			)
		);
		$id    = '' === $anchor ? '' : ' id="' . $anchor . '"';
		return '<!-- wp:heading' . ( $attrs ? ' ' . wp_json_encode( $attrs ) : '' ) . " -->\n<h{$level} class=\"wp-block-heading\"{$id}>{$text}</h{$level}>\n<!-- /wp:heading -->\n";
	}

	/**
	 * Creates a post and renders it through core/post-content.
	 *
	 * @param string $content Post content.
	 */
	private function render_post( string $content ): string {
		Table_Of_Contents::forget();
		$id = self::factory()->post->create( array( 'post_content' => $content ) );
		$this->go_to( get_permalink( $id ) );
		setup_postdata( get_post( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a template.
		return do_blocks( '<!-- wp:post-content /-->' );
	}

	/**
	 * Removes the test pattern.
	 */
	public function tear_down(): void {
		unregister_block_pattern( 'leitvia-test/toc' );
		parent::tear_down();
	}

	/**
	 * (1) Levels 2 and 3 as nested lists in a nav named by its visible heading; level 4 stays out.
	 */
	public function test_nested_list_in_labelled_nav(): void {
		$html = $this->render_post( "<!-- wp:leitvia/table-of-contents /-->\n" . self::heading( 2, 'Start' ) . self::heading( 3, 'Install' ) . self::heading( 4, 'Deep' ) . self::heading( 2, 'Use' ) );
		$this->assertMatchesRegularExpression( '#<nav [^>]*aria-labelledby="([^"]+)"[^>]*><h2 id="\1" class="wp-block-leitvia-table-of-contents__title">On this page</h2>#', $html );
		$this->assertStringContainsString( '<ol><li><a href="#start">Start</a><ol><li><a href="#install">Install</a></li></ol></li><li><a href="#use">Use</a></li></ol></nav>', $html );
		$this->assertStringNotContainsString( 'href="#deep"', $html );
	}

	/**
	 * (2) Headings without an id get a stable, unique id; own anchors stay; levels 2 to 4 when set.
	 */
	public function test_ids_are_added_unique_and_own_anchors_stay(): void {
		$content = '<!-- wp:leitvia/table-of-contents {"levels":[2,3,4]} /-->' . "\n" . self::heading( 2, 'Usage' ) . self::heading( 3, 'Setup', 'own' ) . self::heading( 2, 'Usage' ) . self::heading( 4, 'Own' ) . self::heading( 2, 'own' );
		$html    = $this->render_post( $content );
		$this->assertStringContainsString( '<h2 id="usage" class="wp-block-heading">Usage</h2>', $html );
		$this->assertStringContainsString( '<h2 id="usage-2" class="wp-block-heading">Usage</h2>', $html );
		$this->assertStringContainsString( '<h3 class="wp-block-heading" id="own">Setup</h3>', $html );
		$this->assertStringContainsString( '<h4 id="own-2" class="wp-block-heading">Own</h4>', $html );
		$this->assertStringContainsString( '<h2 id="own-3" class="wp-block-heading">own</h2>', $html );
		$this->assertStringContainsString( 'href="#usage-2"', $html );
		$this->assertStringContainsString( 'href="#own"', $html );
		$this->assertSame( $html, $this->render_post( $content ), 'same ids on every render' );
	}

	/**
	 * (2) Without a table of contents in the post, headings stay as saved.
	 */
	public function test_no_ids_without_table_of_contents(): void {
		$html = $this->render_post( self::heading( 2, 'Usage' ) . self::heading( 2, 'More' ) );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Usage</h2>', $html );
	}

	/**
	 * (3) Fewer than two entries render nothing.
	 */
	public function test_fewer_than_two_entries_render_nothing(): void {
		$html = $this->render_post( "<!-- wp:leitvia/table-of-contents /-->\n" . self::heading( 2, 'Only' ) . self::heading( 4, 'Deep' ) );
		$this->assertStringNotContainsString( '<nav', $html );
	}

	/**
	 * (4) Headings in theme patterns and synced patterns count and get their ids.
	 */
	public function test_patterns_and_synced_patterns_count(): void {
		register_block_pattern(
			'leitvia-test/toc',
			array(
				'title'   => 'TOC test',
				'content' => self::heading( 2, 'From pattern' ),
			)
		);
		$synced = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => self::heading( 3, 'From synced' ),
			)
		);
		$html   = $this->render_post( "<!-- wp:leitvia/table-of-contents /-->\n<!-- wp:pattern {\"slug\":\"leitvia-test/toc\"} /-->\n<!-- wp:block {\"ref\":{$synced}} /-->" );
		$this->assertStringContainsString( '<a href="#from-pattern">From pattern</a><ol><li><a href="#from-synced">From synced</a>', $html );
		$this->assertStringContainsString( '<h2 id="from-pattern" class="wp-block-heading">', $html );
		$this->assertStringContainsString( '<h3 id="from-synced" class="wp-block-heading">', $html );
	}

	/**
	 * (5) Heading text and ids are escaped; markup in a heading becomes plain text.
	 */
	public function test_output_is_escaped(): void {
		$html = $this->render_post( "<!-- wp:leitvia/table-of-contents /-->\n" . self::heading( 2, 'A &lt;b&gt; &amp; <em>c</em>' ) . self::heading( 2, 'Quote', 'x&quot;onmouseover=&quot;y' ) );
		$this->assertStringContainsString( '>A &lt;b&gt; &amp; c</a>', $html );
		$this->assertStringNotContainsString( '"onmouseover', $html );
	}
}
