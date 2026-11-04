<?php
/**
 * Share: plain links to share the current post on a few services, rendered on the server.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Every service is an ordinary link to its own share page, built from the address and title of the post; nothing
 * loads from a service before the visitor clicks (no script, no image, no counter, no tracking parameter). The marks
 * are small inline SVGs in currentColor (Simple Icons, CC0; envelope, link and share are our own). "Copy link" and
 * the "Share…" button of the Web Share API need scripting: they render hidden and the view module shows the ones the
 * browser supports.
 */
final class Share {

	public const BLOCK = 'leitvia/share';

	public const SERVICES = array( 'linkedin', 'bluesky', 'facebook', 'whatsapp', 'threads', 'xing', 'email', 'copy' );

	public const DEFAULT_SERVICES = array( 'linkedin', 'bluesky', 'whatsapp', 'email', 'copy' );

	public const DISPLAYS = array( 'text', 'icon-text', 'icon' );

	/**
	 * Share page per service; {url}, {title} and {text} (title and address) are replaced raw-URL-encoded.
	 */
	private const ENDPOINTS = array(
		'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}',
		'bluesky'  => 'https://bsky.app/intent/compose?text={text}',
		'facebook' => 'https://www.facebook.com/sharer/sharer.php?u={url}',
		'whatsapp' => 'https://wa.me/?text={text}',
		'threads'  => 'https://www.threads.com/intent/post?text={text}',
		'xing'     => 'https://www.xing.com/spi/shares/new?url={url}',
		'email'    => 'mailto:?subject={title}&body={url}',
	);

	/**
	 * Path data of the marks, viewBox 0 0 24 24. Brand marks filled, own marks stroked.
	 */
	private const ICONS = array(
		'linkedin' => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
		'bluesky'  => 'M12 10.8c-1.087-2.114-4.046-6.053-6.798-7.995C2.566.944 1.561 1.266.902 1.565.139 1.908 0 3.08 0 3.768c0 .69.378 5.65.624 6.479.815 2.736 3.713 3.66 6.383 3.364.136-.02.275-.039.415-.056-.138.022-.276.04-.415.056-3.912.58-7.387 2.005-2.83 7.078 5.013 5.19 6.87-1.113 7.823-4.308.953 3.195 2.05 9.271 7.733 4.308 4.267-4.308 1.172-6.498-2.74-7.078a8.741 8.741 0 0 1-.415-.056c.14.017.279.036.415.056 2.67.297 5.568-.628 6.383-3.364.246-.828.624-5.79.624-6.478 0-.69-.139-1.861-.902-2.206-.659-.298-1.664-.62-4.3 1.24C16.046 4.748 13.087 8.687 12 10.8Z',
		'facebook' => 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z',
		'whatsapp' => 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z',
		'threads'  => 'M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.589 12c.027 3.086.718 5.496 2.057 7.164 1.43 1.783 3.631 2.698 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.964-.065-1.19.408-2.285 1.33-3.082.88-.76 2.119-1.207 3.583-1.291a13.853 13.853 0 0 1 3.02.142c-.126-.742-.375-1.332-.75-1.757-.513-.586-1.308-.883-2.359-.89h-.029c-.844 0-1.992.232-2.721 1.32L7.734 7.847c.98-1.454 2.568-2.256 4.478-2.256h.044c3.194.02 5.097 1.975 5.287 5.388.108.046.216.094.321.142 1.49.7 2.58 1.761 3.154 3.07.797 1.82.871 4.79-1.548 7.158-1.85 1.81-4.094 2.628-7.277 2.65Zm1.003-11.69c-.242 0-.487.007-.739.021-1.836.103-2.98.946-2.916 2.143.067 1.256 1.452 1.839 2.784 1.767 1.224-.065 2.818-.543 3.086-3.71a10.5 10.5 0 0 0-2.215-.221z',
		'xing'     => 'M18.188 0c-.517 0-.741.325-.927.66 0 0-7.455 13.224-7.702 13.657.015.024 4.919 9.023 4.919 9.023.17.308.436.66.967.66h3.454c.211 0 .375-.078.463-.22.089-.151.089-.346-.009-.536l-4.879-8.916c-.004-.006-.004-.016 0-.022L22.139.756c.095-.191.097-.387.006-.535C22.056.078 21.894 0 21.686 0h-3.498zM3.648 4.74c-.211 0-.385.074-.473.216-.09.149-.078.339.02.531l2.34 4.05c.004.01.004.016 0 .021L1.86 16.051c-.099.188-.093.381 0 .529.085.142.239.234.45.234h3.461c.518 0 .766-.348.945-.667l3.734-6.609-2.378-4.155c-.172-.315-.434-.659-.962-.659H3.648v.016z',
		'email'    => 'M3 5h18v14H3zM3 5l9 8 9-8',
		'copy'     => 'M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1',
		'native'   => 'M12 3v12M7 8l5-5 5 5M5 13v8h14v-8',
	);

	/**
	 * Renders the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param \WP_Block|null       $block      Block instance.
	 */
	public static function render( array $attributes, ?\WP_Block $block = null ): string {
		$services = self::services( $attributes['services'] ?? self::DEFAULT_SERVICES );
		if ( ! $services ) {
			return '';
		}
		$display             = in_array( $attributes['display'] ?? '', self::DISPLAYS, true ) ? $attributes['display'] : 'icon-text';
		list( $url, $title ) = self::target( (int) ( $block->context['postId'] ?? 0 ) );
		$values              = array(
			'{url}'   => rawurlencode( $url ),
			'{title}' => rawurlencode( $title ),
			'{text}'  => rawurlencode( '' === $title ? $url : $title . ' ' . $url ),
		);
		/* translators: %s: name of the service, for example LinkedIn. */
		$share_on = __( 'Share on %s', 'leitvia-studio' );
		/* translators: %s: name of a link, for example "Share on LinkedIn". */
		$new_window = __( '%s (opens in a new window)', 'leitvia-studio' );

		$items = '';
		foreach ( $services as $service ) {
			if ( 'copy' === $service ) {
				$items .= self::button( 'copy', __( 'Copy link', 'leitvia-studio' ) );
				continue;
			}
			$label  = 'email' === $service ? __( 'Email', 'leitvia-studio' ) : self::brand( $service );
			$name   = 'email' === $service ? __( 'Share by email', 'leitvia-studio' ) : sprintf( $new_window, sprintf( $share_on, $label ) );
			$items .= sprintf(
				'<li class="wp-block-leitvia-share__item"><a class="wp-block-leitvia-share__link" href="%1$s" aria-label="%2$s"%3$s>%4$s<span class="wp-block-leitvia-share__label">%5$s</span></a></li>',
				esc_url( strtr( self::ENDPOINTS[ $service ], $values ), array( 'https', 'mailto' ) ),
				esc_attr( $name ),
				'email' === $service ? '' : ' target="_blank" rel="noopener noreferrer"',
				self::icon( $service ),
				esc_html( $label )
			);
		}
		// The Web Share API offers the apps of the device; it stays hidden where the browser has none.
		$items .= self::button( 'native', __( 'Share…', 'leitvia-studio' ) );

		return sprintf(
			'<nav %1$s><ul class="wp-block-leitvia-share__list">%2$s</ul><p class="wp-block-leitvia-share__status" role="status" data-copied="%3$s" data-failed="%4$s"></p></nav>',
			get_block_wrapper_attributes(
				array(
					'class'      => 'is-display-' . $display,
					'aria-label' => __( 'Share this post', 'leitvia-studio' ),
					'data-url'   => esc_url_raw( $url ),
					'data-title' => $title,
				)
			),
			$items,
			esc_attr__( 'Link copied', 'leitvia-studio' ),
			esc_attr__( 'Copy failed, copy the address from the address bar', 'leitvia-studio' )
		);
	}

	/**
	 * Valid services from an attribute value, in the given order, each once.
	 *
	 * @param mixed $services Attribute value.
	 * @return array<int, string>
	 */
	public static function services( $services ): array {
		return array_values( array_unique( array_intersect( array_map( 'strval', (array) $services ), self::SERVICES ) ) );
	}

	/**
	 * Address and plain title to share: the post of the block context when visitors can see it, else the current page
	 * without its query string.
	 *
	 * @param int $post_id Post ID from the block context, 0 for none.
	 * @return array{0: string, 1: string}
	 */
	public static function target( int $post_id ): array {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( $post && is_post_publicly_viewable( $post ) ) {
			return array( (string) get_permalink( $post ), self::plain( get_the_title( $post ) ) );
		}
		global $wp;
		$path = $wp instanceof \WP ? (string) $wp->request : '';
		return array( home_url( '' === $path ? '/' : user_trailingslashit( $path ) ), self::plain( wp_get_document_title() ) );
	}

	/**
	 * Text without tags and entities.
	 *
	 * @param string $html Title HTML.
	 */
	private static function plain( string $html ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Brand name of a service, not translated.
	 *
	 * @param string $service Service key.
	 */
	private static function brand( string $service ): string {
		$brands = array(
			'linkedin' => 'LinkedIn',
			'bluesky'  => 'Bluesky',
			'facebook' => 'Facebook',
			'whatsapp' => 'WhatsApp',
			'threads'  => 'Threads',
			'xing'     => 'XING',
		);
		return $brands[ $service ] ?? $service;
	}

	/**
	 * Hidden button the view module shows when the browser supports it.
	 *
	 * @param string $action copy or native.
	 * @param string $label  Text of the button, visually hidden in the icon-only display.
	 */
	private static function button( string $action, string $label ): string {
		return sprintf(
			'<li class="wp-block-leitvia-share__item wp-block-leitvia-share__item--%1$s" hidden><button type="button" class="wp-block-leitvia-share__link" data-share="%1$s">%2$s<span class="wp-block-leitvia-share__label">%3$s</span></button></li>',
			esc_attr( $action ),
			self::icon( $action ),
			esc_html( $label )
		);
	}

	/**
	 * Inline mark in currentColor, hidden from assistive technology.
	 *
	 * @param string $name Key of ICONS.
	 */
	private static function icon( string $name ): string {
		$own = in_array( $name, array( 'email', 'copy', 'native' ), true );
		return sprintf(
			'<svg class="wp-block-leitvia-share__icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"%1$s><path d="%2$s"/></svg>',
			$own ? ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' : ' fill="currentColor"',
			esc_attr( self::ICONS[ $name ] )
		);
	}
}
