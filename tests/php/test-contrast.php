<?php
/**
 * Contrast tests: PHP and JS agree on tests/fixtures/contrast-cases.json.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Tokens\Contrast;

/**
 * Parity with src/lib/contrast.mjs.
 */
class Test_Contrast extends WP_UnitTestCase {

	/**
	 * Every fixture case yields the failures of the JS implementation, ratios rounded to 4 places.
	 */
	public function test_matches_js_fixture(): void {
		$fixture = wp_json_file_decode( dirname( __DIR__ ) . '/fixtures/contrast-cases.json', array( 'associative' => true ) );
		foreach ( $fixture['cases'] as $case ) {
			$failures = array_map(
				static fn( array $f ): array => array(
					'fg'    => $f['fg'],
					'bg'    => $f['bg'],
					'ratio' => null === $f['ratio'] ? null : round( $f['ratio'], 4 ),
				),
				Contrast::check_palette( $case['palette'], $case['pairs'] ?? $fixture['pairs'] )
			);
			$this->assertEqualsWithDelta( $case['failures'], $failures, 0.0001, $case['name'] );
		}
	}

	/**
	 * Known ratios, unsupported formats and missing roles.
	 */
	public function test_known_values_and_bad_input(): void {
		$this->assertSame( 21.0, round( Contrast::ratio( '#000000', '#ffffff' ), 2 ) );
		$this->assertSame( 4.48, round( Contrast::ratio( '#777777', '#ffffff' ), 2 ) );
		$this->assertWPError( Contrast::ratio( 'red', '#fff' ) );
		$failures = Contrast::check_palette(
			array(
				array(
					'slug'  => 'ink',
					'color' => 'red',
				),
				array(
					'slug'  => 'surface',
					'color' => '#fff',
				),
			),
			array(
				array(
					'fg'  => 'ink',
					'bg'  => 'surface',
					'min' => 4.5,
				),
			)
		);
		$this->assertNull( $failures[0]['ratio'] );
		$this->assertSame( array( 0.0, 0.0, 0.0 ), Contrast::parse( 'hsl(0 0% 0%)' ) );
		$this->assertSame( array( 255.0, 255.0, 255.0 ), Contrast::parse( 'rgba(100%, 100%, 100%, 1)' ) );
		$this->assertNull( Contrast::parse( 'rgba(0, 0, 0, 0.5)' ) );
		$this->assertNull( Contrast::parse( 'rgb(0deg 0 0)' ) );
	}

	/**
	 * Findings round ratios down and name missing roles and unreadable colors.
	 */
	public function test_findings_messages(): void {
		$pairs = array(
			array(
				'fg'  => 'text',
				'bg'  => 'paper',
				'min' => 4.5,
			),
		);
		$grey  = static fn( string $v ): array => array(
			array(
				'slug'  => 'text',
				'color' => "rgb($v $v $v)",
			),
			array(
				'slug'  => 'paper',
				'color' => 'rgb(253 253 253)',
			),
		);
		$this->assertSame( array(), Contrast::findings( $grey( '117.45940287407777' ), $pairs ) );

		$below = Contrast::findings( $grey( '117.5' ), $pairs )[0];
		$this->assertSame( 'error', $below['severity'] );
		$this->assertSame( 4.49, $below['data']['ratio'] );
		$this->assertSame( 'text on paper: 4.49:1, needs 4.5:1', $below['message'] );

		$missing = Contrast::findings( array_slice( $grey( '0' ), 1 ), $pairs )[0];
		$this->assertSame( 'error', $missing['severity'] );
		$this->assertSame( 'text on paper: the color role text is missing', $missing['message'] );

		$unchecked = Contrast::findings( $grey( 'x' ), $pairs )[0];
		$this->assertSame( 'warning', $unchecked['severity'] );
		$this->assertNull( $unchecked['data']['ratio'] );
		$this->assertSame( 'text on paper: unsupported color format, contrast not checked', $unchecked['message'] );
	}
}
