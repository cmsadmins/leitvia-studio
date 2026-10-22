<?php
/**
 * Pattern "Header with announcement": the announcement bar over the full width above the theme's default header, a
 * starting pattern of the template part area header. Registered as leitvia-studio/header-announcement
 * (Blocks::patterns()). The header itself stays the theme pattern leitvia/header-default, so it follows the theme; with
 * another theme the pattern stays out of the inserter.
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

ob_start();
?>
<!-- wp:leitvia/announcement-bar -->
<section class="wp-block-leitvia-announcement-bar alignfull" aria-label="<?php echo esc_attr__( 'Announcement', 'leitvia-studio' ); ?>"><p class="wp-block-leitvia-announcement-bar__text"><?php echo esc_html__( 'New opening hours from 1 November.', 'leitvia-studio' ); ?> <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php echo esc_html__( 'See the times', 'leitvia-studio' ); ?></a></p></section>
<!-- /wp:leitvia/announcement-bar -->

<!-- wp:pattern {"slug":"leitvia/header-default"} /-->
<?php
return array(
	'title'       => __( 'Header with announcement', 'leitvia-studio' ),
	'description' => __( 'A dismissable announcement bar over the full width above the default header with logo, title and navigation.', 'leitvia-studio' ),
	'categories'  => array( 'header' ),
	'keywords'    => array( __( 'announcement', 'leitvia-studio' ), __( 'banner', 'leitvia-studio' ), __( 'header', 'leitvia-studio' ) ),
	'blockTypes'  => array( 'core/template-part/header' ),
	'inserter'    => 'leitvia' === get_template(),
	'content'     => (string) ob_get_clean(),
);
