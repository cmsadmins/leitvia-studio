<?php
/**
 * Add-on registration.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps every add-on that registered on leitvia_studio_init, compatible or not, so the settings screen can list both.
 */
final class Extensions {

	/**
	 * Slugs no add-on may use: "leitvia" would own the "leitvia/" prefix of Studio's entries.
	 */
	private const RESERVED = array( 'leitvia', 'leitvia-studio' );

	/**
	 * Registered add-ons by slug.
	 *
	 * @var array<string, array{slug: string, label: string, version: string, requires_studio: string, requires_api: int, compatible: bool, reason: string}>
	 */
	private array $items = array();

	/**
	 * Creates the add-on list.
	 *
	 * @param \Closure $initializing Returns whether leitvia_studio_init is running.
	 * @phpstan-param \Closure(): bool $initializing
	 */
	public function __construct( private \Closure $initializing ) {
		$this->items['leitvia-studio'] = array(
			'slug'            => 'leitvia-studio',
			'label'           => 'Leitvia Studio',
			'version'         => LEITVIA_STUDIO_VERSION,
			'requires_studio' => LEITVIA_STUDIO_VERSION,
			'requires_api'    => LEITVIA_STUDIO_API,
			'compatible'      => true,
			'reason'          => '',
		);
	}

	/**
	 * Registers an add-on. Incompatible add-ons are kept with compatible = false and a reason.
	 *
	 * @param string               $slug Add-on slug, also the prefix of its registry entries.
	 * @param array<string, mixed> $args label, version (semver), requires_studio (semver), requires_api (int,
	 *                                   accepted from LEITVIA_STUDIO_API_MIN to LEITVIA_STUDIO_API).
	 * @return bool Whether the add-on is registered and compatible.
	 */
	public function register( string $slug, array $args ): bool {
		$error = $this->validate( $slug, $args );
		if ( '' !== $error ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Add-on "%s": %s', $slug, $error ) ), '0.1.0' );
			return false;
		}
		$requires_studio = (string) $args['requires_studio'];
		$requires_api    = (int) $args['requires_api'];
		$reason          = match ( true ) {
			! version_compare( LEITVIA_STUDIO_VERSION, $requires_studio, '>=' ) => 'requires_studio',
			$requires_api < LEITVIA_STUDIO_API_MIN || $requires_api > LEITVIA_STUDIO_API => 'requires_api',
			default => '',
		};

		$this->items[ $slug ] = array(
			'slug'            => $slug,
			'label'           => (string) $args['label'],
			'version'         => (string) $args['version'],
			'requires_studio' => $requires_studio,
			'requires_api'    => $requires_api,
			'compatible'      => '' === $reason,
			'reason'          => $reason,
		);
		return '' === $reason;
	}

	/**
	 * Whether the add-on is registered and compatible.
	 *
	 * @param string $slug Add-on slug.
	 */
	public function is_compatible( string $slug ): bool {
		return $this->items[ $slug ]['compatible'] ?? false;
	}

	/**
	 * All registered add-ons, compatible or not.
	 *
	 * @return array<string, array{slug: string, label: string, version: string, requires_studio: string, requires_api: int, compatible: bool, reason: string}>
	 */
	public function all(): array {
		return $this->items;
	}

	/**
	 * Returns why the registration is malformed, or '' when it is well-formed.
	 *
	 * @param string               $slug Add-on slug.
	 * @param array<string, mixed> $args Registration arguments.
	 */
	private function validate( string $slug, array $args ): string {
		$semver = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';
		return match ( true ) {
			! ( $this->initializing )() => 'add-ons register on the leitvia_studio_init action',
			! preg_match( '/^[a-z0-9-]+$/', $slug ) => 'slug must contain lowercase letters, digits and hyphens only',
			in_array( $slug, self::RESERVED, true ) => 'slug is reserved for Leitvia Studio',
			isset( $this->items[ $slug ] ) => 'already registered',
			! is_string( $args['label'] ?? null ) || '' === $args['label'] => 'label is missing',
			! is_string( $args['version'] ?? null ) || ! preg_match( $semver, $args['version'] ) => 'version must be a semantic version',
			! is_string( $args['requires_studio'] ?? null ) || ! preg_match( $semver, $args['requires_studio'] ) => 'requires_studio must be a semantic version',
			! is_int( $args['requires_api'] ?? null ) => 'requires_api must be an integer',
			default => '',
		};
	}
}
