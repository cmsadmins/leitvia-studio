<?php
/**
 * Read ability that plans a page.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway;
use LeitviaStudio\Lab\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Ability leitvia/plan-page: page frame, pattern order and text lengths for a goal and content points. Without AI the
 * plan comes from pageFrames, examples and the composition rules of the contract and is the same for the same input.
 * With ai: true the core AI client refines it; the prompt holds the goal, the points and pattern names with their
 * contract metadata, nothing from the database. The plan is a proposal, nothing is written.
 */
final class Read_Plan {

	public const NAME = 'leitvia/plan-page';

	public const MAX_POINTS = 20;

	/**
	 * Registers the ability. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$frames = array_column( (array) ( leitvia_studio_contract()->data()['pageFrames'] ?? array() ), 'type' );
		$text   = array(
			'type'      => 'string',
			'maxLength' => 300,
		);
		Gateway\Abilities::read(
			self::NAME,
			__( 'Plan page', 'leitvia-studio' ),
			__( 'Proposes a page for a goal and content points: page frame, template, the patterns in page order with role, slot, text lengths and the points each section carries, and the composition score. Without AI the plan follows the page frames, examples and composition rules of the contract and is the same for the same input. With ai set to true the AI connector the site owner set up in WordPress refines it; the goal, the points and pattern names with their metadata are sent to that provider. Writes nothing; build the page with leitvia/compose-page.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'goal'   => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 500,
					),
					'points' => array(
						'type'     => 'array',
						'items'    => $text,
						'maxItems' => self::MAX_POINTS,
					),
					'frame'  => array(
						'type'        => 'string',
						'enum'        => $frames,
						'description' => __( 'Type of a page frame; without it the frame named in the goal, else home.', 'leitvia-studio' ),
					),
					'ai'     => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Refine the plan through the AI connector of the site. Sends the goal, the points and pattern names to that provider.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'goal' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'frame'    => array( 'type' => 'string' ),
					'template' => array( 'type' => 'string' ),
					'sections' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'score'    => array( 'type' => 'integer' ),
					'issues'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'source'   => array(
						'type' => 'string',
						'enum' => array( 'rules', 'ai' ),
					),
					'dropped'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			array( self::class, 'plan' )
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function plan( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$goal   = sanitize_text_field( (string) ( $input['goal'] ?? '' ) );
		$points = array_values( array_filter( array_map( static fn( $p ): string => sanitize_text_field( (string) $p ), array_slice( (array) ( $input['points'] ?? array() ), 0, self::MAX_POINTS ) ) ) );
		$frame  = self::frame( (string) ( $input['frame'] ?? '' ), $goal );
		if ( ! $frame ) {
			return Gateway\Rejection::make( 'leitvia_invalid_input', __( 'The contract has no page frame of this type.', 'leitvia-studio' ), 400, 'input', array(), 'leitvia/get-contract', 'once' );
		}
		$plan = self::rules( $frame, $points );
		if ( ! filter_var( $input['ai'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			return $plan;
		}
		return self::refine( $plan, $frame, $goal, $points );
	}

	/**
	 * The frame of the given type, else the first frame whose type the goal names, else the first frame.
	 *
	 * @param string $type Requested type or ''.
	 * @param string $goal Goal.
	 * @return array<string, mixed>|null
	 */
	private static function frame( string $type, string $goal ): ?array {
		$frames = array_values( (array) ( leitvia_studio_contract()->data()['pageFrames'] ?? array() ) );
		foreach ( $frames as $frame ) {
			if ( '' === $type ? (bool) preg_match( '/\b' . preg_quote( (string) $frame['type'], '/' ) . '/i', $goal ) : $frame['type'] === $type ) {
				return $frame;
			}
		}
		return '' === $type ? ( $frames[0] ?? null ) : null;
	}

	/**
	 * Registered, approved patterns of the contract by slug; deprecated ones and those with deprecated parts are left out.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function patterns(): array {
		$data     = leitvia_studio_contract()->data();
		$registry = \WP_Block_Patterns_Registry::get_instance();
		$usable   = array();
		foreach ( array_merge( (array) ( $data['patterns'] ?? array() ), (array) ( $data['studioPatterns'] ?? array() ), (array) ( $data['addonPatterns'] ?? array() ) ) as $pattern ) {
			$slug = (string) ( $pattern['slug'] ?? '' );
			if ( '' !== $slug && $registry->is_registered( $slug ) && 'approved' === Status::effective( 'pattern:' . $slug ) ) {
				$usable[ $slug ] = $pattern;
			}
		}
		return $usable;
	}

	/**
	 * Deterministic plan: the example of the frame when all its patterns are usable, else per slot and role the usable
	 * pattern that scores best after the sections before it. The content slot repeats its roles until every point has
	 * a section, within maxSections.
	 *
	 * @param array<string, mixed> $frame  Page frame.
	 * @param string[]             $points Content points.
	 * @return array<string, mixed>
	 */
	private static function rules( array $frame, array $points ): array {
		$contract = leitvia_studio_contract()->data();
		$patterns = self::patterns();
		$slugs    = array();
		foreach ( (array) ( $contract['examples'] ?? array() ) as $example ) {
			if ( ( $example['frame'] ?? '' ) === $frame['type'] && ! array_diff( (array) $example['sections'], array_keys( $patterns ) ) ) {
				$slugs = array_values( (array) $example['sections'] );
				break;
			}
		}
		if ( ! $slugs ) {
			$max = (int) ( $contract['compositionRules']['maxSections'] ?? 12 );
			foreach ( $frame['slots'] as $slot ) {
				$roles = array_values( (array) $slot['roles'] );
				$count = 'content' === $slot['name'] && $roles ? max( count( $roles ), count( $points ) ) : count( $roles );
				$count = min( $count, $max - count( $slugs ) );
				for ( $i = 0; $i < $count; $i++ ) {
					$best = self::best( $slugs, $roles[ $i % count( $roles ) ], $patterns, $contract, (string) $frame['template'] );
					if ( null !== $best ) {
						$slugs[] = $best;
					}
				}
			}
		}
		return self::result( $frame, $slugs, $points, 'rules' );
	}

	/**
	 * Unused pattern of the role with the best score after the given sections; the first in contract order on a tie.
	 *
	 * @param string[]                            $slugs    Sections so far.
	 * @param string                              $role     Composition role.
	 * @param array<string, array<string, mixed>> $patterns Usable patterns.
	 * @param array<string, mixed>                $contract Contract data.
	 * @param string                              $template Template slug.
	 */
	private static function best( array $slugs, string $role, array $patterns, array $contract, string $template ): ?string {
		$best  = null;
		$score = -1;
		foreach ( $patterns as $slug => $pattern ) {
			if ( ( $pattern['composition']['role'] ?? '' ) !== $role || in_array( $slug, $slugs, true ) ) {
				continue;
			}
			$try = Gateway\Composition::score( array_merge( $slugs, array( $slug ) ), $contract, $template )['score'];
			if ( $try > $score ) {
				$best  = $slug;
				$score = $try;
			}
		}
		return $best;
	}

	/**
	 * The plan for a list of sections: role, slot, text lengths and points per section, score and findings. Points
	 * without a section of their own go round the sections of the content slot, or of the whole page without one.
	 *
	 * @param array<string, mixed>     $frame    Page frame.
	 * @param string[]                 $slugs    Pattern slugs in page order.
	 * @param string[]                 $points   Content points.
	 * @param string                   $source   rules or ai.
	 * @param array<int, list<string>> $assigned Points per section index from the AI.
	 * @param string[]                 $dropped  Patterns the AI named that are not usable.
	 * @return array<string, mixed>
	 */
	private static function result( array $frame, array $slugs, array $points, string $source, array $assigned = array(), array $dropped = array() ): array {
		$contract = leitvia_studio_contract()->data();
		$patterns = self::patterns();
		$sections = array();
		foreach ( $slugs as $index => $slug ) {
			$role = (string) ( $patterns[ $slug ]['composition']['role'] ?? '' );
			$slot = '';
			foreach ( $frame['slots'] as $candidate ) {
				if ( in_array( $role, (array) $candidate['roles'], true ) ) {
					$slot = (string) $candidate['name'];
					break;
				}
			}
			$sections[] = array(
				'pattern' => $slug,
				'title'   => (string) ( $patterns[ $slug ]['title'] ?? '' ),
				'role'    => $role,
				'slot'    => $slot,
				'length'  => (array) ( $patterns[ $slug ]['docs']['contentLength'] ?? array() ),
				'points'  => $assigned[ $index ] ?? array(),
			);
		}
		if ( ! $assigned && $sections && $points ) {
			$targets = array_keys( array_filter( $sections, static fn( array $s ): bool => 'content' === $s['slot'] ) );
			$targets = $targets ? $targets : array_keys( $sections );
			foreach ( $points as $i => $point ) {
				$sections[ $targets[ $i % count( $targets ) ] ]['points'][] = $point;
			}
		}
		$score = Gateway\Composition::score( $slugs, $contract, (string) $frame['template'] );
		return array(
			'frame'    => (string) $frame['type'],
			'template' => (string) $frame['template'],
			'sections' => $sections,
			'score'    => $score['score'],
			'issues'   => $score['issues'],
			'source'   => $source,
			'dropped'  => $dropped,
		);
	}

	/**
	 * Refines the plan through the core AI client. Patterns the model names that are not usable fall out; an answer
	 * without any usable pattern is a rejection. Every rejection carries the plan without AI.
	 *
	 * @param array<string, mixed> $plan   Plan without AI.
	 * @param array<string, mixed> $frame  Page frame.
	 * @param string               $goal   Goal.
	 * @param string[]             $points Content points.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function refine( array $plan, array $frame, string $goal, array $points ) {
		$patterns = self::patterns();
		$rules    = (array) ( leitvia_studio_contract()->data()['compositionRules'] ?? array() );
		$catalog  = array();
		foreach ( $patterns as $slug => $pattern ) {
			if ( ( $pattern['composition']['role'] ?? 'frame' ) !== 'frame' ) {
				$catalog[] = array(
					'slug'        => $slug,
					'title'       => (string) ( $pattern['title'] ?? '' ),
					'area'        => (string) ( $pattern['area'] ?? '' ),
					'composition' => (array) ( $pattern['composition'] ?? array() ),
					'useWhen'     => (string) ( $pattern['useWhen'] ?? '' ),
				);
			}
		}
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'sections' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 30,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'pattern' => array( 'type' => 'string' ),
							'points'  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
						'required'             => array( 'pattern', 'points' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'sections' ),
			'additionalProperties' => false,
		);
		$prompt = (string) wp_json_encode(
			array(
				'goal'             => $goal,
				'points'           => $points,
				'frame'            => array_intersect_key( $frame, array_flip( array( 'type', 'purpose', 'slots' ) ) ),
				'compositionRules' => array_intersect_key( $rules, array_flip( array( 'noAdjacentRepeat', 'breakWithin', 'breakWhen', 'accentMinGap', 'maxDensityRun', 'heroFirstOnly', 'maxSections' ) ) ),
				'draft'            => array_column( $plan['sections'], 'pattern' ),
				'patterns'         => $catalog,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		$system = 'You plan one web page from block patterns. Pick patterns only from "patterns", in page order, following the slots of "frame" and "compositionRules". Improve "draft" for the goal and assign every point to the section that carries it. Answer with JSON only.';
		$answer = Gateway\Ai::json( $system, $prompt, $schema, self::NAME, array( 'plan' => $plan ) );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$slugs    = array();
		$assigned = array();
		$dropped  = array();
		foreach ( $answer['sections'] as $section ) {
			$slug = (string) $section['pattern'];
			if ( ! isset( $patterns[ $slug ] ) ) {
				$dropped[] = $slug;
				continue;
			}
			$assigned[ count( $slugs ) ] = array_values( array_filter( array_map( static fn( $p ): string => sanitize_text_field( (string) $p ), (array) $section['points'] ) ) );
			$slugs[]                     = $slug;
		}
		if ( ! $slugs ) {
			return Gateway\Rejection::make(
				'leitvia_ai_invalid_response',
				__( 'The AI model named no pattern of this site. Try once more, or call again with ai set to false.', 'leitvia-studio' ),
				502,
				'ai',
				array(),
				self::NAME,
				'once',
				array(
					'plan'    => $plan,
					'dropped' => $dropped,
					'hint'    => array( 'ai' => false ),
				)
			);
		}
		return self::result( $frame, $slugs, $points, 'ai', $assigned, $dropped );
	}
}
