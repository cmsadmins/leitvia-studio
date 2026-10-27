<?php
/**
 * DTCG import, export and token sources.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Tokens\Dtcg;
use LeitviaStudio\Tokens\Token_Sets;

/**
 * Round trips of documents and CSS variables, rejected imports, permissions and the download.
 *
 * @group multisite
 */
class Test_Dtcg_Io extends WP_UnitTestCase {

	/**
	 * Leitvia theme, administrator, empty user global styles.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		// The test case unregisters all meta keys after each test.
		Token_Sets::register();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
		self::reset_styles();
	}

	/**
	 * Drops cached theme.json data.
	 */
	public function tear_down(): void {
		remove_all_actions( 'leitvia_studio_init', 99 );
		leitvia_studio()->reset_for_tests();
		\LeitviaStudio\Plugin::forget_contract();
		WP_Theme_JSON_Resolver::clean_cached_data();
		parent::tear_down();
	}

	/**
	 * Import then export gives the same document; foreign metadata and empty objects survive.
	 */
	public function test_document_round_trip(): void {
		$json     = self::fixture();
		$response = self::import( 'Foreign', $json );
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$id = $response->get_data()['id'];
		$this->assertSame( 'private', get_post_status( $id ) );

		// The stored document is the original, 1.0 and {} included.
		$this->assertSame( self::canonical( $json ), self::canonical( get_post_meta( $id, Token_Sets::META, true ) ) );

		// The REST answer only loses the fraction of whole floats (wp_json_encode() in the REST server).
		$response = rest_do_request( new WP_REST_Request( 'GET', "/leitvia-studio/v1/token-sets/$id/export" ) );
		$this->assertSame( 200, $response->get_status() );
		$exported = (string) wp_json_encode( $response->get_data() );
		$this->assertSame( self::canonical( $json, true ), self::canonical( $exported, true ) );
		$this->assertStringContainsString( '"flags":{},"modes":[],"deep":{"a":{"b":{}}},"indexed":{"0":"first"}', $exported );
		$this->assertStringContainsString( '"$deprecated":"Use the focus role instead."', $exported );

		// Only the contract version is written.
		$doc = json_decode( $json );
		$doc->{'$extensions'}->{'com.leitvia'}->contractVersion = '0.9.0';
		$id = self::import( 'Old', (string) wp_json_encode( $doc ) )->get_data()['id'];
		$doc->{'$extensions'}->{'com.leitvia'}->contractVersion = leitvia_studio_contract()->version();
		$this->assertSame( self::canonical( (string) wp_json_encode( $doc ) ), self::canonical( get_post_meta( $id, Token_Sets::META, true ) ) );

		// Titles keep backslashes, are capped and never empty.
		$id = self::import( 'A\\B', $json )->get_data()['id'];
		$this->assertSame( 'A\\B', get_post( $id )->post_title );
		$id = self::import( str_repeat( 'x', 300 ), $json )->get_data()['id'];
		$this->assertSame( 200, strlen( get_post( $id )->post_title ) );
		$id = self::import( '', $json )->get_data()['id'];
		$this->assertSame( 'Imported token set', get_post( $id )->post_title );
	}

	/**
	 * Theme and every style variation: a set from the source gives the CSS variables of the variation chosen in the
	 * Site Editor.
	 */
	public function test_css_variables_round_trip(): void {
		$sources = rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/token-sources' ) )->get_data();
		$sources = array_column( $sources, null, 'name' );
		$this->assertSame( 'leitvia-studio', $sources['leitvia/active-theme']['owner'] );
		$this->assertCount( 1, $sources['leitvia/active-theme']['documents'] );

		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/leitvia/variations' );
		$styles  = rest_do_request( $request )->get_data();
		$this->assertCount( 10, $styles );
		$this->assertCount( 10, $sources['leitvia/style-variations']['documents'] );

		$base  = null;
		$cases = array( array( 'leitvia/active-theme', 0, null ) );
		foreach ( $styles as $index => $variation ) {
			$cases[] = array( 'leitvia/style-variations', $index, $variation );
		}
		foreach ( $cases as list( $source, $index, $variation ) ) {
			if ( $variation ) {
				$this->assertSame( $variation['title'], $sources[ $source ]['documents'][ $index ]['title'] );
				$this->assertSame( 200, self::save_styles( $variation )->get_status() );
			}
			$expected = self::preset_variables();
			$this->assertNotEmpty( preg_grep( '/^--wp--preset--color--accent:/', $expected ) );
			// Every variation changes the variables of the theme, so the comparison below is not trivial.
			$base ??= $expected;
			if ( $variation ) {
				$this->assertNotSame( $base, $expected, "$source $index" );
			}
			self::reset_styles();

			$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/token-sets' );
			$request->set_body_params(
				array(
					'source' => $source,
					'index'  => $index,
				)
			);
			$response = rest_do_request( $request );
			$this->assertSame( 201, $response->get_status(), "$source $index: " . wp_json_encode( $response->get_data() ) );
			$id       = $response->get_data()['id'];
			$response = rest_do_request( new WP_REST_Request( 'POST', "/leitvia-studio/v1/token-sets/$id/activate" ) );
			$this->assertSame( 200, $response->get_status(), "$source $index" );

			$this->assertSame( $expected, self::preset_variables(), "$source $index" );
			self::reset_styles();
		}

		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/token-sets' );
		$request->set_body_params(
			array(
				'source' => 'leitvia/style-variations',
				'index'  => 99,
			)
		);
		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}

	/**
	 * Invalid imports are rejected with a concrete reason and store nothing.
	 */
	public function test_invalid_imports(): void {
		$count = (int) wp_count_posts( Token_Sets::POST_TYPE )->private;

		// The failing color sits behind an alias.
		$doc                              = json_decode( self::fixture(), true );
		$doc['base']['green-700']         = array(
			'$value' => array(
				'colorSpace' => 'srgb',
				'components' => array( 0.6, 0.72, 0.68 ),
				'hex'        => '#9ab8ae',
			),
		);
		$doc['color']['accent']['$value'] = '{base.green-700}';
		$response                         = self::import( 'Contrast', (string) wp_json_encode( $doc ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'contrast', $response->get_data()['data']['findings'][0]['code'] );

		$doc                 = json_decode( self::fixture(), true );
		$doc['$description'] = str_repeat( 'a', 512 * 1024 );
		$this->assertSame( 413, self::import( 'Large', (string) wp_json_encode( $doc ) )->get_status() );

		// Under the limit as sent, over it when stored pretty printed: 413, never an empty set.
		$compact = strlen( self::padded( 0 ) );
		$this->assertSame( 413, self::import( 'Pretty', self::padded( Dtcg::MAX_BYTES - $compact - 1 ) )->get_status() );

		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/token-sets/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'title'    => 'Text',
					'document' => 'not json',
				)
			)
		);
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
		$this->assertSame( 400, self::import( 'Deep', str_repeat( '{"a":', 40 ) . '1' . str_repeat( '}', 40 ) )->get_status() );

		$doc = json_decode( self::fixture(), true );
		unset( $doc['color']['focus'] );
		$response = self::import( 'Missing', (string) wp_json_encode( $doc ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'leitvia_studio_dtcg_missing_role', $response->get_data()['data']['findings'][0]['code'] );

		$doc = json_decode( self::fixture(), true );
		$doc['spacing']['m']['$extensions']['com.leitvia']['css'] = 'url(https://example.com/x)';
		$this->assertSame( 422, self::import( 'Css', (string) wp_json_encode( $doc ) )->get_status() );

		$this->assertSame( $count, (int) wp_count_posts( Token_Sets::POST_TYPE )->private );

		// Exactly at the limit in the stored form.
		$stored   = strlen( Dtcg::sanitize_json( Dtcg::stamp( self::padded( 0 ), leitvia_studio_contract()->version() ) ) );
		$json     = self::padded( Dtcg::MAX_BYTES - $stored );
		$response = self::import( 'Limit', $json );
		$this->assertSame( 201, $response->get_status() );
		$meta = get_post_meta( $response->get_data()['id'], Token_Sets::META, true );
		$this->assertSame( Dtcg::MAX_BYTES, strlen( $meta ) );
		$this->assertSame( self::canonical( $json ), self::canonical( $meta ) );
	}

	/**
	 * Documents from a token source pass the same checks as imports.
	 */
	public function test_invalid_source_documents(): void {
		add_action(
			'leitvia_studio_init',
			static function ( $studio ): void {
				$studio->registry( 'token_sources' )->register(
					'leitvia/test-bad',
					array(
						'label'    => 'Bad',
						'callback' => static function (): array {
							$contrast                           = json_decode( self::fixture(), true );
							$contrast['color']['accent']['$value'] = array(
								'colorSpace' => 'srgb',
								'components' => array( 0.6, 0.72, 0.68 ),
								'hex'        => '#9ab8ae',
							);
							$css = json_decode( self::fixture(), true );
							$css['spacing']['m']['$extensions']['com.leitvia']['css'] = 'url(https://example.com/x)';
							$missing = json_decode( self::fixture(), true );
							unset( $missing['color']['focus'] );
							return array(
								array(
									'title'    => 'Contrast',
									'document' => $contrast,
								),
								array(
									'title'    => 'Css',
									'document' => $css,
								),
								array(
									'title'    => 'Missing',
									'document' => $missing,
								),
							);
						},
					),
					'leitvia-studio'
				);
			},
			99
		);
		leitvia_studio()->reset_for_tests();

		$count = (int) wp_count_posts( Token_Sets::POST_TYPE )->private;
		$codes = array();
		foreach ( array( 0, 1, 2 ) as $index ) {
			$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/token-sets' );
			$request->set_body_params(
				array(
					'source' => 'leitvia/test-bad',
					'index'  => $index,
				)
			);
			$response = rest_do_request( $request );
			$this->assertSame( 422, $response->get_status() );
			$codes[] = $response->get_data()['data']['findings'][0]['code'];
		}
		$this->assertSame( array( 'contrast', 'leitvia_studio_dtcg_invalid_value', 'leitvia_studio_dtcg_missing_role' ), $codes );
		$this->assertSame( $count, (int) wp_count_posts( Token_Sets::POST_TYPE )->private );
	}

	/**
	 * Editors may neither import, export nor read the sources.
	 */
	public function test_permissions(): void {
		$id = self::import( 'Foreign', self::fixture() )->get_data()['id'];
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, self::import( 'Foreign', self::fixture() )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', "/leitvia-studio/v1/token-sets/$id/export" ) )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/token-sources' ) )->get_status() );
		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/token-sets' );
		$request->set_body_params( array( 'source' => 'leitvia/active-theme' ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	/**
	 * With download=1 the export is a .tokens.json attachment, otherwise plain JSON for the UI.
	 */
	public function test_export_download(): void {
		$id = self::import( 'Foreign Tool', self::fixture() )->get_data()['id'];

		$response = rest_do_request( new WP_REST_Request( 'GET', "/leitvia-studio/v1/token-sets/$id/export" ) );
		$this->assertArrayNotHasKey( 'Content-Disposition', $response->get_headers() );

		$request = new WP_REST_Request( 'GET', "/leitvia-studio/v1/token-sets/$id/export" );
		$request->set_param( 'download', '1' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'attachment; filename="foreign-tool.tokens.json"', $response->get_headers()['Content-Disposition'] );
		$this->assertSame( 'application/design-tokens+json', $response->get_headers()['Content-Type'] );
		$this->assertSame( self::canonical( self::fixture(), true ), self::canonical( (string) wp_json_encode( $response->get_data() ), true ) );

		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/token-sets/999999/export' ) )->get_status() );
	}

	/**
	 * Another sync target exports the file in its own form, named after it; an unknown target is refused.
	 */
	public function test_export_through_a_sync_target(): void {
		add_action(
			'leitvia_studio_init',
			static function ( $studio ): void {
				$studio->registry( 'sync_targets' )->register(
					'leitvia/test-flat',
					array(
						'label'  => 'Flat file',
						'export' => static fn( array $package ): array => array( 'title' => $package['title'] ),
					),
					'leitvia-studio'
				);
			},
			99
		);
		leitvia_studio()->reset_for_tests();
		$id      = self::import( 'Foreign Tool', self::fixture() )->get_data()['id'];
		$request = new WP_REST_Request( 'GET', "/leitvia-studio/v1/token-sets/$id/export" );
		$request->set_param( 'target', 'leitvia/test-flat' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'attachment; filename="foreign-tool.test-flat.tokens.json"', $response->get_headers()['Content-Disposition'] );
		$this->assertSame( array( 'title' => 'Foreign Tool' ), $response->get_data() );

		$request->set_param( 'target', 'leitvia/missing' );
		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}

	/**
	 * Raw fixture JSON.
	 */
	private static function fixture(): string {
		$raw = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/dtcg/foreign-tool.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture, raw text keeps {}.
		// An import always stores the contract version of the active theme; the fixture follows it, so the round trips
		// compare the rest of the document and survive the next contract release.
		return (string) preg_replace( '/"contractVersion": "[^"]*"/', '"contractVersion": "' . leitvia_studio_contract()->version() . '"', $raw, 1 );
	}

	/**
	 * Imports a raw document as the JSON body of the request.
	 *
	 * @param string $title Set title.
	 * @param string $json  Raw document.
	 */
	private static function import( string $title, string $json ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/leitvia-studio/v1/token-sets/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"title":' . wp_json_encode( $title ) . ',"document":' . $json . '}' );
		return rest_do_request( $request );
	}

	/**
	 * Fixture with a $description of the given length, as compact JSON.
	 *
	 * @param int $length Description length.
	 */
	private static function padded( int $length ): string {
		$doc                   = json_decode( self::fixture() );
		$doc->{'$description'} = str_repeat( 'a', $length );
		return (string) wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * Canonical form of a JSON text: object keys sorted recursively and marked, so {"0":1} differs from [1]; lists
	 * in order.
	 *
	 * @param string $json         JSON text.
	 * @param bool   $whole_floats Whether 1.0 counts as 1, for answers of the REST server, which drops the fraction.
	 * @return mixed
	 */
	private static function canonical( string $json, bool $whole_floats = false ) {
		$walk = static function ( $value ) use ( &$walk, $whole_floats ) {
			if ( $value instanceof stdClass ) {
				$value = (array) $value;
				ksort( $value, SORT_STRING );
				return array( '{}' => array_map( $walk, $value ) );
			}
			if ( is_array( $value ) ) {
				return array_map( $walk, $value );
			}
			return $whole_floats && is_float( $value ) && floor( $value ) === $value ? (int) $value : $value;
		};
		return $walk( json_decode( $json ) );
	}

	/**
	 * Sorted --wp--preset--* declarations of the global stylesheet.
	 *
	 * @return list<string>
	 */
	private static function preset_variables(): array {
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		preg_match_all( '/--wp--preset--[a-z0-9-]+:[^;}]+/', wp_get_global_stylesheet( array( 'variables' ) ), $matches );
		$vars = array_values( array_unique( $matches[0] ) );
		sort( $vars );
		return $vars;
	}

	/**
	 * Applies a style variation over REST, as the Site Editor does.
	 *
	 * @param array<string, mixed> $variation Variation from the variations route.
	 */
	private static function save_styles( array $variation ): WP_REST_Response {
		$id      = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$request = new WP_REST_Request( 'POST', "/wp/v2/global-styles/$id" );
		$request->set_body_params(
			array(
				'settings' => $variation['settings'] ?? array(),
				'styles'   => $variation['styles'] ?? array(),
			)
		);
		return rest_do_request( $request );
	}

	/**
	 * Empties the user global styles of the active theme.
	 */
	private static function reset_styles(): void {
		wp_update_post(
			wp_slash(
				array(
					'ID'           => WP_Theme_JSON_Resolver::get_user_global_styles_post_id(),
					'post_content' => wp_json_encode(
						array(
							'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
							'isGlobalStylesUserThemeJSON' => true,
						)
					),
				)
			)
		);
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
	}
}
