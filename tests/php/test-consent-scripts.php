<?php
/**
 * Consent for scripts of plugins: scripts the filter leitvia_studio_consent_scripts names wait as text/plain.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Consent\Scripts;

/**
 * Held scripts carry type="text/plain" and data-leitvia-consent, every other script stays as WordPress prints it.
 */
class Test_Consent_Scripts extends WP_UnitTestCase {

	/**
	 * Entries of the filter for the current test.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $entries = array();

	/**
	 * A fresh script registry and the filter with the entries of the test.
	 */
	public function set_up(): void {
		parent::set_up();
		$GLOBALS['wp_scripts'] = new WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh registry per test.
		$this->entries         = array(
			array(
				'host'     => 'widgets.example.com',
				'category' => 'marketing',
			),
			array(
				'handle'   => 'my-tracker',
				'category' => 'statistics',
			),
		);
		add_filter( 'leitvia_studio_consent_scripts', fn() => $this->entries );
	}

	/**
	 * Back to the registry of the next test.
	 */
	public function tear_down(): void {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh registry for the next test.
		parent::tear_down();
	}

	/**
	 * What WordPress prints for the handles.
	 *
	 * @param string ...$handles Script handles.
	 */
	private static function print( string ...$handles ): string {
		ob_start();
		wp_scripts()->do_items( $handles );
		return (string) ob_get_clean();
	}

	/**
	 * Entries need a category of the list and a handle or a host; a host given as URL becomes the host name.
	 */
	public function test_filter_entries_are_checked(): void {
		$this->entries = array(
			array(
				'host'     => 'https://Widgets.Example.com:8443/path/',
				'category' => 'marketing',
			),
			array(
				'host'     => '/',
				'category' => 'marketing',
			),
			array(
				'handle'   => 'a',
				'category' => 'advertising',
			),
			array( 'category' => 'statistics' ),
			array( 'handle' => 'b' ),
			'c',
		);
		$this->assertSame(
			array(
				array(
					'handle'   => '',
					'host'     => 'widgets.example.com',
					'category' => 'marketing',
				),
			),
			Scripts::entries()
		);
		$this->assertSame( array( 'statistics', 'marketing' ), array_keys( Scripts::categories() ) );
		$this->assertSame( 'widgets.example.com', Scripts::host( 'widgets.example.com/' ) );
		$this->assertSame( 'widgets.example.com', Scripts::host( '//WIDGETS.example.com' ) );
	}

	/**
	 * The keys of the categories are reserved: a service of that name is dropped, others stay.
	 */
	public function test_category_keys_are_reserved_for_services(): void {
		$filter   = static fn( array $services ): array => $services + array(
			'marketing' => array( 'label' => 'Ads' ),
			'peertube'  => array( 'label' => 'PeerTube' ),
		);
		add_filter( 'leitvia_studio_consent_services', $filter );
		$services = LeitviaStudio\Consent\Consent::services();
		remove_filter( 'leitvia_studio_consent_services', $filter );
		$this->assertArrayNotHasKey( 'marketing', $services );
		$this->assertArrayHasKey( 'peertube', $services );
	}

	/**
	 * Resource hints and preloads to a listed host go, in string and array form, in every relation; others stay.
	 */
	public function test_resource_hints_to_a_listed_host_go(): void {
		$hints   = static fn( array $urls ): array => array_merge(
			$urls,
			array(
				'https://cdn.widgets.example.com',
				array(
					'href'        => '//widgets.example.com',
					'crossorigin' => 'anonymous',
				),
				'https://other.example.org',
			)
		);
		add_filter( 'wp_resource_hints', $hints );
		$preload = static fn( array $resources ): array => array_merge(
			$resources,
			array(
				array(
					'href' => 'https://widgets.example.com/font.woff2',
					'as'   => 'font',
				),
				array(
					'href' => home_url( '/own.woff2' ),
					'as'   => 'font',
				),
			)
		);
		add_filter( 'wp_preload_resources', $preload );
		$html    = get_echo( 'wp_resource_hints' ) . get_echo( 'wp_preload_resources' );
		remove_filter( 'wp_resource_hints', $hints );
		remove_filter( 'wp_preload_resources', $preload );
		$this->assertStringNotContainsString( 'widgets.example.com', $html );
		$this->assertStringContainsString( 'other.example.org', $html );
		$this->assertStringContainsString( 'own.woff2', $html );
	}

	/**
	 * A script from a listed host (subdomains included) waits with its inline scripts; the src stays, a browser fetches
	 * no script of type text/plain.
	 */
	public function test_script_of_a_listed_host_waits(): void {
		wp_register_script( 'widget', 'https://cdn.widgets.example.com/widget.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- As plugins load third-party scripts.
		wp_add_inline_script( 'widget', 'window.widgetBefore = 1;', 'before' );
		wp_add_inline_script( 'widget', 'window.widget.init();' );
		wp_localize_script( 'widget', 'widgetConfig', array( 'id' => '1' ) );
		$html = self::print( 'widget' );
		$this->assertMatchesRegularExpression( '#<script(?=[^>]*id="widget-js-extra")(?=[^>]*type="text/plain")[^>]*data-leitvia-consent="marketing"#', $html );
		$this->assertMatchesRegularExpression( '#<script[^>]*id="widget-js-before"[^>]*>#', $html );
		$this->assertSame( 4, preg_match_all( '#<script(?=[^>]*type="text/plain")(?=[^>]*data-leitvia-consent="marketing")[^>]*>#', $html ) );
		$this->assertStringContainsString( 'src="https://cdn.widgets.example.com/widget.js"', $html );
		$this->assertStringContainsString( 'window.widget.init();', $html );
	}

	/**
	 * A script of the own site and of an unlisted host stays unchanged, a host that only ends like a listed one too.
	 */
	public function test_other_scripts_stay_unchanged(): void {
		wp_register_script( 'own', home_url( '/own.js' ), array(), '1', true );
		wp_add_inline_script( 'own', 'own();' );
		wp_register_script( 'other', 'https://other.example.org/x.js', array(), '1', true );
		wp_register_script( 'lookalike', 'https://notwidgets.example.com/x.js', array(), '1', true );
		$html = self::print( 'own', 'other', 'lookalike' );
		$this->assertStringNotContainsString( 'text/plain', $html );
		$this->assertStringNotContainsString( 'data-leitvia-consent', $html );
		$this->assertSame( 4, substr_count( $html, '<script' ) );
	}

	/**
	 * A listed handle waits wherever its file lives, and so does a script that depends on it, in the same category.
	 */
	public function test_listed_handle_and_its_dependents_wait(): void {
		wp_register_script( 'my-tracker', home_url( '/tracker.js' ), array(), '1', true );
		wp_register_script( 'tracker-init', home_url( '/tracker-init.js' ), array( 'my-tracker' ), '1', true );
		$html = self::print( 'tracker-init' );
		$this->assertMatchesRegularExpression( '#<script(?=[^>]*id="my-tracker-js")(?=[^>]*type="text/plain")[^>]*data-leitvia-consent="statistics"#', $html );
		$this->assertMatchesRegularExpression( '#<script(?=[^>]*id="tracker-init-js")(?=[^>]*type="text/plain")[^>]*data-leitvia-consent="statistics"#', $html );
	}

	/**
	 * The inline script of a listed handle without a file waits too; only script_loader_tag never sees it.
	 */
	public function test_inline_script_of_a_listed_handle_waits(): void {
		wp_register_script( 'my-tracker', false, array(), '1', true );
		wp_add_inline_script( 'my-tracker', 'track();' );
		$html = self::print( 'my-tracker' );
		$this->assertMatchesRegularExpression( '#<script(?=[^>]*id="my-tracker-js-after")(?=[^>]*type="text/plain")[^>]*data-leitvia-consent="statistics"[^>]*>\s*track\(\);#', $html );
	}

	/**
	 * An inline loader that names a listed host waits; JSON data naming it and other inline scripts stay.
	 */
	public function test_inline_loader_of_a_listed_host_waits(): void {
		$loader = wp_get_inline_script_tag( 'var s = document.createElement("script"); s.src = "https://widgets.example.com/loader.js"; document.head.append(s);', array( 'id' => 'loader' ) );
		$this->assertMatchesRegularExpression( '#<script(?=[^>]*type="text/plain")[^>]*data-leitvia-consent="marketing"#', $loader );
		$json = wp_get_inline_script_tag( '{"url":"https://widgets.example.com/"}', array( 'type' => 'application/json' ) );
		$this->assertStringNotContainsString( 'data-leitvia-consent', $json );
		$this->assertStringNotContainsString( 'data-leitvia-consent', wp_get_inline_script_tag( 'var a = "notwidgets.example.com";' ) );
	}

	/**
	 * A script of type module keeps its type in data-leitvia-type for the activation.
	 */
	public function test_original_type_is_kept(): void {
		$tag = wp_get_script_tag(
			array(
				'src'  => 'https://widgets.example.com/m.js',
				'type' => 'module',
			)
		);
		$this->assertStringContainsString( 'type="text/plain"', $tag );
		$this->assertStringContainsString( 'data-leitvia-type="module"', $tag );
	}

	/**
	 * A tag a plugin rewrote on script_loader_tag still waits, and a held tag is not held twice.
	 */
	public function test_rewritten_tag_waits_once(): void {
		wp_register_script( 'widget', 'https://widgets.example.com/w.js', array(), '1', true );
		add_filter( 'script_loader_tag', static fn( $tag ) => str_replace( ' type="text/plain"', ' type="text/javascript"', $tag ), 20 );
		$html = self::print( 'widget' );
		$this->assertStringContainsString( 'type="text/plain"', $html );
		$this->assertStringNotContainsString( 'text/javascript', $html );
		$this->assertSame( 1, substr_count( $html, 'data-leitvia-consent=' ) );
	}

	/**
	 * A tag another filter rebuilt without the ids of WordPress still waits; a data block in it stays.
	 */
	public function test_rewritten_tag_without_id_waits(): void {
		wp_register_script( 'widget', 'https://widgets.example.com/w.js', array(), '1', true );
		$rewrite = static fn( $tag, $handle ) => 'widget' === $handle ? '<script type="application/json">{}</script><script src="https://widgets.example.com/w.js" data-cfasync="false"></script>' : $tag; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A tag as another filter rebuilds it.
		add_filter( 'script_loader_tag', $rewrite, 20, 2 );
		$html    = self::print( 'widget' );
		remove_filter( 'script_loader_tag', $rewrite, 20 );
		$this->assertMatchesRegularExpression( '#<script(?=[^>]*src="https://widgets.example.com/w.js")(?=[^>]*type="text/plain")[^>]*data-leitvia-consent="marketing"#', $html );
		$this->assertStringContainsString( '<script type="application/json">{}</script>', $html );
	}

	/**
	 * With entries, every page loads the consent module that activates held scripts; without, none.
	 */
	public function test_module_only_with_entries(): void {
		Scripts::enqueue();
		$this->assertContains( '@leitvia/consent', wp_script_modules()->get_queue() );
		wp_dequeue_script_module( '@leitvia/consent' );
		$this->entries = array();
		Scripts::enqueue();
		$this->assertNotContains( '@leitvia/consent', wp_script_modules()->get_queue() );
	}

	/**
	 * The consent settings block offers each category of the entries with an Allow button; without entries it stays.
	 */
	public function test_settings_block_offers_the_categories(): void {
		$block = '<div class="wp-block-leitvia-consent-settings"><div hidden data-wp-bind--hidden="state.noScript"><p role="status" data-wp-text="state.status"></p></div></div>';
		$html  = LeitviaStudio\Consent\Consent::filter_settings_block( $block );
		$this->assertStringContainsString( 'wp-block-leitvia-consent-settings__scripts', $html );
		$this->assertMatchesRegularExpression( '#data-wp-on--click="actions.allow">Allow <span class="screen-reader-text">Marketing</span></button>#', $html );
		$this->assertStringContainsString( 'Statistics', $html );
		$this->assertMatchesRegularExpression( '#__scripts.*<p role="status"#s', $html );

		$this->entries = array(
			array(
				'host'     => 'widgets.example.com',
				'category' => 'marketing',
			),
		);
		$this->assertStringNotContainsString( '>Statistics<', LeitviaStudio\Consent\Consent::filter_settings_block( $block ) );

		$this->entries = array();
		$this->assertStringNotContainsString( '__scripts', LeitviaStudio\Consent\Consent::filter_settings_block( $block ) );
	}
}
