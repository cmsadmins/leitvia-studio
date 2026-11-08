<?php
/**
 * Contact form: name, email and message, sent with wp_mail().
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * A dynamic block that handles its own POST while it renders, on the address of the page it stands on. That way it
 * works wherever a block can stand (post content, synced pattern, template part), and recipient and subject are the
 * attributes of the block being rendered: no field of the request can name them, and neither is ever printed.
 *
 * Block themes render before any output, so a sent form redirects (post, redirect, get). A classic theme that has
 * started its output shows the confirmation in the POST response instead.
 *
 * The block renders itself as a leitvia/form-errors block: that block fills its summary from aria-invalid and
 * aria-describedby on the server, validates in the browser and brings its stylesheet. No script of its own.
 *
 * Nothing is stored. The rate limit keeps a counter under a salted hash of the IP address for an hour.
 */
final class Contact_Form {

	/**
	 * Below this time between page and submit a form counts as filled in by a bot. Cached pages carry an older time, so
	 * on them this check passes; honeypot and rate limit still apply. Public, so extensions with a form of their own
	 * that use token() check the same time.
	 */
	public const MIN_SECONDS = 3;

	/**
	 * Messages per IP address and hour. A site behind a proxy that hides REMOTE_ADDR raises it with the filter
	 * leitvia_studio_contact_form_limit, since all visitors then share one counter.
	 */
	private const LIMIT = 5;

	/**
	 * Prefix of the field names: a plain "name" in a POST request is a public query var of core and ends in a 404.
	 */
	private const PREFIX = 'leitvia_contact_';

	/**
	 * Longest accepted input per field.
	 */
	private const MAX = array(
		'name'    => 200,
		'email'   => 254,
		'message' => 10000,
	);

	/**
	 * Result of the POST request per form key, so a block rendered twice in one request sends once.
	 *
	 * @var array<string, array{sent: bool, values: array<string, string>, errors: array<string, string>, alert: string}>
	 */
	private static array $results = array();

	/**
	 * Renders the block and handles a submitted form of this block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		// ponytail: the key covers all attributes, so a page cached before the block was edited posts into nothing and shows an empty form. A stored form id would end this; editing a live form is rare.
		$key    = substr( wp_hash( 'leitvia-contact-form' . wp_json_encode( $attributes ) ), 0, 12 );
		$result = self::posted( 'form' ) === $key ? self::$results[ $key ] ??= self::submit( $attributes, $key ) : null;
		if ( ( $result['sent'] ?? false ) && ! headers_sent() ) {
			wp_safe_redirect( add_query_arg( 'leitvia-sent', $key ), 303 );
			exit;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compared, shows a fixed message.
		$shown = sanitize_key( wp_unslash( $_GET['leitvia-sent'] ?? '' ) );
		$sent  = ( $result['sent'] ?? false ) || $key === $shown;
		$html  = $sent ? self::notice( $attributes ) : self::form( $attributes, $key, $result );
		$html  = sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( array( 'class' => 'wp-block-leitvia-form-errors' ) ), $html );
		return render_block(
			array(
				'blockName'    => 'leitvia/form-errors',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $html,
				'innerContent' => array( $html ),
			)
		);
	}

	/**
	 * Hidden time field: the time of the page, signed for this form. Replaces the nonce, which a cached page would
	 * outlive; the form acts for no logged-in user.
	 *
	 * @param string $key  Form key.
	 * @param int    $time Unix time.
	 */
	public static function token( string $key, int $time ): string {
		return $time . '.' . wp_hash( 'leitvia-contact-form|' . $key . '|' . $time );
	}

	/**
	 * Checks and sends one submission.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $key        Form key.
	 * @return array{sent: bool, values: array<string, string>, errors: array<string, string>, alert: string}
	 */
	private static function submit( array $attributes, string $key ): array {
		$result = array(
			'sent'   => false,
			'values' => array(
				'name'    => mb_substr( sanitize_text_field( self::posted( 'name' ) ), 0, self::MAX['name'] ),
				'email'   => mb_substr( sanitize_text_field( self::posted( 'email' ) ), 0, self::MAX['email'] ),
				'message' => mb_substr( sanitize_textarea_field( self::posted( 'message' ) ), 0, self::MAX['message'] ),
			),
			'errors' => array(),
			'alert'  => '',
		);
		// A filled trap looks like a success, so a bot learns nothing.
		if ( '' !== self::posted( 'homepage' ) ) {
			return array( 'sent' => true ) + $result;
		}
		$token = self::posted( 'token' );
		$time  = (int) strtok( $token, '.' );
		if ( ! hash_equals( self::token( $key, $time ), $token ) || time() - $time < self::MIN_SECONDS ) {
			return array( 'alert' => __( 'Your message was not sent yet. Please wait a moment and send it again.', 'leitvia-studio' ) ) + $result;
		}
		$values = $result['values'];
		$email  = is_email( $values['email'] );
		$errors = array_filter(
			array(
				'name'    => '' === $values['name'] ? __( 'Enter your name.', 'leitvia-studio' ) : '',
				'email'   => $email ? '' : __( 'Enter a valid email address.', 'leitvia-studio' ),
				'message' => '' === $values['message'] ? __( 'Enter your message.', 'leitvia-studio' ) : '',
			)
		);
		if ( $errors || ! $email ) {
			return array( 'errors' => $errors ) + $result;
		}
		$ip    = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$limit = 'leitvia_contact_' . substr( wp_hash( 'leitvia-contact-limit|' . $ip ), 0, 24 );
		$count = (int) get_transient( $limit );
		if ( $count >= (int) apply_filters( 'leitvia_studio_contact_form_limit', self::LIMIT ) ) {
			return array( 'alert' => __( 'Too many messages came from your connection. Please try again later.', 'leitvia-studio' ) ) + $result;
		}
		set_transient( $limit, $count + 1, HOUR_IN_SECONDS );
		if ( self::mail( $attributes, $values, $email ) ) {
			return array( 'sent' => true ) + $result;
		}
		return array( 'alert' => __( 'Your message could not be sent. Please try again later.', 'leitvia-studio' ) ) + $result;
	}

	/**
	 * Sends the message to the recipient of the block. Only the checked address goes into a header, as a bare address;
	 * subject and recipient come from the block, so no text of a visitor reaches any other header. The sender stays the
	 * default of the site: a visitor's address as sender fails SPF.
	 *
	 * @param array<string, mixed>  $attributes Block attributes.
	 * @param array<string, string> $values     Sanitized input.
	 * @param string                $email      Address that passed is_email().
	 */
	private static function mail( array $attributes, array $values, string $email ): bool {
		$to      = is_email( (string) ( $attributes['recipient'] ?? '' ) );
		$subject = sanitize_text_field( (string) ( $attributes['subject'] ?? '' ) );
		/* translators: %s: site title. */
		$subject = '' !== $subject ? $subject : sprintf( __( 'New message from %s', 'leitvia-studio' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body    = sprintf(
			"%s: %s\n%s: %s\n\n%s\n",
			__( 'Name', 'leitvia-studio' ),
			$values['name'],
			__( 'Email address', 'leitvia-studio' ),
			$email,
			$values['message']
		);
		return wp_mail( $to ? $to : (string) get_option( 'admin_email' ), $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $email ) );
	}

	/**
	 * The confirmation. It takes the focus without JavaScript through autofocus, so screen readers read it on arrival.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private static function notice( array $attributes ): string {
		return sprintf(
			'<p class="wp-block-leitvia-contact-form__notice" role="status" tabindex="-1" autofocus>%s</p>',
			esc_html( self::text( $attributes, 'successMessage', __( 'Thank you. Your message has been sent.', 'leitvia-studio' ) ) )
		);
	}

	/**
	 * The form with the summary of leitvia/form-errors. Server errors are marked on the fields, Form_Errors::render()
	 * builds the summary from them. A field without a server error carries its message for the view module of that
	 * block; on a field with one the module takes the message from the page.
	 *
	 * @param array<string, mixed>                                                                                $attributes Block attributes.
	 * @param string                                                                                              $key        Form key.
	 * @param array{sent: bool, values: array<string, string>, errors: array<string, string>, alert: string}|null $result     Result of the POST request.
	 */
	private static function form( array $attributes, string $key, ?array $result ): string {
		$id     = wp_unique_id( 'leitvia-contact-' );
		$values = $result['values'] ?? array();
		$errors = $result['errors'] ?? array();
		$fields = array(
			'name'    => array( self::text( $attributes, 'nameLabel', __( 'Name', 'leitvia-studio' ) ), __( 'Enter your name.', 'leitvia-studio' ) ),
			'email'   => array( self::text( $attributes, 'emailLabel', __( 'Email address', 'leitvia-studio' ) ), __( 'Enter a valid email address.', 'leitvia-studio' ) ),
			'message' => array( self::text( $attributes, 'messageLabel', __( 'Message', 'leitvia-studio' ) ), __( 'Enter your message.', 'leitvia-studio' ) ),
		);
		$html   = '';
		foreach ( $fields as $name => list( $label, $hint ) ) {
			$field   = $id . '-' . $name;
			$invalid = isset( $errors[ $name ] );
			$attrs   = sprintf( ' id="%s" name="%s" maxlength="%d" required', esc_attr( $field ), esc_attr( self::PREFIX . $name ), self::MAX[ $name ] );
			$attrs  .= $invalid ? sprintf( ' aria-invalid="true" aria-describedby="%s-error"', esc_attr( $field ) ) : sprintf( ' data-error="%s"', esc_attr( $hint ) );
			$value   = $values[ $name ] ?? '';
			$control = 'message' === $name
				? sprintf( '<textarea rows="6"%s>%s</textarea>', $attrs, self::no_shortcode( esc_textarea( $value ) ) )
				: sprintf( '<input type="%s" autocomplete="%s" value="%s"%s>', 'email' === $name ? 'email' : 'text', $name, self::no_shortcode( esc_attr( $value ) ), $attrs );
			$html   .= sprintf(
				'<div class="wp-block-leitvia-contact-form__field"><label for="%1$s">%2$s</label>%3$s%4$s</div>',
				esc_attr( $field ),
				esc_html( $label ),
				$invalid ? sprintf( '<span id="%s-error" class="wp-block-leitvia-form-errors__message">%s</span>', esc_attr( $field ), esc_html( $errors[ $name ] ) ) : '',
				$control
			);
		}
		$alert = (string) ( $result['alert'] ?? '' );
		if ( '' !== $alert ) {
			$alert = sprintf( '<p class="wp-block-leitvia-contact-form__notice is-error" role="alert" tabindex="-1" autofocus>%s</p>', esc_html( $alert ) );
		}
		return sprintf(
			'%1$s<form method="post">%2$s<p class="wp-block-leitvia-contact-form__hint">%3$s</p>%4$s<div class="wp-block-leitvia-contact-form__trap" aria-hidden="true"><label for="%5$s-homepage">%6$s</label><input type="text" id="%5$s-homepage" name="%7$shomepage" tabindex="-1" autocomplete="off"></div><input type="hidden" name="%7$sform" value="%8$s"><input type="hidden" name="%7$stoken" value="%9$s">%10$s<p><button type="submit" class="wp-element-button">%11$s</button></p></form>',
			Form_Errors::SUMMARY,
			$alert,
			esc_html( self::text( $attributes, 'requiredNote', __( 'All fields are required.', 'leitvia-studio' ) ) ),
			$html,
			esc_attr( $id ),
			esc_html__( 'Leave this field empty', 'leitvia-studio' ),
			self::PREFIX,
			esc_attr( $key ),
			esc_attr( self::token( $key, time() ) ),
			self::privacy_note( $attributes ),
			esc_html( self::text( $attributes, 'buttonText', __( 'Send message', 'leitvia-studio' ) ) )
		);
	}

	/**
	 * A text of the block: its attribute, or the translated default while the attribute is empty. So no block carries a
	 * default in its content, and blocks without own texts follow the language of the site.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $name       Attribute.
	 * @param string               $fallback   Translated default.
	 */
	private static function text( array $attributes, string $name, string $fallback ): string {
		$text = (string) ( $attributes[ $name ] ?? '' );
		return '' !== $text ? $text : $fallback;
	}

	/**
	 * The note on data use, with the link of core to the privacy policy page while the site has a published one.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private static function privacy_note( array $attributes ): string {
		if ( ! ( $attributes['privacyNote'] ?? true ) ) {
			return '';
		}
		return sprintf(
			'<p class="wp-block-leitvia-contact-form__hint">%s %s</p>',
			esc_html( self::text( $attributes, 'privacyText', __( 'We use your details only to answer your message and do not store them on this website.', 'leitvia-studio' ) ) ),
			get_the_privacy_policy_link()
		);
	}

	/**
	 * Hides square brackets of a visitor's text in entities: the_content runs do_shortcode after the blocks, and a form
	 * shown again would run a shortcode the visitor typed.
	 *
	 * @param string $escaped Text escaped for HTML.
	 */
	private static function no_shortcode( string $escaped ): string {
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $escaped );
	}

	/**
	 * One field of the POST request as a raw string, empty when it is missing or no string.
	 *
	 * @param string $name Field name without prefix.
	 */
	private static function posted( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Signed time field in place of a nonce (token()); every caller sanitizes.
		$value = wp_unslash( $_POST[ self::PREFIX . $name ] ?? '' );
		return is_string( $value ) ? $value : '';
	}
}
