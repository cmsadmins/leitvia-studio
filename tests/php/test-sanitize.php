<?php
/**
 * Sanitizing of meta and options by type.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Lab\Pattern_Meta;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;
use LeitviaStudio\Tokens\Dtcg;

/**
 * Every write of a field, setting, lab meta or token document gets text cleaned for its type: no tags, no script, line
 * breaks only where the type keeps them.
 */
class Test_Sanitize extends WP_UnitTestCase {

	/**
	 * Text with a tag, a script and a line break.
	 */
	private const DIRTY = "Hall <b>2</b><script>alert(1)</script>\nsecond line";

	/**
	 * Registers the events model.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Model::OPTION, json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/model/events.json' ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		Model::forget();
		Registrar::register();
	}

	/**
	 * Unregisters the settings and drops the cached model.
	 */
	public function tear_down(): void {
		foreach ( array( 'company_phone', 'company_email' ) as $key ) {
			unregister_setting( Registrar::SETTINGS_GROUP, Registrar::SETTING_PREFIX . $key );
		}
		Model::forget();
		parent::tear_down();
	}

	/**
	 * Cases of Registrar::sanitize(): field, value, expected value.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: mixed}>
	 */
	public static function provide_types(): array {
		$select = array(
			'type'    => 'select',
			'options' => array(
				'beginner' => 'Beginner',
				'advanced' => 'Advanced',
			),
		);
		return array(
			'text drops tags, script and line breaks' => array( array( 'type' => 'text' ), self::DIRTY, 'Hall 2 second line' ),
			'textarea keeps line breaks'              => array( array( 'type' => 'textarea' ), self::DIRTY, "Hall 2\nsecond line" ),
			'email keeps an address'                  => array( array( 'type' => 'email' ), ' a@example.org ', 'a@example.org' ),
			'email drops a script'                    => array( array( 'type' => 'email' ), '<script>alert(1)</script>', '' ),
			'url drops a script scheme'               => array( array( 'type' => 'url' ), 'javascript:alert(1)', '' ),
			'url keeps https'                         => array( array( 'type' => 'url' ), 'https://example.org/a?b=1', 'https://example.org/a?b=1' ),
			'integer'                                 => array( array( 'type' => 'integer' ), '12<script>', 0 ),
			'number'                                  => array( array( 'type' => 'number' ), '12.5', 12.5 ),
			'boolean'                                 => array( array( 'type' => 'boolean' ), 'false', false ),
			'select keeps an option key'              => array( $select, 'advanced', 'advanced' ),
			'select drops anything else'              => array( $select, '<b>advanced</b>', '' ),
			'date'                                    => array( array( 'type' => 'date' ), '2026-11-14<script>', '' ),
			'time'                                    => array( array( 'type' => 'time' ), '18:30', '18:30' ),
			'datetime drops text that is no date'     => array( array( 'type' => 'datetime' ), '<b>soon</b>', '' ),
			'empty stays empty'                       => array( array( 'type' => 'number' ), '', '' ),
		);
	}

	/**
	 * Values are cleaned for the type of the field.
	 *
	 * @dataProvider provide_types
	 *
	 * @param array<string, mixed> $field    Field.
	 * @param mixed                $value    Value.
	 * @param mixed                $expected Expected value.
	 */
	public function test_sanitize_by_type( array $field, $value, $expected ): void {
		$this->assertSame( $expected, Registrar::sanitize( $field, $value ) );
	}

	/**
	 * Meta of a field is cleaned on every write, not only over REST.
	 */
	public function test_field_meta_is_cleaned_on_write(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'event' ) );
		update_post_meta( $id, 'event_venue', self::DIRTY );
		$this->assertSame( 'Hall 2 second line', get_post_meta( $id, 'event_venue', true ) );
		update_post_meta( $id, 'event_level', '<script>x</script>' );
		$this->assertSame( '', get_post_meta( $id, 'event_level', true ) );
		update_post_meta( $id, 'event_speakers', array( '3', 'x', -2, 0 ) );
		$this->assertSame( array( 3, 2 ), get_post_meta( $id, 'event_speakers', true ) );
	}

	/**
	 * A site setting is cleaned after the schema check, the way update-site-settings writes it.
	 */
	public function test_setting_is_cleaned_on_write(): void {
		update_option( Registrar::SETTING_PREFIX . 'company_phone', self::DIRTY );
		$this->assertSame( 'Hall 2 second line', get_option( Registrar::SETTING_PREFIX . 'company_phone' ) );
	}

	/**
	 * Lab meta cleans every string of its entries, free text keeps line breaks.
	 */
	public function test_lab_meta_cleans_every_string(): void {
		$value = Pattern_Meta::sanitize_entries(
			array(
				array(
					'target' => '<b>p:0</b>',
					'text'   => self::DIRTY,
				),
			),
			'_leitvia_lab_annotations'
		);
		$this->assertSame( 'p:0', $value[0]['target'] );
		$this->assertSame( "Hall 2\nsecond line", $value[0]['text'] );
	}

	/**
	 * Strings of a token document lose tags.
	 */
	public function test_token_document_strings_are_plain(): void {
		$token = array(
			'$description' => 'Brand <script>x</script>',
			'$value'       => '#000000',
		);
		$json  = Dtcg::sanitize_json( (string) wp_json_encode( array( 'color' => $token ) ) );
		$this->assertStringNotContainsString( '<script>', $json );
		$this->assertStringContainsString( '#000000', $json );
	}
}
