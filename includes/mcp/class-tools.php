<?php
/**
 * Abilities of the Studio registry as MCP tools.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

use LeitviaStudio\Gateway\Mcp;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go out as JSON-RPC, never as HTML.

/**
 * One tool per ability of Studio and its add-ons that is shown in REST, named with a hyphen for the slash
 * (leitvia/get-contract becomes leitvia-get-contract), in a fixed order. A call runs WP_Ability::execute(), so input
 * schema, permission and the pipeline apply exactly as over REST. Tools for views (meta mcp.visibility without model)
 * exist only for a client that shows views; tools/list carries _meta.ui from Apps::meta().
 */
final class Tools {

	/**
	 * Abilities by tool name, sorted by name.
	 *
	 * @return array<string, \WP_Ability>
	 */
	public static function abilities(): array {
		$tools = array();
		foreach ( Mcp::abilities() as $name ) {
			$ability = wp_get_ability( $name );
			if ( null !== $ability ) {
				$tools[ str_replace( '/', '-', $name ) ] = $ability;
			}
		}
		ksort( $tools );
		return $tools;
	}

	/**
	 * Result of tools/list. A tool for views (Apps) only for a client that shows them.
	 *
	 * @param Context|null $context Protocol state, null outside a request.
	 * @return array{tools: list<array<string, mixed>>}
	 */
	public static function list( ?Context $context = null ): array {
		$tools = array();
		foreach ( self::abilities() as $name => $ability ) {
			if ( Apps::listed( $ability, $context ) ) {
				$tools[] = self::definition( $name, $ability, $context );
			}
		}
		return array( 'tools' => $tools );
	}

	/**
	 * Tool definition of an ability.
	 *
	 * @param string       $name    Tool name.
	 * @param \WP_Ability  $ability Ability.
	 * @param Context|null $context Protocol state; decides the _meta of views.
	 * @return array<string, mixed>
	 */
	public static function definition( string $name, \WP_Ability $ability, ?Context $context = null ): array {
		$input       = $ability->get_input_schema();
		$output      = $ability->get_output_schema();
		$annotations = (array) $ability->get_meta_item( 'annotations', array() );
		$tool        = array(
			'name'        => $name,
			'title'       => $ability->get_label(),
			'description' => $ability->get_description(),
			'inputSchema' => self::objects( self::input_schema( $ability ) ),
			'annotations' => array(
				'readOnlyHint'    => (bool) ( $annotations['readonly'] ?? false ),
				'destructiveHint' => (bool) ( $annotations['destructive'] ?? true ),
				'idempotentHint'  => (bool) ( $annotations['idempotent'] ?? false ),
				'openWorldHint'   => (bool) ( $annotations['openWorldHint'] ?? false ),
			),
		);
		// MCP allows only an object as outputSchema root.
		if ( 'object' === ( $output['type'] ?? '' ) ) {
			$tool['outputSchema'] = self::objects( $output );
		}
		$meta = Apps::meta( $name, $ability, $context );
		if ( $meta ) {
			$tool['_meta'] = $meta;
		}
		return $tool;
	}

	/**
	 * Result of tools/call: first the questions of Input_Required, then a task (Tasks) or the run. A rejection of the ability is a tool
	 * result with isError, its text JSON with code, message and data, so the agent gets hash, next ability and retry
	 * rule.
	 *
	 * @param array<string, mixed> $params  Params of tools/call.
	 * @param Context              $context Protocol state.
	 * @return array<string, mixed>
	 * @throws Protocol_Error For an unknown tool or an invalid requestState.
	 */
	public static function call( array $params, Context $context ): array {
		$ability = self::find( $params, $context );
		$name    = (string) $params['name'];
		// No question and no task for a user who may not call the ability at all.
		$input = $ability->normalize_input( self::input( $ability, $params['arguments'] ?? null ) );
		if ( is_wp_error( $input ) ) {
			return self::failure( $input );
		}
		$permitted = $ability->check_permissions( $input );
		if ( is_wp_error( $permitted ) ) {
			// The rejection of the permission callback keeps its code, so the agent learns why and what to do.
			return self::failure( $permitted );
		}
		if ( true !== $permitted ) {
			/* translators: %s: ability name */
			return self::failure( new \WP_Error( 'ability_invalid_permissions', sprintf( __( 'Ability "%s" does not have necessary permission.', 'leitvia-studio' ), $ability->get_name() ) ) );
		}
		$asked = Input_Required::resolve( $name, $ability, $params['arguments'] ?? null, $params, $context );
		if ( is_wp_error( $asked ) ) {
			return self::failure( $asked );
		}
		if ( isset( $asked['ask'] ) ) {
			return $asked['ask'];
		}
		// Questions come before the task, as the tasks extension asks.
		if ( Tasks::wanted( $ability, $context ) ) {
			return Tasks::start( $name, $asked['arguments'] );
		}
		return self::execute( $ability, $asked['arguments'], $context );
	}

	/**
	 * Runs an ability with the arguments of a tool call and returns the tool result. A tool only views call (meta
	 * mcp.visibility without model) carries a short note as text instead of the JSON, which can be large (images as
	 * data: URIs); the view reads structuredContent.
	 *
	 * @param \WP_Ability $ability   Ability.
	 * @param mixed       $arguments Arguments of the call.
	 * @param Context     $context   Protocol state.
	 * @return array<string, mixed>
	 */
	public static function execute( \WP_Ability $ability, $arguments, Context $context ): array {
		$result = $ability->execute( self::input( $ability, $arguments ) );
		if ( is_wp_error( $result ) ) {
			return self::failure( $result );
		}
		// An empty result of an object schema is {} in JSON, not [].
		if ( array() === $result && 'object' === ( $ability->get_output_schema()['type'] ?? '' ) ) {
			$result = new \stdClass();
		}
		$name = str_replace( '/', '-', $ability->get_name() );
		$tool = array( 'content' => array( self::text( $result ) ) );
		// 2025-11-25 allows only an object as structuredContent, 2026-07-28 any JSON value.
		if ( $context->modern || $result instanceof \stdClass || ( is_array( $result ) && ! array_is_list( $result ) ) ) {
			$tool['structuredContent'] = $result;
			if ( ! in_array( 'model', Apps::visibility( $ability ), true ) ) {
				$tool['content'] = array(
					array(
						'type' => 'text',
						'text' => sprintf( 'Result of %s for a view, in structuredContent.', $name ),
					),
				);
			}
		}
		/**
		 * Filters the result of one tools/call of the MCP server leitvia after the ability ran, for example to add
		 * _meta a view reads and the model does not.
		 *
		 * @param array<string, mixed> $tool    CallToolResult with content and, where it applies, structuredContent.
		 * @param string               $name    Tool name, such as leitvia-get-package.
		 * @param \WP_Ability          $ability The ability behind the tool.
		 * @param mixed                $result  Output of the ability.
		 * @param Context              $context Protocol state; Apps::offered( $context ) tells whether the client shows views.
		 */
		return (array) apply_filters( 'leitvia_studio_mcp_tool_result', $tool, $name, $ability, $result, $context );
	}

	/**
	 * The checks of tools/call that need no work, for the event stream path, which must answer them before its
	 * status goes out: the tool and the requestState.
	 *
	 * @param array<string, mixed> $params  Params of tools/call.
	 * @param Context              $context Protocol state.
	 * @throws Protocol_Error For an unknown tool or an invalid requestState.
	 */
	public static function precheck( array $params, Context $context ): void {
		self::find( $params, $context );
		Input_Required::verify( (string) $params['name'], $params['arguments'] ?? null, $params, $context );
	}

	/**
	 * The ability of a tools/call.
	 *
	 * @param array<string, mixed> $params  Params of tools/call.
	 * @param Context              $context Protocol state.
	 * @throws Protocol_Error For a missing name or an unknown tool.
	 */
	private static function find( array $params, Context $context ): \WP_Ability {
		if ( ! is_string( $params['name'] ?? null ) ) {
			throw new Protocol_Error( -32602, 'name must be a string.' );
		}
		$ability = self::abilities()[ $params['name'] ] ?? null;
		if ( null === $ability || ! Apps::listed( $ability, $context ) ) {
			throw new Protocol_Error( -32602, sprintf( 'Unknown tool: %s', $params['name'] ) );
		}
		return $ability;
	}

	/**
	 * Input of the ability from the arguments of a tool call.
	 *
	 * @param \WP_Ability $ability   Ability.
	 * @param mixed       $arguments Arguments of the call.
	 * @return mixed
	 */
	private static function input( \WP_Ability $ability, $arguments ) {
		if ( empty( $ability->get_input_schema() ) ) {
			return null;
		}
		if ( self::wraps( $ability ) ) {
			return is_array( $arguments ) ? ( $arguments['input'] ?? null ) : null;
		}
		// Missing arguments reach the ability as null, so the default of its schema applies as over REST.
		return is_array( $arguments ) ? $arguments : null;
	}

	/**
	 * Tool result of a rejection.
	 *
	 * @param \WP_Error $error Rejection.
	 * @return array{content: list<array{type: string, text: string}>, isError: true}
	 */
	private static function failure( \WP_Error $error ): array {
		return array(
			'content' => array( self::text( Mcp::details( $error ) ) ),
			'isError' => true,
		);
	}

	/**
	 * Runs an ability as resources/read does: an ability without input schema takes no input. tools/call shapes its
	 * input in execute() (wrapped schemas, missing arguments) and does not use this.
	 *
	 * @param \WP_Ability          $ability   Ability.
	 * @param array<string, mixed> $arguments Arguments.
	 * @return mixed Result of the ability or its WP_Error.
	 */
	public static function run( \WP_Ability $ability, array $arguments ) {
		return $ability->execute( empty( $ability->get_input_schema() ) ? null : $arguments );
	}

	/**
	 * Arguments a client repeats as header Mcp-Param-<name>: properties of the input schema with x-mcp-header.
	 *
	 * @param array<string, mixed> $schema Input schema of a tool.
	 * @return array<string, string> Header suffix => argument name.
	 */
	public static function param_headers( array $schema ): array {
		$map = array();
		foreach ( (array) ( $schema['properties'] ?? array() ) as $property => $definition ) {
			$header = is_array( $definition ) ? ( $definition['x-mcp-header'] ?? null ) : null;
			if ( is_string( $header ) && '' !== $header ) {
				$map[ $header ] = (string) $property;
			}
		}
		return $map;
	}

	/**
	 * Whether the input schema of an ability has no object root, so the tool takes the value as property input.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	private static function wraps( \WP_Ability $ability ): bool {
		$input = $ability->get_input_schema();
		return ! empty( $input ) && 'object' !== ( $input['type'] ?? '' );
	}

	/**
	 * Input schema of the tool: an object, the schema of the ability itself or wrapped as property input.
	 *
	 * @param \WP_Ability $ability Ability.
	 * @return array<string, mixed>
	 */
	private static function input_schema( \WP_Ability $ability ): array {
		$input = $ability->get_input_schema();
		if ( empty( $input ) ) {
			return array( 'type' => 'object' );
		}
		if ( self::wraps( $ability ) ) {
			return array(
				'type'       => 'object',
				'properties' => array( 'input' => $input ),
				'required'   => array( 'input' ),
			);
		}
		return $input;
	}

	/**
	 * Text content with a value as JSON.
	 *
	 * @param mixed $value Value.
	 * @return array{type: string, text: string}
	 */
	private static function text( $value ): array {
		return array(
			'type' => 'text',
			'text' => (string) wp_json_encode( $value ),
		);
	}

	/**
	 * A schema whose empty properties encode as {} instead of [], at every depth.
	 *
	 * @param array<string, mixed> $schema JSON schema.
	 * @return array<string, mixed>
	 */
	private static function objects( array $schema ): array {
		foreach ( $schema as $key => $value ) {
			if ( 'properties' === $key && array() === $value ) {
				$schema[ $key ] = new \stdClass();
			} elseif ( is_array( $value ) ) {
				$schema[ $key ] = self::objects( $value );
			}
		}
		return $schema;
	}
}
