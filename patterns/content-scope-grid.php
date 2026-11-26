<?php
/**
 * Pattern "Scope grid with icons": a section mark, a heading and an intro on the left, six items in a grid with fine
 * lines on the right, each with its own icon, a title and a short text. Registered as leitvia-studio/content-scope-grid
 * (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Scope grid with icons"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|l","left":"var:preset|spacing|xl"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"34%","style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-column" style="flex-basis:34%">
			<!-- wp:group {"metadata":{"name":"Section mark"},"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
			<div class="wp-block-group">
				<!-- wp:leitvia/icon {"icon":"shield-check","textColor":"accent","fontSize":"lead"} /-->
				<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
				<p class="is-style-eyebrow"><?php echo esc_html__( 'Scope', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:heading {"fontSize":"heading-l"} -->
			<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'What every plan includes', 'leitvia-studio' ); ?></h2>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'All plans run on the same platform. They differ in storage, sites and resources, not in care.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:group {"leitviaAnimation":"stagger","className":"is-style-grid-net","style":{"spacing":{"blockGap":"0"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"12rem"}} -->
			<div class="wp-block-group is-style-grid-net">
				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}} -->
				<div class="wp-block-group">
					<!-- wp:leitvia/icon {"icon":"zap","textColor":"accent","fontSize":"heading-s"} /-->
					<!-- wp:heading {"level":3,"fontSize":"lead"} -->
					<h3 class="wp-block-heading has-lead-font-size"><?php echo esc_html__( 'Fast pages', 'leitvia-studio' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
					<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'A current web server with caching for every site.', 'leitvia-studio' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}} -->
				<div class="wp-block-group">
					<!-- wp:leitvia/icon {"icon":"lock","textColor":"accent","fontSize":"heading-s"} /-->
					<!-- wp:heading {"level":3,"fontSize":"lead"} -->
					<h3 class="wp-block-heading has-lead-font-size"><?php echo esc_html__( 'Encrypted connections', 'leitvia-studio' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
					<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Certificates included and renewed on their own.', 'leitvia-studio' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}} -->
				<div class="wp-block-group">
					<!-- wp:leitvia/icon {"icon":"archive","textColor":"accent","fontSize":"heading-s"} /-->
					<!-- wp:heading {"level":3,"fontSize":"lead"} -->
					<h3 class="wp-block-heading has-lead-font-size"><?php echo esc_html__( 'Daily backups', 'leitvia-studio' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
					<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Kept for thirty days and restored on request.', 'leitvia-studio' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}} -->
				<div class="wp-block-group">
					<!-- wp:leitvia/icon {"icon":"search","textColor":"accent","fontSize":"heading-s"} /-->
					<!-- wp:heading {"level":3,"fontSize":"lead"} -->
					<h3 class="wp-block-heading has-lead-font-size"><?php echo esc_html__( 'Security scans', 'leitvia-studio' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
					<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Every night, with findings sent to a person.', 'leitvia-studio' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}} -->
				<div class="wp-block-group">
					<!-- wp:leitvia/icon {"icon":"activity","textColor":"accent","fontSize":"heading-s"} /-->
					<!-- wp:heading {"level":3,"fontSize":"lead"} -->
					<h3 class="wp-block-heading has-lead-font-size"><?php echo esc_html__( 'Monitoring', 'leitvia-studio' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
					<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'We see an outage before your visitors do.', 'leitvia-studio' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
				<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}} -->
				<div class="wp-block-group">
					<!-- wp:leitvia/icon {"icon":"headset","textColor":"accent","fontSize":"heading-s"} /-->
					<!-- wp:heading {"level":3,"fontSize":"lead"} -->
					<h3 class="wp-block-heading has-lead-font-size"><?php echo esc_html__( 'Support by people', 'leitvia-studio' ); ?></h3>
					<!-- /wp:heading -->
					<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
					<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'A contact who knows your site, on working days.', 'leitvia-studio' ); ?></p>
					<!-- /wp:paragraph -->
				</div>
				<!-- /wp:group -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Scope grid with icons', 'leitvia-studio' ),
	'description' => __( 'Section mark, heading and intro on the left, six items in a grid with fine lines on the right, each with its own icon, a title and a short text. Use for what a plan or a service includes, where a plain list would look lost.', 'leitvia-studio' ),
	'categories'  => array( 'text', 'services' ),
	'keywords'    => array( __( 'scope', 'leitvia-studio' ), __( 'features', 'leitvia-studio' ), __( 'included', 'leitvia-studio' ), __( 'grid', 'leitvia-studio' ), __( 'icons', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
