<?php
/**
 * Typed registry for Studio and add-on entries.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * Typed registry. A field spec is 'string', 'callable', 'int', 'array' or a list of allowed string values.
 * Names are "<owner>/<name>"; core Studio entries use the owner "leitvia-studio" and the prefix "leitvia/".
 */
final class Registry {

	/**
	 * Registered entries by name. Valid names are lowercase and never empty.
	 *
	 * @var array<lowercase-string&non-falsy-string, array<string, mixed>>
	 */
	private array $items = array();

	/**
	 * Creates a registry.
	 *
	 * @param string                             $type   Registry type, used in error messages.
	 * @param array<string, string|list<string>> $fields Required fields and their specs.
	 * @param \Closure|null                      $guard  Gets owner and entry after the field checks; returns an error message or ''.
	 * @phpstan-param (\Closure(string, array<string, mixed>): string)|null $guard
	 */
	public function __construct( private string $type, private array $fields, private ?\Closure $guard = null ) {}

	/**
	 * Registers an entry.
	 *
	 * @param string               $name  Entry name, "<owner>/<name>" or "leitvia/<name>" for Studio itself.
	 * @param array<string, mixed> $item  Entry fields.
	 * @param string               $owner Slug of the registering add-on.
	 * @return bool Whether the entry was registered.
	 * @phpstan-param lowercase-string&non-falsy-string $name
	 */
	public function register( string $name, array $item, string $owner ): bool {
		$error = $this->validate( $name, $item, $owner );
		if ( '' !== $error ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( '%s "%s": %s', $this->type, $name, $error ) ), '0.1.0' );
			return false;
		}
		$this->items[ $name ] = array(
			'owner' => $owner,
			'name'  => $name,
		) + $item;
		return true;
	}

	/**
	 * Returns one entry.
	 *
	 * @param string $name Entry name.
	 * @return array<string, mixed>|null
	 */
	public function get( string $name ): ?array {
		return $this->items[ $name ] ?? null;
	}

	/**
	 * Returns all entries, optionally filtered. The filter compares as strings, so use it on scalar fields only.
	 *
	 * @param array<string, mixed> $where Field => value filter.
	 * @return array<lowercase-string&non-falsy-string, array<string, mixed>>
	 */
	public function all( array $where = array() ): array {
		return array_filter(
			$this->items,
			static fn( array $item ): bool => array_intersect_assoc( $where, array_filter( $item, 'is_scalar' ) ) === $where
		);
	}

	/**
	 * Returns why an entry is invalid, or '' when it is valid.
	 *
	 * @param string               $name  Entry name.
	 * @param array<string, mixed> $item  Entry fields.
	 * @param string               $owner Owner slug.
	 */
	private function validate( string $name, array $item, string $owner ): string {
		$prefix = 'leitvia-studio' === $owner ? 'leitvia/' : $owner . '/';
		if ( ! str_starts_with( $name, $prefix ) || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ) {
			return "name must look like {$prefix}<name>";
		}
		if ( isset( $this->items[ $name ] ) ) {
			return 'already registered';
		}
		foreach ( $this->fields as $field => $spec ) {
			$value = $item[ $field ] ?? null;
			$ok    = match ( true ) {
				is_array( $spec )    => in_array( $value, $spec, true ),
				'callable' === $spec => is_callable( $value ),
				'string' === $spec   => is_string( $value ) && '' !== $value,
				'int' === $spec      => is_int( $value ),
				default              => is_array( $value ),
			};
			if ( ! $ok ) {
				return "field {$field} is missing or invalid";
			}
		}
		return $this->guard ? ( $this->guard )( $owner, $item ) : '';
	}
}
