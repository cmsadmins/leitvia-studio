<?php
/**
 * Maintenance abilities: content, internal links, alt text and deprecated patterns.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Block_Edit;
use LeitviaStudio\Gateway\Block_Ids;
use LeitviaStudio\Gateway\Content_Checks;
use LeitviaStudio\Gateway\Outline;
use LeitviaStudio\Gateway\Rejection;
use LeitviaStudio\Lab\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities leitvia/update-content, leitvia/repair-links, leitvia/set-alt-text and leitvia/replace-pattern. Each one
 * changes one post through Pipeline::run(). Link repairs and alt texts are corrections (autonomous by default); a live
 * write of any of them gets a change package, so leitvia/rollback can undo it.
 */
final class Maintain {

	/**
	 * Registers the abilities. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$post      = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$overwrite = array(
			'destructive' => true,
			'idempotent'  => false,
		);
		$add       = array(
			'destructive' => false,
			'idempotent'  => false,
		);
		Abilities::write(
			'leitvia/update-content',
			__( 'Update content', 'leitvia-studio' ),
			__( 'Updates title, excerpt, slug, date, terms, fields, featured image, comment status and the texts and button links of several blocks of one post in one operation, blocks found by their ID from the outline. Terms, fields and featured image follow the rules of leitvia/create-content; terms replace those of their taxonomy. Instead of blocks, content replaces the whole content with block markup, for example to turn classic content into blocks; the new content may hold no classic block. Read the post with leitvia/get-page-outline first and send its hash as base_hash. A slug change is a URL change and lands at most as a suggestion; after one, leitvia/repair-links with auto points the links of other posts to the new address. For attributes or images of a block use leitvia/update-block.', 'leitvia-studio' ),
			'maintain',
			$overwrite,
			array(
				'properties'           => array(
					'post'           => $post,
					'title'          => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'excerpt'        => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
					'slug'           => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]+$',
					),
					'blocks'         => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'block' => array(
									'type'        => 'string',
									'minLength'   => 1,
									'maxLength'   => 200,
									'description' => __( 'Block ID or path ID from the outline.', 'leitvia-studio' ),
								),
								'text'  => array(
									'type'        => 'string',
									'maxLength'   => 5000,
									'description' => __( 'Plain text of a paragraph, heading, list item or button.', 'leitvia-studio' ),
								),
								'url'   => array(
									'type'        => 'string',
									'maxLength'   => 2000,
									'description' => __( 'Link of a button.', 'leitvia-studio' ),
								),
							),
							'required'             => array( 'block' ),
							'additionalProperties' => false,
						),
					),
					'content'        => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Block markup that replaces the whole content; not together with blocks.', 'leitvia-studio' ),
					),
					'date'           => Content::date_schema(),
					'terms'          => Content::terms_schema(),
					'fields'         => Content::fields_schema(),
					'featured_image' => Content::image_schema( false ),
					'comment_status' => Content::comment_status_schema(),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_update_content' )
		);
		$link = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 2000,
		);
		Abilities::write(
			'leitvia/repair-links',
			__( 'Repair links', 'leitvia-studio' ),
			__( 'Repairs internal links of one post: from pairs of old and new link, or with auto through the old slugs WordPress keeps for redirects. The base hash is the hash of the post from leitvia/get-page-outline. Only links of this site change. External links are never requested, only listed in the report, as are internal links that lead nowhere and cannot be resolved.', 'leitvia-studio' ),
			'maintain',
			$add,
			array(
				'properties'           => array(
					'post'  => $post,
					'links' => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'from' => $link,
								'to'   => $link,
							),
							'required'             => array( 'from', 'to' ),
							'additionalProperties' => false,
						),
					),
					'auto'  => array(
						'type'        => 'boolean',
						'description' => __( 'Find links to posts whose slug changed and point them to the current address.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_repair_links' )
		);
		Abilities::write(
			'leitvia/set-alt-text',
			__( 'Set alt text', 'leitvia-studio' ),
			__( 'Sets the alt text of an image: without post and block on the attachment in the media library (base_hash of the attachment), with post and block on one image or cover block that shows the image (base_hash of the post). leitvia/get-page-outline returns both hashes, for the attachment with its ID as post. leitvia/audit-site reports missing alt text. Call it once per place, with the same package key. The alt text check applies.', 'leitvia-studio' ),
			'maintain',
			$add,
			array(
				'properties'           => array(
					'image' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Attachment ID of the image.', 'leitvia-studio' ),
					),
					'alt'   => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 500,
					),
					'post'  => $post,
					'block' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => __( 'Block ID or path ID of the image or cover block from the outline.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'image', 'alt' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_set_alt_text' )
		);
		Abilities::write(
			'leitvia/replace-pattern',
			__( 'Replace deprecated pattern', 'leitvia-studio' ),
			__( 'Replaces the sections of one post that use a deprecated pattern (metadata.patternName) with the replacement the contract names, all of them or the one section given by block. leitvia/audit-site reports the posts that use deprecated patterns; the base hash is the hash of the post from leitvia/get-page-outline. Texts and button links move over in order per block type. A section without replacement is rejected with leitvia_no_replacement.', 'leitvia-studio' ),
			'maintain',
			$overwrite,
			array(
				'properties'           => array(
					'post'  => $post,
					'block' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => __( 'Block ID or path ID of a top-level section from the outline.', 'leitvia-studio' ),
					),
				),
				'required'             => array( 'post' ),
				'additionalProperties' => false,
			),
			array( self::class, 'describe_replace_pattern' )
		);
	}

	/**
	 * Operation of update-content. With content the target carries replace, so the pipeline allows no classic block in
	 * it even where the old content was classic.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_update_content( array $input ) {
		$post    = (int) ( $input['post'] ?? 0 );
		$edits   = (array) ( $input['blocks'] ?? array() );
		$replace = isset( $input['content'] ) ? (string) $input['content'] : null;
		$fields  = array_filter(
			array(
				'post_title'     => $input['title'] ?? null,
				'post_excerpt'   => $input['excerpt'] ?? null,
				'post_name'      => $input['slug'] ?? null,
				'comment_status' => $input['comment_status'] ?? null,
			),
			'is_string'
		);
		$extra   = array_intersect_key( $input, array_flip( array( 'date', 'terms', 'fields', 'featured_image' ) ) );
		if ( null !== $replace && $edits ) {
			return Block_Edit::invalid( 'change', __( 'Send either content or blocks, not both.', 'leitvia-studio' ), array( 'post' => $post ) );
		}
		if ( ! $fields && ! $edits && null === $replace && ! $extra ) {
			return Block_Edit::invalid( 'change', __( 'Name what to change: title, excerpt, slug, date, terms, fields, featured image, comment status, blocks or content.', 'leitvia-studio' ), array( 'post' => $post ) );
		}
		return array(
			'area'   => 'content',
			'target' => array( 'post' => $post ) + ( null === $replace ? array() : array( 'replace' => true ) ),
			'record' => true,
			'change' => static function ( array $state ) use ( $post, $edits, $fields, $replace, $extra ) {
				$content = $replace ?? Build::edit( (string) $state['post_content'], $edits, array( 'post' => $post ) );
				return is_wp_error( $content ) ? $content : Content::change( array( 'post_content' => $content ) + $fields + $state, $extra, array( 'post' => $post ) );
			},
		);
	}

	/**
	 * Operation of repair-links. Links are read and rewritten in the markup with the HTML API; block comments stay.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_repair_links( array $input ) {
		$post   = (int) ( $input['post'] ?? 0 );
		$target = array( 'post' => $post );
		$pairs  = array();
		foreach ( (array) ( $input['links'] ?? array() ) as $link ) {
			$from = trim( (string) ( $link['from'] ?? '' ) );
			$to   = trim( (string) ( $link['to'] ?? '' ) );
			if ( ! self::internal( $from ) || ! self::internal( $to ) ) {
				return Block_Edit::invalid( 'external_link', __( 'Only links of this site can be repaired. External links are never requested; change them by hand.', 'leitvia-studio' ), $target );
			}
			$pairs[ self::normal( $from ) ] = $to;
		}
		$auto = ! empty( $input['auto'] );
		if ( ! $pairs && ! $auto ) {
			return Block_Edit::invalid( 'change', __( 'Send pairs of old and new links, or set auto.', 'leitvia-studio' ), $target );
		}
		$report = array(
			'repaired'   => array(),
			'external'   => array(),
			'unresolved' => array(),
		);
		return array(
			'area'   => 'corrections',
			'target' => $target,
			'record' => true,
			'report' => static function () use ( &$report ): array {
				return $report;
			},
			'change' => static function ( array $state ) use ( $pairs, $auto, $target, &$report ) {
				$tags = new \WP_HTML_Tag_Processor( (string) $state['post_content'] );
				while ( $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
					$href = $tags->get_attribute( 'href' );
					$href = is_string( $href ) ? trim( $href ) : '';
					if ( '' === $href ) {
						continue;
					}
					if ( ! self::internal( $href ) ) {
						if ( '' !== (string) wp_parse_url( $href, PHP_URL_HOST ) ) {
							$report['external'][] = $href;
						}
						continue;
					}
					$new = $pairs[ self::normal( $href ) ] ?? ( $auto ? self::resolve( $href ) : '' );
					if ( null === $new ) {
						$report['unresolved'][] = $href;
						continue;
					}
					if ( '' === $new || $new === $href ) {
						continue;
					}
					$fragment = (string) wp_parse_url( $href, PHP_URL_FRAGMENT );
					$new     .= '' !== $fragment && ! str_contains( $new, '#' ) ? '#' . $fragment : '';
					$tags->set_attribute( 'href', esc_url_raw( $new ) );
					$report['repaired'][] = array(
						'from' => $href,
						'to'   => $new,
					);
				}
				$report['external']   = array_values( array_unique( $report['external'] ) );
				$report['unresolved'] = array_values( array_unique( $report['unresolved'] ) );
				if ( ! $report['repaired'] ) {
					return Rejection::make( 'leitvia_invalid_input', __( 'No link needs or allows a repair. The report lists external links and internal links that could not be resolved.', 'leitvia-studio' ), 422, 'nothing_to_repair', $target, '', 'never', array( 'report' => $report ) );
				}
				return array( 'post_content' => $tags->get_updated_html() ) + $state;
			},
		);
	}

	/**
	 * Operation of set-alt-text: on the attachment, or on one block of a post that shows the image.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function describe_set_alt_text( array $input ) {
		$image = (int) ( $input['image'] ?? 0 );
		$alt   = trim( (string) ( $input['alt'] ?? '' ) );
		if ( '' === $alt ) {
			return Block_Edit::invalid( 'alt_text', __( 'Give the image an alt text that describes it.', 'leitvia-studio' ), array( 'post' => $image ) );
		}
		if ( isset( $input['post'] ) !== isset( $input['block'] ) ) {
			return Block_Edit::invalid( 'target', __( 'Send post and block together for a block, or neither for the attachment.', 'leitvia-studio' ), array( 'post' => $image ) );
		}
		if ( ! isset( $input['post'] ) ) {
			return array(
				'area'   => 'corrections',
				'target' => array( 'post' => $image ),
				'record' => true,
				'change' => static function ( array $state ) use ( $image, $alt ) {
					if ( 'attachment' !== $state['post_type'] || ! wp_attachment_is_image( $image ) ) {
						return Block_Edit::invalid( 'image', __( 'The ID is no image of the media library.', 'leitvia-studio' ), array( 'post' => $image ) );
					}
					$findings = Content_Checks::alt_text( Build::image_markup( wp_basename( (string) get_attached_file( $image ) ), $alt, false ), array( 'post_type' => 'page' ) );
					if ( $findings ) {
						return Rejection::make( 'leitvia_check_failed', __( 'Checks found problems. Fix them as each finding suggests and try again.', 'leitvia-studio' ), 422, 'checks', array( 'post' => $image ), '', 'once', array( 'findings' => $findings ) );
					}
					$state['meta_input']['_wp_attachment_image_alt'] = $alt;
					return $state;
				},
			);
		}
		$post   = (int) $input['post'];
		$block  = (string) $input['block'];
		$target = array(
			'post'  => $post,
			'block' => $block,
		);
		return array(
			'area'   => 'corrections',
			'target' => $target,
			'record' => true,
			'change' => static function ( array $state ) use ( $post, $block, $image, $alt, $target ) {
				$found = Outline::find( Block_Ids::blocks( parse_blocks( (string) $state['post_content'] ) ), $block );
				if ( $found && (int) ( $found[0]['attrs']['id'] ?? 0 ) !== $image ) {
					return Block_Edit::invalid( 'image', __( 'The block does not show this image. Read the outline for the block that does.', 'leitvia-studio' ), $target, 'leitvia/get-page-outline' );
				}
				$content = Block_Edit::apply(
					(string) $state['post_content'],
					$block,
					array(
						'image' => array(
							'id'  => $image,
							'alt' => $alt,
						),
					),
					array( 'post' => $post )
				);
				return is_wp_error( $content ) ? $content : array( 'post_content' => $content ) + $state;
			},
		);
	}

	/**
	 * Operation of replace-pattern.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function describe_replace_pattern( array $input ): array {
		$post  = (int) ( $input['post'] ?? 0 );
		$block = (string) ( $input['block'] ?? '' );
		return array(
			'area'   => 'structure',
			'target' => array( 'post' => $post ) + ( '' !== $block ? array( 'block' => $block ) : array() ),
			'record' => true,
			'change' => static function ( array $state ) use ( $post, $block ) {
				$content = self::replace( (string) $state['post_content'], $block, array( 'post' => $post ) );
				return is_wp_error( $content ) ? $content : array( 'post_content' => $content ) + $state;
			},
		);
	}

	/**
	 * Markup with the top-level sections of deprecated patterns replaced, all or the one with the ID.
	 *
	 * @param string               $content Block markup.
	 * @param string               $only    leitviaId or path ID of one section, '' for all.
	 * @param array<string, mixed> $target  Target for rejections.
	 * @return string|\WP_Error
	 */
	private static function replace( string $content, string $only, array $target ) {
		// ponytail: top-level sections only, as compose-page writes them; nested ones would need placeholder bookkeeping.
		$blocks   = array();
		$index    = 0;
		$replaced = 0;
		$found    = false;
		foreach ( parse_blocks( $content ) as $block ) {
			if ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) ) {
				$blocks[] = $block;
				continue;
			}
			$path   = 'p:' . ( $index++ );
			$id     = '' !== Block_Ids::id( $block ) ? Block_Ids::id( $block ) : $path;
			$chosen = '' === $only || $id === $only || $path === $only;
			$found  = $found || ( '' !== $only && $chosen );
			$name   = (string) ( $block['attrs']['metadata']['patternName'] ?? '' );
			if ( ! $chosen || ( '' === $only && ( '' === $name || 'deprecated' !== Status::own( 'pattern:' . $name ) ) ) ) {
				$blocks[] = $block;
				continue;
			}
			$ref     = '' !== $name ? (string) preg_replace( '/^pattern:/', '', (string) ( leitvia_studio_contract()->pattern( $name )['docs']['replacedBy'] ?? '' ) ) : '';
			$pattern = '' !== $ref ? Build::pattern( $ref ) : null;
			if ( ! $pattern || ( is_wp_error( $pattern ) && 'leitvia_unknown_pattern' === $pattern->get_error_code() ) ) {
				return self::no_replacement( $target + array( 'block' => $id ), $name );
			}
			if ( is_wp_error( $pattern ) ) {
				return $pattern;
			}
			$markup = self::carry( $block, Build::name_sections( $pattern['content'], $pattern['name'] ), $target );
			if ( is_wp_error( $markup ) ) {
				return $markup;
			}
			$blocks = array_merge( $blocks, parse_blocks( $markup ) );
			++$replaced;
		}
		if ( '' !== $only && ! $found ) {
			return Rejection::make( 'leitvia_not_found', __( 'There is no top-level section with this ID. Read the outline for the current IDs.', 'leitvia-studio' ), 404, 'block', $target + array( 'block' => $only ), 'leitvia/get-page-outline' );
		}
		return $replaced ? serialize_blocks( $blocks ) : self::no_replacement( $target, '' );
	}

	/**
	 * Rejection leitvia_no_replacement.
	 *
	 * @param array<string, mixed> $target  Target.
	 * @param string               $pattern Pattern of the section, '' when no section uses a deprecated pattern.
	 */
	private static function no_replacement( array $target, string $pattern ): \WP_Error {
		return Rejection::make( 'leitvia_no_replacement', __( 'There is no deprecated pattern with an available replacement here. Choose a pattern and change the section by hand or with leitvia/compose-page.', 'leitvia-studio' ), 422, 'no_replacement', $target, 'leitvia/list-patterns', 'never', array( 'pattern' => $pattern ) );
	}

	/**
	 * Replacement markup with the texts and button links of the old section, in order per block type.
	 *
	 * @param array<string, mixed> $old    Old section.
	 * @param string               $markup Replacement markup.
	 * @param array<string, mixed> $target Target for rejections.
	 * @return string|\WP_Error
	 */
	private static function carry( array $old, string $markup, array $target ) {
		// ponytail: plain text only; inline formatting and links inside paragraphs do not move over.
		$texts = array();
		foreach ( self::texts( array( $old ), 'p:' ) as $item ) {
			$texts[ $item['name'] ][] = $item;
		}
		foreach ( self::texts( parse_blocks( $markup ), 'p:' ) as $item ) {
			$from = array_shift( $texts[ $item['name'] ] );
			if ( ! $from || '' === $from['text'] ) {
				continue;
			}
			$change = array( 'text' => $from['text'] ) + ( 'core/button' === $item['name'] && '' !== $from['url'] ? array( 'url' => $from['url'] ) : array() );
			$markup = Block_Edit::apply( $markup, $item['path'], $change, $target );
			if ( is_wp_error( $markup ) ) {
				return $markup;
			}
		}
		return $markup;
	}

	/**
	 * Text blocks in document order with name, path ID (counted as Block_Edit counts), plain text and link.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @param string       $path   Path prefix.
	 * @return list<array{name: string, path: string, text: string, url: string}>
	 */
	private static function texts( array $blocks, string $path ): array {
		$found = array();
		$index = 0;
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) ) {
				continue;
			}
			$here = $path . ( $index++ );
			$name = (string) $block['blockName'];
			if ( isset( Block_Edit::TEXT_BLOCKS[ $name ] ) ) {
				$html    = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
				$tags    = new \WP_HTML_Tag_Processor( $html );
				$url     = $tags->next_tag( array( 'tag_name' => 'a' ) ) ? $tags->get_attribute( 'href' ) : '';
				$found[] = array(
					'name' => $name,
					'path' => $here,
					'text' => trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
					'url'  => is_string( $url ) ? $url : '',
				);
			}
			$found = array_merge( $found, self::texts( (array) $block['innerBlocks'], $here . '.' ) );
		}
		return $found;
	}

	/**
	 * Whether a link points to this site: a root-relative path or the host of home_url(). Nothing is requested.
	 *
	 * @param string $url Link.
	 */
	public static function internal( string $url ): bool {
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return true;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& strtolower( (string) ( $parts['host'] ?? '' ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Path and query of an internal link without trailing slash, to compare links however they are written.
	 *
	 * @param string $url Internal link.
	 */
	private static function normal( string $url ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Current address of an internal link: '' when it leads to a post or is no post link (home, files, archives, admin),
	 * the permalink of the published post that had its last path segment as old slug (what core's
	 * wp_old_slug_redirect() follows), null when nothing resolves it.
	 *
	 * @param string $href Internal link.
	 */
	public static function resolve( string $href ): ?string {
		// The link on the address of home, port included, so url_to_postid() can strip home from it.
		$home     = (array) wp_parse_url( home_url() );
		$query    = (string) wp_parse_url( $href, PHP_URL_QUERY );
		$absolute = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . wp_parse_url( $href, PHP_URL_PATH ) . ( '' !== $query ? '?' . $query : '' );
		$path     = trim( (string) wp_parse_url( $absolute, PHP_URL_PATH ), '/' );
		$home_dir = trim( (string) ( $home['path'] ?? '' ), '/' );
		$below    = ltrim( substr( $path, strlen( $home_dir ) ), '/' );
		if ( $path === $home_dir || str_contains( wp_basename( $path ), '.' ) || str_starts_with( $below . '/', 'wp-admin/' ) || url_to_postid( $absolute ) || self::archive( $below ) ) {
			return '';
		}
		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => '_wp_old_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The lookup core's wp_old_slug_redirect() makes too.
				'meta_value'     => sanitize_title( wp_basename( $path ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);
		return $ids ? (string) get_permalink( (int) $ids[0] ) : null;
	}

	/**
	 * Whether the first rewrite rule that matches a path below home asks for no single post (a term, archive, feed,
	 * author or date), as url_to_postid() matches it; such a link leads somewhere without a post behind it.
	 *
	 * @param string $path Path below home, without slashes around it.
	 */
	private static function archive( string $path ): bool {
		global $wp_rewrite;
		$single = array( 'p', 'page_id', 'name', 'pagename', 'attachment', 'attachment_id', 'subpost', 'subpost_id' );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$single[] = is_string( $type->query_var ) ? $type->query_var : '';
		}
		foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $match => $query ) {
			if ( preg_match( "#^{$match}#", $path, $matches ) ) {
				parse_str( \WP_MatchesMapRegex::apply( (string) preg_replace( '!^.+\?!', '', $query ), $matches ), $vars );
				return ! array_intersect_key( $vars, array_flip( array_filter( $single ) ) );
			}
		}
		return false;
	}
}
