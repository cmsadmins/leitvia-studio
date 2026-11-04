<?php
/**
 * Pattern "Share at the end of a post": one line that invites readers to pass the post on, above the share block with
 * its default services (Blocks\Share). Nothing loads from the networks before a click. Meant for the end of the post
 * content, so it travels with the post into any template. Registered as leitvia-studio/content-share-post
 * (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Share at the end of a post"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|l","bottom":"var:preset|spacing|l"}}},"backgroundColor":"surface","layout":{"type":"constrained","justifyContent":"left"}} -->
<div class="wp-block-group has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--l);padding-bottom:var(--wp--preset--spacing--l)">
	<!-- wp:paragraph {"textColor":"ink-muted"} -->
	<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Found this useful? Pass it on. The links load nothing from the networks until you choose one.', 'leitvia-studio' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- wp:leitvia/share /-->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Share at the end of a post', 'leitvia-studio' ),
	'description' => __( 'One line that invites readers to pass the post on, above links to share it on social networks, by email or by copying the link. Use once at the end of an article, a news post or a case study.', 'leitvia-studio' ),
	'categories'  => array( 'text' ),
	'keywords'    => array( __( 'share', 'leitvia-studio' ), __( 'social', 'leitvia-studio' ), __( 'post', 'leitvia-studio' ), __( 'article', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
