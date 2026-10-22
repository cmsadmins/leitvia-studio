<?php
/**
 * Code copy: hidden button, live region, module only with code.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Code_Copy;

/**
 * The button exists in the markup but stays hidden until the script module runs.
 */
class Test_Code_Copy extends WP_UnitTestCase {

	/**
	 * Starts every test with an empty module queue.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_dequeue_script_module( Code_Copy::MODULE );
		Code_Copy::register_module();
	}

	/**
	 * Enqueued script modules.
	 *
	 * @return array<int, string>
	 */
	private static function queue(): array {
		$queue = ( new ReflectionProperty( WP_Script_Modules::class, 'queue' ) )->getValue( wp_script_modules() );
		return array_values( (array) $queue );
	}

	/**
	 * The code block gets a hidden, named button and an empty live region with both messages; terminal style too.
	 */
	public function test_button_is_hidden_and_named(): void {
		foreach ( array( '', ' is-style-terminal' ) as $style ) {
			$html = do_blocks( '<!-- wp:code --><pre class="wp-block-code' . $style . '"><code>npm ci</code></pre><!-- /wp:code -->' );
			$this->assertStringContainsString( '<pre class="wp-block-code' . $style . '"', $html );
			$this->assertStringContainsString( '<button type="button" class="leitvia-code-copy__button" hidden>Copy code</button>', $html );
			$this->assertMatchesRegularExpression( '#<span class="leitvia-code-copy__status" role="status" data-copied="Copied" data-failed="Copy failed, select the code and copy it manually"></span>#', $html );
		}
	}

	/**
	 * Wide code stays wide: the wrapper takes the alignment class.
	 */
	public function test_wrapper_keeps_alignment(): void {
		$html = do_blocks( '<!-- wp:code {"align":"wide"} --><pre class="wp-block-code alignwide"><code>x</code></pre><!-- /wp:code -->' );
		$this->assertStringStartsWith( '<div class="leitvia-code-copy alignwide">', $html );
	}

	/**
	 * The module loads only with a code block on the page.
	 */
	public function test_module_only_with_code(): void {
		do_blocks( '<!-- wp:paragraph --><p>No code.</p><!-- /wp:paragraph -->' );
		$this->assertNotContains( Code_Copy::MODULE, self::queue() );
		do_blocks( '<!-- wp:code --><pre class="wp-block-code"><code>x</code></pre><!-- /wp:code -->' );
		$this->assertContains( Code_Copy::MODULE, self::queue() );
	}
}
