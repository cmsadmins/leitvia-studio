<?php
/**
 * Pattern "FAQ with structured data": a heading and a short introduction above five questions of the FAQ block, one
 * answer open at a time, with FAQPage structured data from the block (Blocks\Faq). Registered as
 * leitvia-studio/content-faq-schema (Blocks::patterns()). The theme pattern leitvia/content-faq has the same job
 * without structured data, because the theme runs without Studio.
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"FAQ with structured data"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|l","bottom":"var:preset|spacing|l"}}},"backgroundColor":"surface-alt","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-alt-background-color has-background" style="padding-top:var(--wp--preset--spacing--l);padding-bottom:var(--wp--preset--spacing--l)">
	<!-- wp:heading {"fontSize":"heading-m"} -->
	<h2 class="wp-block-heading has-heading-m-font-size"><?php echo esc_html__( 'Answers before you ask', 'leitvia-studio' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph {"textColor":"ink-muted"} -->
	<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'The questions people send us most often, each answered in a few sentences. Open one to read the answer.', 'leitvia-studio' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- wp:leitvia/faq {"exclusive":true,"leitviaAnimation":"fade"} -->
	<div class="wp-block-leitvia-faq"><!-- wp:details {"showContent":true} -->
	<details class="wp-block-details" open><summary><?php echo esc_html__( 'How soon will I hear back?', 'leitvia-studio' ); ?></summary><!-- wp:paragraph -->
	<p><?php echo esc_html__( 'Within one working day. Messages that arrive on a weekend are answered on Monday morning.', 'leitvia-studio' ); ?></p>
	<!-- /wp:paragraph --></details>
	<!-- /wp:details -->

	<!-- wp:details -->
	<details class="wp-block-details"><summary><?php echo esc_html__( 'What does it cost?', 'leitvia-studio' ); ?></summary><!-- wp:paragraph -->
	<p><?php echo esc_html__( 'Every price is listed with what it includes. There are no setup fees and nothing is added at the end.', 'leitvia-studio' ); ?></p>
	<!-- /wp:paragraph --></details>
	<!-- /wp:details -->

	<!-- wp:details -->
	<details class="wp-block-details"><summary><?php echo esc_html__( 'Can I change my mind?', 'leitvia-studio' ); ?></summary><!-- wp:paragraph -->
	<p><?php echo esc_html__( 'Yes. Tell us within 30 days and you get your money back, without having to give a reason.', 'leitvia-studio' ); ?></p>
	<!-- /wp:paragraph --></details>
	<!-- /wp:details -->

	<!-- wp:details -->
	<details class="wp-block-details"><summary><?php echo esc_html__( 'Where is my data stored?', 'leitvia-studio' ); ?></summary><!-- wp:paragraph -->
	<p><?php echo esc_html__( 'On servers in the European Union. We keep only what we need to answer you and delete it when it is no longer needed.', 'leitvia-studio' ); ?></p>
	<!-- /wp:paragraph --></details>
	<!-- /wp:details -->

	<!-- wp:details -->
	<details class="wp-block-details"><summary><?php echo esc_html__( 'Who answers my questions?', 'leitvia-studio' ); ?></summary><!-- wp:paragraph -->
	<p><?php echo esc_html__( 'The same small team that does the work. There is no call centre and no chatbot in between.', 'leitvia-studio' ); ?></p>
	<!-- /wp:paragraph --></details>
	<!-- /wp:details --></div>
	<!-- /wp:leitvia/faq -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'FAQ with structured data', 'leitvia-studio' ),
	'description' => __( 'Heading and short intro above five questions that open one at a time, with FAQ structured data for search engines. Use to answer the questions a site answers itself, on service, product, shop or contact pages.', 'leitvia-studio' ),
	'categories'  => array( 'text' ),
	'keywords'    => array( __( 'faq', 'leitvia-studio' ), __( 'questions', 'leitvia-studio' ), __( 'answers', 'leitvia-studio' ), __( 'structured data', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
