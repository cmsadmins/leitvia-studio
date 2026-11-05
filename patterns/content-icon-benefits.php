<?php
/**
 * Pattern "Benefits with icons": a heading and a short introduction beside an icon list of five benefits, each item
 * with its own icon from the bundled set (Blocks\Icon). Registered as leitvia-studio/content-icon-benefits
 * (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Benefits with icons"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|l","left":"var:preset|spacing|xxl"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:heading {"fontSize":"heading-l"} -->
			<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'What every order includes', 'leitvia-studio' ); ?></h2>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"lead"} -->
			<p class="has-ink-muted-color has-text-color has-lead-font-size"><?php echo esc_html__( 'Five promises that hold for everything we sell, without small print.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:leitvia/icon-list {"leitviaAnimation":"stagger","fontSize":"lead"} -->
			<ul role="list" class="wp-block-leitvia-icon-list has-lead-font-size"><!-- wp:leitvia/icon-list-item {"icon":"truck"} -->
			<li class="wp-block-leitvia-icon-list-item"><span class="wp-block-leitvia-icon-list-item__text"><?php echo esc_html__( 'Delivery in two to four working days', 'leitvia-studio' ); ?></span></li>
			<!-- /wp:leitvia/icon-list-item -->

			<!-- wp:leitvia/icon-list-item {"icon":"recycle"} -->
			<li class="wp-block-leitvia-icon-list-item"><span class="wp-block-leitvia-icon-list-item__text"><?php echo esc_html__( 'Returns within 30 days, the label is in the parcel', 'leitvia-studio' ); ?></span></li>
			<!-- /wp:leitvia/icon-list-item -->

			<!-- wp:leitvia/icon-list-item {"icon":"credit-card"} -->
			<li class="wp-block-leitvia-icon-list-item"><span class="wp-block-leitvia-icon-list-item__text"><?php echo esc_html__( 'Payment by invoice, card or direct debit', 'leitvia-studio' ); ?></span></li>
			<!-- /wp:leitvia/icon-list-item -->

			<!-- wp:leitvia/icon-list-item {"icon":"leaf"} -->
			<li class="wp-block-leitvia-icon-list-item"><span class="wp-block-leitvia-icon-list-item__text"><?php echo esc_html__( 'Packed in paper and card, without plastic', 'leitvia-studio' ); ?></span></li>
			<!-- /wp:leitvia/icon-list-item -->

			<!-- wp:leitvia/icon-list-item {"icon":"headphones"} -->
			<li class="wp-block-leitvia-icon-list-item"><span class="wp-block-leitvia-icon-list-item__text"><?php echo esc_html__( 'Advice by phone and email on working days', 'leitvia-studio' ); ?></span></li>
			<!-- /wp:leitvia/icon-list-item --></ul>
			<!-- /wp:leitvia/icon-list -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Benefits with icons', 'leitvia-studio' ),
	'description' => __( 'Heading and short intro beside a list of five benefits, each with its own icon. Use for what every order, membership or service includes, such as delivery, returns and support.', 'leitvia-studio' ),
	'categories'  => array( 'text' ),
	'keywords'    => array( __( 'benefits', 'leitvia-studio' ), __( 'icons', 'leitvia-studio' ), __( 'list', 'leitvia-studio' ), __( 'promises', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
