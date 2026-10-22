<?php
/**
 * Editor script of the content model panel.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Model\Bindings;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;

/**
 * The script loads in the block editor of declared types with fields only, with the declaration of the type.
 */
class Test_Editor_Assets extends WP_UnitTestCase {

	/**
	 * Stores the fixture and registers it.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Model::OPTION, json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/model/events.json' ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		Model::forget();
		Registrar::register();
	}

	/**
	 * Resets screen, scripts and the cached model.
	 */
	public function tear_down(): void {
		wp_dequeue_script( 'leitvia-studio-editor' );
		wp_deregister_script( 'leitvia-studio-editor' );
		wp_dequeue_style( 'leitvia-studio-editor' );
		wp_deregister_style( 'leitvia-studio-editor' );
		set_current_screen( 'front' );
		Model::forget();
		parent::tear_down();
	}

	/**
	 * Fires the editor hook on the edit screen of a post type.
	 *
	 * @param string $post_type Post type.
	 */
	private function enqueue_for( string $post_type ): void {
		set_current_screen( $post_type );
		do_action( 'enqueue_block_editor_assets' );
	}

	/**
	 * Events get the script with their declaration.
	 */
	public function test_enqueued_for_event_with_declaration(): void {
		$this->assertFileExists( dirname( LEITVIA_STUDIO_FILE ) . '/build/editor.asset.php', 'Run the build first.' );
		$this->enqueue_for( 'event' );
		$this->assertTrue( wp_script_is( 'leitvia-studio-editor' ) );
		$this->assertTrue( wp_style_is( 'leitvia-studio-editor' ) );
		$inline = array_filter( (array) wp_scripts()->get_data( 'leitvia-studio-editor', 'before' ) );
		$this->assertStringStartsWith( 'window.leitviaStudioBindings = ', (string) end( $inline ) );
		$inline = (string) reset( $inline );
		$this->assertStringStartsWith( 'window.leitviaStudioModel = ', $inline );
		$declaration = json_decode( substr( $inline, strlen( 'window.leitviaStudioModel = ' ), -1 ), true );
		$this->assertSame( 'event', $declaration['postType'] );
		$this->assertSame( array( 'event' ), array_keys( $declaration['postTypes'] ) );
		$this->assertSame( array( 'event_speakers' ), array_keys( $declaration['relations'] ) );
		$this->assertSame( 'event_session', $declaration['postTypes']['event']['repeaters']['event_agenda']['postType'] );
	}

	/**
	 * The site editor gets the script for the "Child entries" variation, with every type that has repeaters and no panel.
	 */
	public function test_site_editor_gets_repeater_types(): void {
		$this->enqueue_for( 'site-editor' );
		$this->assertTrue( wp_script_is( 'leitvia-studio-editor' ) );
		$inline = implode( '', (array) wp_scripts()->get_data( 'leitvia-studio-editor', 'before' ) );
		$this->assertStringContainsString( 'window.leitviaStudioBindings = ', $inline );
		$this->assertStringContainsString( 'window.leitviaStudioModel = ', $inline );
		$declaration = Registrar::site_editor_declaration();
		$this->assertSame( '', $declaration['postType'] );
		$this->assertSame( array( 'event' ), array_keys( $declaration['postTypes'] ) );
	}

	/**
	 * Other post types get the script with the bindings data only; the panel declaration stays with types that have
	 * fields, relations or repeaters.
	 */
	public function test_bindings_data_everywhere_panel_only_for_declared_types(): void {
		foreach ( array( 'post', 'event_session' ) as $screen ) {
			$this->enqueue_for( $screen );
			$this->assertTrue( wp_script_is( 'leitvia-studio-editor' ), $screen );
			$inline = implode( '', (array) wp_scripts()->get_data( 'leitvia-studio-editor', 'before' ) );
			$this->assertStringNotContainsString( 'leitviaStudioModel', $inline, $screen );
			$this->assertStringStartsWith( 'window.leitviaStudioBindings = ', $inline, $screen );
			$this->assertStringContainsString( '"company_phone"', $inline, $screen );
			$this->assertStringNotContainsString( 'company_email', $inline, $screen );
			wp_dequeue_script( 'leitvia-studio-editor' );
			wp_deregister_script( 'leitvia-studio-editor' );
		}
		$this->assertNull( Registrar::editor_declaration( 'post' ) );

		$decl = get_option( Model::OPTION );
		unset( $decl['postTypes']['person']['fields'] );
		update_option( Model::OPTION, $decl );
		Model::forget();
		$this->assertNull( Registrar::editor_declaration( 'person' ) );
		$this->assertNotNull( Registrar::editor_declaration( 'event' ) );
	}

	/**
	 * The bindings data lists declared fields by type, relations and bindable settings with their value.
	 */
	public function test_bindings_data(): void {
		update_option( 'leitvia_site_company_phone', '+49 30 123456' );
		$data = json_decode( (string) wp_json_encode( Bindings::editor_data() ), true );
		$this->assertSame( array( 'event', 'event_session', 'person' ), array_keys( $data['postTypes'] ) );
		$this->assertSame( array( 'event_start', 'event_venue', 'event_price', 'event_level', 'event_poster' ), array_keys( $data['postTypes']['event']['fields'] ) );
		$this->assertSame( 'person', $data['relations']['event_speakers']['to'] );
		$this->assertSame( '+49 30 123456', $data['settings']['company_phone']['value'] );
		$this->assertSame( 'Phone', $data['settings']['company_phone']['label'] );
		// Settings without "bindable" stay out of the editor, whatever the user may do.
		$this->assertSame( array( 'company_phone' ), array_keys( $data['settings'] ) );
	}

	/**
	 * Widget editors get nothing. A model without anything to bind still gets the script (format "Highlight"), without
	 * bindings data.
	 */
	public function test_not_enqueued_in_widget_editor_no_data_without_model(): void {
		$this->enqueue_for( 'widgets' );
		$this->assertFalse( wp_script_is( 'leitvia-studio-editor' ) );
		update_option( Model::OPTION, array( 'version' => 1 ) );
		Model::forget();
		$this->assertNull( Bindings::editor_data() );
		$this->enqueue_for( 'post' );
		$this->assertTrue( wp_script_is( 'leitvia-studio-editor' ) );
		$this->assertSame( array(), array_filter( (array) wp_scripts()->get_data( 'leitvia-studio-editor', 'before' ) ) );
	}
}
