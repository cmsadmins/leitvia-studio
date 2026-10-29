<?php
/**
 * Pattern "Annotated image": a heading with an introduction beside it on a tinted band and a wide image with numbered
 * points that each open a short note (spotlight block). Registered as leitvia-studio/content-spotlight
 * (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

$leitvia_studio_points = array(
	array( 72, 28, __( 'The sun', 'leitvia-studio' ), __( 'The accent of the palette, used once, so the eye finds it first.', 'leitvia-studio' ) ),
	array( 38, 52, __( 'The far hills', 'leitvia-studio' ), __( 'A muted middle tone that gives the picture its depth without drawing attention.', 'leitvia-studio' ) ),
	array( 18, 82, __( 'The front hill', 'leitvia-studio' ), __( 'The darkest shape, closest to the viewer, holds the composition together.', 'leitvia-studio' ) ),
);

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Annotated image"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface-alt","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-alt-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|m","left":"var:preset|spacing|xxl"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"55%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:55%">
			<!-- wp:heading {"fontSize":"heading-l"} -->
			<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'Every part of the picture has a reason', 'leitvia-studio' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"verticalAlignment":"bottom","width":"45%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:45%">
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Open a numbered point to read what it shows. On small screens the notes follow the image as a list.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
	<!-- wp:leitvia/spotlight {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|l"}}}} -->
	<div data-wp-interactive="leitvia/spotlight" data-wp-on--keydown="actions.keydown" class="wp-block-leitvia-spotlight alignwide" style="margin-top:var(--wp--preset--spacing--l)">
		<!-- wp:image {"aspectRatio":"3/2","scale":"cover","sizeSlug":"full","linkDestination":"none"} -->
		<figure class="wp-block-image size-full"><img src="<?php echo esc_url( plugins_url( 'assets/images/landscape.webp', LEITVIA_STUDIO_FILE ) ); ?>" alt="<?php echo esc_attr__( 'Illustration of layered green hills below a terracotta sun', 'leitvia-studio' ); ?>" style="aspect-ratio:3/2;object-fit:cover"/></figure>
		<!-- /wp:image -->
		<?php foreach ( $leitvia_studio_points as list( $leitvia_studio_x, $leitvia_studio_y, $leitvia_studio_label, $leitvia_studio_text ) ) : ?>
		<!-- wp:leitvia/spotlight-hotspot {"x":<?php echo (int) $leitvia_studio_x; ?>,"y":<?php echo (int) $leitvia_studio_y; ?>} -->
		<details style="--x:<?php echo (int) $leitvia_studio_x; ?>%;--y:<?php echo (int) $leitvia_studio_y; ?>%" data-wp-on--toggle="actions.toggle" class="wp-block-leitvia-spotlight-hotspot"><summary class="wp-block-leitvia-spotlight-hotspot__summary"><span class="wp-block-leitvia-spotlight-hotspot__label"><?php echo esc_html( $leitvia_studio_label ); ?></span></summary><div class="wp-block-leitvia-spotlight-hotspot__content"><!-- wp:paragraph -->
<p><?php echo esc_html( $leitvia_studio_text ); ?></p>
<!-- /wp:paragraph --></div></details>
		<!-- /wp:leitvia/spotlight-hotspot -->
		<?php endforeach; ?>
	</div>
	<!-- /wp:leitvia/spotlight -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Annotated image', 'leitvia-studio' ),
	'description' => __( 'Heading with a short introduction beside it on a tinted band and a wide image with numbered points that each open a note. Use to explain the parts of a product, a room, a building or a page.', 'leitvia-studio' ),
	'categories'  => array( 'media' ),
	'keywords'    => array( __( 'spotlight', 'leitvia-studio' ), __( 'hotspot', 'leitvia-studio' ), __( 'annotated', 'leitvia-studio' ), __( 'image', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
