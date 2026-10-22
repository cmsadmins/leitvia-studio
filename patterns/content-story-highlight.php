<?php
/**
 * Pattern "Story with highlight": a heading with highlighted words beside a few paragraphs that rise into view while
 * scrolling. Registered as leitvia-studio/content-story-highlight (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Story with highlight"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xxl","bottom":"var:preset|spacing|xxl"}}},"backgroundColor":"surface-alt","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-alt-background-color has-background" style="padding-top:var(--wp--preset--spacing--xxl);padding-bottom:var(--wp--preset--spacing--xxl)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|l","left":"var:preset|spacing|xxl"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"42%"} -->
		<div class="wp-block-column" style="flex-basis:42%">
			<!-- wp:heading {"fontSize":"heading-l"} -->
			<h2 class="wp-block-heading has-heading-l-font-size"><?php echo esc_html__( 'Most visitors decide in seconds.', 'leitvia-studio' ); ?> <mark class="leitvia-highlight is-marker is-accent"><?php echo esc_html__( 'Give them a reason to stay.', 'leitvia-studio' ); ?></mark></h2>
			<!-- /wp:heading -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"width":"58%"} -->
		<div class="wp-block-column" style="flex-basis:58%">
			<!-- wp:leitvia/reveal -->
			<div class="wp-block-leitvia-reveal is-effect-rise">
				<!-- wp:paragraph {"fontSize":"lead"} -->
				<p class="has-lead-font-size"><?php echo esc_html__( 'The first screen has one job: to tell people they are in the right place. Everything else can wait a scroll.', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'That is why we write the opening sentence before we choose a picture. It names the reader, the problem and what changes after they have read the page.', 'leitvia-studio' ); ?> <mark class="leitvia-highlight is-underline is-accent"><?php echo esc_html__( 'If the sentence works on its own, the design only has to support it.', 'leitvia-studio' ); ?></mark></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'The rest of the page follows the questions a visitor would ask next, in that order, with one answer per section.', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:leitvia/reveal -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Story with highlight', 'leitvia-studio' ),
	'description' => __( 'Heading with highlighted words beside three paragraphs that rise into view while scrolling, with one key sentence underlined. Use to make an argument where a few words must stand out.', 'leitvia-studio' ),
	'categories'  => array( 'text' ),
	'keywords'    => array( __( 'story', 'leitvia-studio' ), __( 'highlight', 'leitvia-studio' ), __( 'reveal', 'leitvia-studio' ), __( 'text', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
