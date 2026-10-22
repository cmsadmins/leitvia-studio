import { RichText, useBlockProps } from '@wordpress/block-editor';

export default function save( {
	attributes: { prefix, value, suffix, label },
} ) {
	return (
		<div { ...useBlockProps.save() }>
			<p className="wp-block-leitvia-counter__number">
				{ prefix && (
					<span className="wp-block-leitvia-counter__prefix">
						{ prefix }
					</span>
				) }
				<span
					className="wp-block-leitvia-counter__value"
					// The counting copy of view.js never gets wider than the end value, so nothing moves.
					style={ { minInlineSize: `${ value.length }ch` } }
				>
					{ value }
				</span>
				{ suffix && (
					<span className="wp-block-leitvia-counter__suffix">
						{ suffix }
					</span>
				) }
			</p>
			<RichText.Content
				tagName="p"
				className="wp-block-leitvia-counter__label"
				value={ label }
			/>
		</div>
	);
}
