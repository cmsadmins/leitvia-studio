<?php
/**
 * Extension API entry point.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Extension;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the add-on list and the eight registries. Studio uses the same registries as its add-ons.
 */
final class Studio {

	/**
	 * Required fields per registry type, see Registry for the spec format.
	 */
	public const FIELDS = array(
		'abilities'     => array(
			'args'  => 'array',
			'group' => array( 'read', 'setup', 'build', 'maintain' ),
		),
		'blocks'        => array( 'path' => 'string' ),
		'lab_panels'    => array(
			'label'      => 'string',
			'script'     => 'string',
			'order'      => 'int',
			'capability' => 'string',
		),
		'token_sources' => array(
			'label'    => 'string',
			'callback' => 'callable',
		),
		'sync_targets'  => array(
			'label'  => 'string',
			'export' => 'callable',
		),
		'checks'        => array(
			'label'    => 'string',
			'scope'    => array( 'token_set', 'pattern', 'content', 'page' ),
			'callback' => 'callable',
		),
		'recipes'       => array(
			'label' => 'string',
			'path'  => 'string',
		),
		'patterns'      => array(
			'title'       => 'string',
			'description' => 'string',
			'area'        => array( 'hero', 'content', 'teaser', 'proof', 'cta', 'offer' ),
			'composition' => 'array',
		),
	);

	/**
	 * Whether leitvia_studio_init is running.
	 *
	 * @var bool
	 */
	private bool $initializing = false;

	/**
	 * Registered add-ons.
	 *
	 * @var Extensions
	 */
	private Extensions $extensions;

	/**
	 * Registries by type.
	 *
	 * @var array<string, Registry>
	 */
	private array $registries = array();

	/**
	 * Creates empty registries.
	 */
	public function __construct() {
		$this->reset();
	}

	/**
	 * The add-on list.
	 */
	public function extensions(): Extensions {
		return $this->extensions;
	}

	/**
	 * One registry.
	 *
	 * @param string $type One of the keys of self::FIELDS.
	 * @throws \InvalidArgumentException For an unknown type.
	 */
	public function registry( string $type ): Registry {
		if ( ! isset( $this->registries[ $type ] ) ) {
			throw new \InvalidArgumentException( esc_html( "Unknown Leitvia Studio registry \"{$type}\"" ) );
		}
		return $this->registries[ $type ];
	}

	/**
	 * Whether leitvia_studio_init is running, the only time add-ons and entries can register.
	 */
	public function is_initializing(): bool {
		return $this->initializing;
	}

	/**
	 * Lets add-ons register; Studio itself is always in the add-on list. Runs on init, priority 5.
	 */
	public function initialize(): void {
		$this->initializing = true;
		/**
		 * Fires once on init (priority 5). Add-ons register themselves and their registry entries here.
		 *
		 * @param Studio $studio The Studio instance, also returned by leitvia_studio().
		 */
		do_action( 'leitvia_studio_init', $this );
		$this->initializing = false;
	}

	/**
	 * Clears all registrations and runs leitvia_studio_init again. For tests only.
	 */
	public function reset_for_tests(): void {
		$this->reset();
		$this->initialize();
	}

	/**
	 * Registers the blocks entries with core. Runs on init, priority 20.
	 */
	public function register_blocks(): void {
		foreach ( $this->registry( 'blocks' )->all() as $item ) {
			register_block_type( $item['path'] );
		}
	}

	/**
	 * Registers the ability category when there is at least one ability. Runs on wp_abilities_api_categories_init.
	 */
	public function register_ability_category(): void {
		// Idempotent: tests and late loaders fire the action again after core built its registry.
		if ( ! $this->registry( 'abilities' )->all() || wp_has_ability_category( 'leitvia' ) ) {
			return;
		}
		wp_register_ability_category(
			'leitvia',
			array(
				'label'       => leitvia_studio_brand_short(),
				/* translators: %s: product name */
				'description' => sprintf( __( 'Abilities of %s and its add-ons.', 'leitvia-studio' ), leitvia_studio_brand()['name'] ),
			)
		);
	}

	/**
	 * Hands the abilities entries to core; register() already checked them. meta.public is never set here.
	 * Runs on wp_abilities_api_init.
	 */
	public function register_abilities(): void {
		foreach ( $this->registry( 'abilities' )->all() as $name => $item ) {
			if ( wp_has_ability( $name ) ) {
				continue;
			}
			wp_register_ability( $name, $item['args'] + array( 'category' => 'leitvia' ) );
		}
	}

	/**
	 * Returns why an abilities entry may not reach core, or ''. MCP clients assume the worst without annotations.
	 *
	 * @param array<string, mixed> $item Entry with the arguments of wp_register_ability() in 'args'.
	 */
	private static function ability_error( array $item ): string {
		$args        = $item['args'];
		$annotations = $args['meta']['annotations'] ?? null;
		$complete    = is_array( $annotations );
		foreach ( array( 'readonly', 'destructive', 'idempotent' ) as $key ) {
			$complete = $complete && is_bool( $annotations[ $key ] ?? null );
		}
		return is_callable( $args['permission_callback'] ?? null ) && $complete
			? ''
			: 'args need a callable permission_callback and boolean meta.annotations readonly, destructive and idempotent';
	}

	/**
	 * Empties the add-on list and the registries.
	 */
	private function reset(): void {
		$this->extensions = new Extensions( fn(): bool => $this->initializing );
		foreach ( self::FIELDS as $type => $fields ) {
			$guard                     = function ( string $owner, array $item ) use ( $type ): string {
				if ( ! $this->initializing ) {
					return 'entries register on the leitvia_studio_init action';
				}
				if ( ! $this->extensions->is_compatible( $owner ) ) {
					return "owner {$owner} is not a registered, compatible add-on";
				}
				return 'abilities' === $type ? self::ability_error( $item ) : '';
			};
			$this->registries[ $type ] = new Registry( $type, $fields, $guard );
		}
	}
}
