<?php
/**
 * Pattern "Contact form": a heading and short invitation next to the contact form block (Blocks\Contact_Form).
 * Registered as leitvia-studio/cta-contact-form (Blocks::patterns()), so it exists only with Studio and the theme
 * depends on nothing.
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Contact form"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|l","left":"var:preset|spacing|xxl"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"42%"} -->
		<div class="wp-block-column" style="flex-basis:42%">
			<!-- wp:heading {"fontSize":"heading-l"} -->
			<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'Write to us', 'leitvia-studio' ); ?></h2>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"ink-muted"} -->
			<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Tell us what you need. We read every message and usually answer within one working day.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"width":"58%"} -->
		<div class="wp-block-column" style="flex-basis:58%">
			<!-- wp:leitvia/contact-form /-->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Contact form', 'leitvia-studio' ),
	'description' => __( 'Heading and short invitation next to a form with name, email and message that sends an email to the site owner. Use on a contact page or as the closing section of a service page.', 'leitvia-studio' ),
	'categories'  => array( 'call-to-action' ),
	'keywords'    => array( __( 'contact', 'leitvia-studio' ), __( 'form', 'leitvia-studio' ), __( 'email', 'leitvia-studio' ), __( 'message', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
