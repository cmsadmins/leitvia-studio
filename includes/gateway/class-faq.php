<?php
/**
 * Questions and answers of existing blocks as a section of the pattern leitvia/content-faq.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the section of insert-section faq: a range of top-level blocks where each question is a heading, a paragraph
 * that starts with a bold question (the rest of the paragraph starts the answer) or a short paragraph of its own that
 * ends with a question mark, and the blocks up to the next question are its answer. Each pair becomes a details block
 * of the pattern, the first one open. The answers keep their blocks and markup, so code, bold, italics and links stay;
 * paragraphs and lists get the muted body text of the pattern. The summary keeps the inline formatting of the
 * question without links, since a link inside the summary would be a control inside a control. An id of a heading
 * moves to its details block, so links to it keep their target.
 */
final class Faq {

	/**
	 * The pattern the section is built from.
	 */
	public const PATTERN = 'leitvia/content-faq';

	/**
	 * Most characters of a paragraph that counts as question by its question mark alone.
	 */
	private const QUESTION_MAX = 200;

	/**
	 * Classes and attributes of the answer text in the pattern.
	 */
	private const ANSWER = array(
		'textColor'  => 'ink-muted',
		'fontSize'   => 'body',
		'fontFamily' => 'body',
	);

	/**
	 * Markup of the FAQ section.
	 *
	 * @param array<array<string, mixed>> $blocks  Parsed top-level blocks of the range, in order.
	 * @param string                      $heading Text of the h2 of the section.
	 * @param string                      $intro   Text below the heading, '' for none.
	 * @param array<string, mixed>        $target  Target for rejections.
	 * @return string|\WP_Error
	 */
	public static function section( array $blocks, string $heading, string $intro, array $target ) {
		$questions = array();
		$answers   = array();
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$question = self::question( $block );
			if ( $question ) {
				$questions[] = $question;
				$answers[]   = $question['rest'] ? array( $question['rest'] ) : array();
				continue;
			}
			if ( ! $questions ) {
				return Block_Edit::invalid( 'faq', __( 'The range has to start with a question: a heading, a paragraph that starts with a bold question or a short paragraph that ends with a question mark.', 'leitvia-studio' ), $target );
			}
			$answers[ count( $answers ) - 1 ][] = self::answer( $block );
		}
		foreach ( $questions as $index => $question ) {
			if ( ! $answers[ $index ] ) {
				/* translators: %s: text of the question. */
				return Block_Edit::invalid( 'faq', sprintf( __( 'The question "%s" has no answer in the range. Each question needs at least one block after it.', 'leitvia-studio' ), wp_strip_all_tags( $question['html'] ) ), $target );
			}
		}
		$pattern = \LeitviaStudio\Gateway\Abilities\Build::pattern( self::PATTERN );
		if ( is_wp_error( $pattern ) ) {
			return $pattern;
		}
		$section = self::named( parse_blocks( (string) $pattern['content'] ) )[0] ?? null;
		$grid    = $section ? self::named( (array) $section['innerBlocks'] )[0] ?? null : null;
		$columns = $grid ? self::named( (array) $grid['innerBlocks'] ) : array();
		$details = isset( $columns[1] ) ? self::named( (array) $columns[1]['innerBlocks'] ) : array();
		if ( ! $section || ! $grid || count( $details ) < 2 ) {
			return Rejection::make( 'leitvia_unknown_pattern', __( 'The pattern leitvia/content-faq is not the one of the theme Leitvia, so the questions cannot go into it.', 'leitvia-studio' ), 404, 'pattern', array( 'pattern' => self::PATTERN ), 'leitvia/list-patterns', 'never' );
		}
		$entries = array();
		foreach ( $questions as $index => $question ) {
			$entries[] = self::details( $details[ 0 === $index ? 0 : 1 ], $question['html'], $question['id'], $answers[ $index ] );
		}
		$columns[0] = self::intro( $columns[0], $heading, $intro );
		$columns[1] = self::with_inner( $columns[1], $entries );
		$section    = self::with_inner( $section, array( self::with_inner( $grid, $columns ) ) );
		return serialize_block( $section );
	}

	/**
	 * The question of a block, null when it is none: html (inline markup without links), id (of a heading, else '')
	 * and rest, an answer paragraph with the text after a bold question, null without one.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array{html: string, id: string, rest: array<string, mixed>|null}|null
	 */
	private static function question( array $block ): ?array {
		$html = trim( (string) $block['innerHTML'] );
		if ( 'core/heading' === $block['blockName'] && preg_match( '#^<h[1-6]\b([^>]*)>(.*)</h[1-6]>$#is', $html, $match ) ) {
			return array(
				'html' => self::inline( $match[2] ),
				'id'   => preg_match( '/\bid="([^"]+)"/', $match[1], $id ) ? $id[1] : '',
				'rest' => null,
			);
		}
		if ( 'core/paragraph' !== $block['blockName'] || ! preg_match( '#^<p\b[^>]*>(.*)</p>$#is', $html, $match ) ) {
			return null;
		}
		$inner = trim( $match[1] );
		if ( preg_match( '#^<(strong|b)>(.*?)</\1>(.*)$#is', $inner, $bold ) ) {
			$rest = trim( (string) preg_replace( '#^(\s|:|&nbsp;|<br\s*/?>)+#i', '', $bold[3] ) );
			if ( '' === $rest || str_ends_with( trim( wp_strip_all_tags( $bold[2] ) ), '?' ) ) {
				return array(
					'html' => self::inline( $bold[2] ),
					'id'   => '',
					'rest' => '' === $rest ? null : self::answer( parse_blocks( '<!-- wp:paragraph --><p>' . $rest . '</p><!-- /wp:paragraph -->' )[0] ),
				);
			}
		}
		$text = trim( wp_strip_all_tags( $inner ) );
		if ( str_ends_with( $text, '?' ) && mb_strlen( $text ) <= self::QUESTION_MAX ) {
			return array(
				'html' => self::inline( $inner ),
				'id'   => '',
				'rest' => null,
			);
		}
		return null;
	}

	/**
	 * Inline markup of a question for the summary: links become their text, line breaks spaces.
	 *
	 * @param string $html Inline markup.
	 */
	private static function inline( string $html ): string {
		return trim( (string) preg_replace( array( '#</?a\b[^>]*>#i', '#<br\s*/?>#i' ), array( '', ' ' ), $html ) );
	}

	/**
	 * An answer block with the muted body text of the pattern: paragraphs and lists get its color, font and size where
	 * they have none of their own; other blocks stay as they are.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	private static function answer( array $block ): array {
		if ( ! in_array( $block['blockName'], array( 'core/paragraph', 'core/list' ), true ) ) {
			return $block;
		}
		$add = array_diff_key( self::ANSWER, (array) $block['attrs'] );
		if ( ! $add ) {
			return $block;
		}
		// Attributes in the order of the editor (own attributes and style, then color, size, family), classes in the
		// order of the pattern (color, family, size).
		$block['attrs'] = (array) $block['attrs'] + $add;
		$names          = array();
		foreach ( array( 'textColor', 'fontFamily', 'fontSize' ) as $field ) {
			if ( isset( $add[ $field ] ) ) {
				$names[] = 'textColor' === $field ? 'has-ink-muted-color has-text-color' : 'has-body-' . ( 'fontSize' === $field ? 'font-size' : 'font-family' );
			}
		}
		$class              = static function ( string $html ) use ( $names ): string {
			$tags = new \WP_HTML_Tag_Processor( $html );
			if ( ! $tags->next_tag() ) {
				return $html;
			}
			foreach ( explode( ' ', implode( ' ', $names ) ) as $name ) {
				$tags->add_class( $name );
			}
			return $tags->get_updated_html();
		};
		$block['innerHTML'] = $class( (string) $block['innerHTML'] );
		if ( isset( $block['innerContent'][0] ) && is_string( $block['innerContent'][0] ) ) {
			$block['innerContent'][0] = $class( $block['innerContent'][0] );
		}
		return $block;
	}

	/**
	 * A details block of the pattern with the question as summary and the answer blocks inside; the id of a heading
	 * becomes its anchor.
	 *
	 * @param array<string, mixed>       $template Details block of the pattern.
	 * @param string                     $question Inline markup of the question.
	 * @param string                     $id       Id of the heading of the question, '' for none.
	 * @param list<array<string, mixed>> $answer   Answer blocks.
	 * @return array<string, mixed>
	 */
	private static function details( array $template, string $question, string $id, array $answer ): array {
		$open  = (string) ( $template['innerContent'][0] ?? '' );
		$close = (string) end( $template['innerContent'] );
		$open  = (string) preg_replace( '#<summary>.*?</summary>#s', '<summary>' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $question ) . '</summary>', $open, 1 );
		$type  = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/details' );
		if ( '' !== $id && $type && block_has_support( $type, 'anchor' ) ) {
			$tags = new \WP_HTML_Tag_Processor( $open );
			if ( $tags->next_tag( array( 'tag_name' => 'details' ) ) ) {
				$tags->set_attribute( 'id', $id );
				$open = $tags->get_updated_html();
			}
			// The anchor support comes before the style supports in the attributes of the editor.
			$attrs             = (array) $template['attrs'];
			$template['attrs'] = array_intersect_key( $attrs, array( 'showContent' => true ) ) + array( 'anchor' => $id ) + $attrs;
		}
		$template['innerBlocks']  = $answer;
		$template['innerContent'] = array_merge( array( $open ), array_fill( 0, count( $answer ), null ), array( $close ) );
		$template['innerHTML']    = $open . $close;
		return $template;
	}

	/**
	 * The first column of the pattern with the heading and the intro, or without the intro paragraph.
	 *
	 * @param array<string, mixed> $column  Column with the heading and the paragraph of the pattern.
	 * @param string               $heading Heading text.
	 * @param string               $intro   Intro text, '' for none.
	 * @return array<string, mixed>
	 */
	private static function intro( array $column, string $heading, string $intro ): array {
		$inner = array();
		foreach ( self::named( $column['innerBlocks'] ) as $block ) {
			$tag  = 'core/heading' === $block['blockName'] ? 'h2' : ( 'core/paragraph' === $block['blockName'] ? 'p' : '' );
			$text = 'h2' === $tag ? $heading : $intro;
			if ( '' === $tag || '' === $text ) {
				continue;
			}
			$html                  = (string) preg_replace( '#(<' . $tag . '\b[^>]*>).*?(</' . $tag . '>)#s', '${1}' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), esc_html( $text ) ) . '${2}', (string) $block['innerHTML'], 1 );
			$block['innerHTML']    = $html;
			$block['innerContent'] = array( $html );
			$inner[]               = $block;
		}
		return self::with_inner( $column, $inner );
	}

	/**
	 * A block with new inner blocks, between the first and the last string of its markup.
	 *
	 * @param array<string, mixed>       $block Parsed block.
	 * @param list<array<string, mixed>> $inner Inner blocks.
	 * @return array<string, mixed>
	 */
	private static function with_inner( array $block, array $inner ): array {
		$strings               = array_values( array_filter( (array) $block['innerContent'], 'is_string' ) );
		$open                  = (string) ( $strings[0] ?? '' );
		$close                 = (string) ( count( $strings ) > 1 ? end( $strings ) : '' );
		$block['innerBlocks']  = $inner;
		$block['innerContent'] = array_merge( array( $open ), array_fill( 0, count( $inner ), null ), array( $close ) );
		$block['innerHTML']    = $open . $close;
		return $block;
	}

	/**
	 * Blocks with a name, without the whitespace between them.
	 *
	 * @param array<array<string, mixed>> $blocks Parsed blocks.
	 * @return list<array<string, mixed>>
	 */
	private static function named( array $blocks ): array {
		return array_values( array_filter( $blocks, static fn( array $block ): bool => null !== $block['blockName'] ) );
	}
}
