<?php
/**
 * Consent placeholder for third-party content: embeds load nothing from another host before the visitor agrees.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the oEmbed HTML of core/embed and iframes from other hosts in the post content and in core/html with a
 * placeholder and keeps the original in a <template>, which the script module @leitvia/consent inserts after a click
 * or a stored "always allow".
 */
final class Consent {

	public const MODULE = '@leitvia/consent';

	/**
	 * Registers the script module. Runs on init before the blocks (priority 20), whose consent-settings block uses it.
	 */
	public static function register_module(): void {
		$asset_file = dirname( LEITVIA_STUDIO_FILE ) . '/build/consent/view.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_register_script_module( self::MODULE, plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'build/consent/view.js', $asset['dependencies'], $asset['version'] );
	}

	/**
	 * Known services: key => label, host names (subdomains included) and the provider's privacy policy.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function services(): array {
		$services = array(
			'youtube'    => array(
				'label'   => 'YouTube',
				'hosts'   => array( 'youtube.com', 'youtu.be', 'youtube-nocookie.com' ),
				'privacy' => 'https://policies.google.com/privacy',
			),
			'vimeo'      => array(
				'label'   => 'Vimeo',
				'hosts'   => array( 'vimeo.com' ),
				'privacy' => 'https://vimeo.com/privacy',
			),
			'x'          => array(
				'label'   => 'X',
				'hosts'   => array( 'x.com', 'twitter.com' ),
				'privacy' => 'https://x.com/en/privacy',
			),
			'spotify'    => array(
				'label'   => 'Spotify',
				'hosts'   => array( 'spotify.com' ),
				'privacy' => 'https://www.spotify.com/legal/privacy-policy/',
			),
			'soundcloud' => array(
				'label'   => 'SoundCloud',
				'hosts'   => array( 'soundcloud.com' ),
				'privacy' => 'https://soundcloud.com/pages/privacy',
			),
			'tiktok'     => array(
				'label'   => 'TikTok',
				'hosts'   => array( 'tiktok.com' ),
				'privacy' => 'https://www.tiktok.com/legal/privacy-policy',
			),
		);

		/**
		 * Filters the services the consent placeholder knows. Add a service, change its label, privacy policy or
		 * purpose (one sentence shown in the placeholder). An embed from a host no service lists still gets a
		 * placeholder, named after the host.
		 *
		 * @param array<string, array<string, mixed>> $services
		 */
		return (array) apply_filters( 'leitvia_studio_consent_services', $services );
	}

	/**
	 * Service key for a URL: a known service, the host name for any other foreign host, '' for the own site.
	 *
	 * @param string $url URL of the embedded content.
	 */
	public static function service_for( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host ) {
			return '';
		}
		foreach ( self::services() as $key => $service ) {
			foreach ( (array) ( $service['hosts'] ?? array() ) as $pattern ) {
				if ( $host === $pattern || str_ends_with( $host, '.' . $pattern ) ) {
					return (string) $key;
				}
			}
		}
		return $host;
	}

	/**
	 * Label of a service, the key itself for a host without an entry.
	 *
	 * @param string $service Service key.
	 */
	public static function label( string $service ): string {
		return (string) ( self::services()[ $service ]['label'] ?? $service );
	}

	/**
	 * Placeholder for third-party content, with the original HTML in a <template>.
	 *
	 * @param string $service      Service key (see services()) or a host name.
	 * @param string $html         Original HTML; it is inserted unchanged after consent.
	 * @param string $fallback_url Link to the content on the provider's site, for visitors without JavaScript.
	 */
	public static function placeholder( string $service, string $html, string $fallback_url ): string {
		$services = self::services();
		$label    = self::label( $service );
		$privacy  = esc_url( (string) ( $services[ $service ]['privacy'] ?? '' ) );
		$open     = esc_url( $fallback_url );
		$purpose  = (string) ( $services[ $service ]['purpose'] ?? sprintf(
			/* translators: %s: provider name, for example YouTube. */
			__( 'This content is hosted by %s. Loading it sends your IP address and details of your device to the provider, which may also set cookies.', 'leitvia-studio' ),
			$label
		) );

		wp_enqueue_script_module( self::MODULE );
		wp_enqueue_style( 'leitvia-consent-settings-style' );
		self::site_state();

		$privacy_link = '' === $privacy ? '' : sprintf(
			' <a href="%1$s">%2$s</a>',
			$privacy,
			/* translators: %s: provider name. */
			esc_html( sprintf( __( 'Privacy policy of %s', 'leitvia-studio' ), $label ) )
		);
		$open_link = '' === $open ? '' : sprintf(
			'<a href="%1$s">%2$s</a>',
			$open,
			/* translators: %s: provider name. */
			esc_html( sprintf( __( 'Open on %s', 'leitvia-studio' ), $label ) )
		);
		$id = wp_unique_id( 'leitvia-consent-' );

		return sprintf(
			'<div class="leitvia-consent" data-wp-interactive="leitvia/consent" data-wp-init="callbacks.init" data-leitvia-service="%1$s" data-leitvia-loaded="%2$s">'
				. '<div class="leitvia-consent__placeholder">'
				. '<p class="leitvia-consent__title"><strong>%3$s</strong></p>'
				. '<p class="leitvia-consent__purpose">%4$s%5$s</p>'
				. '<p class="leitvia-consent__actions" hidden data-wp-bind--hidden="state.noScript">'
				. '<button type="button" class="wp-element-button" data-wp-on--click="actions.load">%6$s</button> '
				. '<span class="leitvia-consent__always"><input type="checkbox" id="%7$s"> <label for="%7$s">%8$s</label></span>'
				. '</p>'
				. '<p class="leitvia-consent__open">%9$s</p>'
				. '</div>'
				. '<div class="leitvia-consent__content" tabindex="-1"></div>'
				. '<p class="leitvia-consent__status screen-reader-text" role="status"></p>'
				. '<template data-leitvia-consent="%1$s">%10$s</template>'
				. '</div>',
			esc_attr( $service ),
			/* translators: %s: provider name. */
			esc_attr( sprintf( __( 'Content from %s loaded.', 'leitvia-studio' ), $label ) ),
			/* translators: %s: provider name. */
			esc_html( sprintf( __( 'Content from %s', 'leitvia-studio' ), $label ) ),
			esc_html( $purpose ),
			$privacy_link,
			/* translators: %s: provider name. */
			esc_html( sprintf( __( 'Load content from %s', 'leitvia-studio' ), $label ) ),
			esc_attr( $id ),
			/* translators: %s: provider name. */
			esc_html( sprintf( __( 'Always allow %s on this site', 'leitvia-studio' ), $label ) ),
			$open_link,
			$html
		);
	}

	/**
	 * Swaps the oEmbed HTML of a core/embed block from another host for the placeholder. Runs on
	 * render_block_core/embed, after core has turned the URL into the oEmbed HTML (the_content, priority 8).
	 *
	 * @param string               $content Rendered block.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function filter_embed( string $content, array $block ): string {
		$url     = (string) ( $block['attrs']['url'] ?? '' );
		$service = self::service_for( $url );
		$open    = strpos( $content, '<div class="wp-block-embed__wrapper">' );
		$close   = strrpos( $content, '</div>' );
		if ( '' === $service || false === $open || false === $close || ! leitvia_studio_setting( 'consent_embeds' ) ) {
			return $content;
		}
		$open += strlen( '<div class="wp-block-embed__wrapper">' );
		if ( $close < $open ) {
			return $content;
		}
		$content = substr( $content, 0, $open ) . self::placeholder( $service, substr( $content, $open, $close - $open ), $url ) . substr( $content, $close );

		// The class keeps the aspect ratio on the placeholder instead of the wrapper (style.scss of consent-settings).
		$tags = new \WP_HTML_Tag_Processor( $content );
		if ( $tags->next_tag( array( 'tag_name' => 'figure' ) ) ) {
			$tags->add_class( 'has-leitvia-consent' );
		}
		return $tags->get_updated_html();
	}

	/**
	 * Puts every iframe from another host outside a <template> behind the placeholder, as filter_embed() does for
	 * core/embed, with the HTML API. Runs on render_block_core/html and on the_content at priority 12 for classic
	 * content, classic blocks and what autoembed made of a bare URL: after wpautop (10), which would break the markup
	 * of the placeholder. Iframes of the own site and without src stay; a paragraph around nothing but the iframe
	 * (wpautop) goes with it.
	 *
	 * @param string $html Markup.
	 */
	public static function filter_iframes( string $html ): string {
		if ( false === stripos( $html, '<iframe' ) || ! leitvia_studio_setting( 'consent_embeds' ) ) {
			return $html;
		}
		// An iframe is one token for the HTML API, closer included; its bookmark spans the whole element.
		$tags     = new class( $html ) extends \WP_HTML_Tag_Processor {

			/**
			 * Byte offset and length of the current token.
			 *
			 * @return array{0: int, 1: int}
			 */
			public function span(): array {
				$this->set_bookmark( 'leitvia-iframe' );
				return array( $this->bookmarks['leitvia-iframe']->start, $this->bookmarks['leitvia-iframe']->length );
			}
		};
		$found    = array();
		$template = 0;
		while ( $tags->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			if ( 'TEMPLATE' === $tags->get_tag() ) {
				$template += $tags->is_tag_closer() ? -1 : 1;
				continue;
			}
			if ( 'IFRAME' !== $tags->get_tag() || $template > 0 ) {
				continue;
			}
			$src     = $tags->get_attribute( 'src' );
			$service = is_string( $src ) ? self::service_for( $src ) : '';
			if ( '' !== $service ) {
				$found[] = array( $service, $src, ...$tags->span() );
			}
		}
		foreach ( array_reverse( $found ) as [ $service, $src, $start, $length ] ) {
			$placeholder = self::placeholder( $service, substr( $html, $start, $length ), $src );
			if ( str_ends_with( substr( $html, 0, $start ), '<p>' ) && str_starts_with( substr( $html, $start + $length ), '</p>' ) ) {
				$start  -= 3;
				$length += 7;
			}
			$html = substr_replace( $html, $placeholder, $start, $length );
		}
		return $html;
	}

	/**
	 * Hands the service labels to the module wherever the consent-settings block renders. Runs on
	 * render_block_leitvia/consent-settings.
	 *
	 * @param string $content Rendered block.
	 */
	public static function filter_settings_block( string $content ): string {
		wp_interactivity_state( 'leitvia/consent', array( 'labels' => array_map( static fn( $s ) => (string) ( $s['label'] ?? '' ), self::services() ) ) );
		self::site_state();
		return $content;
	}

	/**
	 * Hands the path of the site to the module, which stores consent under it: sites of a subdirectory network share an
	 * origin and with it the local storage of the browser, but each has its own controller and privacy policy.
	 */
	private static function site_state(): void {
		wp_interactivity_state( 'leitvia/consent', array( 'site' => trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) ) );
	}
}
