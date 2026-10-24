<?php
/**
 * Read abilities for the structure of a page.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/get-page-outline and leitvia/get-block.
 */
final class Read_Structure {

	/**
	 * Registers both abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$post   = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Post ID.', 'leitvia-studio' ),
		);
		$hash   = array( 'type' => 'string' );
		$nodes  = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		$string = array( 'type' => 'string' );
		Gateway\Abilities::read(
			'leitvia/get-page-outline',
			__( 'Get page outline', 'leitvia-studio' ),
			__( 'Returns a post as a compact block tree: per block the ID, name, pattern, the first 80 characters of text, the link targets of the block itself (links, only when it has any), size, hash and children. Blocks without a stable ID have a path ID such as p:0.2.1 until the next save. Use the document hash as base_hash for changes and get-block for full attributes.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'post' => $post ),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'integer' ),
					'type'     => $string,
					'hash'     => $hash,
					'title'    => $string,
					'status'   => $string,
					'template' => $string,
					'kind'     => $string,
					'blocks'   => $nodes,
				),
			),
			static fn( $input ) => Gateway\Outline::page( (int) ( $input['post'] ?? 0 ) )
		);
		Gateway\Abilities::read(
			'leitvia/get-block',
			__( 'Get block', 'leitvia-studio' ),
			__( 'Returns one block of a post by its ID or path ID with all attributes, its own markup, the attachments of the images in that markup, its hash, the IDs of its children and the document hash. Classic content without blocks is one block p:0 named core/freeform.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'post'  => $post,
					'block' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => __( 'Block ID from the page outline.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'post', 'block' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'id'            => $string,
					'id_stable'     => array( 'type' => 'boolean' ),
					'name'          => $string,
					'attributes'    => array( 'type' => 'object' ),
					'inner_html'    => $string,
					'attachments'   => array(
						'type'        => 'array',
						'description' => __( 'Images in inner_html that are attachments of this site, in order: attachment ID and the src as written.', 'leitvia-studio' ),
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'id'  => array( 'type' => 'integer' ),
								'url' => $string,
							),
						),
					),
					'hash'          => $hash,
					'children'      => array(
						'type'  => 'array',
						'items' => $string,
					),
					'document_hash' => $hash,
				),
			),
			static fn( $input ) => Gateway\Outline::block( (int) ( $input['post'] ?? 0 ), (string) ( $input['block'] ?? '' ) )
		);
	}
}
