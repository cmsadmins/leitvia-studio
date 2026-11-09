<?php
/**
 * The one path for every write of an agent.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Gateway;

use LeitviaStudio\Gateway\Abilities\Commerce;
use LeitviaStudio\Gateway\Abilities\Content;
use LeitviaStudio\Model\Model;
use LeitviaStudio\Model\Registrar;
use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Token_Sets;

defined( 'ABSPATH' ) || exit;

/**
 * Runs an operation through identity, scope, base hash, content safety, autonomy and checks, then writes it live, as
 * a draft or as a change package, with snapshot and audit entry. Every rejection comes from here with its data, see
 * Rejection.
 */
final class Pipeline {

	/**
	 * Post fields an operation reads and writes. comment_status takes open or closed only.
	 */
	public const POST_FIELDS = array( 'post_type', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'comment_status' );

	/**
	 * Further fields an operation may set on a new post only: the MIME type and file of an attachment, meta (for
	 * example the template or lab metadata), terms (the theme and area of a template part in tax_input, terms of a
	 * content type in terms), the date and the author. A failed create deletes the post, so nothing of them needs restoring.
	 */
	public const CREATE_FIELDS = array( 'post_mime_type', 'file', 'meta_input', 'tax_input', 'terms', 'post_date', 'post_author' );

	/**
	 * Meta keys per post type that belong to the state of an existing post, under meta_input: hashed, snapshotted,
	 * written and rolled back like the post fields. The DTCG document is the content of a token set.
	 */
	public const META = array(
		'attachment'     => array( '_wp_attachment_image_alt' ),
		'leitvia_tokens' => array( Token_Sets::META ),
	);

	/**
	 * Term fields of the state of a term; TERM_META adds meta keys per taxonomy (the image of a product category).
	 */
	public const TERM_FIELDS = array( 'name', 'description', 'parent' );

	public const TERM_META = array( 'product_cat' => array( 'thumbnail_id' ) );

	/**
	 * Options an operation may target: the organization data of the site, the reading settings, the pages a recipe
	 * connects and the options a recipe may turn off (see schemas/recipe.schema.json). Every option counts as
	 * organization data. The site settings of the content model are open as well, see model_setting(), and the options
	 * of add-ons, see addon_options().
	 */
	public const OPTIONS = array( 'blogname', 'blogdescription', 'site_logo', 'site_icon', 'show_on_front', 'page_on_front', 'page_for_posts', 'leitvia_studio_settings', 'default_pingback_flag', 'default_ping_status', 'default_comment_status', 'show_avatars', 'wp_page_for_privacy_policy', 'woocommerce_terms_page_id', 'woocommerce_feature_order_attribution_enabled', 'woocommerce_allow_tracking', 'woocommerce_show_marketplace_suggestions', 'woocommerce_feature_remote_logging_enabled' );

	/**
	 * Core options that stay closed to add-ons, see protected_option().
	 */
	private const PROTECTED_OPTIONS = array( 'siteurl', 'home', 'active_plugins', 'active_sitewide_plugins', 'recently_activated', 'uninstall_plugins', 'users_can_register', 'default_role', 'admin_email', 'new_admin_email', 'adminhash', 'template', 'stylesheet', 'current_theme', 'allowedthemes', 'cron', 'secret_key', 'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'upload_path', 'upload_url_path', 'uploads_use_yearmonth_folders', 'permalink_structure', 'rewrite_rules', 'db_version', 'initial_db_version', 'wp_user_roles', 'mailserver_login', 'mailserver_pass', 'mailserver_url', 'fileupload_url', 'site_admins', 'registration', 'dashboard_widget_options', 'wp_force_deactivated_plugins' );

	/**
	 * Option of the content model. Model::save() validates and stores it, and like the Model screen it needs
	 * manage_options; it is no organization data.
	 */
	public const MODEL_OPTION = Model::OPTION;

	/**
	 * Post types whose content is theme.json data, not block markup: the block checks do not apply, the content must
	 * be a JSON object and holds no custom CSS unless the user may edit CSS.
	 */
	public const JSON_TYPES = array( 'wp_global_styles' );

	/**
	 * Characters of markup in the place of a rejection, see unsafe().
	 */
	private const EXCERPT_LENGTH = 80;

	/**
	 * Statuses visitors see.
	 */
	private const PUBLIC_STATUSES = array( 'publish', 'future' );

	/**
	 * Post types without a useful draft: core renders only published template parts and navigation menus, a draft
	 * template would replace the one of the theme all the same, and no ability publishes a draft. Under the level draft
	 * a new one waits in a package instead.
	 */
	private const NO_DRAFT = array( 'wp_template', 'wp_template_part', 'wp_navigation' );

	/**
	 * Runs an operation and writes its audit entry. Fires leitvia_studio_gateway_executed after a write or a package.
	 *
	 * A replay (package > 0) writes a stored operation of that package live: no rate limit (the call that applies the
	 * package counted) and no autonomy lock (whoever applies decided), except that an agent never replays a target of
	 * Autonomy::person_decides(); checks and capabilities apply as always. A
	 * rollback replay writes a snapshot back: content safety and checks are skipped because the state existed before,
	 * and the target key delete removes a post the package created.
	 *
	 * @param Operation $op       Operation.
	 * @param int       $package  Change package the operation is replayed from, 0 for a new operation.
	 * @param bool      $rollback Whether the replay restores a snapshot.
	 * @return array<string, mixed>|\WP_Error Result with mode (live, draft, package, unchanged), level, kinds, target, hash,
	 *                                        package, snapshot and the warnings in findings; or a rejection.
	 */
	public static function run( Operation $op, int $package = 0, bool $rollback = false ) {
		// The check after the write counts only contrast failures this write brings.
		Verify::remember();
		$result = self::process( $op, $package, $rollback );
		$error  = is_wp_error( $result );
		$code   = $error ? (string) $result->get_error_code() : '';
		Store::audit(
			array(
				'ability'     => $op->ability,
				'result'      => 'leitvia_execution_failed' === $code ? 'failed' : ( $error ? 'rejected' : 'ok' ),
				'code'        => $code,
				'object_type' => match ( true ) {
					'' !== $op->option() => 'option',
					$op->term() > 0      => 'term',
					default              => (string) ( get_post_type( $op->post_id() ) ? get_post_type( $op->post_id() ) : ( $op->target['post_type'] ?? '' ) ),
				},
				'object_id'   => $error ? max( $op->post_id(), $op->term() ) : (int) ( $result['target']['post'] ?? $result['target']['term'] ?? 0 ),
				'package_id'  => $error ? 0 : (int) $result['package'],
				'details'     => ( $error ? array() : array_intersect_key( $result, array_flip( array( 'mode', 'level', 'kinds', 'snapshot' ) ) ) + array_intersect_key( $op->target, array( 'option' => true ) ) ) + ( Abilities::batching() ? array( 'batched' => true ) : array() ),
			)
		);
		if ( ! $error ) {
			/**
			 * Fires after an operation was written live, as a draft or as a change package.
			 *
			 * @param Operation            $op     Operation.
			 * @param array<string, mixed> $result Result of Pipeline::run().
			 */
			do_action( 'leitvia_studio_gateway_executed', $op, $result );
		}
		return $result;
	}

	/**
	 * Hash of the current state of the target, '' when there is none.
	 *
	 * @param Operation $op      Operation.
	 * @param int|null  $post_id Post to hash instead of the target, for a post the operation created.
	 */
	public static function hash( Operation $op, ?int $post_id = null ): string {
		if ( '' !== $op->option() ) {
			return Hashes::of( (string) wp_json_encode( get_option( $op->option() ) ) );
		}
		if ( isset( $op->target['term'] ) ) {
			return Hashes::term( $op->term() );
		}
		return Hashes::document( $post_id ?? $op->post_id() );
	}

	/**
	 * Whether a stored hash matches the current state of the target, see hash(); for a post a hash in the format before
	 * the template joined the state matches as well, unless the operation writes another template (Hashes::matches()).
	 *
	 * @param Operation   $op       Operation.
	 * @param string      $stored   Stored hash.
	 * @param string|null $template Template the operation writes, null when it writes none.
	 */
	public static function matches( Operation $op, string $stored, ?string $template = null ): bool {
		if ( '' !== $op->option() || isset( $op->target['term'] ) ) {
			$current = self::hash( $op );
			return '' !== $current && hash_equals( $current, $stored );
		}
		return Hashes::matches( $stored, $op->post_id(), $template );
	}

	/**
	 * Steps 1 to 3 of the pipeline, then decide().
	 *
	 * @param Operation $op       Operation.
	 * @param int       $package  Package of a replay, 0 for none.
	 * @param bool      $rollback Whether the replay restores a snapshot.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function process( Operation $op, int $package, bool $rollback ) {
		$target = self::target( $op );
		// 1. Identity: a real user with the capability, never user 0; rate limit.
		if ( ! Abilities::can_use() ) {
			return Rejection::make( 'leitvia_forbidden', __( 'Sign in as a user who may edit content.', 'leitvia-studio' ), 403, 'identity', $target, '', 'never' );
		}
		$limited = $package ? null : Abilities::rate_limit( get_current_user_id() );
		if ( $limited ) {
			return $limited;
		}
		// 2. Scope and the capability for this target.
		$before = self::before( $op, $package );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		// 3. Base hash.
		$current = $op->creates() ? '' : self::hash( $op );
		$stale   = static fn(): \WP_Error => Rejection::make(
			'leitvia_stale_hash',
			__( 'The target changed since it was read. Read it again and repeat the change on the current state.', 'leitvia-studio' ),
			409,
			'stale_hash',
			$target,
			'' !== $op->option() ? '' : 'leitvia/get-page-outline',
			'once',
			array( 'current_hash' => $current )
		);
		if ( ! $op->creates() && ! self::matches( $op, $op->base_hash ) ) {
			return $stale();
		}
		// A base hash of the format before Studio 1.3.31 cannot see the template, see Hashes::matches().
		$legacy = ! $op->creates() && ! hash_equals( $current, $op->base_hash );
		// An earlier operation of the same package on this target: the change builds on the state it leaves.
		$follows = $package ? array() : Packages::follows( $op );
		$before  = $follows ? (array) $follows['state'] : $before;
		$after   = self::after( $op, $before );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		if ( $legacy && isset( $after['meta_input']['_wp_page_template'] ) && ! self::matches( $op, $op->base_hash, (string) $after['meta_input']['_wp_page_template'] ) ) {
			return $stale();
		}
		$result = self::decide( $op, $before, $after, $package, $rollback, (int) ( $follows['id'] ?? 0 ) );
		// A file the change put in the private area belongs to nobody after a rejection; a stored one stays for a retry.
		if ( is_wp_error( $result ) && ! $package ) {
			Packages::discard_file( $after );
		}
		return $result;
	}

	/**
	 * Steps 4 to 8 of the pipeline.
	 *
	 * @param Operation            $op       Operation.
	 * @param array<string, mixed> $before   State before.
	 * @param array<string, mixed> $after    State after.
	 * @param int                  $package  Package of a replay, 0 for none.
	 * @param bool                 $rollback Whether the replay restores a snapshot.
	 * @param int                  $follows  ID of the operation in the open package whose state is the state before, 0
	 *                                       for none; the write then always goes into that package.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function decide( Operation $op, array $before, array $after, int $package, bool $rollback, int $follows = 0 ) {
		$target = self::target( $op );
		// 4. Content safety. Harmless rests of word processors are dropped first, see office(), but only from a text
		// this write changes: a change of meta, title or status leaves the stored content as it is. Unsafe content is
		// rejected with the first finding; safe content is written through the KSES of core, see execute().
		if ( ! $rollback && ! self::json( $after ) ) {
			foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
				if ( isset( $after[ $field ] ) && is_string( $after[ $field ] ) && ( $before[ $field ] ?? null ) !== $after[ $field ] ) {
					$after[ $field ] = self::office( $after[ $field ] );
				}
			}
			// Block markup agents often get wrong is repaired the same way, see Normalize; a package shows the repair.
			if ( isset( $after['post_content'] ) && is_string( $after['post_content'] ) && ( $before['post_content'] ?? null ) !== $after['post_content'] && ! isset( $after['product'] ) ) {
				$after['post_content'] = Normalize::markup( $after['post_content'], is_string( $before['post_content'] ?? null ) ? $before['post_content'] : '' );
			}
			if ( isset( $after['term']['description'] ) && is_string( $after['term']['description'] ) && ( $before['term']['description'] ?? null ) !== $after['term']['description'] ) {
				$after['term']['description'] = self::office( $after['term']['description'] );
			}
		}
		$unsafe = match ( true ) {
			$rollback                  => null,
			self::json( $after )       => self::unsafe_json( (string) $after['post_content'] ),
			// Term descriptions and product texts are classic HTML; the markup checks apply all the same.
			isset( $after['term'] )    => self::unsafe( (string) ( $after['term']['description'] ?? '' ), true ),
			isset( $after['product'] ) => self::unsafe( (string) $after['post_content'], true ) ?? self::unsafe( (string) $after['post_excerpt'], true ),
			// Content that replaces classic content whole keeps no classic block (target replace, see update-content).
			// Only blocks the write changes count: a block stored as it is (a block of a plugin that is gone, with markup
			// KSES would cut) stays byte for byte, see stored_unsafe().
			default                    => self::unsafe( (string) ( $after['post_content'] ?? '' ), self::classic( $before ) && empty( $op->target['replace'] ), (string) ( $before['post_content'] ?? '' ) ),
		};
		if ( $unsafe ) {
			return Rejection::make(
				'leitvia_unsafe_content',
				__( 'The content holds markup agents may not write: scripts, event attributes, Custom HTML blocks, classic blocks or Base64 data. Use registered blocks and media from the library.', 'leitvia-studio' ) . self::where( $unsafe ),
				422,
				$unsafe['reason'],
				array( 'block' => $unsafe['block'] ) + $target,
				'leitvia/list-blocks',
				'once',
				array( 'found' => $unsafe )
			);
		}
		// The check of an add-on option, see addon_options().
		$check = $rollback || '' === $op->option() ? null : ( self::addon_options()[ $op->option() ] ?? null );
		try {
			$valid = $check ? $check( $after['value'] ?? null ) : true;
		} catch ( \Throwable $e ) {
			unset( $e );
			$valid = false;
		}
		if ( true !== $valid ) {
			return Rejection::make( 'leitvia_invalid_input', is_wp_error( $valid ) ? $valid->get_error_message() : __( 'The value is not valid for this option.', 'leitvia-studio' ), 422, 'option', $target, '', 'never' );
		}
		// 5. Autonomy.
		$kinds = Autonomy::kinds( $op, $before, $after );
		$level = Autonomy::level( $op->area, $kinds, $op->target );
		if ( 'locked' === $level && ! $package ) {
			return Rejection::make( 'leitvia_locked', __( 'Agents may not change this area. A person can change the autonomy settings.', 'leitvia-studio' ), 403, 'autonomy', $target, '', 'never', array( 'kinds' => $kinds ) );
		}
		// Settings, the content model and the global styles reach the site only when a person applies them; an agent
		// never replays such an operation, whatever path it takes (apply-package, rollback).
		if ( $package && Gateway::is_agent() && Autonomy::person_decides( $op->target ) ) {
			return Rejection::make( 'leitvia_needs_human', __( 'A person has to decide on these operations in the inbox. Apply only operations whose area is autonomous for you.', 'leitvia-studio' ), 403, 'autonomy', $target, '', 'never', array( 'kinds' => $kinds ) );
		}
		// A change that leaves the target as it is (a recipe or task run again) writes nothing and queues nothing.
		if ( ! $package && ! $op->creates() && self::same( $before, $after ) ) {
			return array(
				'mode'     => $follows ? 'package' : 'unchanged',
				'level'    => $level,
				'kinds'    => $kinds,
				'target'   => $target,
				'hash'     => self::hash( $op ),
				'package'  => $follows ? Packages::find( $op->package ) : 0,
				'snapshot' => 0,
				'findings' => array(),
			);
		}
		// 6. Checks, counting only what this write brings: findings the target had before are not its business.
		$findings = $rollback ? array() : self::check( $op, $after );
		$findings = $findings && ! $op->creates() ? self::introduced( $findings, self::check( $op, $before ) ) : $findings;
		$errors   = array_values( array_filter( $findings, static fn( array $finding ): bool => 'warning' !== ( $finding['severity'] ?? 'error' ) ) );
		if ( $errors ) {
			return Rejection::make( 'leitvia_check_failed', __( 'Checks found problems. Fix them as each finding suggests and try again.', 'leitvia-studio' ), 422, 'checks', $target, Content_Checks::NEXT_ABILITY[ $errors[0]['check'] ?? '' ] ?? '', 'once', array( 'findings' => $findings ) );
		}
		// 7. Execution.
		// A package key from the caller always means a package: an agent may hold itself to more than the autonomy asks.
		// A setting that points to a page waiting in the package (target page, see Recipes) can only wait as well.
		// So can content whose featured image waits in the package (target media, see Abilities\Content).
		$strict = '' !== $op->package && ( ! Recipes::collects( $op->package ) || isset( $op->target['page'] ) || isset( $op->target['media'] ) );
		$mode   = match ( true ) {
			$package > 0            => 'live',
			$follows > 0 || $strict => 'package',
			default                 => self::mode( $op, $level, $before, $after, (bool) $findings ),
		};
		// The warnings of a token set come after the mode: they are reported but hold nothing back, since the set has to
		// exist before a person uploads its fonts and activates it again.
		$findings = $rollback ? $findings : array_merge( $findings, self::token_warnings( $op, $after ) );
		$result   = array(
			'mode'     => $mode,
			'level'    => $level,
			'kinds'    => $kinds,
			'target'   => $target,
			'hash'     => '',
			'package'  => $package,
			'snapshot' => 0,
			'findings' => $findings,
		) + self::slug_change( $op, $after );
		if ( 'package' === $mode ) {
			$queued = self::queued( $op, $after );
			if ( $queued ) {
				$result['package'] = $queued;
				return $result;
			}
			$package = Packages::add(
				$op->package,
				array(
					'ability'   => $op->ability,
					'area'      => $op->area,
					'target'    => $op->target,
					'base_hash' => $op->base_hash,
					'level'     => $level,
					'kinds'     => $kinds,
					'state'     => $after,
					'findings'  => $findings,
				) + ( $follows ? array( 'follows' => $follows ) : array() )
			);
			if ( is_wp_error( $package ) ) {
				return self::failed( $target );
			}
			$result['package'] = $package;
			return $result;
		}
		if ( 'draft' === $mode && $op->creates() && in_array( $after['post_status'], self::PUBLIC_STATUSES, true ) ) {
			$after['post_status'] = 'draft';
		}
		$denied = self::publish_denied( $op, $before, $after );
		if ( $denied ) {
			return $denied;
		}
		// 8. Snapshot, write; on any failure the previous state comes back.
		return self::execute( $op, $before, $after, $result, $rollback && ! empty( $op->target['delete'] ), $rollback || self::stored_unsafe( $op, $after ) );
	}

	/**
	 * The field slug_changed with requested and slug when the post cannot have the slug of the state because another post uses it
	 * or core reserves it: slug is the address it gets, or gets on publishing, since core keeps duplicates in drafts.
	 * Empty otherwise, also for what has no address.
	 *
	 * @param Operation            $op    Operation.
	 * @param array<string, mixed> $after State after.
	 * @return array<string, array{requested: string, slug: string}>
	 */
	private static function slug_change( Operation $op, array $after ): array {
		$wanted = (string) ( $after['post_name'] ?? '' );
		$type   = (string) ( $after['post_type'] ?? '' );
		if ( '' === $wanted || 'attachment' === $type || ! is_post_type_viewable( $type ) ) {
			return array();
		}
		$slug = wp_unique_post_slug( $wanted, $op->post_id(), 'publish', $type, (int) ( $after['post_parent'] ?? 0 ) );
		return $slug === $wanted ? array() : array(
			'slug_changed' => array(
				'requested' => $wanted,
				'slug'      => $slug,
			),
		);
	}

	/**
	 * Findings of the state after that the state before did not have. The same check, severity, block and message count
	 * as the same finding, as often as the state before had it.
	 * ponytail: a finding whose block got another path ID (a block inserted above it without leitviaId) or whose message
	 * names a changed number counts as new and fails the write as before; match on more than the message if that bites.
	 *
	 * @param list<array<string, mixed>> $after  Findings of the state after.
	 * @param list<array<string, mixed>> $before Findings of the state before.
	 * @return list<array<string, mixed>>
	 */
	private static function introduced( array $after, array $before ): array {
		$key  = static fn( array $finding ): string => (string) wp_json_encode( array( $finding['check'] ?? '', $finding['severity'] ?? 'error', $finding['block'] ?? '', $finding['message'] ?? '' ) );
		$seen = array_count_values( array_map( $key, $before ) );
		$new  = array();
		foreach ( $after as $finding ) {
			$id = $key( $finding );
			if ( empty( $seen[ $id ] ) ) {
				$new[] = $finding;
				continue;
			}
			--$seen[ $id ];
		}
		return $new;
	}

	/**
	 * Target for rejections and results.
	 *
	 * @param Operation $op Operation.
	 * @return array<string, mixed>
	 */
	private static function target( Operation $op ): array {
		if ( '' !== $op->option() ) {
			return array( 'option' => $op->option() );
		}
		if ( isset( $op->target['term'] ) ) {
			return array( 'term' => $op->term() );
		}
		return array( 'post' => $op->post_id() ) + array_intersect_key( $op->target, array( 'block' => true ) );
	}

	/**
	 * Current state of the target after the scope and capability checks: post fields, array( 'value' => … ) for an
	 * option, an empty array for a new post. A post the user may not edit (core asks manage_privacy_options for the
	 * privacy policy page) is open to a proposal: a write with a package key, outside a replay and a settled recipe run,
	 * only lands in the package, and the person who applies it needs the capability then; edit_posts of the post type
	 * is enough to propose.
	 *
	 * @param Operation $op      Operation.
	 * @param int       $package Package of a replay, 0 for none.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function before( Operation $op, int $package = 0 ) {
		$target    = self::target( $op );
		$out       = Rejection::make( 'leitvia_out_of_scope', __( 'Agents may not change this object. Only published or draft content of the allowed post types is open to them.', 'leitvia-studio' ), 403, 'scope', $target, '', 'never' );
		$forbidden = Rejection::make( 'leitvia_forbidden', __( 'Your user may not change this object.', 'leitvia-studio' ), 403, 'capability', $target, '', 'never' );
		if ( '' !== $op->option() ) {
			return in_array( $op->option(), self::OPTIONS, true ) || self::MODEL_OPTION === $op->option() || self::model_setting( $op->option() ) || isset( self::addon_options()[ $op->option() ] ) ? array( 'value' => get_option( $op->option() ) ) : $out;
		}
		if ( isset( $op->target['term'] ) ) {
			if ( ! Scope::term_readable( $op->term() ) ) {
				return $out;
			}
			return current_user_can( 'edit_term', $op->term() ) ? self::term_state( $op->term() ) : $forbidden;
		}
		if ( $op->creates() ) {
			$type   = (string) ( $op->target['post_type'] ?? '' );
			$object = get_post_type_object( $type );
			if ( ! $object || ! in_array( $type, Scope::post_types(), true ) ) {
				return $out;
			}
			return current_user_can( $object->cap->create_posts ) ? array() : $forbidden;
		}
		if ( ! Scope::readable( $op->post_id() ) ) {
			return $out;
		}
		if ( ! current_user_can( 'edit_post', $op->post_id() ) ) {
			$object   = get_post_type_object( (string) get_post_type( $op->post_id() ) );
			$proposal = ! $package && '' !== $op->package && ! Recipes::collects( $op->package ) && $object && current_user_can( $object->cap->edit_posts );
			if ( ! $proposal ) {
				return $forbidden;
			}
		}
		return self::state( $op->post_id() );
	}

	/**
	 * Options of add-ons that their own abilities write through the pipeline, by name the check of a new value: a
	 * callable that gets the value and returns true or a WP_Error. The check runs on every write but a rollback, also
	 * when a package is applied, so a value that waited in a package passes it again. The options of OPTIONS and of the
	 * content model keep their own rules and are left out. Such a write is a settings change: agents propose it, a
	 * person applies it (Autonomy::person_decides()). A check that throws counts as a refusal. Core options that
	 * matter for security never open, see protected_option(). An add-on creates its option before the first write, with autoload
	 * as it wants it; update_option() keeps that.
	 *
	 * @return array<string, callable>
	 */
	public static function addon_options(): array {
		/**
		 * Filters the options of add-ons the pipeline writes.
		 *
		 * @param array<mixed> $options Check of a new value by option name.
		 */
		$options = (array) apply_filters( 'leitvia_studio_gateway_options', array() );
		return array_filter(
			$options,
			static fn( $check, $name ): bool => is_string( $name ) && is_callable( $check ) && ! in_array( $name, self::OPTIONS, true ) && self::MODEL_OPTION !== $name && ! self::model_setting( $name ) && ! self::protected_option( $name ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Whether an option is one that no filter opens to the pipeline: addresses, plugins, theme, users and roles, keys
	 * and salts, cron, uploads, the gateway settings and transients, and the auto_update_ options of plugins, themes
	 * and core (as a prefix). An add-on that names one gets nothing.
	 *
	 * @param string $option Option name.
	 */
	public static function protected_option( string $option ): bool {
		global $wpdb;
		return in_array( strtolower( $option ), array_map( 'strtolower', array_merge( self::PROTECTED_OPTIONS, array( $wpdb->prefix . 'user_roles', $wpdb->get_blog_prefix() . 'user_roles', Gateway::OPTION ) ) ), true ) || (bool) preg_match( '/^_(site_)?transient_|^auto_update_|_(key|salt)$/', strtolower( $option ) );
	}

	/**
	 * Whether an option is a site setting of the current content model (leitvia_site_<key>), which a building block may
	 * fill with its default value; it is organization data like the options of OPTIONS.
	 *
	 * @param string $option Option name.
	 */
	public static function model_setting( string $option ): bool {
		$key = str_starts_with( $option, Registrar::SETTING_PREFIX ) ? substr( $option, strlen( Registrar::SETTING_PREFIX ) ) : '';
		return '' !== $key && isset( Model::current()['settings'][ $key ] );
	}

	/**
	 * State of an existing post: the post fields and, for post types in META, those meta keys under meta_input; an
	 * empty array when the post does not exist.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function state( int $post_id ): array {
		$post = $post_id ? get_post( $post_id, ARRAY_A ) : null;
		if ( ! $post ) {
			return array();
		}
		$state = array_intersect_key( $post, array_flip( self::POST_FIELDS ) );
		foreach ( self::META[ $post['post_type'] ] ?? array() as $key ) {
			$state['meta_input'][ $key ] = (string) get_post_meta( $post_id, $key, true );
		}
		if ( Commerce::handles( (string) $post['post_type'] ) ) {
			$state['product'] = Commerce::props( $post_id );
		}
		return $state + Content::state( $post_id, (string) $post['post_type'] );
	}

	/**
	 * State of a term: array( 'term' => … ) with TERM_FIELDS and the TERM_META of its taxonomy; an empty array when the
	 * term does not exist.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>
	 */
	public static function term_state( int $term_id ): array {
		$term = $term_id ? get_term( $term_id ) : null;
		if ( ! $term instanceof \WP_Term ) {
			return array();
		}
		$state = array(
			'name'        => $term->name,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
		);
		foreach ( self::TERM_META[ $term->taxonomy ] ?? array() as $key ) {
			$state[ $key ] = (string) get_term_meta( $term_id, $key, true );
		}
		return array( 'term' => $state );
	}

	/**
	 * State after the change, limited to the known fields; the post type never changes and the status stays in scope.
	 *
	 * @param Operation            $op     Operation.
	 * @param array<string, mixed> $before State before.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function after( Operation $op, array $before ) {
		$start = $before;
		if ( $op->creates() ) {
			$start = array(
				'post_type'      => (string) $op->target['post_type'],
				'post_title'     => '',
				'post_content'   => '',
				'post_excerpt'   => '',
				'post_status'    => 'publish',
				'post_name'      => '',
				'post_parent'    => 0,
				'menu_order'     => 0,
				'comment_status' => get_default_comment_status( (string) $op->target['post_type'] ),
			);
			// A new product brings the props of create-product, see Commerce::CREATE_PROPS.
			if ( 'product' === $op->target['post_type'] && Commerce::handles( 'product' ) ) {
				$start['product'] = Commerce::defaults();
			}
		}
		$after = ( $op->change )( $start );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		$after = array_intersect_key( (array) $after, $start + ( $op->creates() ? array_flip( self::CREATE_FIELDS ) : array() ) ) + $start;
		if ( ! $op->creates() && isset( $start['meta_input'] ) ) {
			// Only the meta keys of META; an existing post takes no other meta through the pipeline.
			$after['meta_input'] = array_intersect_key( (array) $after['meta_input'], $start['meta_input'] );
		}
		foreach ( array( 'product', 'term', 'terms' ) as $key ) {
			// Only the props, term fields and taxonomies the state knows.
			if ( isset( $start[ $key ] ) ) {
				$after[ $key ] = array_intersect_key( (array) $after[ $key ], $start[ $key ] ) + $start[ $key ];
			}
		}
		if ( '' !== $op->option() || isset( $op->target['term'] ) ) {
			return $after;
		}
		$after['post_type'] = $start['post_type'];
		$statuses           = Scope::statuses( (string) $after['post_type'] );
		if ( ! in_array( $after['post_status'], $statuses, true ) ) {
			return Rejection::make( 'leitvia_out_of_scope', __( 'Agents may not set this status. Use draft, pending or publish.', 'leitvia-studio' ), 403, 'status', self::target( $op ), '', 'once' );
		}
		$comments = $after['comment_status'] ?? null;
		if ( ( $before['comment_status'] ?? null ) !== $comments && ! in_array( $comments, array( 'open', 'closed' ), true ) ) {
			return Block_Edit::invalid( 'comment_status', __( 'Use open or closed for comment_status.', 'leitvia-studio' ), self::target( $op ) );
		}
		return $after;
	}

	/**
	 * Whether the current content is classic content, the only place a classic block is allowed.
	 *
	 * @param array<string, mixed> $before State before.
	 */
	private static function classic( array $before ): bool {
		$content = (string) ( $before['post_content'] ?? '' );
		return '' !== trim( $content ) && ! has_blocks( $content );
	}

	/**
	 * The first block agents may not write, with its ID (metadata.leitviaId or path p:0.2.1), the reason and the place:
	 * tag and attribute ('' when the finding has none; a block attribute as dotted key such as metadata.name) and a
	 * short excerpt; null when the content is safe. Unsafe: core/html, a classic block outside classic content, Base64
	 * data, and any markup or attribute text that wp_kses_post() would change beyond entity and self-closing-tag spelling.
	 *
	 * A block whose own markup and attributes the content before holds as they are is not checked again (its inner
	 * blocks are): what is stored was written by a person or passed before, and a write elsewhere must not fail on it.
	 * A copy of such a block in the same post passes as well, on purpose: it brings no markup the post did not hold.
	 *
	 * @param string $content Block markup.
	 * @param bool   $classic Whether the current content is classic.
	 * @param string $before  Content before the write, '' for none.
	 * @return array{block: string, reason: string, tag: string, attribute: string, excerpt: string}|null
	 */
	public static function unsafe( string $content, bool $classic = false, string $before = '' ): ?array {
		$known = array();
		foreach ( '' !== $before && has_blocks( $before ) ? self::own_parts( parse_blocks( $before ) ) : array() as $part ) {
			$known[ $part ] = true;
		}
		return self::unsafe_blocks( parse_blocks( $content ), 'p:', $classic, $known );
	}

	/**
	 * Keys of the own markup and attributes of every block, inner blocks included.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @return list<string>
	 */
	public static function own_parts( array $blocks ): array {
		$parts = array();
		foreach ( $blocks as $block ) {
			$parts[] = self::own_part( $block );
			array_push( $parts, ...self::own_parts( (array) $block['innerBlocks'] ) );
		}
		return $parts;
	}

	/**
	 * Key of the own markup and attributes of a block. innerContent goes in whole, the places of the inner blocks (null)
	 * included: a stored block whose markup holds a separator must not match a new block that splits that markup around
	 * an inner block (<a href="java|script:..."> against <a href="java<!-- wp:x /-->script:...">).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	public static function own_part( array $block ): string {
		return md5( (string) wp_json_encode( array( (string) $block['blockName'], array_values( (array) $block['innerContent'] ), $block['attrs'] ) ) );
	}

	/**
	 * Whether the content after the write keeps stored blocks that KSES would change (see unsafe()): then the write
	 * passes by the KSES filter of post content, so they stay byte for byte. unsafe() found every block the write
	 * changes safe, that is kept by KSES.
	 *
	 * @param Operation            $op    Operation.
	 * @param array<string, mixed> $after State after.
	 */
	private static function stored_unsafe( Operation $op, array $after ): bool {
		if ( '' !== $op->option() || isset( $op->target['term'] ) || isset( $after['product'] ) || self::json( $after ) || ! is_string( $after['post_content'] ?? null ) ) {
			return false;
		}
		$content = $after['post_content'];
		return has_blocks( $content ) && null !== self::unsafe_blocks( parse_blocks( $content ), 'p:', false );
	}

	/**
	 * Recursive part of unsafe().
	 *
	 * @param array<int|string, array<string, mixed>> $blocks  Parsed blocks.
	 * @param string                                  $path    Path prefix.
	 * @param bool                                    $classic Whether classic blocks are allowed.
	 * @param array<string, true>                     $known   Keys of own_part() of stored blocks, not checked.
	 * @return array{block: string, reason: string, tag: string, attribute: string, excerpt: string}|null
	 */
	private static function unsafe_blocks( array $blocks, string $path, bool $classic, array $known = array() ): ?array {
		foreach ( Block_Ids::blocks( $blocks ) as $index => $block ) {
			$name   = (string) ( $block['blockName'] ?? '' );
			$html   = implode( '', array_filter( (array) $block['innerContent'], 'is_string' ) );
			$id     = Block_Ids::id( $block );
			$id     = '' !== $id ? $id : $path . $index;
			$reason = match ( true ) {
				isset( $known[ self::own_part( $block ) ] ) => '',
				'core/html' === $name => 'html_block',
				( '' === $name || 'core/freeform' === $name ) && ! $classic && '' !== trim( $html ) => 'classic_block',
				(bool) preg_match( '/data:[^,"\'\s]*;base64/i', $html . wp_json_encode( $block['attrs'] ) ) => 'base64',
				! self::kses_safe( $html ) || ! self::html_urls_safe( $html ) || ! self::attributes_safe( (array) $block['attrs'] ) => 'markup',
				default => '',
			};
			if ( '' !== $reason ) {
				$place = in_array( $reason, array( 'markup', 'base64' ), true ) ? self::locate( $html, (array) $block['attrs'] ) : array();
				return array(
					'block'     => $id,
					'reason'    => $reason,
					'tag'       => $place['tag'] ?? '',
					'attribute' => $place['attribute'] ?? '',
					'excerpt'   => $place['excerpt'] ?? self::excerpt( trim( $html ) ),
				);
			}
			$inner = self::unsafe_blocks( (array) $block['innerBlocks'], $path . $index . '.', false, $known );
			if ( $inner ) {
				return $inner;
			}
		}
		return null;
	}

	/**
	 * Tag, attribute and excerpt of the first unsafe place in a block: a tag wp_kses_post() does not allow, an attribute
	 * it would change or drop, a script URL or Base64 data, in the markup first, then in the block attributes. Without
	 * such a place (text that KSES changes) only the excerpt of the markup.
	 *
	 * @param string       $html  Own markup of the block.
	 * @param array<mixed> $attrs Block attributes.
	 * @return array{tag?: string, attribute?: string, excerpt: string}
	 */
	private static function locate( string $html, array $attrs ): array {
		$allowed = wp_kses_allowed_html( 'post' );
		$tags    = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$tag   = strtolower( (string) $tags->get_tag() );
			$names = (array) $tags->get_attribute_names_with_prefix( '' );
			$parts = array();
			foreach ( $names as $name ) {
				$value   = $tags->get_attribute( $name );
				$parts[] = is_string( $value ) ? $name . '="' . $value . '"' : $name;
			}
			$opening = self::excerpt( '<' . $tag . ( $parts ? ' ' . implode( ' ', $parts ) : '' ) . '>' );
			if ( ! isset( $allowed[ $tag ] ) ) {
				return array(
					'tag'       => $tag,
					'attribute' => '',
					'excerpt'   => $opening,
				);
			}
			foreach ( $names as $name ) {
				$value = $tags->get_attribute( $name );
				$text  = is_string( $value ) ? $value : '';
				if ( self::bad_value( $text ) || ! self::kses_safe( '<' . $tag . ' ' . $name . ( is_string( $value ) ? '="' . esc_attr( $value ) . '"' : '' ) . '>' ) ) {
					return array(
						'tag'       => $tag,
						'attribute' => $name,
						'excerpt'   => $opening,
					);
				}
			}
		}
		return self::locate_attrs( $attrs, '' ) ?? array( 'excerpt' => self::excerpt( trim( $html ) ) );
	}

	/**
	 * The first string of block attributes, by dotted key, that holds a script URL or Base64 data or that
	 * wp_kses_post() would change; null for none.
	 *
	 * @param array<mixed> $values Attributes.
	 * @param string       $prefix Key prefix.
	 * @return array{tag: string, attribute: string, excerpt: string}|null
	 */
	private static function locate_attrs( array $values, string $prefix ): ?array {
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) ) {
				$found = self::locate_attrs( $value, $prefix . $key . '.' );
				if ( $found ) {
					return $found;
				}
			} elseif ( is_string( $value ) && ( self::bad_value( $value ) || ! self::kses_safe( $value ) ) ) {
				return array(
					'tag'       => '',
					'attribute' => $prefix . $key,
					'excerpt'   => self::excerpt( $value ),
				);
			}
		}
		return null;
	}

	/**
	 * Whether a value holds Base64 data or is a script URL.
	 *
	 * @param string $value Value.
	 */
	private static function bad_value( string $value ): bool {
		return (bool) preg_match( '/data:[^,"\'\s]*;base64/i', $value ) || self::script_url( $value );
	}

	/**
	 * The first EXCERPT_LENGTH characters of a text, with an ellipsis when cut.
	 *
	 * @param string $text Text.
	 */
	private static function excerpt( string $text ): string {
		return mb_strlen( $text ) > self::EXCERPT_LENGTH ? mb_substr( $text, 0, self::EXCERPT_LENGTH ) . '…' : $text;
	}

	/**
	 * The place of a finding of unsafe(), as a sentence for the rejection with a space before it; '' for a finding of
	 * unsafe_json(), which has no place.
	 *
	 * @param array{block: string, reason: string, tag?: string, attribute?: string, excerpt?: string} $found Finding.
	 */
	private static function where( array $found ): string {
		if ( ! isset( $found['excerpt'] ) ) {
			return '';
		}
		$place = trim( ( '' !== ( $found['tag'] ?? '' ) ? '<' . $found['tag'] . '>' : '' ) . ' ' . ( $found['attribute'] ?? '' ) );
		return ' ' . ( '' !== $place
			/* translators: 1: block ID, 2: tag and attribute such as <b> style, 3: excerpt of the markup */
			? sprintf( __( 'First finding in block %1$s, %2$s: %3$s', 'leitvia-studio' ), $found['block'], $place, $found['excerpt'] )
			/* translators: 1: block ID, 2: excerpt of the markup */
			: sprintf( __( 'First finding in block %1$s: %2$s', 'leitvia-studio' ), $found['block'], $found['excerpt'] ) );
	}

	/**
	 * Content without the harmless rests a word processor leaves when text is pasted: mso-* declarations in style
	 * attributes (the attribute goes when nothing else is left) and empty <o:p> elements. Both would otherwise reject
	 * the whole write; an o:p with text stays and is rejected with its place.
	 * ponytail: splits declarations at every semicolon; a mso- style next to a url() with a semicolon in it breaks.
	 *
	 * @param string $content Block markup or classic HTML.
	 */
	public static function office( string $content ): string {
		if ( ! str_contains( $content, 'mso-' ) && ! str_contains( $content, '<o:p' ) ) {
			return $content;
		}
		$content = (string) preg_replace( '#<o:p>(?:\s|&nbsp;|&\#160;|\xC2\xA0)*</o:p>#i', '', $content );
		$tags    = new \WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag() ) {
			$style = $tags->get_attribute( 'style' );
			if ( ! is_string( $style ) || ! str_contains( strtolower( $style ), 'mso-' ) ) {
				continue;
			}
			$kept = array_filter( array_map( 'trim', explode( ';', $style ) ), static fn( string $rule ): bool => '' !== $rule && ! str_starts_with( strtolower( $rule ), 'mso-' ) );
			if ( $kept ) {
				// Joined as safecss_filter_attr() joins, so KSES keeps the attribute as it is.
				$tags->set_attribute( 'style', implode( ';', $kept ) );
			} else {
				$tags->remove_attribute( 'style' );
			}
		}
		// remove_attribute() leaves the space before it (<b >), which KSES would close up.
		return (string) preg_replace( '#<([a-z][^\s/>]*)\s+>#i', '<$1>', $tags->get_updated_html() );
	}

	/**
	 * Whether every string in block attributes passes wp_kses_post() and holds no script URL. Core escapes URLs when it
	 * renders, but the editor shows attributes of the comment as they are.
	 *
	 * @param array<mixed> $attrs Attributes.
	 */
	private static function attributes_safe( array $attrs ): bool {
		foreach ( $attrs as $value ) {
			$safe = match ( true ) {
				is_array( $value )  => self::attributes_safe( $value ),
				is_string( $value ) => self::kses_safe( $value ) && ! self::script_url( $value ),
				default             => true,
			};
			if ( ! $safe ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether no attribute in the markup holds a script URL. wp_kses_post() checks the protocol of URL attributes only;
	 * data attributes pass it, and scripts may read them as links.
	 *
	 * @param string $html Markup.
	 */
	private static function html_urls_safe( string $html ): bool {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			foreach ( (array) $tags->get_attribute_names_with_prefix( '' ) as $name ) {
				$value = $tags->get_attribute( $name );
				if ( is_string( $value ) && self::script_url( $value ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Whether a value is a script URL (javascript:, vbscript:, livescript:) or holds one in url(), read as a browser
	 * reads it: character references decoded, also without semicolon, and whitespace and control characters dropped.
	 *
	 * @param string $value Value.
	 */
	private static function script_url( string $value ): bool {
		$value = (string) preg_replace_callback( '/&#(x[0-9a-f]+|[0-9]+);?/i', static fn( array $m ): string => (string) mb_chr( 'x' === strtolower( $m[1][0] ) ? (int) hexdec( substr( $m[1], 1 ) ) : (int) $m[1], 'UTF-8' ), $value );
		$plain = strtolower( (string) preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return (bool) preg_match( '/(?:^|url\(["\']?)(?:java|vb|live)script:/', $plain );
	}

	/**
	 * Whether wp_kses_post() keeps the markup, apart from how entities and self-closing tags are spelled.
	 *
	 * @param string $html Markup.
	 */
	private static function kses_safe( string $html ): bool {
		$normal = static fn( string $markup ): string => (string) preg_replace( '#\s*/>#', '>', html_entity_decode( $markup, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		add_filter( 'safecss_filter_attr_allow_css', array( self::class, 'allow_colors' ), 10, 2 );
		$kept   = wp_kses_post( $html );
		remove_filter( 'safecss_filter_attr_allow_css', array( self::class, 'allow_colors' ), 10 );
		return $normal( $kept ) === $normal( $html );
	}

	/**
	 * Lets a style declaration with rgb(), rgba(), hsl() or hsla() colors pass safecss_filter_attr(), which allows var()
	 * and calc() but no other parentheses. Only numbers, units, separators and none count as color arguments; the rest
	 * of the declaration meets the core check unchanged. Runs on safecss_filter_attr_allow_css during kses_safe() and the
	 * write in execute() only.
	 *
	 * @param bool   $allow Whether core allows the declaration.
	 * @param string $css   Declaration with url(), gradients, var() and calc() already removed.
	 */
	public static function allow_colors( $allow, $css ): bool {
		$rest = preg_replace( '/\b(?:rgba?|hsla?)\(\s*(?:[0-9.+%-]+(?:deg|turn|rad|grad)?|none|[\s,\/])*\)/i', '', (string) $css );
		return (bool) $allow || ( is_string( $rest ) && ! preg_match( '%[\\(&=}]|/\*%', $rest ) );
	}

	/**
	 * Findings of the registry checks with scope content and page, see Content_Checks::run(). The context has post,
	 * post_type, ability and, for a new post, the template it will use.
	 *
	 * @param Operation            $op    Operation.
	 * @param array<string, mixed> $after State after.
	 * @return list<array<string, mixed>>
	 */
	private static function check( Operation $op, array $after ): array {
		if ( '' !== $op->option() || isset( $op->target['term'] ) || self::json( $after ) ) {
			return array();
		}
		$context = array(
			'post'      => $op->post_id(),
			'post_type' => (string) $after['post_type'],
			'ability'   => $op->ability,
		);
		if ( isset( $after['meta_input']['_wp_page_template'] ) ) {
			$context['template'] = (string) $after['meta_input']['_wp_page_template'];
		}
		return Content_Checks::run( (string) $after['post_content'], $context );
	}

	/**
	 * Warnings of the token set checks (Compiler::check(), for example font_not_bundled) for a token set the operation
	 * creates or activates. check() skips JSON documents and Token_Sets::validate() keeps only errors, so without this
	 * an agent never heard of a font that falls back to the system font.
	 *
	 * @param Operation            $op    Operation.
	 * @param array<string, mixed> $after State after.
	 * @return list<array<string, mixed>>
	 */
	private static function token_warnings( Operation $op, array $after ): array {
		$doc = match ( true ) {
			isset( $op->target['token_set'] )                         => Compiler::document( (int) $op->target['token_set'] ),
			Token_Sets::POST_TYPE === ( $after['post_type'] ?? null ) => json_decode( (string) ( $after['meta_input'][ Token_Sets::META ] ?? '' ), true ),
			default                                                   => null,
		};
		return is_array( $doc ) ? array_values( wp_list_filter( Compiler::check( $doc ), array( 'severity' => 'warning' ) ) ) : array();
	}

	/**
	 * How the operation is carried out: package for "suggest" and for settings changes of users without
	 * manage_options; for "draft" new posts and changes to unpublished posts as drafts, everything public and new
	 * posts of NO_DRAFT as a package;
	 * for "autonomous" live, or a package when a check warned.
	 *
	 * @param Operation            $op       Operation.
	 * @param string               $level    Autonomy level.
	 * @param array<string, mixed> $before   State before.
	 * @param array<string, mixed> $after    State after.
	 * @param bool                 $warnings Whether checks warned.
	 */
	private static function mode( Operation $op, string $level, array $before, array $after, bool $warnings ): string {
		// Only people with manage_options change settings live; for agents a settings change waits for a person.
		if ( '' !== $op->option() && ! Gateway::is_person() ) {
			return 'package';
		}
		if ( 'autonomous' === $level ) {
			return $warnings ? 'package' : 'live';
		}
		// Terms have no draft; visitors see every change of one.
		if ( 'draft' !== $level || '' !== $op->option() || isset( $op->target['term'] ) ) {
			return 'package';
		}
		if ( $op->creates() && ! in_array( $after['post_type'], self::NO_DRAFT, true ) ) {
			return 'draft';
		}
		$public = in_array( $before['post_status'] ?? '', self::PUBLIC_STATUSES, true ) || in_array( $after['post_status'], self::PUBLIC_STATUSES, true );
		return $public ? 'package' : 'draft';
	}

	/**
	 * A rejection when the user may not make the post public or change the option, else null.
	 *
	 * @param Operation            $op     Operation.
	 * @param array<string, mixed> $before State before.
	 * @param array<string, mixed> $after  State after.
	 */
	private static function publish_denied( Operation $op, array $before, array $after ): ?\WP_Error {
		$target = self::target( $op );
		if ( '' !== $op->option() ) {
			$allowed = current_user_can( 'manage_options' );
		} elseif ( isset( $op->target['term'] ) ) {
			// before() checked edit_term.
			$allowed = true;
		} else {
			$publishes = in_array( $after['post_status'], self::PUBLIC_STATUSES, true ) && ! in_array( $before['post_status'] ?? '', self::PUBLIC_STATUSES, true );
			$object    = get_post_type_object( (string) $after['post_type'] );
			$allowed   = ! $publishes || ( $object && current_user_can( $object->cap->publish_posts ) );
		}
		return $allowed ? null : Rejection::make( 'leitvia_forbidden', __( 'Your user may not publish this.', 'leitvia-studio' ), 403, 'capability', $target, '', 'never' );
	}

	/**
	 * Writes the new state with a snapshot of the old one. Any error or exception restores the previous state. The hash
	 * of the result is read from what was stored, after core's filters ran.
	 *
	 * @param Operation            $op       Operation.
	 * @param array<string, mixed> $before   State before.
	 * @param array<string, mixed> $after    State after.
	 * @param array<string, mixed> $result   Result so far.
	 * @param bool                 $delete   Whether to delete the target post, the rollback of a post a package created.
	 * @param bool                 $snapshot Whether the write restores a snapshot (rollback).
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function execute( Operation $op, array $before, array $after, array $result, bool $delete = false, bool $snapshot = false ) {
		$option = $op->option();
		$type   = match ( true ) {
			'' !== $option  => 'option',
			$op->term() > 0 => 'term',
			default         => (string) $after['post_type'],
		};
		$id = max( $op->post_id(), $op->term() );
		if ( ! $op->creates() ) {
			$result['snapshot'] = Store::snapshot( $type, $id, $op->base_hash, $before, (int) $result['package'] );
			if ( ! $result['snapshot'] ) {
				return self::failed( $result['target'] );
			}
		}
		if ( $delete ) {
			// Forced: an attachment takes its files along, and agents cannot see the trash.
			return wp_delete_post( $id, true ) ? $result : self::failed( $result['target'] );
		}
		// A new attachment waits in the private area until now, see Packages::stash_file().
		$private = (string) ( $after['file'] ?? '' );
		if ( '' !== $private ) {
			$after = Packages::promote_file( $after );
			if ( ! $after ) {
				return self::failed( $result['target'] );
			}
		}
		$created = 0;
		$capture = static function ( $post_id ) use ( &$created ): void {
			$created = $created ? $created : (int) $post_id;
		};
		// New content goes through core's KSES like any write of a user without unfiltered_html; unsafe() rejected
		// before what KSES would change beyond entity and self-closing-tag spelling, with the same allow list, so KSES
		// keeps the content. The rgb(), rgba(), hsl() and hsla() colors that unsafe() accepts (allow_colors()) stay
		// allowed for this write as well. Whatever KSES still normalizes is what gets stored: the hash of the result is
		// read back from the post (hash() below), and later diffs, conflicts and snapshots start from that stored state.
		// A rollback and the restore after a failed write put back a snapshot of what was stored before, which may hold
		// markup a person with unfiltered_html wrote (a Custom HTML block on a page an agent replaced whole); KSES would
		// cut it, so only these writes pass by the KSES filter of post content. So do JSON documents (token sets, global
		// styles), which are no HTML: KSES would encode their strings, and unsafe_json() checked them instead.
		$html = ! $snapshot && ! self::json( $after );
		$kses = $html ? false : has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( false !== $kses ) {
			remove_filter( 'content_save_pre', 'wp_filter_post_kses', $kses );
		}
		if ( $html ) {
			add_filter( 'safecss_filter_attr_allow_css', array( self::class, 'allow_colors' ), 10, 2 );
		}
		add_action( 'clean_post_cache', $capture, 1 );
		try {
			if ( self::MODEL_OPTION === $option ) {
				$failed = is_wp_error( Model::save( (array) $after['value'] ) );
			} elseif ( '' !== $option ) {
				update_option( $option, $after['value'] );
				$failed = false;
			} elseif ( $op->term() ) {
				$failed = ! self::update_term( $id, (array) $after['term'] );
			} elseif ( isset( $op->target['token_set'] ) ) {
				// The compiler writes the same presets the change computed, plus the active set and its drift hash.
				$activated = Compiler::activate( (int) $op->target['token_set'] );
				$id        = is_wp_error( $activated ) ? 0 : (int) ( \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() )['ID'] ?? 0 );
				$failed    = ! $id;
			} else {
				$written = match ( true ) {
					! $op->creates()           => self::update( $id, $after ),
					isset( $after['product'] ) => Commerce::create( $after ),
					default                    => wp_insert_post( wp_slash( Content::prepare( 0, $after ) ), true ),
				};
				$id     = is_wp_error( $written ) ? 0 : (int) $written;
				$failed = ! $id || ( $op->creates() && ! Content::set_terms( $id, $after ) );
			}
		} catch ( \Throwable $e ) {
			$failed = true;
		}
		remove_action( 'clean_post_cache', $capture, 1 );
		remove_filter( 'safecss_filter_attr_allow_css', array( self::class, 'allow_colors' ), 10 );
		if ( $failed ) {
			self::without_kses( static fn() => self::restore( $op, $before, $created ) );
			if ( '' !== $private ) {
				wp_delete_file( (string) $after['file'] );
			}
		} elseif ( '' !== $private ) {
			Packages::discard_file( array( 'file' => $private ) );
			// The smaller sizes core makes on upload, so the image gets a srcset and phones load a small file.
			if ( $id && wp_attachment_is_image( $id ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, (string) $after['file'] ) );
			}
		}
		if ( false !== $kses ) {
			add_filter( 'content_save_pre', 'wp_filter_post_kses', $kses );
		}
		if ( $failed ) {
			return self::failed( $result['target'] );
		}
		if ( $op->creates() ) {
			$result['target']['post'] = $id;
			$result['snapshot']       = Store::snapshot( $type, $id, '', array(), (int) $result['package'] );
		}
		$result['hash'] = self::hash( $op, $id );
		return $result;
	}

	/**
	 * Runs a write that puts back stored content, with the KSES filter of post content off for its duration.
	 *
	 * @param callable $write Write.
	 */
	private static function without_kses( callable $write ): void {
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( false !== $kses ) {
			remove_filter( 'content_save_pre', 'wp_filter_post_kses', $kses );
		}
		try {
			$write();
		} finally {
			if ( false !== $kses ) {
				add_filter( 'content_save_pre', 'wp_filter_post_kses', $kses );
			}
		}
	}

	/**
	 * Writes fields of an existing post over its current row, so author, dates, MIME type, ping status and the other
	 * fields a state does not hold stay. page_template stays out: wp_insert_post() would validate it against the page
	 * templates again, which block templates of the theme may not pass.
	 *
	 * @param int                  $id     Post ID.
	 * @param array<string, mixed> $fields Fields.
	 * @return int|\WP_Error
	 */
	private static function update( int $id, array $fields ) {
		if ( isset( $fields['product'] ) && Commerce::handles( (string) get_post_type( $id ) ) ) {
			return Commerce::save( $id, $fields );
		}
		$row     = array_diff_key( (array) get_post( $id, ARRAY_A ), array_flip( array( 'page_template', 'ancestors', 'post_category', 'tags_input' ) ) );
		$written = wp_insert_post( wp_slash( array( 'ID' => $id ) + Content::prepare( $id, $fields ) + $row ), true );
		return is_wp_error( $written ) || Content::set_terms( $id, $fields ) ? $written : self::failed( array( 'post' => $id ) );
	}

	/**
	 * Writes the fields of a term and the meta keys of TERM_META for its taxonomy.
	 *
	 * @param int                  $id     Term ID.
	 * @param array<string, mixed> $fields Term fields, see term_state().
	 */
	private static function update_term( int $id, array $fields ): bool {
		$term = get_term( $id );
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}
		$args = array_intersect_key( $fields, array_flip( self::TERM_FIELDS ) );
		if ( is_wp_error( wp_update_term( $id, $term->taxonomy, wp_slash( $args ) ) ) ) {
			return false;
		}
		foreach ( self::TERM_META[ $term->taxonomy ] ?? array() as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				update_term_meta( $id, $key, (string) $fields[ $key ] );
			}
		}
		return true;
	}

	/**
	 * Restores the state before a failed write: the old post fields or option value, or deletes a half-created post.
	 *
	 * @param Operation            $op      Operation.
	 * @param array<string, mixed> $before  State before.
	 * @param int                  $created ID of a post created before the failure, 0 for none.
	 */
	private static function restore( Operation $op, array $before, int $created ): void {
		try {
			if ( self::MODEL_OPTION === $op->option() ) {
				Model::save( (array) $before['value'] );
			} elseif ( '' !== $op->option() ) {
				update_option( $op->option(), $before['value'] );
			} elseif ( $op->term() ) {
				self::update_term( $op->term(), (array) $before['term'] );
			} elseif ( $op->creates() ) {
				if ( $created ) {
					wp_delete_post( $created, true );
				}
			} else {
				self::update( $op->post_id(), $before );
			}
		} catch ( \Throwable $e ) {
			// The snapshot keeps the previous state for a person to restore.
			unset( $e );
		}
	}

	/**
	 * Whether the state is theme.json data, see JSON_TYPES.
	 *
	 * @param array<string, mixed> $state State of a post.
	 */
	private static function json( array $state ): bool {
		return in_array( $state['post_type'] ?? '', self::JSON_TYPES, true );
	}

	/**
	 * Why theme.json content may not be written: no JSON object, or custom CSS from a user who may not edit CSS; null
	 * when it is safe.
	 *
	 * @param string $content Content.
	 * @return array{block: string, reason: string}|null
	 */
	private static function unsafe_json( string $content ): ?array {
		$data = json_decode( $content, true );
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return array(
				'block'  => '',
				'reason' => 'json',
			);
		}
		$css = false;
		array_walk_recursive(
			$data,
			static function ( $value, $key ) use ( &$css ): void {
				$css = $css || 'css' === $key;
			}
		);
		return $css && ! current_user_can( 'edit_css' ) ? array(
			'block'  => '',
			'reason' => 'css',
		) : null;
	}

	/**
	 * ID of the open package with the operation's key that already holds the same change, 0 for none; repeating a
	 * task adds nothing twice.
	 *
	 * @param Operation            $op    Operation.
	 * @param array<string, mixed> $after State after.
	 */
	private static function queued( Operation $op, array $after ): int {
		$id = '' !== $op->package ? Packages::find( $op->package ) : 0;
		foreach ( $id ? Packages::operations( $id ) : array() as $entry ) {
			// Loose comparison: the stored entry went through JSON.
			if ( ( $entry['ability'] ?? '' ) === $op->ability && ( $entry['target'] ?? null ) == $op->target && ( $entry['state'] ?? null ) == $after ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Whether two states are equal regardless of key order, of JSON spelling and of the block IDs core adds on save
	 * (Block_Ids); types count, so a doubtful case is a change.
	 *
	 * @param array<mixed> $a State.
	 * @param array<mixed> $b State.
	 */
	private static function same( array $a, array $b ): bool {
		$sort  = static function ( array $value ) use ( &$sort ): array {
			ksort( $value );
			return array_map( static fn( $item ) => is_array( $item ) ? $sort( $item ) : $item, $value );
		};
		$strip = static function ( array $blocks ) use ( &$strip ): array {
			foreach ( $blocks as &$block ) {
				unset( $block['attrs']['metadata']['leitviaId'] );
				if ( array() === ( $block['attrs']['metadata'] ?? null ) ) {
					unset( $block['attrs']['metadata'] );
				}
				$block['innerBlocks'] = $strip( (array) $block['innerBlocks'] );
			}
			return $blocks;
		};
		$plain = static function ( array $state ) use ( $strip ): array {
			// Theme.json content compares as data: core re-encodes it on save for users without unfiltered_html (key order,
			// escaped slashes), which every site admin of a multisite network is.
			$json = self::json( $state ) && is_string( $state['post_content'] ?? null ) ? json_decode( $state['post_content'], true ) : null;
			if ( is_array( $json ) ) {
				$state['post_content'] = $json;
			} elseif ( is_string( $state['post_content'] ?? null ) && str_contains( $state['post_content'], '<!-- wp:' ) ) {
				$state['post_content'] = $strip( parse_blocks( $state['post_content'] ) );
			}
			return $state;
		};
		return $sort( $plain( $a ) ) === $sort( $plain( $b ) );
	}

	/**
	 * Rejection leitvia_execution_failed.
	 *
	 * @param array<string, mixed> $target Target.
	 */
	private static function failed( array $target ): \WP_Error {
		return Rejection::make( 'leitvia_execution_failed', __( 'The change could not be saved; nothing was changed. Try again later.', 'leitvia-studio' ), 500, 'execution', $target, '', 'never' );
	}
}
