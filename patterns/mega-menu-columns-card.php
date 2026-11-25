<?php
/**
 * Pattern "Mega menu with a highlighted card": the columns of mega-menu-columns and, as the last column, a card with an accent border, icon, text and button for the offer that stands out. Registered as leitvia-studio/mega-menu-columns-card (Blocks::patterns()).
 *
 * Column heads carry the class leitvia-mega-menu-head: in the mobile overlay view.js of the mega menu turns each into
 * a button that shows its column, a second level of the accordion. An agent fills the pattern with
 * leitvia/update-template-part (part mega-menu-<name>, pattern and edits): remove or repeat a column for two to four.
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|m"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
<div class="wp-block-group">
	<!-- wp:columns {"style":{"spacing":{"blockGap":{"top":"var:preset|spacing|m","left":"var:preset|spacing|l"}}}} -->
	<div class="wp-block-columns">
		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"leitvia-mega-menu-head","style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group leitvia-mega-menu-head">
				<!-- wp:leitvia/icon {"icon":"layers","textColor":"accent","fontSize":"lead"} /-->
				<!-- wp:heading {"fontSize":"body"} -->
				<h2 class="wp-block-heading has-body-font-size"><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Services', 'leitvia-studio' ); ?></a></h2>
				<!-- /wp:heading -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Strategy', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Goals, audience and a plan for the next twelve months.', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Design', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Brand, layout and every page in one style.', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Development', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Built, tested and handed over with a short guide.', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"leitvia-mega-menu-head","style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group leitvia-mega-menu-head">
				<!-- wp:leitvia/icon {"icon":"briefcase","textColor":"accent","fontSize":"lead"} /-->
				<!-- wp:heading {"fontSize":"body"} -->
				<h2 class="wp-block-heading has-body-font-size"><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Work', 'leitvia-studio' ); ?></a></h2>
				<!-- /wp:heading -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Case studies', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Projects from the first sketch to the launch.', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Clients', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Who we work for, from start-ups to public bodies.', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"style":{"spacing":{"blockGap":"var:preset|spacing|s"}}} -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"leitvia-mega-menu-head","style":{"spacing":{"blockGap":"var:preset|spacing|xs"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
			<div class="wp-block-group leitvia-mega-menu-head">
				<!-- wp:leitvia/icon {"icon":"users","textColor":"accent","fontSize":"lead"} /-->
				<!-- wp:heading {"fontSize":"body"} -->
				<h2 class="wp-block-heading has-body-font-size"><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php echo esc_html__( 'Company', 'leitvia-studio' ); ?></a></h2>
				<!-- /wp:heading -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php echo esc_html__( 'About', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'The team, how we work and what we stand for.', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
			<!-- wp:group {"style":{"spacing":{"blockGap":"0"}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group">
				<!-- wp:paragraph -->
				<p><a href="<?php echo esc_url( home_url( '/journal/' ) ); ?>"><?php echo esc_html__( 'Journal', 'leitvia-studio' ); ?></a></p>
				<!-- /wp:paragraph -->
				<!-- wp:paragraph {"textColor":"ink-muted","fontSize":"meta"} -->
				<p class="has-ink-muted-color has-text-color has-meta-font-size"><?php echo esc_html__( 'Notes from projects and what we learn.', 'leitvia-studio' ); ?></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:group {"className":"is-style-card-accent","style":{"spacing":{"blockGap":"var:preset|spacing|s"}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group is-style-card-accent">
				<!-- wp:leitvia/icon {"icon":"package","textColor":"accent","fontSize":"heading-m"} /-->
				<!-- wp:heading {"fontSize":"lead"} -->
				<h2 class="wp-block-heading has-lead-font-size"><?php echo esc_html__( 'The complete package', 'leitvia-studio' ); ?></h2>
				<!-- /wp:heading -->
				<!-- wp:paragraph -->
				<p><?php echo esc_html__( 'Strategy, design and development with one contact and one invoice.', 'leitvia-studio' ); ?></p>
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
	<!-- wp:group {"style":{"border":{"top":{"color":"var:preset|color|stroke","width":"0.0625rem"}},"spacing":{"padding":{"top":"var:preset|spacing|s"},"blockGap":"var:preset|spacing|m"}},"layout":{"type":"flex","flexWrap":"wrap"}} -->
	<div class="wp-block-group" style="border-top-color:var(--wp--preset--color--stroke);border-top-width:0.0625rem;padding-top:var(--wp--preset--spacing--s)">
		<!-- wp:paragraph -->
		<p><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'All services', 'leitvia-studio' ); ?></a></p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph -->
		<p><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php echo esc_html__( 'Talk to us', 'leitvia-studio' ); ?></a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Mega menu with a highlighted card', 'leitvia-studio' ),
	'description' => __( 'Three columns headed by an icon and a linked title and a card with an accent border for the offer that stands out, for the panel of a mega menu.', 'leitvia-studio' ),
	'categories'  => array( 'header' ),
	'keywords'    => array( __( 'mega menu', 'leitvia-studio' ), __( 'navigation', 'leitvia-studio' ), __( 'card', 'leitvia-studio' ) ),
	'blockTypes'  => array( 'core/template-part/mega-menu' ),
	'content'     => (string) ob_get_clean(),
);
