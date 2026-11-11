<?php
/**
 * Core preset aliases: contract, Normalize and the token lint rule no-unknown-preset.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Normalize;
use LeitviaStudio\Lab\Token_Lint;

/**
 * Core slugs that agents with the WordPress skills write (base, spacing 50, large) land on the role; slugs the theme has
 * neither as role nor as alias are rejected with the roles of their type.
 */
class Test_Core_Aliases extends WP_UnitTestCase {

	/**
	 * Activates the Leitvia theme.
	 */
	public function set_up(): void {
		parent::set_up();
		switch_theme( 'leitvia' );
		\LeitviaStudio\Plugin::forget_contract();
	}

	/**
	 * Drops the cached contract.
	 */
	public function tear_down(): void {
		\LeitviaStudio\Plugin::forget_contract();
		parent::tear_down();
	}

	/**
	 * The contract gives the aliases by preset type, with string keys for numeric slugs.
	 */
	public function test_contract_aliases(): void {
		$aliases = leitvia_studio_contract()->core_aliases();
		$this->assertSame( 'surface', $aliases['color']['base'] );
		$this->assertSame( 'l', $aliases['spacing']['50'] );
		$this->assertSame( 'lead', $aliases['font-size']['large'] );
		$this->assertSame( 'mono', $aliases['font-family']['monospace'] );
		$this->assertSame( 'subtle', $aliases['shadow']['natural'] );
		$this->assertContains( 'media', leitvia_studio_contract()->preset_roles()['border-radius'] );
	}

	/**
	 * Slug attributes, style references, classes and inline variables move to the role; a block that names roles stays
	 * byte for byte.
	 */
	public function test_normalize_rewrites_aliases_to_roles(): void {
		$written = '<!-- wp:group {"backgroundColor":"tertiary","textColor":"contrast","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|80"}},"shadow":"var:preset|shadow|natural"},"layout":{"type":"constrained"}} -->'
			. '<div class="wp-block-group has-contrast-color has-tertiary-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--80);box-shadow:var(--wp--preset--shadow--natural)">'
			. '<!-- wp:heading {"fontSize":"x-large","fontFamily":"system-sans-serif"} --><h2 class="wp-block-heading has-system-sans-serif-font-family has-x-large-font-size">has-base-color stays in text</h2><!-- /wp:heading -->'
			. '</div><!-- /wp:group -->';
		$fixed   = Normalize::markup( $written );
		$group   = parse_blocks( $fixed )[0];
		$this->assertSame( 'surface-alt', $group['attrs']['backgroundColor'] );
		$this->assertSame( 'ink', $group['attrs']['textColor'] );
		$this->assertSame( 'var:preset|spacing|l', $group['attrs']['style']['spacing']['padding']['top'] );
		$this->assertSame( 'var:preset|spacing|xxxl', $group['attrs']['style']['spacing']['padding']['bottom'] );
		$this->assertSame( 'var:preset|shadow|subtle', $group['attrs']['style']['shadow'] );
		$this->assertStringContainsString( 'class="wp-block-group has-ink-color has-surface-alt-background-color has-text-color has-background"', $fixed );
		$this->assertStringContainsString( 'padding-top:var(--wp--preset--spacing--l);padding-bottom:var(--wp--preset--spacing--xxxl);box-shadow:var(--wp--preset--shadow--subtle)', $fixed );
		$heading = $group['innerBlocks'][0];
		$this->assertSame( 'heading-m', $heading['attrs']['fontSize'] );
		$this->assertSame( 'ui', $heading['attrs']['fontFamily'] );
		$this->assertStringContainsString( 'class="wp-block-heading has-ui-font-family has-heading-m-font-size">has-base-color stays in text</h2>', $fixed );
		$this->assertSame( array(), Token_Lint::lint_markup( $fixed ) );

		$roles = '<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} --><p class="has-ink-muted-color has-text-color has-meta-font-size">x</p><!-- /wp:paragraph -->';
		$this->assertSame( $roles, Normalize::markup( $roles ) );
		$this->assertSame( $written, Normalize::markup( $written, $written ), 'a stored block keeps its slugs' );
	}

	/**
	 * Aliases and roles pass the lint, unknown slugs fail it in attributes, references and CSS variables; custom sizes
	 * and digit slugs are left to the other rules.
	 */
	public function test_lint_rejects_unknown_presets(): void {
		$rules = static fn( string $markup ): array => array_column( Token_Lint::lint_markup( $markup ), 'rule' );
		$this->assertSame( array(), $rules( '<!-- wp:group {"textColor":"base","style":{"spacing":{"padding":"var:preset|spacing|50"}}} -->' ) );
		$this->assertSame( array(), $rules( '<!-- wp:heading {"style":{"typography":{"fontSize":"2rem"}}} -->' ) );
		$this->assertSame( array( 'no-unknown-preset', 'no-unknown-preset', 'no-unknown-preset' ), $rules( '<!-- wp:group {"backgroundColor":"white","style":{"spacing":{"margin":"var:preset|spacing|90"}}} --><div style="box-shadow:var(--wp--preset--shadow--sharp)"></div>' ) );
		$this->assertSame( array( 'no-digit-preset' ), $rules( '<p style="gap:var(--wp--preset--spacing--2xs)">' ) );
	}

	/**
	 * The finding of leitvia/content-tokens names the roles of the type.
	 */
	public function test_content_tokens_name_the_roles(): void {
		$found = Content_Checks::tokens( '<!-- wp:paragraph {"backgroundColor":"white"} --><p class="has-white-background-color has-background">x</p><!-- /wp:paragraph -->', array( 'post_type' => 'page' ) );
		$this->assertCount( 1, $found );
		$this->assertStringContainsString( 'preset "backgroundColor:white"', $found[0]['message'] );
		$this->assertStringContainsString( 'surface, surface-alt, ink, ink-muted', $found[0]['message'] );
		$this->assertSame( 'xxs, xs, s, m, l, xl, xxl, xxxl', Token_Lint::roles_for( 'var:preset|spacing|90' ) );
		$this->assertSame( '', Token_Lint::roles_for( 'no preset' ) );
	}
}
