<?php
/**
 * Form error summary: translated headings and the summary of errors the server already reported.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Script modules cannot load translations here, so the summary carries its headings in data-titles: one entry per
 * number of errors from 1 to 20, %d stands for the number. When the wrapped form comes back from the server with
 * fields marked aria-invalid="true" (Gravity Forms does this for its field errors), the summary is filled and shown
 * right away: it is there on the first paint, so nothing moves when the view module takes it over, and it works
 * without JavaScript.
 */
final class Form_Errors {

	/**
	 * Runs on render_block_leitvia/form-errors.
	 *
	 * @param string $html Block markup with the rendered inner blocks.
	 */
	public static function render( string $html ): string {
		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( array( 'class_name' => 'wp-block-leitvia-form-errors__summary' ) ) ) {
			return $html;
		}
		$titles = array( __( 'There is a problem', 'leitvia-studio' ) );
		for ( $n = 2; $n <= 20; $n++ ) {
			/* translators: Heading of the error summary of a form. %d: number of errors, 2 or more. */
			$titles[] = _n( 'There is %d problem', 'There are %d problems', $n, 'leitvia-studio' );
		}
		$tags->set_attribute( 'data-titles', (string) wp_json_encode( $titles ) );
		$errors = self::server_errors( $html );
		if ( ! $errors ) {
			return $tags->get_updated_html();
		}
		$title_id = wp_unique_id( 'wp-block-leitvia-form-errors-title-' );
		$tags->remove_attribute( 'hidden' );
		$tags->set_attribute( 'aria-labelledby', $title_id );
		$items = '';
		foreach ( $errors as $id => $text ) {
			$items .= sprintf( '<li><a href="#%s">%s</a></li>', esc_attr( $id ), esc_html( $text ) );
		}
		$title = str_replace( '%d', (string) count( $errors ), $titles[ min( count( $errors ), 20 ) - 1 ] );
		$html  = (string) preg_replace(
			'#<h2 class="wp-block-leitvia-form-errors__title"></h2>#',
			sprintf( '<h2 class="wp-block-leitvia-form-errors__title" id="%s">%s</h2>', esc_attr( $title_id ), esc_html( $title ) ),
			$tags->get_updated_html(),
			1
		);
		return (string) preg_replace( '#<ul class="wp-block-leitvia-form-errors__list"></ul>#', '<ul class="wp-block-leitvia-form-errors__list">' . $items . '</ul>', $html, 1 );
	}

	/**
	 * Fields with an id and aria-invalid="true", each as "Name: message" like the view module writes it. The name is
	 * the text of label[for] without required markers, the message the text of the element named by
	 * aria-errormessage or by the first id in aria-describedby.
	 *
	 * @param string $html Block markup.
	 * @return array<string, string> Field id => link text, in document order.
	 */
	private static function server_errors( string $html ): array {
		$proc = str_contains( $html, 'aria-invalid' ) ? \WP_HTML_Processor::create_fragment( $html ) : null;
		if ( null === $proc ) {
			return array();
		}
		$fields = array(); // Field id => array( message element id, fallback name ).
		$texts  = array(); // "label:<for>" or "id:<id>" => text.
		$open   = array(); // Keys of $texts still collecting => depth of their element.
		$skip   = 0;       // Depth of the required marker in a label whose text is left out, 0 for none.
		while ( $proc->next_token() ) {
			$depth = $proc->get_current_depth();
			if ( '#text' === $proc->get_token_type() ) {
				foreach ( $skip ? array() : array_keys( $open ) as $key ) {
					$texts[ $key ] = ( $texts[ $key ] ?? '' ) . $proc->get_modifiable_text();
				}
				continue;
			}
			if ( '#tag' !== $proc->get_token_type() ) {
				continue;
			}
			if ( $proc->is_tag_closer() ) {
				$open = array_filter( $open, static fn( int $d ): bool => $d <= $depth );
				$skip = $skip > $depth ? 0 : $skip;
				continue;
			}
			$id = $proc->get_attribute( 'id' );
			if ( in_array( $proc->get_tag(), array( 'INPUT', 'SELECT', 'TEXTAREA' ), true ) ) {
				if ( is_string( $id ) && 'true' === $proc->get_attribute( 'aria-invalid' ) ) {
					$ref           = $proc->get_attribute( 'aria-errormessage' );
					$ref           = is_string( $ref ) && '' !== $ref ? $ref : strtok( (string) $proc->get_attribute( 'aria-describedby' ), " \t\n" );
					$name          = array_filter( array( $proc->get_attribute( 'aria-label' ), $proc->get_attribute( 'placeholder' ), $proc->get_attribute( 'name' ) ), 'is_string' );
					$fields[ $id ] = array( is_string( $ref ) ? $ref : '', (string) reset( $name ) );
				}
				continue;
			}
			if ( ! $proc->expects_closer() ) {
				continue;
			}
			$in_label = (bool) preg_grep( '/^label:/', array_keys( $open ) );
			if ( ! $skip && $in_label && str_contains( (string) $proc->get_attribute( 'class' ), 'required' ) ) {
				$skip = $depth;
				continue;
			}
			$for = $proc->get_attribute( 'for' );
			if ( 'LABEL' === $proc->get_tag() && is_string( $for ) ) {
				$open[ 'label:' . $for ] = $depth;
			}
			if ( is_string( $id ) ) {
				$open[ 'id:' . $id ] = $depth;
			}
		}
		$clean  = static fn( string $text ): string => rtrim( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), ' *' );
		$errors = array();
		foreach ( $fields as $id => list( $ref, $fallback ) ) {
			$name          = $clean( $texts[ 'label:' . $id ] ?? '' );
			$errors[ $id ] = implode( ': ', array_filter( array( '' !== $name ? $name : $fallback, $clean( $texts[ 'id:' . $ref ] ?? '' ) ) ) );
		}
		return $errors;
	}
}
