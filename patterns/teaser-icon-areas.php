<?php
/**
 * Pattern "Areas with icons": a section mark, a heading and an intro above four areas, each with its own icon in a
 * framed field, a title, a short text and a link, four across on wide screens. Registered as
 * leitvia-studio/teaser-icon-areas (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Areas with icons"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|s"}},"layout":{"type":"constrained","contentSize":"46rem","justifyContent":"left"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"metadata":{"name":"Section mark"},"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group">
			<!-- wp:leitvia/icon {"icon":"layers","textColor":"accent","fontSize":"lead"} /-->
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'Services', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:heading {"fontSize":"heading-l"} -->
		<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'Everything we look after, by area', 'leitvia-studio' ); ?></h2>
		<!-- /wp:heading -->
		<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"lead"} -->
		<p class="has-ink-muted-color has-text-color has-lead-font-size"><?php echo esc_html__( 'Four areas, each with its own symbol. Pick the one that fits, or ask us which one does.', 'leitvia-studio' ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- wp:group {"leitviaAnimation":"stagger","align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|l"},"blockGap":"var:preset|spacing|l"}},"layout":{"type":"grid","columnCount":4,"minimumColumnWidth":"12rem"}} -->
	<div class="wp-block-group alignwide" style="margin-top:var(--wp--preset--spacing--l)">
		<!-- wp:group {"style":{"border":{"top":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"top":"var:preset|spacing|m"},"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group" style="border-top-color:var(--wp--preset--color--stroke);border-top-width:0.0625rem;padding-top:var(--wp--preset--spacing--m)">
			<!-- wp:leitvia/icon {"icon":"wrench","className":"is-style-field","textColor":"accent"} /-->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'Maintenance', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Updates, backups and monitoring in a fixed rhythm, with a short report each month.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}}} -->
			<p style="font-weight:600"><a><?php echo esc_html__( 'More about maintenance', 'leitvia-studio' ); ?></a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"style":{"border":{"top":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"top":"var:preset|spacing|m"},"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group" style="border-top-color:var(--wp--preset--color--stroke);border-top-width:0.0625rem;padding-top:var(--wp--preset--spacing--m)">
			<!-- wp:leitvia/icon {"icon":"server","className":"is-style-field","textColor":"accent"} /-->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'Hosting', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Fast servers in a data centre near you, with daily backups and a contact who knows your site.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}}} -->
			<p style="font-weight:600"><a><?php echo esc_html__( 'More about hosting', 'leitvia-studio' ); ?></a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"style":{"border":{"top":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"top":"var:preset|spacing|m"},"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group" style="border-top-color:var(--wp--preset--color--stroke);border-top-width:0.0625rem;padding-top:var(--wp--preset--spacing--m)">
			<!-- wp:leitvia/icon {"icon":"shield-check","className":"is-style-field","textColor":"accent"} /-->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'Security', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Hardening, a review of who can log in and help within hours when something went wrong.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}}} -->
			<p style="font-weight:600"><a><?php echo esc_html__( 'More about security', 'leitvia-studio' ); ?></a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"style":{"border":{"top":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"top":"var:preset|spacing|m"},"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-group" style="border-top-color:var(--wp--preset--color--stroke);border-top-width:0.0625rem;padding-top:var(--wp--preset--spacing--m)">
			<!-- wp:leitvia/icon {"icon":"pen-tool","className":"is-style-field","textColor":"accent"} /-->
			<!-- wp:heading {"level":3,"fontSize":"heading-s"} -->
			<h3 class="wp-block-heading has-heading-s-font-size"><?php echo esc_html__( 'Design and relaunch', 'leitvia-studio' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'A new site from the first sketch to launch, and the care that keeps it working afterwards.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}}} -->
			<p style="font-weight:600"><a><?php echo esc_html__( 'More about design', 'leitvia-studio' ); ?></a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Areas with icons', 'leitvia-studio' ),
	'description' => __( 'Section mark, heading and intro above four areas, each with its own icon in a framed field, a title, a short text and a link to its page, up to four across. Use on a home or overview page to show the areas of an offer, with a symbol instead of a number.', 'leitvia-studio' ),
	'categories'  => array( 'services', 'featured' ),
	'keywords'    => array( __( 'areas', 'leitvia-studio' ), __( 'services', 'leitvia-studio' ), __( 'icons', 'leitvia-studio' ), __( 'overview', 'leitvia-studio' ), __( 'grid', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
