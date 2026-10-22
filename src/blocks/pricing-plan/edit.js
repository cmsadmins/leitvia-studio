import { __, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import {
	InspectorControls,
	RichText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { prices } from '../pricing/savings.mjs';
import useSiteLocale from '../pricing/locale';

const TEMPLATE = [
	[ 'core/list', {}, [ [ 'core/list-item' ], [ 'core/list-item' ] ] ],
	[ 'core/buttons', {}, [ [ 'core/button' ] ] ],
];

/**
 * The two price lines as saved, for example "€9 per month" and "€90 per year, save €18".
 *
 * @param {Object} plan   Attributes monthly, yearly, currency.
 * @param {string} locale Site language.
 * @return {{monthlyText: string, yearlyText: string}} Texts.
 */
function texts( plan, locale ) {
	let amounts;
	try {
		amounts = prices( { ...plan, locale } );
	} catch {
		// An unknown currency code while typing; keep the last texts.
		return {};
	}
	return {
		monthlyText: sprintf(
			/* translators: %s: price, for example €9. */
			__( '%s per month', 'leitvia-studio' ),
			amounts.monthly
		),
		yearlyText: amounts.savings
			? sprintf(
					/* translators: 1: yearly price, 2: saving against twelve monthly prices. */
					__( '%1$s per year, save %2$s', 'leitvia-studio' ),
					amounts.yearly,
					amounts.savings
				)
			: sprintf(
					/* translators: %s: price, for example €90. */
					__( '%s per year', 'leitvia-studio' ),
					amounts.yearly
				),
	};
}

export default function Edit( { attributes, setAttributes } ) {
	const { name, monthly, yearly, currency, recommended, badge } = attributes;
	const locale = useSiteLocale();
	useEffect( () => {
		const next = texts( { monthly, yearly, currency }, locale );
		if (
			next.monthlyText &&
			( next.monthlyText !== attributes.monthlyText ||
				next.yearlyText !== attributes.yearlyText )
		) {
			setAttributes( next );
		}
		if ( '' === String( badge ?? '' ) ) {
			setAttributes( { badge: __( 'Recommended', 'leitvia-studio' ) } );
		}
	} );
	const amount = ( key, label ) => (
		<TextControl
			__next40pxDefaultSize
			type="number"
			min={ 0 }
			step="0.01"
			label={ label }
			value={ attributes[ key ] }
			onChange={ ( value ) =>
				setAttributes( { [ key ]: Number( value ) || 0 } )
			}
		/>
	);
	const blockProps = useBlockProps( {
		className: recommended ? 'is-recommended' : undefined,
	} );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Price', 'leitvia-studio' ) }>
					{ amount(
						'monthly',
						__( 'Price per month', 'leitvia-studio' )
					) }
					{ amount(
						'yearly',
						__( 'Price per year', 'leitvia-studio' )
					) }
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Currency', 'leitvia-studio' ) }
						help={ __(
							'Three-letter code such as EUR, USD or CHF. The saving is worked out when you change a price.',
							'leitvia-studio'
						) }
						value={ currency }
						onChange={ ( value ) =>
							setAttributes( {
								currency: value.trim().toUpperCase(),
							} )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Recommended plan', 'leitvia-studio' ) }
						help={ __(
							'Shows the badge text, so the highlight does not rely on colour alone.',
							'leitvia-studio'
						) }
						checked={ recommended }
						onChange={ ( value ) =>
							setAttributes( { recommended: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<article { ...blockProps }>
				<RichText
					tagName="h3"
					className="wp-block-leitvia-pricing-plan__name"
					aria-label={ __( 'Plan name', 'leitvia-studio' ) }
					placeholder={ __( 'Plan name', 'leitvia-studio' ) }
					value={ name }
					onChange={ ( value ) => setAttributes( { name: value } ) }
					allowedFormats={ [] }
				/>
				{ recommended && (
					<RichText
						tagName="p"
						className="wp-block-leitvia-pricing-plan__badge"
						aria-label={ __( 'Badge', 'leitvia-studio' ) }
						value={ badge }
						onChange={ ( value ) =>
							setAttributes( { badge: value } )
						}
						allowedFormats={ [] }
					/>
				) }
				<p className="wp-block-leitvia-pricing-plan__price">
					{ attributes.monthlyText }
				</p>
				<p className="wp-block-leitvia-pricing-plan__price">
					{ attributes.yearlyText }
				</p>
				<div
					{ ...useInnerBlocksProps(
						{ className: 'wp-block-leitvia-pricing-plan__content' },
						{ template: TEMPLATE }
					) }
				/>
			</article>
		</>
	);
}
