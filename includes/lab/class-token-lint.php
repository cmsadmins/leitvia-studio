<?php
/**
 * Token lint for pattern markup.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Lab;

defined( 'ABSPATH' ) || exit;

/**
 * Finds raw values where design tokens belong, and preset slugs the theme does not have. The rules and expressions match lintMarkup() in
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
	 * A preset reference in block attributes or CSS, and the slug attributes of blocks, as PRESET in lintMarkup(). The
	 * attribute slug starts with a letter, so a custom size in style.typography ("fontSize":"2rem") is no preset.
	 */
	public const PRESET = '/var:preset\|(color|spacing|font-size|font-family|shadow|border-radius)\|([a-z0-9-]+)|--wp--preset--(color|spacing|font-size|font-family|shadow|border-radius)--([a-z0-9-]+)|"(textColor|backgroundColor|borderColor|fontSize|fontFamily)":"([a-z][a-z0-9-]*)"/';

	/**
	 * Slug attributes of blocks => preset type.
	 */
	private const ATTR_TYPES = array(
		'textColor'       => 'color',
		'backgroundColor' => 'color',
		'borderColor'     => 'color',
		'fontSize'        => 'font-size',
		'fontFamily'      => 'font-family',
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
		foreach ( self::unknown_presets( $content ) as $match ) {
			$findings[] = array(
				'rule'  => 'no-unknown-preset',
				'match' => $match,
			);
		}
		return $findings;
	}

	/**
	 * Rule no-unknown-preset: preset slugs that are neither a role of the contract of the active theme nor one of its
	 * coreAliases. The theme has no such preset, so the value renders nothing (ink text on an unknown background stays
	 * ink on ink). Slugs that mix digits and letters are left to no-digit-preset. Without a contract nothing is known
	 * and the rule stays silent.
	 *
	 * @param string $content Block markup.
	 * @return list<string> Matches.
	 */
	private static function unknown_presets( string $content ): array {
		$contract = leitvia_studio_contract();
		$known    = $contract->preset_roles();
		if ( ! $known ) {
			return array();
		}
		foreach ( $contract->core_aliases() as $type => $aliases ) {
			$known[ $type ] = array_merge( $known[ $type ] ?? array(), array_map( 'strval', array_keys( $aliases ) ) );
		}
		preg_match_all( self::PRESET, $content, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL );
		$found = array();
		foreach ( $matches as $m ) {
			[ $type, $slug ] = self::preset_of( $m );
			if ( ! in_array( $slug, $known[ $type ] ?? array(), true ) && ! preg_match( '/^\d+-?[a-z]/', $slug ) ) {
				$found[] = (string) $m[0];
			}
		}
		return $found;
	}

	/**
	 * Preset type and slug of a match of PRESET.
	 *
	 * @param array<int, string|null> $m Match groups.
	 * @return array{0: string, 1: string}
	 */
	private static function preset_of( array $m ): array {
		if ( isset( $m[1] ) ) {
			return array( $m[1], (string) $m[2] );
		}
		if ( isset( $m[3] ) ) {
			return array( $m[3], (string) $m[4] );
		}
		return array( self::ATTR_TYPES[ (string) $m[5] ], (string) $m[6] );
	}

	/**
	 * The roles that may stand in for an unknown preset: the role slugs of its type, comma separated.
	 *
	 * @param string $found Match of no-unknown-preset, such as var:preset|color|white.
	 */
	public static function roles_for( string $found ): string {
		if ( ! preg_match( self::PRESET, $found, $m, PREG_UNMATCHED_AS_NULL ) ) {
			return '';
		}
		$type = self::preset_of( $m )[0];
		return implode( ', ', leitvia_studio_contract()->preset_roles()[ $type ] ?? array() );
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
