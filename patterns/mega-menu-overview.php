<?php
/**
 * Pattern "Mega menu with link columns and a card": four columns, each headed by an icon and a linked eyebrow title
 * above a fine line, with one-line entries and a link to all of its topic, a card with a fixed width on the right
 * (eyebrow, title, one sentence, button) and a footer row with a text and a link. Registered as
 * leitvia-studio/mega-menu-overview (Blocks::patterns()).
 *
 * Each entry is a row (group, flex, space between) with its link, so an agent can put a price beside it: insert a
 * paragraph after the link with bind, textColor ink-muted and fontSize meta. Column heads carry the class
 * leitvia-mega-menu-head: in the mobile overlay view.js turns each into a button that shows its column. The panel
 * takes the width of the header row (view.js) and fits 1440 x 900 without scrolling.
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"className":"leitvia-mega-menu-overview","style":{"spacing":{"blockGap":"var:preset|spacing|m"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
<div class="wp-block-group leitvia-mega-menu-overview">
	<!-- wp:columns {"style":{"spacing":{"blockGap":{"top":"var:preset|spacing|m","left":"var:preset|spacing|l"}}}} -->
	<div class="wp-block-columns">
		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}} -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"leitvia-mega-menu-head leitvia-mega-menu-head--eyebrow","style":{"border":{"bottom":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"bottom":"var:preset|spacing|xs"},"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group leitvia-mega-menu-head leitvia-mega-menu-head--eyebrow" style="border-bottom-color:var(--wp--preset--color--stroke);border-bottom-width:0.0625rem;padding-bottom:var(--wp--preset--spacing--xs)">
				<!-- wp:leitvia/icon {"icon":"layers","textColor":"accent","fontSize":"body"} /-->
				<!-- wp:heading {"fontSize":"meta"} -->
				<h2 class="wp-block-heading has-meta-font-size"><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Services', 'leitvia-studio' ); ?></a></h2>
				<!-- /wp:heading -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Strategy', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Design', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Development', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Support', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:paragraph {"className":"leitvia-mega-menu-all","fontSize":"meta"} -->
			<p class="leitvia-mega-menu-all has-meta-font-size"><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'All services', 'leitvia-studio' ); ?></a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}} -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"leitvia-mega-menu-head leitvia-mega-menu-head--eyebrow","style":{"border":{"bottom":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"bottom":"var:preset|spacing|xs"},"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group leitvia-mega-menu-head leitvia-mega-menu-head--eyebrow" style="border-bottom-color:var(--wp--preset--color--stroke);border-bottom-width:0.0625rem;padding-bottom:var(--wp--preset--spacing--xs)">
				<!-- wp:leitvia/icon {"icon":"briefcase","textColor":"accent","fontSize":"body"} /-->
				<!-- wp:heading {"fontSize":"meta"} -->
				<h2 class="wp-block-heading has-meta-font-size"><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Work', 'leitvia-studio' ); ?></a></h2>
				<!-- /wp:heading -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Case studies', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Clients', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Awards', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Process', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:paragraph {"className":"leitvia-mega-menu-all","fontSize":"meta"} -->
			<p class="leitvia-mega-menu-all has-meta-font-size"><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'All work', 'leitvia-studio' ); ?></a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}} -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"leitvia-mega-menu-head leitvia-mega-menu-head--eyebrow","style":{"border":{"bottom":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"bottom":"var:preset|spacing|xs"},"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group leitvia-mega-menu-head leitvia-mega-menu-head--eyebrow" style="border-bottom-color:var(--wp--preset--color--stroke);border-bottom-width:0.0625rem;padding-bottom:var(--wp--preset--spacing--xs)">
				<!-- wp:leitvia/icon {"icon":"users","textColor":"accent","fontSize":"body"} /-->
				<!-- wp:heading {"fontSize":"meta"} -->
				<h2 class="wp-block-heading has-meta-font-size"><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php echo esc_html__( 'Company', 'leitvia-studio' ); ?></a></h2>
				<!-- /wp:heading -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php echo esc_html__( 'About', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php echo esc_html__( 'Team', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php echo esc_html__( 'Careers', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php echo esc_html__( 'Journal', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:paragraph {"className":"leitvia-mega-menu-all","fontSize":"meta"} -->
			<p class="leitvia-mega-menu-all has-meta-font-size"><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php echo esc_html__( 'All about us', 'leitvia-studio' ); ?></a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|xs"}}} -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"leitvia-mega-menu-head leitvia-mega-menu-head--eyebrow","style":{"border":{"bottom":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"bottom":"var:preset|spacing|xs"},"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group leitvia-mega-menu-head leitvia-mega-menu-head--eyebrow" style="border-bottom-color:var(--wp--preset--color--stroke);border-bottom-width:0.0625rem;padding-bottom:var(--wp--preset--spacing--xs)">
				<!-- wp:leitvia/icon {"icon":"book-open","textColor":"accent","fontSize":"body"} /-->
				<!-- wp:heading {"fontSize":"meta"} -->
				<h2 class="wp-block-heading has-meta-font-size"><a href="<?php echo esc_url( home_url( '/journal/' ) ); ?>"><?php echo esc_html__( 'Resources', 'leitvia-studio' ); ?></a></h2>
				<!-- /wp:heading -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/journal/' ) ); ?>"><?php echo esc_html__( 'Guides', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/journal/' ) ); ?>"><?php echo esc_html__( 'Checklists', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/journal/' ) ); ?>"><?php echo esc_html__( 'Glossary', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"className":"leitvia-mega-menu-entry","style":{"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|s"}}},"layout":{"type":"flex","justifyContent":"space-between"}} -->
			<div class="wp-block-group leitvia-mega-menu-entry">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/journal/' ) ); ?>"><?php echo esc_html__( 'Help', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:paragraph {"className":"leitvia-mega-menu-all","fontSize":"meta"} -->
			<p class="leitvia-mega-menu-all has-meta-font-size"><a href="<?php echo esc_url( home_url( '/journal/' ) ); ?>"><?php echo esc_html__( 'All resources', 'leitvia-studio' ); ?></a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"is-style-card-accent","style":{"spacing":{"blockGap":"var:preset|spacing|s"}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group is-style-card-accent">
				<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
				<p class="is-style-eyebrow"><?php echo esc_html__( 'Recommended', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:heading {"fontSize":"lead"} -->
				<h2 class="wp-block-heading has-lead-font-size"><?php echo esc_html__( 'The complete package', 'leitvia-studio' ); ?></h2>
				<!-- /wp:heading -->
				<!-- wp:paragraph {"fontSize":"meta"} -->
				<p class="has-meta-font-size"><?php echo esc_html__( 'Strategy, design and development with one contact and one invoice.', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
				<!-- wp:buttons -->
				<div class="wp-block-buttons">
					<!-- wp:button -->
					<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'See the package', 'leitvia-studio' ); ?></a></div>
					<!-- /wp:button -->
				</div>
				<!-- /wp:buttons -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
	<!-- wp:group {"className":"leitvia-mega-menu-footer","style":{"border":{"top":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"top":"var:preset|spacing|s"},"blockGap":"var:preset|spacing|m"}},"fontSize":"meta","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group leitvia-mega-menu-footer has-meta-font-size" style="border-top-color:var(--wp--preset--color--stroke);border-top-width:0.0625rem;padding-top:var(--wp--preset--spacing--s)">
		<!-- wp:paragraph -->
		<p><?php echo esc_html__( 'Not sure which fits?', 'leitvia-studio' ); ?> <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php echo esc_html__( 'Talk to us', 'leitvia-studio' ); ?></a></p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph -->
		<p><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'All services and prices', 'leitvia-studio' ); ?></a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Mega menu with link columns and a card', 'leitvia-studio' ),
	'description' => __( 'Four columns headed by an icon and a linked title in capitals above a fine line, one-line entries and a link to all of each topic, a card with a fixed width on the right and a footer row, for the panel of a mega menu.', 'leitvia-studio' ),
	'categories'  => array( 'header' ),
	'keywords'    => array( __( 'mega menu', 'leitvia-studio' ), __( 'navigation', 'leitvia-studio' ), __( 'columns', 'leitvia-studio' ) ),
	'blockTypes'  => array( 'core/template-part/mega-menu' ),
	'content'     => (string) ob_get_clean(),
);
