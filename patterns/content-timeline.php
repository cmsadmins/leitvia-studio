<?php
/**
 * Pattern "Timeline with introduction": a heading, a short introduction and a link on the left, five dated entries of
 * the timeline block on the right. Registered as leitvia-studio/content-timeline (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Timeline with introduction"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface-alt","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-alt-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|l","left":"var:preset|spacing|xxl"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"38%","style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-column" style="flex-basis:38%">
			<!-- wp:heading {"fontSize":"heading-l"} -->
			<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'How the new library came about', 'leitvia-studio' ); ?></h2>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"lead"} -->
			<p class="has-ink-muted-color has-text-color has-lead-font-size"><?php echo esc_html__( 'Nine years passed between the first sketch and the opening day. These are the dates that decided it.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph -->
			<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html__( 'Read the full story', 'leitvia-studio' ); ?></a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"width":"62%"} -->
		<div class="wp-block-column" style="flex-basis:62%">
			<!-- wp:leitvia/timeline -->
			<ol class="wp-block-leitvia-timeline"><!-- wp:leitvia/timeline-item -->
<li class="wp-block-leitvia-timeline-item"><time class="wp-block-leitvia-timeline-item__date" datetime="2017"><?php echo esc_html__( '2017', 'leitvia-studio' ); ?></time><h3 class="wp-block-leitvia-timeline-item__heading"><?php echo esc_html__( 'A first sketch', 'leitvia-studio' ); ?></h3><div class="wp-block-leitvia-timeline-item__content"><!-- wp:paragraph -->
<p><?php echo esc_html__( 'Two architects propose to turn the old post office into a library.', 'leitvia-studio' ); ?></p>
<!-- /wp:paragraph --></div></li>
<!-- /wp:leitvia/timeline-item -->

<!-- wp:leitvia/timeline-item -->
<li class="wp-block-leitvia-timeline-item"><time class="wp-block-leitvia-timeline-item__date" datetime="2019"><?php echo esc_html__( '2019', 'leitvia-studio' ); ?></time><h3 class="wp-block-leitvia-timeline-item__heading"><?php echo esc_html__( 'Residents have their say', 'leitvia-studio' ); ?></h3><div class="wp-block-leitvia-timeline-item__content"><!-- wp:paragraph -->
<p><?php echo esc_html__( 'Three public evenings collect more than 600 wishes, from late opening hours to a reading garden.', 'leitvia-studio' ); ?></p>
<!-- /wp:paragraph --></div></li>
<!-- /wp:leitvia/timeline-item -->

<!-- wp:leitvia/timeline-item -->
<li class="wp-block-leitvia-timeline-item"><time class="wp-block-leitvia-timeline-item__date" datetime="2021"><?php echo esc_html__( '2021', 'leitvia-studio' ); ?></time><h3 class="wp-block-leitvia-timeline-item__heading"><?php echo esc_html__( 'The council votes', 'leitvia-studio' ); ?></h3><div class="wp-block-leitvia-timeline-item__content"><!-- wp:paragraph -->
<p><?php echo esc_html__( 'A clear majority approves the budget and the design of the winning team.', 'leitvia-studio' ); ?></p>
<!-- /wp:paragraph --></div></li>
<!-- /wp:leitvia/timeline-item -->

<!-- wp:leitvia/timeline-item -->
<li class="wp-block-leitvia-timeline-item"><time class="wp-block-leitvia-timeline-item__date" datetime="2023"><?php echo esc_html__( '2023', 'leitvia-studio' ); ?></time><h3 class="wp-block-leitvia-timeline-item__heading"><?php echo esc_html__( 'Building starts', 'leitvia-studio' ); ?></h3><div class="wp-block-leitvia-timeline-item__content"><!-- wp:paragraph -->
<p><?php echo esc_html__( 'The listed facade stays; behind it the floors are rebuilt in timber.', 'leitvia-studio' ); ?></p>
<!-- /wp:paragraph --></div></li>
<!-- /wp:leitvia/timeline-item -->

<!-- wp:leitvia/timeline-item -->
<li class="wp-block-leitvia-timeline-item"><time class="wp-block-leitvia-timeline-item__date" datetime="2026"><?php echo esc_html__( '2026', 'leitvia-studio' ); ?></time><h3 class="wp-block-leitvia-timeline-item__heading"><?php echo esc_html__( 'Doors open', 'leitvia-studio' ); ?></h3><div class="wp-block-leitvia-timeline-item__content"><!-- wp:paragraph -->
<p><?php echo esc_html__( 'On the first weekend 4,000 visitors borrow 9,000 books.', 'leitvia-studio' ); ?></p>
<!-- /wp:paragraph --></div></li>
<!-- /wp:leitvia/timeline-item --></ol>
			<!-- /wp:leitvia/timeline -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Timeline with introduction', 'leitvia-studio' ),
	'description' => __( 'Heading, short introduction and a link on the left, five dated entries of a timeline on the right. Use to tell how a project, a decision or a story developed over time.', 'leitvia-studio' ),
	'categories'  => array( 'text' ),
	'keywords'    => array( __( 'timeline', 'leitvia-studio' ), __( 'history', 'leitvia-studio' ), __( 'dates', 'leitvia-studio' ), __( 'milestones', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
