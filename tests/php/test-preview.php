<?php
/**
 * Front-end preview of the Lab.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Lab\Preview;
use LeitviaStudio\Tokens\Token_Sets;

/**
 * Access, rendering, style variations and token sets of Preview::prepare() and the template canvas.
 *
 * @group multisite
 */
class Test_Preview extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private static int $editor;

	/**
	 * Author user ID.
	 *
	 * @var int
	 */
	private static int $author;

	/**
	 * Creates the users.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WordPress test API.
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
		self::$author = $factory->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Activates the Leitvia theme so its patterns and variations exist.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		_register_theme_block_patterns();
	}

	/**
	 * Preview request as the current user, with a valid nonce unless one is given.
	 *
	 * @param array<string, string> $query Query parameters.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private function preview( array $query ): array {
		return leitvia_studio_tests_preview( $query + array( '_wpnonce' => wp_create_nonce( 'leitvia_studio_preview' ) ) );
	}

	/**
	 * Without login or without nonce there is no preview.
	 */
	public function test_requires_login_and_nonce(): void {
		$ref = array( 'ref' => 'pattern:leitvia/hero-split-image' );

		wp_set_current_user( 0 );
		$response = $this->preview( $ref );
		$this->assertSame( 403, $response['status'] );
		$this->assertStringNotContainsString( '<main', $response['body'] );

		wp_set_current_user( self::$editor );
		foreach ( array( '', 'invalid' ) as $nonce ) {
			$response = leitvia_studio_tests_preview( $ref + array( '_wpnonce' => $nonce ) );
			$this->assertSame( 403, $response['status'] );
			$this->assertStringNotContainsString( '<main', $response['body'] );
		}
	}

	/**
	 * An editor gets the rendered theme pattern in a full page that is neither indexed nor cached.
	 */
	public function test_renders_theme_pattern(): void {
		wp_set_current_user( self::$editor );
		$response = $this->preview( array( 'ref' => 'pattern:leitvia/hero-split-image' ) );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'noindex', $response['headers']['X-Robots-Tag'] );
		$this->assertStringContainsString( 'no-store', $response['headers']['Cache-Control'] );
		$this->assertSame( 'SAMEORIGIN', $response['headers']['X-Frame-Options'] );
		$this->assertSame( "frame-ancestors 'self'", $response['headers']['Content-Security-Policy'] );
		// Blocks render before wp_head(), so the styles they enqueue are printed in head.
		$head = strstr( $response['body'], '</head>', true );
		$this->assertMatchesRegularExpression( '/id="core-block-supports-inline-css">[^<]*.wp-container-core-columns-/', (string) $head );
		$this->assertStringStartsWith( '<!DOCTYPE html>', ltrim( $response['body'] ) );
		$this->assertMatchesRegularExpression( '/<main id="main" class="wp-block-group[^"]*">/', $response['body'] );
		$this->assertSame( 1, substr_count( $response['body'], '<title' ) );
		$this->assertStringContainsString( '<title>Pattern preview</title>', $response['body'] );
		$this->assertStringContainsString( 'name="viewport"', $response['body'] );
		$this->assertMatchesRegularExpression( '/<body class="[^"]*leitvia-studio-preview[ "]/', $response['body'] );
		$this->assertStringContainsString( 'Websites built to stay useful for years.', $response['body'] );
		$this->assertStringContainsString( 'leitvia-studio:height', $response['body'] );

		$this->assertSame( 404, $this->preview( array( 'ref' => 'pattern:leitvia/missing' ) )['status'] );
		$this->assertSame( 404, $this->preview( array( 'ref' => 'something' ) )['status'] );
	}

	/**
	 * A style variation changes the rendered CSS for this request only.
	 */
	public function test_style_variation_applies_to_the_request_only(): void {
		wp_set_current_user( self::$editor );
		$saved = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );

		$response = $this->preview(
			array(
				'ref'   => 'pattern:leitvia/hero-split-image',
				'style' => 'warm-editorial-dark',
			)
		);
		$this->assertSame( 200, $response['status'] );
		$this->assertMatchesRegularExpression( '/--wp--preset--color--surface:\s*#171512/', $response['body'] );

		$this->assertSame( $saved, WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() ) );
		$this->assertMatchesRegularExpression( '/--wp--preset--color--surface:\s*#f7f4ee/', wp_get_global_stylesheet() );

		// Partial variations live one folder below styles/.
		$night = $this->preview(
			array(
				'ref'   => 'pattern:leitvia/hero-split-image',
				'style' => 'night',
			)
		);
		$this->assertSame( 200, $night['status'] );
		$this->assertMatchesRegularExpression( '/--wp--preset--color--surface:\s*#(?!f7f4ee)/', $night['body'] );

		foreach ( array( 'missing', '../theme' ) as $style ) {
			$response = $this->preview(
				array(
					'ref'   => 'pattern:leitvia/hero-split-image',
					'style' => $style,
				)
			);
			$this->assertSame( 422, $response['status'] );
		}
	}

	/**
	 * Only valid token sets are applied.
	 */
	public function test_invalid_token_set_is_rejected(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		$post = self::factory()->post->create();
		foreach ( array( '999999', (string) $post ) as $set ) {
			$response = $this->preview(
				array(
					'ref' => 'pattern:leitvia/hero-split-image',
					'set' => $set,
				)
			);
			$this->assertSame( 422, $response['status'] );
			$this->assertStringNotContainsString( '<main', $response['body'] );
		}
	}

	/**
	 * A valid, private token set is applied for this request only, and only for users who may manage token sets.
	 */
	public function test_valid_token_set_applies_to_the_request_only(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		$id                               = Token_Sets::create_from_active_theme( 'Preview test' );
		$doc                              = json_decode( get_post_meta( $id, Token_Sets::META, true ), true );
		$doc['color']['accent']['$value'] = array(
			'colorSpace' => 'srgb',
			'components' => array( 0.07, 0.2, 0.34 ),
			'hex'        => '#123456',
		);
		update_post_meta( $id, Token_Sets::META, wp_slash( wp_json_encode( $doc ) ) );
		$this->assertSame( 'private', get_post_status( $id ) );
		$query = array(
			'ref' => 'pattern:leitvia/hero-split-image',
			'set' => (string) $id,
		);

		$response = $this->preview( $query );
		$this->assertSame( 200, $response['status'] );
		$this->assertMatchesRegularExpression( '/--wp--preset--color--accent:\s*#123456/', $response['body'] );
		$this->assertDoesNotMatchRegularExpression( '/--wp--preset--color--accent:\s*#123456/', wp_get_global_stylesheet() );

		wp_set_current_user( self::$editor );
		$this->assertSame( 403, $this->preview( $query )['status'] );
	}

	/**
	 * A private wp_block of another author needs read_post.
	 */
	public function test_private_block_needs_read_post(): void {
		$block = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'private',
				'post_author'  => self::$editor,
				'post_title'   => 'Private preview pattern',
				'post_content' => '<!-- wp:paragraph --><p>Private preview text</p><!-- /wp:paragraph -->',
			)
		);
		$ref   = array( 'ref' => 'block:' . $block );

		wp_set_current_user( self::$author );
		$response = $this->preview( $ref );
		$this->assertSame( 403, $response['status'] );
		$this->assertStringNotContainsString( 'Private preview text', $response['body'] );

		wp_set_current_user( self::$editor );
		$response = $this->preview( $ref );
		$this->assertSame( 200, $response['status'] );
		$this->assertStringContainsString( 'Private preview text', $response['body'] );
	}
}
