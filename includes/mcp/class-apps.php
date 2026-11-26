<?php
/**
 * MCP Apps of the server leitvia: views that add-ons attach to tools.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Entries of the registry mcp_apps become ui:// resources of the extension io.modelcontextprotocol/ui (SEP-1865,
 * stable 2026-01-26) with the MIME type text/html;profile=mcp-app. A tool an entry names gets _meta.ui.resourceUri, an
 * ability with meta mcp.visibility ["app"] is a tool only views call. Both only for a client that declared the
 * extension with that MIME type, in _meta under 2026-07-28 or at initialize under 2025-11-25; any other client sees
 * the tools as before and no tool meant for views. Studio ships no view of its own.
 */
final class Apps {

	public const EXTENSION = 'io.modelcontextprotocol/ui';

	public const MIME = 'text/html;profile=mcp-app';

	/**
	 * CSP fields of SEP-1865 an entry may declare, each a list of https origins. Without them the host applies its
	 * restrictive default: no connections, images and media only from data: and the view itself.
	 */
	public const CSP = array( 'connectDomains', 'resourceDomains', 'frameDomains', 'baseUriDomains' );

	/**
	 * Why an entry of mcp_apps is invalid, '' when it is valid. Runs as guard of the registry.
	 *
	 * @param array<string, mixed> $item Entry.
	 */
	public static function invalid( array $item ): string {
		$path = (string) $item['path'];
		if ( ! str_ends_with( $path, '.html' ) || ! is_readable( $path ) ) {
			return 'path must name a readable HTML file';
		}
		if ( isset( $item['description'] ) && ( ! is_string( $item['description'] ) || '' === $item['description'] ) ) {
			return 'description must be a string';
		}
		$tools = $item['tools'] ?? array();
		if ( ! is_array( $tools ) || array_filter( $tools, static fn( $tool ): bool => ! is_string( $tool ) || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#D', $tool ) ) ) {
			return 'tools must list ability names';
		}
		if ( isset( $item['data'] ) && ! is_callable( $item['data'] ) ) {
			return 'data must be callable';
		}
		if ( isset( $item['prefers_border'] ) && ! is_bool( $item['prefers_border'] ) ) {
			return 'prefers_border must be a boolean';
		}
		$csp = $item['csp'] ?? array();
		if ( ! is_array( $csp ) ) {
			return 'csp must be an array';
		}
		foreach ( $csp as $field => $origins ) {
			if ( ! in_array( $field, self::CSP, true ) || ! is_array( $origins ) ) {
				return 'csp takes the lists ' . implode( ', ', self::CSP );
			}
			foreach ( $origins as $origin ) {
				if ( ! is_string( $origin ) || ! preg_match( '#^https://(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(:\d{1,5})?$#D', $origin ) ) {
					return 'csp origins look like https://host or https://*.host, without a path';
				}
			}
		}
		return '';
	}

	/**
	 * Registered entries by ui:// URI, sorted.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$apps = array();
		foreach ( leitvia_studio()->registry( 'mcp_apps' )->all() as $name => $entry ) {
			$apps[ 'ui://' . $name ] = $entry;
		}
		ksort( $apps );
		return $apps;
	}

	/**
	 * Whether this request may get views: a registered entry and a client that declared the extension with the MIME
	 * type of SEP-1865.
	 *
	 * @param Context|null $context Protocol state, null outside a request.
	 */
	public static function offered( ?Context $context ): bool {
		$ui = null === $context ? null : ( $context->capabilities['extensions'][ self::EXTENSION ] ?? null );
		return is_array( $ui ) && in_array( self::MIME, (array) ( $ui['mimeTypes'] ?? array() ), true ) && array() !== self::all();
	}

	/**
	 * Who may call the tool of an ability: meta mcp.visibility, ["model", "app"] without it.
	 *
	 * @param \WP_Ability $ability Ability.
	 * @return list<string>
	 */
	public static function visibility( \WP_Ability $ability ): array {
		$mcp  = $ability->get_meta_item( 'mcp', array() );
		$list = is_array( $mcp ) && is_array( $mcp['visibility'] ?? null ) ? array_values( array_intersect( array( 'model', 'app' ), $mcp['visibility'] ) ) : array();
		return $list ? $list : array( 'model', 'app' );
	}

	/**
	 * Whether the tool of an ability exists for this request: a tool only for views needs a client that shows them.
	 *
	 * @param \WP_Ability  $ability Ability.
	 * @param Context|null $context Protocol state.
	 */
	public static function listed( \WP_Ability $ability, ?Context $context ): bool {
		return in_array( 'model', self::visibility( $ability ), true ) || self::offered( $context );
	}

	/**
	 * _meta of a tool for this request: _meta.ui.resourceUri of the first entry that names the ability, with the flat
	 * key ui/resourceUri that hosts from before the stable revision read (the helper registerAppTool of the official SDK
	 * writes both), and _meta.ui.visibility of a tool not for the model; then the filter.
	 *
	 * @param string       $tool    Tool name.
	 * @param \WP_Ability  $ability Ability.
	 * @param Context|null $context Protocol state.
	 * @return array<string, mixed>
	 */
	public static function meta( string $tool, \WP_Ability $ability, ?Context $context ): array {
		$meta = array();
		if ( self::offered( $context ) ) {
			foreach ( self::all() as $uri => $entry ) {
				if ( in_array( $ability->get_name(), (array) ( $entry['tools'] ?? array() ), true ) ) {
					$meta['ui']             = array( 'resourceUri' => $uri );
					$meta['ui/resourceUri'] = $uri;
					break;
				}
			}
			$visibility = self::visibility( $ability );
			if ( array( 'model', 'app' ) !== $visibility ) {
				$meta['ui']['visibility'] = $visibility;
			}
		}
		/**
		 * Filters the _meta of one tool of the MCP server leitvia in tools/list.
		 *
		 * @param array<string, mixed> $meta    _meta so far, empty for a tool without a view or for a client without views.
		 * @param string               $tool    Tool name, such as leitvia-get-package.
		 * @param \WP_Ability          $ability The ability behind the tool.
		 * @param Context|null         $context Protocol state; Apps::offered( $context ) tells whether the client shows views.
		 */
		return (array) apply_filters( 'leitvia_studio_mcp_tool_meta', $meta, $tool, $ability, $context );
	}

	/**
	 * Entries of resources/list with the _meta.ui a host reviews when it connects.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function resources(): array {
		$list = array();
		foreach ( self::all() as $uri => $entry ) {
			$list[] = array_filter(
				array(
					'uri'         => $uri,
					'name'        => str_replace( '/', '-', substr( $uri, strlen( 'ui://' ) ) ),
					'title'       => (string) $entry['title'],
					'description' => (string) ( $entry['description'] ?? '' ),
					'mimeType'    => self::MIME,
					'_meta'       => array( 'ui' => self::ui( $entry ) ),
				),
				static fn( $value ): bool => '' !== $value
			);
		}
		return $list;
	}

	/**
	 * Content of resources/read for a ui:// URI, null for an unknown one: the file of the entry with the JSON of its
	 * data callback in script#mcp-app-data before </head>. JSON_HEX_TAG keeps < and > out, so no data can end the script.
	 *
	 * @param string $uri Resource URI.
	 * @return array<string, mixed>|null
	 */
	public static function read( string $uri ): ?array {
		$entry = self::all()[ $uri ] ?? null;
		if ( null === $entry ) {
			return null;
		}
		$html = (string) file_get_contents( (string) $entry['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of an add-on, checked by the guard.
		$at   = stripos( $html, '</head>' );
		if ( isset( $entry['data'] ) && false !== $at ) {
			$json = (string) wp_json_encode( call_user_func( $entry['data'] ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
			$html = substr_replace( $html, '<script type="application/json" id="mcp-app-data">' . $json . '</script>', $at, 0 );
		}
		return array(
			'uri'      => $uri,
			'mimeType' => self::MIME,
			'text'     => $html,
			'_meta'    => array( 'ui' => self::ui( $entry ) ),
		);
	}

	/**
	 * _meta.ui of a view: prefersBorder (true unless the entry says otherwise, hosts differ in their default) and the CSP
	 * lists it declared.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return array<string, mixed>
	 */
	private static function ui( array $entry ): array {
		$ui  = array( 'prefersBorder' => (bool) ( $entry['prefers_border'] ?? true ) );
		$csp = array_filter( (array) ( $entry['csp'] ?? array() ) );
		if ( $csp ) {
			$ui['csp'] = $csp;
		}
		return $ui;
	}
}
