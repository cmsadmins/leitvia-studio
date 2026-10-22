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
	 * Loads the contract of the active theme: child theme first, then the parent theme.
	 *
	 * The first existing file wins, valid or not. A broken child theme contract does not fall back to the parent
	 * theme contract; its error stays visible, so the child theme author sees and fixes it.
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

		foreach ( $candidates as $path ) {
			if ( self::is_local_file( $path ) ) {
				return self::from_file( $path );
			}
		}
		return new self();
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
	 * Whether the contract exists and passed validation.
	 */
	public function is_valid(): bool {
		return $this->exists && '' === $this->error;
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
