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
	 * Post of the last render_post().
	 *
	 * @var int
	 */
	private int $post_id = 0;

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
		$this->post_id = self::factory()->post->create( array( 'post_content' => $content ) );
		return $this->render_part( 1 );
	}

	/**
	 * Renders a part of the post of the last render_post() through core/post-content, as the address of that part does.
	 *
	 * @param int $part Part, from 1.
	 */
	private function render_part( int $part ): string {
		$id = $this->post_id;
		$this->go_to( 1 === $part ? get_permalink( $id ) : add_query_arg( 'page', $part, get_permalink( $id ) ) );
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
	 * (6) Display companion adds a hidden chapter button with a progress bar and a modal dialog with the same list;
	 * inline, the default and an unknown value render the list alone.
	 */
	public function test_companion_adds_button_and_dialog(): void {
		$headings = self::heading( 2, 'Start' ) . self::heading( 2, 'Use' );
		$html     = $this->render_post( '<!-- wp:leitvia/table-of-contents {"display":"companion"} /-->' . "\n" . $headings );
		$list     = '<ol><li><a href="#start">Start</a></li><li><a href="#use">Use</a></li></ol>';
		$this->assertMatchesRegularExpression( '#<nav [^>]*class="[^"]*\bis-display-companion\b#', $html );
		$this->assertStringContainsString( '<div class="wp-block-leitvia-table-of-contents__companion" hidden data-count="Section %1$d of %2$d">', $html );
		$this->assertStringContainsString( '<button type="button" class="wp-block-leitvia-table-of-contents__open" aria-haspopup="dialog"><span class="wp-block-leitvia-table-of-contents__label">Chapters</span>', $html );
		$this->assertStringContainsString( 'role="progressbar" aria-label="Reading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"', $html );
		$this->assertMatchesRegularExpression( '#<dialog class="wp-block-leitvia-table-of-contents__dialog" aria-labelledby="(leitvia-toc-dialog-\d+)"><div class="wp-block-leitvia-table-of-contents__sheet"><h2 id="\1" class="wp-block-leitvia-table-of-contents__title">On this page</h2>#', $html );
		$this->assertStringContainsString( '</h2>' . $list . '<button type="button" class="wp-block-leitvia-table-of-contents__close">Close</button>', $html );
		$this->assertSame( 2, substr_count( $html, $list ), 'the list in the flow and in the dialog' );

		foreach ( array( '', ' {"display":"inline"}', ' {"display":"floating"}' ) as $attrs ) {
			$plain = $this->render_post( '<!-- wp:leitvia/table-of-contents' . $attrs . ' /-->' . "\n" . $headings );
			$this->assertStringContainsString( $list . '</nav>', $plain, $attrs );
			$this->assertStringNotContainsString( '<dialog', $plain, $attrs );
			$this->assertStringNotContainsString( 'is-display-companion', $plain, $attrs );
		}
	}

	/**
	 * (5) Heading text and ids are escaped; markup in a heading becomes plain text.
	 */
	public function test_output_is_escaped(): void {
		$html = $this->render_post( "<!-- wp:leitvia/table-of-contents /-->\n" . self::heading( 2, 'A &lt;b&gt; &amp; <em>c</em>' ) . self::heading( 2, 'Quote', 'x&quot;onmouseover=&quot;y' ) );
		$this->assertStringContainsString( '>A &lt;b&gt; &amp; c</a>', $html );
		$this->assertStringNotContainsString( '"onmouseover', $html );
	}

	/**
	 * Three parts: the table of contents in part 1, Arrival in parts 1 and 2, an own id in part 3 and an h4 with an
	 * own id there that the list leaves out.
	 *
	 * @param string $attrs Attributes of the table of contents, JSON with a leading space or empty.
	 */
	private static function three_parts( string $attrs = '' ): string {
		$next = "<!-- wp:nextpage -->\n<!--nextpage-->\n<!-- /wp:nextpage -->\n";
		return '<!-- wp:leitvia/table-of-contents' . $attrs . " /-->\n"
			. self::heading( 2, 'Arrival' ) . self::heading( 3, 'Ferry' ) . $next
			. self::heading( 2, 'Highlands' ) . self::heading( 2, 'Arrival' ) . $next
			. self::heading( 2, 'Islands', 'isles' ) . self::heading( 4, 'Deep', 'deep' ) . self::heading( 2, 'Departure' );
	}

	/**
	 * (7) In part 2 of three, scope all groups the entries by part: the part links lead to their parts, the current
	 * one with aria-current page; entries of parts 1 and 3 link to the address of their part plus the anchor, those of
	 * part 2 to the anchor alone. The list stands at the top of part 2, which has none of its own, and the second
	 * Arrival gets its id, not the one of part 1.
	 */
	public function test_parts_link_to_their_part(): void {
		$this->render_post( self::three_parts() );
		$html = $this->render_part( 2 );
		$one  = home_url( '/?p=' ) . $this->post_id;
		$this->assertStringContainsString(
			'<ol><li><a href="' . $one . '">Part 1</a><ol><li><a href="' . $one . '#arrival">Arrival</a><ol><li><a href="' . $one . '#ferry">Ferry</a></li></ol></li></ol></li>'
			. '<li><a href="' . $one . '&#038;page=2" aria-current="page">Part 2</a><ol><li><a href="#highlands">Highlands</a></li><li><a href="#arrival-2">Arrival</a></li></ol></li>'
			. '<li><a href="' . $one . '&#038;page=3">Part 3</a><ol><li><a href="' . $one . '&#038;page=3#isles">Islands</a></li><li><a href="' . $one . '&#038;page=3#departure">Departure</a></li></ol></li></ol></nav>',
			$html
		);
		$this->assertMatchesRegularExpression( '#^<div [^>]*class="[^"]*\bwp-block-post-content\b[^"]*"[^>]*><nav #', $html, 'the list at the top of the part' );
		$this->assertSame( 1, substr_count( $html, '<nav ' ) );
		$this->assertStringContainsString( '<h2 id="highlands" class="wp-block-heading">Highlands</h2>', $html );
		$this->assertStringContainsString( '<h2 id="arrival-2" class="wp-block-heading">Arrival</h2>', $html );
		$this->assertStringNotContainsString( 'Ferry</h3>', $html, 'part 1 does not render in part 2' );
	}

	/**
	 * (7) With pretty permalinks the parts are /<slug>/<n>/; in part 1 its own entries link to the anchor alone and
	 * the list in the flow is not repeated.
	 */
	public function test_parts_with_pretty_permalinks(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$html = $this->render_post( self::three_parts() );
		$url  = get_permalink( $this->post_id );
		$this->assertStringContainsString( '<a href="' . $url . '" aria-current="page">Part 1</a><ol><li><a href="#arrival">Arrival</a>', $html );
		$this->assertStringContainsString( '<li><a href="' . $url . '2/#arrival-2">Arrival</a></li>', $html );
		$this->assertStringContainsString( '<li><a href="' . $url . '3/#departure">Departure</a></li>', $html );
		$this->assertStringContainsString( '<h2 id="arrival" class="wp-block-heading">Arrival</h2>', $html );
		$this->assertSame( 1, substr_count( $html, '<nav ' ) );
	}

	/**
	 * (7) Scope part lists the current part alone, without part links; a post without parts ignores the scope.
	 */
	public function test_scope_part_lists_the_current_part(): void {
		$this->render_post( self::three_parts( ' {"scope":"part"}' ) );
		$html = $this->render_part( 3 );
		$this->assertStringContainsString( '<ol><li><a href="#isles">Islands</a></li><li><a href="#departure">Departure</a></li></ol></nav>', $html );
		$this->assertStringNotContainsString( 'Part 1', $html );
		$this->assertStringNotContainsString( 'arrival', $html );

		$plain = $this->render_post( '<!-- wp:leitvia/table-of-contents {"scope":"part"} /-->' . "\n" . self::heading( 2, 'Start' ) . self::heading( 2, 'Use' ) );
		$this->assertStringContainsString( '<ol><li><a href="#start">Start</a></li><li><a href="#use">Use</a></li></ol></nav>', $plain );
	}

	/**
	 * (8) anchor_url() for add-ons: in part 2, an anchor of part 1 or 3 gets the address of its part, generated ids,
	 * own ids and own ids of headings outside the list alike; an anchor of part 2 and an unknown one stay #anchor, so
	 * does every anchor of a post without parts. A leading # is allowed.
	 */
	public function test_anchor_url_for_add_ons(): void {
		$this->render_post( self::three_parts() );
		$id  = $this->post_id;
		$one = home_url( '/?p=' ) . $id;
		$this->render_part( 2 );
		$this->assertSame( $one . '#ferry', Table_Of_Contents::anchor_url( $id, 'ferry' ) );
		$this->assertSame( $one . '&page=3#isles', Table_Of_Contents::anchor_url( get_post( $id ), '#isles' ) );
		$this->assertSame( $one . '&page=3#deep', Table_Of_Contents::anchor_url( $id, 'deep' ) );
		$this->assertSame( '#arrival-2', Table_Of_Contents::anchor_url( $id, 'arrival-2' ) );
		$this->assertSame( '#nowhere', Table_Of_Contents::anchor_url( $id, 'nowhere' ) );

		$this->render_post( "<!-- wp:leitvia/table-of-contents /-->\n" . self::heading( 2, 'Start' ) );
		$this->assertSame( '#start', Table_Of_Contents::anchor_url( $this->post_id, 'start' ) );
		$this->assertSame( '#x', Table_Of_Contents::anchor_url( 0, 'x' ) );
	}
}
