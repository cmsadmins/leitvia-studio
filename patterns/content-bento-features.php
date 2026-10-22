<?php
/**
 * Pattern "Bento features": a heading with an introduction beside it and a bento grid of six feature tiles in
 * different sizes; the large tile ends with a short list. Registered as leitvia-studio/content-bento-features
 * (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

$leitvia_studio_tiles = array(
	array( '2x2', __( 'One system for every page', 'leitvia-studio' ), __( 'Type, color and space come from one set of tokens, so a new page looks right before anyone adjusts it. Change a token once and every page follows.', 'leitvia-studio' ), array( __( 'Colors checked for contrast in pairs', 'leitvia-studio' ), __( 'Type sizes that scale with the screen', 'leitvia-studio' ), __( 'Spacing in steps, never random values', 'leitvia-studio' ) ) ),
	array( '2x1', __( 'Accessible from the start', 'leitvia-studio' ), __( 'Every pattern works with a keyboard and a screen reader and keeps its contrast in every style.', 'leitvia-studio' ) ),
	array( '1x1', __( 'Fast on slow phones', 'leitvia-studio' ), __( 'No theme scripts and small styles per block.', 'leitvia-studio' ) ),
	array( '1x1', __( 'Private by default', 'leitvia-studio' ), __( 'Nothing loads from other hosts without consent.', 'leitvia-studio' ) ),
	array( '2x1', __( 'Content stays yours', 'leitvia-studio' ), __( 'Blocks save plain HTML. Switch the plugin off and every text, image and link is still there.', 'leitvia-studio' ) ),
	array( '2x1', __( 'Editors stay in control', 'leitvia-studio' ), __( 'Patterns, styles and checks live in the editor your team already knows.', 'leitvia-studio' ) ),
);

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Bento features"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:columns {"verticalAlignment":"bottom","align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|m","left":"var:preset|spacing|xxl"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-bottom">
		<!-- wp:column {"verticalAlignment":"bottom","width":"60%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:60%">
			<!-- wp:heading {"fontSize":"heading-l"} -->
			<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'Everything a site needs, nothing it has to carry', 'leitvia-studio' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"verticalAlignment":"bottom","width":"40%"} -->
		<div class="wp-block-column is-vertically-aligned-bottom" style="flex-basis:40%">
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Six things that stay true for every page you build, whichever style you choose.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
	<!-- wp:leitvia/bento {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|l"}}}} -->
	<div class="wp-block-leitvia-bento alignwide" style="margin-top:var(--wp--preset--spacing--l)">
	<?php foreach ( $leitvia_studio_tiles as $leitvia_studio_tile ) : ?>
		<!-- wp:leitvia/bento-item <?php echo '1x1' === $leitvia_studio_tile[0] ? '' : '{"size":"' . esc_attr( $leitvia_studio_tile[0] ) . '"} '; ?>-->
		<div class="wp-block-leitvia-bento-item is-size-<?php echo esc_attr( $leitvia_studio_tile[0] ); ?>">
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html( $leitvia_studio_tile[1] ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph -->
			<p><?php echo esc_html( $leitvia_studio_tile[2] ); ?></p>
			<!-- /wp:paragraph -->
			<?php if ( isset( $leitvia_studio_tile[3] ) ) : ?>
			<!-- wp:list -->
			<ul class="wp-block-list">
				<?php foreach ( $leitvia_studio_tile[3] as $leitvia_studio_item ) : ?>
				<!-- wp:list-item -->
				<li><?php echo esc_html( $leitvia_studio_item ); ?></li>
				<!-- /wp:list-item -->
				<?php endforeach; ?>
			</ul>
			<!-- /wp:list -->
			<?php endif; ?>
		</div>
		<!-- /wp:leitvia/bento-item -->
	<?php endforeach; ?>
	</div>
	<!-- /wp:leitvia/bento -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Bento features', 'leitvia-studio' ),
	'description' => __( 'Heading with a short introduction beside it and six feature tiles of different sizes in a bento grid. Use to show the strengths of a product or service at a glance.', 'leitvia-studio' ),
	'categories'  => array( 'featured', 'text' ),
	'keywords'    => array( __( 'bento', 'leitvia-studio' ), __( 'features', 'leitvia-studio' ), __( 'grid', 'leitvia-studio' ), __( 'tiles', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
