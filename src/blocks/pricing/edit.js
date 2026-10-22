import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import {
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';

const PLAN = ( name, monthly, yearly, recommended = false ) => [
	'leitvia/pricing-plan',
	{ name, monthly, yearly, recommended },
];

const TEMPLATE = [
	PLAN( 'Basic', 9, 90 ),
	PLAN( 'Team', 29, 290, true ),
	[
		'core/details',
		{ summary: __( 'Compare all features', 'leitvia-studio' ) },
		[ [ 'leitvia/comparison-table' ] ],
	],
];

// Texts the visitor sees, filled in the site editor's language while they are empty (a legend and option labels are required).
const DEFAULTS = {
	legend: __( 'Billing period', 'leitvia-studio' ),
	monthlyLabel: __( 'Monthly', 'leitvia-studio' ),
	yearlyLabel: __( 'Yearly', 'leitvia-studio' ),
};

export default function Edit( { attributes, setAttributes } ) {
	const { groupId, taxNote, guarantee } = attributes;
	useEffect( () => {
		const missing = Object.fromEntries(
			Object.entries( DEFAULTS ).filter(
				( [ key ] ) => '' === String( attributes[ key ] ?? '' )
			)
		);
		// The radios of each pricing block need their own name, or two blocks on a page would share one group.
		if ( ! groupId ) {
			missing.groupId = Math.random().toString( 36 ).slice( 2, 8 );
		}
		if ( Object.keys( missing ).length ) {
			setAttributes( missing );
		}
	} );
	const text = ( key, props ) => (
		<RichText
			value={ attributes[ key ] }
			onChange={ ( value ) => setAttributes( { [ key ]: value } ) }
			allowedFormats={ [] }
			{ ...props }
		/>
	);
	return (
		<div { ...useBlockProps() }>
			<div className="wp-block-leitvia-pricing__period">
				{ text( 'legend', {
					tagName: 'span',
					'aria-label': __( 'Period switch label', 'leitvia-studio' ),
				} ) }
				{ text( 'monthlyLabel', {
					tagName: 'span',
					className: 'wp-block-leitvia-pricing__monthly',
					'aria-label': __( 'Monthly option', 'leitvia-studio' ),
				} ) }
				{ text( 'yearlyLabel', {
					tagName: 'span',
					className: 'wp-block-leitvia-pricing__yearly',
					'aria-label': __( 'Yearly option', 'leitvia-studio' ),
				} ) }
			</div>
			<div
				{ ...useInnerBlocksProps(
					{ className: 'wp-block-leitvia-pricing__plans' },
					{ template: TEMPLATE }
				) }
			/>
			{ text( 'taxNote', {
				tagName: 'p',
				className: 'wp-block-leitvia-pricing__tax',
				placeholder: __(
					'Tax note, for example: All prices include VAT.',
					'leitvia-studio'
				),
				value: taxNote,
			} ) }
			{ text( 'guarantee', {
				tagName: 'p',
				className: 'wp-block-leitvia-pricing__guarantee',
				placeholder: __(
					'Guarantee, for example: 30 days money back.',
					'leitvia-studio'
				),
				value: guarantee,
			} ) }
		</div>
	);
}
