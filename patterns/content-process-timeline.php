<?php
/**
 * Pattern "Timeline with key figure": a section mark and a heading with a key figure beside them, above four steps
 * on a line in the accent color, each with its own icon in a field framed in accent, a step label, a title and a
 * short text. Registered as leitvia-studio/content-process-timeline (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Timeline with key figure"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface-alt","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-alt-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|l"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group">
			<!-- wp:group {"metadata":{"name":"Section mark"},"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
			<div class="wp-block-group">
				<!-- wp:leitvia/icon {"icon":"activity","textColor":"accent","fontSize":"lead"} /-->
				<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
				<p class="is-style-eyebrow"><?php echo esc_html__( 'How it works', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:heading {"fontSize":"heading-l"} -->
			<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'How the move works', 'leitvia-studio' ); ?></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"metadata":{"name":"Key figure"},"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"bottom"}} -->
		<div class="wp-block-group">
			<!-- wp:paragraph {"style":{"typography":{"lineHeight":"1"}},"fontSize":"heading-m","fontFamily":"heading"} -->
			<p class="has-heading-font-family has-heading-m-font-size" style="line-height:1"><?php echo esc_html__( '4 weeks', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'from review to switch-over', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
	<!-- wp:group {"leitviaAnimation":"stagger","align":"wide","className":"is-style-steps-line","style":{"spacing":{"margin":{"top":"var:preset|spacing|l"},"blockGap":"var:preset|spacing|l"}},"layout":{"type":"grid","columnCount":4,"minimumColumnWidth":"12rem"}} -->
	<div class="wp-block-group alignwide is-style-steps-line" style="margin-top:var(--wp--preset--spacing--l)">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group">
			<!-- wp:leitvia/icon {"icon":"clipboard","className":"is-style-field-accent","textColor":"accent"} /-->
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'Step 1', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'Review', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'We check the site, the database, the mail and the domain settings at the old host.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group">
			<!-- wp:leitvia/icon {"icon":"copy","className":"is-style-field-accent","textColor":"accent"} /-->
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'Step 2', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'Copy to the new server', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Your data moves over while the old site keeps running.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group">
			<!-- wp:leitvia/icon {"icon":"eye","className":"is-style-field-accent","textColor":"accent"} /-->
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'Step 3', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'Test and approval', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'You check the copy at a test address before anything changes.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group">
			<!-- wp:leitvia/icon {"icon":"arrow-left-right","className":"is-style-field-accent","textColor":"accent"} /-->
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'Step 4', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'Switch over', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'The domain points to the new server, and we watch the first days closely.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Timeline with key figure', 'leitvia-studio' ),
	'description' => __( 'Section mark and heading with a key figure such as a price or a duration beside them, above four steps on a line, each with its own icon framed in the accent color, a step label, a title and a short text. Use for a project with a fixed course, such as a move, an onboarding or a launch.', 'leitvia-studio' ),
	'categories'  => array( 'text', 'services' ),
	'keywords'    => array( __( 'timeline', 'leitvia-studio' ), __( 'steps', 'leitvia-studio' ), __( 'process', 'leitvia-studio' ), __( 'project', 'leitvia-studio' ), __( 'icons', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
