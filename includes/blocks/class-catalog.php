<?php
/**
 * Block catalog: every block of Studio and its add-ons with attribute schema and usage rules, for agents.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the catalog from the core block registry for the names in the Studio registry blocks, so add-on blocks
 * appear with their owner and nothing is maintained twice: title, description, category, parent, ancestor, supports,
 * attributes and allowed children come from block.json as core registered it.
 *
 * The usage rules (useWhen, avoidWhen, a11y) live in catalog.json next to block.json and are copied to the build by
 * webpack. They cannot live in block.json: core would ignore an own key such as "leitvia", but the block.json schema
 * (schemas.wp.org/trunk/block.json) sets additionalProperties to false, so editors and schema checks reject it.
 */
final class Catalog {

	public const SCHEMA = 'schemas/block-catalog.schema.json';

	/**
	 * Supports keys that tell an agent what it may set on a block. Others (for example lock or __experimental keys)
	 * are left out.
	 */
	private const SUPPORTS = array( 'align', 'anchor', 'color', 'spacing', 'typography', 'dimensions', 'border', 'shadow', 'layout', 'html', 'multiple', 'reusable', 'inserter', 'interactivity' );

	/**
	 * Attributes core adds to every block or through supports. The catalog lists only the attributes of block.json;
	 * supports covers the rest.
	 */
	private const GLOBAL_ATTRIBUTES = array( 'lock', 'metadata', 'className', 'style', 'anchor', 'align', 'layout', 'backgroundColor', 'textColor', 'gradient', 'borderColor', 'fontSize', 'fontFamily', 'shadow', 'ariaLabel' );

	/**
	 * Attribute keys copied into the JSON schema of an attribute.
	 */
	private const ATTRIBUTE_KEYS = array( 'type', 'enum', 'default', 'items', 'properties', 'source' );

	/**
	 * Core blocks whose behavior Studio changes, with the change and their usage rules.
	 */
	private const CORE_EXTENSIONS = array(
		'core/code'  => array(
			'extension' => 'Studio adds a "Copy code" button once scripting runs; without scripting the code stays selectable text.',
			'usage'     => array(
				'useWhen'   => 'Code, commands or configuration that readers copy. Use the block style terminal for command lines.',
				'avoidWhen' => 'Short inline code inside a sentence; use inline code formatting in the paragraph instead.',
				'a11y'      => 'The copy result is announced in a live region. Do not put the only explanation of a step into the code.',
			),
		),
		'core/embed' => array(
			'extension' => 'While the setting consent_embeds is on, Studio replaces embeds from other hosts with a consent placeholder that names the provider and links to the content, until the visitor agrees.',
			'usage'     => array(
				'useWhen'   => 'Videos, posts or other content hosted by a third party such as YouTube or Vimeo.',
				'avoidWhen' => 'Content that can be hosted on the site itself, such as an image or a video file; use the image or video block.',
				'a11y'      => 'Give the embed a caption that says what it shows; the placeholder uses the provider name and keeps a direct link.',
			),
		),
		'core/tabs'  => array(
			'extension' => 'Studio shows all tab panels one below the other with their labels when scripting is off; core keeps its tab logic otherwise.',
			'usage'     => array(
				'useWhen'   => 'Two to six parallel views of the same topic, such as plans or platforms, where readers need only one at a time.',
				'avoidWhen' => 'Content everyone must read or sequential steps; use headings or the wizard block instead.',
				'a11y'      => 'Keep tab labels short and unique. Content must make sense when all panels show at once.',
			),
		),
	);

	/**
	 * Catalog of this request and the registry names it was built for.
	 *
	 * @var array{key: string, catalog: list<array<string, mixed>>}|null
	 */
	private static ?array $cache = null;

	/**
	 * The catalog, filtered on every call. The unfiltered catalog is built once per request after wp_loaded and rebuilt
	 * when the registry changes.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get(): array {
		/**
		 * Filters the block catalog that GET /leitvia-studio/v1/blocks returns.
		 *
		 * @param list<array<string, mixed>> $catalog Entries as described in schemas/block-catalog.schema.json.
		 */
		return apply_filters( 'leitvia_studio_block_catalog', self::build() );
	}

	/**
	 * The unfiltered catalog.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function build(): array {
		$entries = leitvia_studio()->registry( 'blocks' )->all();
		$key     = implode( ',', array_keys( $entries ) );
		if ( null !== self::$cache && self::$cache['key'] === $key ) {
			return self::$cache['catalog'];
		}
		$catalog = array();
		$types   = \WP_Block_Type_Registry::get_instance();
		foreach ( $entries as $name => $entry ) {
			$type = $types->get_registered( $name );
			if ( $type ) {
				$path      = (string) $entry['path'];
				$file      = ( str_ends_with( $path, '.json' ) ? dirname( $path ) : $path ) . '/catalog.json';
				$catalog[] = self::entry( $type, (string) $entry['owner'], self::usage( $file ) );
			}
		}
		foreach ( self::CORE_EXTENSIONS as $name => $extension ) {
			$type = $types->get_registered( $name );
			if ( $type ) {
				$catalog[] = self::entry( $type, 'core', $extension['usage'] ) + array( 'extension' => $extension['extension'] );
			}
		}
		$catalog = self::add_children( $catalog );
		if ( did_action( 'wp_loaded' ) ) {
			self::$cache = array(
				'key'     => $key,
				'catalog' => $catalog,
			);
		}
		return $catalog;
	}

	/**
	 * The catalog schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$json = file_get_contents( dirname( LEITVIA_STUDIO_FILE ) . '/' . self::SCHEMA ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin.
		return (array) json_decode( (string) $json, true );
	}

	/**
	 * One catalog entry.
	 *
	 * @param \WP_Block_Type             $type  Registered block type.
	 * @param string                     $owner Add-on slug, core for core blocks.
	 * @param array<string, string>|null $usage Usage rules, null when the block has none.
	 * @return array<string, mixed>
	 */
	private static function entry( \WP_Block_Type $type, string $owner, ?array $usage ): array {
		$properties = array();
		foreach ( (array) $type->attributes as $name => $attribute ) {
			if ( ! in_array( $name, self::GLOBAL_ATTRIBUTES, true ) && is_array( $attribute ) ) {
				$schema = array_intersect_key( $attribute, array_flip( self::ATTRIBUTE_KEYS ) );
				// rich-text is a string of HTML in the block markup.
				if ( 'rich-text' === ( $schema['type'] ?? null ) ) {
					$schema['type'] = 'string';
				}
				$properties[ $name ] = $schema;
			}
		}
		return array(
			'name'        => $type->name,
			'title'       => $type->title,
			'description' => $type->description,
			'category'    => $type->category,
			'parent'      => $type->parent,
			'ancestor'    => $type->ancestor,
			'supports'    => array_intersect_key( (array) $type->supports, array_flip( self::SUPPORTS ) ),
			'attributes'  => array(
				'type'       => 'object',
				'properties' => $properties,
			),
			'innerBlocks' => $type->allowed_blocks,
			'usage'       => $usage,
			'owner'       => $owner,
		);
	}

	/**
	 * Fills innerBlocks of entries without allowedBlocks with the catalog blocks that name them as parent.
	 *
	 * @param list<array<string, mixed>> $catalog Catalog entries.
	 * @return list<array<string, mixed>>
	 */
	private static function add_children( array $catalog ): array {
		foreach ( $catalog as $i => $entry ) {
			if ( null !== $entry['innerBlocks'] ) {
				continue;
			}
			$children = array();
			foreach ( $catalog as $child ) {
				if ( in_array( $entry['name'], (array) $child['parent'], true ) ) {
					$children[] = $child['name'];
				}
			}
			$catalog[ $i ]['innerBlocks'] = $children ? $children : null;
		}
		return $catalog;
	}

	/**
	 * Usage rules from a catalog.json, or null when the file is missing or broken.
	 *
	 * @param string $file Path to catalog.json.
	 * @return array<string, string>|null
	 */
	private static function usage( string $file ): ?array {
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of a block.
		$usage = is_array( $data ) ? ( $data['usage'] ?? null ) : null;
		return is_array( $usage ) ? array_map( 'strval', array_intersect_key( $usage, array_flip( array( 'useWhen', 'avoidWhen', 'a11y' ) ) ) ) : null;
	}
}
