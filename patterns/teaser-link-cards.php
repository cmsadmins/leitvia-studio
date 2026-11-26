<?php
/**
 * Pattern "Link cards with icons": a section mark and a heading above six framed cards, each one link with its own
 * icon, a title, a short text and an arrow. Registered as leitvia-studio/teaser-link-cards (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Link cards with icons"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|s"}},"layout":{"type":"constrained","contentSize":"46rem","justifyContent":"left"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:group {"metadata":{"name":"Section mark"},"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group">
			<!-- wp:leitvia/icon {"icon":"layers","textColor":"accent","fontSize":"lead"} /-->
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'Related', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
		<!-- wp:heading {"fontSize":"heading-l"} -->
		<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'More on this topic', 'leitvia-studio' ); ?></h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->
	<!-- wp:group {"leitviaAnimation":"stagger","align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|l"},"blockGap":"var:preset|spacing|s"}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"18rem"}} -->
	<div class="wp-block-group alignwide" style="margin-top:var(--wp--preset--spacing--l)">
		<!-- wp:group {"className":"is-style-link-card","style":{"spacing":{"blockGap":"var:preset|spacing|m"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group is-style-link-card">
			<!-- wp:leitvia/icon {"icon":"circle-dollar-sign","className":"is-style-field","textColor":"accent"} /-->
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xxs"}}} -->
			<div class="wp-block-group">
				<!-- wp:heading {"level":3,"fontSize":"lead"} -->
				<h3 class="wp-block-heading has-lead-font-size"><a><?php echo esc_html__( 'What does a plan cost?', 'leitvia-studio' ); ?></a></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Prices and what drives them, compared', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"className":"is-style-link-card","style":{"spacing":{"blockGap":"var:preset|spacing|m"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group is-style-link-card">
			<!-- wp:leitvia/icon {"icon":"shopping-bag","className":"is-style-field","textColor":"accent"} /-->
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xxs"}}} -->
			<div class="wp-block-group">
				<!-- wp:heading {"level":3,"fontSize":"lead"} -->
				<h3 class="wp-block-heading has-lead-font-size"><a><?php echo esc_html__( 'Care for online shops', 'leitvia-studio' ); ?></a></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'For shops with a checkout and payment providers', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"className":"is-style-link-card","style":{"spacing":{"blockGap":"var:preset|spacing|m"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group is-style-link-card">
			<!-- wp:leitvia/icon {"icon":"headset","className":"is-style-field","textColor":"accent"} /-->
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xxs"}}} -->
			<div class="wp-block-group">
				<!-- wp:heading {"level":3,"fontSize":"lead"} -->
				<h3 class="wp-block-heading has-lead-font-size"><a><?php echo esc_html__( 'Support by the hour', 'leitvia-studio' ); ?></a></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Help with tasks outside the plan', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"className":"is-style-link-card","style":{"spacing":{"blockGap":"var:preset|spacing|m"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group is-style-link-card">
			<!-- wp:leitvia/icon {"icon":"list-checks","className":"is-style-field","textColor":"accent"} /-->
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xxs"}}} -->
			<div class="wp-block-group">
				<!-- wp:heading {"level":3,"fontSize":"lead"} -->
				<h3 class="wp-block-heading has-lead-font-size"><a><?php echo esc_html__( 'Update check', 'leitvia-studio' ); ?></a></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Find out what breaks before a major update', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"className":"is-style-link-card","style":{"spacing":{"blockGap":"var:preset|spacing|m"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group is-style-link-card">
			<!-- wp:leitvia/icon {"icon":"plug","className":"is-style-field","textColor":"accent"} /-->
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xxs"}}} -->
			<div class="wp-block-group">
				<!-- wp:heading {"level":3,"fontSize":"lead"} -->
				<h3 class="wp-block-heading has-lead-font-size"><a><?php echo esc_html__( 'Plugin for your dashboard', 'leitvia-studio' ); ?></a></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Status, tickets and reports in one place', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
		<!-- wp:group {"className":"is-style-link-card","style":{"spacing":{"blockGap":"var:preset|spacing|m"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"center"}} -->
		<div class="wp-block-group is-style-link-card">
			<!-- wp:leitvia/icon {"icon":"info","className":"is-style-field","textColor":"accent"} /-->
			<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|xxs"}}} -->
			<div class="wp-block-group">
				<!-- wp:heading {"level":3,"fontSize":"lead"} -->
				<h3 class="wp-block-heading has-lead-font-size"><a><?php echo esc_html__( 'What changes this year', 'leitvia-studio' ); ?></a></h3>
				<!-- /wp:heading -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'The news site owners should know', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Link cards with icons', 'leitvia-studio' ),
	'description' => __( 'Section mark and heading above six framed cards, three across, each one link with its own icon, a title, a short text and an arrow. Use at the end of a page for related pages and guides, instead of a numbered list of links.', 'leitvia-studio' ),
	'categories'  => array( 'featured', 'text' ),
	'keywords'    => array( __( 'links', 'leitvia-studio' ), __( 'related', 'leitvia-studio' ), __( 'cards', 'leitvia-studio' ), __( 'icons', 'leitvia-studio' ), __( 'more', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
