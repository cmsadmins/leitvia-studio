<?php
/**
 * Read-only view of the leitvia-contract.json of the active theme.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Loads and validates a theme contract. The file is only read, never written.
 */
final class Contract {

	public const SUPPORTED_MAJOR = 1;
	public const FILE            = 'leitvia-contract.json';
	private const MAX_BYTES      = 2 * 1024 * 1024;
	private const REQUIRED       = array( 'contractVersion', 'theme', 'roles', 'pairs', 'patterns' );

	/**
	 * Role group of the contract => preset type in var:preset|<type>|<slug> and --wp--preset--<type>--<slug>, as
	 * PRESET_TYPES in scripts/lib/lint-rules.mjs.
	 */
	public const PRESET_TYPES = array(
		'color'      => 'color',
		'spacing'    => 'spacing',
		'fontSize'   => 'font-size',
		'fontFamily' => 'font-family',
		'shadow'     => 'shadow',
		'radius'     => 'border-radius',
	);

	/**
	 * Decoded contract, empty unless valid.
	 *
	 * @var array<string, mixed>
	 */
	private array $data = array();

	/**
	 * Why the contract is invalid, empty when valid.
	 *
	 * @var string
	 */
	private string $error = '';

	/**
	 * Whether a contract file was found.
	 *
	 * @var bool
	 */
	private bool $exists = false;

	/**
	 * Theme whose contract this file adds to (key extends), empty for a contract of its own.
	 *
	 * @var string
	 */
	private string $extends = '';

	/**
	 * Patterns of a contract that adds to the contract of the parent theme.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $additions = array();

	/**
	 * Loads the contract of the active theme: child theme first, then the parent theme.
	 *
	 * The first existing file wins, valid or not. A broken child theme contract does not fall back to the parent
	 * theme contract; its error stays visible, so the child theme author sees and fixes it. A child theme contract
	 * with "extends": "<theme>" holds only patterns and adds them to the next contract found, see add().
	 */
	public static function for_active_theme(): self {
		$candidates = array_unique(
			array(
				trailingslashit( get_stylesheet_directory() ) . self::FILE,
				trailingslashit( get_template_directory() ) . self::FILE,
			)
		);

		/**
		 * Filters the candidate paths of the theme contract; the first existing local file wins.
		 * Stream wrappers such as php:// or phar:// are ignored.
		 *
		 * @param list<string> $candidates Absolute paths, child theme before parent theme.
		 */
		$candidates = (array) apply_filters( 'leitvia_studio_contract_path', array_values( $candidates ) );

		// A child theme contract with extends adds its patterns to the next contract (the parent theme) instead of
		// replacing it, so a child theme lists only its own patterns.
		$addition = null;
		foreach ( $candidates as $path ) {
			if ( ! self::is_local_file( $path ) ) {
				continue;
			}
			$contract = self::from_file( $path );
			if ( null === $addition && '' !== $contract->extends ) {
				$addition = $contract;
				continue;
			}
			return $addition ? $contract->add( $addition ) : $contract;
		}
		return $addition ? $addition->without_parent() : new self();
	}

	/**
	 * The contract with the patterns of a child theme contract after its own; a slug the contract already lists keeps
	 * its entry. The child theme contract with its error when it is broken or extends another theme.
	 *
	 * @param self $addition Contract with extends, from from_file().
	 */
	private function add( self $addition ): self {
		if ( '' !== $addition->error || ! $this->is_valid() ) {
			return $this->is_valid() ? $addition : $this;
		}
		if ( ( $this->data['theme'] ?? null ) !== $addition->extends ) {
			return $addition->without_parent();
		}
		$known = array_column( $this->data['patterns'], 'slug' );
		foreach ( $addition->additions as $pattern ) {
			if ( ! in_array( $pattern['slug'], $known, true ) ) {
				$this->data['patterns'][] = $pattern;
			}
		}
		return $this;
	}

	/**
	 * Loads and validates one contract file.
	 *
	 * @param string $path Absolute local path to a contract file.
	 */
	public static function from_file( string $path ): self {
		$contract = new self();
		if ( ! self::is_local_file( $path ) ) {
			return $contract;
		}
		$contract->exists = true;

		if ( filesize( $path ) > self::MAX_BYTES ) {
			$contract->error = __( 'The theme contract is larger than 2 MB.', 'leitvia-studio' );
			return $contract;
		}

		// Not wp_json_file_decode(): it raises a PHP notice on broken JSON.
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme file.
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			$contract->error = __( 'The theme contract is not valid JSON.', 'leitvia-studio' );
			return $contract;
		}

		if ( isset( $data['extends'] ) ) {
			$contract->extends = is_string( $data['extends'] ) && '' !== $data['extends'] ? $data['extends'] : '-';
			if ( '-' === $contract->extends || ! self::is_list_of( $data['patterns'] ?? null, static fn( array $pattern ): bool => is_string( $pattern['slug'] ?? null ) ) ) {
				/* translators: %s: field name in the theme contract. */
				$contract->error = sprintf( __( 'The field %s of the theme contract has an invalid structure.', 'leitvia-studio' ), '-' === $contract->extends ? 'extends' : 'patterns' );
				return $contract;
			}
			// Valid only together with the contract of the theme it extends, see add().
			$contract->additions = $data['patterns'];
			return $contract;
		}

		$contract->error = self::validate( $data );
		if ( '' === $contract->error ) {
			$contract->data = $data;
		}
		return $contract;
	}

	/**
	 * Whether the path is a readable plain file on disk, not a stream wrapper.
	 *
	 * @param mixed $path Candidate path.
	 */
	private static function is_local_file( $path ): bool {
		return is_string( $path ) && ! str_contains( $path, '://' ) && is_file( $path ) && is_readable( $path );
	}

	/**
	 * Returns an error message, or an empty string for a valid contract.
	 *
	 * @param array<mixed> $data Decoded contract.
	 */
	private static function validate( array $data ): string {
		foreach ( self::REQUIRED as $field ) {
			if ( empty( $data[ $field ] ) ) {
				/* translators: %s: field name in the theme contract. */
				return sprintf( __( 'The theme contract misses the required field %s.', 'leitvia-studio' ), $field );
			}
		}
		if ( ! is_string( $data['contractVersion'] ) || ! preg_match( '/^(\d+)\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $data['contractVersion'], $match ) ) {
			return __( 'The theme contract has no valid contractVersion.', 'leitvia-studio' );
		}
		if ( self::SUPPORTED_MAJOR !== (int) $match[1] ) {
			/* translators: %d: major version of the theme contract. */
			return sprintf( __( 'The theme contract uses the unsupported major version %d.', 'leitvia-studio' ), (int) $match[1] );
		}

		$colors = is_array( $data['roles'] ) ? ( $data['roles']['color'] ?? null ) : null;
		$checks = array(
			'roles.color' => self::is_list_of( $colors, static fn( array $role ): bool => is_string( $role['slug'] ?? null ) ),
			'pairs'       => self::is_list_of( $data['pairs'], static fn( array $pair ): bool => is_string( $pair['fg'] ?? null ) && is_string( $pair['bg'] ?? null ) && is_numeric( $pair['min'] ?? null ) ),
			'patterns'    => self::is_list_of( $data['patterns'], 'is_array' ),
		);
		foreach ( $checks as $field => $ok ) {
			if ( ! $ok ) {
				/* translators: %s: field name in the theme contract. */
				return sprintf( __( 'The field %s of the theme contract has an invalid structure.', 'leitvia-studio' ), $field );
			}
		}
		return '';
	}

	/**
	 * A child theme contract without the contract it extends: found, but not valid.
	 */
	private function without_parent(): self {
		if ( '' === $this->error ) {
			/* translators: %s: theme slug, for example leitvia. */
			$this->error = sprintf( __( 'The child theme contract extends %s, but the parent theme has no contract of that theme.', 'leitvia-studio' ), $this->extends );
		}
		return $this;
	}

	/**
	 * Whether the value is a list of arrays that all pass the check.
	 *
	 * @param mixed    $value Value to check.
	 * @param callable $check Check for one entry, receives an array.
	 */
	private static function is_list_of( $value, callable $check ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return false;
		}
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) || ! $check( $entry ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a contract file was found.
	 */
	public function exists(): bool {
		return $this->exists;
	}

	/**
	 * Whether the contract exists and passed validation. A child theme contract with extends from from_file() alone is
	 * not: it is valid only together with the contract it extends.
	 */
	public function is_valid(): bool {
		return $this->exists && '' === $this->error && array() !== $this->data;
	}

	/**
	 * Validation error, empty when valid or missing.
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * The contractVersion, empty unless valid.
	 */
	public function version(): string {
		return (string) ( $this->data['contractVersion'] ?? '' );
	}

	/**
	 * The whole contract, empty unless valid, with the section patterns of add-ons as addonPatterns.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		// Section patterns of add-ons (registry patterns) join the patterns of the theme and of Studio as addonPatterns.
		$addon = $this->data ? \LeitviaStudio\Plugin::studio()->registry( 'patterns' )->all() : array();
		if ( ! $addon ) {
			return $this->data;
		}
		$items = array();
		foreach ( $addon as $name => $item ) {
			$items[] = array( 'slug' => $name ) + array_diff_key( $item, array_flip( array( 'name', 'owner' ) ) );
		}
		return $this->data + array( 'addonPatterns' => $items );
	}

	/**
	 * Color role slugs in contract order.
	 *
	 * @return list<string>
	 */
	public function color_roles(): array {
		$roles = $this->data['roles']['color'] ?? array();
		return is_array( $roles ) ? array_map( 'strval', array_column( $roles, 'slug' ) ) : array();
	}

	/**
	 * Role slugs by preset type (color, spacing, font-size, font-family, shadow, border-radius), in contract order.
	 *
	 * @return array<string, list<string>>
	 */
	public function preset_roles(): array {
		$roles = array();
		foreach ( self::PRESET_TYPES as $group => $type ) {
			$list = $this->data['roles'][ $group ] ?? null;
			if ( is_array( $list ) ) {
				$roles[ $type ] = array_map( 'strval', array_column( $list, 'slug' ) );
			}
		}
		return $roles;
	}

	/**
	 * Core preset slugs a default theme uses (base, spacing 50, large) and the role each stands for, by preset type,
	 * from coreAliases. An alias that points to no role is left out.
	 *
	 * @return array<string, array<int|string, string>> Type => alias => role; PHP keeps numeric aliases such as 50 as
	 *         integer keys, a lookup by the string finds them.
	 */
	public function core_aliases(): array {
		$roles   = $this->preset_roles();
		$aliases = array();
		foreach ( (array) ( $this->data['coreAliases'] ?? array() ) as $group => $map ) {
			$type = self::PRESET_TYPES[ $group ] ?? null;
			if ( null === $type || ! is_array( $map ) ) {
				continue;
			}
			foreach ( $map as $alias => $role ) {
				if ( is_string( $role ) && in_array( $role, $roles[ $type ] ?? array(), true ) ) {
					$aliases[ $type ][ $alias ] = $role;
				}
			}
		}
		return $aliases;
	}

	/**
	 * Contrast pairs.
	 *
	 * @return list<array{fg: string, bg: string, min: float, ring?: string}>
	 */
	public function pairs(): array {
		return array_values( $this->data['pairs'] ?? array() );
	}

	/**
	 * Pattern metadata in contract order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function patterns(): array {
		return array_values( $this->data['patterns'] ?? array() );
	}

	/**
	 * Metadata of one pattern, or null when the contract does not list it.
	 *
	 * @param string $slug Pattern slug, for example leitvia/hero-split-image.
	 * @return array<string, mixed>|null
	 */
	public function pattern( string $slug ): ?array {
		foreach ( $this->patterns() as $pattern ) {
			if ( ( $pattern['slug'] ?? null ) === $slug ) {
				return $pattern;
			}
		}
		return null;
	}

	/**
	 * Style variations.
	 *
	 * @return list<array<mixed>>
	 */
	public function variations(): array {
		return array_values( array_filter( (array) ( $this->data['variations'] ?? array() ), 'is_array' ) );
	}

	/**
	 * Block catalog of Studio and its add-ons, see schemas/block-catalog.schema.json. Independent of the theme
	 * contract file: the blocks come from the plugins.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function blocks(): array {
		return \LeitviaStudio\Blocks\Catalog::get();
	}
}
