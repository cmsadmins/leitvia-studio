<?php
/**
 * Share: links, encoding, names, hidden buttons, fallback address.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Share;

/**
 * Renders the block inside the post it shares, as core/post-content gives it the postId context.
 */
class Test_Share extends WP_UnitTestCase {

	/**
	 * Renders a share block with the post as context.
	 *
	 * @param int                  $post_id    Post ID.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private static function render( int $post_id, array $attributes = array() ): string {
		$block = new WP_Block(
			array(
				'blockName' => Share::BLOCK,
				'attrs'     => $attributes,
			),
			array( 'postId' => $post_id )
		);
		return $block->render();
	}

	/**
	 * Hrefs of the links, decoded.
	 *
	 * @param string $html Block HTML.
	 * @return array<int, string>
	 */
	private static function hrefs( string $html ): array {
		preg_match_all( '#<a [^>]*href="([^"]+)"#', $html, $found );
		return array_map( 'html_entity_decode', $found[1] );
	}

	/**
	 * (1) Default services in order, a named nav, URL and title raw-URL-encoded, mailto without a new window.
	 */
	public function test_default_links_are_encoded(): void {
		$id   = self::factory()->post->create( array( 'post_title' => 'Tea & Toast: ü?' ) );
		$html = self::render( $id );
		$url  = rawurlencode( get_permalink( $id ) );
		$text = rawurlencode( 'Tea & Toast: ü? ' . get_permalink( $id ) );

		$this->assertMatchesRegularExpression( '#^<nav [^>]*aria-label="Share this post"#', $html );
		$this->assertStringContainsString( 'is-display-icon-text', $html );
		$this->assertSame(
			array(
				'https://www.linkedin.com/sharing/share-offsite/?url=' . $url,
				'https://bsky.app/intent/compose?text=' . $text,
				'https://wa.me/?text=' . $text,
				'mailto:?subject=' . rawurlencode( 'Tea & Toast: ü?' ) . '&body=' . $url,
			),
			self::hrefs( $html )
		);
		$this->assertStringNotContainsString( 'utm_', $html );
	}

	/**
	 * (2) Links that open a new window say so in their name and carry noopener noreferrer; email does not.
	 */
	public function test_names_and_new_window(): void {
		$html = self::render( self::factory()->post->create(), array( 'services' => array( 'linkedin', 'email' ) ) );
		$this->assertMatchesRegularExpression( '#aria-label="Share on LinkedIn \(opens in a new window\)" target="_blank" rel="noopener noreferrer">#', $html );
		$this->assertMatchesRegularExpression( '#aria-label="Share by email">#', $html );
		$this->assertSame( 1, substr_count( $html, 'target="_blank"' ) );
		$this->assertStringContainsString( '<span class="wp-block-leitvia-share__label">LinkedIn</span>', $html );
	}

	/**
	 * (3) Copy and the Web Share button render hidden; the live region and its messages are there.
	 */
	public function test_buttons_hidden_and_live_region(): void {
		$html = self::render( self::factory()->post->create(), array( 'services' => array( 'copy' ) ) );
		$this->assertMatchesRegularExpression( '#<li class="[^"]*__item--copy" hidden><button type="button" class="wp-block-leitvia-share__link" data-share="copy">#', $html );
		$this->assertMatchesRegularExpression( '#<li class="[^"]*__item--native" hidden><button [^>]*data-share="native">#', $html );
		$this->assertMatchesRegularExpression( '#<p class="wp-block-leitvia-share__status" role="status" data-copied="Link copied" data-failed="[^"]+"></p>#', $html );
	}

	/**
	 * (4) Order follows the attribute; unknown and repeated services drop out; none left renders nothing.
	 */
	public function test_order_and_unknown_services(): void {
		$this->assertSame( array( 'xing', 'facebook', 'threads' ), Share::services( array( 'xing', 'twitter', 'facebook', 'xing', 'threads' ) ) );
		$html  = self::render( self::factory()->post->create(), array( 'services' => array( 'threads', 'facebook' ) ) );
		$hrefs = self::hrefs( $html );
		$this->assertStringStartsWith( 'https://www.threads.com/intent/post?text=', $hrefs[0] );
		$this->assertStringStartsWith( 'https://www.facebook.com/sharer/sharer.php?u=', $hrefs[1] );
		// The block parser drops an attribute outside the enum, so the class is called directly.
		$this->assertSame( '', Share::render( array( 'services' => array( 'myspace' ) ) ) );
	}

	/**
	 * (5) Marks are inline, in currentColor and hidden from assistive technology; the display class follows the
	 * attribute, an unknown display falls back.
	 */
	public function test_icons_and_display(): void {
		$id   = self::factory()->post->create();
		$html = self::render( $id, array( 'display' => 'icon' ) );
		$this->assertMatchesRegularExpression( '#class="is-display-icon wp-block-leitvia-share"#', $html );
		$this->assertMatchesRegularExpression( '#<svg [^>]*aria-hidden="true" focusable="false" fill="currentColor">#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<(img|script|iframe)\b#', $html );
		$this->assertStringContainsString( 'is-display-icon-text', self::render( $id, array( 'display' => 'huge' ) ) );
	}

	/**
	 * (6) A draft is not shared by its address; the current page is, without the query string.
	 */
	public function test_unpublished_post_falls_back_to_current_page(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->go_to( home_url( '/?utm_source=x' ) );
		list( $url ) = Share::target( $draft );
		$this->assertSame( home_url( '/' ), $url );
		list( $url, $title ) = Share::target( self::factory()->post->create( array( 'post_title' => 'Open' ) ) );
		$this->assertStringContainsString( '?p=', $url );
		$this->assertSame( 'Open', $title );
	}
}
