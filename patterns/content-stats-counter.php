<?php
/**
 * Pattern "Counted key figures": a heading and a short intro above four leitvia/counter blocks, four across on wide
 * screens and two by two on narrower ones. Shows the saved markup of the block, which keeps its values in the HTML.
 * Registered as leitvia-studio/content-stats-counter (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

$leitvia_studio_rows = array(
	array(
		array( '14', '', __( 'Years in practice', 'leitvia-studio' ) ),
		array( '120', '+', __( 'Sites launched', 'leitvia-studio' ) ),
	),
	array(
		array( '87', '%', __( 'Clients who return for a second project', 'leitvia-studio' ) ),
		array( '48', 'h', __( 'Typical reply time', 'leitvia-studio' ) ),
	),
);

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Counted key figures"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xxl","bottom":"var:preset|spacing|xxl"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--xxl);padding-bottom:var(--wp--preset--spacing--xxl)">
	<!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"44rem","justifyContent":"left"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:heading {"fontSize":"heading-m"} -->
		<h2 class="wp-block-heading has-heading-m-font-size"><?php echo esc_html__( 'What clients can count on', 'leitvia-studio' ); ?></h2>
		<!-- /wp:heading -->
		<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"lead"} -->
		<p class="has-ink-muted-color has-text-color has-lead-font-size"><?php echo esc_html__( 'Figures from our own project records, updated every January.', 'leitvia-studio' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- wp:group {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|l"},"blockGap":"var:preset|spacing|l"}},"layout":{"type":"grid","columnCount":2,"minimumColumnWidth":"21rem"}} -->
	<div class="wp-block-group alignwide" style="margin-top:var(--wp--preset--spacing--l)">
	<?php foreach ( $leitvia_studio_rows as $leitvia_studio_row ) : ?>
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|l"}},"layout":{"type":"grid","columnCount":2,"minimumColumnWidth":"9rem"}} -->
		<div class="wp-block-group">
		<?php foreach ( $leitvia_studio_row as $leitvia_studio_figure ) : ?>
			<!-- wp:leitvia/counter -->
			<div class="wp-block-leitvia-counter"><p class="wp-block-leitvia-counter__number"><span class="wp-block-leitvia-counter__value" style="min-inline-size:<?php echo (int) strlen( $leitvia_studio_figure[0] ); ?>ch"><?php echo esc_html( $leitvia_studio_figure[0] ); ?></span><?php echo $leitvia_studio_figure[1] ? '<span class="wp-block-leitvia-counter__suffix">' . esc_html( $leitvia_studio_figure[1] ) . '</span>' : ''; ?></p><p class="wp-block-leitvia-counter__label"><?php echo esc_html( $leitvia_studio_figure[2] ); ?></p></div>
			<!-- /wp:leitvia/counter -->
		<?php endforeach; ?>
		</div>
		<!-- /wp:group -->
	<?php endforeach; ?>
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Counted key figures', 'leitvia-studio' ),
	'description' => __( 'Heading and a short intro above four figures that count up as they scroll into view, four across on wide screens and two by two on narrower ones. Use to back up a claim with numbers where a little motion fits.', 'leitvia-studio' ),
	'categories'  => array( 'featured', 'text' ),
	'keywords'    => array( __( 'counter', 'leitvia-studio' ), __( 'figures', 'leitvia-studio' ), __( 'numbers', 'leitvia-studio' ), __( 'stats', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
