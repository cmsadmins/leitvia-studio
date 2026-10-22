<?php
/**
 * Block catalog: registry coverage, schema, usage rules, core extensions, REST permission.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Catalog;
use LeitviaStudio\Extension\Studio;

/**
 * GET /leitvia-studio/v1/blocks and leitvia_studio_contract()->blocks().
 */
class Test_Catalog extends WP_UnitTestCase {

	/**
	 * Drops the add-on block and leaves Studio as it was after boot; the parent restores the hooks first.
	 */
	public function tear_down(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'catalog-addon/demo' ) ) {
			unregister_block_type( 'catalog-addon/demo' );
		}
		parent::tear_down();
		leitvia_studio()->reset_for_tests();
	}

	/**
	 * Catalog entries by block name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function by_name(): array {
		return array_column( leitvia_studio_contract()->blocks(), null, 'name' );
	}

	/**
	 * Every entry of the registry blocks is in the catalog with its owner, also the block of an add-on.
	 */
	public function test_every_registry_entry_is_in_the_catalog(): void {
		add_action(
			'leitvia_studio_init',
			static function ( Studio $studio ): void {
				$studio->extensions()->register(
					'catalog-addon',
					array(
						'label'           => 'Catalog Add-on',
						'version'         => '1.0.0',
						'requires_studio' => '0.1.0',
						'requires_api'    => 1,
					)
				);
				$studio->registry( 'blocks' )->register( 'catalog-addon/demo', array( 'path' => dirname( __DIR__ ) . '/fixtures/addon-block' ), 'catalog-addon' );
			}
		);
		leitvia_studio()->reset_for_tests();
		register_block_type( dirname( __DIR__ ) . '/fixtures/addon-block' );

		$catalog = self::by_name();
		$entries = leitvia_studio()->registry( 'blocks' )->all();
		$this->assertGreaterThan( 20, count( $entries ) );
		foreach ( $entries as $name => $entry ) {
			$this->assertArrayHasKey( $name, $catalog );
			$this->assertSame( $entry['owner'], $catalog[ $name ]['owner'], $name );
		}
		$this->assertSame( 'catalog-addon', $catalog['catalog-addon/demo']['owner'] );
		$this->assertNull( $catalog['catalog-addon/demo']['usage'] );
		$this->assertSame(
			array(
				'type'    => 'string',
				'enum'    => array( 'a', 'b' ),
				'default' => 'a',
			),
			$catalog['catalog-addon/demo']['attributes']['properties']['mode']
		);
	}

	/**
	 * The catalog validates against schemas/block-catalog.schema.json.
	 */
	public function test_catalog_matches_the_schema(): void {
		$result = rest_validate_value_from_schema( leitvia_studio_contract()->blocks(), Catalog::schema(), 'catalog' );
		$this->assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Every Studio block has useWhen, avoidWhen and a11y; attributes come from block.json without the global ones.
	 */
	public function test_studio_blocks_have_usage(): void {
		$catalog = self::by_name();
		foreach ( $catalog as $name => $entry ) {
			if ( 'leitvia-studio' !== $entry['owner'] ) {
				continue;
			}
			foreach ( array( 'useWhen', 'avoidWhen', 'a11y' ) as $key ) {
				$this->assertNotEmpty( $entry['usage'][ $key ] ?? '', "{$name} {$key}" );
			}
		}
		$bento = $catalog['leitvia/bento'];
		$this->assertSame( array( 'leitvia/bento-item' ), $bento['innerBlocks'] );
		$this->assertSame( array( 'wide', 'full' ), $bento['supports']['align'] );
		$this->assertSame( 'string', $catalog['leitvia/counter']['attributes']['properties']['label']['type'] );
		$this->assertSame( array( 'leitvia/bento' ), $catalog['leitvia/bento-item']['parent'] );
	}

	/**
	 * The core blocks Studio extends are listed with the extension.
	 */
	public function test_core_extensions(): void {
		$catalog = self::by_name();
		foreach ( array( 'core/code', 'core/embed', 'core/tabs' ) as $name ) {
			$this->assertArrayHasKey( $name, $catalog );
			$this->assertSame( 'core', $catalog[ $name ]['owner'] );
			$this->assertNotEmpty( $catalog[ $name ]['extension'] );
			$this->assertNotEmpty( $catalog[ $name ]['usage']['useWhen'] );
		}
	}

	/**
	 * The filter changes the catalog.
	 */
	public function test_filter(): void {
		$filter = static fn( array $catalog ): array => array_slice( $catalog, 0, 1 );
		add_filter( 'leitvia_studio_block_catalog', $filter );
		$count  = count( leitvia_studio_contract()->blocks() );
		remove_filter( 'leitvia_studio_block_catalog', $filter );
		$this->assertSame( 1, $count );
	}

	/**
	 * The route needs edit_posts; the contract route points to it.
	 */
	public function test_rest_needs_edit_posts(): void {
		$request = new WP_REST_Request( 'GET', '/leitvia-studio/v1/blocks' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'leitvia/timeline', array_column( $response->get_data(), 'name' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$contract = rest_do_request( new WP_REST_Request( 'GET', '/leitvia-studio/v1/contract' ) )->get_data();
		$this->assertSame( rest_url( 'leitvia-studio/v1/blocks' ), $contract['blocks'] );
	}
}
