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
	 * Attributes of the blocks core binds whose value a program reads, not a visitor: core/image id and url,
	 * core/button url, linkTarget and rel, core/post-date datetime (core runs strtotime() on it). They get the stored
	 * value, never the site format or a label.
	 */
	public const MACHINE_ATTRIBUTES = array( 'id', 'url', 'datetime', 'linkTarget', 'rel' );

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
	 * not empty, such as a currency ("45 EUR"); a machine-readable attribute (URL, date) gets no affixes.
	 *
	 * @param array<string, mixed> $args      Source arguments: key, prefix, suffix.
	 * @param \WP_Block            $block     Block instance.
	 * @param string               $attribute Bound attribute.
	 */
	public static function meta_value( array $args, \WP_Block $block, string $attribute ): ?string {
		$post  = self::readable_post( (int) ( $block->context['postId'] ?? 0 ) );
		$value = $post ? self::field_value( $post, (string) ( $args['key'] ?? '' ), $block, $attribute ) : null;
		if ( null === $value || '' === $value || in_array( $attribute, self::MACHINE_ATTRIBUTES, true ) ) {
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
	 * Answers core/post-meta for a field of the content model like leitvia/meta: the label of a select option, a date in
	 * the site format, entries of a type without single pages. Core returns the raw value and nothing for such a type.
	 * A relation is a list of IDs no attribute can show, so it keeps the saved content; meta of other plugins and types
	 * stays untouched. Runs on block_bindings_source_value.
	 *
	 * @param mixed                $value     Value of the source.
	 * @param string               $source    Source name.
	 * @param array<string, mixed> $args      Source arguments.
	 * @param mixed                $block     Block instance, a WP_Block from core.
	 * @param string               $attribute Bound attribute.
	 * @return mixed
	 */
	public static function core_post_meta( $value, string $source, array $args, $block, string $attribute ) {
		$post_type = $block instanceof \WP_Block ? (string) get_post_type( (int) ( $block->context['postId'] ?? 0 ) ) : '';
		$key       = (string) ( $args['key'] ?? '' );
		if ( 'core/post-meta' !== $source || ! $post_type || ! Registrar::owns_meta( $post_type, $key ) ) {
			return $value;
		}
		return isset( Model::current()['relations'][ $key ] ) ? null : self::meta_value( array( 'key' => $key ), $block, $attribute );
	}

	/**
	 * A list bound like a paragraph, metadata.bindings.content on core/list: one item per line of the value, so a
	 * textarea field is a list field, kept and edited as text. No line, no list: visitors get no empty ul. Core has no
	 * binding for a whole list, since it would have to write inner blocks, so the markup comes from here; the editor
	 * shows the saved items. Runs on render_block_core/list.
	 *
	 * @param string               $html     Block HTML.
	 * @param array<string, mixed> $block    Parsed block.
	 * @param \WP_Block            $instance Block instance.
	 */
	public static function list_items( string $html, array $block, \WP_Block $instance ): string {
		$binding = $block['attrs']['metadata']['bindings']['content'] ?? null;
		$source  = is_array( $binding ) ? get_block_bindings_source( (string) ( $binding['source'] ?? '' ) ) : null;
		if ( ! $source || ! preg_match( '/^\s*<(ul|ol)\b[^>]*>/i', $html, $open ) ) {
			return $html;
		}
		$value = $source->get_value( (array) ( $binding['args'] ?? array() ), $instance, 'content' );
		if ( null === $value ) {
			return $html;
		}
		$lines = preg_split( '/\R/u', (string) $value );
		$lines = array_filter( array_map( 'trim', false === $lines ? array() : $lines ), static fn( string $line ): bool => '' !== $line );
		$items = implode( '', array_map( static fn( string $line ): string => '<li>' . esc_html( $line ) . '</li>', $lines ) );
		return '' === $items ? '' : $open[0] . $items . '</' . strtolower( $open[1] ) . '>';
	}

	/**
	 * Gives core/list the context the sources read the entry from, as core does for the blocks it binds. Runs on
	 * get_block_type_uses_context.
	 *
	 * @param string[]       $uses_context Context the block type uses.
	 * @param \WP_Block_Type $block_type   Block type.
	 * @return string[]
	 */
	public static function list_context( array $uses_context, \WP_Block_Type $block_type ): array {
		return 'core/list' === $block_type->name ? array_values( array_unique( array_merge( $uses_context, array( 'postId', 'postType' ) ) ) ) : $uses_context;
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
	 * Attributes of a block that a binding can set: the list of core for the running WordPress, with its filter
	 * block_bindings_supported_attributes, plus content of core/list, which list_items() renders.
	 *
	 * @param string $block_name Block name.
	 * @return list<string>
	 */
	public static function attributes( string $block_name ): array {
		$attributes = array_values( array_map( 'strval', (array) get_block_bindings_supported_attributes( $block_name ) ) );
		return 'core/list' === $block_name ? array_values( array_unique( array_merge( $attributes, array( 'content' ) ) ) ) : $attributes;
	}

	/**
	 * Every registered block with the attributes a binding can set, see attributes().
	 *
	 * @return array<string, list<string>>
	 */
	public static function attribute_map(): array {
		$map = array();
		foreach ( array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $name ) {
			$attributes = self::attributes( (string) $name );
			if ( $attributes ) {
				$map[ (string) $name ] = $attributes;
			}
		}
		ksort( $map );
		return $map;
	}

	/**
	 * Declared fields of a post type of the content model, repeater types included.
	 *
	 * @param string                    $post_type Post type.
	 * @param array<string, mixed>|null $model     Declaration to read, the current one by default.
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( string $post_type, ?array $model = null ): array {
		foreach ( (array) ( ( $model ?? Model::current() )['postTypes'] ?? array() ) as $key => $type ) {
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
		$type = get_post_type_object( $entry->post_type );
		// A public type without single pages (singlePages) shows published entries on pages.
		$readable = is_post_publicly_viewable( $entry )
			|| ( $type && $type->public && 'publish' === $entry->post_status )
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
	 * Formats a stored value for the bound attribute. Text a visitor reads gets dates in the site format, select options
	 * as labels, booleans as Yes or No, and of an image its alt text, caption or title. A machine-readable attribute
	 * (MACHINE_ATTRIBUTES) gets the stored value: a date as ISO 8601, the key of an option, 1 or an empty string, the
	 * URL or ID of an image. An empty value gives an empty string, so the block shows that the declared field is empty.
	 *
	 * @param array<string, mixed> $field     Field declaration.
	 * @param mixed                $value     Stored value.
	 * @param \WP_Block            $block     Block instance.
	 * @param string               $attribute Bound attribute.
	 */
	private static function format( array $field, $value, \WP_Block $block, string $attribute ): string {
		$machine = in_array( $attribute, self::MACHINE_ATTRIBUTES, true );
		if ( 'boolean' === $field['type'] && null !== $value ) {
			// Single meta and options store false as an empty string.
			$true  = in_array( $value, array( true, 1, '1', 'true' ), true );
			$value = $machine ? ( $true ? '1' : '' ) : ( $true ? __( 'Yes', 'leitvia-studio' ) : __( 'No', 'leitvia-studio' ) );
		}
		if ( null === $value || '' === $value || is_array( $value ) ) {
			return '';
		}
		switch ( $field['type'] ) {
			case 'date':
			case 'datetime':
			case 'time':
				$date = date_create_immutable( (string) $value, wp_timezone() );
				if ( ! $date ) {
					return '';
				}
				if ( $machine ) {
					// A time has no day that a datetime attribute could name.
					$formats = array(
						'date'     => 'Y-m-d',
						'datetime' => 'c',
						'time'     => '',
					);
				} else {
					$formats = array(
						'date'     => get_option( 'date_format' ),
						'datetime' => get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						'time'     => get_option( 'time_format' ),
					);
				}
				$value = '' === $formats[ $field['type'] ] ? '' : wp_date( $formats[ $field['type'] ], $date->getTimestamp() );
				break;
			case 'select':
				$value = $machine ? (string) $value : ( $field['options'][ (string) $value ] ?? '' );
				break;
			case 'image':
				$id = (int) $value;
				if ( ! wp_attachment_is_image( $id ) ) {
					return '';
				}
				switch ( $attribute ) {
					case 'id':
						return (string) $id;
					case 'url':
						$value = wp_get_attachment_url( $id );
						break;
					case 'alt':
						$value = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
						break;
					case 'caption':
						$value = (string) wp_get_attachment_caption( $id );
						break;
					default:
						// The title of the media library, for title and any text of the block, never the URL as text.
						return self::text( get_the_title( $id ), $block, $attribute, true );
				}
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
