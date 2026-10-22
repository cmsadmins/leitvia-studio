<?php
/**
 * Pattern "Mega menu panel": three columns with a heading and a list of links each, the starting content of a panel in
 * the template part area mega-menu. Registered as leitvia-studio/mega-menu-panel (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:columns -->
<div class="wp-block-columns">
	<!-- wp:column -->
	<div class="wp-block-column">
		<!-- wp:heading {"level":2,"fontSize":"body"} -->
		<h2 class="wp-block-heading has-body-font-size"><?php echo esc_html__( 'Services', 'leitvia-studio' ); ?></h2>
		<!-- /wp:heading -->
		<!-- wp:list -->
		<ul class="wp-block-list">
			<!-- wp:list-item -->
			<li><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Strategy', 'leitvia-studio' ); ?></a></li>
			<!-- /wp:list-item -->
			<!-- wp:list-item -->
			<li><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Design', 'leitvia-studio' ); ?></a></li>
			<!-- /wp:list-item -->
			<!-- wp:list-item -->
			<li><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php echo esc_html__( 'Development', 'leitvia-studio' ); ?></a></li>
			<!-- /wp:list-item -->
		</ul>
		<!-- /wp:list -->
	</div>
	<!-- /wp:column -->
	<!-- wp:column -->
	<div class="wp-block-column">
		<!-- wp:heading {"level":2,"fontSize":"body"} -->
		<h2 class="wp-block-heading has-body-font-size"><?php echo esc_html__( 'Work', 'leitvia-studio' ); ?></h2>
		<!-- /wp:heading -->
		<!-- wp:list -->
		<ul class="wp-block-list">
			<!-- wp:list-item -->
			<li><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Case studies', 'leitvia-studio' ); ?></a></li>
			<!-- /wp:list-item -->
			<!-- wp:list-item -->
			<li><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Clients', 'leitvia-studio' ); ?></a></li>
			<!-- /wp:list-item -->
			<!-- wp:list-item -->
			<li><a href="<?php echo esc_url( home_url( '/work/' ) ); ?>"><?php echo esc_html__( 'Awards', 'leitvia-studio' ); ?></a></li>
			<!-- /wp:list-item -->
		</ul>
		<!-- /wp:list -->
	</div>
	<!-- /wp:column -->
	<!-- wp:column -->
	<div class="wp-block-column">
		<!-- wp:heading {"level":2,"fontSize":"body"} -->
		<h2 class="wp-block-heading has-body-font-size"><?php echo esc_html__( 'Company', 'leitvia-studio' ); ?></h2>
		<!-- /wp:heading -->
		<!-- wp:list -->
		<ul class="wp-block-list">
			<!-- wp:list-item -->
			<li><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php echo esc_html__( 'About', 'leitvia-studio' ); ?></a></li>
			<!-- /wp:list-item -->
			<!-- wp:list-item -->
			<li><a href="<?php echo esc_url( home_url( '/journal/' ) ); ?>"><?php echo esc_html__( 'Journal', 'leitvia-studio' ); ?></a></li>
			<!-- /wp:list-item -->
			<!-- wp:list-item -->
			<li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php echo esc_html__( 'Contact', 'leitvia-studio' ); ?></a></li>
			<!-- /wp:list-item -->
		</ul>
		<!-- /wp:list -->
	</div>
	<!-- /wp:column -->
</div>
<!-- /wp:columns -->
<?php
return array(
	'title'       => __( 'Mega menu panel', 'leitvia-studio' ),
	'description' => __( 'Three columns with a heading and a list of links each, as the starting content of a mega menu panel.', 'leitvia-studio' ),
	'categories'  => array( 'header' ),
	'keywords'    => array( __( 'mega menu', 'leitvia-studio' ), __( 'navigation', 'leitvia-studio' ), __( 'links', 'leitvia-studio' ) ),
	'blockTypes'  => array( 'core/template-part/mega-menu' ),
	'content'     => (string) ob_get_clean(),
);
