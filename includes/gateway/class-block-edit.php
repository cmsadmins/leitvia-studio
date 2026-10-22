<?php
/**
 * Targeted changes to one block in block markup.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Changes one block found by leitviaId or path ID: attributes from the block catalog and the level of a heading, the
 * text of paragraphs, headings, list items and buttons, the citation of a quote, the summary of a details block, the
 * link of a button or the one link in a text, and the image of an image or cover block. Static markup is rebuilt from
 * the new values with the HTML API on the block's own markup, never patched as a string (the HTML API cannot rename a
 * tag, so the level of a heading swaps the tag name of its one element); inner blocks and their placeholders in
 * innerContent stay as they are. What cannot be rebuilt safely is rejected with
 * leitvia_static_markup.
 */
final class Block_Edit {

	/**
	 * Blocks whose text can change, with the query for the element that holds the text.
	 */
	public const TEXT_BLOCKS = array(
		'core/paragraph' => array(),
		'core/heading'   => array(),
		'core/list-item' => array(),
		'core/button'    => array( 'class_name' => 'wp-block-button__link' ),
		'core/quote'     => array( 'tag_name' => 'cite' ),
		'core/details'   => array( 'tag_name' => 'summary' ),
	);

	/**
	 * Blocks whose image can change.
	 */
	public const IMAGE_BLOCKS = array( 'core/image', 'core/cover' );

	/**
	 * Attributes of core blocks outside the block catalog that may change, as JSON schema; their markup is rebuilt
	 * from the new value. The level of a heading is the tag name of its element.
	 */
	public const CORE_ATTRIBUTES = array(
		'core/heading' => array(
			'level' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 6,
			),
		),
	);

	/**
	 * Content with one block changed.
	 *
	 * @param string               $content Block markup.
	 * @param string               $id      leitviaId or path ID such as p:0.2.1.
	 * @param array<string, mixed> $change  attributes, text, url and image (id, alt, decorative); each optional.
	 * @param array<string, mixed> $target  Target for rejections, for example array( 'post' => 12 ).
	 * @return string|\WP_Error
	 */
	public static function apply( string $content, string $id, array $change, array $target = array() ) {
		$found  = false;
		$error  = null;
		$blocks = self::map(
			parse_blocks( $content ),
			$id,
			'p:',
			static function ( array $block, string $path ) use ( $change, $target, &$error ): array {
				$changed = self::change( $block, $change, $target + array( 'block' => '' !== Block_Ids::id( $block ) ? Block_Ids::id( $block ) : $path ) );
				if ( is_wp_error( $changed ) ) {
					$error = $changed;
					return $block;
				}
				return $changed;
			},
			$found
		);
		if ( ! $found ) {
			return Rejection::make( 'leitvia_not_found', __( 'There is no block with this ID. Read the outline for the current IDs.', 'leitvia-studio' ), 404, 'block', $target + array( 'block' => $id ), 'leitvia/get-page-outline' );
		}
		return $error ?? serialize_blocks( $blocks );
	}

	/**
	 * Replaces the block with the ID by the result of the callback; paths count without whitespace blocks, as in
	 * Block_Ids::blocks(). Inner block lists keep their length, so the placeholders in innerContent stay in sync.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @param string       $id     leitviaId or path ID.
	 * @param string       $path   Path prefix.
	 * @param callable     $edit   Gets the block and its path ID, returns the new block.
	 * @param bool         $found  Set to true once the block is found.
	 * @return array<mixed>
	 */
	private static function map( array $blocks, string $id, string $path, callable $edit, bool &$found ): array {
		$index = 0;
		foreach ( $blocks as $key => $block ) {
			if ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) ) {
				continue;
			}
			$here = $path . ( $index++ );
			if ( Block_Ids::id( $block ) === $id || $here === $id ) {
				$found          = true;
				$blocks[ $key ] = $edit( $block, $here );
				break;
			}
			$blocks[ $key ]['innerBlocks'] = self::map( (array) $block['innerBlocks'], $id, $here . '.', $edit, $found );
			if ( $found ) {
				break;
			}
		}
		return $blocks;
	}

	/**
	 * One block with the change applied.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param array<string, mixed> $change Change.
	 * @param array<string, mixed> $target Target with the block ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function change( array $block, array $change, array $target ) {
		$name    = (string) $block['blockName'];
		$rebuild = Rejection::make( 'leitvia_static_markup', __( 'This change needs markup that cannot be rebuilt safely. Insert the pattern again with leitvia/compose-page, or change a block that supports it.', 'leitvia-studio' ), 422, 'static_markup', $target, 'leitvia/compose-page' );
		if ( isset( $change['attributes'] ) ) {
			$block = self::attributes( $block, (array) $change['attributes'], $target, $rebuild );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		$link = isset( $change['url'] ) && 'core/button' !== $name;
		if ( isset( $change['text'] ) || $link ) {
			// The link of a button is the text element itself; it follows below.
			$block = self::text( $block, $link ? $change : array_diff_key( $change, array( 'url' => true ) ), $target, $rebuild );
			if ( is_wp_error( $block ) ) {
				return $block;
			}
		}
		$html = is_string( $block['innerContent'][0] ?? null ) ? $block['innerContent'][0] : '';
		if ( isset( $change['url'] ) && ! $link ) {
			$url  = self::url( (string) $change['url'] );
			$tags = self::processor( $html );
			if ( ! $tags->next_tag( self::TEXT_BLOCKS[ $name ] ) || 'A' !== $tags->get_tag() ) {
				return $rebuild;
			}
			if ( '' === $url ) {
				return self::invalid( 'url', __( 'The link is not a valid URL.', 'leitvia-studio' ), $target );
			}
			$tags->set_attribute( 'href', $url );
			$html = $tags->get_updated_html();
		}
		if ( isset( $change['image'] ) ) {
			if ( ! in_array( $name, self::IMAGE_BLOCKS, true ) ) {
				return $rebuild;
			}
			$image = self::image( $block, $html, (array) $change['image'], $target, $rebuild );
			if ( is_wp_error( $image ) ) {
				return $image;
			}
			[ $block, $html ] = $image;
		}
		if ( '' !== $html ) {
			$block['innerContent'][0] = $html;
		}
		$block['innerHTML'] = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
		return $block;
	}

	/**
	 * Block with new text in its element of TEXT_BLOCKS, found in any part of its own markup (the citation of a quote
	 * follows its inner blocks). One link in the element stays: around link_text, else around its old text when the
	 * new text still contains it; url changes where it points. Text that would drop the link is refused.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param array<string, mixed> $change  text, link_text and url.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function text( array $block, array $change, array $target, \WP_Error $rebuild ) {
		$query = self::TEXT_BLOCKS[ (string) $block['blockName'] ] ?? null;
		foreach ( (array) $block['innerContent'] as $key => $html ) {
			$tags  = is_string( $html ) && null !== $query ? self::processor( $html ) : null;
			$range = $tags && $tags->next_tag( $query ) ? $tags->inner_range() : null;
			if ( ! $range ) {
				continue;
			}
			$inner = self::inner( substr( $html, $range[0], $range[1] ), $change, $target, $rebuild );
			if ( is_wp_error( $inner ) ) {
				return $inner;
			}
			$block['innerContent'][ $key ] = substr_replace( $html, $inner, $range[0], $range[1] );
			return $block;
		}
		return $rebuild;
	}

	/**
	 * New content of a text element: the escaped text, with its one link around the linked part; the opening tag of the
	 * link stays as it was, only href changes with url. Plain text may replace a sample link to "#".
	 *
	 * @param string               $inner   Old content of the element.
	 * @param array<string, mixed> $change  text, link_text and url.
	 * @param array<string, mixed> $target  Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return string|\WP_Error
	 */
	private static function inner( string $inner, array $change, array $target, \WP_Error $rebuild ) {
		$plain = static fn( string $html ): string => html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$tags  = self::processor( $inner );
		$links = 0;
		$open  = '';
		$old   = '';
		while ( $tags->next_tag( 'a' ) ) {
			$range = 1 === ++$links ? $tags->inner_range() : null;
			if ( $range ) {
				$open = substr( $inner, $range[2], $range[0] - $range[2] );
				$old  = $plain( substr( $inner, $range[0], $range[1] ) );
			}
		}
		$text = isset( $change['text'] ) ? (string) $change['text'] : $plain( $inner );
		if ( 0 === $links ) {
			return isset( $change['url'] ) ? $rebuild : esc_html( $text );
		}
		if ( 1 !== $links || '' === $open ) {
			return $rebuild;
		}
		$label = (string) ( $change['link_text'] ?? $old );
		$at    = '' !== $label ? strpos( $text, $label ) : false;
		$href  = new \WP_HTML_Tag_Processor( $open );
		$href->next_tag();
		// A sample link to "#" leads nowhere; plain text may replace it.
		if ( false === $at && ! isset( $change['link_text'] ) && ! isset( $change['url'] ) && in_array( trim( (string) $href->get_attribute( 'href' ) ), array( '', '#' ), true ) ) {
			return esc_html( $text );
		}
		if ( false === $at ) {
			return self::invalid( 'link_text', __( 'The text has a link that the new text would drop. Send link_text, the part of the text that stays linked, and url for where it points.', 'leitvia-studio' ), $target );
		}
		if ( isset( $change['url'] ) ) {
			$url = self::url( (string) $change['url'] );
			if ( '' === $url ) {
				return self::invalid( 'url', __( 'The link is not a valid URL.', 'leitvia-studio' ), $target );
			}
			$tag = new \WP_HTML_Tag_Processor( $open );
			$tag->next_tag();
			$tag->set_attribute( 'href', $url );
			$open = $tag->get_updated_html();
		}
		return esc_html( substr( $text, 0, $at ) ) . $open . esc_html( $label ) . '</a>' . esc_html( substr( $text, $at + strlen( $label ) ) );
	}

	/**
	 * Block with new attributes. Allowed are attributes of the block catalog that live in the block comment, on blocks
	 * that render on the server or save no markup of their own, and the CORE_ATTRIBUTES whose markup is rebuilt here;
	 * anything else would leave the saved markup behind.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param array<string, mixed> $attrs  New attribute values.
	 * @param array<string, mixed> $target Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function attributes( array $block, array $attrs, array $target, \WP_Error $rebuild ) {
		$name  = (string) $block['blockName'];
		$core  = self::CORE_ATTRIBUTES[ $name ] ?? null;
		$entry = $core ? array( 'attributes' => array( 'properties' => $core ) ) : array_column( \LeitviaStudio\Blocks\Catalog::get(), null, 'name' )[ $name ] ?? null;
		if ( ! $entry ) {
			return self::invalid( 'attributes', __( 'This block is not in the block catalog; its attributes cannot be set.', 'leitvia-studio' ), $target, 'leitvia/list-blocks' );
		}
		foreach ( $attrs as $key => $value ) {
			$schema = $entry['attributes']['properties'][ $key ] ?? null;
			if ( ! is_array( $schema ) ) {
				/* translators: %s: attribute name. */
				return self::invalid( 'attributes', sprintf( __( 'The block has no attribute %s in the catalog.', 'leitvia-studio' ), $key ), $target, 'leitvia/list-blocks' );
			}
			if ( isset( $schema['source'] ) ) {
				return $rebuild;
			}
			$valid = rest_validate_value_from_schema( $value, array_diff_key( $schema, array( 'default' => true ) ), (string) $key );
			if ( is_wp_error( $valid ) ) {
				return self::invalid( 'attributes', $valid->get_error_message(), $target, 'leitvia/list-blocks' );
			}
		}
		if ( $core ) {
			return isset( $attrs['level'] ) ? self::level( $block, (int) $attrs['level'], $rebuild ) : $block;
		}
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$own  = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
		if ( ! $type || ( ! $type->is_dynamic() && '' !== trim( $own ) ) ) {
			return $rebuild;
		}
		$block['attrs'] = $attrs + (array) $block['attrs'];
		return $block;
	}

	/**
	 * Heading with a new level: the tag name of its element and the attribute level, left out at 2 as the editor saves
	 * it.
	 *
	 * @param array<string, mixed> $block   Parsed heading block.
	 * @param int                  $level   Level 1 to 6.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function level( array $block, int $level, \WP_Error $rebuild ) {
		$html = (string) ( $block['innerContent'][0] ?? '' );
		$new  = preg_replace( '#^(\s*<h)[1-6](\b.*</h)[1-6](>\s*)$#s', '${1}' . $level . '${2}' . $level . '${3}', $html, 1, $count );
		if ( 1 !== count( (array) $block['innerContent'] ) || 1 !== $count ) {
			return $rebuild;
		}
		$block['innerContent'][0] = $new;
		unset( $block['attrs']['level'] );
		$block['attrs'] += 2 === $level ? array() : array( 'level' => $level );
		return $block;
	}

	/**
	 * Block and its own markup with a new image from the media library: attachment ID, URL, alt text and the class
	 * wp-image-<ID> as the editor saves them; metadata.leitviaDecorative marks an image with empty alt on purpose.
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param string               $html   Own markup before the inner blocks.
	 * @param array<string, mixed> $image  id, alt, decorative.
	 * @param array<string, mixed> $target Target.
	 * @param \WP_Error            $rebuild Rejection for static markup.
	 * @return array{0: array<string, mixed>, 1: string}|\WP_Error
	 */
	private static function image( array $block, string $html, array $image, array $target, \WP_Error $rebuild ) {
		$id         = (int) ( $image['id'] ?? 0 );
		$decorative = ! empty( $image['decorative'] );
		$alt        = $decorative ? '' : trim( (string) ( $image['alt'] ?? '' ) );
		if ( ! wp_attachment_is_image( $id ) || ! Scope::readable( $id ) ) {
			return self::invalid( 'image', __( 'The image is not in the media library. Add it with leitvia/add-media first.', 'leitvia-studio' ), $target, 'leitvia/add-media' );
		}
		if ( '' === $alt && ! $decorative ) {
			return self::invalid( 'alt_text', __( 'Give the image an alt text that describes it, or mark it as decorative.', 'leitvia-studio' ), $target );
		}
		$cover = 'core/cover' === $block['blockName'];
		$url   = $cover ? wp_get_attachment_url( $id ) : wp_get_attachment_image_url( $id, (string) ( $block['attrs']['sizeSlug'] ?? 'full' ) );
		$tags  = self::processor( $html );
		if ( ! $url || ! $tags->next_tag( 'img' ) ) {
			return $rebuild;
		}
		foreach ( $tags->class_list() as $class ) {
			if ( preg_match( '/^wp-image-\d+$/', $class ) ) {
				$tags->remove_class( $class );
			}
		}
		$tags->add_class( 'wp-image-' . $id );
		$tags->set_attribute( 'src', $url );
		$tags->set_attribute( 'alt', $alt );
		$attrs       = array( 'id' => $id ) + (array) $block['attrs'];
		$attrs['id'] = $id;
		if ( $cover ) {
			// The cover keeps url and alt in the comment; alt is left out at its default, as the editor does.
			$attrs['url'] = $url;
			unset( $attrs['alt'] );
			$attrs += '' !== $alt ? array( 'alt' => $alt ) : array();
		}
		$meta = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
		unset( $meta['leitviaDecorative'] );
		$meta += $decorative ? array( 'leitviaDecorative' => true ) : array();
		unset( $attrs['metadata'] );
		$attrs         += $meta ? array( 'metadata' => $meta ) : array();
		$block['attrs'] = $attrs;
		return array( $block, $tags->get_updated_html() );
	}

	/**
	 * A link sent by an agent or a recipe, sanitized. A root-relative path such as /services/ is relative to the site:
	 * on a site below a path (a subdirectory network, an installation in a folder) it gets that path in front, unless
	 * it starts with it already. Empty when the URL is not valid.
	 *
	 * @param string $url URL.
	 */
	public static function url( string $url ): string {
		$url  = esc_url_raw( trim( $url ) );
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' === $home || ! str_starts_with( $url, '/' ) || str_starts_with( $url, '//' ) || str_starts_with( $url . '/', $home . '/' ) ) {
			return $url;
		}
		return $home . $url;
	}

	/**
	 * Rejection leitvia_invalid_input.
	 *
	 * @param string               $reason       Reason.
	 * @param string               $message      Message with the fix.
	 * @param array<string, mixed> $target       Target.
	 * @param string               $next_ability Next ability.
	 */
	public static function invalid( string $reason, string $message, array $target = array(), string $next_ability = '' ): \WP_Error {
		return Rejection::make( 'leitvia_invalid_input', $message, 422, $reason, $target, $next_ability );
	}

	/**
	 * A tag processor that can tell where the content of the current element lies.
	 *
	 * @param string $html Markup.
	 * @return mixed A WP_HTML_Tag_Processor with inner_range(); an anonymous class has no name to declare.
	 */
	private static function processor( string $html ) {
		return new class( $html ) extends \WP_HTML_Tag_Processor {

			/**
			 * Byte offset and length of everything between the current opener and its closer, and the offset of the
			 * opener, found with bookmarks as core's directives processor does; the cursor ends on the closer.
			 *
			 * @return array{0: int, 1: int, 2: int}|null Null when the cursor is not on an opener or the closer is not
			 *                                            in this markup.
			 */
			public function inner_range(): ?array {
				$tag = $this->get_tag();
				if ( null === $tag || $this->is_tag_closer() || ! $this->set_bookmark( 'leitvia-open' ) ) {
					return null;
				}
				$depth = 1;
				while ( $depth && $this->next_tag(
					array(
						'tag_name'    => $tag,
						'tag_closers' => 'visit',
					)
				) ) {
					$depth += $this->is_tag_closer() ? -1 : 1;
				}
				if ( $depth || ! $this->set_bookmark( 'leitvia-close' ) ) {
					return null;
				}
				$start = $this->bookmarks['leitvia-open']->start + $this->bookmarks['leitvia-open']->length;
				return array( $start, $this->bookmarks['leitvia-close']->start - $start, $this->bookmarks['leitvia-open']->start );
			}
		};
	}
}
