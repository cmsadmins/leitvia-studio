import {
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';

// The filter CSS (style.scss) covers this many plans.
export const MAX_PLANS = 8;

export default function save( {
	attributes: { heading, countLabel, filter, plans, labels },
} ) {
	// Set by the editor in the site editor's language when the block is inserted.
	const {
		legend = 'Show features of',
		one = '%d feature shown',
		other = '%d features shown',
	} = labels ?? {};
	const filtered = filter && plans.length > 1;
	return (
		<section { ...useBlockProps.save() }>
			<h2 className="wp-block-leitvia-feature-group__heading">
				<RichText.Content
					tagName="span"
					className="wp-block-leitvia-feature-group__title"
					value={ heading }
				/>{ ' ' }
				<span className="wp-block-leitvia-feature-group__count">
					{ countLabel }
				</span>
			</h2>
			{ filtered && (
				<fieldset className="wp-block-leitvia-feature-group__filter">
					<legend>{ legend }</legend>
					{ plans.slice( 0, MAX_PLANS ).map( ( plan, index ) => (
						// eslint-disable-next-line jsx-a11y/label-has-associated-control -- the input is nested in the label.
						<label key={ index }>
							<input
								type="checkbox"
								value={ index + 1 }
								checked
							/>
							{ plan }
						</label>
					) ) }
				</fieldset>
			) }
			<ul
				{ ...useInnerBlocksProps.save( {
					className: 'wp-block-leitvia-feature-group__list',
				} ) }
			/>
			{ filtered && (
				<p
					className="wp-block-leitvia-feature-group__status"
					role="status"
					data-one={ one }
					data-other={ other }
				></p>
			) }
		</section>
	);
}
