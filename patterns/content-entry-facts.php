<?php
/**
 * Pattern "Entry facts": the facts of one entry of the content model as a ruled list of label and value, rendered as a
 * description list (block style leitvia-facts, see Model\Entry_View::facts()). Each value is a paragraph bound to a
 * field with leitvia/meta; the keys entry_date, entry_price and entry_places stand for the fields of the type, and the
 * argument suffix adds the currency to the price. Registered as leitvia-studio/content-entry-facts.
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Entry facts"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|l","bottom":"var:preset|spacing|l"}}},"backgroundColor":"surface","layout":{"type":"constrained","justifyContent":"left"}} -->
<div class="wp-block-group has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--l);padding-bottom:var(--wp--preset--spacing--l)"><!-- wp:group {"className":"is-style-leitvia-facts","style":{"border":{"bottom":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"blockGap":"0"}},"layout":{"type":"constrained","justifyContent":"left"}} -->
<div class="wp-block-group is-style-leitvia-facts" style="border-bottom-color:var(--wp--preset--color--stroke);border-bottom-width:0.0625rem"><!-- wp:group {"style":{"border":{"top":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"top":"var:preset|spacing|s","bottom":"var:preset|spacing|s"},"blockGap":"var:preset|spacing|s"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group" style="border-top-color:var(--wp--preset--color--stroke);border-top-width:0.0625rem;padding-top:var(--wp--preset--spacing--s);padding-bottom:var(--wp--preset--spacing--s)"><!-- wp:paragraph {"textColor":"ink-muted"} -->
<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Date', 'leitvia-studio' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"leitvia/meta","args":{"key":"entry_date"}}}}} -->
<p></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"style":{"border":{"top":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"top":"var:preset|spacing|s","bottom":"var:preset|spacing|s"},"blockGap":"var:preset|spacing|s"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group" style="border-top-color:var(--wp--preset--color--stroke);border-top-width:0.0625rem;padding-top:var(--wp--preset--spacing--s);padding-bottom:var(--wp--preset--spacing--s)"><!-- wp:paragraph {"textColor":"ink-muted"} -->
<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Price', 'leitvia-studio' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"leitvia/meta","args":{"key":"entry_price","suffix":" €"}}}}} -->
<p></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"style":{"border":{"top":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"top":"var:preset|spacing|s","bottom":"var:preset|spacing|s"},"blockGap":"var:preset|spacing|s"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group" style="border-top-color:var(--wp--preset--color--stroke);border-top-width:0.0625rem;padding-top:var(--wp--preset--spacing--s);padding-bottom:var(--wp--preset--spacing--s)"><!-- wp:paragraph {"textColor":"ink-muted"} -->
<p class="has-ink-muted-color has-text-color"><?php echo esc_html__( 'Free places', 'leitvia-studio' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"leitvia/meta","args":{"key":"entry_places"}}}}} -->
<p></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Entry facts', 'leitvia-studio' ),
	'description' => __( 'The facts of one entry as a ruled list of label and value, such as date, price and free places, each value bound to a field of the content model. Use in the content of an entry page below the introduction.', 'leitvia-studio' ),
	'categories'  => array( 'text' ),
	'keywords'    => array( __( 'facts', 'leitvia-studio' ), __( 'fields', 'leitvia-studio' ), __( 'details', 'leitvia-studio' ), __( 'content model', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
