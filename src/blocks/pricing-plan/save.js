import {
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';

export default function save( {
	attributes: { name, monthlyText, yearlyText, recommended, badge },
} ) {
	return (
		<article
			{ ...useBlockProps.save( {
				className: recommended ? 'is-recommended' : undefined,
			} ) }
		>
			<RichText.Content
				tagName="h3"
				className="wp-block-leitvia-pricing-plan__name"
				value={ name }
			/>
			{ recommended && (
				<RichText.Content
					tagName="p"
					className="wp-block-leitvia-pricing-plan__badge"
					value={ badge }
				/>
			) }
			<p className="wp-block-leitvia-pricing-plan__price wp-block-leitvia-pricing-plan__price--monthly">
				{ monthlyText }
			</p>
			<p className="wp-block-leitvia-pricing-plan__price wp-block-leitvia-pricing-plan__price--yearly">
				{ yearlyText }
			</p>
			<div
				{ ...useInnerBlocksProps.save( {
					className: 'wp-block-leitvia-pricing-plan__content',
				} ) }
			/>
		</article>
	);
}
