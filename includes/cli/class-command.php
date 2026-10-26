<?php
/**
 * WP-CLI commands wp leitvia tokens|patterns|model|audit|package|recipe.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Cli;

use LeitviaStudio\Gateway\Abilities\Read_Audit;
use LeitviaStudio\Gateway\Abilities\Read_Design;
use LeitviaStudio\Gateway\Abilities\Setup;
use LeitviaStudio\Gateway\Gateway;
use LeitviaStudio\Gateway\Operation;
use LeitviaStudio\Gateway\Packages;
use LeitviaStudio\Gateway\Pipeline;
use LeitviaStudio\Gateway\Recipes;
use LeitviaStudio\Gateway\Rejection;
use LeitviaStudio\Gateway\Store;
use LeitviaStudio\Tokens\Compiler;

defined( 'ABSPATH' ) || exit;

/**
 * Every write runs through the same abilities as REST and MCP, so through Pipeline::run(), as the user of --user;
 * without one a write stops before anything happens. Reads of design data work without a user. Lists take
 * --format=table|json|yaml; a rejection exits with 1 and, with --format=json, prints code, message and data as JSON.
 *
 * Package export and import carry a hash manifest: import compares the base hash of every target with the current
 * one first and refuses with the list of conflicts, so nothing is overwritten. Files of pending media travel inside the
 * export, never as a path.
 */
final class Command {

	public const NO_USER = 'run with --user=<agent or admin>';

	/**
	 * Commands: name => method, short description, synopsis.
	 */
	private const COMMANDS = array(
		'tokens list'      => array( 'tokens_list', 'Lists the token sets.', '[--format=<format>]' ),
		'tokens export'    => array( 'tokens_export', 'Prints a token set as JSON with title and DTCG document.', '<id>' ),
		'tokens import'    => array( 'tokens_import', 'Creates a token set from an exported JSON file.', '<file> [--format=<format>]' ),
		'tokens activate'  => array( 'tokens_activate', 'Activates a token set.', '<id> [--base-hash=<hash>] [--format=<format>]' ),
		'patterns list'    => array( 'patterns_list', 'Lists the theme and Studio patterns.', '[--status=<status>] [--format=<format>]' ),
		'patterns export'  => array( 'patterns_export', 'Prints patterns with markup as JSON, all when no slug is given.', '[<slug>...]' ),
		'patterns import'  => array( 'patterns_import', 'Creates Lab patterns from a JSON list of title and content.', '<file> [--format=<format>]' ),
		'model get'        => array( 'model_get', 'Prints the content model declaration.', '[--format=<format>]' ),
		'model apply'      => array( 'model_apply', 'Adds or replaces content model entries from a JSON file.', '<file> [--dry-run] [--base-hash=<hash>] [--format=<format>]' ),
		'audit'            => array( 'audit', 'Audits published content page by page and sums the findings per check.', '[--after=<cursor>] [--limit=<n>] [--format=<format>]' ),
		'package list'     => array( 'package_list', 'Lists change packages.', '[--state=<state>] [--format=<format>]' ),
		'package show'     => array( 'package_show', 'Shows a change package with its operations.', '<id> [--format=<format>]' ),
		'package apply'    => array( 'package_apply', 'Applies the open operations of a change package.', '<id> [--ops=<ids>] [--format=<format>]' ),
		'package reject'   => array( 'package_reject', 'Rejects a change package.', '<id> [--format=<format>]' ),
		'package rollback' => array( 'package_rollback', 'Rolls back a change package.', '<id> [--format=<format>]' ),
		'package export'   => array( 'package_export', 'Prints a change package with hash manifest and embedded media as JSON; redirect it into a file.', '<id>' ),
		'package import'   => array( 'package_import', 'Creates an open change package from an export after checking every base hash.', '<file> [--format=<format>]' ),
		'recipe list'      => array( 'recipe_list', 'Lists the site recipes.', '[--format=<format>]' ),
		'recipe apply'     => array( 'recipe_apply', 'Applies a site recipe.', '<name> [--format=<format>]' ),
	);

	/**
	 * Registers the commands. Runs when the plugin loads under WP-CLI.
	 */
	public static function register(): void {
		$command = new self();
		foreach ( self::COMMANDS as $name => [ $method, $short, $synopsis ] ) {
			\WP_CLI::add_command(
				'leitvia ' . $name,
				array( $command, $method ),
				array(
					'shortdesc' => $short,
					'synopsis'  => $synopsis,
				)
			);
		}
	}

	/**
	 * Wp leitvia tokens list.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function tokens_list( array $args, array $assoc ): void {
		self::show( Read_Design::tokens()['sets'], $assoc, array( 'id', 'title', 'status', 'modified' ) );
	}

	/**
	 * Wp leitvia tokens export <id>.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function tokens_export( array $args, array $assoc ): void {
		$id = (int) ( $args[0] ?? 0 );
		self::user( $assoc );
		$document = current_user_can( 'read_post', $id ) ? Compiler::document( $id ) : Rejection::make( 'leitvia_forbidden', __( 'Your user may not read this token set.', 'leitvia-studio' ), 403, 'capability', array( 'token_set' => $id ), '', 'never' );
		self::show(
			array(
				'title'    => get_the_title( $id ),
				'document' => self::ok( $document, $assoc ),
			),
			$assoc
		);
	}

	/**
	 * Wp leitvia tokens import <file>: leitvia/create-token-set.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function tokens_import( array $args, array $assoc ): void {
		$data = self::read( (string) ( $args[0] ?? '' ), $assoc );
		self::show(
			self::execute(
				'leitvia/create-token-set',
				array(
					'title'     => (string) ( $data['title'] ?? '' ),
					'document'  => (array) ( $data['document'] ?? array() ),
					'base_hash' => '',
				),
				$assoc
			),
			$assoc
		);
	}

	/**
	 * Wp leitvia tokens activate <id>: leitvia/activate-token-set.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function tokens_activate( array $args, array $assoc ): void {
		self::show(
			self::execute(
				'leitvia/activate-token-set',
				array(
					'set'       => (int) ( $args[0] ?? 0 ),
					'base_hash' => (string) ( $assoc['base-hash'] ?? '' ),
				),
				$assoc
			),
			$assoc
		);
	}

	/**
	 * Wp leitvia patterns list.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function patterns_list( array $args, array $assoc ): void {
		$status = (string) ( $assoc['status'] ?? '' );
		$items  = array_filter( self::patterns(), static fn( array $item ): bool => '' === $status || $status === $item['status'] );
		self::show( array_values( $items ), $assoc, array( 'slug', 'title', 'source', 'status', 'replacedBy' ) );
	}

	/**
	 * Wp leitvia patterns export [<slug>...].
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function patterns_export( array $args, array $assoc ): void {
		$slugs = $args ? $args : array_column( self::patterns(), 'slug' );
		$list  = array();
		foreach ( $slugs as $slug ) {
			$pattern = self::ok( Read_Design::pattern( (string) $slug ), $assoc );
			$list[]  = array_intersect_key( $pattern, array_flip( array( 'slug', 'title', 'description', 'categories', 'content' ) ) );
		}
		self::show( $list, $assoc );
	}

	/**
	 * Wp leitvia patterns import <file>: leitvia/create-pattern per entry; exits with 1 when one is rejected.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function patterns_import( array $args, array $assoc ): void {
		$list = self::read( (string) ( $args[0] ?? '' ), $assoc );
		self::user( $assoc );
		$rows   = array();
		$failed = false;
		foreach ( array_values( $list ) as $entry ) {
			$entry  = (array) $entry;
			$result = self::execute(
				'leitvia/create-pattern',
				array(
					'title'     => (string) ( $entry['title'] ?? '' ),
					'content'   => (string) ( $entry['content'] ?? '' ),
					'base_hash' => '',
				),
				$assoc,
				false
			);
			$error  = is_wp_error( $result );
			$failed = $failed || $error;
			$rows[] = array(
				'title'   => (string) ( $entry['title'] ?? '' ),
				'result'  => $error ? $result->get_error_code() : $result['mode'],
				'post'    => $error ? 0 : (int) ( $result['target']['post'] ?? 0 ),
				'package' => $error ? 0 : (int) $result['package'],
			);
		}
		self::show( $rows, $assoc, array( 'title', 'result', 'post', 'package' ) );
		if ( $failed ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Wp leitvia model get.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function model_get( array $args, array $assoc ): void {
		self::show( Read_Design::content_model(), $assoc );
	}

	/**
	 * Wp leitvia model apply <file> [--dry-run]: leitvia/define-content-type, or only its merge and validation.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function model_apply( array $args, array $assoc ): void {
		$data  = self::read( (string) ( $args[0] ?? '' ), $assoc );
		$input = array(
			'declaration' => (array) ( $data['declaration'] ?? $data ),
			'base_hash'   => (string) ( $assoc['base-hash'] ?? '' ),
		);
		if ( empty( $assoc['dry-run'] ) ) {
			self::show( self::execute( 'leitvia/define-content-type', $input, $assoc ), $assoc );
			return;
		}
		// The same merge and schema check the ability runs, without the write.
		$parts = self::ok( Setup::describe_define_content_type( $input ), $assoc );
		$state = self::ok( ( $parts['change'] )( array( 'value' => get_option( Pipeline::MODEL_OPTION ) ) ), $assoc );
		self::show(
			array(
				'valid'       => true,
				'declaration' => $state['value'],
			),
			$assoc
		);
	}

	/**
	 * Wp leitvia audit: leitvia/audit-site, following the cursor to the end, then the findings per check.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function audit( array $args, array $assoc ): void {
		$after = (int) ( $assoc['after'] ?? 0 );
		$limit = (int) ( $assoc['limit'] ?? 20 );
		$items = array();
		$first = true;
		do {
			// The first page goes through the ability (permission, rate limit, audit entry); the command counts as one call.
			$page  = $first ? self::execute(
				'leitvia/audit-site',
				array(
					'after' => $after,
					'limit' => $limit,
				),
				$assoc
			) : Read_Audit::audit( (int) $after, $limit );
			$first = false;
			$items = array_merge( $items, (array) $page['items'] );
			$after = $page['next'];
		} while ( null !== $after );
		$summary = array();
		foreach ( $items as $item ) {
			foreach ( (array) $item['findings'] as $finding ) {
				$summary[ (string) $finding['check'] ] = ( $summary[ (string) $finding['check'] ] ?? 0 ) + 1;
			}
		}
		ksort( $summary );
		if ( 'table' !== self::format( $assoc, 'table' ) ) {
			self::show(
				array(
					'items'   => $items,
					'summary' => $summary,
				),
				$assoc
			);
			return;
		}
		$rows = array_map(
			static fn( array $item ): array => array(
				'post'     => $item['post'],
				'type'     => $item['type'],
				'title'    => $item['title'],
				'findings' => count( (array) $item['findings'] ),
			),
			$items
		);
		self::show( $rows, $assoc, array( 'post', 'type', 'title', 'findings' ) );
		$sums = array();
		foreach ( $summary as $check => $count ) {
			$sums[] = array(
				'check' => $check,
				'count' => $count,
			);
		}
		self::show( $sums, $assoc, array( 'check', 'count' ) );
	}

	/**
	 * Wp leitvia package list: leitvia/list-packages.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function package_list( array $args, array $assoc ): void {
		$input = isset( $assoc['state'] ) ? array( 'state' => (string) $assoc['state'] ) : array();
		self::show( self::execute( 'leitvia/list-packages', $input, $assoc ), $assoc, array( 'id', 'title', 'state', 'author', 'created', 'operations' ) );
	}

	/**
	 * Wp leitvia package show <id>: leitvia/get-package; the table lists the operations.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function package_show( array $args, array $assoc ): void {
		$package = self::execute( 'leitvia/get-package', array( 'id' => (int) ( $args[0] ?? 0 ) ), $assoc );
		if ( 'table' === self::format( $assoc, 'table' ) ) {
			self::show( (array) $package['items'], $assoc, array( 'id', 'ability', 'area', 'level', 'status', 'target' ) );
			return;
		}
		self::show( $package, $assoc );
	}

	/**
	 * Wp leitvia package apply <id> [--ops=<ids>]: leitvia/apply-package; exits with 1 on conflicts or errors.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function package_apply( array $args, array $assoc ): void {
		$input = array( 'id' => (int) ( $args[0] ?? 0 ) );
		$ops   = array_filter( array_map( 'intval', explode( ',', (string) ( $assoc['ops'] ?? '' ) ) ) );
		if ( $ops ) {
			$input['operations'] = array_values( $ops );
		}
		$report = self::execute( 'leitvia/apply-package', $input, $assoc );
		self::show( $report, $assoc );
		if ( $report['conflicts'] || $report['errors'] ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Wp leitvia package reject <id>. No ability exists for it: a person decides, as in the inbox. Writes nothing but
	 * the package state and gets an audit entry.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function package_reject( array $args, array $assoc ): void {
		$id = (int) ( $args[0] ?? 0 );
		self::user( $assoc );
		self::own( $id, $assoc );
		$result = Packages::reject( $id );
		Store::audit(
			array(
				'ability'    => 'cli/package-reject',
				'result'     => is_wp_error( $result ) ? 'rejected' : 'ok',
				'code'       => is_wp_error( $result ) ? $result->get_error_code() : '',
				'package_id' => $id,
			)
		);
		self::show( self::ok( $result, $assoc ), $assoc );
	}

	/**
	 * Wp leitvia package rollback <id>: leitvia/rollback.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function package_rollback( array $args, array $assoc ): void {
		self::show( self::execute( 'leitvia/rollback', array( 'id' => (int) ( $args[0] ?? 0 ) ), $assoc ), $assoc );
	}

	/**
	 * Wp leitvia package export <id>: the operations that are not rolled back, each with the hash its target had before,
	 * and the manifest of the objects they change, printed as JSON (wp leitvia package export 12 > package.json); the
	 * plugin writes no file of its own. Files of new media are embedded; a new attachment without its file is refused.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function package_export( array $args, array $assoc ): void {
		$id = (int) ( $args[0] ?? 0 );
		self::user( $assoc );
		self::own( $id, $assoc );
		$operations = array();
		foreach ( Packages::operations( $id ) as $entry ) {
			if ( ! isset( $entry['rolled_back'] ) ) {
				$operations[] = self::ok( self::portable( $entry ), $assoc );
			}
		}
		$export = array(
			'objects'        => self::objects( $operations ),
			'operations'     => $operations,
			'created'        => gmdate( 'c' ),
			'studio_version' => LEITVIA_STUDIO_VERSION,
			'title'          => get_the_title( $id ),
		);
		\WP_CLI::line( (string) wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Wp leitvia package import <file>: checks the base hash of every target and refuses with leitvia_import_conflict
	 * and the list of conflicts; otherwise an open change package of the user, applied later with package apply.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function package_import( array $args, array $assoc ): void {
		$data = self::read( (string) ( $args[0] ?? '' ), $assoc );
		self::user( $assoc );
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			self::fail( Rejection::make( 'leitvia_forbidden', __( 'Your user may not create change packages.', 'leitvia-studio' ), 403, 'capability', array(), '', 'never' ), $assoc );
		}
		$operations = array();
		foreach ( (array) ( $data['operations'] ?? array() ) as $entry ) {
			$operations[] = self::ok( self::entry( (array) $entry ), $assoc );
		}
		if ( ! $operations ) {
			self::fail( self::invalid( __( 'The file holds no operations.', 'leitvia-studio' ) ), $assoc );
		}
		$conflicts = self::conflicts( $operations );
		if ( $conflicts ) {
			self::fail(
				Rejection::make( 'leitvia_import_conflict', __( 'Targets changed on this site since the package was made. Nothing was imported; compare and export again.', 'leitvia-studio' ), 409, 'stale_hash', array(), '', 'never', array( 'conflicts' => $conflicts ) ),
				$assoc
			);
		}
		$key = substr( 'import-' . sanitize_title( (string) ( $data['title'] ?? '' ) ), 0, 48 ) . '-' . strtolower( wp_generate_password( 8, false ) );
		$id  = 0;
		foreach ( $operations as $entry ) {
			$media = (array) ( $entry['media'] ?? array() );
			unset( $entry['media'] );
			if ( $media ) {
				try {
					$bytes = sodium_base642bin( (string) ( $media['data'] ?? '' ), SODIUM_BASE64_VARIANT_ORIGINAL );
				} catch ( \SodiumException $e ) {
					$bytes = '';
				}
				$path = '' !== $bytes ? Packages::stash_file( (string) ( $media['name'] ?? 'media' ), $bytes ) : '';
				if ( '' === $path ) {
					self::fail( new \WP_Error( 'leitvia_cli_write', __( 'The media file could not be stored.', 'leitvia-studio' ) ), $assoc );
				}
				$entry['state']['file'] = $path;
			}
			$id = self::ok( Packages::add( $key, $entry ), $assoc );
		}
		Store::audit(
			array(
				'ability'    => 'cli/package-import',
				'result'     => 'ok',
				'package_id' => $id,
			)
		);
		self::show(
			array(
				'package'    => $id,
				'operations' => count( $operations ),
			),
			$assoc
		);
	}

	/**
	 * Wp leitvia recipe list.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function recipe_list( array $args, array $assoc ): void {
		self::show( Recipes::all(), $assoc, array( 'name', 'title', 'owner', 'available', 'pages' ) );
	}

	/**
	 * Wp leitvia recipe apply <name>: leitvia/apply-recipe; exits with 1 when a step was rejected.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Options.
	 */
	public function recipe_apply( array $args, array $assoc ): void {
		$result = self::execute( 'leitvia/apply-recipe', array( 'recipe' => (string) ( $args[0] ?? '' ) ), $assoc );
		if ( 'table' === self::format( $assoc, 'table' ) ) {
			self::show( (array) $result['steps'], $assoc, array( 'step', 'ability', 'status', 'target', 'code' ) );
			/* translators: %d: change package ID */
			\WP_CLI::log( sprintf( __( 'Change package: %d', 'leitvia-studio' ), (int) $result['package'] ) );
		} else {
			self::show( $result, $assoc );
		}
		if ( in_array( 'rejected', array_column( (array) $result['steps'], 'status' ), true ) ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Runs an ability as the current user. A write with an empty base hash on an existing target repeats once with the
	 * hash the rejection reports, as the retry rule "once" allows: the operator acts on the current state.
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Input.
	 * @param array<string, mixed> $assoc Options.
	 * @param bool                 $stop  Whether a rejection ends the command.
	 * @return mixed Output, or with $stop false the rejection.
	 */
	private static function execute( string $name, array $input, array $assoc, bool $stop = true ) {
		self::user( $assoc );
		$ability = wp_has_ability( $name ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			self::fail( new \WP_Error( 'leitvia_cli_ability_missing', __( 'The ability is not registered.', 'leitvia-studio' ), array( 'ability' => $name ) ), $assoc );
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) && 'leitvia_stale_hash' === $result->get_error_code() && '' === ( $input['base_hash'] ?? null ) ) {
			$result = $ability->execute( array( 'base_hash' => (string) ( ( (array) $result->get_error_data() )['current_hash'] ?? '' ) ) + $input );
		}
		return $stop ? self::ok( $result, $assoc ) : $result;
	}

	/**
	 * Ends the command when nobody is signed in: writes and package commands never run as user 0.
	 *
	 * @param array<string, mixed> $assoc Options.
	 */
	private static function user( array $assoc ): void {
		if ( get_current_user_id() < 1 ) {
			self::fail( Rejection::make( 'leitvia_cli_no_user', __( 'This command acts as a user; run with --user=<agent or admin>.', 'leitvia-studio' ), 401, 'identity', array(), '', 'never' ), $assoc );
		}
	}

	/**
	 * Ends the command unless the package exists and is the user's own (users with manage_options: any package).
	 *
	 * @param int                  $id    Package ID.
	 * @param array<string, mixed> $assoc Options.
	 */
	private static function own( int $id, array $assoc ): void {
		if ( '' === Packages::state( $id ) ) {
			self::fail( Packages::unknown( $id ), $assoc );
		}
		if ( (int) get_post_field( 'post_author', $id ) !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			self::fail( Rejection::make( 'leitvia_forbidden', __( 'This change package belongs to someone else.', 'leitvia-studio' ), 403, 'capability', array( 'package' => $id ), 'leitvia/list-packages', 'never' ), $assoc );
		}
	}

	/**
	 * The value, or the end of the command for a WP_Error.
	 *
	 * @template T
	 * @param T|\WP_Error          $result Result.
	 * @param array<string, mixed> $assoc  Options.
	 * @return T
	 */
	private static function ok( $result, array $assoc ) {
		if ( is_wp_error( $result ) ) {
			self::fail( $result, $assoc );
		}
		return $result;
	}

	/**
	 * Prints a rejection and exits with 1: as JSON with code, message and data for --format=json, else as error line.
	 *
	 * @param \WP_Error            $error Rejection.
	 * @param array<string, mixed> $assoc Options.
	 */
	private static function fail( \WP_Error $error, array $assoc ): never {
		$data = $error->get_error_data();
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::line(
				(string) wp_json_encode(
					array(
						'code'    => $error->get_error_code(),
						'message' => $error->get_error_message(),
						'data'    => $data,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				)
			);
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::error( sprintf( '%s [%s]', $error->get_error_message(), $error->get_error_code() ) . ( $data ? ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : '' ) );
	}

	/**
	 * The output format; lists default to table, single objects to json.
	 *
	 * @param array<string, mixed> $assoc   Options.
	 * @param string               $fallback Default.
	 */
	private static function format( array $assoc, string $fallback ): string {
		$format = (string) ( $assoc['format'] ?? $fallback );
		if ( ! in_array( $format, array( 'table', 'json', 'yaml' ), true ) ) {
			\WP_CLI::error( __( 'Use --format=table, json or yaml.', 'leitvia-studio' ) );
		}
		return $format;
	}

	/**
	 * Prints a list with these fields, or a single value when no fields are given.
	 *
	 * @param mixed                $data   Data.
	 * @param array<string, mixed> $assoc  Options.
	 * @param string[]             $fields Columns of a list.
	 */
	private static function show( $data, array $assoc, array $fields = array() ): void {
		$format = self::format( $assoc, $fields ? 'table' : 'json' );
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$items = $fields ? (array) $data : array( (array) $data );
		\WP_CLI\Utils\format_items( $format, $items, $fields ? $fields : array_keys( (array) $data ) );
	}

	/**
	 * A JSON file as array, or the end of the command.
	 *
	 * @param string               $file  Path.
	 * @param array<string, mixed> $assoc Options.
	 * @return array<mixed>
	 */
	private static function read( string $file, array $assoc ): array {
		$json = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- File the operator names on the command line.
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			self::fail( new \WP_Error( 'leitvia_cli_read', __( 'The file is missing or holds no JSON object or list.', 'leitvia-studio' ), array( 'file' => $file ) ), $assoc );
		}
		return $data;
	}

	/**
	 * Every listed pattern, all pages.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function patterns(): array {
		$items = array();
		$page  = 1;
		do {
			$list  = Read_Design::list_patterns(
				array(
					'page'     => $page,
					'per_page' => Read_Design::MAX_PER_PAGE,
				)
			);
			$items = array_merge( $items, $list['items'] );
		} while ( $page++ < $list['pages'] );
		return $items;
	}

	/**
	 * A stored operation as it travels: base hash of the target before (a live write records it in its snapshot), a
	 * created post as new object again, and the file of new media embedded.
	 *
	 * @param array<string, mixed> $entry Stored operation.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function portable( array $entry ) {
		$item     = array_intersect_key( $entry, array_flip( array( 'id', 'ability', 'area', 'target', 'base_hash', 'kinds', 'state' ) ) );
		$snapshot = isset( $entry['applied'] ) && '' === $entry['base_hash'] ? Store::get_snapshot( (int) $entry['applied']['snapshot'] ) : null;
		if ( $snapshot && ! $snapshot['state'] ) {
			$item['target'] = array(
				'post'      => 0,
				'post_type' => (string) ( $item['state']['post_type'] ?? $item['target']['post_type'] ?? '' ),
			);
		} elseif ( $snapshot ) {
			$item['base_hash'] = (string) $snapshot['base_hash'];
		}
		$file = (string) ( $item['state']['file'] ?? '' );
		unset( $item['state']['file'] );
		$creates = ! isset( $item['target']['option'] ) && 0 === (int) ( $item['target']['post'] ?? 0 );
		if ( '' === $file && ! ( $creates && 'attachment' === ( $item['state']['post_type'] ?? '' ) ) ) {
			return $item;
		}
		$uploads = realpath( (string) wp_upload_dir( null, false )['basedir'] );
		$real    = '' !== $file ? realpath( $file ) : false;
		if ( ! $uploads || ! $real || ! str_starts_with( $real, trailingslashit( $uploads ) ) || ! is_file( $real ) ) {
			return Rejection::make( 'leitvia_media_missing', __( 'The file of a new image is not on this site any more. Add the image again with leitvia/add-media, then export.', 'leitvia-studio' ), 422, 'media', array( 'op' => (int) $entry['id'] ), 'leitvia/add-media', 'never' );
		}
		$item['media'] = array(
			'name' => wp_basename( $real ),
			'data' => sodium_bin2base64( (string) file_get_contents( $real ), SODIUM_BASE64_VARIANT_ORIGINAL ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- File below uploads.
		);
		return $item;
	}

	/**
	 * Manifest: each existing object the operations change, once, with the hash of its first operation.
	 *
	 * @param list<array<string, mixed>> $operations Portable operations.
	 * @return list<array{type: string, id: int|string, base_hash: string}>
	 */
	private static function objects( array $operations ): array {
		$objects = array();
		foreach ( $operations as $entry ) {
			$option = (string) ( $entry['target']['option'] ?? '' );
			$post   = (int) ( $entry['target']['post'] ?? 0 );
			$key    = '' !== $option ? 'option:' . $option : 'post:' . $post;
			if ( ( '' === $option && ! $post ) || isset( $objects[ $key ] ) ) {
				continue;
			}
			$objects[ $key ] = array(
				'type'      => '' !== $option ? 'option' : (string) ( $entry['state']['post_type'] ?? get_post_type( $post ) ),
				'id'        => '' !== $option ? $option : $post,
				'base_hash' => (string) $entry['base_hash'],
			);
		}
		return array_values( $objects );
	}

	/**
	 * Conflicts of an import: per existing target its first base hash against the current hash.
	 *
	 * @param list<array<string, mixed>> $operations Checked operations.
	 * @return list<array<string, mixed>>
	 */
	private static function conflicts( array $operations ): array {
		$conflicts = array();
		foreach ( self::objects( $operations ) as $object ) {
			$target  = 'option' === $object['type'] ? array( 'option' => (string) $object['id'] ) : array( 'post' => (int) $object['id'] );
			$current = Pipeline::hash( new Operation( '', '', $target, '', static fn( array $state ): array => $state ) );
			$type    = 'option' === $object['type'] || get_post_type( (int) $object['id'] ) === $object['type'];
			if ( ! $type || '' === $current || ! hash_equals( $object['base_hash'], $current ) ) {
				$conflicts[] = $object + array( 'current_hash' => $current );
			}
		}
		return $conflicts;
	}

	/**
	 * An imported operation reduced to what a package stores, with the autonomy level of this site, or a rejection.
	 * The target key delete belongs to rollbacks only and is refused; a file path never comes from the import.
	 *
	 * @param array<string, mixed> $entry Operation from the file.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function entry( array $entry ) {
		$target = (array) ( $entry['target'] ?? array() );
		$kinds  = array_values( array_intersect( (array) ( $entry['kinds'] ?? array() ), Gateway::KINDS ) );
		$valid  = preg_match( '#^leitvia/[a-z0-9-]+$#', (string) ( $entry['ability'] ?? '' ) )
			&& in_array( $entry['area'] ?? '', Gateway::AREAS, true )
			&& is_array( $entry['state'] ?? null )
			&& preg_match( '/^([0-9a-f]{16})?$/', (string) ( $entry['base_hash'] ?? '' ) )
			&& ! array_diff_key( $target, array_flip( array( 'option', 'post', 'post_type', 'block', 'token_set', 'replace' ) ) )
			&& ( isset( $target['option'] ) xor isset( $target['post'] ) );
		if ( ! $valid ) {
			return self::invalid( __( 'An operation is incomplete or names a target the gateway does not know.', 'leitvia-studio' ) );
		}
		unset( $entry['state']['file'] );
		$item = array(
			'ability'   => (string) $entry['ability'],
			'area'      => (string) $entry['area'],
			'target'    => $target,
			'base_hash' => (string) $entry['base_hash'],
			'level'     => \LeitviaStudio\Gateway\Autonomy::level( (string) $entry['area'], $kinds ),
			'kinds'     => $kinds,
			'state'     => $entry['state'],
			'findings'  => array(),
		);
		if ( isset( $entry['media'] ) ) {
			$item['media'] = (array) $entry['media'];
		}
		return $item;
	}

	/**
	 * Rejection leitvia_invalid_package.
	 *
	 * @param string $message Message.
	 */
	private static function invalid( string $message ): \WP_Error {
		return Rejection::make( 'leitvia_invalid_package', $message, 400, 'package', array(), '', 'never' );
	}
}
