<?php
/**
 * Pattern "Entries of a type": a heading above the latest entries of one post type as a ruled list, each with its linked
 * title and excerpt. Registered as leitvia-studio/teaser-type-entries (Blocks::patterns()). The query lists posts; an
 * overview of a post type of the content model takes this markup with postType set to that type and one paragraph per
 * field bound to leitvia/meta, written with leitvia/create-content (see the skill leitvia-page).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Entries of a type"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|l","bottom":"var:preset|spacing|l"}}},"backgroundColor":"surface","layout":{"type":"constrained","contentSize":"56rem","justifyContent":"left"}} -->
<div class="wp-block-group alignfull has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--l);padding-bottom:var(--wp--preset--spacing--l)">
	<!-- wp:heading {"fontSize":"heading-l"} -->
	<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'Upcoming dates', 'leitvia-studio' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:query {"query":{"perPage":12,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false},"style":{"spacing":{"margin":{"top":"var:preset|spacing|l"}}}} -->
	<div class="wp-block-query">
		<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|m"}}} -->
			<!-- wp:group {"style":{"border":{"top":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"top":"var:preset|spacing|m"},"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"constrained","justifyContent":"left"}} -->
			<div class="wp-block-group" style="border-top-color:var(--wp--preset--color--stroke);border-top-width:0.0625rem;padding-top:var(--wp--preset--spacing--m)">
				<!-- wp:post-title {"level":3,"isLink":true,"style":{"elements":{"link":{"color":{"text":"var:preset|color|ink"}}}},"fontSize":"heading-s"} /-->
				<!-- wp:post-excerpt {"excerptLength":30,"textColor":"ink-muted"} /-->
			</div>
			<!-- /wp:group -->
		<!-- /wp:post-template -->
		<!-- wp:query-no-results -->
			<!-- wp:paragraph -->
			<p><?php echo esc_html__( 'There are no entries yet.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
		<!-- /wp:query-no-results -->
	</div>
	<!-- /wp:query -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Entries of a type', 'leitvia-studio' ),
	'description' => __( 'Heading above the latest entries of one post type as a ruled list, each with its linked title and excerpt. Use as the overview of courses, events or other entries of the content model, with their fields in each row.', 'leitvia-studio' ),
	'categories'  => array( 'query' ),
	'keywords'    => array( __( 'entries', 'leitvia-studio' ), __( 'list', 'leitvia-studio' ), __( 'overview', 'leitvia-studio' ), __( 'content model', 'leitvia-studio' ) ),
	'blockTypes'  => array( 'core/query' ),
	'content'     => (string) ob_get_clean(),
);
