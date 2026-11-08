<?php
/**
 * Consent for scripts of plugins: scripts from other providers wait until the visitor allows their category.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Holds back the scripts the filter leitvia_studio_consent_scripts names, by handle or by host. A held script keeps
 * its markup and gets type="text/plain", which a browser neither fetches nor runs, and data-leitvia-consent with its
 * category. The script module @leitvia/consent puts a working copy in its place once the category is allowed.
 *
 * Covers what WordPress prints: enqueued scripts (wp_script_attributes, script_loader_tag), their inline scripts and
 * inline scripts printed with wp_print_inline_script_tag() (wp_inline_script_attributes). A script a plugin echoes
 * itself passes none of these filters and stays as it is.
 */
final class Scripts {

	/**
	 * Categories a visitor allows, key => label.
	 *
	 * @return array<string, string>
	 */
	public static function categories(): array {
		return array(
			'statistics' => __( 'Statistics', 'leitvia-studio' ),
			'marketing'  => __( 'Marketing', 'leitvia-studio' ),
		);
	}

	/**
	 * Valid entries of the filter: a handle or a host name (subdomains included) and a category of categories().
	 *
	 * @return array<int, array{handle: string, host: string, category: string}>
	 */
	public static function entries(): array {
		/**
		 * Filters the scripts that wait for consent. Each entry names a script handle or a host name and a category,
		 * 'statistics' or 'marketing': array( 'host' => 'widgets.example.com', 'category' => 'marketing' ).
		 *
		 * @param array<int, mixed> $entries
		 */
		$entries = (array) apply_filters( 'leitvia_studio_consent_scripts', array() );
		$valid   = array();
		foreach ( $entries as $entry ) {
			$entry = is_array( $entry ) ? $entry : array();
			$item  = array(
				'handle'   => (string) ( $entry['handle'] ?? '' ),
				'host'     => self::host( (string) ( $entry['host'] ?? '' ) ),
				'category' => (string) ( $entry['category'] ?? '' ),
			);
			if ( isset( self::categories()[ $item['category'] ] ) && ( '' !== $item['handle'] || '' !== $item['host'] ) ) {
				$valid[] = $item;
			}
		}
		return $valid;
	}

	/**
	 * Host name of a URL or of a bare host ("https://Widgets.example.com/x.js", "//widgets.example.com",
	 * "widgets.example.com/"), lowercase without port and path; '' when there is none.
	 *
	 * @param string $url URL or host name.
	 */
	public static function host( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$host = wp_parse_url( str_contains( $url, '//' ) ? $url : '//' . $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Category of the first entry whose host is the host of the URL or a parent of it, '' for none.
	 *
	 * @param string                                                            $url     URL or host name.
	 * @param array<int, array{handle: string, host: string, category: string}> $entries Entries of the filter.
	 */
	private static function host_category( string $url, array $entries ): string {
		$host = self::host( $url );
		foreach ( '' === $host ? array() : $entries as $entry ) {
			if ( '' !== $entry['host'] && ( $host === $entry['host'] || str_ends_with( $host, '.' . $entry['host'] ) ) ) {
				return $entry['category'];
			}
		}
		return '';
	}

	/**
	 * Category of a script, '' when it may run: by its handle, the host of its URL, or a script it depends on.
	 *
	 * Only on the front end: the admin offers no way to consent.
	 *
	 * @param string                                                                 $handle  Script handle, '' when unknown.
	 * @param string                                                                 $src     Script URL, '' for an inline script.
	 * @param array<int, array{handle: string, host: string, category: string}>|null $entries Entries, null to read them.
	 * @param array<string, bool>                                                    $seen    Handles already looked at, against circular dependencies.
	 */
	private static function category( string $handle, string $src, ?array $entries = null, array $seen = array() ): string {
		$entries ??= is_admin() ? array() : self::entries();
		if ( array() === $entries ) {
			return '';
		}
		foreach ( '' === $handle ? array() : $entries as $entry ) {
			if ( $entry['handle'] === $handle ) {
				return $entry['category'];
			}
		}
		// Only a URL with a scheme or // names a host; a relative path is the own site.
		$category = str_contains( $src, '//' ) ? self::host_category( $src, $entries ) : '';
		if ( '' !== $category ) {
			return $category;
		}
		$script = '' === $handle ? null : wp_scripts()->query( $handle );
		foreach ( $script ? (array) $script->deps : array() as $dep ) {
			if ( ! isset( $seen[ $dep ] ) ) {
				$seen[ $dep ] = true;
				$dep_script   = wp_scripts()->query( $dep );
				$category     = self::category( (string) $dep, $dep_script && is_string( $dep_script->src ) ? $dep_script->src : '', $entries, $seen );
				if ( '' !== $category ) {
					return $category;
				}
			}
		}
		return '';
	}

	/**
	 * Handle of a script tag id that WordPress gives an enqueued script and its inline scripts ("<handle>-js",
	 * "<handle>-js-before", "-js-after", "-js-extra" of wp_localize_script(), "-js-translations"), '' for any other id.
	 *
	 * @param mixed $id Value of the id attribute.
	 */
	private static function handle( $id ): string {
		return is_string( $id ) && preg_match( '/^(.+)-js(?:-before|-after|-extra|-translations)?$/', $id, $match ) ? $match[1] : '';
	}

	/**
	 * The attributes of a waiting script: type text/plain, the category and the original type unless it is plain
	 * JavaScript.
	 *
	 * @param array<string, mixed> $attributes Attributes of the script tag.
	 * @param string               $category   Category of the script.
	 * @return array<string, mixed>
	 */
	private static function hold( array $attributes, string $category ): array {
		$type = (string) ( $attributes['type'] ?? '' );
		if ( '' !== $type && 'text/javascript' !== $type && 'text/plain' !== $type ) {
			$attributes['data-leitvia-type'] = $type;
		}
		$attributes['type']                 = 'text/plain';
		$attributes['data-leitvia-consent'] = $category;
		return $attributes;
	}

	/**
	 * Holds a script with a URL. Runs on wp_script_attributes, for enqueued scripts and wp_print_script_tag().
	 *
	 * @param array<string, mixed> $attributes Attributes of the script tag.
	 * @return array<string, mixed>
	 */
	public static function script_attributes( array $attributes ): array {
		$src      = is_string( $attributes['src'] ?? null ) ? $attributes['src'] : '';
		$category = self::category( self::handle( $attributes['id'] ?? null ), $src );
		return '' === $category ? $attributes : self::hold( $attributes, $category );
	}

	/**
	 * Holds an inline script of a held handle and an inline script that names a listed host, such as a loader that
	 * inserts the script of a provider. Data blocks (JSON and other types) stay. Runs on wp_inline_script_attributes.
	 *
	 * @param array<string, mixed> $attributes Attributes of the script tag.
	 * @param string               $data       Content of the script.
	 * @return array<string, mixed>
	 */
	public static function inline_attributes( array $attributes, $data = '' ): array {
		if ( ! self::is_js( $attributes['type'] ?? null ) ) {
			return $attributes;
		}
		$handle   = self::handle( $attributes['id'] ?? null );
		$script   = '' === $handle ? null : wp_scripts()->query( $handle );
		$category = '' === $handle ? '' : self::category( $handle, $script && is_string( $script->src ) ? $script->src : '' );
		foreach ( '' === $category && ! is_admin() ? self::entries() : array() as $entry ) {
			if ( '' !== $entry['host'] && preg_match( '#[/.@]' . preg_quote( $entry['host'], '#' ) . '(?![a-z0-9.-])#i', (string) $data ) ) {
				$category = $entry['category'];
				break;
			}
		}
		return '' === $category ? $attributes : self::hold( $attributes, $category );
	}

	/**
	 * Whether a type attribute stands for a script a browser runs; data blocks such as JSON, import maps and
	 * speculation rules do not.
	 *
	 * @param mixed $type Value of the type attribute, null or true when it is missing or empty.
	 */
	private static function is_js( $type ): bool {
		return ! is_string( $type ) || in_array( strtolower( trim( $type ) ), array( '', 'text/javascript', 'application/javascript', 'module' ), true );
	}

	/**
	 * Holds the tags of an enqueued script again after other filters of script_loader_tag rewrote them, with the HTML
	 * API. Runs on script_loader_tag at a late priority.
	 *
	 * @param string $tag    Script tags of the handle, its inline scripts included.
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 */
	public static function loader_tag( $tag, $handle, $src = '' ): string {
		$tag      = (string) $tag;
		$category = self::category( (string) $handle, (string) $src );
		if ( '' === $category ) {
			return $tag;
		}
		$tags = new \WP_HTML_Tag_Processor( $tag );
		while ( $tags->next_tag( array( 'tag_name' => 'script' ) ) ) {
			// Every script of the handle, with or without the ids WordPress gives them: other filters rewrite tags.
			$held = 'text/plain' === $tags->get_attribute( 'type' ) && $category === $tags->get_attribute( 'data-leitvia-consent' );
			if ( $held || ! self::is_js( $tags->get_attribute( 'type' ) ) ) {
				continue;
			}
			$type = $tags->get_attribute( 'type' );
			foreach ( self::hold( is_string( $type ) ? array( 'type' => $type ) : array(), $category ) as $name => $value ) {
				$tags->set_attribute( $name, $value );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * Drops resource hints (preconnect, dns-prefetch, prefetch and any other relation) and preloads to a listed host: they
	 * would reach the host before consent. Runs on wp_resource_hints and wp_preload_resources at a late priority.
	 *
	 * @param mixed $urls URLs as strings or arrays with href.
	 * @return array<int, mixed>
	 */
	public static function resource_hints( $urls ): array {
		$entries = is_admin() ? array() : self::entries();
		if ( array() === $entries || ! is_array( $urls ) ) {
			return is_array( $urls ) ? $urls : array();
		}
		return array_values(
			array_filter(
				$urls,
				static function ( $url ) use ( $entries ): bool {
					$href = is_array( $url ) ? ( $url['href'] ?? '' ) : $url;
					return ! is_string( $href ) || '' === self::host_category( $href, $entries );
				}
			)
		);
	}

	/**
	 * Loads the consent module on every page while the filter names scripts: it activates the scripts of the
	 * categories the visitor allowed. Runs on wp_enqueue_scripts.
	 */
	public static function enqueue(): void {
		if ( array() !== self::entries() ) {
			wp_enqueue_script_module( Consent::MODULE );
			Consent::site_state();
		}
	}

	/**
	 * The part of the consent settings block that offers the categories of the entries, each with a button. The
	 * module hides a category the visitor allowed, which then shows in the list of allowed providers with Revoke.
	 */
	public static function settings_markup(): string {
		$used  = array_unique( array_column( self::entries(), 'category' ) );
		$items = '';
		foreach ( self::categories() as $key => $label ) {
			if ( ! in_array( $key, $used, true ) ) {
				continue;
			}
			$items .= sprintf(
				'<li data-wp-context="%1$s" data-wp-bind--hidden="state.isAllowed"><span>%2$s</span> <button type="button" class="wp-element-button" data-wp-on--click="actions.allow">%3$s <span class="screen-reader-text">%2$s</span></button></li>',
				esc_attr(
					(string) wp_json_encode(
						array(
							'category' => $key,
							'label'    => $label,
							/* translators: %s: category of scripts, for example Marketing. */
							'granted'  => __( '%s is allowed.', 'leitvia-studio' ),
						)
					)
				),
				esc_html( $label ),
				esc_html__( 'Allow', 'leitvia-studio' )
			);
		}
		if ( '' === $items ) {
			return '';
		}
		return sprintf(
			'<div class="wp-block-leitvia-consent-settings__scripts"><p tabindex="-1">%1$s</p><ul class="wp-block-leitvia-consent-settings__list">%2$s</ul></div>',
			esc_html__( 'Scripts of other providers that plugins on this site use load only after you allow them:', 'leitvia-studio' ),
			$items
		);
	}
}
