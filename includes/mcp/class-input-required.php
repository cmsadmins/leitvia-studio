<?php
/**
 * Multi round-trip requests of revision 2026-07-28: questions to the person before a tool runs.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

use LeitviaStudio\Gateway\Rejection;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go out as JSON-RPC, never as HTML.

/**
 * Before tools/call runs an ability, the server may answer resultType input_required with elicitation forms: choose
 * when every missing required argument has a closed list of values (enum, or a source of Completion), confirm when
 * the ability is annotated destructive, removes or reverts (delete-, remove-, reject-, rollback) and the call would
 * change the site (no package key, no dry run); filter leitvia_studio_mcp_confirm. Only for
 * clients that declared form elicitation; every other client runs as before. Revision 2025-11-25 gets no questions:
 * there elicitation is a request of the server on the event stream, answered in a separate POST, and the first
 * request would have to wait for it.
 *
 * The retry carries inputResponses and the requestState of the server: HMAC with wp_salt( 'auth' ) over user,
 * method, a digest of tool and arguments, expiry after TTL, a nonce that works once, the values chosen so far and a
 * yes already given.
 * Answers without a valid state are not taken. Missing answers ask again; decline, cancel or a no end the call with
 * leitvia_cancelled.
 *
 * A yes is no permission: the pipeline decides on autonomy, checks and the Inbox as for any call, and what has to
 * wait for a person still waits in a package.
 */
final class Input_Required {

	/**
	 * Seconds a requestState is valid.
	 */
	public const TTL = 10 * MINUTE_IN_SECONDS;

	private const CONFIRM = 'confirm';

	private const CHOOSE = 'choose';

	/**
	 * Transient prefix of the nonces of open states.
	 */
	private const NONCE = 'leitvia_mcp_rs_';

	/**
	 * Most values a choice offers; longer lists stay with the ability, which names what is missing.
	 */
	private const MAX_CHOICES = 100;

	/**
	 * Required arguments of abilities whose values come from a source of Completion.
	 */
	private const SOURCES = array(
		'leitvia/apply-recipe' => array( 'recipe' => 'recipes' ),
	);

	/**
	 * The arguments to run with, the input_required result to answer with, or the rejection leitvia_cancelled.
	 *
	 * @param string               $tool      Tool name.
	 * @param \WP_Ability          $ability   Ability.
	 * @param mixed                $arguments Arguments as the client sent them.
	 * @param array<string, mixed> $params    Params of tools/call with inputResponses and requestState.
	 * @param Context              $context   Protocol state.
	 * @return array{arguments: mixed}|array{ask: array<string, mixed>}|\WP_Error
	 * @throws Protocol_Error When the requestState fails verification.
	 */
	public static function resolve( string $tool, \WP_Ability $ability, $arguments, array $params, Context $context ) {
		if ( ! self::offered( $context ) ) {
			return array( 'arguments' => $arguments );
		}
		$digest    = self::digest( $tool, $arguments );
		$chosen    = array();
		$confirmed = false;
		$answers   = array();
		if ( array_key_exists( 'requestState', $params ) ) {
			[ $chosen, $confirmed ] = self::redeem( $params['requestState'], $digest );
			$answers                = is_array( $params['inputResponses'] ?? null ) ? $params['inputResponses'] : array();
		}
		$open = array();
		foreach ( self::requests( $ability, self::merge( $arguments, $chosen ) ) as $key => $request ) {
			// A yes given in an earlier round stays, a choice still open does not ask it again.
			if ( self::CONFIRM === $key && $confirmed ) {
				continue;
			}
			$answer = is_array( $answers[ $key ] ?? null ) ? $answers[ $key ] : array();
			$action = $answer['action'] ?? null;
			if ( 'decline' === $action || 'cancel' === $action ) {
				return self::cancelled( $ability );
			}
			$content = 'accept' === $action && is_array( $answer['content'] ?? null ) ? $answer['content'] : null;
			if ( null !== $content && self::CONFIRM === $key ) {
				if ( true !== ( $content['confirm'] ?? null ) ) {
					return self::cancelled( $ability );
				}
				$confirmed = true;
				continue;
			}
			$picked = null !== $content ? self::picked( $request, $content ) : null;
			if ( null !== $picked ) {
				$chosen = $picked + $chosen;
				continue;
			}
			$open[ $key ] = $request;
		}
		if ( array() === $open ) {
			return array( 'arguments' => self::merge( $arguments, $chosen ) );
		}
		return array(
			'ask' => array(
				'resultType'    => 'input_required',
				'inputRequests' => $open,
				'requestState'  => self::issue( $digest, $chosen, $confirmed ),
			),
		);
	}

	/**
	 * A payload as requestState: base64url JSON, a dot, its HMAC.
	 *
	 * @param array<string, mixed> $payload Payload.
	 */
	public static function seal( array $payload ): string {
		$body = rtrim( strtr( base64_encode( (string) wp_json_encode( $payload ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque requestState of MCP 2026-07-28, signed below.
		return $body . '.' . self::mac( $body );
	}

	/**
	 * Payload of a requestState whose HMAC fits, else null. Checks nothing else.
	 *
	 * @param string $state requestState.
	 * @return array<string, mixed>|null
	 */
	public static function open( string $state ): ?array {
		$parts = explode( '.', $state );
		if ( 2 !== count( $parts ) || ! hash_equals( self::mac( $parts[0] ), $parts[1] ) ) {
			return null;
		}
		$payload = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Opaque requestState of MCP 2026-07-28, signed.
		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Checks the requestState of a call without using it up, for the event stream path that must answer before it
	 * runs the call.
	 *
	 * @param string               $tool      Tool name.
	 * @param mixed                $arguments Arguments as the client sent them.
	 * @param array<string, mixed> $params    Params of tools/call.
	 * @param Context              $context   Protocol state.
	 * @throws Protocol_Error When the requestState fails verification.
	 */
	public static function verify( string $tool, $arguments, array $params, Context $context ): void {
		if ( self::offered( $context ) && array_key_exists( 'requestState', $params ) ) {
			self::redeem( $params['requestState'], self::digest( $tool, $arguments ), false );
		}
	}

	/**
	 * HMAC of a requestState body, in a domain of its own: no other HMAC with the auth salt matches it.
	 *
	 * @param string $body base64url body.
	 */
	private static function mac( string $body ): string {
		return hash_hmac( 'sha256', 'leitvia-mcp-requeststate|' . $body, wp_salt( 'auth' ) );
	}

	/**
	 * Whether the client takes form elicitation: elicitation declared, with form or without url (an empty object
	 * means form). Only under 2026-07-28.
	 *
	 * @param Context $context Protocol state.
	 */
	private static function offered( Context $context ): bool {
		$elicitation = $context->capabilities['elicitation'] ?? null;
		return $context->modern && is_array( $elicitation ) && ( isset( $elicitation['form'] ) || ! isset( $elicitation['url'] ) );
	}

	/**
	 * Values chosen so far of a valid state and whether the person said yes; uses the state up.
	 *
	 * @param mixed  $state  requestState of the client.
	 * @param string $digest Digest of the call.
	 * @param bool   $consume Whether to use the state up; false only checks it.
	 * @return array{0: array<string, mixed>, 1: bool}
	 * @throws Protocol_Error When the state is not this user's, not this call's, expired, changed or used.
	 */
	private static function redeem( $state, string $digest, bool $consume = true ): array {
		$payload = is_string( $state ) ? self::open( $state ) : null;
		$nonce   = is_string( $payload['n'] ?? null ) ? $payload['n'] : '';
		$valid   = null !== $payload
			&& get_current_user_id() === (int) ( $payload['u'] ?? 0 )
			&& 'tools/call' === ( $payload['m'] ?? '' )
			&& hash_equals( $digest, (string) ( $payload['d'] ?? '' ) )
			&& time() <= (int) ( $payload['e'] ?? 0 )
			&& preg_match( '/^[0-9a-f]{32}$/D', $nonce )
			// delete_transient() deletes the row once (a DELETE without a cache, DEL with one): a second retry with
			// the same state finds nothing, also in parallel.
			&& ( $consume ? delete_transient( self::NONCE . $nonce ) : false !== get_transient( self::NONCE . $nonce ) );
		if ( ! $valid ) {
			throw new Protocol_Error( -32602, 'Invalid requestState: send the original request again without it.' );
		}
		return array( is_array( $payload['c'] ?? null ) ? $payload['c'] : array(), true === ( $payload['y'] ?? false ) );
	}

	/**
	 * A new state for the open questions.
	 *
	 * @param string               $digest    Digest of the call.
	 * @param array<string, mixed> $chosen    Values chosen so far.
	 * @param bool                 $confirmed Whether the person already said yes.
	 */
	private static function issue( string $digest, array $chosen, bool $confirmed ): string {
		$nonce = bin2hex( random_bytes( 16 ) );
		set_transient( self::NONCE . $nonce, 1, self::TTL );
		return self::seal(
			array(
				'u' => get_current_user_id(),
				'm' => 'tools/call',
				'd' => $digest,
				'e' => time() + self::TTL,
				'n' => $nonce,
				'c' => $chosen,
				'y' => $confirmed,
			)
		);
	}

	/**
	 * Questions for a call by key: choose and confirm.
	 *
	 * @param \WP_Ability $ability   Ability.
	 * @param mixed       $arguments Arguments with the values chosen so far.
	 * @return array<string, array<string, mixed>>
	 */
	private static function requests( \WP_Ability $ability, $arguments ): array {
		$requests = array();
		$choices  = self::choices( $ability, $arguments );
		if ( array() !== $choices ) {
			/* translators: %s: label of the ability */
			$requests[ self::CHOOSE ] = self::form( sprintf( __( '%s needs a choice before it runs.', 'leitvia-studio' ), $ability->get_label() ), $choices );
		}
		if ( self::confirms( $ability, $arguments ) ) {
			$requests[ self::CONFIRM ] = self::form(
				/* translators: 1: label of the ability, 2: its input as JSON */
				sprintf( __( '%1$s changes or removes content on this site. Run it with this input? %2$s', 'leitvia-studio' ), $ability->get_label(), self::summary( $arguments ) ),
				array(
					'confirm' => array(
						'type'    => 'boolean',
						'title'   => __( 'Run it', 'leitvia-studio' ),
						'default' => false,
					),
				)
			);
		}
		return $requests;
	}

	/**
	 * Form fields for the missing required arguments, or none when one of them has no closed list.
	 *
	 * @param \WP_Ability $ability   Ability.
	 * @param mixed       $arguments Arguments.
	 * @return array<string, array<string, mixed>>
	 */
	private static function choices( \WP_Ability $ability, $arguments ): array {
		$schema = $ability->get_input_schema();
		if ( 'object' !== ( $schema['type'] ?? '' ) ) {
			return array();
		}
		$given      = is_array( $arguments ) ? $arguments : array();
		$properties = array();
		foreach ( (array) ( $schema['required'] ?? array() ) as $name ) {
			$name = (string) $name;
			if ( array_key_exists( $name, $given ) ) {
				continue;
			}
			$property = (array) ( $schema['properties'][ $name ] ?? array() );
			$source   = self::SOURCES[ $ability->get_name() ][ $name ] ?? '';
			$values   = '' !== $source ? Completion::source( $source, '' ) : array_values( array_filter( (array) ( $property['enum'] ?? array() ), 'is_string' ) );
			if ( array() === $values || count( $values ) > self::MAX_CHOICES || 'string' !== ( $property['type'] ?? 'string' ) ) {
				return array();
			}
			$properties[ $name ] = array(
				'type'  => 'string',
				'title' => is_string( $property['title'] ?? null ) ? $property['title'] : $name,
				'enum'  => $values,
			) + ( is_string( $property['description'] ?? null ) ? array( 'description' => $property['description'] ) : array() );
		}
		return $properties;
	}

	/**
	 * Whether the call needs a yes: an ability annotated destructive that removes or reverts (its name without
	 * namespace starts with delete-, remove- or reject-, or is rollback), without a package key and without a dry
	 * run; filterable. Most destructive abilities only change content and ask nothing, a question on every write
	 * would teach the person to click yes.
	 *
	 * @param \WP_Ability $ability   Ability.
	 * @param mixed       $arguments Arguments.
	 */
	private static function confirms( \WP_Ability $ability, $arguments ): bool {
		$name        = $ability->get_name();
		$short       = str_contains( $name, '/' ) ? substr( $name, strpos( $name, '/' ) + 1 ) : $name;
		$annotations = (array) $ability->get_meta_item( 'annotations', array() );
		$input       = is_array( $arguments ) ? $arguments : array();
		$packaged    = is_string( $input['package'] ?? null ) && '' !== $input['package'];
		$removes     = 'rollback' === $short || 1 === preg_match( '/^(delete|remove|reject)-/', $short );
		$ask         = $removes && ! empty( $annotations['destructive'] ) && empty( $input['dryRun'] ) && ! $packaged;
		/**
		 * Whether a call of an MCP client that takes form elicitation asks the person first. By default for abilities
		 * annotated destructive whose name starts with delete-, remove- or reject- or is rollback, without a package
		 * key and without a dry run. A yes changes no permission: the pipeline decides as for any call.
		 *
		 * @param bool                 $ask     Whether to ask.
		 * @param string               $ability Ability name.
		 * @param array<string, mixed> $input   Arguments of the call, with the values chosen so far.
		 */
		return (bool) apply_filters( 'leitvia_studio_mcp_confirm', $ask, $name, $input );
	}

	/**
	 * An elicitation request with a form.
	 *
	 * @param string                              $message    Message for the person.
	 * @param array<string, array<string, mixed>> $properties Form fields.
	 * @return array<string, mixed>
	 */
	private static function form( string $message, array $properties ): array {
		return array(
			'method' => 'elicitation/create',
			'params' => array(
				'mode'            => 'form',
				'message'         => $message,
				'requestedSchema' => array(
					'type'       => 'object',
					'properties' => $properties,
					'required'   => array_keys( $properties ),
				),
			),
		);
	}

	/**
	 * Values of an accepted choice, null when one is missing or not offered.
	 *
	 * @param array<string, mixed> $request Elicitation request.
	 * @param array<string, mixed> $content Content of the answer.
	 * @return array<string, string>|null
	 */
	private static function picked( array $request, array $content ): ?array {
		$picked = array();
		foreach ( (array) $request['params']['requestedSchema']['properties'] as $name => $property ) {
			$value = $content[ $name ] ?? null;
			if ( ! is_string( $value ) || ! in_array( $value, (array) ( $property['enum'] ?? array() ), true ) ) {
				return null;
			}
			$picked[ (string) $name ] = $value;
		}
		return $picked;
	}

	/**
	 * Arguments with the values chosen so far.
	 *
	 * @param mixed                $arguments Arguments of the client.
	 * @param array<string, mixed> $chosen    Chosen values.
	 * @return mixed
	 */
	private static function merge( $arguments, array $chosen ) {
		return array() === $chosen ? $arguments : $chosen + ( is_array( $arguments ) ? $arguments : array() );
	}

	/**
	 * Input of a call for the question, without the hash, at most 300 characters.
	 *
	 * @param mixed $arguments Arguments.
	 */
	private static function summary( $arguments ): string {
		$input = is_array( $arguments ) ? $arguments : array();
		unset( $input['base_hash'] );
		$json = (string) wp_json_encode( (object) $input );
		return mb_strlen( $json ) > 300 ? mb_substr( $json, 0, 300 ) . ' …' : $json;
	}

	/**
	 * Digest of tool and arguments, independent of the order of object keys.
	 *
	 * @param string $tool      Tool name.
	 * @param mixed  $arguments Arguments.
	 */
	private static function digest( string $tool, $arguments ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $tool, self::canonical( $arguments ) ) ) );
	}

	/**
	 * A value with the keys of every object sorted.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return array_map( static fn( $item ) => self::canonical( $item ), $value );
	}

	/**
	 * The rejection of a call the person declined.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	private static function cancelled( \WP_Ability $ability ): \WP_Error {
		return Rejection::make( 'leitvia_cancelled', __( 'The person declined the call in the client. Nothing was changed; ask what they want before you try again.', 'leitvia-studio' ), 409, 'declined', array( 'ability' => $ability->get_name() ), '', 'never' );
	}
}
