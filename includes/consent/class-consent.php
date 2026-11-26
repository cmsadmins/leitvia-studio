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
	 * Most providers the settings keep.
	 */
	public const MAX_PROVIDERS = 20;

	/**
	 * Most host names a provider keeps.
	 */
	public const MAX_HOSTS = 10;

	/**
	 * Services Studio knows without settings: key => label, host names (subdomains included) and privacy policy.
	 */
	private const SERVICES = array(
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
	 * Known services: key => label, host names (subdomains included), the provider's privacy policy and an optional
	 * purpose. The built-in services come first, then the providers saved under Settings, Privacy (setting
	 * consent_providers), which never replace a built-in one, then the filter.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function services(): array {
		$services = self::SERVICES;
		foreach ( (array) leitvia_studio_setting( 'consent_providers' ) as $provider ) {
			if ( is_array( $provider ) && is_string( $provider['key'] ?? null ) && ! isset( $services[ $provider['key'] ] ) ) {
				// An empty purpose or privacy policy stays out: the placeholder then uses its own sentence and shows no link.
				$services[ $provider['key'] ] = array_filter( array_intersect_key( $provider, array_flip( array( 'label', 'hosts', 'purpose', 'privacy' ) ) ) );
			}
		}

		/**
		 * Filters the services the consent placeholder knows. Add a service, change its label, privacy policy or
		 * purpose (one sentence shown in the placeholder). The providers saved under Settings, Privacy are in the list
		 * already. The keys statistics and marketing are reserved for the categories of scripts (Scripts::categories())
		 * and dropped. An embed from a host no service lists still gets a placeholder, named after the host.
		 *
		 * @param array<string, array<string, mixed>> $services
		 */
		$services = (array) apply_filters( 'leitvia_studio_consent_services', $services );
		// The categories of scripts share the list of allowed keys in the browser (Scripts); a service never takes one.
		return array_diff_key( $services, Scripts::categories() );
	}

	/**
	 * Providers a person adds under Settings, Privacy: a label, host names (lowercase, without scheme, port and path,
	 * subdomains included, never the own site), an optional purpose and the https address of the provider's privacy
	 * policy. A provider without a label or a valid host is dropped, at most MAX_PROVIDERS stay with MAX_HOSTS hosts
	 * each. The key is the saved one or one made from the label; saved keys are claimed before new ones are made, it
	 * never takes a key of a built-in service or of a category of scripts, and two providers never share one, so "Always
	 * allow" stays with its provider when the label changes or a new provider comes first. Runs from
	 * Settings::sanitize().
	 *
	 * @param mixed $providers Submitted list.
	 * @return list<array{key: string, label: string, hosts: list<string>, purpose: string, privacy: string}>
	 */
	public static function sanitize_providers( $providers ): array {
		$text  = static fn( $value ): string => is_scalar( $value ) ? (string) $value : '';
		$own   = Scripts::host( home_url() );
		$taken = array_fill_keys( array_merge( array_keys( self::SERVICES ), array_keys( Scripts::categories() ) ), true );
		$kept  = array();
		foreach ( is_array( $providers ) ? $providers : array() as $provider ) {
			$provider = is_array( $provider ) ? $provider : array();
			$label    = sanitize_text_field( $text( $provider['label'] ?? '' ) );
			$hosts    = array();
			foreach ( (array) ( $provider['hosts'] ?? array() ) as $host ) {
				$host = Scripts::host( $text( $host ) );
				if ( $host !== $own && preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) ) {
					$hosts[] = $host;
				}
			}
			$hosts = array_slice( array_values( array_unique( $hosts ) ), 0, self::MAX_HOSTS );
			if ( '' === $label || ! $hosts || count( $kept ) >= self::MAX_PROVIDERS ) {
				continue;
			}
			$kept[] = array(
				// sanitize_title() keeps percent signs of non-Latin letters; sanitize_key() drops them, so the key stays the same on the next save.
				'key'     => sanitize_key( $text( $provider['key'] ?? '' ) ),
				'label'   => $label,
				'hosts'   => $hosts,
				'purpose' => sanitize_text_field( $text( $provider['purpose'] ?? '' ) ),
				'privacy' => esc_url_raw( $text( $provider['privacy'] ?? '' ), array( 'https' ) ),
			);
		}
		// Saved keys first, so a new provider listed before a saved one never takes its key.
		$claimed = array();
		foreach ( $kept as $i => $provider ) {
			if ( '' !== $provider['key'] && ! isset( $taken[ $provider['key'] ] ) ) {
				$taken[ $provider['key'] ] = true;
				$claimed[ $i ]             = true;
			}
		}
		foreach ( $kept as $i => $provider ) {
			if ( isset( $claimed[ $i ] ) ) {
				continue;
			}
			$base = '' !== $provider['key'] ? $provider['key'] : sanitize_key( sanitize_title( $provider['label'] ) );
			$base = '' !== $base ? $base : 'provider';
			$key  = $base;
			for ( $n = 2; isset( $taken[ $key ] ); $n++ ) {
				$key = $base . '-' . $n;
			}
			$taken[ $key ]     = true;
			$kept[ $i ]['key'] = $key;
		}
		return $kept;
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
				// Hosts added by the filter may come in any case.
				$pattern = strtolower( (string) $pattern );
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
	 * @param string $preview      HTML of an image of the own site shown behind the texts until the content loads, such
	 *                             as wp_get_attachment_image(); '' for none. Never an image of the provider.
	 */
	public static function placeholder( string $service, string $html, string $fallback_url, string $preview = '' ): string {
		$services = self::services();
		$label    = self::label( $service );
		$privacy  = esc_url( (string) ( $services[ $service ]['privacy'] ?? '' ) );
		$open     = esc_url( $fallback_url );
		// The purpose a provider names comes first; the sentence on what loading sends is always there.
		$purpose = trim(
			(string) ( $services[ $service ]['purpose'] ?? '' ) . ' ' . sprintf(
				/* translators: %s: provider name, for example YouTube. */
				__( 'This content is hosted by %s. Loading it sends your IP address and details of your device to the provider, which may also set cookies.', 'leitvia-studio' ),
				$label
			)
		);

		wp_enqueue_script_module( self::MODULE );
		wp_enqueue_style( 'leitvia-consent-settings-style' );
		self::site_state();
		Consent_Link::embed();

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

		$texts = sprintf(
			'<p class="leitvia-consent__title"><strong>%1$s</strong></p>'
				. '<p class="leitvia-consent__purpose">%2$s%3$s</p>'
				. '<p class="leitvia-consent__actions" hidden data-wp-bind--hidden="state.noScript">'
				. '<button type="button" class="wp-element-button" data-wp-on--click="actions.load">%4$s</button> '
				. '<span class="leitvia-consent__always"><input type="checkbox" id="%5$s"> <label for="%5$s">%6$s</label></span>'
				. '</p>'
				. '<p class="leitvia-consent__open">%7$s</p>',
			/* translators: %s: provider name. */
			esc_html( sprintf( __( 'Content from %s', 'leitvia-studio' ), $label ) ),
			esc_html( $purpose ),
			$privacy_link,
			/* translators: %s: provider name. */
			esc_html( sprintf( __( 'Load content from %s', 'leitvia-studio' ), $label ) ),
			esc_attr( $id ),
			/* translators: %s: provider name. */
			esc_html( sprintf( __( 'Always allow %s on this site', 'leitvia-studio' ), $label ) ),
			$open_link
		);
		// The texts sit on a surface of their own in front of a preview image (style.scss of consent-settings).
		$placeholder = '' === $preview
			? '<div class="leitvia-consent__placeholder">' . $texts . '</div>'
			: '<div class="leitvia-consent__placeholder has-preview">' . $preview . '<div class="leitvia-consent__text">' . $texts . '</div></div>';

		return sprintf(
			'<div class="leitvia-consent" data-wp-interactive="leitvia/consent" data-wp-init="callbacks.init" data-leitvia-service="%1$s" data-leitvia-loaded="%2$s">'
				. '%3$s'
				. '<div class="leitvia-consent__content" tabindex="-1"></div>'
				. '<p class="leitvia-consent__status screen-reader-text" role="status"></p>'
				. '<template data-leitvia-consent="%1$s">%4$s</template>'
				. '</div>',
			esc_attr( $service ),
			/* translators: %s: provider name. */
			esc_attr( sprintf( __( 'Content from %s loaded.', 'leitvia-studio' ), $label ) ),
			$placeholder,
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
	 * The same for an embed block saved under one of the old names (core-embed/twitter, core-embed/youtube and the
	 * rest, posts from before WordPress 5.6, the Theme Unit Test Data among them): render_block_core/embed never fires
	 * for them, so the script of X loaded without consent. Runs on render_block for every block and leaves every other
	 * block alone.
	 *
	 * @param string               $content Rendered block.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public static function filter_legacy_embed( string $content, array $block ): string {
		return str_starts_with( (string) ( $block['blockName'] ?? '' ), 'core-embed/' ) ? self::filter_embed( $content, $block ) : $content;
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
			 * @return list{int, int}
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
		wp_interactivity_state( 'leitvia/consent', array( 'labels' => array_map( static fn( $s ) => (string) ( $s['label'] ?? '' ), self::services() ) + Scripts::categories() ) );
		self::site_state();
		// The categories of scripts that wait for consent (Scripts), above the status line the block shares with them.
		$scripts = Scripts::settings_markup();
		$status  = '<p role="status" data-wp-text="state.status"></p>';
		if ( '' !== $scripts && str_contains( $content, $status ) ) {
			$content = str_replace( $status, $scripts . $status, $content );
		}
		// Save writes these texts into the markup, in the language of whoever saved it; a block saved in English follows the site.
		return strtr(
			$content,
			array(
				'Without JavaScript, this site stores no consent: content from other providers stays a link.' => esc_html__( 'Without JavaScript, this site stores no consent: content from other providers stays a link.', 'leitvia-studio' ),
				'You have not allowed any provider on this site in this browser.' => esc_html__( 'You have not allowed any provider on this site in this browser.', 'leitvia-studio' ),
				/* translators: %s: provider name, for example YouTube. */
				'%s is no longer allowed.' => esc_attr__( '%s is no longer allowed. This applies the next time a page loads.', 'leitvia-studio' ),
				'>Revoke '                 => '>' . esc_html__( 'Revoke', 'leitvia-studio' ) . ' ',
			)
		);
	}

	/**
	 * Hands the path of the site to the module, which stores consent under it: sites of a subdirectory network share an
	 * origin and with it the local storage of the browser, but each has its own controller and privacy policy. The rest
	 * is the state without JavaScript, for the server to render the directives: the module sets noScript to false and
	 * fills the list.
	 */
	public static function site_state(): void {
		wp_interactivity_state(
			'leitvia/consent',
			array(
				'site'     => trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ),
				'noScript' => true,
				'list'     => array(),
				'hasList'  => false,
			)
		);
	}
}
