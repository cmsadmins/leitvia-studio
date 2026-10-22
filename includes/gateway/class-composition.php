<?php
/**
 * Composition check of a page against the rhythm rules of the contract.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Lab\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Port of scripts/lib/composition.mjs: same rules, same order of findings, same score. The fixtures in
 * tests/fixtures/composition keep PHP and Node equal. A page is the list of pattern slugs of its sections; patterns
 * with role frame are skipped, slugs the contract does not list count as unknownPattern. Approved Lab patterns
 * (block:<ID>) join the contract as labPatterns, see lab(); one without composition is known but outside the rhythm.
 *
 * Check leitvia/composition (scope page) warns when an agent write leaves a page below the minimum score of the
 * gateway settings and below the score it had before; a warning turns "autonomous" into a change package.
 */
final class Composition {

	public const CHECK = 'leitvia/composition';

	/**
	 * Replacement candidates named per finding.
	 */
	private const MAX_SUGGESTIONS = 3;

	/**
	 * Registers the check. Runs on leitvia_studio_init.
	 *
	 * @param \LeitviaStudio\Extension\Studio $studio Studio.
	 */
	public static function register( \LeitviaStudio\Extension\Studio $studio ): void {
		$studio->registry( 'checks' )->register(
			self::CHECK,
			array(
				'label'    => __( 'Page composition score', 'leitvia-studio' ),
				'scope'    => 'page',
				'callback' => array( self::class, 'page' ),
			),
			'leitvia-studio'
		);
	}

	/**
	 * Findings of the rules, like checkComposition() in Node: rule, index (position in slugs) and message.
	 *
	 * @param string[]             $slugs    Pattern slugs in page order.
	 * @param array<string, mixed> $contract Contract data.
	 * @param string               $template Template slug, '' to skip the hero template rule.
	 * @return list<array{rule: string, index: int, message: string}>
	 */
	public static function check( array $slugs, array $contract, string $template = '' ): array {
		$rules   = (array) ( $contract['compositionRules'] ?? array() );
		$by_slug = array();
		foreach ( array_merge( (array) ( $contract['patterns'] ?? array() ), (array) ( $contract['studioPatterns'] ?? array() ), (array) ( $contract['addonPatterns'] ?? array() ), (array) ( $contract['labPatterns'] ?? array() ) ) as $pattern ) {
			$by_slug[ $pattern['slug'] ] = $pattern;
		}
		$issues = array();
		$add    = static function ( string $rule, int $index, string $message ) use ( &$issues ): void {
			$issues[] = array(
				'rule'    => $rule,
				'index'   => $index,
				'message' => $message,
			);
		};

		$sections = array();
		foreach ( array_values( $slugs ) as $index => $slug ) {
			$pattern = $by_slug[ $slug ] ?? null;
			if ( ! $pattern ) {
				/* translators: 1: section number, 2: pattern slug. */
				$add( 'unknownPattern', $index, sprintf( __( 'Section %1$d uses unknown pattern %2$s.', 'leitvia-studio' ), $index + 1, $slug ) );
			} elseif ( isset( $pattern['composition'] ) && 'frame' !== $pattern['composition']['role'] ) {
				$sections[] = $pattern + array(
					'index' => $index,
					'n'     => $index + 1,
				);
			}
		}

		$break_when = (array) ( $rules['breakWhen'] ?? array() );
		$is_break   = static function ( array $s ) use ( $break_when ): bool {
			foreach ( $break_when as $key => $values ) {
				if ( in_array( 'patterns' === $key ? $s['slug'] : ( $s['composition'][ $key ] ?? null ), (array) $values, true ) ) {
					return true;
				}
			}
			return false;
		};
		$hints      = array();
		foreach ( $break_when as $key => $values ) {
			$hints[] = ( 'patterns' === $key ? 'pattern' : $key ) . ' ' . self::or_list( (array) $values );
		}
		$break_hint     = implode( '; ', $hints );
		$hero_templates = array();
		foreach ( (array) ( $contract['pageFrames'] ?? array() ) as $frame ) {
			foreach ( $frame['slots'] as $slot ) {
				if ( 'intro' === $slot['name'] && in_array( 'hook', $slot['roles'], true ) ) {
					$hero_templates[] = $frame['template'];
				}
			}
		}
		$repeat = (array) ( $rules['noAdjacentRepeat'] ?? array() );

		$plain_run   = 0;
		$run_start   = 0;
		$density_run = 0;
		$last_accent = null;
		foreach ( $sections as $i => $s ) {
			$prev = $sections[ $i - 1 ] ?? null;
			$c    = $s['composition'];

			if ( '' !== $template && 'hero' === $s['area'] && ! in_array( $template, $hero_templates, true ) ) {
				/* translators: 1: section number, 2: template slug. */
				$add( 'heroTemplate', $s['index'], sprintf( __( 'Section %1$d is a hero, but template %2$s already provides the page title.', 'leitvia-studio' ), $s['n'], $template ) );
			}

			if ( ! empty( $rules['heroFirstOnly'] ) && 'hero' === $s['area'] && $i > 0 ) {
				/* translators: %d: section number. */
				$add( 'heroFirstOnly', $s['index'], sprintf( __( 'Section %d is a hero; only the first section may be a hero.', 'leitvia-studio' ), $s['n'] ) );
			}

			if ( $prev && $repeat && array_filter( $repeat, static fn( string $key ): bool => ( $prev['composition'][ $key ] ?? null ) !== ( $c[ $key ] ?? null ) ) === array() ) {
				$what = implode( '/', array_map( static fn( string $key ): string => (string) $c[ $key ], $repeat ) );
				/* translators: 1: section number, 2: next section number, 3: layout/tone. */
				$add( 'noAdjacentRepeat', $s['index'], sprintf( __( 'Sections %1$d and %2$d are both %3$s; give one of them another tone or layout.', 'leitvia-studio' ), $prev['n'], $s['n'], $what ) );
			}

			$plain_run = $is_break( $s ) ? 0 : $plain_run + 1;
			if ( 1 === $plain_run ) {
				$run_start = $s['n'];
			}
			if ( isset( $rules['breakWithin'] ) && $plain_run === (int) $rules['breakWithin'] + 1 ) {
				/* translators: 1: first section number, 2: last section number, 3: list of breaks. */
				$add( 'breakWithin', $s['index'], sprintf( __( 'Sections %1$d to %2$d have no break; add one of: %3$s.', 'leitvia-studio' ), $run_start, $s['n'], $break_hint ) );
			}

			if ( 'accent' === $c['tone'] ) {
				$gap = (int) ( $rules['accentMinGap'] ?? 0 );
				if ( $last_accent && $i - $last_accent['i'] - 1 < $gap ) {
					/* translators: 1: section number, 2: next accent section number, 3: minimum gap. */
					$add( 'accentMinGap', $s['index'], sprintf( __( 'Sections %1$d and %2$d are both accent; keep at least %3$d sections between them.', 'leitvia-studio' ), $last_accent['n'], $s['n'], $gap ) );
				}
				$last_accent = array(
					'i' => $i,
					'n' => $s['n'],
				);
			}

			$density_run = $prev && $prev['composition']['density'] === $c['density'] ? $density_run + 1 : 1;
			if ( isset( $rules['maxDensityRun'] ) && $density_run === (int) $rules['maxDensityRun'] + 1 ) {
				/* translators: 1: section number, 2: number of sections, 3: density. */
				$add( 'maxDensityRun', $s['index'], sprintf( __( 'Section %1$d makes %2$d %3$s sections in a row; alternate density.', 'leitvia-studio' ), $s['n'], $density_run, $c['density'] ) );
			}
		}

		$max = (int) ( $rules['maxSections'] ?? 0 );
		if ( $max > 0 && count( $sections ) > $max ) {
			/* translators: 1: number of sections, 2: maximum. */
			$add( 'maxSections', $sections[ $max ]['index'], sprintf( __( 'The page has %1$d sections; keep it at %2$d or fewer.', 'leitvia-studio' ), count( $sections ), $max ) );
		}

		return $issues;
	}

	/**
	 * Findings and score, like scoreComposition() in Node: 100 minus compositionRules.scoring per finding, at least 0.
	 *
	 * @param string[]             $slugs    Pattern slugs in page order.
	 * @param array<string, mixed> $contract Contract data.
	 * @param string               $template Template slug or ''.
	 * @return array{issues: list<array{rule: string, index: int, message: string}>, score: int}
	 */
	public static function score( array $slugs, array $contract, string $template = '' ): array {
		$issues  = self::check( $slugs, $contract, $template );
		$scoring = (array) ( $contract['compositionRules']['scoring'] ?? array() );
		$score   = 100;
		foreach ( $issues as $issue ) {
			$score -= (int) ( $scoring[ $issue['rule'] ] ?? 0 );
		}
		return array(
			'issues' => $issues,
			'score'  => max( 0, $score ),
		);
	}

	/**
	 * Score with concrete suggestions: per finding at a pattern section, up to three patterns of the same role, none
	 * deprecated, that remove the finding and raise the score when they replace that section.
	 *
	 * @param string[]             $slugs    Pattern slugs in page order.
	 * @param array<string, mixed> $contract Contract data.
	 * @param string               $template Template slug or ''.
	 * @return array{issues: list<array<string, mixed>>, score: int, suggestions: list<array<string, mixed>>}
	 */
	public static function report( array $slugs, array $contract, string $template = '' ): array {
		$slugs                 = array_values( $slugs );
		$result                = self::score( $slugs, $contract, $template );
		$patterns              = array_merge( (array) ( $contract['patterns'] ?? array() ), (array) ( $contract['studioPatterns'] ?? array() ), (array) ( $contract['addonPatterns'] ?? array() ) );
		$roles                 = array_combine( array_column( $patterns, 'slug' ), array_column( array_column( $patterns, 'composition' ), 'role' ) );
		$replaced              = array_filter( array_combine( array_column( $patterns, 'slug' ), array_map( static fn( array $pattern ): bool => ! empty( $pattern['docs']['replacedBy'] ), $patterns ) ) );
		$result['suggestions'] = array();
		foreach ( $result['issues'] as $issue ) {
			$role       = $roles[ $slugs[ $issue['index'] ] ] ?? '';
			$candidates = array();
			// ponytail: tries every pattern of the role, about 60 checks of a short list per finding; fine for pages.
			foreach ( $roles as $slug => $other ) {
				if ( '' === $role || $other !== $role || $slug === $slugs[ $issue['index'] ] || isset( $replaced[ $slug ] ) ) {
					continue;
				}
				$try                    = $slugs;
				$try[ $issue['index'] ] = $slug;
				$new                    = self::score( $try, $contract, $template );
				$same                   = array_filter( $new['issues'], static fn( array $found ): bool => $found['rule'] === $issue['rule'] && $found['index'] === $issue['index'] );
				if ( ! $same && $new['score'] > $result['score'] ) {
					$candidates[ $slug ] = $new['score'];
				}
			}
			arsort( $candidates );
			$replace = array_slice( array_keys( $candidates ), 0, self::MAX_SUGGESTIONS );
			$text    = $issue['message'];
			if ( $replace ) {
				/* translators: 1: finding, 2: section number, 3: pattern slugs. */
				$text = sprintf( __( '%1$s Replace section %2$d with %3$s.', 'leitvia-studio' ), $issue['message'], $issue['index'] + 1, self::or_list( $replace ) );
			} elseif ( 'unknownPattern' === $issue['rule'] ) {
				/* translators: %s: finding. */
				$text = sprintf( __( '%s Use a pattern from leitvia/list-patterns.', 'leitvia-studio' ), $issue['message'] );
			}
			$result['suggestions'][] = array(
				'rule'         => $issue['rule'],
				'index'        => $issue['index'],
				'replace_with' => $replace,
				'message'      => $text,
			);
		}
		return $result;
	}

	/**
	 * Contract data with labPatterns: the approved Lab patterns among the slugs, each with area and composition of the
	 * theme pattern it varies, itself or through the Lab pattern it is a variant of. Drafts, patterns in review and
	 * deprecated ones stay unknown.
	 *
	 * @param string[]             $slugs    Pattern slugs.
	 * @param array<string, mixed> $contract Contract data.
	 * @return array<string, mixed>
	 */
	public static function lab( array $slugs, array $contract ): array {
		$lab = array();
		foreach ( array_unique( $slugs ) as $slug ) {
			$id = preg_match( '/^block:([1-9]\d*)$/D', (string) $slug, $match ) ? (int) $match[1] : 0;
			if ( $id && 'wp_block' === get_post_type( $id ) && 'approved' === Status::effective( $slug ) ) {
				$lab[] = array( 'slug' => $slug ) + self::source( $id, $contract );
			}
		}
		$contract['labPatterns'] = $lab;
		return $contract;
	}

	/**
	 * Area and composition of the theme or Studio pattern a Lab pattern varies, itself or through the Lab pattern it is
	 * a variant of; empty for a Lab pattern of its own.
	 *
	 * @param int                  $id       Post ID of the Lab pattern.
	 * @param array<string, mixed> $contract Contract data.
	 * @return array<string, mixed>
	 */
	public static function source( int $id, array $contract ): array {
		$of   = (int) get_post_meta( $id, '_leitvia_lab_variant_of', true );
		$slug = (string) get_post_meta( $of ? $of : $id, '_leitvia_lab_source_pattern', true );
		foreach ( array_merge( (array) ( $contract['patterns'] ?? array() ), (array) ( $contract['studioPatterns'] ?? array() ), (array) ( $contract['addonPatterns'] ?? array() ) ) as $pattern ) {
			if ( '' !== $slug && ( $pattern['slug'] ?? null ) === $slug ) {
				return array_intersect_key( $pattern, array_flip( array( 'area', 'composition' ) ) );
			}
		}
		return array();
	}

	/**
	 * Section slugs of block markup: metadata.patternName of each root block (block:<ID> for a Lab pattern), the block
	 * name for a root block without one; template parts are frames and skipped. Content without any patternName is no
	 * composed page: empty list.
	 *
	 * @param string $content Block markup.
	 * @return list<string>
	 */
	public static function sections( string $content ): array {
		$slugs   = array();
		$pattern = false;
		foreach ( Block_Ids::blocks( parse_blocks( $content ) ) as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( 'core/template-part' === $name ) {
				continue;
			}
			$from    = (string) ( $block['attrs']['metadata']['patternName'] ?? '' );
			$pattern = $pattern || '' !== $from;
			$ref     = Status::of_name( $from );
			$slugs[] = '' !== $from ? ( str_starts_with( $ref, 'block:' ) ? $ref : $from ) : ( '' !== $name ? $name : 'core/freeform' );
		}
		return $pattern ? $slugs : array();
	}

	/**
	 * Check leitvia/composition: a warning when the page scores below the minimum score and, for a write to an existing
	 * post, below the score of the saved page. Pages without patterns and post types without a front end are skipped.
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $context post, post_type, ability, template.
	 * @return list<array<string, mixed>>
	 */
	public static function page( string $content, array $context ): array {
		$contract = leitvia_studio_contract();
		$slugs    = is_post_type_viewable( (string) ( $context['post_type'] ?? '' ) ) ? self::sections( $content ) : array();
		if ( ! $slugs || ! $contract->is_valid() ) {
			return array();
		}
		$post     = (int) ( $context['post'] ?? 0 );
		$template = (string) ( $context['template'] ?? ( $post ? get_page_template_slug( $post ) : '' ) );
		// A write is measured against the saved page too; the audit reports the saved page as it is.
		$compare = $post && 'leitvia/audit-site' !== ( $context['ability'] ?? '' );
		$saved   = $compare ? self::sections( (string) get_post_field( 'post_content', $post, 'raw' ) ) : array();
		$data    = self::lab( array_merge( $slugs, $saved ), $contract->data() );
		$report  = self::report( $slugs, $data, $template );
		$min     = (int) Gateway::settings()['min_composition_score'];
		$before  = $compare ? self::score( $saved, $data, $template )['score'] : 101;
		if ( $report['score'] >= $min || $report['score'] >= $before ) {
			return array();
		}
		return array(
			array(
				'check'       => self::CHECK,
				'severity'    => 'warning',
				'block'       => '',
				/* translators: 1: score, 2: minimum score. */
				'message'     => sprintf( __( 'The composition scores %1$d of 100, below the minimum of %2$d.', 'leitvia-studio' ), $report['score'], $min ),
				'fix'         => implode( ' ', array_column( $report['suggestions'], 'message' ) ),
				'score'       => $report['score'],
				'suggestions' => $report['suggestions'],
			),
		);
	}

	/**
	 * "a, b or c".
	 *
	 * @param string[] $values Values.
	 */
	private static function or_list( array $values ): string {
		$last = array_pop( $values );
		return $values ? implode( ', ', $values ) . ' or ' . $last : (string) $last;
	}
}
