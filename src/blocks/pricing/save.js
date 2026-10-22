import {
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';

const NOTES = [ 'taxNote', 'guarantee' ];

export default function save( { attributes } ) {
	const { groupId, legend, monthlyLabel, yearlyLabel } = attributes;
	const name = `leitvia-pricing-${ groupId }`;
	return (
		<div { ...useBlockProps.save() }>
			<fieldset className="wp-block-leitvia-pricing__period">
				<RichText.Content tagName="legend" value={ legend } />
				{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control -- the input is nested in the label. */ }
				<label>
					<input type="radio" name={ name } value="monthly" checked />
					<RichText.Content
						tagName="span"
						className="wp-block-leitvia-pricing__monthly"
						value={ monthlyLabel }
					/>
				</label>
				{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control -- the input is nested in the label. */ }
				<label>
					<input type="radio" name={ name } value="yearly" />
					<RichText.Content
						tagName="span"
						className="wp-block-leitvia-pricing__yearly"
						value={ yearlyLabel }
					/>
				</label>
			</fieldset>
			<div
				{ ...useInnerBlocksProps.save( {
					className: 'wp-block-leitvia-pricing__plans',
				} ) }
			/>
			{ NOTES.filter( ( key ) => String( attributes[ key ] ?? '' ) ).map(
				( key ) => (
					<RichText.Content
						key={ key }
						tagName="p"
						className={ `wp-block-leitvia-pricing__${
							'taxNote' === key ? 'tax' : 'guarantee'
						}` }
						value={ attributes[ key ] }
					/>
				)
			) }
		</div>
	);
}
