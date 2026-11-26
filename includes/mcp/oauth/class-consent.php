<?php
/**
 * Page "Connect agent": the authorization endpoint of OAuth.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp\OAuth;

use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Rest\Gateway_Access_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * The authorization endpoint is a hidden admin page, so WordPress signs the person in first and agents never get there
 * (Gateway::login_guard(), admin_guard()). The page checks client and redirect URI before anything else and shows their
 * errors, never redirects with them (OAuth 2.1, section 4.1.2.1); every later error goes back to the client with state
 * and iss (RFC 9207), at once only for a client with a document and an open connection on this site; for any other
 * the page shows it with a link back, so the authorization endpoint is no open redirect (RFC 9700, section 4.11.2).
 * A registered client named itself freely: the page says so and names it by the host it returns to. A person with manage_options who is no agent picks the agent user the client works as, or
 * themself for leitvia:approve, and the rights. The form posts with a nonce to admin-post.php; nothing is sent by
 * script, nothing is chosen in advance, no page may frame it (HEADERS).
 */
final class Consent {

	public const SLUG = 'leitvia-studio-connect';

	public const ACTION = 'leitvia_studio_oauth_consent';

	/**
	 * Parameters of the authorization request, carried through the form.
	 */
	public const FIELDS = array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' );

	/**
	 * Headers of the page and the form handler: no frame of any origin (clickjacking), stricter than the SAMEORIGIN of
	 * the admin.
	 */
	public const HEADERS = array(
		'X-Frame-Options'         => 'DENY',
		'Content-Security-Policy' => "frame-ancestors 'none'",
	);

	/**
	 * Request checked on load(), shown by render().
	 *
	 * @var array<string, mixed>|\WP_Error|null
	 */
	private static $request = null;

	/**
	 * Adds the hidden page. Runs on admin_menu.
	 */
	public static function register_page(): void {
		$hook = add_submenu_page( '', __( 'Connect agent', 'leitvia-studio' ), '', 'manage_options', self::SLUG, array( self::class, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'load' ) );
		}
	}

	/**
	 * Checks the request before the page prints anything; an error the client may see goes back to it at once.
	 */
	public static function load(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The authorization request of the client; request() compares every field, the form of this page carries the nonce.
		self::$request = self::request( wp_unslash( $_GET ) );
		$redirect      = is_wp_error( self::$request ) ? self::follow_of( self::$request ) : '';
		if ( '' !== $redirect ) {
			wp_redirect( $redirect ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- A redirect URI of the client document, checked in request().
			exit;
		}
	}

	/**
	 * The checked authorization request, or the error to show (redirect_of() empty) or to send back.
	 *
	 * @param array<string, mixed> $params Query of the request, or the fields of the form.
	 * @return array<string, mixed>|\WP_Error client, redirect_uri, state, challenge, resource, scopes, params.
	 */
	public static function request( array $params ) {
		$fields = array();
		foreach ( self::FIELDS as $field ) {
			$fields[ $field ] = is_string( $params[ $field ] ?? null ) ? $params[ $field ] : '';
		}
		if ( ! OAuth::enabled() ) {
			return self::fail( 'access_denied', 'OAuth is switched off on this site.' );
		}
		$client = Clients::find( $fields['client_id'] );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( ! Clients::redirect_matches( $fields['redirect_uri'], $client ) ) {
			return self::fail( 'invalid_request', 'redirect_uri is none of the redirect URIs of the client.' );
		}
		$follow = 'cimd' === $client['kind'] && in_array( (string) $client['client_id'], Tokens::clients(), true );
		$back   = static fn( string $code, string $text ): \WP_Error => self::fail(
			$code,
			$text,
			self::response_url(
				$fields['redirect_uri'],
				array(
					'error'             => $code,
					'error_description' => $text,
				),
				$fields['state']
			),
			$follow
		);
		if ( 'code' !== $fields['response_type'] ) {
			return $back( 'unsupported_response_type', 'response_type must be code.' );
		}
		if ( 'S256' !== $fields['code_challenge_method'] || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $fields['code_challenge'] ) ) {
			return $back( 'invalid_request', 'PKCE is required: code_challenge with code_challenge_method S256.' );
		}
		if ( '' !== $fields['resource'] && ! OAuth::same_resource( $fields['resource'] ) ) {
			return $back( 'invalid_target', 'resource is not the MCP server of this site.' );
		}
		// Unknown scopes such as offline_access drop out; none asked means read and write.
		$asked = array_values( array_intersect( OAuth::SCOPES, (array) preg_split( '/\s+/', $fields['scope'], -1, PREG_SPLIT_NO_EMPTY ) ) );
		return array(
			'client'       => $client,
			'redirect_uri' => $fields['redirect_uri'],
			'state'        => $fields['state'],
			'challenge'    => $fields['code_challenge'],
			'resource'     => OAuth::resource(),
			'scopes'       => $asked ? $asked : OAuth::BASIC_SCOPES,
			'params'       => $fields,
		);
	}

	/**
	 * What the form decided: the redirect to the client with a code, or with access_denied; WP_Error when the request
	 * or the choice is not valid.
	 *
	 * @param array<string, mixed> $form Fields of the form.
	 * @return string|\WP_Error
	 */
	public static function decide( array $form ) {
		$request = self::request( $form );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		if ( 'allow' !== ( $form['decision'] ?? '' ) ) {
			return self::response_url(
				$request['redirect_uri'],
				array(
					'error'             => 'access_denied',
					'error_description' => 'The person declined the connection.',
				),
				$request['state']
			);
		}
		$picked  = array_values( array_intersect( OAuth::SCOPES, array_filter( (array) ( $form['scopes'] ?? array() ), 'is_string' ) ) );
		$subject = is_string( $form['subject'] ?? null ) ? $form['subject'] : '';
		if ( 'self' === $subject ) {
			$user   = get_current_user_id();
			$scopes = array( OAuth::READ, OAuth::APPROVE );
		} else {
			if ( in_array( OAuth::APPROVE, $picked, true ) ) {
				return self::fail( 'invalid_scope', __( 'Only you can approve changes, as yourself; an agent cannot.', 'leitvia-studio' ) );
			}
			$agent = Gateway_Access_Controller::find( (int) $subject );
			if ( is_wp_error( $agent ) ) {
				return $agent;
			}
			$user   = $agent->ID;
			$scopes = in_array( OAuth::WRITE, $picked, true ) ? array( OAuth::READ, OAuth::WRITE ) : array( OAuth::READ );
		}
		$grant = array(
			'user_id'      => $user,
			'client_id'    => (string) $request['client']['client_id'],
			'client_name'  => (string) $request['client']['client_name'],
			'scope'        => implode( ' ', $scopes ),
			'resource'     => $request['resource'],
			'redirect_uri' => $request['redirect_uri'],
			'challenge'    => $request['challenge'],
		);
		$code  = Tokens::issue_code( $grant );
		Tokens::log( 'oauth/grant', $grant );
		return self::response_url( $request['redirect_uri'], array( 'code' => $code ), $request['state'] );
	}

	/**
	 * Handles the form. Runs on admin_post_leitvia_studio_oauth_consent.
	 */
	public static function handle(): void {
		check_admin_referer( self::ACTION );
		self::guard();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- request() compares every field with the client document, the metadata or a pattern; nothing is stored or printed as sent.
		$result = self::decide( wp_unslash( $_POST ) );
		$target = is_wp_error( $result ) ? self::redirect_of( $result ) : $result;
		if ( '' === $target ) {
			$detail = is_wp_error( $result ) ? $result->get_error_message() : '';
			wp_die(
				esc_html__( 'The client cannot connect:', 'leitvia-studio' ) . ' ' . esc_html( $detail ),
				esc_html__( 'Connect agent', 'leitvia-studio' ),
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}
		wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- A redirect URI of the client document, checked in request().
		exit;
	}

	/**
	 * Prints the page.
	 */
	public static function render(): void {
		$request = self::$request;
		echo '<div class="wrap leitvia-studio-connect"><h1>' . esc_html__( 'Connect agent', 'leitvia-studio' ) . '</h1>';
		if ( ! is_array( $request ) ) {
			$detail = is_wp_error( $request ) ? $request->get_error_message() : 'The request of the client is incomplete.';
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The client cannot connect:', 'leitvia-studio' ) . ' <code>' . esc_html( $detail ) . '</code></p></div>';
			$redirect = is_wp_error( $request ) ? self::redirect_of( $request ) : '';
			if ( '' !== $redirect ) {
				printf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( $redirect ),
					/* translators: %s: host the client returns to, for example claude.ai. */
					esc_html( sprintf( __( 'Back to %s', 'leitvia-studio' ), (string) wp_parse_url( $redirect, PHP_URL_HOST ) ) )
				);
			}
			echo '</div>';
			return;
		}
		$client = $request['client'];
		$back   = (string) wp_parse_url( $request['redirect_uri'], PHP_URL_HOST );
		$dcr    = 'cimd' !== $client['kind'];
		$origin = $dcr ? __( 'registered on this site, without a client document', 'leitvia-studio' ) : (string) wp_parse_url( (string) $client['client_id'], PHP_URL_HOST );
		$agents = array_filter( get_users( array( 'role' => Gateway::ROLE ) ), static fn( \WP_User $user ): bool => ! is_wp_error( Gateway_Access_Controller::find( $user->ID ) ) );
		$asks   = sprintf(
			/* translators: %s: name of the client, for example Claude, or for a self-registered client the host it returns to. */
			__( '%s asks to work on this site through its MCP server.', 'leitvia-studio' ),
			$dcr ? $back : (string) $client['client_name']
		);
		$myself = sprintf(
			Bearer::approve_tools()
				/* translators: %s: display name of the signed-in person. */
				? __( 'Myself, %s: reads, and approves change packages where the client shows them for approval; never writes', 'leitvia-studio' )
				/* translators: %s: display name of the signed-in person. */
				: __( 'Myself, %s: reads only, never writes', 'leitvia-studio' ),
			wp_get_current_user()->display_name
		);
		$write = __( 'Write: change content through the pipeline, with autonomy levels, checks and rollback. For agents only.', 'leitvia-studio' );
		if ( in_array( OAuth::WRITE, $request['scopes'], true ) ) {
			$write .= ' ' . __( 'The client asks for it.', 'leitvia-studio' );
		}
		echo '<p>' . esc_html( $asks ) . '</p>';
		if ( $dcr ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Not verified: this client registered itself on this site; its name can be chosen freely.', 'leitvia-studio' ) . '</p></div>';
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Client', 'leitvia-studio' ) . '</th><td>' . esc_html( (string) $client['client_name'] ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Comes from', 'leitvia-studio' ) . '</th><td><code>' . esc_html( $origin ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Returns to', 'leitvia-studio' ) . '</th><td><code>' . esc_html( $request['redirect_uri'] ) . '</code></td></tr>';
		echo '</tbody></table>';
		if ( Clients::loopback( $back ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The answer goes to a program on the computer you are signed in on. Connect only if you started this connection yourself just now.', 'leitvia-studio' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		foreach ( self::FIELDS as $field ) {
			echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $request['params'][ $field ] ) . '">';
		}
		echo '<fieldset><legend><h2>' . esc_html__( 'Work as', 'leitvia-studio' ) . '</h2></legend>';
		foreach ( $agents as $agent ) {
			printf( '<p><label><input type="radio" name="subject" value="%1$d" required> %2$s (%3$s)</label></p>', (int) $agent->ID, esc_html( $agent->display_name ), esc_html( $agent->user_login ) );
		}
		if ( ! $agents ) {
			printf( '<p>%1$s <a href="%2$s">%3$s</a></p>', esc_html__( 'There is no agent you may connect yet.', 'leitvia-studio' ), esc_url( admin_url( 'admin.php?page=leitvia-studio-agents' ) ), esc_html__( 'Create an agent', 'leitvia-studio' ) );
		}
		printf( '<p><label><input type="radio" name="subject" value="self" required> %s</label></p>', esc_html( $myself ) );
		echo '</fieldset>';
		echo '<fieldset><legend><h2>' . esc_html__( 'Rights', 'leitvia-studio' ) . '</h2></legend>';
		echo '<p>' . esc_html__( 'Reading is always included.', 'leitvia-studio' ) . '</p>';
		// Never ticked in advance: the person grants write, the request of the client only shows in the label.
		printf( '<p><label><input type="checkbox" name="scopes[]" value="%1$s"> %2$s</label></p>', esc_attr( OAuth::WRITE ), esc_html( $write ) );
		echo '</fieldset>';
		echo '<p class="submit"><button type="submit" name="decision" value="allow" class="button button-primary">' . esc_html__( 'Connect', 'leitvia-studio' ) . '</button> ';
		echo '<button type="submit" name="decision" value="deny" class="button" formnovalidate>' . esc_html__( 'Cancel', 'leitvia-studio' ) . '</button></p>';
		echo '</form></div>';
	}

	/**
	 * The redirect an error carries, empty when it is shown on the page.
	 *
	 * @param \WP_Error $error Error of request() or decide().
	 */
	public static function redirect_of( \WP_Error $error ): string {
		$data = $error->get_error_data();
		return is_array( $data ) ? (string) ( $data['redirect'] ?? '' ) : '';
	}

	/**
	 * The redirect of an error to follow at once, empty when the page shows it.
	 *
	 * @param \WP_Error $error Error of request().
	 */
	public static function follow_of( \WP_Error $error ): string {
		$data = $error->get_error_data();
		return is_array( $data ) && ! empty( $data['follow'] ) ? self::redirect_of( $error ) : '';
	}

	/**
	 * Sends HEADERS and stops anyone but a person with manage_options who is no agent.
	 */
	private static function guard(): void {
		if ( ! headers_sent() ) {
			foreach ( self::HEADERS as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}
		if ( ! Gateway::is_person() ) {
			wp_die( esc_html__( 'Only an administrator who is no agent may connect a client.', 'leitvia-studio' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Address back to the client with iss and, when the client sent one, state.
	 *
	 * @param string                $redirect Checked redirect URI.
	 * @param array<string, string> $args     code, or error and error_description.
	 * @param string                $state    state of the request.
	 */
	private static function response_url( string $redirect, array $args, string $state ): string {
		$args['iss'] = OAuth::issuer();
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		return add_query_arg( array_map( 'rawurlencode', $args ), $redirect );
	}

	/**
	 * Error of the authorization request.
	 *
	 * @param string $code     OAuth error code.
	 * @param string $message  Description.
	 * @param string $redirect Address back to the client, empty to show the error.
	 * @param bool   $follow   Whether the page follows the redirect at once.
	 */
	private static function fail( string $code, string $message, string $redirect = '', bool $follow = false ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status'   => 400,
				'redirect' => $redirect,
				'follow'   => $follow,
			)
		);
	}
}
