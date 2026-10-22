<?php
/**
 * The one way Leitvia uses AI inside the site: the core AI client with the connector of the site owner.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a prompt through wp_ai_client_prompt() and returns the JSON answer checked against a schema. No provider keys,
 * no own HTTP requests: without core support, a connector or a model for text, the caller gets a rejection.
 *
 * The core AI client turns every failed HTTP request into prompt_network_error (503). A refused connector approval of
 * the AI plugin (wpai_connector_not_approved from pre_http_request) only survives in the message, so every network
 * error that is no timeout is explained as "not reachable or not approved".
 */
final class Ai {

	/**
	 * Seconds one request may take.
	 */
	public const TIMEOUT = 20;

	/**
	 * JSON answer of the model for a prompt, or a rejection.
	 *
	 * @param string               $system       System instruction.
	 * @param string               $prompt       Prompt.
	 * @param array<string, mixed> $schema       JSON schema of the answer.
	 * @param string               $next_ability Ability named in rejections.
	 * @param array<string, mixed> $extra        Further data of rejections.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function json( string $system, string $prompt, array $schema, string $next_ability, array $extra = array() ) {
		$extra += array( 'hint' => array( 'ai' => false ) );
		if ( ! function_exists( 'wp_supports_ai' ) || ! function_exists( 'wp_ai_client_prompt' ) || ! wp_supports_ai() ) {
			return self::unavailable( $next_ability, $extra );
		}
		$timeout = static fn(): int => self::TIMEOUT;
		add_filter( 'wp_ai_client_default_request_timeout', $timeout );
		$builder = wp_ai_client_prompt( $prompt );
		remove_filter( 'wp_ai_client_default_request_timeout', $timeout );
		$builder = $builder->using_system_instruction( $system )->as_json_response( $schema );
		if ( ! $builder->is_supported_for_text_generation() ) {
			return self::unavailable( $next_ability, $extra );
		}
		$text = $builder->generate_text();
		if ( is_wp_error( $text ) ) {
			return self::rejection( $text, $next_ability, $extra );
		}
		$data = json_decode( (string) $text, true );
		if ( ! is_array( $data ) || true !== rest_validate_value_from_schema( $data, $schema, 'answer' ) ) {
			return Rejection::make( 'leitvia_ai_invalid_response', __( 'The AI model answered with data that does not match the expected format. Try once more, or call again with ai set to false.', 'leitvia-studio' ), 502, 'ai', array(), $next_ability, 'once', $extra );
		}
		return $data;
	}

	/**
	 * Rejection for a site without AI support, connector or model for text.
	 *
	 * @param string               $next_ability Ability named in the rejection.
	 * @param array<string, mixed> $extra        Further data.
	 */
	private static function unavailable( string $next_ability, array $extra ): \WP_Error {
		return Rejection::make( 'leitvia_ai_unavailable', __( 'AI is not available on this site: it needs WordPress with the AI client, AI switched on and a connector with a text model set up by an administrator under Settings, Connectors. Call again with ai set to false for a plan without AI.', 'leitvia-studio' ), 424, 'ai', array(), $next_ability, 'never', $extra );
	}

	/**
	 * Rejection for an error of the AI client.
	 *
	 * @param \WP_Error            $error        Error of the AI client.
	 * @param string               $next_ability Ability named in the rejection.
	 * @param array<string, mixed> $extra        Further data.
	 */
	private static function rejection( \WP_Error $error, string $next_ability, array $extra ): \WP_Error {
		$code  = $error->get_error_code();
		$extra = array( 'ai_error' => $code ) + $extra;
		if ( in_array( $code, array( 'prompt_prevented', 'prompt_invalid_argument' ), true ) ) {
			return self::unavailable( $next_ability, $extra );
		}
		if ( 'prompt_network_error' === $code && preg_match( '/timed? ?out|cURL error 28/i', $error->get_error_message() ) ) {
			/* translators: %d: seconds. */
			return Rejection::make( 'leitvia_ai_timeout', sprintf( __( 'The AI provider did not answer within %d seconds. Try once more, or call again with ai set to false.', 'leitvia-studio' ), self::TIMEOUT ), 504, 'ai', array(), $next_ability, 'once', $extra );
		}
		if ( 'prompt_network_error' === $code ) {
			return Rejection::make( 'leitvia_ai_not_approved', __( 'The AI provider could not be reached, or an administrator has not approved this request for the connector yet. When the AI plugin is active, an administrator approves pending connector requests in its settings; until then, call again with ai set to false.', 'leitvia-studio' ), 503, 'ai', array(), $next_ability, 'never', $extra );
		}
		return Rejection::make( 'leitvia_ai_failed', __( 'The AI provider refused or failed the request. An administrator checks the connector under Settings, Connectors; until then, call again with ai set to false.', 'leitvia-studio' ), 502, 'ai', array(), $next_ability, 'never', $extra );
	}
}
