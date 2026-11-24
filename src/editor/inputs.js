// Plain inputs for fields of the content model where the DataForm controls get in the way: a date with the date
// picker of the browser (the DataForm control offers past days as presets), a number with the decimal separator of
// the site language (8,50 in German), which a person types as they write prices.
import { useEffect, useState } from '@wordpress/element';
import { TextControl } from '@wordpress/components';

// Decimal separator of the site language, from the lang attribute of the admin page.
const separator = ( 1.5 ).toLocaleString(
	document.documentElement.lang || undefined
)[ 1 ];

const show = ( value ) =>
	value === '' || value === null || value === undefined
		? ''
		: String( value ).replace( '.', separator );

export function DateEdit( { data, field, onChange, hideLabelFromVision } ) {
	return (
		<TextControl
			__next40pxDefaultSize
			type="date"
			label={ field.label }
			help={ field.description }
			hideLabelFromVision={ hideLabelFromVision }
			value={ field.getValue( { item: data } ) ?? '' }
			onChange={ ( value ) =>
				onChange( field.setValue( { item: data, value } ) )
			}
		/>
	);
}

export function DecimalEdit( { data, field, onChange, hideLabelFromVision } ) {
	const value = field.getValue( { item: data } );
	const [ text, setText ] = useState( show( value ) );
	// A value changed elsewhere (undo, another control) replaces what is typed.
	useEffect( () => {
		setText( ( current ) =>
			Number( current.replace( separator, '.' ) ) === Number( value ) &&
			current !== ''
				? current
				: show( value )
		);
	}, [ value ] );
	return (
		<TextControl
			__next40pxDefaultSize
			inputMode="decimal"
			label={ field.label }
			help={ field.description }
			hideLabelFromVision={ hideLabelFromVision }
			value={ text }
			onChange={ ( typed ) => {
				setText( typed );
				const plain = typed.trim().replace( ',', '.' );
				if ( plain === '' ) {
					onChange( field.setValue( { item: data, value: '' } ) );
				} else if ( /^-?\d*\.?\d+$|^-?\d+\.$/.test( plain ) ) {
					onChange(
						field.setValue( { item: data, value: Number( plain ) } )
					);
				}
			} }
		/>
	);
}

// The value of a number field in the summary of the panel, in the format of the site language; empty stays empty.
export function DecimalView( { item, field } ) {
	const value = field.getValue( { item } );
	return value === '' || value === null || value === undefined
		? ''
		: Number( value ).toLocaleString(
				document.documentElement.lang || undefined,
				{ maximumFractionDigits: 2 }
			);
}
