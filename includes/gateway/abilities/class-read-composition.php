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
				'message' => array( 'type' => 'string' ),
			),
		);
		Gateway\Abilities::read(
			'leitvia/check-composition',
			__( 'Check composition', 'leitvia-studio' ),
			__( 'Scores a page against the composition rules of the contract: pass pattern slugs in page order (block:<ID> for a Lab pattern; only approved ones count as known), or a post ID to read the sections of a saved page from metadata.patternName. Returns the findings (rule and 0-based section index), the score from 100 down, the minimum score for autonomous writes and per finding up to three patterns that would fix it.', 'leitvia-studio' ),
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
					'sections'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'issues'      => array(
						'type'  => 'array',
						'items' => $finding,
					),
					'score'       => array( 'type' => 'integer' ),
					'min_score'   => array( 'type' => 'integer' ),
					'suggestions' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
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
		if ( $post ) {
			if ( ! Gateway\Scope::readable( $post ) ) {
				return Gateway\Rejection::make( 'leitvia_out_of_scope', __( 'Agents may not read this object. Only published or draft content of the allowed post types is open to them.', 'leitvia-studio' ), 403, 'scope', array( 'post' => $post ), '', 'never' );
			}
			$slugs    = Gateway\Composition::sections( (string) get_post_field( 'post_content', $post, 'raw' ) );
			$template = '' !== $template ? $template : (string) get_page_template_slug( $post );
		} else {
			$slugs = array_slice( array_map( 'strval', (array) ( $input['slugs'] ?? array() ) ), 0, self::MAX_SLUGS );
		}
		if ( ! $slugs ) {
			return Gateway\Rejection::make( 'leitvia_invalid_input', __( 'Pass pattern slugs, or a post whose sections come from patterns.', 'leitvia-studio' ), 400, 'input', $post ? array( 'post' => $post ) : array(), 'leitvia/list-patterns', 'once' );
		}
		return array( 'sections' => $slugs ) + Gateway\Composition::report( $slugs, Gateway\Composition::lab( $slugs, leitvia_studio_contract()->data() ), $template ) + array( 'min_score' => (int) Gateway\Gateway::settings()['min_composition_score'] );
	}
}
