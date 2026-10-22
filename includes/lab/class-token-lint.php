<?php
/**
 * Token lint for pattern markup.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Lab;

defined( 'ABSPATH' ) || exit;

/**
 * Finds raw values where design tokens belong. The rules and expressions match lintMarkup() in
 * scripts/lib/lint-rules.mjs; tests/fixtures/token-lint-cases.json keeps both in step.
 */
final class Token_Lint {

	/**
	 * Findings kept per pattern; the count stays complete.
	 */
	public const MAX_FINDINGS = 200;

	/**
	 * Rule => PCRE, in the order of lintMarkup(). No u flag: \b, \d and \w stay ASCII as in JavaScript.
	 */
	public const RULES = array(
		'no-hex'          => '/(?<!&)(?<!href=["\'])#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})\b(?![\w-])/i',
		'no-px'           => '/\b\d+(?:\.\d+)?px\b/',
		'no-important'    => '/!\s*important/i',
		'no-digit-preset' => '/--wp--preset--[a-z-]+--\d+-?[a-z]/i',
	);

	/**
	 * Lints block markup.
	 *
	 * @param string $content Block markup.
	 * @return list<array{rule: string, match: string}>
	 */
	public static function lint_markup( string $content ): array {
		$findings = array();
		foreach ( self::RULES as $rule => $regex ) {
			preg_match_all( $regex, $content, $matches );
			foreach ( $matches[0] as $match ) {
				$findings[] = array(
					'rule'  => $rule,
					'match' => $match,
				);
			}
		}
		return $findings;
	}

	/**
	 * Lint result as stored in _leitvia_lab_checks.tokens: the full count and at most MAX_FINDINGS findings.
	 *
	 * @param string $content Block markup.
	 * @return array{count: int, findings: list<array{rule: string, match: string}>}
	 */
	public static function result( string $content ): array {
		$findings = self::lint_markup( $content );
		return array(
			'count'    => count( $findings ),
			'findings' => array_slice( $findings, 0, self::MAX_FINDINGS ),
		);
	}

	/**
	 * Registers the check leitvia/tokens. Runs on leitvia_studio_init.
	 *
	 * @param \LeitviaStudio\Extension\Studio $studio Studio.
	 */
	public static function register_check( \LeitviaStudio\Extension\Studio $studio ): void {
		$studio->registry( 'checks' )->register(
			'leitvia/tokens',
			array(
				'label'    => __( 'Design tokens', 'leitvia-studio' ),
				'scope'    => 'pattern',
				'callback' => array( self::class, 'lint_markup' ),
			),
			'leitvia-studio'
		);
	}

	/**
	 * Stores the lint result in _leitvia_lab_checks.tokens. Runs on save_post_wp_block; writes meta only, so
	 * save_post does not fire again.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function store( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$checks               = get_post_meta( $post_id, '_leitvia_lab_checks', true );
		$checks               = is_array( $checks ) ? $checks : array();
		$checks['tokens']     = self::result( $post->post_content );
		$checks['checked_at'] = gmdate( 'c' );
		update_post_meta( $post_id, '_leitvia_lab_checks', wp_slash( $checks ) );
	}
}
