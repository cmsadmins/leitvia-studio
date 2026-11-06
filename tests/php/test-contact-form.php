<?php
/**
 * Contact form: rendering, spam checks and delivery.
 *
 * @package LeitviaStudio
 */

use LeitviaStudio\Blocks\Contact_Form;

/**
 * Each test uses its own attributes, since a form is handled once per request and per set of attributes. In PHPUnit
 * output has started, so a sent form takes the branch for classic themes: the confirmation in the POST response.
 */
class Test_Contact_Form extends WP_UnitTestCase {

	/**
	 * Mails that reached wp_mail().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

	/**
	 * Answer of wp_mail(): true sent, false failed.
	 *
	 * @var bool
	 */
	private bool $delivers = true;

	/**
	 * Captures mail instead of sending it.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter(
			'pre_wp_mail',
			function ( $result, array $atts ): bool {
				$this->mails[] = $atts;
				return $this->delivers;
			},
			10,
			2
		);
	}

	/**
	 * Clears the request.
	 */
	public function tear_down(): void {
		$_POST = array();
		$_GET  = array();
		parent::tear_down();
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function html( array $attributes ): string {
		return do_blocks( get_comment_delimited_block_content( 'leitvia/contact-form', $attributes, '' ) );
	}

	/**
	 * The form key in the rendered block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function key( array $attributes ): string {
		$tags = new WP_HTML_Tag_Processor( $this->html( $attributes ) );
		while ( $tags->next_tag( 'input' ) ) {
			if ( 'leitvia_contact_form' === $tags->get_attribute( 'name' ) ) {
				return (string) $tags->get_attribute( 'value' );
			}
		}
		return '';
	}

	/**
	 * Submits the form with the given fields. Without a token in $fields the form was opened a minute ago.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $fields     Fields without the prefix, over valid defaults.
	 */
	private function submit( array $attributes, array $fields = array() ): string {
		$key     = $this->key( $attributes );
		$fields += array(
			'form'    => $key,
			'token'   => Contact_Form::token( $key, time() - 60 ),
			'name'    => 'Alex Example',
			'email'   => 'alex@example.com',
			'message' => 'Hello there',
		);
		$_POST   = array();
		foreach ( $fields as $name => $value ) {
			$_POST[ str_starts_with( $name, 'raw:' ) ? substr( $name, 4 ) : 'leitvia_contact_' . $name ] = $value;
		}
		return $this->html( $attributes );
	}

	/**
	 * The page holds labels and the three required fields, and never the recipient or the subject.
	 */
	public function test_form_shows_labels_and_hides_the_recipient(): void {
		$html = $this->html(
			array(
				'recipient' => 'owner@example.com',
				'subject'   => 'Secret subject',
			)
		);
		foreach ( array( 'Name', 'Email address', 'Message', 'Send message' ) as $text ) {
			$this->assertStringContainsString( '>' . $text . '<', $html );
		}
		$this->assertSame( 3, substr_count( $html, ' required data-error' ) );
		$this->assertStringContainsString( 'autocomplete="email"', $html );
		$this->assertStringContainsString( 'wp-block-leitvia-form-errors__summary', $html );
		$this->assertStringNotContainsString( 'owner@example.com', $html );
		$this->assertStringNotContainsString( 'Secret subject', $html );
	}

	/**
	 * Empty attributes keep the translated defaults, so the block stores no text; own texts replace them.
	 */
	public function test_labels_from_attributes(): void {
		$german = static fn( string $translation, string $text ): string => 'Message' === $text ? 'Nachricht' : $translation;
		add_filter( 'gettext', $german, 10, 2 );
		$html   = $this->html( array() );
		remove_filter( 'gettext', $german, 10 );
		$this->assertStringContainsString( '>Nachricht<', $html );
		$html = $this->html(
			array(
				'nameLabel'    => 'Ihr Name',
				'emailLabel'   => 'Ihre E-Mail',
				'messageLabel' => 'Ihr Anliegen',
				'requiredNote' => 'Bitte alles ausfüllen.',
			)
		);
		foreach ( array( 'Ihr Name', 'Ihre E-Mail', 'Ihr Anliegen', 'Bitte alles ausfüllen.' ) as $text ) {
			$this->assertStringContainsString( '>' . $text . '<', $html );
		}
		$this->assertStringNotContainsString( '>Email address<', $html );
	}

	/**
	 * The Studio pattern holds the block, so it renders the form.
	 */
	public function test_pattern_renders_the_form(): void {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'leitvia-studio/cta-contact-form' );
		$this->assertStringContainsString( 'name="leitvia_contact_message"', do_blocks( $pattern['content'] ) );
	}

	/**
	 * The privacy note links the privacy policy page and can be switched off.
	 */
	public function test_privacy_note(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $page );
		$this->assertStringContainsString( 'class="privacy-policy-link"', $this->html( array() ) );
		$this->assertStringContainsString( 'Own note', $this->html( array( 'privacyText' => 'Own note' ) ) );
		$this->assertStringNotContainsString( 'privacy-policy-link', $this->html( array( 'privacyNote' => false ) ) );
	}

	/**
	 * A valid submission goes to the block recipient with the visitor as Reply-To and a confirmation instead of the form.
	 */
	public function test_sends_to_the_block_recipient(): void {
		$html = $this->submit(
			array(
				'recipient'      => 'owner@example.com',
				'subject'        => 'Fixed subject',
				'successMessage' => 'Thanks!',
			)
		);
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'owner@example.com', $this->mails[0]['to'] );
		$this->assertSame( 'Fixed subject', $this->mails[0]['subject'] );
		$this->assertContains( 'Reply-To: alex@example.com', $this->mails[0]['headers'] );
		$this->assertStringContainsString( 'Alex Example', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'Hello there', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'Thanks!', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * Without a valid recipient attribute the mail goes to the administration address, subject with the site title.
	 */
	public function test_empty_recipient_means_admin_email(): void {
		$this->submit( array( 'buttonText' => 'Empty recipient' ) );
		$this->submit(
			array(
				'buttonText' => 'Broken recipient',
				'recipient'  => 'not an address',
			)
		);
		$this->assertSame( array( get_option( 'admin_email' ), get_option( 'admin_email' ) ), array_column( $this->mails, 'to' ) );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $this->mails[0]['subject'] );
	}

	/**
	 * Any field a forged request adds changes neither recipient nor subject nor adds a header.
	 */
	public function test_forged_fields_cannot_name_a_recipient(): void {
		$this->submit(
			array( 'recipient' => 'owner@example.com' ),
			array(
				'recipient'     => 'evil@example.org',
				'to'            => 'evil@example.org',
				'subject'       => 'Forged',
				'raw:recipient' => 'evil@example.org',
				'raw:to'        => 'evil@example.org',
				'raw:subject'   => 'Forged',
				'raw:bcc'       => 'evil@example.org',
			)
		);
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'owner@example.com', $this->mails[0]['to'] );
		$this->assertStringNotContainsString( 'evil@example.org', (string) wp_json_encode( $this->mails[0] ) );
		$this->assertStringNotContainsString( 'Forged', (string) wp_json_encode( $this->mails[0] ) );
	}

	/**
	 * A form key of another block is not this block: nothing is sent.
	 */
	public function test_unknown_form_key_is_ignored(): void {
		$html = $this->submit( array( 'buttonText' => 'Other key' ), array( 'form' => 'abcdef123456' ) );
		$this->assertSame( array(), $this->mails );
		$this->assertStringContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'aria-invalid', $html );
	}

	/**
	 * Line breaks cannot add headers: an address with one is refused, a name with one reaches only the body, flattened.
	 */
	public function test_line_breaks_cannot_add_headers(): void {
		$bcc  = "\r\nBcc: evil@example.org";
		$html = $this->submit( array( 'buttonText' => 'Injection 1' ), array( 'email' => 'alex@example.com' . $bcc ) );
		$this->assertSame( array(), $this->mails );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );

		$this->submit( array( 'buttonText' => 'Injection 2' ), array( 'name' => 'Alex' . $bcc ) );
		$this->assertCount( 1, $this->mails );
		$this->assertCount( 2, $this->mails[0]['headers'] );
		$this->assertStringNotContainsString( 'evil@example.org', implode( '', $this->mails[0]['headers'] ) );
		$this->assertStringNotContainsString( "\nBcc:", $this->mails[0]['message'] );
	}

	/**
	 * A filled honeypot looks like a success and sends nothing.
	 */
	public function test_honeypot(): void {
		$html = $this->submit( array( 'buttonText' => 'Honeypot' ), array( 'homepage' => 'https://spam.example' ) );
		$this->assertSame( array(), $this->mails );
		$this->assertStringContainsString( 'role="status"', $html );
	}

	/**
	 * A fresh, a missing and a forged time field are refused with a message, and the input stays.
	 */
	public function test_time_field(): void {
		foreach ( array(
			'fresh'   => static fn( string $key ): string => Contact_Form::token( $key, time() ),
			'missing' => static fn(): string => '',
			'forged'  => static fn(): string => ( time() - 60 ) . '.forged',
			'shifted' => static fn( string $key ): string => ( time() - 60 ) . substr( Contact_Form::token( $key, time() ), 10 ),
		) as $name => $token ) {
			$attributes = array( 'buttonText' => $name );
			$html       = $this->submit( $attributes, array( 'token' => $token( $this->key( $attributes ) ) ) );
			$this->assertSame( array(), $this->mails, $name );
			$this->assertStringContainsString( 'role="alert"', $html, $name );
			$this->assertStringContainsString( 'autofocus', $html, $name );
			$this->assertStringContainsString( 'value="Alex Example"', $html, $name );
			$this->assertStringContainsString( 'Hello there</textarea>', $html, $name );
		}
	}

	/**
	 * Empty fields come back with the summary, which takes the focus, and messages tied to the fields.
	 */
	public function test_server_errors_fill_the_summary(): void {
		$html = $this->submit(
			array( 'buttonText' => 'Empty' ),
			array(
				'name'    => '',
				'email'   => 'no address',
				'message' => "  \n ",
			)
		);
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 3, substr_count( $html, 'aria-invalid="true"' ) );
		$this->assertSame( 3, substr_count( $html, 'class="wp-block-leitvia-form-errors__message"' ) );
		$this->assertStringContainsString( 'value="no address"', $html );
		$this->assertStringNotContainsString( 'data-error', $html );

		$tags = new WP_HTML_Tag_Processor( $html );
		$tags->next_tag( array( 'class_name' => 'wp-block-leitvia-form-errors__summary' ) );
		$this->assertNull( $tags->get_attribute( 'hidden' ) );
		$this->assertTrue( $tags->get_attribute( 'autofocus' ) );
		$this->assertStringContainsString( '>There are 3 problems</h2>', $html );
		$tags->next_tag( 'a' );
		$first = (string) $tags->get_attribute( 'href' );
		$this->assertMatchesRegularExpression( '/^#leitvia-contact-\d+-name$/', $first );
		$this->assertStringContainsString( 'id="' . substr( $first, 1 ) . '-error"', $html );
		$this->assertStringContainsString( 'aria-describedby="' . substr( $first, 1 ) . '-error"', $html );
		foreach ( array( 'Name: Enter your name.', 'Email address: Enter a valid email address.', 'Message: Enter your message.' ) as $link ) {
			$this->assertStringContainsString( '>' . $link . '</a>', $html );
		}
	}

	/**
	 * Input shown again is escaped, and a shortcode typed into the form does not run when the_content renders the page.
	 */
	public function test_shown_again_input_is_inert(): void {
		add_shortcode( 'leitvia_contact_probe', static fn(): string => 'SHORTCODE RAN' );
		$html = apply_filters(
			'the_content',
			$this->submit(
				array( 'buttonText' => 'Inert' ),
				array(
					'name'    => '"><script>alert(1)</script>[leitvia_contact_probe]',
					'email'   => '[leitvia_contact_probe]',
					'message' => '</textarea><b>x</b>[leitvia_contact_probe]',
				)
			)
		);
		remove_shortcode( 'leitvia_contact_probe' );
		$this->assertStringNotContainsString( 'SHORTCODE RAN', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
	}

	/**
	 * The sixth message of an address within the hour is refused; the transient key holds no address.
	 */
	public function test_rate_limit(): void {
		$address                = $_SERVER['REMOTE_ADDR']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored below.
		$_SERVER['REMOTE_ADDR'] = '192.0.2.77';
		for ( $n = 1; $n <= 6; $n++ ) {
			$html = $this->submit( array( 'buttonText' => 'Limit ' . $n ) );
		}
		$this->assertCount( 5, $this->mails );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'Too many messages', $html );
		global $wpdb;
		$rows = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%leitvia_contact_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertStringNotContainsString( '192.0.2.77', implode( ' ', $rows ) );
		$_SERVER['REMOTE_ADDR'] = $address;
	}

	/**
	 * A failed delivery says so and keeps the message.
	 */
	public function test_failed_delivery_keeps_the_input(): void {
		$this->delivers = false;
		$html           = $this->submit( array( 'buttonText' => 'Failed' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'could not be sent', $html );
		$this->assertStringContainsString( 'Hello there</textarea>', $html );
	}

	/**
	 * The address the redirect leads to shows the confirmation of this form only.
	 */
	public function test_confirmation_after_the_redirect(): void {
		$attributes           = array( 'successMessage' => 'Arrived' );
		$_GET['leitvia-sent'] = $this->key( $attributes );
		$this->assertStringContainsString( 'Arrived', $this->html( $attributes ) );
		$_GET['leitvia-sent'] = 'other';
		$this->assertStringNotContainsString( 'Arrived', $this->html( $attributes ) );
	}
}
