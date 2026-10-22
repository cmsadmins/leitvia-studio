<?php
/**
 * Token lint tests.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Lab\Token_Lint;

/**
 * Parity with lintMarkup() in JavaScript, the registered check and the stored result.
 */
class Test_Token_Lint extends WP_UnitTestCase {

	/**
	 * PHP finds exactly what JavaScript finds, on every theme pattern and the artificial cases.
	 */
	public function test_parity_with_javascript(): void {
		$fixture = wp_json_file_decode( __DIR__ . '/../fixtures/token-lint-cases.json', array( 'associative' => true ) );
		$this->assertGreaterThan( 3, count( $fixture['cases'] ) );
		foreach ( $fixture['cases'] as $case ) {
			$this->assertSame( $case['expected'], Token_Lint::lint_markup( $case['content'] ), $case['name'] );
		}
	}

	/**
	 * The check is registered in the checks registry with scope pattern.
	 */
	public function test_check_is_registered(): void {
		leitvia_studio()->reset_for_tests();
		$check = leitvia_studio()->registry( 'checks' )->get( 'leitvia/tokens' );
		$this->assertIsArray( $check );
		$this->assertSame( 'pattern', $check['scope'] );
		$this->assertSame( 'leitvia-studio', $check['owner'] );
		$this->assertCount( 1, call_user_func( $check['callback'], '<p style="color:#fff">x</p>' ) );
	}

	/**
	 * The stored result keeps the full count but at most 200 findings.
	 */
	public function test_findings_are_capped(): void {
		$result = Token_Lint::result( str_repeat( '<p style="color:#fff">x</p>', 250 ) );
		$this->assertSame( 250, $result['count'] );
		$this->assertCount( 200, $result['findings'] );
	}

	/**
	 * Saving a wp_block stores the findings, keeps other check results and updates them on the next save.
	 */
	public function test_result_is_stored_on_save(): void {
		$id     = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:paragraph --><p style="padding:12px">x</p><!-- /wp:paragraph -->',
			)
		);
		$checks = get_post_meta( $id, '_leitvia_lab_checks', true );
		$this->assertSame( 1, $checks['tokens']['count'] );
		$this->assertSame(
			array(
				array(
					'rule'  => 'no-px',
					'match' => '12px',
				),
			),
			$checks['tokens']['findings']
		);
		$this->assertNotEmpty( $checks['checked_at'] );

		$checks['axe'] = array( 'default|390' => array() );
		update_post_meta( $id, '_leitvia_lab_checks', $checks );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);
		$checks = get_post_meta( $id, '_leitvia_lab_checks', true );
		$this->assertSame( 0, $checks['tokens']['count'] );
		$this->assertSame( array( 'default|390' => array() ), $checks['axe'] );
	}
}
