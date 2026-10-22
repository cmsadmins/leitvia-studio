import {
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';

// A fieldset with its legend, the inner blocks and, with a field name, the choices as radio buttons or checkboxes.
// A required radio group needs one choice; checkboxes have no group-wide "required" in HTML, so they stay optional.
export default function save( {
	attributes: { legend, name, type, required, options },
} ) {
	const { children, ...props } = useInnerBlocksProps.save(
		useBlockProps.save()
	);
	return (
		<fieldset { ...props }>
			<RichText.Content tagName="legend" value={ legend } />
			{ children }
			{ name && options.length > 0 && (
				<div className="wp-block-leitvia-wizard-step__options">
					{ options.map( ( option, index ) => (
						// The label wraps its input, so no id is needed (kses keeps no id on inputs).
						// eslint-disable-next-line jsx-a11y/label-has-associated-control
						<label key={ index }>
							<input
								type={ type }
								name={
									'checkbox' === type ? `${ name }[]` : name
								}
								value={ option.value || option.label }
								required={
									( required && 'radio' === type ) ||
									undefined
								}
							/>{ ' ' }
							{ option.label }
						</label>
					) ) }
				</div>
			) }
		</fieldset>
	);
}
