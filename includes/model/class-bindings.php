<?php
/**
 * Block bindings sources for the content model.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Sources leitvia/meta, leitvia/setting and leitvia/relation. Only keys the content model declares are reachable, and
 * only from posts the visitor may read. Every value is a string or null; null makes core keep the saved block content.
 */
final class Bindings {

	/**
	 * Registers the sources. Runs on init.
	 */
	public static function register(): void {
		$context = array( 'postId', 'postType' );
		register_block_bindings_source(
			'leitvia/meta',
			array(
				'label'              => __( 'Content model field', 'leitvia-studio' ),
				'get_value_callback' => array( self::class, 'meta_value' ),
				'uses_context'       => $context,
			)
		);
		register_block_bindings_source(
			'leitvia/setting',
			array(
				'label'              => __( 'Site setting', 'leitvia-studio' ),
				'get_value_callback' => array( self::class, 'setting_value' ),
			)
		);
		register_block_bindings_source(
			'leitvia/relation',
			array(
				'label'              => __( 'Related entry', 'leitvia-studio' ),
				'get_value_callback' => array( self::class, 'relation_value' ),
				'uses_context'       => $context,
			)
		);
	}

	/**
	 * Value of a declared field of the current post. The arguments prefix and suffix put text around a value that is
	 * not empty, such as a currency ("45 EUR"); a URL gets no affixes.
	 *
	 * @param array<string, mixed> $args      Source arguments: key, prefix, suffix.
	 * @param \WP_Block            $block     Block instance.
	 * @param string               $attribute Bound attribute.
	 */
	public static function meta_value( array $args, \WP_Block $block, string $attribute ): ?string {
		$post  = self::readable_post( (int) ( $block->context['postId'] ?? 0 ) );
		$value = $post ? self::field_value( $post, (string) ( $args['key'] ?? '' ), $block, $attribute ) : null;
		if ( null === $value || '' === $value || 'url' === $attribute ) {
			return $value;
		}
		return self::text( (string) ( $args['prefix'] ?? '' ), $block, $attribute ) . $value . self::text( (string) ( $args['suffix'] ?? '' ), $block, $attribute );
	}

	/**
	 * Value of a declared site setting marked as bindable. Other settings stay private, also to the editor.
	 *
	 * @param array<string, mixed> $args      Source arguments: key.
	 * @param \WP_Block            $block     Block instance.
	 * @param string               $attribute Bound attribute.
	 */
	public static function setting_value( array $args, \WP_Block $block, string $attribute ): ?string {
		$key   = (string) ( $args['key'] ?? '' );
		$field = Model::current()['settings'][ $key ] ?? null;
		if ( ! is_array( $field ) || empty( $field['bindable'] ) ) {
			return null;
		}
		return self::format( $field, get_option( Registrar::SETTING_PREFIX . $key, null ), $block, $attribute );
	}

	/**
	 * Title, permalink, excerpt or declared field of a related post.
	 *
	 * @param array<string, mixed> $args      Source arguments: relation, field, index (default 0).
	 * @param \WP_Block            $block     Block instance.
	 * @param string               $attribute Bound attribute.
	 */
	public static function relation_value( array $args, \WP_Block $block, string $attribute ): ?string {
		$key      = (string) ( $args['relation'] ?? '' );
		$relation = Model::current()['relations'][ $key ] ?? null;
		$post     = self::readable_post( (int) ( $block->context['postId'] ?? 0 ) );
		if ( ! is_array( $relation ) || ! $post || $relation['from'] !== $post->post_type ) {
			return null;
		}
		$ids    = array_values( (array) get_post_meta( $post->ID, $key, true ) );
		$target = self::readable_post( (int) ( $ids[ max( 0, (int) ( $args['index'] ?? 0 ) ) ] ?? 0 ), false );
		if ( ! $target || $relation['to'] !== $target->post_type ) {
			return null;
		}
		$field = (string) ( $args['field'] ?? 'title' );
		switch ( $field ) {
			case 'title':
				return self::text( get_the_title( $target ), $block, $attribute, true );
			case 'url':
				return self::text( (string) get_permalink( $target ), $block, $attribute );
			case 'excerpt':
				return self::text( get_the_excerpt( $target ), $block, $attribute, true );
			default:
				return self::field_value( $target, $field, $block, $attribute );
		}
	}

	/**
	 * What the editor side of the sources needs: declared fields by post type, relations, and the bindable site
	 * settings with their stored value, which the sources show on the front end anyway. Null when the model declares
	 * nothing to bind.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function editor_data(): ?array {
		$model      = Model::current();
		$post_types = array();
		foreach ( Registrar::post_types() as $post_type ) {
			$fields = self::fields( $post_type );
			if ( $fields && Registrar::owns_post_type( $post_type ) ) {
				$post_types[ $post_type ] = array(
					// Names fields that several types declare in the field list of templates.
					'label'  => wp_specialchars_decode( (string) get_post_type_object( $post_type )?->labels->singular_name, ENT_QUOTES ),
					'fields' => $fields,
				);
			}
		}
		$settings = array();
		foreach ( array_filter( (array) ( $model['settings'] ?? array() ), static fn( $field ): bool => ! empty( $field['bindable'] ) ) as $key => $field ) {
			$settings[ $key ] = $field + array( 'value' => get_option( Registrar::SETTING_PREFIX . $key, null ) );
		}
		$relations = (array) ( $model['relations'] ?? array() );
		if ( ! $post_types && ! $settings && ! $relations ) {
			return null;
		}
		return array(
			'postTypes' => (object) $post_types,
			'relations' => (object) $relations,
			'settings'  => (object) $settings,
		);
	}

	/**
	 * Declared fields of a post type of the content model, repeater types included.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( string $post_type ): array {
		foreach ( (array) ( Model::current()['postTypes'] ?? array() ) as $key => $type ) {
			if ( $key === $post_type ) {
				return (array) ( $type['fields'] ?? array() );
			}
			foreach ( (array) ( $type['repeaters'] ?? array() ) as $repeater ) {
				if ( $repeater['postType'] === $post_type ) {
					return (array) ( $repeater['fields'] ?? array() );
				}
			}
		}
		return array();
	}

	/**
	 * The post when the current visitor may read it: viewable for everyone, or readable for a user who may edit entries
	 * of its type, like the REST controllers of non-public types. A repeater row is readable when its entry is.
	 * Password-protected posts stay closed until the password is entered.
	 *
	 * @param int  $id    Post ID.
	 * @param bool $owned Whether the post must belong to a post type of the content model; relation targets may be of
	 *                    any type.
	 */
	private static function readable_post( int $id, bool $owned = true ): ?\WP_Post {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ( $owned && ! Registrar::owns_post_type( $post->post_type ) ) ) {
			return null;
		}
		$entry = $post;
		if ( Registrar::is_repeater( $post->post_type ) ) {
			// get_post( 0 ) would answer the global post.
			$entry = $post->post_parent ? get_post( $post->post_parent ) : null;
		}
		if ( ! $entry ) {
			return null;
		}
		$type     = get_post_type_object( $entry->post_type );
		$readable = is_post_publicly_viewable( $entry )
			|| ( $type && current_user_can( $type->cap->edit_posts ) && current_user_can( 'read_post', $entry->ID ) );
		return $readable && ! post_password_required( $entry ) && ! in_array( 'trash', array( $post->post_status, $entry->post_status ), true ) ? $post : null;
	}

	/**
	 * Formatted value of a declared field of a readable post; an empty string when nothing is stored.
	 *
	 * @param \WP_Post  $post      Post.
	 * @param string    $key       Field key.
	 * @param \WP_Block $block     Block instance.
	 * @param string    $attribute Bound attribute.
	 */
	private static function field_value( \WP_Post $post, string $key, \WP_Block $block, string $attribute ): ?string {
		$field = self::fields( $post->post_type )[ $key ] ?? null;
		if ( ! is_array( $field ) ) {
			return null;
		}
		return self::format( $field, metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, true ) : null, $block, $attribute );
	}

	/**
	 * Formats a stored value for the bound attribute: dates in the site format, select options as labels, images as URL
	 * or alt text (the ID for the attribute id), booleans as Yes or No. An empty value gives an empty string, so the
	 * block shows that the declared field is empty.
	 *
	 * @param array<string, mixed> $field     Field declaration.
	 * @param mixed                $value     Stored value.
	 * @param \WP_Block            $block     Block instance.
	 * @param string               $attribute Bound attribute.
	 */
	private static function format( array $field, $value, \WP_Block $block, string $attribute ): string {
		if ( 'boolean' === $field['type'] && null !== $value ) {
			// Single meta and options store false as an empty string.
			$value = in_array( $value, array( true, 1, '1', 'true' ), true ) ? __( 'Yes', 'leitvia-studio' ) : __( 'No', 'leitvia-studio' );
		}
		if ( null === $value || '' === $value || is_array( $value ) ) {
			return '';
		}
		switch ( $field['type'] ) {
			case 'date':
			case 'datetime':
			case 'time':
				$formats = array(
					'date'     => get_option( 'date_format' ),
					'datetime' => get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					'time'     => get_option( 'time_format' ),
				);
				$date    = date_create_immutable( (string) $value, wp_timezone() );
				$value   = $date ? wp_date( $formats[ $field['type'] ], $date->getTimestamp() ) : false;
				break;
			case 'select':
				$value = $field['options'][ (string) $value ] ?? '';
				break;
			case 'image':
				$id = (int) $value;
				if ( ! wp_attachment_is_image( $id ) ) {
					return '';
				}
				if ( 'id' === $attribute ) {
					return (string) $id;
				}
				$value = 'alt' === $attribute
					? (string) get_post_meta( $id, '_wp_attachment_image_alt', true )
					: wp_get_attachment_url( $id );
				break;
		}
		return is_scalar( $value ) ? self::text( (string) $value, $block, $attribute, false, 'textarea' === $field['type'] ) : '';
	}

	/**
	 * Escapes a text for the bound attribute. HTML attributes of the block (content, button text) get the text escaped,
	 * so markup in a field shows as text; URLs are cleaned; other attributes are plain text, which core escapes when it
	 * writes the HTML attribute.
	 *
	 * @param string    $text      Plain text, or HTML when $is_html.
	 * @param \WP_Block $block     Block instance.
	 * @param string    $attribute Bound attribute.
	 * @param bool      $is_html   Whether the text is already HTML, like a rendered title.
	 * @param bool      $multiline Whether line breaks of the text become <br> in HTML attributes (textarea fields).
	 */
	private static function text( string $text, \WP_Block $block, string $attribute, bool $is_html = false, bool $multiline = false ): string {
		$source = $block->block_type?->attributes[ $attribute ]['source'] ?? '';
		if ( 'url' === $attribute ) {
			$text = esc_url_raw( $text );
		} elseif ( in_array( $source, array( 'html', 'rich-text' ), true ) ) {
			if ( $is_html ) {
				$text = wp_kses_post( $text );
			} else {
				$text = $multiline ? nl2br( esc_html( $text ), false ) : esc_html( $text );
			}
		} elseif ( $is_html ) {
			$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		}
		return $text;
	}
}
