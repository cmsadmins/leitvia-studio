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
