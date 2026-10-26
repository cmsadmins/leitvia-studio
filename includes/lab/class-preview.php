<?php
/**
 * Front-end preview of one pattern for the Lab.
 *
 * @package LeitviaStudio
 */

namespace LeitviaStudio\Lab;

use LeitviaStudio\Tokens\Compiler;
use LeitviaStudio\Tokens\Dtcg;
use LeitviaStudio\Tokens\Token_Sets;

defined( 'ABSPATH' ) || exit;

/**
 * Renders /?leitvia_studio_preview=1&ref=<block:ID|pattern:slug>&style=<variation>&set=<token set ID>&axe=1&annotations=1&_wpnonce=…
 * as a full page, with a style variation or token set applied to this request only. With axe=1 the page runs axe-core
 * and reports the violations to the Lab. With annotations=1 the blocks named in the annotations of a wp_block carry
 * data-leitvia-annotation="<number>" and get numbered marks.
 */
final class Preview {

	/**
	 * Draws one outlined, numbered mark per annotated block and keeps the marks in place when the page reflows. The frame
	 * uses the focus colour of the theme with a Canvas ring inside, so it stands out on any background; the number uses
	 * the system pair CanvasText on Canvas, whose contrast the browser guarantees.
	 */
	private const MARKS_SCRIPT = <<<'JS'
(function(){
var layer=document.createElement('div');
layer.setAttribute('aria-hidden','true');
layer.className='leitvia-studio-annotations';
document.body.appendChild(layer);
function draw(){
layer.textContent='';
document.querySelectorAll('[data-leitvia-annotation]').forEach(function(el){
var r=el.getBoundingClientRect(),box=document.createElement('div'),label=document.createElement('span');
box.className='leitvia-studio-annotation';
box.style.cssText='position:absolute;pointer-events:none;outline:2px solid var(--wp--preset--color--focus,Highlight);outline-offset:-2px;box-shadow:inset 0 0 0 4px Canvas;top:'+(r.top+window.scrollY)+'px;left:'+(r.left+window.scrollX)+'px;width:'+r.width+'px;height:'+r.height+'px';
label.style.cssText='position:absolute;top:0;left:0;padding:2px 6px;font:700 14px/1.4 sans-serif;background:CanvasText;color:Canvas';
label.textContent=el.getAttribute('data-leitvia-annotation');
box.appendChild(label);
layer.appendChild(box);
});
}
draw();
new ResizeObserver(draw).observe(document.documentElement);
})();
JS;

	/**
	 * Answers preview requests before any template loads. Runs on template_redirect, priority 0.
	 */
	public static function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- respond() checks the nonce.
		if ( ! isset( $_GET['leitvia_studio_preview'] ) ) {
			return;
		}
		$query = array_map(
			static fn( $value ): string => is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '',
			array_intersect_key( $_GET, array_flip( array( 'ref', 'style', 'set', 'axe', 'annotations', '_wpnonce' ) ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		show_admin_bar( false );
		$response = self::respond( $query );
		status_header( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( "{$name}: {$value}" );
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The preview document respond() builds for a user with edit_posts and a valid nonce: escaped head and rendered blocks.
		exit;
	}

	/**
	 * Status, headers and body of a preview request.
	 *
	 * @param array<string, string> $query Query parameters ref, style, set, axe, annotations and _wpnonce.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function respond( array $query ): array {
		// Shown only in frames of the Lab on the same site, never cached, never indexed.
		$headers = array(
			'X-Robots-Tag'            => 'noindex',
			'X-Frame-Options'         => 'SAMEORIGIN',
			'Content-Security-Policy' => "frame-ancestors 'self'",
		) + array_filter( wp_get_nocache_headers() );
		$error   = static fn( int $status, string $message ): array => array(
			'status'  => $status,
			'headers' => $headers + array( 'Content-Type' => 'text/plain; charset=utf-8' ),
			'body'    => $message,
		);

		if ( ! current_user_can( 'edit_posts' ) || ! wp_verify_nonce( $query['_wpnonce'] ?? '', 'leitvia_studio_preview' ) ) {
			return $error( 403, __( 'You are not allowed to preview patterns.', 'leitvia-studio' ) );
		}
		$content = self::content( $query['ref'] ?? '' );
		if ( is_int( $content ) ) {
			return $error( $content, 403 === $content ? __( 'You are not allowed to preview this pattern.', 'leitvia-studio' ) : __( 'The pattern does not exist.', 'leitvia-studio' ) );
		}
		$config = self::config( $query['style'] ?? '', $query['set'] ?? '' );
		if ( is_wp_error( $config ) ) {
			return $error( (int) ( $config->get_error_data()['status'] ?? 422 ), $config->get_error_message() );
		}

		$filter = static function ( \WP_Theme_JSON_Data $theme_json ) use ( $config ): \WP_Theme_JSON_Data {
			if ( isset( $config['style'] ) ) {
				$theme_json = $theme_json->update_with( $config['style'] + array( 'version' => \WP_Theme_JSON::LATEST_SCHEMA ) );
			}
			if ( isset( $config['set'] ) ) {
				// Laid over like an activation, so the roles of the set outrank the presets of a style variation.
				$theme_json = new \WP_Theme_JSON_Data( Compiler::lay_over( $theme_json->get_data(), $config['set'] ), 'custom' );
			}
			return $theme_json;
		};
		if ( '1' === ( $query['axe'] ?? '' ) ) {
			self::enqueue_axe();
		}
		add_filter( 'wp_theme_json_data_user', $filter );
		\WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();

		$numbers = array();
		$id      = preg_match( '/^block:([1-9]\d*)$/D', $query['ref'] ?? '', $match ) ? (int) $match[1] : 0;
		if ( '1' === ( $query['annotations'] ?? '' ) && $id ) {
			foreach ( self::annotations( $id ) as $annotation ) {
				// An empty target would match every unnamed block; an orphaned one matches nothing.
				if ( '' === $annotation['target'] || $annotation['orphaned'] ) {
					continue;
				}
				$numbers[ $annotation['target'] ][] = $annotation['number'];
			}
		}
		$mark = static function ( string $html, array $block ) use ( $numbers ): string {
			$name = (string) ( $block['attrs']['metadata']['name'] ?? '' );
			if ( ! isset( $numbers[ $name ] ) ) {
				return $html;
			}
			$tags = new \WP_HTML_Tag_Processor( $html );
			if ( $tags->next_tag() ) {
				$tags->set_attribute( 'data-leitvia-annotation', implode( ' ', $numbers[ $name ] ) );
			}
			return $tags->get_updated_html();
		};
		if ( $numbers ) {
			add_filter( 'render_block', $mark, 10, 2 );
		}
		// Rendered before wp_head(), as the block template canvas does, so block styles and script modules land in head.
		$blocks = do_blocks( $content );
		remove_filter( 'render_block', $mark );
		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( __( 'Pattern preview', 'leitvia-studio' ) ); ?></title>
		<?php
		// The page has its own title (WCAG 2.4.2); core prints one only for themes with title-tag support.
		remove_action( 'wp_head', '_wp_render_title_tag', 1 );
		wp_head();
		?>
</head>
<body class="leitvia-studio-preview">
<main id="main">
		<?php echo $blocks; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output of do_blocks(), as core prints the_content; kses would strip forms and inline SVG the pattern shows. ?>
</main>
		<?php
		// Reports the page height to the Lab so the frame can grow with the pattern; links and forms stay inert.
		wp_print_inline_script_tag(
			'new ResizeObserver(function(){window.parent.postMessage({type:"leitvia-studio:height",height:document.documentElement.scrollHeight},window.location.origin);}).observe(document.documentElement);'
			. 'document.addEventListener("click",function(e){if(e.target.closest&&e.target.closest("a[href]")){e.preventDefault();}},true);'
			. 'document.addEventListener("submit",function(e){e.preventDefault();},true);'
		);
		if ( $numbers ) {
			// Numbered marks over the annotated blocks, drawn in the focus colour of the theme. The Lab lists the same
			// annotations as text, so the marks are hidden from assistive technology.
			wp_print_inline_script_tag( self::MARKS_SCRIPT );
		}
		wp_footer();
		?>
</body>
</html>
		<?php
		$body = (string) ob_get_clean();

		remove_filter( 'wp_theme_json_data_user', $filter );
		\WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();

		return array(
			'status'  => 200,
			'headers' => $headers + array( 'Content-Type' => 'text/html; charset=' . get_bloginfo( 'charset' ) ),
			'body'    => $body,
		);
	}

	/**
	 * Annotations of a wp_block, numbered from 1 in stored order. Orphaned ones name a block the pattern no longer has.
	 *
	 * @param int $id ID of the wp_block.
	 * @return list<array{number: int, target: string, text: string, orphaned: bool}>
	 */
	public static function annotations( int $id ): array {
		$names = self::names( (string) get_post_field( 'post_content', $id ) );
		$list  = array();
		foreach ( array_values( (array) get_post_meta( $id, '_leitvia_lab_annotations', true ) ) as $i => $entry ) {
			$target = is_array( $entry ) ? (string) ( $entry['target'] ?? '' ) : '';
			$list[] = array(
				'number'   => $i + 1,
				'target'   => $target,
				'text'     => is_array( $entry ) ? (string) ( $entry['text'] ?? '' ) : '',
				'orphaned' => ! in_array( $target, $names, true ),
			);
		}
		return $list;
	}

	/**
	 * Block names (metadata.name, "Rename" in the editor) in block markup, inner blocks included, without duplicates.
	 *
	 * @param string $content Block markup.
	 * @return list<string>
	 */
	public static function names( string $content ): array {
		$names = array();
		$walk  = static function ( array $blocks ) use ( &$walk, &$names ): void {
			foreach ( $blocks as $block ) {
				$name = $block['attrs']['metadata']['name'] ?? '';
				if ( is_string( $name ) && '' !== $name ) {
					$names[] = $name;
				}
				$walk( (array) ( $block['innerBlocks'] ?? array() ) );
			}
		};
		$walk( parse_blocks( $content ) );
		return array_values( array_unique( $names ) );
	}

	/**
	 * Loads build/axe-runner.js, which bundles axe-core. Only preview requests with axe=1 load it, never the site.
	 */
	private static function enqueue_axe(): void {
		$asset_file = dirname( LEITVIA_STUDIO_FILE ) . '/build/axe-runner.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script(
			'leitvia-studio-axe-runner',
			plugin_dir_url( LEITVIA_STUDIO_FILE ) . 'build/axe-runner.js',
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);
	}

	/**
	 * Block markup of a pattern, or the HTTP status when it cannot be shown.
	 *
	 * @param string $ref block:<ID> or pattern:<slug>.
	 * @return string|int
	 */
	private static function content( string $ref ) {
		if ( str_starts_with( $ref, 'pattern:' ) ) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( substr( $ref, 8 ) );
			return $pattern ? (string) $pattern['content'] : 404;
		}
		$post = preg_match( '/^block:([1-9]\d*)$/D', $ref, $match ) ? get_post( (int) $match[1] ) : null;
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return 404;
		}
		return current_user_can( 'read_post', $post->ID ) ? $post->post_content : 403;
	}

	/**
	 * Theme.json data for the user data: the style variation under style, the compiled settings of the token set
	 * under set.
	 *
	 * @param string $style Variation slug, the file name in the theme's styles/ folder or one level below; '' for none.
	 * @param string $set   Token set ID, '' for none.
	 * @return array{style?: array<string, mixed>, set?: array<string, mixed>}|\WP_Error
	 */
	private static function config( string $style, string $set ) {
		$config = array();
		if ( '' !== $style ) {
			$variation = self::variation( $style );
			if ( null === $variation ) {
				return new \WP_Error( 'leitvia_studio_unknown_style', __( 'Unknown style variation.', 'leitvia-studio' ) );
			}
			$config['style'] = $variation;
		}
		if ( '' !== $set ) {
			$settings = self::token_set( (int) $set );
			if ( is_wp_error( $settings ) ) {
				return $settings;
			}
			$config['set'] = $settings;
		}
		return $config;
	}

	/**
	 * Settings and styles of a style variation. Core does not expose the file name of a variation, so the file is found
	 * like core finds it: child theme first, then the parent theme.
	 *
	 * @param string $slug Variation slug.
	 * @return array<string, mixed>|null
	 */
	private static function variation( string $slug ): ?array {
		if ( ! preg_match( '/^[a-z0-9-]+$/D', $slug ) ) {
			return null;
		}
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			foreach ( array( "{$dir}/styles/{$slug}.json", ...(array) glob( "{$dir}/styles/*/{$slug}.json" ) ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) ) {
					$data = wp_json_file_decode( $file, array( 'associative' => true ) );
					return is_array( $data ) ? array_intersect_key( $data, array_flip( array( 'settings', 'styles' ) ) ) : null;
				}
			}
		}
		return null;
	}

	/**
	 * Compiled settings of a token set that passes the same checks as an activation.
	 *
	 * @param int $id Token set ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function token_set( int $id ) {
		$invalid = new \WP_Error( 'leitvia_studio_invalid_token_set', __( 'The token set does not exist or is invalid.', 'leitvia-studio' ) );
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new \WP_Error( 'leitvia_studio_forbidden_token_set', __( 'You are not allowed to preview token sets.', 'leitvia-studio' ), array( 'status' => 403 ) );
		}
		$doc = Compiler::document( $id );
		if ( is_wp_error( $doc ) || true !== Token_Sets::validate( (string) get_post_meta( $id, Token_Sets::META, true ) ) ) {
			return $invalid;
		}
		$settings = Dtcg::to_settings( $doc, leitvia_studio_contract() );
		return is_wp_error( $settings ) ? $invalid : $settings;
	}
}
