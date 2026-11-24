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
 * Blocks without a pattern are running text, see prose(): paragraphs, lists, images, galleries, accordions or a group
 * of them. They are no section and no finding; the sections before and after them are no neighbors, and an image, a
 * gallery or a video among them is a break. Only a layout built by hand (LAYOUT_BLOCKS) and text above the hero of a
 * template without a title stay unknownPattern.
 *
 * A saved page also hands in what its blocks set themselves: the ground of a section, see tones(), instead of the tone
 * of its pattern, and the sections with the media on the other side, see mirrored(). A media section, see media(), is
 * a break, stands outside the density run and repeats no neighbor that is none: the video or the wide image is the
 * change of pace, whatever its padding, layout and ground.
 *
 * A pattern may name in continues the patterns it continues as one section (price cards after the row of their parts):
 * next to one of them it repeats nothing. With a frame (type of a page frame) the page also needs a section for every
 * kind of its requires (missingSection) and gets a finding without points for every kind of its recommends it lacks
 * (recommendedSection); a section fills the kinds of provides of its pattern and the kind that is its role. Both carry
 * the index count( $slugs ), the end of the page, and the kind. A saved page names its frame in FRAME_META.
 *
 * Check leitvia/composition (scope page) warns when an agent write leaves a page below the minimum score of the
 * gateway settings and below the score it had before; a warning turns "autonomous" into a change package.
 */
final class Composition {

	public const CHECK = 'leitvia/composition';

	/**
	 * Post meta with the type of the page frame of a page, set by compose-page and set-page-structure.
	 */
	public const FRAME_META = '_leitvia_page_frame';

	/**
	 * Findings about the sections a page frame needs or recommends; they name no section of the page.
	 */
	public const FRAME_RULES = array( 'missingSection', 'recommendedSection' );

	/**
	 * Replacement candidates named per finding.
	 */
	private const MAX_SUGGESTIONS = 3;

	/**
	 * Blocks without a pattern that fill the slots without roles of the frames contact and article (intro, toc) before
	 * the first section. Page frames on a template with a hero (hook in the intro slot) have no such slot.
	 */
	public const INTRO_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list', 'leitvia/table-of-contents' );

	/**
	 * Core blocks that lay out a section by hand. One of them without a pattern is no running text.
	 */
	public const LAYOUT_BLOCKS = array( 'core/columns', 'core/cover', 'core/media-text' );

	/**
	 * Blocks that break a run of plain sections when they stand in the running text.
	 */
	public const MEDIA_BLOCKS = array( 'core/image', 'core/gallery', 'core/video', 'core/embed' );

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
	 * @param array<int, string>   $tones    Tone per index in slugs from the blocks, see tones().
	 * @param int[]                $mirrored Indexes in slugs of the sections with the media on the other side.
	 * @param string               $frame    Type of a page frame whose required and recommended kinds count, '' for none.
	 * @return list<array{rule: string, index: int, message: string, kind?: string}>
	 */
	public static function check( array $slugs, array $contract, string $template = '', array $tones = array(), array $mirrored = array(), string $frame = '' ): array {
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

		$hero_templates = array();
		foreach ( (array) ( $contract['pageFrames'] ?? array() ) as $candidate ) {
			foreach ( $candidate['slots'] as $slot ) {
				if ( 'intro' === $slot['name'] && in_array( 'hook', $slot['roles'], true ) ) {
					$hero_templates[] = $candidate['template'];
				}
			}
		}
		$titled   = ! in_array( $template, $hero_templates, true );
		$lead     = true;
		$fresh    = false;
		$relief   = false;
		$sections = array();
		foreach ( array_values( $slugs ) as $index => $slug ) {
			$pattern = $by_slug[ $slug ] ?? null;
			$prose   = ! $pattern && self::prose( (string) $slug, $contract );
			$lead    = $lead && ! $pattern;
			if ( $prose && ( $titled || ! $lead ) ) {
				$fresh  = true;
				$relief = $relief || in_array( $slug, self::MEDIA_BLOCKS, true );
			} elseif ( $prose ) {
				/* translators: 1: section number, 2: block name, 3: template slug. */
				$add( 'unknownPattern', $index, sprintf( __( 'Section %1$d (%2$s) stands above the first section, but template %3$s starts with a hero; move it below the hero.', 'leitvia-studio' ), $index + 1, $slug, $template ) );
			} elseif ( ! $pattern && in_array( $slug, self::LAYOUT_BLOCKS, true ) ) {
				/* translators: 1: section number, 2: block name. */
				$add( 'unknownPattern', $index, sprintf( __( 'Section %1$d is a layout built by hand with %2$s; replace it with a pattern from leitvia/list-patterns. Text, lists, images, galleries and accordions between sections need no pattern.', 'leitvia-studio' ), $index + 1, $slug ) );
			} elseif ( ! $pattern ) {
				/* translators: 1: section number, 2: pattern slug. */
				$add( 'unknownPattern', $index, sprintf( __( 'Section %1$d uses unknown pattern %2$s; use a pattern from leitvia/list-patterns.', 'leitvia-studio' ), $index + 1, $slug ) );
			} elseif ( isset( $pattern['composition'] ) && 'frame' !== $pattern['composition']['role'] ) {
				if ( isset( $tones[ $index ] ) && 'image' !== ( $pattern['composition']['tone'] ?? '' ) ) {
					$pattern['composition']['tone'] = $tones[ $index ];
				}
				if ( in_array( $index, $mirrored, true ) ) {
					$pattern['composition']['layout'] = ( $pattern['composition']['layout'] ?? '' ) . '-mirrored';
				}
				$sections[] = $pattern + array(
					'index'  => $index,
					'n'      => $index + 1,
					'fresh'  => $fresh,
					'relief' => $relief,
					'media'  => self::media( (string) $slug, $contract ),
				);
				$fresh      = false;
				$relief     = false;
			}
		}

		$break_when = (array) ( $rules['breakWhen'] ?? array() );
		$is_break   = static function ( array $s ) use ( $break_when ): bool {
			if ( $s['media'] ) {
				return true;
			}
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
		$hints[]    = 'a media section (video, gallery or wide image)';
		$break_hint = implode( '; ', $hints );
		$repeat     = (array) ( $rules['noAdjacentRepeat'] ?? array() );

		$plain_run   = 0;
		$run_start   = 0;
		$density_run = 0;
		$last_accent = null;
		foreach ( $sections as $i => $s ) {
			$prev = $s['fresh'] ? null : ( $sections[ $i - 1 ] ?? null );
			$c    = $s['composition'];

			if ( '' !== $template && 'hero' === $s['area'] && ! in_array( $template, $hero_templates, true ) ) {
				/* translators: 1: section number, 2: template slug. */
				$add( 'heroTemplate', $s['index'], sprintf( __( 'Section %1$d is a hero, but template %2$s already provides the page title.', 'leitvia-studio' ), $s['n'], $template ) );
			}

			if ( ! empty( $rules['heroFirstOnly'] ) && 'hero' === $s['area'] && $i > 0 ) {
				/* translators: %d: section number. */
				$add( 'heroFirstOnly', $s['index'], sprintf( __( 'Section %d is a hero; only the first section may be a hero.', 'leitvia-studio' ), $s['n'] ) );
			}

			$continued = $prev && in_array( $prev['slug'], (array) ( $s['continues'] ?? array() ), true );
			if ( $prev && ! $continued && $prev['media'] === $s['media'] && $repeat && array_filter( $repeat, static fn( string $key ): bool => ( $prev['composition'][ $key ] ?? null ) !== ( $c[ $key ] ?? null ) ) === array() ) {
				$what = implode( '/', array_map( static fn( string $key ): string => (string) $c[ $key ], $repeat ) );
				/* translators: 1: section number, 2: next section number, 3: layout/tone. */
				$add( 'noAdjacentRepeat', $s['index'], sprintf( __( 'Sections %1$d and %2$d are both %3$s; give one of them another ground (backgroundColor surface or surface-alt on its outer block, or the block style Inverse), put its media on the other side (block style Media right), or move another section between them.', 'leitvia-studio' ), $prev['n'], $s['n'], $what ) );
			}

			$plain_run = $is_break( $s ) ? 0 : ( $s['relief'] ? 1 : $plain_run + 1 );
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

			if ( $s['media'] ) {
				$density_run = 0;
				continue;
			}
			$density_run = $prev && ! $prev['media'] && $prev['composition']['density'] === $c['density'] ? $density_run + 1 : 1;
			if ( isset( $rules['maxDensityRun'] ) && $density_run === (int) $rules['maxDensityRun'] + 1 ) {
				/* translators: 1: section number, 2: number of sections, 3: density. */
				$add( 'maxDensityRun', $s['index'], sprintf( __( 'Section %1$d makes %2$d %3$s sections in a row; move a section of another density or a media section (video, gallery or wide image) between them.', 'leitvia-studio' ), $s['n'], $density_run, $c['density'] ) );
			}
		}

		$max = (int) ( $rules['maxSections'] ?? 0 );
		if ( $max > 0 && count( $sections ) > $max ) {
			/* translators: 1: number of sections, 2: maximum. */
			$add( 'maxSections', $sections[ $max ]['index'], sprintf( __( 'The page has %1$d sections; keep it at %2$d or fewer.', 'leitvia-studio' ), count( $sections ), $max ) );
		}

		$page_frame = self::frame( $frame, $contract );
		$filled     = array();
		foreach ( $sections as $s ) {
			$filled = array_merge( $filled, array_filter( (array) ( $s['provides'] ?? array() ), 'is_string' ), array( (string) ( $s['composition']['role'] ?? '' ) ) );
		}
		foreach ( array_combine( self::FRAME_RULES, array( 'requires', 'recommends' ) ) as $rule => $key ) {
			foreach ( array_values( array_diff( (array) ( $page_frame[ $key ] ?? array() ), $filled ) ) as $kind ) {
				$issues[] = array(
					'rule'    => $rule,
					'index'   => count( $slugs ),
					'message' => 'missingSection' === $rule
						/* translators: 1: page frame type, 2: section kind. */
						? sprintf( __( 'Page frame %1$s needs a section of kind %2$s; add a pattern that provides it (leitvia/list-patterns with provides %2$s).', 'leitvia-studio' ), $frame, $kind )
						/* translators: 1: page frame type, 2: section kind. */
						: sprintf( __( 'Page frame %1$s recommends a section of kind %2$s; add a pattern that provides it (leitvia/list-patterns with provides %2$s).', 'leitvia-studio' ), $frame, $kind ),
					'kind'    => (string) $kind,
				);
			}
		}

		return $issues;
	}

	/**
	 * The page frame of a type, null for '' or a type the contract does not list.
	 *
	 * @param string               $type     Page frame type.
	 * @param array<string, mixed> $contract Contract data.
	 * @return array<string, mixed>|null
	 */
	public static function frame( string $type, array $contract ): ?array {
		foreach ( '' === $type ? array() : (array) ( $contract['pageFrames'] ?? array() ) as $frame ) {
			if ( ( $frame['type'] ?? null ) === $type ) {
				return $frame;
			}
		}
		return null;
	}

	/**
	 * Findings and score, like scoreComposition() in Node: 100 minus compositionRules.scoring per finding, at least 0.
	 *
	 * @param string[]             $slugs    Pattern slugs in page order.
	 * @param array<string, mixed> $contract Contract data.
	 * @param string               $template Template slug or ''.
	 * @param array<int, string>   $tones    Tone per index in slugs, see tones().
	 * @param int[]                $mirrored Indexes in slugs of the sections with the media on the other side.
	 * @param string               $frame    Type of a page frame, '' for none, see check().
	 * @return array{issues: list<array{rule: string, index: int, message: string, kind?: string}>, score: int}
	 */
	public static function score( array $slugs, array $contract, string $template = '', array $tones = array(), array $mirrored = array(), string $frame = '' ): array {
		$issues  = self::check( $slugs, $contract, $template, $tones, $mirrored, $frame );
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
	 * deprecated, that remove the finding and raise the score when they replace that section; per kind a page frame
	 * needs or recommends, up to three patterns that provide it, to add (add).
	 *
	 * @param string[]             $slugs    Pattern slugs in page order.
	 * @param array<string, mixed> $contract Contract data.
	 * @param string               $template Template slug or ''.
	 * @param array<int, string>   $tones    Tone per index in slugs, see tones(); a replaced section loses its own.
	 * @param int[]                $mirrored Indexes of the mirrored sections; a replaced section is not mirrored.
	 * @param string               $frame    Type of a page frame, '' for none, see check().
	 * @return array{issues: list<array<string, mixed>>, score: int, suggestions: list<array<string, mixed>>}
	 */
	public static function report( array $slugs, array $contract, string $template = '', array $tones = array(), array $mirrored = array(), string $frame = '' ): array {
		$slugs                 = array_values( $slugs );
		$result                = self::score( $slugs, $contract, $template, $tones, $mirrored, $frame );
		$patterns              = array_merge( (array) ( $contract['patterns'] ?? array() ), (array) ( $contract['studioPatterns'] ?? array() ), (array) ( $contract['addonPatterns'] ?? array() ) );
		$roles                 = array_combine( array_column( $patterns, 'slug' ), array_column( array_column( $patterns, 'composition' ), 'role' ) );
		$replaced              = array_filter( array_combine( array_column( $patterns, 'slug' ), array_map( static fn( array $pattern ): bool => ! empty( $pattern['docs']['replacedBy'] ), $patterns ) ) );
		$result['suggestions'] = array();
		foreach ( $result['issues'] as $issue ) {
			if ( isset( $issue['kind'] ) ) {
				$kind                    = $issue['kind'];
				$add                     = array_slice( array_column( array_filter( $patterns, static fn( array $pattern ): bool => empty( $pattern['docs']['replacedBy'] ) && ( in_array( $kind, (array) ( $pattern['provides'] ?? array() ), true ) || ( $pattern['composition']['role'] ?? '' ) === $kind ) ), 'slug' ), 0, self::MAX_SUGGESTIONS );
				$result['suggestions'][] = array(
					'rule'         => $issue['rule'],
					'index'        => $issue['index'],
					'replace_with' => array(),
					'add'          => $add,
					/* translators: 1: finding, 2: pattern slugs. */
					'message'      => $add ? sprintf( __( '%1$s For example %2$s.', 'leitvia-studio' ), $issue['message'], self::or_list( $add ) ) : $issue['message'],
				);
				continue;
			}
			$role       = $roles[ $slugs[ $issue['index'] ] ] ?? '';
			$candidates = array();
			// ponytail: tries every pattern of the role, about 60 checks of a short list per finding; fine for pages.
			foreach ( $roles as $slug => $other ) {
				if ( '' === $role || $other !== $role || $slug === $slugs[ $issue['index'] ] || isset( $replaced[ $slug ] ) ) {
					continue;
				}
				$try                    = $slugs;
				$try[ $issue['index'] ] = $slug;
				$new                    = self::score( $try, $contract, $template, array_diff_key( $tones, array( $issue['index'] => true ) ), array_diff( $mirrored, array( $issue['index'] ) ), $frame );
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
	 * name for a root block without one, or the name of the first block of LAYOUT_BLOCKS in it: a group around columns
	 * is a layout built by hand, a group around text is text. Template parts are frames and skipped. Content without
	 * any patternName is no composed page: empty list.
	 *
	 * @param string $content Block markup.
	 * @return list<string>
	 */
	public static function sections( string $content ): array {
		$slugs   = array();
		$pattern = false;
		foreach ( self::roots( $content ) as $block ) {
			$name    = (string) ( $block['blockName'] ?? '' );
			$from    = (string) ( $block['attrs']['metadata']['patternName'] ?? '' );
			$pattern = $pattern || '' !== $from;
			$ref     = Status::of_name( $from );
			$layout  = '' === $from ? self::find( array( $block ), static fn( array $inner ): bool => in_array( $inner['blockName'] ?? '', self::LAYOUT_BLOCKS, true ) ) : null;
			$name    = $layout ? (string) $layout['blockName'] : $name;
			$slugs[] = '' !== $from ? ( str_starts_with( $ref, 'block:' ) ? $ref : $from ) : ( '' !== $name ? $name : 'core/freeform' );
		}
		return $pattern ? $slugs : array();
	}

	/**
	 * Tones the root blocks of block markup set themselves, by index as in sections(): ink for the style Inverse or an
	 * ink ground, otherwise the ground as it is set, a palette slug such as accent, surface or surface-alt (also one of
	 * a kit), a custom color or a gradient. A stage pattern switched to Inverse is a dark band, not the light tone of
	 * its pattern, and a section moved from surface to surface-alt no longer repeats its neighbor.
	 *
	 * @param string $content Block markup.
	 * @return array<int, string>
	 */
	public static function tones( string $content ): array {
		$tones = array();
		foreach ( self::roots( $content ) as $index => $block ) {
			$attrs = (array) ( $block['attrs'] ?? array() );
			$bg    = $attrs['backgroundColor'] ?? $attrs['style']['color']['background'] ?? $attrs['gradient'] ?? $attrs['style']['color']['gradient'] ?? null;
			if ( 'ink' === $bg || self::has_class( $block, 'is-style-inverse' ) ) {
				$tones[ $index ] = 'ink';
			} elseif ( is_string( $bg ) && '' !== $bg ) {
				$tones[ $index ] = $bg;
			}
		}
		return $tones;
	}

	/**
	 * Indexes, as in sections(), of the sections whose media stands on the other side: a block with the style Media
	 * right or a Media & Text block with the media on the right, anywhere in the section. Such a section does not
	 * repeat the layout of a neighbor with the media on the left.
	 *
	 * @param string $content Block markup.
	 * @return list<int>
	 */
	public static function mirrored( string $content ): array {
		$test = static fn( array $block ): bool => self::has_class( $block, 'is-style-media-right' ) || ( 'core/media-text' === ( $block['blockName'] ?? '' ) && 'right' === ( $block['attrs']['mediaPosition'] ?? '' ) );
		return array_keys( array_filter( self::roots( $content ), static fn( array $block ): bool => null !== self::find( array( $block ), $test ) ) );
	}

	/**
	 * Block IDs of the sections by index as in sections(), '' for a block without one.
	 *
	 * @param string $content Block markup.
	 * @return list<string>
	 */
	public static function ids( string $content ): array {
		return array_map( array( Block_Ids::class, 'id' ), self::roots( $content ) );
	}

	/**
	 * Whether a slug that is no pattern is running text: a core block outside LAYOUT_BLOCKS, a block of INTRO_BLOCKS
	 * or another registered block type (contract data may name them as proseBlocks, as the fixtures do).
	 *
	 * @param string               $slug     Slug of a section.
	 * @param array<string, mixed> $contract Contract data.
	 */
	private static function prose( string $slug, array $contract ): bool {
		if ( in_array( $slug, self::LAYOUT_BLOCKS, true ) ) {
			return false;
		}
		if ( str_starts_with( $slug, 'core/' ) || in_array( $slug, self::INTRO_BLOCKS, true ) ) {
			return true;
		}
		return isset( $contract['proseBlocks'] ) ? in_array( $slug, (array) $contract['proseBlocks'], true ) : \WP_Block_Type_Registry::get_instance()->is_registered( $slug );
	}

	/**
	 * Whether a pattern is a media section: its markup holds a video or an embed, or an image, a gallery or a cover
	 * aligned wide or full. Read from the registered pattern, so a new pattern counts without a list of names
	 * (contract data may name them as mediaPatterns, as the fixtures do).
	 * ponytail: judged by the markup of the pattern, not of the page; read the section of the page if edits ever
	 * remove or add the media of a section.
	 *
	 * @param string               $slug     Pattern slug, block:<ID> for a Lab pattern.
	 * @param array<string, mixed> $contract Contract data.
	 */
	private static function media( string $slug, array $contract ): bool {
		static $cache = array();
		if ( isset( $contract['mediaPatterns'] ) ) {
			return in_array( $slug, (array) $contract['mediaPatterns'], true );
		}
		if ( ! isset( $cache[ $slug ] ) ) {
			$pattern        = \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
			$content        = str_starts_with( $slug, 'block:' ) ? (string) get_post_field( 'post_content', (int) substr( $slug, 6 ), 'raw' ) : (string) ( is_array( $pattern ) ? ( $pattern['content'] ?? '' ) : '' );
			$cache[ $slug ] = null !== self::find(
				parse_blocks( $content ),
				static fn( array $block ): bool => 1 === preg_match( '#/(video|embed)$#D', (string) ( $block['blockName'] ?? '' ) )
					|| ( in_array( $block['blockName'] ?? '', array( 'core/image', 'core/gallery', 'core/cover' ), true ) && in_array( $block['attrs']['align'] ?? '', array( 'wide', 'full' ), true ) )
			);
		}
		return $cache[ $slug ];
	}

	/**
	 * First block, inner blocks included, that passes the test.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @param callable                                $test   Gets a block, returns bool.
	 * @return array<string, mixed>|null
	 */
	private static function find( array $blocks, callable $test ): ?array {
		foreach ( $blocks as $block ) {
			$found = $test( $block ) ? $block : self::find( (array) ( $block['innerBlocks'] ?? array() ), $test );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * Whether className of a block holds the class.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param string               $name  Class name.
	 */
	private static function has_class( array $block, string $name ): bool {
		return in_array( $name, explode( ' ', (string) ( $block['attrs']['className'] ?? '' ) ), true );
	}

	/**
	 * Root blocks of block markup without template parts: the sections of sections() and tones().
	 *
	 * @param string $content Block markup.
	 * @return list<array<string, mixed>>
	 */
	private static function roots( string $content ): array {
		return array_values( array_filter( Block_Ids::blocks( parse_blocks( $content ) ), static fn( array $block ): bool => 'core/template-part' !== ( $block['blockName'] ?? '' ) ) );
	}

	/**
	 * Check leitvia/composition: a warning when the page scores below the minimum score and, for a write to an existing
	 * post, below the score of the saved page. Pages without patterns and post types without a front end are skipped.
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $context post, post_type, ability, template, frame (else the frame of the saved page).
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
		$frame    = (string) ( $context['frame'] ?? ( $post ? get_post_meta( $post, self::FRAME_META, true ) : '' ) );
		// A write is measured against the saved page too; the audit reports the saved page as it is.
		$compare = $post && 'leitvia/audit-site' !== ( $context['ability'] ?? '' );
		$saved   = $compare ? self::sections( (string) get_post_field( 'post_content', $post, 'raw' ) ) : array();
		$data    = self::lab( array_merge( $slugs, $saved ), $contract->data() );
		$report  = self::report( $slugs, $data, $template, self::tones( $content ), self::mirrored( $content ), $frame );
		$min     = (int) Gateway::settings()['min_composition_score'];
		$raw     = $compare ? (string) get_post_field( 'post_content', $post, 'raw' ) : '';
		$before  = $compare ? self::score( $saved, $data, $template, self::tones( $raw ), self::mirrored( $raw ), $frame )['score'] : 101;
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
