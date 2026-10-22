<?php
/**
 * Pattern "Pricing plans with comparison": a heading, three plans, each with its features and a button, a monthly and yearly price switch and a
 * collapsible comparison table below them. Registered as leitvia-studio/offer-pricing-plans (Blocks::patterns()).
 *
 * @package LeitviaStudio
 */

defined( 'ABSPATH' ) || exit;

$leitvia_studio_plan    = __( 'Plan', 'leitvia-studio' );
$leitvia_studio_year    = __( 'Price per year', 'leitvia-studio' );
$leitvia_studio_seats   = __( 'Seats', 'leitvia-studio' );
$leitvia_studio_seat    = __( 'Per seat and year', 'leitvia-studio' );
$leitvia_studio_caption = __( 'Plans compared', 'leitvia-studio' );
$leitvia_studio_names   = array( __( 'Basic', 'leitvia-studio' ), __( 'Team', 'leitvia-studio' ), __( 'Business', 'leitvia-studio' ) );
// Name, price per year, seats, price per seat and year as the comparison table formats it (locale en-US, 2 decimals).
$leitvia_studio_rows   = array(
	array( $leitvia_studio_names[0], '€90', '1', '90.00 €' ),
	array( $leitvia_studio_names[1], '€290', '5', '58.00 €' ),
	array( $leitvia_studio_names[2], '€990', '25', '39.60 €' ),
);
$leitvia_studio_table  = array(
	'caption'      => $leitvia_studio_caption,
	'columns'      => array( $leitvia_studio_plan, $leitvia_studio_year, $leitvia_studio_seats ),
	'rows'         => array_map( static fn( $row ) => array_slice( $row, 0, 3 ), $leitvia_studio_rows ),
	'highlightRow' => 1,
	'computed'     => array(
		'label'    => $leitvia_studio_seat,
		'a'        => 1,
		'b'        => 2,
		'decimals' => 2,
		'unit'     => '€',
		'locale'   => 'en-US',
	),
	'source'       => __( 'Price list', 'leitvia-studio' ),
	'sourceDate'   => '2026-10-01',
	'labels'       => array(
		'source' => __( 'Source:', 'leitvia-studio' ),
		'asOf'   => __( 'as of', 'leitvia-studio' ),
	),
);
$leitvia_studio_cell   = static function ( string $label, string $value ): string {
	return '<span class="wp-block-leitvia-comparison-table__label">' . esc_html( $label ) . ': </span>' . esc_html( $value );
};
$leitvia_studio_labels = array( $leitvia_studio_plan, $leitvia_studio_year, $leitvia_studio_seats, $leitvia_studio_seat );

ob_start();
?>
<!-- wp:group {"metadata":{"name":"Pricing plans"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|l","bottom":"var:preset|spacing|l"}}},"backgroundColor":"surface-alt","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-surface-alt-background-color has-background" style="padding-top:var(--wp--preset--spacing--l);padding-bottom:var(--wp--preset--spacing--l)">
	<!-- wp:heading {"align":"wide","fontSize":"heading-l"} -->
	<h2 class="wp-block-heading alignwide has-heading-l-font-size"><?php echo esc_html__( 'Plans that grow with your team', 'leitvia-studio' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph {"align":"wide","textColor":"ink-muted"} -->
	<p class="alignwide has-ink-muted-color has-text-color"><?php echo esc_html__( 'Pay monthly or save two months with a yearly plan. You can change plans at any time.', 'leitvia-studio' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- wp:leitvia/pricing {"groupId":"plans","align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|m"}}}} -->
	<div class="wp-block-leitvia-pricing alignwide" style="margin-top:var(--wp--preset--spacing--m)"><fieldset class="wp-block-leitvia-pricing__period"><legend><?php echo esc_html__( 'Billing period', 'leitvia-studio' ); ?></legend><label><input type="radio" name="leitvia-pricing-plans" value="monthly" checked/><span class="wp-block-leitvia-pricing__monthly"><?php echo esc_html__( 'Monthly', 'leitvia-studio' ); ?></span></label><label><input type="radio" name="leitvia-pricing-plans" value="yearly"/><span class="wp-block-leitvia-pricing__yearly"><?php echo esc_html__( 'Yearly', 'leitvia-studio' ); ?></span></label></fieldset><div class="wp-block-leitvia-pricing__plans">
		<!-- wp:leitvia/pricing-plan {"monthly":9,"yearly":90} -->
		<article class="wp-block-leitvia-pricing-plan"><h3 class="wp-block-leitvia-pricing-plan__name"><?php echo esc_html( $leitvia_studio_names[0] ); ?></h3><p class="wp-block-leitvia-pricing-plan__price wp-block-leitvia-pricing-plan__price--monthly"><?php echo esc_html__( '€9 per month', 'leitvia-studio' ); ?></p><p class="wp-block-leitvia-pricing-plan__price wp-block-leitvia-pricing-plan__price--yearly"><?php echo esc_html__( '€90 per year, save €18', 'leitvia-studio' ); ?></p><div class="wp-block-leitvia-pricing-plan__content">
			<!-- wp:paragraph -->
			<p><?php echo esc_html__( 'For one person who runs a site alone.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:list -->
			<ul class="wp-block-list">
				<!-- wp:list-item -->
				<li><?php echo esc_html__( 'One site with every block', 'leitvia-studio' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php echo esc_html__( 'Accessibility checks while you edit', 'leitvia-studio' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php echo esc_html__( 'Updates for one year', 'leitvia-studio' ); ?></li>
				<!-- /wp:list-item -->
			</ul>
			<!-- /wp:list -->
			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-outline"} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#"><?php echo esc_html__( 'Start with Basic', 'leitvia-studio' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div></article>
		<!-- /wp:leitvia/pricing-plan -->
		<!-- wp:leitvia/pricing-plan {"monthly":29,"yearly":290,"recommended":true} -->
		<article class="wp-block-leitvia-pricing-plan is-recommended"><h3 class="wp-block-leitvia-pricing-plan__name"><?php echo esc_html( $leitvia_studio_names[1] ); ?></h3><p class="wp-block-leitvia-pricing-plan__badge"><?php echo esc_html__( 'Recommended', 'leitvia-studio' ); ?></p><p class="wp-block-leitvia-pricing-plan__price wp-block-leitvia-pricing-plan__price--monthly"><?php echo esc_html__( '€29 per month', 'leitvia-studio' ); ?></p><p class="wp-block-leitvia-pricing-plan__price wp-block-leitvia-pricing-plan__price--yearly"><?php echo esc_html__( '€290 per year, save €58', 'leitvia-studio' ); ?></p><div class="wp-block-leitvia-pricing-plan__content">
			<!-- wp:paragraph -->
			<p><?php echo esc_html__( 'For small teams that edit together.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:list -->
			<ul class="wp-block-list">
				<!-- wp:list-item -->
				<li><?php echo esc_html__( 'Everything in Basic', 'leitvia-studio' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php echo esc_html__( 'Five seats with roles', 'leitvia-studio' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php echo esc_html__( 'Review before publishing', 'leitvia-studio' ); ?></li>
				<!-- /wp:list-item -->
			</ul>
			<!-- /wp:list -->
			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button -->
				<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php echo esc_html__( 'Choose Team', 'leitvia-studio' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div></article>
		<!-- /wp:leitvia/pricing-plan -->
		<!-- wp:leitvia/pricing-plan {"monthly":99,"yearly":990} -->
		<article class="wp-block-leitvia-pricing-plan"><h3 class="wp-block-leitvia-pricing-plan__name"><?php echo esc_html( $leitvia_studio_names[2] ); ?></h3><p class="wp-block-leitvia-pricing-plan__price wp-block-leitvia-pricing-plan__price--monthly"><?php echo esc_html__( '€99 per month', 'leitvia-studio' ); ?></p><p class="wp-block-leitvia-pricing-plan__price wp-block-leitvia-pricing-plan__price--yearly"><?php echo esc_html__( '€990 per year, save €198', 'leitvia-studio' ); ?></p><div class="wp-block-leitvia-pricing-plan__content">
			<!-- wp:paragraph -->
			<p><?php echo esc_html__( 'For organisations with several sites.', 'leitvia-studio' ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:list -->
			<ul class="wp-block-list">
				<!-- wp:list-item -->
				<li><?php echo esc_html__( 'Everything in Team', 'leitvia-studio' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php echo esc_html__( 'Twenty five seats', 'leitvia-studio' ); ?></li>
				<!-- /wp:list-item -->
				<!-- wp:list-item -->
				<li><?php echo esc_html__( 'Single sign-on and audit log', 'leitvia-studio' ); ?></li>
				<!-- /wp:list-item -->
			</ul>
			<!-- /wp:list -->
			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-outline"} -->
				<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#"><?php echo esc_html__( 'Talk to us', 'leitvia-studio' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div></article>
		<!-- /wp:leitvia/pricing-plan -->
	</div><p class="wp-block-leitvia-pricing__tax"><?php echo esc_html__( 'All prices include VAT.', 'leitvia-studio' ); ?></p><p class="wp-block-leitvia-pricing__guarantee"><?php echo esc_html__( '30 days money back, no questions asked.', 'leitvia-studio' ); ?></p></div>
	<!-- /wp:leitvia/pricing -->
	<!-- wp:details {"align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|m"}}}} -->
	<details class="wp-block-details alignwide" style="margin-top:var(--wp--preset--spacing--m)"><summary><?php echo esc_html__( 'Compare all plans', 'leitvia-studio' ); ?></summary>
		<!-- wp:leitvia/comparison-table <?php echo serialize_block_attributes( $leitvia_studio_table ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block comment JSON, escaped by core. ?> -->
		<figure class="wp-block-leitvia-comparison-table" tabindex="0" role="region" aria-label="<?php echo esc_attr( $leitvia_studio_caption ); ?>"><table><caption><?php echo esc_html( $leitvia_studio_caption ); ?></caption><thead><tr>
		<?php foreach ( $leitvia_studio_labels as $leitvia_studio_label ) : ?>
			<th scope="col"><?php echo esc_html( $leitvia_studio_label ); ?></th>
		<?php endforeach; ?>
		</tr></thead><tbody>
		<?php foreach ( $leitvia_studio_rows as $leitvia_studio_index => $leitvia_studio_row ) : ?>
			<tr<?php echo 1 === $leitvia_studio_index ? ' class="is-highlighted"' : ''; ?>>
			<?php foreach ( $leitvia_studio_row as $leitvia_studio_col => $leitvia_studio_value ) : ?>
				<?php if ( 0 === $leitvia_studio_col ) : ?>
				<th scope="row" data-label="<?php echo esc_attr( $leitvia_studio_labels[0] ); ?>"><?php echo $leitvia_studio_cell( $leitvia_studio_labels[0], $leitvia_studio_value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></th>
				<?php else : ?>
				<td data-label="<?php echo esc_attr( $leitvia_studio_labels[ $leitvia_studio_col ] ); ?>"><?php echo $leitvia_studio_cell( $leitvia_studio_labels[ $leitvia_studio_col ], $leitvia_studio_value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></td>
				<?php endif; ?>
			<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
		</tbody></table><figcaption class="wp-block-leitvia-comparison-table__source"><?php echo esc_html( $leitvia_studio_table['labels']['source'] . ' ' . $leitvia_studio_table['source'] . ', ' . $leitvia_studio_table['labels']['asOf'] ); ?> <time datetime="2026-10-01">2026-10-01</time></figcaption></figure>
		<!-- /wp:leitvia/comparison-table -->
	</details>
	<!-- /wp:details -->
</div>
<!-- /wp:group -->
<?php
return array(
	'title'       => __( 'Pricing plans with comparison', 'leitvia-studio' ),
	'description' => __( 'Heading and introduction above three plans with a switch between monthly and yearly prices, the middle plan recommended, and a collapsible table that compares all plans. Use on a pricing page or as the offer section of a landing page.', 'leitvia-studio' ),
	'categories'  => array( 'call-to-action', 'text' ),
	'keywords'    => array( __( 'pricing', 'leitvia-studio' ), __( 'plans', 'leitvia-studio' ), __( 'comparison', 'leitvia-studio' ), __( 'offer', 'leitvia-studio' ) ),
	'content'     => (string) ob_get_clean(),
);
