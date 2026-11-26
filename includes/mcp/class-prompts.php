<?php
/**
 * MCP prompts of the server leitvia: the skills and workflows of Leitvia.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Mcp;

use LeitviaStudio\Extension\Studio;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages go out as JSON-RPC, never as HTML.

/**
 * One prompt per entry of the registry mcp_prompts, named after the entry with a hyphen for the slash (leitvia/page
 * becomes leitvia-page). Its text is the Markdown file of the entry without frontmatter, its links to other Markdown
 * files below the root of the entry turned into resources leitvia://skills/<owner>/<path>, and the arguments of the
 * client below it. The title is the first heading, else the prompt name; the description comes from the frontmatter
 * of a SKILL.md, or from the first paragraph of a workflow; the entry may set both.
 *
 * Studio registers the free skills and workflows from its copy in skills/ (scripts/copy-skills.mjs), so the prompts
 * match the version of the plugin. Files ship with the plugin or an add-on; no text of the site reaches a prompt.
 * Arguments live in the entry, since the skill format has no place for them.
 */
final class Prompts {

	/**
	 * Resource template of the files a prompt links to.
	 */
	public const FILES = 'leitvia://skills/{owner}/{+path}';

	/**
	 * Option with the counter of changes of the prompt list, see touch().
	 */
	public const OPTION = 'leitvia_studio_mcp_prompts';

	/**
	 * Workflows with steps of a person on the server (WP-CLI), not of an agent.
	 */
	private const PERSON_ONLY = array( 'new-site-operator' );

	/**
	 * Hooks. Runs from Server::boot().
	 */
	public static function boot(): void {
		add_action( 'leitvia_studio_init', array( self::class, 'register' ) );
		// Another plugin may bring or take prompts of its own; subscribed clients list them again.
		add_action( 'activated_plugin', array( self::class, 'touch' ), 10, 0 );
		add_action( 'deactivated_plugin', array( self::class, 'touch' ), 10, 0 );
	}

	/**
	 * Folder of the copy of the skills in the plugin.
	 */
	public static function root(): string {
		return wp_normalize_path( dirname( LEITVIA_STUDIO_FILE ) ) . '/skills';
	}

	/**
	 * Registers the skills and agent workflows of the copy. Runs on leitvia_studio_init.
	 *
	 * @param Studio $studio Studio.
	 */
	public static function register( Studio $studio ): void {
		$root  = self::root();
		$files = array();
		foreach ( array_filter( (array) glob( $root . '/skills/leitvia-*/SKILL.md' ) ) as $file ) {
			$files[ substr( basename( dirname( $file ) ), strlen( 'leitvia-' ) ) ] = $file;
		}
		foreach ( array_filter( (array) glob( $root . '/workflows/*.md' ) ) as $file ) {
			$name = basename( $file, '.md' );
			if ( ! in_array( $name, self::PERSON_ONLY, true ) ) {
				$files[ $name ] = $file;
			}
		}
		foreach ( $files as $name => $file ) {
			$studio->registry( 'mcp_prompts' )->register(
				'leitvia/' . strtolower( (string) $name ),
				array(
					'path' => $file,
					'root' => $root,
				),
				'leitvia-studio'
			);
		}
	}

	/**
	 * Why an entry of mcp_prompts is invalid, '' when it is valid. Runs as guard of the registry.
	 *
	 * @param array<string, mixed> $item Entry.
	 */
	public static function invalid( array $item ): string {
		// realpath(), so a link named .md cannot point at another file.
		$path = realpath( (string) $item['path'] );
		if ( false === $path || ! str_ends_with( $path, '.md' ) ) {
			return 'path must name a Markdown file';
		}
		if ( isset( $item['root'] ) ) {
			$root = is_string( $item['root'] ) ? realpath( $item['root'] ) : false;
			if ( false === $root || ! is_dir( $root ) ) {
				return 'root must be the folder its links may point into';
			}
			if ( ! str_starts_with( wp_normalize_path( $path ), untrailingslashit( wp_normalize_path( $root ) ) . '/' ) ) {
				return 'path must lie below root';
			}
		}
		foreach ( array( 'title', 'description' ) as $field ) {
			if ( isset( $item[ $field ] ) && ( ! is_string( $item[ $field ] ) || '' === $item[ $field ] ) ) {
				return "{$field} must be a string";
			}
		}
		if ( isset( $item['arguments'] ) && ! is_array( $item['arguments'] ) ) {
			return 'arguments must be a list';
		}
		foreach ( (array) ( $item['arguments'] ?? array() ) as $argument ) {
			if ( ! is_array( $argument ) || ! is_string( $argument['name'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9_]*$/D', $argument['name'] ) || ! is_string( $argument['description'] ?? null ) ) {
				return 'each argument needs a name (a-z, 0-9, _) and a description';
			}
			if ( isset( $argument['complete'] ) && ! in_array( $argument['complete'], Completion::SOURCES, true ) ) {
				return 'complete must be one of ' . implode( ', ', Completion::SOURCES );
			}
		}
		return '';
	}

	/**
	 * Result of prompts/list.
	 *
	 * @return array{prompts: list<array<string, mixed>>}
	 */
	public static function list(): array {
		$prompts = array();
		foreach ( self::entries() as $name => $entry ) {
			$head      = self::head( (string) $entry['path'], $name );
			$prompts[] = array(
				'name'        => $name,
				'title'       => (string) ( $entry['title'] ?? $head['title'] ),
				'description' => (string) ( $entry['description'] ?? $head['description'] ),
				'arguments'   => array_map(
					static fn( array $argument ): array => array(
						'name'        => (string) $argument['name'],
						'description' => (string) $argument['description'],
						'required'    => ! empty( $argument['required'] ),
					),
					self::arguments( $name, $entry )
				),
			);
		}
		return array( 'prompts' => $prompts );
	}

	/**
	 * Result of prompts/get.
	 *
	 * @param array<string, mixed> $params Params with name and arguments (strings).
	 * @return array{description: string, messages: list<array<string, mixed>>}
	 * @throws Protocol_Error For an unknown prompt or a missing required argument.
	 */
	public static function get( array $params ): array {
		$name  = is_string( $params['name'] ?? null ) ? $params['name'] : '';
		$entry = self::entries()[ $name ] ?? null;
		if ( null === $entry ) {
			throw new Protocol_Error( -32602, sprintf( 'Unknown prompt: %s', $name ) );
		}
		$given = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();
		$input = array();
		foreach ( self::arguments( $name, $entry ) as $argument ) {
			$value = $given[ $argument['name'] ] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$input[] = '- ' . $argument['name'] . ': ' . trim( $value );
			} elseif ( ! empty( $argument['required'] ) ) {
				throw new Protocol_Error( -32602, sprintf( 'Missing required argument: %s', $argument['name'] ) );
			}
		}
		$path = (string) $entry['path'];
		$text = self::links( self::body( $path ), $path, $entry );
		if ( array() !== $input ) {
			$text .= "\n\n## Input\n\n" . implode( "\n", $input ) . "\n";
		}
		return array(
			'description' => (string) ( $entry['description'] ?? self::head( $path, $name )['description'] ),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
	}

	/**
	 * Content of a Markdown file a prompt links to, null when the URI names none.
	 *
	 * @param string $uri leitvia://skills/<owner>/<path>.
	 * @return array{uri: string, mimeType: string, text: string}|null
	 */
	public static function file( string $uri ): ?array {
		if ( ! preg_match( '#^leitvia://skills/([A-Za-z0-9_-]+)/(.+\.md)$#D', $uri, $match ) ) {
			return null;
		}
		$relative = self::normalize( rawurldecode( $match[2] ) );
		foreach ( self::entries() as $entry ) {
			$root = self::root_of( $entry );
			if ( null === $relative || '' === $root || $match[1] !== $entry['owner'] ) {
				continue;
			}
			$real = realpath( $root . '/' . $relative );
			$base = realpath( $root );
			if ( false === $real || false === $base || ! str_starts_with( wp_normalize_path( $real ), wp_normalize_path( $base ) . '/' ) || ! is_file( $real ) ) {
				continue;
			}
			return array(
				'uri'      => $uri,
				'mimeType' => 'text/markdown',
				'text'     => self::links( self::body( $real ), $root . '/' . $relative, $entry ),
			);
		}
		return null;
	}

	/**
	 * Completion source of a prompt argument, '' for none.
	 *
	 * @param string $prompt   Prompt name.
	 * @param string $argument Argument name.
	 */
	public static function source( string $prompt, string $argument ): string {
		$entry = self::entries()[ $prompt ] ?? null;
		if ( null === $entry ) {
			return '';
		}
		foreach ( self::arguments( $prompt, $entry ) as $item ) {
			if ( $argument === $item['name'] ) {
				return (string) ( $item['complete'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Counter of changes of the prompt list, read past the object cache for the listen loop.
	 */
	public static function changed(): int {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * Raises the counter: a plugin was switched on or off.
	 */
	public static function touch(): void {
		update_option( self::OPTION, self::changed() + 1, false );
	}

	/**
	 * Entries with a readable file by prompt name, sorted.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function entries(): array {
		$entries = array();
		foreach ( leitvia_studio()->registry( 'mcp_prompts' )->all() as $name => $entry ) {
			if ( is_readable( (string) $entry['path'] ) ) {
				$entries[ str_replace( '/', '-', $name ) ] = $entry;
			}
		}
		ksort( $entries );
		return $entries;
	}

	/**
	 * Arguments of a prompt: those of the entry, else those Studio gives its own prompts.
	 *
	 * @param string               $prompt Prompt name.
	 * @param array<string, mixed> $entry  Entry.
	 * @return list<array<string, mixed>>
	 */
	private static function arguments( string $prompt, array $entry ): array {
		if ( isset( $entry['arguments'] ) ) {
			return array_values( (array) $entry['arguments'] );
		}
		return match ( $prompt ) {
			'leitvia-page'   => array(
				array(
					'name'        => 'goal',
					'description' => __( 'Goal of the page, such as visitors book a consultation.', 'leitvia-studio' ),
				),
				array(
					'name'        => 'points',
					'description' => __( 'Content points, facts and links the page must carry.', 'leitvia-studio' ),
				),
			),
			'leitvia-setup'  => array(
				array(
					'name'        => 'briefing',
					'description' => __( 'What the site offers, for whom, and which pages it needs.', 'leitvia-studio' ),
				),
				array(
					'name'        => 'recipe',
					'description' => __( 'Name of a recipe from leitvia-list-recipes, such as leitvia/company.', 'leitvia-studio' ),
					'complete'    => 'recipes',
				),
			),
			'leitvia-design' => array(
				array(
					'name'        => 'brand',
					'description' => __( 'Brand colors, fonts and logo, or where to find them.', 'leitvia-studio' ),
				),
			),
			default          => array(
				array(
					'name'        => 'task',
					'description' => __( 'What the person wants done, in their own words.', 'leitvia-studio' ),
				),
			),
		};
	}

	/**
	 * Title and description: the first heading, else the name of the frontmatter, else the prompt name; the
	 * description of the frontmatter of a SKILL.md, else the first paragraph after the heading.
	 *
	 * @param string $path   File.
	 * @param string $prompt Prompt name, the last fallback of the title.
	 * @return array{title: string, description: string}
	 */
	private static function head( string $path, string $prompt ): array {
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin or an add-on.
		$name = '';
		$info = '';
		// ponytail: single-line values as every skill has them; a YAML parser comes with the first folded description.
		if ( preg_match( '/\A---\n(.*?)\n---\n/s', $text, $front ) ) {
			preg_match( '/^name:\s*(.+)$/m', $front[1], $found );
			preg_match( '/^description:\s*(.+)$/m', $front[1], $description );
			$name = trim( $found[1] ?? '' );
			$info = trim( $description[1] ?? '' );
			$text = substr( $text, strlen( $front[0] ) );
		}
		preg_match( '/^# (.+)$/m', $text, $heading );
		preg_match( '/^# .+\n\n(.+)$/m', $text, $paragraph );
		$title = trim( $heading[1] ?? '' );
		return array(
			'title'       => '' !== $title ? $title : ( '' !== $name ? $name : $prompt ),
			'description' => '' !== $info ? $info : trim( $paragraph[1] ?? '' ),
		);
	}

	/**
	 * Text of a file without its frontmatter.
	 *
	 * @param string $path File.
	 */
	private static function body( string $path ): string {
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin or an add-on.
		return (string) preg_replace( '/\A---\n.*?\n---\n+/s', '', $text );
	}

	/**
	 * Relative links to Markdown files below the root as resource URIs; links outside the root, to other files and
	 * to the web stay as they are.
	 *
	 * @param string               $text  Markdown.
	 * @param string               $path  File the text comes from.
	 * @param array<string, mixed> $entry Entry with owner and root.
	 */
	private static function links( string $text, string $path, array $entry ): string {
		$root = self::root_of( $entry );
		$path = wp_normalize_path( $path );
		if ( '' === $root || ! str_starts_with( $path, $root . '/' ) ) {
			return $text;
		}
		$owner = (string) $entry['owner'];
		$dir   = substr( dirname( $path ), strlen( $root ) + 1 );
		return (string) preg_replace_callback(
			'/\]\((?![a-z][a-z0-9+.-]*:|#)([^)#\s]+\.md)(#[^)\s]*)?\)/i',
			static function ( array $link ) use ( $root, $owner, $dir ): string {
				$target = self::normalize( ( '' === $dir ? '' : $dir . '/' ) . $link[1] );
				return null !== $target && is_file( $root . '/' . $target ) ? '](leitvia://skills/' . $owner . '/' . $target . ( $link[2] ?? '' ) . ')' : $link[0];
			},
			$text
		);
	}

	/**
	 * A relative path with . and .. resolved, null when it leaves the root or holds a null byte (realpath() throws).
	 *
	 * @param string $path Relative path.
	 */
	private static function normalize( string $path ): ?string {
		if ( str_contains( $path, "\0" ) ) {
			return null;
		}
		$parts = array();
		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $part ) {
			if ( '..' === $part ) {
				if ( array() === $parts ) {
					return null;
				}
				array_pop( $parts );
			} elseif ( '' !== $part && '.' !== $part ) {
				$parts[] = $part;
			}
		}
		return array() === $parts ? null : implode( '/', $parts );
	}

	/**
	 * Root folder of an entry without trailing slash, '' when it has none.
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	private static function root_of( array $entry ): string {
		return is_string( $entry['root'] ?? null ) && '' !== $entry['root'] ? untrailingslashit( wp_normalize_path( $entry['root'] ) ) : '';
	}
}
