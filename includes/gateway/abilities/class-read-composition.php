<?php
/**
 * Read ability for the composition check.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Ability leitvia/check-composition: findings, score and suggestions for a list of pattern slugs or a saved page.
 */
final class Read_Composition {

	public const MAX_SLUGS = 40;

	/**
	 * Registers the ability. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$finding = array(
			'type'       => 'object',
			'properties' => array(
				'rule'    => array( 'type' => 'string' ),
				'index'   => array( 'type' => 'integer' ),
				'block'   => array( 'type' => 'string' ),
				'message' => array( 'type' => 'string' ),
			),
		);
		Gateway\Abilities::read(
			'leitvia/check-composition',
			__( 'Check composition', 'leitvia-studio' ),
			__( 'Scores a page against the composition rules of the contract: pass pattern slugs in page order (block:<ID> for a Lab pattern; only approved ones count as known), or a post ID to read the sections of a saved page from metadata.patternName. A saved page counts as built: the ground each section sets (backgroundColor, block style Inverse) instead of the tone of its pattern, and the side of its media (block style Media right). With slugs, name both yourself: tones for the sections an edit will give another ground, mirrored for those it will give the block style Media right, each by 0-based index. Text, lists, images, galleries and accordions between the sections are running text and no finding. A section with a video, a gallery or a wide image is a break and stands outside the density run. Returns the findings (rule, 0-based section index, for a post the block ID, and what to do), the score from 100 down, the minimum score for autonomous writes and per finding up to three patterns that would fix it. For a post also unregistered: block types that render nothing.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'slugs'    => array(
						'type'     => 'array',
						'items'    => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'maxItems' => self::MAX_SLUGS,
					),
					'tones'    => array(
						'type'                 => 'object',
						'description'          => __( 'Tone per 0-based index in slugs for sections whose ground an edit changes, for example {"2": "ink"} for a third section with className is-style-inverse, or the slug of its backgroundColor such as surface-alt. With post it replaces what the page sets for that section.', 'leitvia-studio' ),
						'additionalProperties' => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9-]{1,40}$',
						),
					),
					'mirrored' => array(
						'type'        => 'array',
						'description' => __( '0-based indexes in slugs of the sections an edit gives the block style Media right, for example [3]. A saved page reads them from its blocks.', 'leitvia-studio' ),
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'maxItems'    => self::MAX_SLUGS,
					),
					'post'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'template' => array(
						'type'        => 'string',
						'maxLength'   => 100,
						'description' => __( 'Template slug, for example page-landing; for a post its saved template is used.', 'leitvia-studio' ),
					),
				),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'sections'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'issues'       => array(
						'type'  => 'array',
						'items' => $finding,
					),
					'score'        => array( 'type' => 'integer' ),
					'min_score'    => array( 'type' => 'integer' ),
					'suggestions'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'unregistered' => array( 'type' => 'object' ),
				),
			),
			array( self::class, 'check' )
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param mixed $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function check( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$post     = (int) ( $input['post'] ?? 0 );
		$template = sanitize_key( (string) ( $input['template'] ?? '' ) );
		$unknown  = array();
		$tones    = array();
		$mirrored = array();
		$ids      = array();
		if ( $post ) {
			if ( ! Gateway\Scope::readable( $post ) ) {
				return Gateway\Rejection::make( 'leitvia_out_of_scope', __( 'Agents may not read this object. Only published or draft content of the allowed post types is open to them.', 'leitvia-studio' ), 403, 'scope', array( 'post' => $post ), '', 'never' );
			}
			$content  = (string) get_post_field( 'post_content', $post, 'raw' );
			$slugs    = Gateway\Composition::sections( $content );
			$tones    = Gateway\Composition::tones( $content );
			$mirrored = Gateway\Composition::mirrored( $content );
			$ids      = Gateway\Composition::ids( $content );
			$template = '' !== $template ? $template : (string) get_page_template_slug( $post );
			$unknown  = Gateway\Content_Checks::unregistered_types( $content );
		} else {
			$slugs = array_slice( array_map( 'strval', (array) ( $input['slugs'] ?? array() ) ), 0, self::MAX_SLUGS );
		}
		foreach ( (array) ( $input['tones'] ?? array() ) as $index => $tone ) {
			if ( isset( $slugs[ (int) $index ] ) && is_string( $tone ) && preg_match( '/^[a-z0-9-]{1,40}$/D', $tone ) ) {
				$tones[ (int) $index ] = $tone;
			}
		}
		$mirrored = array_merge( $mirrored, array_map( 'intval', (array) ( $input['mirrored'] ?? array() ) ) );
		if ( ! $slugs ) {
			return Gateway\Rejection::make( 'leitvia_invalid_input', __( 'Pass pattern slugs, or a post whose sections come from patterns.', 'leitvia-studio' ), 400, 'input', $post ? array( 'post' => $post ) : array(), 'leitvia/list-patterns', 'once', $unknown ? array( 'unregistered' => $unknown ) : array() );
		}
		$report = Gateway\Composition::report( $slugs, Gateway\Composition::lab( $slugs, leitvia_studio_contract()->data() ), $template, $tones, $mirrored );
		foreach ( array( 'issues', 'suggestions' ) as $key ) {
			foreach ( $report[ $key ] as $at => $item ) {
				if ( '' !== ( $ids[ $item['index'] ] ?? '' ) ) {
					$report[ $key ][ $at ]['block'] = $ids[ $item['index'] ];
				}
			}
		}
		return ( $unknown ? array( 'unregistered' => $unknown ) : array() ) + array( 'sections' => $slugs ) + $report + array( 'min_score' => (int) Gateway\Gateway::settings()['min_composition_score'] );
	}
}
