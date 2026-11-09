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
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'text_hash' => array(
						'type'        => 'string',
						'description' => __( 'Only when text was cut: the first 16 hex characters of the SHA-256 of the whole text, tags stripped, entities decoded, white space collapsed to single spaces and trimmed.', 'leitvia-studio' ),
					),
					'image'     => array(
						'type'        => 'object',
						'description' => __( 'Image of an image or cover block: attachment ID (0 outside the media library), alt text (null without alt attribute) and the decorative mark.', 'leitvia-studio' ),
						'properties'  => array(
							'id'         => array( 'type' => 'integer' ),
							'alt'        => array( 'type' => array( 'string', 'null' ) ),
							'decorative' => array( 'type' => 'boolean' ),
						),
					),
				),
			),
		);
		$string = array( 'type' => 'string' );
		Gateway\Abilities::read(
			'leitvia/get-page-outline',
			__( 'Get page outline', 'leitvia-studio' ),
			__( 'Returns a post as a compact block tree: per block the ID, name, pattern, the first 80 characters of text (with text_hash of the whole text when it is longer), the link targets of the block itself (links, only when it has any), for image and cover blocks the image with attachment ID, alt text and decorative mark, size, hash and children. shortcodes: unhandled shortcodes per block. Blocks without a stable ID have a path ID such as p:0.2.1 until the next save. Use the document hash as base_hash for changes and get-block for full attributes.', 'leitvia-studio' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'post' => $post ),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'id'         => array( 'type' => 'integer' ),
					'type'       => $string,
					'hash'       => $hash,
					'title'      => $string,
					'status'     => $string,
					'template'   => $string,
					'kind'       => $string,
					'blocks'     => $nodes,
					'shortcodes' => array(
						'type'        => 'array',
						'description' => __( 'Only when present: name and block of each.', 'leitvia-studio' ),
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'name'  => $string,
								'block' => $string,
							),
						),
					),
				),
			),
			static fn( $input ) => Gateway\Outline::page( (int) ( $input['post'] ?? 0 ) )
		);
		Gateway\Abilities::read(
			'leitvia/get-block',
			__( 'Get block', 'leitvia-studio' ),
			__( 'Returns one block of a post by its ID or path ID with all attributes, its markup alone and with children, the image attachments, its hash, the IDs of its children and the document hash. Classic content without blocks is one block p:0 named core/freeform.', 'leitvia-studio' ),
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
					'markup'        => array(
						'type'        => 'string',
						'description' => __( 'The block with its children as block markup: change it and send it as content of leitvia/update-template-part, or with the other top-level blocks of a post as content of leitvia/update-content.', 'leitvia-studio' ),
					),
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
