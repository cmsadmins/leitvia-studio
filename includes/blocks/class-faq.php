<?php
/**
 * FAQ: questions as core/details blocks, with FAQPage structured data.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Reads question (summary text) and answer (the rest of the details element) from the rendered block and adds them to
 * one FAQPage node of Schema_Nodes. Google allows one FAQPage per page, so the FAQ blocks of a page share a node
 * (merge(), on leitvia_studio_schema_nodes). With "one answer open at a time" the details elements of the block get a
 * shared name, which makes the browser close the others natively, also without JavaScript.
 */
final class Faq {

	/**
	 * Tags Google reads in an answer; everything else is stripped, its text stays.
	 */
	private const ANSWER_TAGS = array( 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'ol', 'ul', 'li', 'a', 'p', 'div', 'b', 'strong', 'i', 'em' );

	/**
	 * Runs on render_block_leitvia/faq.
	 *
	 * @param string               $content Rendered block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function render( string $content, array $block ): string {
		$attrs     = (array) ( $block['attrs'] ?? array() );
		$exclusive = ! empty( $attrs['exclusive'] );
		$schema    = false !== ( $attrs['schema'] ?? true ) && ( is_singular() || is_front_page() );
		$proc      = $exclusive || $schema ? \WP_HTML_Processor::create_fragment( $content ) : null;
		if ( null === $proc ) {
			return $content;
		}
		$name     = wp_unique_id( 'leitvia-faq-' );
		$entries  = array();
		$question = '';    // Text of the current summary.
		$answer   = '';    // HTML of the current answer.
		$nested   = 0;     // Open details elements, 1 inside a question of this block.
		$in_q     = false; // Inside the summary of a question.
		while ( $proc->next_token() ) {
			$type  = $proc->get_token_type();
			$tag   = '#tag' === $type ? $proc->get_tag() : '';
			$close = '#tag' === $type && $proc->is_tag_closer();
			if ( 'DETAILS' === $tag ) {
				$nested += $close ? -1 : 1;
				if ( ! $close && 1 === $nested ) {
					$question = '';
					$answer   = '';
					if ( $exclusive ) {
						$proc->set_attribute( 'name', $name );
					}
				} elseif ( 0 === $nested ) {
					$entries[] = array( $question, $answer );
				} elseif ( $nested > 0 ) {
					// Details inside an answer belong to the answer.
					$answer .= $proc->serialize_token();
				}
				continue;
			}
			if ( $nested < 1 ) {
				continue;
			}
			if ( 'SUMMARY' === $tag && 1 === $nested ) {
				$in_q = ! $close;
				continue;
			}
			if ( $in_q ) {
				$question .= '#text' === $type ? $proc->get_modifiable_text() : '';
			} elseif ( '#text' === $type || ( '#tag' === $type && ! in_array( $tag, array( 'SCRIPT', 'STYLE', 'TEMPLATE' ), true ) ) ) {
				$answer .= $proc->serialize_token();
			}
		}
		$html = $proc->get_updated_html();
		// A parser that gave up has not seen every question: no node is better than an incomplete one.
		if ( $schema && null === $proc->get_last_error() ) {
			$questions = self::questions( $entries );
			if ( $questions ) {
				Schema_Nodes::add(
					array(
						'@type'      => 'FAQPage',
						'mainEntity' => $questions,
					)
				);
			}
		}
		return $html;
	}

	/**
	 * Question nodes; entries without question or answer text are left out.
	 *
	 * @param list<array{0: string, 1: string}> $entries Question text and answer HTML.
	 * @return list<array<string, mixed>>
	 */
	public static function questions( array $entries ): array {
		$allowed      = array_fill_keys( self::ANSWER_TAGS, array() );
		$allowed['a'] = array( 'href' => true );
		$questions    = array();
		foreach ( $entries as list( $question, $answer ) ) {
			$question = trim( (string) preg_replace( '/\s+/u', ' ', $question ) );
			$answer   = (string) preg_replace( '/\s+/u', ' ', wp_kses( $answer, $allowed ) );
			// Space around block tags carries no meaning; around inline tags (a, b, em) it stays.
			$answer = trim( (string) preg_replace( '#\s*(</?(?:p|div|ul|ol|li|h[2-6])>|<br ?/?>)\s*#', '$1', $answer ) );
			if ( '' === $question || '' === trim( wp_strip_all_tags( $answer ) ) ) {
				continue;
			}
			$questions[] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);
		}
		return $questions;
	}

	/**
	 * Joins all FAQPage nodes into the first one, without repeating a question (a block rendered twice on a request,
	 * for example in an excerpt and the content). Runs on leitvia_studio_schema_nodes.
	 *
	 * @param list<array<string, mixed>> $nodes Nodes.
	 * @return list<array<string, mixed>>
	 */
	public static function merge( array $nodes ): array {
		$first = null;
		foreach ( $nodes as $i => $node ) {
			if ( 'FAQPage' !== ( $node['@type'] ?? '' ) ) {
				continue;
			}
			if ( null === $first ) {
				$first = $i;
				continue;
			}
			$nodes[ $first ]['mainEntity'] = array_merge( (array) ( $nodes[ $first ]['mainEntity'] ?? array() ), (array) ( $node['mainEntity'] ?? array() ) );
			unset( $nodes[ $i ] );
		}
		if ( null !== $first ) {
			$seen                          = array();
			$nodes[ $first ]['mainEntity'] = array_values(
				array_filter(
					(array) ( $nodes[ $first ]['mainEntity'] ?? array() ),
					static function ( $question ) use ( &$seen ): bool {
						$key = is_array( $question ) ? (string) ( $question['name'] ?? '' ) : '';
						if ( isset( $seen[ $key ] ) ) {
							return false;
						}
						$seen[ $key ] = true;
						return true;
					}
				)
			);
		}
		return array_values( $nodes );
	}
}
