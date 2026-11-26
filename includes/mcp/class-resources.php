<?php
/**
 * MCP resources of the server leitvia.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

use LeitviaStudio\Gateway\Abilities\Read_Design;
use LeitviaStudio\Gateway\Mcp;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go out as JSON-RPC, never as HTML.

/**
 * Contract, tokens, patterns and Inbox as resources, a page outline and one pattern as templates. Every read runs the
 * ability that the tool of the same content runs, so permission, scope, rate limit and audit are the same as for
 * tools/call. Contents are JSON, the result of the ability. The Markdown files the prompts link to read as
 * leitvia://skills/<owner>/<path> (Prompts::file()), without an ability: they ship with the plugin. Views of add-ons are
 * ui:// resources (Apps), read from their file, not through an ability.
 */
final class Resources {

	public const OUTLINE = 'leitvia://page/{id}/outline';

	public const PATTERN = 'leitvia://pattern/{slug}';

	/**
	 * Fixed resources: ability and input.
	 */
	private const FIXED = array(
		'leitvia://contract' => array( 'leitvia/get-contract', array() ),
		'leitvia://tokens'   => array( 'leitvia/get-tokens', array() ),
		'leitvia://patterns' => array( 'leitvia/list-patterns', array() ),
		'leitvia://inbox'    => array( 'leitvia/list-packages', array( 'state' => 'open' ) ),
	);

	/**
	 * Result of resources/list: the fixed resources, for a client that shows views also the ui:// resources of
	 * mcp_apps (Apps).
	 *
	 * @param Context|null $context Protocol state, null outside a request.
	 * @return array{resources: list<array<string, mixed>>}
	 */
	public static function list( ?Context $context = null ): array {
		$texts     = self::texts();
		$resources = array();
		foreach ( array_keys( self::FIXED ) as $uri ) {
			$resources[] = array(
				'uri'      => $uri,
				'name'     => substr( $uri, strlen( 'leitvia://' ) ),
				'mimeType' => 'application/json',
			) + $texts[ $uri ];
		}
		return array( 'resources' => array_merge( $resources, Apps::offered( $context ) ? Apps::resources() : array() ) );
	}

	/**
	 * Result of resources/templates/list.
	 *
	 * @return array{resourceTemplates: list<array<string, string>>}
	 */
	public static function templates(): array {
		$texts = self::texts();
		return array(
			'resourceTemplates' => array(
				array(
					'uriTemplate' => self::OUTLINE,
					'name'        => 'page-outline',
					'mimeType'    => 'application/json',
				) + $texts[ self::OUTLINE ],
				array(
					'uriTemplate' => self::PATTERN,
					'name'        => 'pattern',
					'mimeType'    => 'application/json',
				) + $texts[ self::PATTERN ],
				array(
					'uriTemplate' => Prompts::FILES,
					'name'        => 'skill-file',
					'mimeType'    => 'text/markdown',
				) + $texts[ Prompts::FILES ],
			),
		);
	}

	/**
	 * Result of resources/read.
	 *
	 * @param array<string, mixed> $params  Params with uri.
	 * @param Context              $context Protocol state.
	 * @return array{contents: list<array<string, mixed>>}
	 * @throws Protocol_Error For an unknown or missing resource, or a rejection of the ability.
	 */
	public static function read( array $params, Context $context ): array {
		$uri = is_string( $params['uri'] ?? null ) ? $params['uri'] : '';
		if ( str_starts_with( $uri, 'ui://' ) ) {
			$view = Apps::read( $uri );
			if ( null === $view ) {
				throw self::missing( $uri, $context );
			}
			return array( 'contents' => array( $view ) );
		}
		$file = Prompts::file( $uri );
		if ( null !== $file ) {
			return array( 'contents' => array( $file ) );
		}
		$route = self::route( $uri );
		if ( null === $route ) {
			throw self::missing( $uri, $context );
		}
		$value = 'leitvia/list-patterns' === $route[0] ? self::patterns() : self::run( $route[0], $route[1] );
		if ( is_wp_error( $value ) ) {
			throw self::rejected( $uri, $value, $context );
		}
		return array(
			'contents' => array(
				array(
					'uri'      => $uri,
					'mimeType' => 'application/json',
					'text'     => (string) wp_json_encode( $value ),
				),
			),
		);
	}

	/**
	 * Ability and input of a URI, null for an unknown one. A pattern slug may contain a slash or come encoded.
	 *
	 * @param string $uri Resource URI.
	 * @return array{0: string, 1: array<string, mixed>}|null
	 */
	private static function route( string $uri ): ?array {
		if ( isset( self::FIXED[ $uri ] ) ) {
			return self::FIXED[ $uri ];
		}
		if ( preg_match( '#^leitvia://page/([1-9]\d{0,18})/outline$#D', $uri, $match ) ) {
			return array( 'leitvia/get-page-outline', array( 'post' => (int) $match[1] ) );
		}
		if ( preg_match( '#^leitvia://pattern/(.+)$#Ds', $uri, $match ) ) {
			return array( 'leitvia/get-pattern', array( 'slug' => rawurldecode( $match[1] ) ) );
		}
		return null;
	}

	/**
	 * Result of an ability of the Studio registry, a rejection with status 404 when it is not offered.
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 */
	private static function run( string $name, array $input ) {
		$ability = Tools::abilities()[ str_replace( '/', '-', $name ) ] ?? null;
		return null === $ability ? new \WP_Error( 'leitvia_not_found', 'Unknown resource.', array( 'status' => 404 ) ) : Tools::run( $ability, $input );
	}

	/**
	 * Every page of list-patterns.
	 *
	 * @return array{items: list<mixed>, total: int}|\WP_Error
	 */
	private static function patterns() {
		$items = array();
		$page  = 1;
		do {
			$list = self::run(
				'leitvia/list-patterns',
				array(
					'page'     => $page,
					'per_page' => Read_Design::MAX_PER_PAGE,
				)
			);
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			$items = array_merge( $items, array_values( (array) $list['items'] ) );
		} while ( $page++ < (int) $list['pages'] );
		return array(
			'items' => $items,
			'total' => count( $items ),
		);
	}

	/**
	 * Error for a resource that does not exist.
	 *
	 * @param string  $uri     Resource URI.
	 * @param Context $context Protocol state.
	 */
	private static function missing( string $uri, Context $context ): Protocol_Error {
		return new Protocol_Error( $context->modern ? -32602 : Server::RESOURCE_NOT_FOUND, 'Resource not found', 404, array( 'uri' => $uri ) );
	}

	/**
	 * Error for a rejection of the ability: a missing resource for status 404 and for an object out of scope, which
	 * Outline answers for a post that does not exist as well, so an agent cannot tell them apart; else invalid params
	 * with the rejection.
	 *
	 * @param string    $uri     Resource URI.
	 * @param \WP_Error $error   Rejection.
	 * @param Context   $context Protocol state.
	 */
	private static function rejected( string $uri, \WP_Error $error, Context $context ): Protocol_Error {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400;
		if ( 404 === $status || 'leitvia_out_of_scope' === $error->get_error_code() ) {
			return self::missing( $uri, $context );
		}
		return new Protocol_Error(
			-32602,
			$error->get_error_message(),
			$status,
			array(
				'uri'       => $uri,
				'rejection' => Mcp::details( $error ),
			)
		);
	}

	/**
	 * Titles and descriptions by URI or template. Texts of Studio only, never data of the site.
	 *
	 * @return array<string, array{title: string, description: string}>
	 */
	private static function texts(): array {
		return array(
			'leitvia://contract' => array(
				'title'       => __( 'Design contract', 'leitvia-studio' ),
				'description' => __( 'The site and the design contract of the active theme, as leitvia-get-contract returns them.', 'leitvia-studio' ),
			),
			'leitvia://tokens'   => array(
				'title'       => __( 'Design tokens', 'leitvia-studio' ),
				'description' => __( 'The active design tokens with token sets, drift and contrast findings, as leitvia-get-tokens returns them.', 'leitvia-studio' ),
			),
			'leitvia://patterns' => array(
				'title'       => __( 'Patterns', 'leitvia-studio' ),
				'description' => __( 'Every pattern of every page of leitvia-list-patterns. Patterns outside the contract carry trust untrusted and status unreviewed until a person approves them in the Lab.', 'leitvia-studio' ),
			),
			'leitvia://inbox'    => array(
				'title'       => __( 'Inbox', 'leitvia-studio' ),
				'description' => __( 'Open change packages that wait for a person, as leitvia-list-packages with state open returns them.', 'leitvia-studio' ),
			),
			self::OUTLINE        => array(
				'title'       => __( 'Page outline', 'leitvia-studio' ),
				'description' => __( 'Block tree of a post with its document hash, as leitvia-get-page-outline returns it.', 'leitvia-studio' ),
			),
			self::PATTERN        => array(
				'title'       => __( 'Pattern', 'leitvia-studio' ),
				'description' => __( 'One pattern with markup, outline and usage, as leitvia-get-pattern returns it. The slug may contain a slash, such as leitvia/hero-split-image, or be block:<ID>.', 'leitvia-studio' ),
			),
			Prompts::FILES       => array(
				'title'       => __( 'Skill file', 'leitvia-studio' ),
				'description' => __( 'A Markdown file of a skill, workflow or the agent handbook that a prompt links to, such as leitvia://skills/leitvia-studio/handbook/README.md.', 'leitvia-studio' ),
			),
		);
	}
}
