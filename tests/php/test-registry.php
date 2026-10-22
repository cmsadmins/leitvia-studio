<?php
/**
 * Registry tests.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Extension\Registry;

/**
 * Typed registry: validation, owner, filter.
 */
class Test_Registry extends WP_UnitTestCase {

	/**
	 * A registry with the fields of the checks registry.
	 */
	private function checks(): Registry {
		return new Registry(
			'checks',
			array(
				'label'    => 'string',
				'scope'    => array( 'token_set', 'pattern' ),
				'callback' => 'callable',
			)
		);
	}

	/**
	 * A valid entry is stored with its owner.
	 */
	public function test_registers_valid_item_with_owner(): void {
		$r = $this->checks();
		$this->assertTrue(
			$r->register(
				'leitvia/contrast',
				array(
					'label'    => 'Contrast',
					'scope'    => 'token_set',
					'callback' => '__return_empty_array',
				),
				'leitvia-studio'
			)
		);
		$this->assertSame( 'leitvia-studio', $r->get( 'leitvia/contrast' )['owner'] );
	}

	/**
	 * Duplicates, missing fields, wrong enum values and foreign prefixes are rejected.
	 */
	public function test_rejects_duplicate_missing_field_and_wrong_enum(): void {
		$this->setExpectedIncorrectUsage( Registry::class . '::register' );
		$r    = $this->checks();
		$item = array(
			'label'    => 'A',
			'scope'    => 'token_set',
			'callback' => '__return_empty_array',
		);
		$r->register( 'x/a', $item, 'x' );
		$this->assertFalse( $r->register( 'x/a', $item, 'x' ) );
		$this->assertFalse( $r->register( 'x/b', array( 'label' => 'B' ), 'x' ) );
		$this->assertFalse( $r->register( 'x/c', array_merge( $item, array( 'scope' => 'page' ) ), 'x' ) );
		$this->assertFalse( $r->register( 'other/d', $item, 'x' ), 'name must start with the owner slug or leitvia/' );
	}

	/**
	 * Filters by scalar fields.
	 */
	public function test_filter_by_field(): void {
		$r = $this->checks();
		$r->register(
			'x/a',
			array(
				'label'    => 'A',
				'scope'    => 'pattern',
				'callback' => '__return_empty_array',
			),
			'x'
		);
		$this->assertSame( array( 'x/a' ), array_keys( $r->all( array( 'scope' => 'pattern' ) ) ) );
		$this->assertSame( array(), $r->all( array( 'scope' => 'token_set' ) ) );
	}
}
