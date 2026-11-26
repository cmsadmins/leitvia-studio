<?php
/**
 * Pattern "Steps with icons": a section mark, a heading and three steps, each with its own icon in a filled field,
 * a line to the next step, a step label, a title and a short text, and a closing line with a link. Registered as
 * leitvia-studio/content-process-icons (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Steps with icons"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|s"}},"layout":{"type":"constrained","contentSize":"46rem","justifyContent":"left"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"metadata":{"name":"Section mark"},"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group">
			<!-- wp:leitvia/icon {"icon":"activity","textColor":"accent","fontSize":"lead"} /-->
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'How it works', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:heading {"fontSize":"heading-l"} -->
		<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'What happens next', 'leitvia-studio' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->
	<!-- wp:group {"leitviaAnimation":"stagger","align":"wide","className":"is-style-steps-line","style":{"spacing":{"margin":{"top":"var:preset|spacing|l"},"blockGap":"var:preset|spacing|l"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"14rem"}} -->
	<div class="wp-block-group alignwide is-style-steps-line" style="margin-top:var(--wp--preset--spacing--l)">
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group">
			<!-- wp:leitvia/icon {"icon":"list-checks","className":"is-style-field-filled"} /-->
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'Step 1', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'Choose a service', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Compare the plans in the table or the cards and pick the one that fits.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group">
			<!-- wp:leitvia/icon {"icon":"send","className":"is-style-field-filled"} /-->
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'Step 2', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'Order or ask', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Order a plan with a fixed price right away, or ask for an offer.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group">
			<!-- wp:leitvia/icon {"icon":"phone","className":"is-style-field-filled"} /-->
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'Step 3', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'We get in touch', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Within one working day, with the next steps and a date.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
	<!-- wp:group {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|l"}}},"layout":{"type":"constrained","contentSize":"46rem","justifyContent":"left"}} -->
	<div class="wp-block-group alignwide" style="margin-top:var(--wp--preset--spacing--l)">
		<!-- wp:paragraph -->
		<p><?php echo esc_html__( 'Not sure which one fits? We look at your site and recommend a plan.', 'leitvia-studio' ); ?> <a><?php echo esc_html__( 'Ask for free advice', 'leitvia-studio' ); ?></a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Steps with icons', 'leitvia-studio' ),
	'description' => __( 'Section mark and heading above three steps, each with its own icon in a filled field, a line to the next step, a step label, a title and a short text, and a closing line with a link. Use to show what happens after an order or an inquiry.', 'leitvia-studio' ),
	'categories'  => array( 'text', 'services' ),
	'keywords'    => array( __( 'steps', 'leitvia-studio' ), __( 'process', 'leitvia-studio' ), __( 'how it works', 'leitvia-studio' ), __( 'icons', 'leitvia-studio' ), __( 'next steps', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
