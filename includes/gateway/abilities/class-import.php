<?php
/**
 * Ability leitvia/import-post: one post, page or entry of another site, with its HTML turned into core blocks.
 *
 * @package LeitviaStudio
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- properties of the DOM extension.

namespace LeitviaStudio\Gateway\Abilities;

use LeitviaStudio\Gateway\Abilities;
use LeitviaStudio\Gateway\Hashes;
use LeitviaStudio\Gateway\Rejection;

defined( 'ABSPATH' ) || exit;

/**
 * For migrations: an agent reads a post of the old site (its address, title, date, author, terms, HTML) and hands it
 * over in one call. The HTML becomes core blocks (paragraph, heading, list, quote, image, table, code, separator,
 * columns, buttons, embed); shortcodes of WPBakery and Impreza become blocks where they hold text, links or images,
 * any other shortcode leaves only its text, and none stays in the content. Images of the old site are loaded into the
 * media library once (the source address is kept on the attachment, so a second import reuses them), links to the old
 * host become paths of this site, heading levels close their gaps below the title. Everything that does not carry over
 * is in the report.
 *
 * The source address is the key: a second call with the same source updates the post it created (through
 * leitvia/update-content) instead of creating another one, so the call is idempotent. The write itself runs through
 * leitvia/create-content or leitvia/update-content and their checks, autonomy and packages; only the images enter the
 * library before, as the writes need their IDs. Images come only from the host of the source and the hosts named in
 * media_hosts, through the safe HTTP API of core (no local or private addresses), up to 5 MB, JPEG, PNG, GIF, WebP or
 * AVIF. With dry_run nothing is loaded or written: the result shows the block markup and the report.
 *
 * The filter leitvia_studio_import_content lets an add-on convert the HTML itself, for example a reader for a page
 * builder: it gets null, the HTML and the input, and returns block markup or null.
 */
final class Import {

	/**
	 * Name of the ability.
	 */
	public const NAME = 'leitvia/import-post';

	/**
	 * Meta key with the source address, on imported posts and images.
	 */
	public const SOURCE_META = '_leitvia_import_source';

	/**
	 * Most images one call loads.
	 */
	private const MAX_IMAGES = 30;

	/**
	 * Largest image file.
	 */
	private const MAX_BYTES = 5 * MB_IN_BYTES;

	/**
	 * Inline markup kept inside a paragraph, heading, list item or cell.
	 */
	private const INLINE = array(
		'a'      => array(
			'href'  => true,
			'title' => true,
		),
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'br'     => array(),
		'code'   => array(),
		'sub'    => array(),
		'sup'    => array(),
		's'      => array(),
		'mark'   => array(),
		'abbr'   => array( 'title' => true ),
	);

	/**
	 * Elements that stay inside the text around them.
	 */
	private const INLINE_TAGS = array( 'a', 'abbr', 'b', 'big', 'br', 'cite', 'code', 'del', 'dfn', 'em', 'font', 'i', 'ins', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'tt', 'u', 'var' );

	/**
	 * Elements that are no text of the page.
	 */
	private const FOREIGN = array( 'script', 'style', 'noscript', 'form', 'input', 'select', 'textarea', 'button', 'object', 'embed', 'svg', 'canvas', 'video', 'audio', 'template' );

	/**
	 * Shortcodes of the builders that are presentation only: spacing, icons, sharing buttons.
	 */
	private const DROPPED = array( 'vc_empty_space', 'us_separator', 'vc_icon', 'us_icon', 'vc_raw_js', 'us_sharing', 'vc_widget_sidebar', 'us_socials' );

	/**
	 * Shortcodes of core, also when the site that imports does not register them.
	 */
	private const CORE_SHORTCODES = array( 'caption', 'wp_caption', 'gallery', 'playlist', 'audio', 'video', 'embed' );

	/**
	 * Shortcodes of page builders and common plugins of the old site: WPBakery, Impreza, Divi, Avada, Enfold,
	 * Cornerstone, Themify, Shortcodes Ultimate, Flatsome, Beaver Builder, Elementor templates, sliders and forms.
	 */
	private const BUILDER = '/^(?:(?:vc|us|et_pb|fusion|av|cs|themify|su|ux|fl_builder|mk|tatsu|qode|edgtf|mkdf)_[\w-]+|elementor-template|rev_slider|layerslider|contact-form-7|contact-form|gravityform|wpforms|ninja_form|fluentform|mc4wp_form)$/';

	/**
	 * Headings that open the questions of an FAQ part, at the start of the heading text, any case.
	 */
	public const FAQ_TITLES = '/^(?:faqs?|frequently asked questions|questions and answers|common questions|häufige fragen|häufig gestellte fragen|fragen und antworten|fragen & antworten)(?!\p{L})/iu';

	/**
	 * Report of the call.
	 *
	 * @var array<string, mixed>
	 */
	private array $report = array(
		'media'       => array(),
		'unmapped'    => array(),
		'dropped'     => array(),
		'removed'     => array(),
		'headings'    => array(),
		'missing_alt' => array(),
		'links'       => 0,
	);

	/**
	 * Last heading level, for closing gaps.
	 *
	 * @var int
	 */
	private int $level = 1;

	/**
	 * Images loaded in this call.
	 *
	 * @var int
	 */
	private int $loaded = 0;

	/**
	 * Images of the content so far, for the place a report names.
	 *
	 * @var int
	 */
	private int $image_count = 0;

	/**
	 * A converter for one source.
	 *
	 * @param string                $source  Address of the source post.
	 * @param string[]              $hosts   Hosts images may come from.
	 * @param bool                  $fetch   Whether images are loaded into the library.
	 * @param array<string, string> $images  Source attachment ID => image address, for image shortcodes.
	 * @param bool                  $preview Whether images that are not loaded keep their source address (dry run).
	 */
	public function __construct( private string $source, private array $hosts, private bool $fetch, private array $images = array(), private bool $preview = false ) {}

	/**
	 * Registers the ability. Runs on leitvia_studio_init.
	 */
	public static function register(): void {
		$text = static fn( int $max, string $description = '' ): array => array(
			'type'      => 'string',
			'maxLength' => $max,
		) + ( '' === $description ? array() : array( 'description' => $description ) );
		leitvia_studio()->registry( 'abilities' )->register(
			self::NAME,
			array(
				'args'  => array(
					'label'               => __( 'Import post', 'leitvia-studio' ),
					'description'         => __( 'Imports one post, page or entry of the content model from another site for a migration: source is its old address, content its HTML. The HTML becomes core blocks; shortcodes of WPBakery and Impreza (vc_row, vc_column, vc_column_text, vc_single_image, vc_btn, us_text, us_btn and others with text) become blocks, every other shortcode leaves only its text. Only shortcodes count: those registered here, of core or of a known page builder or form plugin; any other text in square brackets stays, and inside pre and code nothing changes. Images of the source host (and of media_hosts) are loaded into the media library once, the featured image from featured_image.url too; links to the source host become paths of this site, heading levels below the title close their gaps. author takes a user ID, login or email of a user of this site; parent an ID or parent_source, the old address of an imported parent. A second call with the same source updates that post instead of creating a new one. The write follows leitvia/create-content or leitvia/update-content with their checks and autonomy. Script, style, noscript, template and form elements go with their content (report.removed counts them per tag); report lists the loaded images and everything that did not carry over. dry_run shows the block markup and the report without loading or writing anything.', 'leitvia-studio' ),
					'input_schema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'source'         => array(
								'type'        => 'string',
								'format'      => 'uri',
								'maxLength'   => 2000,
								'description' => __( 'Address of the post on the old site, http or https; the key that makes a second import update the same post.', 'leitvia-studio' ),
							),
							'post_type'      => $text( 40, __( 'post, page or a post type of the content model.', 'leitvia-studio' ) ),
							'title'          => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 200,
								'description' => __( 'Title of the post on the old site, without the name of the site.', 'leitvia-studio' ),
							),
							'content'        => $text( 500000, __( 'HTML of the post as the old site stores it, shortcodes included.', 'leitvia-studio' ) ),
							'excerpt'        => $text( 1000 ),
							'slug'           => array(
								'type'    => 'string',
								'pattern' => '^[a-z0-9-]+$',
							),
							'date'           => Content::date_schema(),
							'status'         => array(
								'type' => 'string',
								'enum' => array( 'draft', 'pending', 'publish' ),
							),
							'author'         => array(
								'type'        => array( 'integer', 'string' ),
								'description' => __( 'User ID, login or email of an existing user of this site; without a match the post belongs to the user who writes it and report.author says so.', 'leitvia-studio' ),
							),
							'author_name'    => array(
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 100,
								'description' => __( 'Name of an author of the old site who has no account here, as the old site showed it: the post shows it as its author name and in its structured data (Person), and no user is created. Written on every import, so a second one can add or correct it; leitvia/update-content takes it too.', 'leitvia-studio' ),
							),
							'author_page'    => Content::author_page_schema(),
							'parent'         => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'parent_source'  => array(
								'type'        => 'string',
								'format'      => 'uri',
								'maxLength'   => 2000,
								'description' => __( 'Old address of the parent, imported before with leitvia/import-post or waiting in the open package with the same package key; instead of parent. Without such a parent the import is rejected, never placed at the top level. A second import with it moves the post under that parent.', 'leitvia-studio' ),
							),
							'terms'          => Content::terms_schema(),
							'kind'           => array(
								'type'        => 'string',
								'enum'        => Build::PAGE_KINDS,
								'description' => __( 'Kind of an imported page, such as imprint or privacy for a legal text, as leitvia/compose-page sets it. A legal kind keeps the page at suggest: the import waits in a change package for a person.', 'leitvia-studio' ),
							),
							'featured_image' => array(
								'type'                 => 'object',
								'properties'           => array(
									'url' => array(
										'type'        => 'string',
										'format'      => 'uri',
										'maxLength'   => 2000,
										'description' => __( 'Address of the image on the old site.', 'leitvia-studio' ),
									),
									'alt' => $text( 500 ),
								),
								'required'             => array( 'url' ),
								'additionalProperties' => false,
							),
							'images'         => array(
								'type'                 => 'object',
								'additionalProperties' => array(
									'type'      => 'string',
									'format'    => 'uri',
									'maxLength' => 2000,
								),
								'description'          => __( 'Attachment ID on the old site => address of that image, for image shortcodes such as vc_single_image image="123".', 'leitvia-studio' ),
							),
							'media_hosts'    => array(
								'type'        => 'array',
								'maxItems'    => 5,
								'items'       => $text( 253 ),
								'description' => __( 'More hosts images may come from, such as the CDN of the old site.', 'leitvia-studio' ),
							),
							'seo'            => array(
								'type'                 => 'object',
								'properties'           => array(
									'title'       => $text( 200 ),
									'description' => $text( 500 ),
								),
								'additionalProperties' => false,
								'description'          => __( 'SEO title and meta description of the old site, written as by leitvia/update-seo.', 'leitvia-studio' ),
							),
							'faq'            => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => __( 'Turns the FAQ part of the post into the block leitvia/faq with FAQPage structured data: after a heading such as FAQ or Frequently asked questions, each question as a heading one level below it or a paragraph in bold, its answer the paragraphs and lists up to the next question; report.faq counts the questions. false keeps headings and paragraphs.', 'leitvia-studio' ),
							),
							'dry_run'        => array( 'type' => 'boolean' ),
							'package'        => array(
								'type'        => 'string',
								'maxLength'   => 64,
								'description' => __( 'Key of the task: every import of a migration with the same key goes into one change package.', 'leitvia-studio' ),
							),
						),
						'required'             => array( 'source', 'post_type', 'title' ),
						'additionalProperties' => false,
					),
					'output_schema'       => Abilities::write_output(),
					'permission_callback' => array( Abilities::class, 'can_use' ),
					'execute_callback'    => static fn( $input = null ) => self::execute( is_array( $input ) ? $input : array() ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'      => false,
							'destructive'   => false,
							'idempotent'    => true,
							'openWorldHint' => true,
						),
						'show_in_rest' => true,
					),
				),
				'group' => 'build',
			),
			'leitvia-studio'
		);
	}

	/**
	 * Execute callback.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute( array $input ) {
		$source = (string) ( $input['source'] ?? '' );
		$host   = self::host( $source );
		if ( '' === $host ) {
			$error = Rejection::make( 'leitvia_invalid_input', __( 'source must be the http or https address of the post on the old site.', 'leitvia-studio' ), 422, 'source', array( 'post' => 0 ), '', 'never' );
			return Abilities::run( self::NAME, static fn() => $error, $input );
		}
		$dry   = ! empty( $input['dry_run'] );
		$hosts = array_values( array_unique( array_merge( array( $host ), array_map( 'strtolower', array_map( 'strval', (array) ( $input['media_hosts'] ?? array() ) ) ) ) ) );
		$self  = new self( $source, $hosts, ! $dry && current_user_can( 'upload_files' ), array_map( 'strval', (array) ( $input['images'] ?? array() ) ), $dry );
		$html  = (string) ( $input['content'] ?? '' );
		/**
		 * Filters the block markup of imported HTML before Studio converts it; null lets Studio convert.
		 *
		 * @param string|null          $markup Block markup, null by default.
		 * @param string               $html   HTML of the source.
		 * @param array<string, mixed> $input  Input of leitvia/import-post.
		 */
		$markup  = apply_filters( 'leitvia_studio_import_content', null, $html, $input );
		$content = is_string( $markup ) ? $markup : $self->convert( $html );
		$content = is_string( $markup ) || false === ( $input['faq'] ?? true ) ? $content : $self->faq( $content );
		$found   = self::find( $source );
		if ( $dry ) {
			return Abilities::run(
				self::NAME,
				static fn(): array => array(
					'mode'   => 'unchanged',
					'report' => array(
						'action'  => $found ? 'update' : 'create',
						'post'    => $found,
						'content' => $content,
					) + $self->report,
				),
				$input
			);
		}
		$write = array_intersect_key( $input, array_flip( array( 'title', 'excerpt', 'date', 'terms', 'package' ) ) );
		$kind  = (string) ( $input['kind'] ?? '' );
		if ( '' !== $kind && 'page' !== ( $input['post_type'] ?? '' ) ) {
			$error = Rejection::make( 'leitvia_invalid_input', __( 'Only pages have a kind.', 'leitvia-studio' ), 422, 'kind', array( 'post' => 0 ), '', 'never' );
			return Abilities::run( self::NAME, static fn() => $error, $input );
		}
		if ( '' !== trim( $content ) ) {
			$write['content'] = $content;
		}
		if ( isset( $input['author'] ) ) {
			$user = self::user( $input['author'] );
			if ( $user ) {
				$write['author'] = $user;
			} else {
				$self->report['author'] = __( 'No user of this site matches; the post belongs to the user who writes it. Send the name the old site showed as author_name to keep it as the author name without an account.', 'leitvia-studio' );
			}
		}
		// The name of an author without an account: public on the old site already, no user, no email, no login. It is
		// written on the first import and on every later one, so a second import can add or correct it.
		if ( isset( $input['author_name'] ) ) {
			$write['author_name'] = (string) $input['author_name'];
		}
		if ( isset( $input['author_page'] ) ) {
			$write['author_page'] = (int) $input['author_page'];
		}
		if ( isset( $input['featured_image']['url'] ) ) {
			$alt   = trim( (string) ( $input['featured_image']['alt'] ?? '' ) );
			$image = $self->media( (string) $input['featured_image']['url'], $alt );
			if ( $image ) {
				$write['featured_image'] = array( 'id' => $image['id'] ) + ( '' === $alt && '' === trim( (string) get_post_meta( $image['id'], '_wp_attachment_image_alt', true ) ) ? array( 'decorative' => true ) : array() );
			}
		}
		$report  = static fn(): array => $self->report;
		$parent  = (int) ( $input['parent'] ?? 0 );
		$pending = '';
		if ( isset( $input['parent_source'] ) ) {
			$parent  = self::find( (string) $input['parent_source'] );
			$pending = $parent ? '' : self::pending( (string) $input['parent_source'], (string) ( $input['package'] ?? '' ) );
			if ( ! $parent && '' === $pending ) {
				$error = Rejection::make( 'leitvia_invalid_input', __( 'No post imported here has the address in parent_source, nor does an operation of the open package with this package key. Import the parent first, in the same package or applied, then the child; without that the child would land at the top level.', 'leitvia-studio' ), 422, 'parent_source', array( 'post' => 0 ), self::NAME, 'never', array( 'parent_source' => (string) $input['parent_source'] ) );
				return Abilities::run( self::NAME, static fn() => $error, $input );
			}
		}
		if ( '' !== $pending ) {
			$self->report['parent'] = __( 'The parent waits in the same change package; the post gets it as parent when the package is applied.', 'leitvia-studio' );
		}
		if ( $found ) {
			// A second import with parent_source moves the post under its parent, for example one that landed at the top.
			$parts = Maintain::describe_update_content( array( 'post' => $found ) + $write + ( $parent && isset( $input['parent_source'] ) ? array( 'parent' => $parent ) : array() ) );
			if ( is_array( $parts ) ) {
				if ( '' !== $pending ) {
					$parts['target']['parent_source'] = $pending;
				}
				$parts['base_hash'] = Hashes::document( $found );
				if ( '' !== $kind ) {
					$parts = self::with_kind( $parts, $kind );
				}
				$parts['change'] = $self->with_seo( $parts['change'], (array) ( $input['seo'] ?? array() ), $found );
				$self->report   += array(
					'action' => 'updated',
					'post'   => $found,
				);
			}
		} else {
			$parts = Content::describe_create(
				$write + array_filter(
					array(
						'post_type' => (string) ( $input['post_type'] ?? '' ),
						'kind'      => $kind,
						'slug'      => (string) ( $input['slug'] ?? '' ),
						'status'    => (string) ( $input['status'] ?? '' ),
						'parent'    => $parent,
					)
				)
			);
			if ( is_array( $parts ) ) {
				$inner           = $parts['change'];
				$parts['change'] = static function ( array $state ) use ( $inner, $source ) {
					$state = $inner( $state );
					if ( is_array( $state ) ) {
						$state['meta_input'] = array( self::SOURCE_META => $source ) + (array) ( $state['meta_input'] ?? array() );
					}
					return $state;
				};
				if ( '' !== $pending ) {
					// The parent waits in the same package: applying it gives the ID this child gets as its parent.
					$parts['target']['parent_source'] = $pending;
				}
				$parts['change'] = $self->with_seo( $parts['change'], (array) ( $input['seo'] ?? array() ), 0 );
				$self->report   += array( 'action' => 'created' );
			}
		}
		if ( is_array( $parts ) ) {
			$parts['report'] = $report;
		}
		return Abilities::execute_write( self::NAME, static fn() => $parts, $input );
	}

	/**
	 * Parts of an update of an imported page with a page kind: the meta _leitvia_page_kind, and the kind legal for a
	 * legal text, so the update waits for a person as compose-page and create-content do (Autonomy::LEGAL_PAGES).
	 *
	 * @param array<string, mixed> $parts Parts of leitvia/update-content.
	 * @param string               $kind  Page kind of Build::PAGE_KINDS.
	 * @return array<string, mixed>
	 */
	private static function with_kind( array $parts, string $kind ): array {
		$change          = $parts['change'];
		$parts['change'] = static function ( array $state ) use ( $change, $kind ) {
			$state = $change( $state );
			if ( is_array( $state ) ) {
				$state['meta_input'] = array( '_leitvia_page_kind' => $kind ) + (array) ( $state['meta_input'] ?? array() );
			}
			return $state;
		};
		if ( in_array( $kind, \LeitviaStudio\Gateway\Autonomy::LEGAL_PAGES, true ) ) {
			$parts['kinds'] = array_values( array_unique( array_merge( (array) ( $parts['kinds'] ?? array() ), array( 'legal' ) ) ) );
		}
		return $parts;
	}

	/**
	 * The change of an update with the SEO texts of the input.
	 *
	 * @param callable             $change Change.
	 * @param array<string, mixed> $seo    SEO texts.
	 * @param int                  $post   Post ID.
	 */
	private function with_seo( callable $change, array $seo, int $post ): callable {
		if ( ! $seo ) {
			return $change;
		}
		return function ( array $state ) use ( $change, $seo, $post ) {
			$state    = $change( $state );
			$warnings = array();
			$texts    = is_wp_error( $state ) ? $state : Seo::texts( $seo, array( 'post' => $post ), $warnings );
			// The texts of the live site stay as they were; what is off (length, a fact the page does not say) goes into
			// the report, the import goes on.
			$state = is_wp_error( $texts ) ? $texts : Seo::write_texts( $state, $texts, array( 'post' => $post ), $warnings );
			if ( $warnings ) {
				$this->report['seo'] = $warnings;
			}
			return $state;
		};
	}

	/**
	 * ID of the post or image imported from a source address, 0 for none.
	 *
	 * @param string $source Source address.
	 * @param string $type   attachment for images, any for posts.
	 */
	public static function find( string $source, string $type = 'any' ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'any' === $type ? Content::types() : $type,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup per import.
				'meta_value'     => $source, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The source address when an open operation of the package with this key imports a post from it, '' otherwise.
	 *
	 * @param string $source Source address of the parent.
	 * @param string $key    Package key, '' for none.
	 */
	private static function pending( string $source, string $key ): string {
		$id = '' !== $key ? \LeitviaStudio\Gateway\Packages::find( $key ) : 0;
		foreach ( $id ? \LeitviaStudio\Gateway\Packages::operations( $id ) : array() as $entry ) {
			if ( self::NAME === ( $entry['ability'] ?? '' ) && ! isset( $entry['applied'] ) && ( $entry['state']['meta_input'][ self::SOURCE_META ] ?? '' ) === $source ) {
				return $source;
			}
		}
		return '';
	}

	/**
	 * ID of the user an author of the old site names: an ID, a login, an email or a nicename; 0 for none.
	 *
	 * @param mixed $author Author.
	 */
	private static function user( $author ): int {
		if ( is_int( $author ) || ctype_digit( (string) $author ) ) {
			$user = get_userdata( (int) $author );
			return $user ? (int) $user->ID : 0;
		}
		foreach ( array( 'login', 'email', 'slug' ) as $field ) {
			$user = get_user_by( $field, (string) $author );
			if ( $user ) {
				return (int) $user->ID;
			}
		}
		return 0;
	}

	/**
	 * Lower-case host of an http or https address, '' otherwise.
	 *
	 * @param string $url Address.
	 */
	private static function host( string $url ): string {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
	}

	/**
	 * The report of the conversion.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		return $this->report;
	}

	/**
	 * Block markup of HTML with shortcodes.
	 *
	 * @param string $html HTML.
	 */
	public function convert( string $html ): string {
		// Code keeps its square brackets: [R=301,L] in a rewrite rule or [a-z] in a pattern is text, never a shortcode.
		$kept = array();
		$html = (string) preg_replace_callback(
			'#<(pre|code)\b[^>]*>.*?</\1\s*>#is',
			static function ( array $m ) use ( &$kept ): string {
				$kept[] = $m[0];
				return "\u{E000}" . ( count( $kept ) - 1 ) . "\u{E001}";
			},
			$this->without_code( str_replace( array( "\r\n", "\r" ), "\n", $html ) )
		);
		$html = $this->shortcodes( $html );
		$html = wpautop( (string) preg_replace_callback( "/\u{E000}(\d+)\u{E001}/", static fn( array $m ): string => $kept[ (int) $m[1] ] ?? '', $html ) );
		if ( '' === trim( $html ) ) {
			return '';
		}
		$doc      = new \DOMDocument();
		$internal = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $internal );
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		return $body ? $this->children( $body ) : '';
	}

	/**
	 * Block markup with each FAQ part as the block leitvia/faq: a heading whose text matches FAQ_TITLES stays, the
	 * questions after it (a heading below its level, or a paragraph that is all bold, with an answer after a line break
	 * in the same paragraph or in the paragraphs and lists that follow) become core/details in one leitvia/faq, up to a
	 * heading of the same level or higher or another block. A part without a question with an answer stays as it was.
	 * ponytail: only blocks at the top level, not FAQ parts inside columns; add a walk when old sites need it.
	 *
	 * @param string $markup Block markup.
	 */
	public function faq( string $markup ): string {
		if ( ! preg_match( '/(?:faq|fragen|questions)/iu', wp_strip_all_tags( $markup ) ) ) {
			return $markup;
		}
		$blocks = array_values( array_filter( parse_blocks( $markup ), static fn( array $b ): bool => null !== $b['blockName'] ) );
		$text   = static fn( array $b ): string => trim( html_entity_decode( wp_strip_all_tags( (string) $b['innerHTML'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$level  = static fn( array $b ): int => 'core/heading' === $b['blockName'] ? (int) ( $b['attrs']['level'] ?? 2 ) : 0;
		$out    = array();
		$count  = 0;
		for ( $i = 0, $n = count( $blocks ); $i < $n; $i++ ) {
			$out[] = serialize_block( $blocks[ $i ] );
			$top   = $level( $blocks[ $i ] );
			if ( ! $top || ! preg_match( self::FAQ_TITLES, $text( $blocks[ $i ] ) ) ) {
				continue;
			}
			$questions = array();
			$answers   = array();
			$j         = $i + 1;
			for ( ; $j < $n; $j++ ) {
				$block = $blocks[ $j ];
				$html  = trim( (string) $block['innerHTML'] );
				if ( $level( $block ) ) {
					if ( $level( $block ) <= $top ) {
						break;
					}
					$questions[] = $text( $block );
					$answers[]   = array();
				} elseif ( 'core/paragraph' === $block['blockName'] && preg_match( '#^<p>\s*<(strong|b)>(.+?)</\1>\s*(?:<br\s*/?>(.*))?</p>$#is', $html, $m ) ) {
					$rest        = trim( (string) ( $m[3] ?? '' ) );
					$questions[] = trim( html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					$answers[]   = '' === $rest ? array() : array( '<!-- wp:paragraph --><p>' . $rest . '</p><!-- /wp:paragraph -->' );
				} elseif ( $questions && in_array( $block['blockName'], array( 'core/paragraph', 'core/list' ), true ) ) {
					$answers[ count( $answers ) - 1 ][] = serialize_block( $block );
				} else {
					break;
				}
			}
			$details = array();
			foreach ( $questions as $at => $question ) {
				if ( '' !== $question && $answers[ $at ] ) {
					$details[] = '<!-- wp:details --><details class="wp-block-details"><summary>' . esc_html( $question ) . '</summary>' . implode( "\n\n", $answers[ $at ] ) . '</details><!-- /wp:details -->';
				}
			}
			if ( ! $details ) {
				continue;
			}
			$out[]  = '<!-- wp:leitvia/faq --><div class="wp-block-leitvia-faq">' . implode( '', $details ) . '</div><!-- /wp:leitvia/faq -->';
			$count += count( $details );
			$i      = $j - 1;
		}
		if ( $count ) {
			$this->report['faq'] = $count;
		}
		return $count ? implode( "\n\n", $out ) : $markup;
	}

	/**
	 * HTML without the elements that are code or controls, never text of the page: script (also inside svg), style,
	 * noscript, template and form with everything in them. Rendered HTML of a page builder or a form plugin carries
	 * these; their content would otherwise land in a paragraph as visible text. report.removed counts them per tag.
	 *
	 * @param string $html HTML.
	 */
	private function without_code( string $html ): string {
		$count = function ( array $m ): string {
			$tag                             = strtolower( $m[1] );
			$this->report['removed'][ $tag ] = (int) ( $this->report['removed'][ $tag ] ?? 0 ) + 1;
			return '';
		};
		$html  = (string) preg_replace_callback( '#<(script|style|noscript|template|form)\b[^>]*>.*?</\1\s*>#is', $count, $html );
		// An opening tag without its end (cut off source) takes the rest with it, as a browser would.
		return (string) preg_replace_callback( '#<(script|style|noscript|template)\b[^>]*>.*$#is', $count, $html );
	}

	/**
	 * Shortcodes into HTML the converter knows. Only shortcodes: those registered on this site, those of the page
	 * builders (BUILDER) and of core (CORE_SHORTCODES), and a tag of another plugin that shows itself as one by its
	 * closing tag ([box]…[/box]) or a quoted attribute ([box id="2"]). Any other text in square brackets stays as it
	 * is: [R=301,L] in a rewrite rule, [web_root] in a guide, [a-z0-9_-] in a pattern.
	 *
	 * @param string $text Text with shortcodes.
	 */
	private function shortcodes( string $text ): string {
		if ( ! preg_match_all( '/\[([a-zA-Z][\w-]*)/', $text, $tags ) ) {
			return $text;
		}
		$known = array_values(
			array_filter(
				array_unique( $tags[1] ),
				static fn( string $tag ): bool => self::is_shortcode( $tag ) || str_contains( $text, '[/' . $tag . ']' ) || 1 === preg_match( '/\[' . preg_quote( $tag, '/' ) . '\s+[\w-]+\s*=\s*["\']/', $text )
			)
		);
		if ( ! $known ) {
			return $text;
		}
		$regex = get_shortcode_regex( $known );
		return (string) preg_replace_callback( '/' . $regex . '/s', array( $this, 'shortcode' ), $text );
	}

	/**
	 * Whether a tag in square brackets is a shortcode: registered here, of core, or of a known page builder or plugin.
	 *
	 * @param string $tag Tag.
	 */
	public static function is_shortcode( string $tag ): bool {
		$tag = strtolower( $tag );
		return shortcode_exists( $tag ) || in_array( $tag, self::CORE_SHORTCODES, true ) || 1 === preg_match( self::BUILDER, $tag );
	}

	/**
	 * One shortcode: get_shortcode_regex() groups 1 and 6 escape it, 2 is the tag, 3 the attributes, 5 the content.
	 *
	 * @param array<int, string> $m Match.
	 */
	private function shortcode( array $m ): string {
		if ( '[' === ( $m[1] ?? '' ) && ']' === ( $m[6] ?? '' ) ) {
			return substr( $m[0], 1, -1 );
		}
		$tag   = strtolower( $m[2] );
		$atts  = array_map( 'strval', (array) shortcode_parse_atts( $m[3] ?? '' ) );
		$inner = (string) ( $m[5] ?? '' );
		$att   = static fn( string $key ): string => trim( html_entity_decode( (string) ( $atts[ $key ] ?? '' ), ENT_QUOTES, 'UTF-8' ) );
		$block = static fn( string $html ): string => "\n\n" . $html . "\n\n";
		switch ( $tag ) {
			case 'vc_row':
			case 'vc_row_inner':
			case 'vc_section':
				$columns = preg_match_all( '/' . get_shortcode_regex( array( 'vc_column', 'vc_column_inner' ) ) . '/s', $inner, $found, PREG_SET_ORDER );
				if ( $columns > 1 ) {
					$html = '';
					foreach ( $found as $column ) {
						$html .= '<div data-lv-column>' . wpautop( $this->shortcodes( (string) $column[5] ) ) . '</div>';
					}
					return $block( '<div data-lv-columns>' . $html . '</div>' );
				}
				return $block( $this->shortcodes( $inner ) );
			case 'vc_column':
			case 'vc_column_inner':
			case 'vc_column_text':
			case 'vc_message':
			case 'us_message':
				return $block( $this->shortcodes( $inner ) );
			case 'vc_custom_heading':
				$level = preg_match( '/tag:h([1-6])/', $att( 'font_container' ), $h ) ? $h[1] : '2';
				return '' === $att( 'text' ) ? '' : $block( sprintf( '<h%1$s>%2$s</h%1$s>', $level, esc_html( $att( 'text' ) ) ) );
			case 'us_text':
				$level = preg_match( '/^h([1-6])$/', $att( 'tag' ), $h ) ? $h[1] : '';
				$text  = '' !== $att( 'text' ) ? $att( 'text' ) : $this->shortcodes( $inner );
				return '' === trim( $text ) ? '' : $block( '' === $level ? '<p>' . $text . '</p>' : sprintf( '<h%1$s>%2$s</h%1$s>', $level, $text ) );
			case 'vc_single_image':
			case 'us_image':
				return $block( $this->shortcode_images( $att( 'image' ), $tag ) );
			case 'vc_gallery':
			case 'us_gallery':
			case 'vc_images_carousel':
				return $block( $this->shortcode_images( '' !== $att( 'images' ) ? $att( 'images' ) : $att( 'ids' ), $tag ) );
			case 'vc_btn':
			case 'us_btn':
			case 'vc_button':
				$link  = self::link( '' !== $att( 'link' ) ? $att( 'link' ) : $att( 'href' ) );
				$label = '' !== $att( 'title' ) ? $att( 'title' ) : ( '' !== $att( 'label' ) ? $att( 'label' ) : $att( 'text' ) );
				return '' === $link || '' === $label ? $this->unmapped( 'shortcode', $tag, $label ) : $block( sprintf( '<p data-lv-button><a href="%1$s">%2$s</a></p>', esc_url( $link ), esc_html( $label ) ) );
			case 'vc_separator':
			case 'vc_text_separator':
				return $block( '' === $att( 'title' ) ? '<hr>' : '<h2>' . esc_html( $att( 'title' ) ) . '</h2>' );
			case 'vc_video':
			case 'us_video':
				$link = '' !== $att( 'link' ) ? $att( 'link' ) : $att( 'url' );
				return '' === $link ? '' : $block( '<figure data-lv-embed="' . esc_url( $link ) . '"></figure>' );
			case 'vc_raw_html':
				return $this->unmapped( 'shortcode', $tag, '' );
		}
		if ( in_array( $tag, self::DROPPED, true ) ) {
			$this->report['dropped'][ $tag ] = (int) ( $this->report['dropped'][ $tag ] ?? 0 ) + 1;
			return '';
		}
		if ( preg_match( '/^(vc|us)_/', $tag ) ) {
			// Any other shortcode of the builders: its text attributes, the title ones as a heading, then its content.
			$html = '';
			foreach ( array( 'title', 'heading', 'name' ) as $key ) {
				$html .= '' === $att( $key ) ? '' : '<h3>' . esc_html( $att( $key ) ) . '</h3>';
			}
			foreach ( array( 'subtitle', 'role', 'text', 'description', 'label', 'content' ) as $key ) {
				$html .= '' === $att( $key ) ? '' : '<p>' . esc_html( $att( $key ) ) . '</p>';
			}
			$link  = self::link( $att( 'link' ) );
			$html .= '' === $link || '' === $att( 'btn_label' ) ? '' : sprintf( '<p data-lv-button><a href="%1$s">%2$s</a></p>', esc_url( $link ), esc_html( $att( 'btn_label' ) ) );
			$html .= $this->shortcodes( $inner );
			if ( '' === trim( $html ) ) {
				return $this->unmapped( 'shortcode', $tag, '' );
			}
			$this->report['unmapped'][] = array(
				'kind' => 'shortcode-text',
				'name' => $tag,
				'text' => __( 'Only the text carried over.', 'leitvia-studio' ),
			);
			return $block( $html );
		}
		// Shortcodes of other plugins leave their text; without text they are reported.
		$text = $this->shortcodes( $inner );
		return '' === trim( wp_strip_all_tags( $text ) ) ? $this->unmapped( 'shortcode', $tag, '' ) : $text;
	}

	/**
	 * Image tags for the source attachment IDs of an image shortcode, from the input images.
	 *
	 * @param string $ids Comma separated IDs.
	 * @param string $tag Shortcode.
	 */
	private function shortcode_images( string $ids, string $tag ): string {
		$html = '';
		foreach ( array_filter( array_map( 'trim', explode( ',', $ids ) ) ) as $id ) {
			if ( isset( $this->images[ $id ] ) ) {
				$html .= '<p><img src="' . esc_url( $this->images[ $id ] ) . '"></p>';
			} else {
				/* translators: %s: attachment ID on the old site. */
				$this->unmapped( 'image', $tag, sprintf( __( 'Image %s of the old site: name its address in images.', 'leitvia-studio' ), $id ) );
			}
		}
		return $html;
	}

	/**
	 * The address of a link attribute of WPBakery (url:...|title:...) or Impreza (url-encoded JSON), or a plain one.
	 *
	 * @param string $value Attribute.
	 */
	private static function link( string $value ): string {
		$value = trim( $value );
		$json  = json_decode( rawurldecode( $value ), true );
		if ( is_array( $json ) ) {
			return trim( (string) ( $json['url'] ?? '' ) );
		}
		if ( preg_match( '/(?:^|\|)url:([^|]*)/', $value, $m ) ) {
			return trim( rawurldecode( $m[1] ) );
		}
		return preg_match( '#^(https?:|/|mailto:|tel:)#', $value ) ? $value : '';
	}

	/**
	 * Notes something that does not carry over; returns ''.
	 *
	 * @param string $kind Kind: shortcode, element or image.
	 * @param string $name Tag.
	 * @param string $text What it held.
	 */
	private function unmapped( string $kind, string $name, string $text ): string {
		$this->report['unmapped'][] = array(
			'kind' => $kind,
			'name' => $name,
			'text' => mb_substr( trim( $text ), 0, 200 ),
		);
		return '';
	}

	/**
	 * Blocks of the children of an element; runs of inline content become paragraphs.
	 *
	 * @param \DOMNode $element Element.
	 */
	private function children( \DOMNode $element ): string {
		$blocks = array();
		$inline = '';
		$flush  = function () use ( &$inline, &$blocks ): void {
			$text = $this->inline_html( $inline );
			if ( '' !== $text ) {
				$blocks[] = self::block( 'paragraph', array(), '<p>' . $text . '</p>' );
			}
			$inline = '';
		};
		foreach ( iterator_to_array( $element->childNodes ) as $node ) {
			if ( $node instanceof \DOMElement && ! in_array( strtolower( $node->tagName ), self::INLINE_TAGS, true ) && 'img' !== strtolower( $node->tagName ) ) {
				$flush();
				$blocks[] = $this->element( $node );
			} elseif ( $node instanceof \DOMElement && 'img' === strtolower( $node->tagName ) ) {
				$flush();
				$blocks[] = $this->image_element( $node, '', '' );
			} else {
				$inline .= (string) $node->ownerDocument?->saveHTML( $node );
			}
		}
		$flush();
		return implode( "\n\n", array_filter( $blocks ) );
	}

	/**
	 * Blocks of one block-level element.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function element( \DOMElement $el ): string {
		$tag = strtolower( $el->tagName );
		if ( $el->hasAttribute( 'data-lv-columns' ) ) {
			return $this->columns( $el );
		}
		if ( $el->hasAttribute( 'data-lv-button' ) ) {
			return $this->button( $el );
		}
		if ( $el->hasAttribute( 'data-lv-embed' ) ) {
			return $this->embed( $el->getAttribute( 'data-lv-embed' ) );
		}
		if ( in_array( $tag, self::FOREIGN, true ) ) {
			return $this->unmapped( 'element', $tag, (string) $el->textContent );
		}
		return match ( $tag ) {
			'p'                                  => $this->paragraph( $el ),
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->heading( (int) $tag[1], $this->inline( $el ) ),
			'ul', 'ol'                           => $this->list_block( $el ),
			'blockquote'                         => $this->quote( $el ),
			'figure'                             => $this->figure( $el ),
			'table'                              => $this->table( $el ),
			'pre'                                => '' === trim( (string) $el->textContent ) ? '' : self::block( 'code', array(), '<pre class="wp-block-code"><code>' . esc_html( (string) $el->textContent ) . '</code></pre>' ),
			'hr'                                 => self::block( 'separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' ),
			'iframe'                             => $this->embed( $el->getAttribute( 'src' ) ),
			default                              => $this->children( $el ),
		};
	}

	/**
	 * A paragraph; images in it follow as image blocks.
	 *
	 * @param \DOMElement $el Paragraph.
	 */
	private function paragraph( \DOMElement $el ): string {
		$blocks = array();
		$text   = $this->inline( $el );
		if ( '' !== $text ) {
			$blocks[] = self::block( 'paragraph', array(), '<p>' . $text . '</p>' );
		}
		foreach ( iterator_to_array( $el->getElementsByTagName( 'img' ) ) as $img ) {
			$link     = $img->parentNode instanceof \DOMElement && 'a' === strtolower( $img->parentNode->tagName ) ? $img->parentNode->getAttribute( 'href' ) : '';
			$blocks[] = $this->image_element( $img, '', $link );
		}
		return implode( "\n\n", array_filter( $blocks ) );
	}

	/**
	 * A heading; h1 becomes h2 (the title is the h1), a level that skips one moves up.
	 *
	 * @param int    $level Level of the source.
	 * @param string $inner Inline HTML.
	 */
	private function heading( int $level, string $inner ): string {
		if ( '' === $inner ) {
			return '';
		}
		$to = max( 2, min( $level, $this->level + 1 ) );
		if ( $to !== $level ) {
			$this->report['headings'][] = array(
				'from' => $level,
				'to'   => $to,
				'text' => mb_substr( wp_strip_all_tags( $inner ), 0, 100 ),
			);
		}
		$this->level = $to;
		return self::block( 'heading', 2 === $to ? array() : array( 'level' => $to ), sprintf( '<h%1$d class="wp-block-heading">%2$s</h%1$d>', $to, $inner ) );
	}

	/**
	 * A list with its items; nested lists stay inside their item.
	 *
	 * @param \DOMElement $el ul or ol.
	 */
	private function list_block( \DOMElement $el ): string {
		$items = '';
		foreach ( iterator_to_array( $el->childNodes ) as $li ) {
			if ( ! $li instanceof \DOMElement || 'li' !== strtolower( $li->tagName ) ) {
				continue;
			}
			$nested = '';
			$text   = '';
			foreach ( iterator_to_array( $li->childNodes ) as $child ) {
				if ( $child instanceof \DOMElement && in_array( strtolower( $child->tagName ), array( 'ul', 'ol' ), true ) ) {
					$nested .= $this->list_block( $child );
				} else {
					$text .= (string) $child->ownerDocument?->saveHTML( $child );
				}
			}
			$text = $this->inline_html( $text );
			if ( '' !== $text || '' !== $nested ) {
				$items .= self::block( 'list-item', array(), '<li>' . $text . $nested . '</li>' );
			}
		}
		if ( '' === $items ) {
			return '';
		}
		$ordered = 'ol' === strtolower( $el->tagName );
		return self::block( 'list', $ordered ? array( 'ordered' => true ) : array(), sprintf( '<%1$s class="wp-block-list">%2$s</%1$s>', $ordered ? 'ol' : 'ul', $items ) );
	}

	/**
	 * A quote with its paragraphs.
	 *
	 * @param \DOMElement $el blockquote.
	 */
	private function quote( \DOMElement $el ): string {
		$inner = $this->children( $el );
		return '' === $inner ? '' : self::block( 'quote', array(), '<blockquote class="wp-block-quote">' . $inner . '</blockquote>' );
	}

	/**
	 * A figure: an image with its caption, a table or an embed.
	 *
	 * @param \DOMElement $el figure.
	 */
	private function figure( \DOMElement $el ): string {
		$caption = $el->getElementsByTagName( 'figcaption' )->item( 0 );
		$text    = $caption ? $this->inline( $caption ) : '';
		$img     = $el->getElementsByTagName( 'img' )->item( 0 );
		if ( $img ) {
			$link = $img->parentNode instanceof \DOMElement && 'a' === strtolower( $img->parentNode->tagName ) ? $img->parentNode->getAttribute( 'href' ) : '';
			return $this->image_element( $img, $text, $link );
		}
		$table = $el->getElementsByTagName( 'table' )->item( 0 );
		if ( $table ) {
			return $this->table( $table );
		}
		$frame = $el->getElementsByTagName( 'iframe' )->item( 0 );
		return $frame ? $this->embed( $frame->getAttribute( 'src' ) ) : $this->children( $el );
	}

	/**
	 * An image block of an img element, with the image loaded into the library; without it the image is reported.
	 *
	 * @param \DOMElement $img     img.
	 * @param string      $caption Caption, inline HTML.
	 * @param string      $link    Link around the image.
	 */
	private function image_element( \DOMElement $img, string $caption, string $link ): string {
		$src = trim( $img->getAttribute( 'src' ) );
		if ( '' === $src ) {
			return '';
		}
		$alt = $img->hasAttribute( 'alt' ) ? trim( $img->getAttribute( 'alt' ) ) : null;
		// A file name is no alt text; the check of Studio refuses it.
		if ( null !== $alt && preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $alt ) ) {
			$alt = null;
		}
		$image = $this->media( $src, (string) $alt );
		if ( ! $image ) {
			return ! $this->preview ? '' : self::block( 'image', array(), sprintf( '<figure class="wp-block-image"><img src="%1$s" alt="%2$s"/></figure>', esc_url( $src ), esc_attr( (string) $alt ) ) );
		}
		$alt = '' !== (string) $alt ? (string) $alt : trim( (string) get_post_meta( $image['id'], '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) {
			$this->report['missing_alt'][] = $image['url'];
		}
		$link = $this->local( $link );
		// A linked image without alt text is a link without text (WCAG 2.4.4): the image comes without its link, and the
		// report names the place and what to do, instead of the whole import failing on the check.
		++$this->image_count;
		if ( '' === $alt && '' !== $link ) {
			$this->report['unlinked'][] = array(
				'image' => $this->image_count,
				'url'   => $image['url'],
				'link'  => $link,
				/* translators: 1: number of the image in the post, 2: address of the link. */
				'text'  => sprintf( __( 'Image %1$d linked to %2$s has no alt text, so the link was left out. Give the image an alt text that names where the link leads (leitvia/update-block with image alt) and set attributes href to the address.', 'leitvia-studio' ), $this->image_count, $link ),
			);
			$link                       = '';
		}
		$img   = sprintf( '<img src="%1$s" alt="%2$s" class="wp-image-%3$d"/>', esc_url( $image['url'] ), esc_attr( $alt ), $image['id'] );
		$attrs = array(
			'id'              => $image['id'],
			'sizeSlug'        => 'full',
			'linkDestination' => '' === $link ? 'none' : 'custom',
		) + ( '' === $alt ? array( 'metadata' => array( 'leitviaDecorative' => true ) ) : array() );
		return self::block(
			'image',
			$attrs,
			sprintf(
				'<figure class="wp-block-image size-full">%1$s%2$s</figure>',
				'' === $link ? $img : '<a href="' . esc_url( $link ) . '">' . $img . '</a>',
				'' === $caption ? '' : '<figcaption class="wp-element-caption">' . $caption . '</figcaption>'
			)
		);
	}

	/**
	 * A table with its head and body; cells keep inline markup.
	 *
	 * @param \DOMNode $el table.
	 */
	private function table( \DOMNode $el ): string {
		$head = '';
		$body = '';
		foreach ( iterator_to_array( $el instanceof \DOMElement ? $el->getElementsByTagName( 'tr' ) : array() ) as $i => $tr ) {
			$cells = '';
			$th    = true;
			foreach ( iterator_to_array( $tr->childNodes ) as $cell ) {
				if ( $cell instanceof \DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
					$th     = $th && 'th' === strtolower( $cell->tagName );
					$cells .= sprintf( '<%1$s>%2$s</%1$s>', strtolower( $cell->tagName ), $this->inline( $cell ) );
				}
			}
			$section = $tr->parentNode instanceof \DOMElement ? strtolower( $tr->parentNode->tagName ) : '';
			if ( 'thead' === $section || ( 0 === $i && $th && '' !== $cells ) ) {
				$head .= '<tr>' . $cells . '</tr>';
			} elseif ( '' !== $cells ) {
				$body .= '<tr>' . $cells . '</tr>';
			}
		}
		if ( '' === $head . $body ) {
			return '';
		}
		return self::block(
			'table',
			array( 'hasFixedLayout' => false ),
			'<figure class="wp-block-table"><table>' . ( '' === $head ? '' : '<thead>' . $head . '</thead>' ) . ( '' === $body ? '' : '<tbody>' . $body . '</tbody>' ) . '</table></figure>'
		);
	}

	/**
	 * Columns of a row of the builder; empty columns stay out.
	 *
	 * @param \DOMElement $el The row.
	 */
	private function columns( \DOMElement $el ): string {
		$columns = array();
		foreach ( iterator_to_array( $el->childNodes ) as $column ) {
			$inner = $column instanceof \DOMElement ? $this->children( $column ) : '';
			if ( '' !== $inner ) {
				$columns[] = self::block( 'column', array(), '<div class="wp-block-column">' . $inner . '</div>' );
			}
		}
		if ( count( $columns ) < 2 ) {
			return implode( "\n\n", array_map( static fn( string $c ): string => (string) preg_replace( '#^<!-- wp:column -->\s*<div class="wp-block-column">(.*)</div>\s*<!-- /wp:column -->$#s', '$1', $c ), $columns ) );
		}
		return self::block( 'columns', array(), '<div class="wp-block-columns">' . implode( '', $columns ) . '</div>' );
	}

	/**
	 * A button of the builder.
	 *
	 * @param \DOMElement $el Paragraph with one link.
	 */
	private function button( \DOMElement $el ): string {
		$a = $el->getElementsByTagName( 'a' )->item( 0 );
		if ( ! $a ) {
			return '';
		}
		$href = $this->local( $a->getAttribute( 'href' ) );
		$text = esc_html( trim( (string) $a->textContent ) );
		return self::block( 'buttons', array(), '<div class="wp-block-buttons">' . self::block( 'button', array(), '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $href ) . '">' . $text . '</a></div>' ) . '</div>' );
	}

	/**
	 * An embed of YouTube or Vimeo; another address stays as a link and is reported.
	 *
	 * @param string $src Address of the player or the video.
	 */
	private function embed( string $src ): string {
		$src = html_entity_decode( trim( $src ), ENT_QUOTES, 'UTF-8' );
		if ( preg_match( '#(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?v=)|youtu\.be/)([\w-]{6,})#', $src, $m ) ) {
			$url      = 'https://www.youtube.com/watch?v=' . $m[1];
			$provider = 'youtube';
		} elseif ( preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $src, $m ) ) {
			$url      = 'https://vimeo.com/' . $m[1];
			$provider = 'vimeo';
		} else {
			$this->unmapped( 'element', 'iframe', $src );
			return preg_match( '#^https?://#', $src ) ? self::block( 'paragraph', array(), '<p><a href="' . esc_url( $src ) . '">' . esc_html( $src ) . '</a></p>' ) : '';
		}
		$class = 'wp-embed-aspect-16-9 wp-has-aspect-ratio';
		return self::block(
			'embed',
			array(
				'url'              => $url,
				'type'             => 'video',
				'providerNameSlug' => $provider,
				'responsive'       => true,
				'className'        => $class,
			),
			sprintf( '<figure class="wp-block-embed is-type-video is-provider-%1$s wp-block-embed-%1$s %2$s"><div class="wp-block-embed__wrapper">' . "\n" . '%3$s' . "\n" . '</div></figure>', $provider, $class, esc_url( $url ) )
		);
	}

	/**
	 * Inline HTML of the children of an element.
	 *
	 * @param \DOMNode $el Element.
	 */
	private function inline( \DOMNode $el ): string {
		$html = '';
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			$html .= (string) $child->ownerDocument?->saveHTML( $child );
		}
		return $this->inline_html( $html );
	}

	/**
	 * Inline HTML as a paragraph may hold it: the allowed tags only, links to the source host as paths, links
	 * without an address or text unwrapped, whitespace collapsed.
	 *
	 * @param string $html HTML.
	 */
	private function inline_html( string $html ): string {
		$html = trim( (string) preg_replace( '/\s+/u', ' ', wp_kses( $html, self::INLINE ) ) );
		$html = (string) preg_replace( '#^(<br\s*/?>\s*)+|(\s*<br\s*/?>)+$#', '', $html );
		$html = (string) preg_replace( '~<a\b(?![^>]*\bhref="[^"#][^"]*")[^>]*>(.*?)</a>~s', '$1', $html );
		$html = (string) preg_replace( '#<a\b[^>]*>\s*</a>#', '', $html );
		if ( str_contains( $html, '<a ' ) ) {
			$tags = new \WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag( array( 'tag_name' => 'A' ) ) ) {
				$href = (string) $tags->get_attribute( 'href' );
				$path = $this->local( $href );
				if ( $path !== $href ) {
					$tags->set_attribute( 'href', $path );
				}
			}
			$html = $tags->get_updated_html();
		}
		return '' === trim( wp_strip_all_tags( $html ) ) ? '' : $html;
	}

	/**
	 * A link to the source host as a path of this site, counted in the report; other links as they are.
	 *
	 * @param string $href Address.
	 */
	private function local( string $href ): string {
		$href = trim( $href );
		if ( '' === $href || self::host( $href ) !== self::host( $this->source ) ) {
			return $href;
		}
		$parts = (array) wp_parse_url( $href );
		++$this->report['links'];
		return ( (string) ( $parts['path'] ?? '/' ) ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Loads an image of an allowed host into the media library once; null when it is not allowed or fails, with the
	 * reason in the report. An image imported before is reused. The original is tried first, without the size suffix
	 * of WordPress (-1024x683).
	 *
	 * @param string $src Address, absolute or relative to the source.
	 * @param string $alt Alt text for a new image.
	 * @return array{id: int, url: string}|null
	 */
	public function media( string $src, string $alt ): ?array {
		$url = html_entity_decode( trim( $src ), ENT_QUOTES, 'UTF-8' );
		$url = str_starts_with( $url, '//' ) ? 'https:' . $url : $url;
		if ( str_starts_with( $url, '/' ) ) {
			$parts = (array) wp_parse_url( $this->source );
			$url   = (string) ( $parts['scheme'] ?? 'https' ) . '://' . (string) ( $parts['host'] ?? '' ) . $url;
		}
		if ( ! in_array( self::host( $url ), $this->hosts, true ) ) {
			$this->unmapped( 'image', 'img', $url . ' ' . __( '(host not allowed, add it to media_hosts)', 'leitvia-studio' ) );
			return null;
		}
		$found = self::find( $url, 'attachment' );
		if ( $found ) {
			$this->report['media'][] = array(
				'url'    => $url,
				'id'     => $found,
				'reused' => true,
			);
			return array(
				'id'  => $found,
				'url' => (string) wp_get_attachment_url( $found ),
			);
		}
		if ( ! $this->fetch ) {
			if ( $this->preview ) {
				return null;
			}
			$this->unmapped( 'image', 'img', $url . ' ' . __( '(your user may not upload files)', 'leitvia-studio' ) );
			return null;
		}
		if ( $this->loaded >= self::MAX_IMAGES ) {
			$this->unmapped( 'image', 'img', $url . ' ' . __( '(more than 30 images in one call)', 'leitvia-studio' ) );
			return null;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$original = (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', (string) strtok( $url, '?#' ) );
		$error    = '';
		foreach ( array_unique( array( $original, $url ) ) as $candidate ) {
			$tmp = download_url( $candidate, 30 );
			if ( is_wp_error( $tmp ) ) {
				$error = $tmp->get_error_message();
				continue;
			}
			$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $candidate, PHP_URL_PATH ) ) );
			$type = wp_check_filetype_and_ext( $tmp, $name );
			if ( '' === $name || (int) filesize( $tmp ) > self::MAX_BYTES || ! in_array( $type['type'], array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ), true ) ) {
				wp_delete_file( $tmp );
				$error = __( 'no image up to 5 MB in JPEG, PNG, GIF, WebP or AVIF', 'leitvia-studio' );
				continue;
			}
			// As Packages::promote_file(): core writes uploads with PHP (wp_upload_bits()), whatever FS_METHOD says.
			$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The temporary file of the download above.
			wp_delete_file( $tmp );
			$upload = wp_upload_bits( $name, null, $bytes );
			if ( ! empty( $upload['error'] ) ) {
				$error = (string) $upload['error'];
				continue;
			}
			$id = wp_insert_attachment(
				array(
					'post_mime_type' => (string) $type['type'],
					'post_title'     => sanitize_text_field( (string) pathinfo( $name, PATHINFO_FILENAME ) ),
					'post_status'    => 'inherit',
				),
				$upload['file'],
				0,
				true
			);
			if ( is_wp_error( $id ) ) {
				wp_delete_file( $upload['file'] );
				$error = $id->get_error_message();
				continue;
			}
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
			++$this->loaded;
			update_post_meta( $id, self::SOURCE_META, $url );
			if ( '' !== $alt ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
			}
			$this->report['media'][] = array(
				'url'    => $url,
				'id'     => (int) $id,
				'reused' => false,
			);
			return array(
				'id'  => (int) $id,
				'url' => (string) wp_get_attachment_url( $id ),
			);
		}
		$this->unmapped( 'image', 'img', $url . ' (' . $error . ')' );
		return null;
	}

	/**
	 * Markup of one block.
	 *
	 * @param string               $name  Block name without core/.
	 * @param array<string, mixed> $attrs Attributes.
	 * @param string               $html  Saved HTML.
	 */
	private static function block( string $name, array $attrs, string $html ): string {
		$json = $attrs ? ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
		return '<!-- wp:' . $name . $json . ' -->' . $html . '<!-- /wp:' . $name . ' -->';
	}
}
