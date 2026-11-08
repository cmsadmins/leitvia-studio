<?php
/**
 * DTCG mapping tests.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Tokens\Dtcg;

/**
 * Round trip theme.json to DTCG and back, aliases, validation.
 */
class Test_Dtcg extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
	}

	/**
	 * Theme presets survive DTCG export and import unchanged.
	 */
	public function test_theme_round_trip_keeps_every_preset(): void {
		$settings = wp_get_global_settings();
		$doc      = Dtcg::from_settings( $settings, leitvia_studio_contract() );
		$back     = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		$this->assertNotWPError( $back );
		$this->assertSame( $settings['color']['palette']['theme'], $back['color']['palette']['theme'] );
		$this->assertSame( $settings['typography']['fontSizes']['theme'], $back['typography']['fontSizes']['theme'] );
		$this->assertSame( $settings['spacing']['spacingSizes']['theme'], $back['spacing']['spacingSizes']['theme'] );
		$this->assertSame( $settings['border']['radiusSizes']['theme'], $back['border']['radiusSizes']['theme'] );
		$this->assertSame( $settings['shadow']['presets']['theme'], $back['shadow']['presets']['theme'] );
		$this->assertSame(
			wp_list_pluck( $settings['typography']['fontFamilies']['theme'], 'fontFamily', 'slug' ),
			wp_list_pluck( $back['typography']['fontFamilies']['theme'], 'fontFamily', 'slug' )
		);
		$this->assertSame(
			wp_list_pluck( $settings['typography']['fontFamilies']['theme'], 'fontFace', 'slug' ),
			wp_list_pluck( $back['typography']['fontFamilies']['theme'], 'fontFace', 'slug' )
		);
	}

	/**
	 * The export follows the Leitvia profile: sRGB colors with hex, css extension for fluid values.
	 */
	public function test_export_format(): void {
		$doc = self::minimal();
		$this->assertSame( 'srgb', $doc['color']['surface']['$value']['colorSpace'] );
		$this->assertSame( '#f7f4ee', $doc['color']['surface']['$value']['hex'] );
		$this->assertSame( array( 0.969, 0.957, 0.933 ), $doc['color']['surface']['$value']['components'] );
		$this->assertSame(
			array(
				'value' => 0.75,
				'unit'  => 'rem',
			),
			$doc['spacing']['s']['$value']
		);
		$this->assertSame( 'clamp(0.75rem, 0.6rem + 0.5vw, 1rem)', $doc['spacing']['s']['$extensions']['com.leitvia']['css'] );
		$this->assertArrayNotHasKey( 'css', $doc['radius']['m']['$extensions']['com.leitvia'] );
		$this->assertArrayNotHasKey( 'css', $doc['color']['surface']['$extensions']['com.leitvia'] );
		$this->assertSame( array( 'Newsreader', 'Georgia', 'serif' ), $doc['fontFamily']['body']['$value'] );
		$this->assertCount( 2, $doc['shadow']['subtle']['$value'] );
		$this->assertSame( 0.06, $doc['shadow']['subtle']['$value'][0]['color']['alpha'] );
		$this->assertSame( 1, $doc['$extensions']['com.leitvia']['format'] );
	}

	/**
	 * Aliases resolve recursively; a cycle is an error.
	 */
	public function test_alias_resolution_and_cycle(): void {
		$doc                            = self::minimal();
		$doc['primitive']['color']['a'] = array(
			'$type'  => 'color',
			'$value' => '{primitive.color.b}',
		);
		$doc['primitive']['color']['b'] = array(
			'$type'  => 'color',
			'$value' => array(
				'colorSpace' => 'srgb',
				'components' => array( 1, 0, 0 ),
			),
		);
		$doc['color']['ink']['$value']  = '{primitive.color.a}';
		$settings                       = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		$this->assertNotWPError( $settings );
		$ink = wp_list_filter( $settings['color']['palette']['theme'], array( 'slug' => 'ink' ) );
		$this->assertSame( '#ff0000', array_values( $ink )[0]['color'] );

		$doc['primitive']['color']['b']['$value'] = '{primitive.color.a}';
		$this->assertSame( 'leitvia_studio_dtcg_alias_cycle', Dtcg::to_settings( $doc, leitvia_studio_contract() )->get_error_code() );

		$doc['color']['ink']['$value'] = '{primitive.color.missing}';
		$this->assertSame( 'leitvia_studio_dtcg_alias_missing', Dtcg::to_settings( $doc, leitvia_studio_contract() )->get_error_code() );
	}

	/**
	 * Missing roles, extra tokens in role groups and other color spaces are rejected.
	 */
	public function test_rejects_missing_role_extra_token_and_non_srgb(): void {
		$c   = leitvia_studio_contract();
		$doc = self::minimal();
		unset( $doc['color']['focus'] );
		$this->assertSame( 'leitvia_studio_dtcg_missing_role', Dtcg::to_settings( $doc, $c )->get_error_code() );
		$doc                   = self::minimal();
		$doc['color']['brand'] = $doc['color']['ink'];
		$this->assertSame( 'leitvia_studio_dtcg_unknown_role', Dtcg::to_settings( $doc, $c )->get_error_code() );
		$doc                           = self::minimal();
		$doc['color']['ink']['$value'] = array(
			'colorSpace' => 'oklch',
			'components' => array( 0.2, 0.01, 90 ),
		);
		$this->assertSame( 'leitvia_studio_dtcg_color_space', Dtcg::to_settings( $doc, $c )->get_error_code() );
		$doc = self::minimal();
		$doc['spacing']['s']['$extensions']['com.leitvia']['css'] = '1rem; background: red';
		$this->assertSame( 'leitvia_studio_dtcg_invalid_value', Dtcg::to_settings( $doc, $c )->get_error_code() );
	}

	/**
	 * Up to eight extra surfaces become palette presets surface-extra-<slug> after the roles and survive the export;
	 * a surface ink does not reach 4.5:1 on, a ninth surface and a slug with digits or capitals are rejected.
	 */
	public function test_extra_surfaces(): void {
		$c     = leitvia_studio_contract();
		$color = static fn( string $hex ): array => array(
			'$type'  => 'color',
			'$value' => array(
				'colorSpace' => 'srgb',
				'components' => array( 0, 0, 0 ),
				'hex'        => $hex,
			),
		);

		$doc                                       = self::minimal();
		$doc['color']['surface-extra']['mint']     = $color( '#dff5ea' ) + array( '$extensions' => array( 'com.leitvia' => array( 'name' => 'Mint' ) ) );
		$doc['color']['surface-extra']['sky-blue'] = $color( '#e3eefc' );
		$palette                                   = Dtcg::to_settings( $doc, $c )['color']['palette']['theme'];
		$this->assertSame(
			array(
				array(
					'slug'  => 'surface-extra-mint',
					'name'  => 'Mint',
					'color' => '#dff5ea',
				),
				array(
					'slug'  => 'surface-extra-sky-blue',
					'name'  => 'sky-blue',
					'color' => '#e3eefc',
				),
			),
			array_slice( $palette, -2 )
		);
		$this->assertSame( array_column( $c->data()['roles']['color'], 'slug' ), array_slice( array_column( $palette, 'slug' ), 0, -2 ) );
		$export = Dtcg::from_settings( array( 'color' => array( 'palette' => array( 'theme' => $palette ) ) ) + wp_get_global_settings(), $c );
		$this->assertSame( array( 'mint', 'sky-blue' ), array_keys( $export['color']['surface-extra'] ) );
		$this->assertSame( '#dff5ea', $export['color']['surface-extra']['mint']['$value']['hex'] );

		$dark                                  = $doc;
		$dark['color']['surface-extra']['ink'] = $color( '#333333' );
		$error                                 = Dtcg::to_settings( $dark, $c );
		$this->assertSame( 'leitvia_studio_dtcg_extra_contrast', $error->get_error_code() );
		$this->assertStringContainsString( 'color.surface-extra.ink', $error->get_error_message() );

		$many = self::minimal();
		foreach ( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h' ) as $slug ) {
			$many['color']['surface-extra'][ $slug ] = $color( '#ffffff' );
		}
		$this->assertNotWPError( Dtcg::to_settings( $many, $c ) );
		$many['color']['surface-extra']['i'] = $color( '#ffffff' );
		$this->assertSame( 'leitvia_studio_dtcg_extra_limit', Dtcg::to_settings( $many, $c )->get_error_code() );

		foreach ( array( 'mint2', 'Mint', 'mint_green', '-mint', '', 'a--b' ) as $slug ) {
			$bad                                    = self::minimal();
			$bad['color']['surface-extra'][ $slug ] = $color( '#ffffff' );
			$this->assertSame( 'leitvia_studio_dtcg_extra_slug', Dtcg::to_settings( $bad, $c )->get_error_code(), $slug );
		}
		// The limit is 4.5:1 unrounded: #8e7f75 reaches 4.500001 on the ink of the theme, #d26027 only 4.499999.
		$edge                                   = self::minimal();
		$edge['color']['surface-extra']['sand'] = $color( '#8e7f75' );
		$this->assertNotWPError( Dtcg::to_settings( $edge, $c ) );
		$edge['color']['surface-extra']['sand'] = $color( '#d26027' );
		$this->assertSame( 'leitvia_studio_dtcg_extra_contrast', Dtcg::to_settings( $edge, $c )->get_error_code() );

		// A single token or a scalar in place of the group is refused, not read as an empty group.
		foreach ( array( $color( '#ffffff' ), '#ffffff', null ) as $node ) {
			$single                           = self::minimal();
			$single['color']['surface-extra'] = $node;
			$this->assertSame( 'leitvia_studio_dtcg_extra_group', Dtcg::to_settings( $single, $c )->get_error_code() );
		}

		// Only color has the group; elsewhere it stays an unknown role.
		$other                                   = self::minimal();
		$other['spacing']['surface-extra']['xs'] = $other['spacing']['s'];
		$this->assertSame( 'leitvia_studio_dtcg_unknown_role', Dtcg::to_settings( $other, $c )->get_error_code() );
	}

	/**
	 * The export keeps only extra surfaces the import accepts, so its document compiles again with the same palette:
	 * names of lowercase letters and hyphens, the first eight.
	 */
	public function test_extra_surfaces_round_trip(): void {
		$c        = leitvia_studio_contract();
		$settings = wp_get_global_settings();
		$palette  = $settings['color']['palette']['theme'];
		$extra    = array();
		foreach ( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i' ) as $slug ) {
			$extra[] = array(
				'slug'  => 'surface-extra-' . $slug,
				'name'  => strtoupper( $slug ),
				'color' => '#ffffff',
			);
		}
		$bad                                   = array(
			'slug'  => 'surface-extra-tile2',
			'name'  => 'Tile 2',
			'color' => '#ffffff',
		);
		$settings['color']['palette']['theme'] = array_merge( $palette, array( $bad ), $extra );

		$doc  = Dtcg::from_settings( $settings, $c );
		$back = Dtcg::to_settings( $doc, $c );
		$this->assertNotWPError( $back );
		$this->assertSame( array_merge( $palette, array_slice( $extra, 0, 8 ) ), $back['color']['palette']['theme'] );
		$this->assertSame( $doc['color'], Dtcg::from_settings( $back + $settings, $c )['color'] );
	}

	/**
	 * Without a role ink in the contract the surfaces cannot be checked, so they are refused instead of passing.
	 */
	public function test_extra_surfaces_without_ink(): void {
		$data                   = json_decode( (string) file_get_contents( get_template_directory() . '/leitvia-contract.json' ), true );
		$data['roles']['color'] = array_values( array_filter( $data['roles']['color'], static fn( array $r ): bool => 'ink' !== $r['slug'] ) );
		$file                   = get_temp_dir() . 'leitvia-contract-no-ink.json';
		file_put_contents( $file, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$c = \LeitviaStudio\Contract\Contract::from_file( $file );
		$this->assertTrue( $c->is_valid(), $c->error() );

		$doc = self::minimal();
		unset( $doc['color']['ink'] );
		$this->assertNotWPError( Dtcg::to_settings( $doc, $c ) );
		$doc['color']['surface-extra']['mint']['$value'] = array(
			'colorSpace' => 'srgb',
			'components' => array( 1, 1, 1 ),
		);
		$this->assertSame( 'leitvia_studio_dtcg_extra_contrast', Dtcg::to_settings( $doc, $c )->get_error_code() );
		wp_delete_file( $file );
	}

	/**
	 * A token set saved before contract 1.6.0 has no fontFamily.ui; the role takes the body font instead of failing.
	 */
	public function test_missing_role_with_fallback_takes_the_fallback_token(): void {
		$doc = self::minimal();
		unset( $doc['fontFamily']['ui'] );
		$doc['fontFamily']['body']['$value'] = array( 'Open Sans', 'sans-serif' );
		$settings                            = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		$this->assertIsArray( $settings );
		$families = array_column( $settings['typography']['fontFamilies']['theme'], 'fontFamily', 'slug' );
		$this->assertSame( $families['body'], $families['ui'] );
	}

	/**
	 * CSS values pass an allowlist: nothing that can load a resource or break out of the declaration.
	 *
	 * @dataProvider css_values
	 *
	 * @param string $css  CSS value.
	 * @param bool   $safe Whether it is allowed.
	 */
	public function test_css_allowlist( string $css, bool $safe ): void {
		$this->assertSame( $safe, Dtcg::is_safe_css( $css ) );
		$doc = self::minimal();
		$doc['spacing']['s']['$extensions']['com.leitvia']['css'] = $css;
		$result = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		$this->assertSame( $safe ? null : 'leitvia_studio_dtcg_invalid_value', is_wp_error( $result ) ? $result->get_error_code() : null );
	}

	/**
	 * CSS values and whether they are allowed.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function css_values(): array {
		return array(
			'clamp'          => array( 'clamp(0.75rem, 0.6rem + 0.5vw, 1rem)', true ),
			'calc with var'  => array( 'calc(var(--wp--preset--spacing--s) * 2)', true ),
			'rgb shadow'     => array( '0 1px 2px rgb(0 0 0 / 0.06)', true ),
			'url'            => array( 'url(https://example.com/x.png)', false ),
			'url with space' => array( 'url (x)', false ),
			'image-set'      => array( 'image-set("x.png" 1x)', false ),
			'comment'        => array( '1rem /* x */', false ),
			'import'         => array( '@import "x.css"', false ),
			'important'      => array( '1rem !important', false ),
			'semicolon'      => array( '1rem; color: red', false ),
			'newline'        => array( "1rem\n2rem", false ),
			'expression'     => array( 'expression(alert(1))', false ),
			'foreign var'    => array( 'var(--x)', false ),
			'open paren'     => array( 'calc(1rem + 2px', false ),
			'close paren'    => array( '1rem)', false ),
			'open quote'     => array( '"Inter', false ),
			'paren in quote' => array( '"a(b"', false ),
		);
	}

	/**
	 * Font family names are quoted and checked; a name with a paren is rejected.
	 */
	public function test_font_family_names(): void {
		$doc                                 = self::minimal();
		$doc['fontFamily']['mono']['$value'] = array( "Bob's Mono", 'monospace' );
		$settings                            = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		$this->assertNotWPError( $settings );
		$this->assertSame( "\"Bob's Mono\", monospace", wp_list_pluck( $settings['typography']['fontFamilies']['theme'], 'fontFamily', 'slug' )['mono'] );

		$doc['fontFamily']['mono']['$value'] = array( 'x) url(y', 'monospace' );
		$this->assertSame( 'leitvia_studio_dtcg_invalid_value', Dtcg::to_settings( $doc, leitvia_studio_contract() )->get_error_code() );
	}

	/**
	 * Aliases inside composite values resolve; fluid settings and names are checked.
	 */
	public function test_nested_alias_fluid_and_name(): void {
		$doc                              = self::minimal();
		$doc['primitive']['color']['red'] = array(
			'$type'  => 'color',
			'$value' => array(
				'colorSpace' => 'srgb',
				'components' => array( 1, 0, 0 ),
				'alpha'      => 0.5,
			),
		);
		unset( $doc['shadow']['subtle']['$extensions']['com.leitvia']['css'] );
		$doc['shadow']['subtle']['$value'][0]['color']                 = '{primitive.color.red}';
		$doc['fontSize']['body']['$extensions']['com.leitvia']['name'] = '<b>Body</b>';
		$settings = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		$this->assertNotWPError( $settings );
		$this->assertStringStartsWith( '0px 1px 2px 0px rgb(255 0 0 / 0.5), ', wp_list_pluck( $settings['shadow']['presets']['theme'], 'shadow', 'slug' )['subtle'] );
		$this->assertSame( 'Body', wp_list_pluck( $settings['typography']['fontSizes']['theme'], 'name', 'slug' )['body'] );

		$doc['shadow']['subtle']['$value'][0]['color'] = '{primitive.color.none}';
		$this->assertSame( 'leitvia_studio_dtcg_alias_missing', Dtcg::to_settings( $doc, leitvia_studio_contract() )->get_error_code() );

		$doc = self::minimal();
		$doc['fontSize']['body']['$extensions']['com.leitvia']['fluid'] = array( 'min' => 'url(x)' );
		$this->assertSame( 'leitvia_studio_dtcg_invalid_value', Dtcg::to_settings( $doc, leitvia_studio_contract() )->get_error_code() );
		$doc['fontSize']['body']['$extensions']['com.leitvia']['fluid'] = array( 'step' => '1rem' );
		$this->assertSame( 'leitvia_studio_dtcg_invalid_value', Dtcg::to_settings( $doc, leitvia_studio_contract() )->get_error_code() );
	}

	/**
	 * Without css extension the $value is compiled; unknown fonts get no font faces.
	 */
	public function test_value_without_css_and_unknown_font(): void {
		$doc = self::minimal();
		unset( $doc['spacing']['s']['$extensions']['com.leitvia']['css'], $doc['shadow']['subtle']['$extensions']['com.leitvia']['css'] );
		$doc['fontFamily']['mono']['$value'] = array( 'Comic Neue', 'monospace' );
		$settings                            = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		$this->assertNotWPError( $settings );
		$this->assertSame( '0.75rem', wp_list_pluck( $settings['spacing']['spacingSizes']['theme'], 'size', 'slug' )['s'] );
		$this->assertSame( '0px 1px 2px 0px rgb(0 0 0 / 0.06), 0px 2px 8px 0px rgb(0 0 0 / 0.06)', wp_list_pluck( $settings['shadow']['presets']['theme'], 'shadow', 'slug' )['subtle'] );
		$mono = array_values( wp_list_filter( $settings['typography']['fontFamilies']['theme'], array( 'slug' => 'mono' ) ) )[0];
		$this->assertSame( '"Comic Neue", monospace', $mono['fontFamily'] );
		$this->assertArrayNotHasKey( 'fontFace', $mono );
	}

	/**
	 * Size and depth limits.
	 */
	public function test_validate_size_and_sanitize(): void {
		$this->assertTrue( Dtcg::validate_size( '{"a":1}' ) );
		$this->assertSame( 'leitvia_studio_dtcg_too_large', Dtcg::validate_size( '"' . str_repeat( 'a', 512 * 1024 ) . '"' )->get_error_code() );
		$this->assertSame( 'leitvia_studio_dtcg_invalid_json', Dtcg::validate_size( str_repeat( '[', 40 ) . str_repeat( ']', 40 ) )->get_error_code() );
		$this->assertSame( '', Dtcg::sanitize_json( '{broken' ) );
		$this->assertSame( "{\n    \"a\": \"ü/\"\n}", Dtcg::sanitize_json( '{"a":"ü\/"}' ) );
	}

	/**
	 * Theme export of the active theme, a valid document to mutate.
	 *
	 * @return array<string, mixed>
	 */
	private static function minimal(): array {
		return Dtcg::from_settings( wp_get_global_settings(), leitvia_studio_contract() );
	}
}
