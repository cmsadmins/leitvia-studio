<?php
/**
 * Deterministic content checks of the gateway.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Lab\Token_Lint;
use LeitviaStudio\Tokens\Contrast;

defined( 'ABSPATH' ) || exit;

/**
 * Checks of the registry checks with the scopes content and page. A check gets block markup and a context with post,
 * post_type and ability, and returns findings array( 'check', 'severity' => 'error'|'warning', 'block', 'message',
 * 'fix' ). The block is a leitviaId or a path ID such as p:0.2.1, '' for the page. The pipeline runs them before every
 * write, leitvia/audit-site on published content.
 */
final class Content_Checks {

	/**
	 * Link texts that say nothing without context.
	 */
	public const VAGUE_LINKS = array( 'click here', 'here', 'more', 'read more', 'learn more', 'hier', 'mehr', 'weiterlesen', 'mehr lesen', 'hier klicken' );

	/**
	 * Blocks whose images need alt text or a decorative mark.
	 */
	public const IMAGE_BLOCKS = array( 'core/image', 'core/cover', 'core/media-text', 'core/gallery' );

	/**
	 * Studio checks: name => scope and callback.
	 */
	private const CHECKS = array(
		'leitvia/headings'         => array( 'content', 'headings' ),
		'leitvia/alt-text'         => array( 'content', 'alt_text' ),
		'leitvia/link-text'        => array( 'content', 'link_text' ),
		'leitvia/block-validity'   => array( 'content', 'block_validity' ),
		'leitvia/content-tokens'   => array( 'content', 'tokens' ),
		'leitvia/content-contrast' => array( 'content', 'contrast' ),
		'leitvia/page-structure'   => array( 'page', 'page' ),
	);

	/**
	 * Registers the checks. Runs on leitvia_studio_init.
	 *
	 * @param \LeitviaStudio\Extension\Studio $studio Studio.
	 */
	public static function register( \LeitviaStudio\Extension\Studio $studio ): void {
		$labels = array(
			'leitvia/headings'         => __( 'Heading structure', 'leitvia-studio' ),
			'leitvia/alt-text'         => __( 'Alternative text', 'leitvia-studio' ),
			'leitvia/link-text'        => __( 'Link text', 'leitvia-studio' ),
			'leitvia/block-validity'   => __( 'Block validity', 'leitvia-studio' ),
			'leitvia/content-tokens'   => __( 'Design tokens in content', 'leitvia-studio' ),
			'leitvia/content-contrast' => __( 'Color pairs in content', 'leitvia-studio' ),
			'leitvia/page-structure'   => __( 'One h1 and landmarks', 'leitvia-studio' ),
		);
		foreach ( self::CHECKS as $name => [ $scope, $method ] ) {
			$studio->registry( 'checks' )->register(
				$name,
				array(
					'label'    => $labels[ $name ],
					'scope'    => $scope,
					'callback' => array( self::class, $method ),
				),
				'leitvia-studio'
			);
		}
	}

	/**
	 * Findings of all checks with the scopes content and page, as the pipeline collects them.
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $context post, post_type and ability.
	 * @return list<array<string, mixed>>
	 */
	public static function run( string $content, array $context ): array {
		$findings = array();
		foreach ( array( 'content', 'page' ) as $scope ) {
			foreach ( leitvia_studio()->registry( 'checks' )->all( array( 'scope' => $scope ) ) as $check ) {
				foreach ( (array) call_user_func( $check['callback'], $content, $context ) as $finding ) {
					if ( is_array( $finding ) ) {
						$findings[] = $finding + array(
							'check'    => $check['name'],
							'severity' => 'error',
						);
					}
				}
			}
		}
		return $findings;
	}

	/**
	 * Check leitvia/headings: no h1 when the template shows the title as h1, no skipped levels, no empty headings.
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $context Context.
	 * @return list<array<string, mixed>>
	 */
	public static function headings( string $content, array $context ): array {
		if ( ! self::applies( $context ) ) {
			return array();
		}
		$title_h1 = self::title_is_h1( self::template( $context ) );
		$previous = 1;
		$findings = array();
		foreach ( self::walk( $content ) as [ $block, $id ] ) {
			preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', self::html( $block ), $matches, PREG_SET_ORDER );
			foreach ( $matches as [ , $level, $inner ] ) {
				$level = (int) $level;
				if ( ! self::has_text( $inner ) ) {
					$findings[] = self::finding( 'leitvia/headings', 'error', $id, __( 'The heading is empty.', 'leitvia-studio' ), __( 'Give the heading text or remove it.', 'leitvia-studio' ) );
				}
				if ( 1 === $level && $title_h1 ) {
					$findings[] = self::finding( 'leitvia/headings', 'error', $id, __( 'The template already shows the title as h1.', 'leitvia-studio' ), __( 'Use h2 for this heading.', 'leitvia-studio' ) );
				} elseif ( $level > $previous + 1 ) {
					/* translators: 1: heading level, 2: previous heading level. */
					$findings[] = self::finding( 'leitvia/headings', 'error', $id, sprintf( __( 'Heading level h%1$d follows h%2$d and skips a level.', 'leitvia-studio' ), $level, $previous ), sprintf( __( 'Use h%d or add the missing level.', 'leitvia-studio' ), $previous + 1 ) );
				}
				$previous = $level;
			}
		}
		return $findings;
	}

	/**
	 * Check leitvia/alt-text: every image of an image, cover, media and text or gallery block has alt text that is not
	 * the file name, or an empty alt marked as decorative with metadata.leitviaDecorative. Images in classic content
	 * (freeform) count as decorative with role presentation or none, or aria-hidden true.
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $context Context.
	 * @return list<array<string, mixed>>
	 */
	public static function alt_text( string $content, array $context ): array {
		if ( ! self::applies( $context ) ) {
			return array();
		}
		$findings = array();
		foreach ( self::walk( $content ) as [ $block, $id ] ) {
			$classic = in_array( $block['blockName'], array( null, 'core/freeform' ), true );
			if ( ! $classic && ! in_array( $block['blockName'], self::IMAGE_BLOCKS, true ) ) {
				continue;
			}
			$decorative = true === ( $block['attrs']['metadata']['leitviaDecorative'] ?? null );
			$tags       = new \WP_HTML_Tag_Processor( self::html( $block ) );
			while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
				$alt  = $tags->get_attribute( 'alt' );
				$alt  = is_string( $alt ) ? trim( $alt ) : $alt;
				$file = strtolower( (string) wp_basename( (string) wp_parse_url( (string) $tags->get_attribute( 'src' ), PHP_URL_PATH ) ) );
				if ( null === $alt ) {
					$findings[] = self::finding( 'leitvia/alt-text', 'error', $id, __( 'The image has no alt attribute.', 'leitvia-studio' ), $classic ? __( 'Describe what the image shows, or set an empty alt and role="presentation" if it only decorates.', 'leitvia-studio' ) : __( 'Describe what the image shows, or set an empty alt and mark the block as decorative.', 'leitvia-studio' ) );
				} elseif ( '' === $alt || true === $alt ) {
					$marked = $decorative || ( $classic && ( in_array( strtolower( trim( (string) $tags->get_attribute( 'role' ) ) ), array( 'presentation', 'none' ), true ) || 'true' === $tags->get_attribute( 'aria-hidden' ) ) );
					if ( ! $marked ) {
						$findings[] = self::finding( 'leitvia/alt-text', 'warning', $id, __( 'The image has an empty alt but is not marked as decorative.', 'leitvia-studio' ), $classic ? __( 'Describe what the image shows, or set role="presentation" if it only decorates.', 'leitvia-studio' ) : __( 'Describe what the image shows, or set metadata.leitviaDecorative to true if it only decorates.', 'leitvia-studio' ) );
					}
				} elseif ( self::is_file_name( strtolower( $alt ), $file ) ) {
					$findings[] = self::finding( 'leitvia/alt-text', 'error', $id, __( 'The alt text is a file name.', 'leitvia-studio' ), __( 'Describe what the image shows in a short sentence.', 'leitvia-studio' ) );
				}
			}
		}
		return $findings;
	}

	/**
	 * Check leitvia/link-text: no empty links, no vague link texts without an accessible name, no href="#".
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $context Context.
	 * @return list<array<string, mixed>>
	 */
	public static function link_text( string $content, array $context ): array {
		if ( ! self::applies( $context ) ) {
			return array();
		}
		$findings = array();
		foreach ( self::walk( $content ) as [ $block, $id ] ) {
			preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', self::html( $block ), $matches, PREG_SET_ORDER );
			foreach ( $matches as [ , $attributes, $inner ] ) {
				$tag = new \WP_HTML_Tag_Processor( '<a' . $attributes . '>' );
				$tag->next_tag();
				$label = trim( (string) $tag->get_attribute( 'aria-label' ) ) . trim( (string) $tag->get_attribute( 'aria-labelledby' ) );
				$text  = strtolower( trim( (string) preg_replace( array( '/\s+/u', '/[\s.:…»›>]+$/u' ), array( ' ', '' ), html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
				if ( '#' === trim( (string) $tag->get_attribute( 'href' ) ) ) {
					$findings[] = self::finding( 'leitvia/link-text', 'error', $id, __( 'The link points to "#" and goes nowhere.', 'leitvia-studio' ), __( 'Link to the real target, or use a button block for an action.', 'leitvia-studio' ) );
				}
				if ( '' !== $label ) {
					continue;
				}
				if ( ! self::has_text( $inner ) ) {
					$findings[] = self::finding( 'leitvia/link-text', 'error', $id, __( 'The link has no text.', 'leitvia-studio' ), __( 'Give the link a text that names its target.', 'leitvia-studio' ) );
				} elseif ( in_array( $text, self::VAGUE_LINKS, true ) ) {
					/* translators: %s: link text. */
					$findings[] = self::finding( 'leitvia/link-text', 'error', $id, sprintf( __( 'The link text "%s" does not say where the link goes.', 'leitvia-studio' ), $text ), __( 'Name the target in the link text, for example "Read the pricing details".', 'leitvia-studio' ) );
				}
			}
		}
		return $findings;
	}

	/**
	 * Check leitvia/block-validity: registered blocks only, no classic block outside classic content, no Custom HTML,
	 * no Base64 data, no deprecated catalog blocks (hidden from the inserter without a parent), and Studio blocks render
	 * without errors.
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $context Context.
	 * @return list<array<string, mixed>>
	 */
	public static function block_validity( string $content, array $context ): array {
		if ( ! self::applies( $context ) ) {
			return array();
		}
		$classic  = ! has_blocks( $content );
		$types    = \WP_Block_Type_Registry::get_instance();
		$catalog  = array_column( \LeitviaStudio\Blocks\Catalog::get(), null, 'name' );
		$findings = array();
		$add      = static function ( string $id, string $message, string $fix, string $severity = 'error' ) use ( &$findings ): void {
			$findings[] = self::finding( 'leitvia/block-validity', $severity, $id, $message, $fix );
		};
		foreach ( self::walk( $content ) as [ $block, $id ] ) {
			$name = (string) $block['blockName'];
			if ( ( '' === $name || 'core/freeform' === $name ) && ! $classic ) {
				$add( $id, __( 'Classic content outside a classic post.', 'leitvia-studio' ), __( 'Use paragraph, heading, list and image blocks instead.', 'leitvia-studio' ) );
				continue;
			}
			if ( preg_match( '/data:[^,"\'\s]*;base64/i', self::html( $block ) . wp_json_encode( $block['attrs'] ) ) ) {
				$add( $id, __( 'The block holds Base64 data.', 'leitvia-studio' ), __( 'Upload the file to the media library and link it.', 'leitvia-studio' ) );
			}
			if ( '' === $name || 'core/freeform' === $name ) {
				continue;
			}
			if ( 'core/html' === $name ) {
				$add( $id, __( 'Custom HTML blocks are not open to agents.', 'leitvia-studio' ), __( 'Use registered blocks from leitvia/list-blocks.', 'leitvia-studio' ) );
				continue;
			}
			if ( ! $types->is_registered( $name ) ) {
				/* translators: %s: block name. */
				$add( $id, sprintf( __( 'The block %s is not registered.', 'leitvia-studio' ), $name ), __( 'Use a block from leitvia/list-blocks, or activate the plugin that provides it.', 'leitvia-studio' ) );
				continue;
			}
			$entry = $catalog[ $name ] ?? null;
			if ( $entry && false === ( $entry['supports']['inserter'] ?? true ) && ! $entry['parent'] && ! $entry['ancestor'] ) {
				/* translators: %s: block name. */
				$add( $id, sprintf( __( 'The block %s is deprecated.', 'leitvia-studio' ), $name ), __( 'Replace it with a block from leitvia/list-blocks.', 'leitvia-studio' ), 'warning' );
			}
			if ( $entry && 'core' !== $entry['owner'] && ! self::renders( $block ) ) {
				/* translators: %s: block name. */
				$add( $id, sprintf( __( 'The block %s fails to render with these attributes.', 'leitvia-studio' ), $name ), __( 'Check the attributes against leitvia/list-blocks.', 'leitvia-studio' ) );
			}
		}
		return $findings;
	}

	/**
	 * Check leitvia/content-tokens: Token_Lint per block (no hex, px, !important or digit presets).
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $context Context.
	 * @return list<array<string, mixed>>
	 */
	public static function tokens( string $content, array $context ): array {
		if ( ! self::applies( $context ) ) {
			return array();
		}
		$findings = array();
		foreach ( self::walk( $content ) as [ $block, $id ] ) {
			$markup = serialize_block_attributes( (array) $block['attrs'] ) . self::html( $block );
			foreach ( Token_Lint::lint_markup( $markup ) as $lint ) {
				/* translators: 1: raw value, 2: lint rule. */
				$findings[] = self::finding( 'leitvia/content-tokens', 'error', $id, sprintf( __( 'Raw value "%1$s" (%2$s) instead of a design token.', 'leitvia-studio' ), $lint['match'], $lint['rule'] ), __( 'Use the presets from leitvia/get-tokens.', 'leitvia-studio' ) );
			}
		}
		return $findings;
	}

	/**
	 * Check leitvia/content-contrast: a block with textColor and backgroundColor uses a pair of the contract, or the two
	 * roles reach 4.5:1 in the active palette.
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $context Context.
	 * @return list<array<string, mixed>>
	 */
	public static function contrast( string $content, array $context ): array {
		if ( ! self::applies( $context ) ) {
			return array();
		}
		$pairs    = array();
		$findings = array();
		foreach ( leitvia_studio_contract()->pairs() as $pair ) {
			$pairs[ $pair['fg'] . '|' . $pair['bg'] ] = true;
		}
		$palette = null;
		foreach ( self::walk( $content ) as [ $block, $id ] ) {
			$fg = $block['attrs']['textColor'] ?? null;
			$bg = $block['attrs']['backgroundColor'] ?? null;
			if ( ! is_string( $fg ) || ! is_string( $bg ) || isset( $pairs[ $fg . '|' . $bg ] ) ) {
				continue;
			}
			$palette ??= (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
			$pair      = array(
				'fg'  => $fg,
				'bg'  => $bg,
				'min' => 4.5,
			);
			foreach ( Contrast::findings( $palette, array( $pair ) ) as $failure ) {
				$findings[] = self::finding( 'leitvia/content-contrast', $failure['severity'], $id, $failure['message'], __( 'Use a text and background pair from the contract (leitvia/get-contract).', 'leitvia-studio' ) );
			}
		}
		return $findings;
	}

	/**
	 * Check leitvia/page-structure (scope page): exactly one h1 in template plus content, and the landmarks header,
	 * main and footer in the template. Renders the template with do_blocks(), no HTTP request.
	 *
	 * @param string               $content Block markup.
	 * @param array<string, mixed> $context Context.
	 * @return list<array<string, mixed>>
	 */
	public static function page( string $content, array $context ): array {
		// WooCommerce renders product templates only inside the product loop; their h1 and landmarks are the theme's.
		$product  = in_array( (string) ( $context['post_type'] ?? '' ), Scope::COMMERCE_TYPES, true );
		$template = self::applies( $context ) && ! $product ? self::template( $context ) : null;
		if ( ! $template ) {
			return array();
		}
		$html  = self::render_page( $template, $content, (int) ( $context['post'] ?? 0 ) );
		$tags  = new \WP_HTML_Tag_Processor( $html );
		$found = array();
		while ( $tags->next_tag() ) {
			$found[ $tags->get_tag() ] = ( $found[ $tags->get_tag() ] ?? 0 ) + 1;
		}
		$findings = array();
		$h1       = $found['H1'] ?? 0;
		if ( 1 !== $h1 ) {
			/* translators: 1: number of h1 headings, 2: template slug. */
			$findings[] = self::finding( 'leitvia/page-structure', 'error', '', sprintf( __( 'The page has %1$d h1 headings with the template %2$s, it needs exactly one.', 'leitvia-studio' ), $h1, $template->slug ), $h1 ? __( 'Change the extra h1 headings to h2.', 'leitvia-studio' ) : __( 'Start the content with an h1, for example in the hero.', 'leitvia-studio' ) );
		}
		foreach ( array( 'HEADER', 'MAIN', 'FOOTER' ) as $landmark ) {
			if ( empty( $found[ $landmark ] ) ) {
				/* translators: 1: landmark element, 2: template slug. */
				$findings[] = self::finding( 'leitvia/page-structure', 'warning', '', sprintf( __( 'The template %2$s has no %1$s landmark.', 'leitvia-studio' ), strtolower( $landmark ), $template->slug ), __( 'A person should fix the template in the Site Editor.', 'leitvia-studio' ) );
			}
		}
		return $findings;
	}

	/**
	 * Block template a post of the context is shown with, null when the post type has no single view. A template in the
	 * context wins over the one saved for the post, for a post that is about to get it.
	 *
	 * @param array<string, mixed> $context Context.
	 */
	public static function template( array $context ): ?\WP_Block_Template {
		$type = (string) ( $context['post_type'] ?? '' );
		if ( ! is_post_type_viewable( $type ) || 'attachment' === $type ) {
			return null;
		}
		$custom = (string) ( $context['template'] ?? ( ! empty( $context['post'] ) ? get_page_template_slug( (int) $context['post'] ) : '' ) );
		$slugs  = 'page' === $type ? array( $custom, 'page', 'singular', 'index' ) : array( $custom, 'single-' . $type, 'single', 'singular', 'index' );
		foreach ( array_filter( $slugs ) as $slug ) {
			$template = get_block_template( get_stylesheet() . '//' . $slug );
			if ( $template && '' !== (string) $template->content ) {
				return $template;
			}
		}
		return null;
	}

	/**
	 * Whether the checks apply: block content of a post type with the editor.
	 *
	 * @param array<string, mixed> $context Context.
	 */
	private static function applies( array $context ): bool {
		return post_type_supports( (string) ( $context['post_type'] ?? '' ), 'editor' );
	}

	/**
	 * Whether the template shows the post title as h1.
	 *
	 * @param \WP_Block_Template|null $template Template.
	 */
	private static function title_is_h1( ?\WP_Block_Template $template ): bool {
		if ( ! $template ) {
			return false;
		}
		foreach ( self::walk( (string) $template->content ) as [ $block ] ) {
			if ( 'core/post-title' === $block['blockName'] && 1 === (int) ( $block['attrs']['level'] ?? 2 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Template markup with the post title as a plain heading and the content in place of the post content block,
	 * rendered. Navigation blocks stay out: they hold neither h1 nor these landmarks, and their fallback writes posts.
	 *
	 * @param \WP_Block_Template $template Template.
	 * @param string             $content  Block markup.
	 * @param int                $post_id  Post, 0 for a new one.
	 */
	private static function render_page( \WP_Block_Template $template, string $content, int $post_id ): string {
		$title   = $post_id ? get_the_title( $post_id ) : '';
		$replace = static function ( array $blocks ) use ( &$replace, $content, $title ): array {
			foreach ( $blocks as $i => $block ) {
				if ( 'core/post-title' === $block['blockName'] ) {
					$level        = max( 1, min( 6, (int) ( $block['attrs']['level'] ?? 2 ) ) );
					$blocks[ $i ] = self::raw( "<h{$level}>" . esc_html( '' !== $title ? $title : 'Title' ) . "</h{$level}>" );
				} elseif ( 'core/post-content' === $block['blockName'] ) {
					$blocks[ $i ] = self::raw( do_blocks( $content ) );
				} else {
					$blocks[ $i ]['innerBlocks'] = $replace( (array) $block['innerBlocks'] );
				}
			}
			return $blocks;
		};
		$skip    = static fn( $pre, array $block ) => 'core/navigation' === $block['blockName'] ? '' : $pre;
		add_filter( 'pre_render_block', $skip, 10, 2 );
		$html    = do_blocks( serialize_blocks( $replace( parse_blocks( (string) $template->content ) ) ) );
		remove_filter( 'pre_render_block', $skip, 10 );
		return $html;
	}

	/**
	 * A block of plain HTML.
	 *
	 * @param string $html HTML.
	 * @return array<string, mixed>
	 */
	private static function raw( string $html ): array {
		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/**
	 * Whether a block renders without an exception, warning or notice.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private static function renders( array $block ): bool {
		$ok = true;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Turns render warnings into a finding for this one call.
		set_error_handler(
			static function () use ( &$ok ): bool {
				$ok = false;
				return true;
			}
		);
		try {
			render_block( $block );
		} catch ( \Throwable $e ) {
			$ok = false;
		} finally {
			restore_error_handler();
		}
		return $ok;
	}

	/**
	 * Blocks in document order with their IDs (leitviaId or path ID), inner blocks included, whitespace left out.
	 *
	 * @param string $content Block markup.
	 * @return \Generator<array{0: array<string, mixed>, 1: string}>
	 */
	private static function walk( string $content ): \Generator {
		yield from self::walk_blocks( parse_blocks( $content ), 'p:' );
	}

	/**
	 * Recursive part of walk().
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                                  $path   Path prefix.
	 * @return \Generator<array{0: array<string, mixed>, 1: string}>
	 */
	private static function walk_blocks( array $blocks, string $path ): \Generator {
		foreach ( Block_Ids::blocks( $blocks ) as $index => $block ) {
			$id = Block_Ids::id( $block );
			yield array( $block, '' !== $id ? $id : $path . $index );
			yield from self::walk_blocks( (array) $block['innerBlocks'], $path . $index . '.' );
		}
	}

	/**
	 * Own markup of a block, without its inner blocks.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private static function html( array $block ): string {
		return implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
	}

	/**
	 * Whether markup has text or an image with alt text.
	 *
	 * @param string $html Markup.
	 */
	private static function has_text( string $html ): bool {
		if ( '' !== trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\u{a0}" ) ) {
			return true;
		}
		return (bool) preg_match( '#<img\b[^>]*\balt\s*=\s*(["\'])\s*[^"\'\s]#i', $html );
	}

	/**
	 * Whether an alt text is a file name: the image file with or without extension, or any name with an image extension.
	 *
	 * @param string $alt  Alt text, lowercase.
	 * @param string $file File name of the image, lowercase.
	 */
	private static function is_file_name( string $alt, string $file ): bool {
		if ( '' !== $file && in_array( $alt, array( $file, (string) pathinfo( $file, PATHINFO_FILENAME ) ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/^\S+\.(?:jpe?g|png|gif|webp|avif|svg|heic)$/', $alt );
	}

	/**
	 * One finding.
	 *
	 * @param string $check    Check name.
	 * @param string $severity error or warning.
	 * @param string $block    Block ID, '' for the page.
	 * @param string $message  What is wrong.
	 * @param string $fix      How to fix it.
	 * @return array{check: string, severity: string, block: string, message: string, fix: string}
	 */
	private static function finding( string $check, string $severity, string $block, string $message, string $fix ): array {
		return array(
			'check'    => $check,
			'severity' => $severity,
			'block'    => $block,
			'message'  => $message,
			'fix'      => $fix,
		);
	}
}
