<?php
/**
 * Pattern "Feature catalog with filter": an introduction beside a feature group that readers can filter by plan.
 * Registered as leitvia-studio/content-features-catalog (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

$leitvia_studio_plans  = array( __( 'Basic', 'leitvia-studio' ), __( 'Team', 'leitvia-studio' ), __( 'Business', 'leitvia-studio' ) );
$leitvia_studio_legend = __( 'Show features of', 'leitvia-studio' );
/* translators: %d: number of features shown. Kept as %d, the filter fills it in the browser. */
$leitvia_studio_one = __( '%d feature shown', 'leitvia-studio' );
/* translators: %d: number of features shown. Kept as %d, the filter fills it in the browser. */
$leitvia_studio_other    = __( '%d features shown', 'leitvia-studio' );
$leitvia_studio_features = array(
	array( 1, __( 'Two-factor login', 'leitvia-studio' ), __( 'Codes from an authenticator app for every account.', 'leitvia-studio' ) ),
	array( 1, __( 'Brute force protection', 'leitvia-studio' ), __( 'Blocks repeated failed logins without a captcha.', 'leitvia-studio' ) ),
	array( 2, __( 'Audit log', 'leitvia-studio' ), __( 'Every change with time and person, kept for a year.', 'leitvia-studio' ) ),
	array( 2, __( 'Encrypted backups', 'leitvia-studio' ), __( 'Daily, stored in the EU and kept for 30 days.', 'leitvia-studio' ) ),
	array( 3, __( 'Single sign-on', 'leitvia-studio' ), __( 'SAML and OpenID Connect for your own login.', 'leitvia-studio' ) ),
	array( 3, __( 'Review before publishing', 'leitvia-studio' ), __( 'A second person approves changes before they go live.', 'leitvia-studio' ) ),
);
$leitvia_studio_group    = array(
	'filter' => true,
	'plans'  => $leitvia_studio_plans,
	'labels' => array(
		'legend' => $leitvia_studio_legend,
		'one'    => $leitvia_studio_one,
		'other'  => $leitvia_studio_other,
	),
);

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Feature catalog with filter"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|l","bottom":"var:preset|spacing|l"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--l);padding-bottom:var(--wp--preset--spacing--l)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|l","left":"var:preset|spacing|xxl"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"33.33%"} -->
		<div class="wp-block-column" style="flex-basis:33.33%">
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php echo esc_html__( 'Security and teamwork', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"fontSize":"lead"} -->
			<p class="has-lead-font-size"><?php echo esc_html__( 'Every plan protects your site. Larger plans add the tools a team needs to work on it together.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Choose the plans you are comparing to see only what they include.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"width":"66.67%"} -->
		<div class="wp-block-column" style="flex-basis:66.67%">
			<!-- wp:leitvia/feature-group <?php echo wp_kses( serialize_block_attributes( $leitvia_studio_group ), array() ); ?> -->
			<section class="wp-block-leitvia-feature-group"><h2 class="wp-block-leitvia-feature-group__heading"><span class="wp-block-leitvia-feature-group__title"><?php echo esc_html__( 'Features by plan', 'leitvia-studio' ); ?></span> <span class="wp-block-leitvia-feature-group__count"><?php echo esc_html__( '6 features', 'leitvia-studio' ); ?></span></h2><fieldset class="wp-block-leitvia-feature-group__filter"><legend><?php echo esc_html( $leitvia_studio_legend ); ?></legend>
			<?php foreach ( $leitvia_studio_plans as $leitvia_studio_index => $leitvia_studio_name ) : ?>
				<label><input type="checkbox" value="<?php echo (int) $leitvia_studio_index + 1; ?>" checked/><?php echo esc_html( $leitvia_studio_name ); ?></label>
			<?php endforeach; ?>
			</fieldset><ul class="wp-block-leitvia-feature-group__list">
			<?php foreach ( $leitvia_studio_features as $leitvia_studio_feature ) : ?>
				<!-- wp:leitvia/feature-item {"planIndex":<?php echo (int) $leitvia_studio_feature[0]; ?>} -->
				<li class="wp-block-leitvia-feature-item" data-plan="<?php echo (int) $leitvia_studio_feature[0]; ?>"><h3 class="wp-block-leitvia-feature-item__title"><?php echo esc_html( $leitvia_studio_feature[1] ); ?></h3><p class="wp-block-leitvia-feature-item__text"><?php echo esc_html( $leitvia_studio_feature[2] ); ?></p><p class="wp-block-leitvia-feature-item__badge"><?php echo esc_html( $leitvia_studio_plans[ $leitvia_studio_feature[0] - 1 ] ); ?></p></li>
				<!-- /wp:leitvia/feature-item -->
			<?php endforeach; ?>
			</ul><p class="wp-block-leitvia-feature-group__status" role="status" data-one="<?php echo esc_attr( $leitvia_studio_one ); ?>" data-other="<?php echo esc_attr( $leitvia_studio_other ); ?>"></p></section>
			<!-- /wp:leitvia/feature-group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Feature catalog with filter', 'leitvia-studio' ),
	'description' => __( 'Introduction beside a list of six features, each with the plan that includes it, and a filter by plan that works without JavaScript. Use on a product or pricing page to answer what each plan contains.', 'leitvia-studio' ),
	'categories'  => array( 'featured', 'text' ),
	'keywords'    => array( __( 'features', 'leitvia-studio' ), __( 'catalog', 'leitvia-studio' ), __( 'filter', 'leitvia-studio' ), __( 'plans', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
