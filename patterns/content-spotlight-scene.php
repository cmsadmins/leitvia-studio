<?php
/**
 * Pattern "Scene with points": heading and introduction above a wide picture with numbered points, each opening a
 * name, one line and a link. Registered as leitvia-studio/content-spotlight-scene (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

$leitvia_studio_points = array(
	array( 22, 30, __( 'First piece', 'leitvia-studio' ), __( 'One line on what it is, what it is made of and what it costs.', 'leitvia-studio' ), __( 'See the first piece', 'leitvia-studio' ) ),
	array( 70, 26, __( 'Second piece', 'leitvia-studio' ), __( 'One line on what it is, what it is made of and what it costs.', 'leitvia-studio' ), __( 'See the second piece', 'leitvia-studio' ) ),
	array( 48, 62, __( 'Third piece', 'leitvia-studio' ), __( 'One line on what it is, what it is made of and what it costs.', 'leitvia-studio' ), __( 'See the third piece', 'leitvia-studio' ) ),
	array( 82, 78, __( 'Fourth piece', 'leitvia-studio' ), __( 'One line on what it is, what it is made of and what it costs.', 'leitvia-studio' ), __( 'See the fourth piece', 'leitvia-studio' ) ),
);

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Scene with points"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface-alt","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-alt-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"36rem","justifyContent":"left"}} -->
	<div class="wp-block-group alignwide">
	<!-- wp:heading {"fontSize":"heading-l"} -->
	<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'Everything in this picture is in the shop.', 'leitvia-studio' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"lead"} -->
	<p class="has-ink-muted-color has-text-color has-lead-font-size"><?php echo esc_html__( 'Open a number to see what it is and where to find it. On a phone the pieces follow the picture as a list.', 'leitvia-studio' ); ?></p>
	<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- wp:leitvia/spotlight {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|l"}}}} -->
	<div data-wp-interactive="leitvia/spotlight" data-wp-on--keydown="actions.keydown" class="wp-block-leitvia-spotlight alignwide" style="margin-top:var(--wp--preset--spacing--l)"><!-- wp:image {"sizeSlug":"full","linkDestination":"none"} -->
	<figure class="wp-block-image size-full"><img src="<?php echo esc_url( plugins_url( 'assets/images/landscape.webp', LEITVIA_STUDIO_FILE ) ); ?>" alt="<?php echo esc_attr__( 'Illustration of layered green hills below a terracotta sun', 'leitvia-studio' ); ?>"/></figure>
	<!-- /wp:image -->
	<?php foreach ( $leitvia_studio_points as list( $leitvia_studio_x, $leitvia_studio_y, $leitvia_studio_label, $leitvia_studio_text, $leitvia_studio_link ) ) : ?>
	<!-- wp:leitvia/spotlight-hotspot {"x":<?php echo (int) $leitvia_studio_x; ?>,"y":<?php echo (int) $leitvia_studio_y; ?>} -->
	<details style="--x:<?php echo (int) $leitvia_studio_x; ?>%;--y:<?php echo (int) $leitvia_studio_y; ?>%" data-wp-on--toggle="actions.toggle" class="wp-block-leitvia-spotlight-hotspot"><summary class="wp-block-leitvia-spotlight-hotspot__summary"><span class="wp-block-leitvia-spotlight-hotspot__label"><?php echo esc_html( $leitvia_studio_label ); ?></span></summary><div class="wp-block-leitvia-spotlight-hotspot__content"><!-- wp:paragraph -->
	<p><?php echo esc_html( $leitvia_studio_text ); ?> <a href="#"><?php echo esc_html( $leitvia_studio_link ); ?></a></p>
	<!-- /wp:paragraph --></div></details>
	<!-- /wp:leitvia/spotlight-hotspot -->
	<?php endforeach; ?>
	</div>
	<!-- /wp:leitvia/spotlight -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Scene with points', 'leitvia-studio' ),
	'description' => __( 'Heading and short introduction above a wide picture with four numbered points; each point opens a name, one line and a link. Use to show the products in a room, on a table or on a person, or the parts of a product in their place.', 'leitvia-studio' ),
	'categories'  => array( 'media' ),
	'keywords'    => array( __( 'spotlight', 'leitvia-studio' ), __( 'hotspot', 'leitvia-studio' ), __( 'shop the look', 'leitvia-studio' ), __( 'products', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
