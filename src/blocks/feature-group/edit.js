import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	InspectorControls,
	RichText,
	store as blockEditorStore,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { MAX_PLANS } from './save';

const TEMPLATE = [
	[ 'leitvia/feature-item', { plan: 'Basic' } ],
	[ 'leitvia/feature-item', { plan: 'Pro' } ],
];

export default function Edit( { attributes, setAttributes, clientId } ) {
	const { heading, countLabel, filter, plans, labels } = attributes;
	const items = useSelect(
		( select ) => select( blockEditorStore ).getBlocks( clientId ),
		[ clientId ]
	);
	const { updateBlockAttributes } = useDispatch( blockEditorStore );
	// Count, plan list and each item's plan number are saved as text and attributes, so the front end needs no script.
	useEffect( () => {
		const found = [
			...new Set(
				items.map( ( item ) => item.attributes.plan ).filter( Boolean )
			),
		].slice( 0, MAX_PLANS );
		for ( const item of items ) {
			const planIndex = found.indexOf( item.attributes.plan ) + 1;
			if ( planIndex !== item.attributes.planIndex ) {
				updateBlockAttributes( item.clientId, { planIndex } );
			}
		}
		const next = {
			countLabel: sprintf(
				/* translators: %d: number of features. */
				_n(
					'%d feature',
					'%d features',
					items.length,
					'leitvia-studio'
				),
				items.length
			),
		};
		if ( found.join( '\n' ) !== plans.join( '\n' ) ) {
			next.plans = found;
		}
		if ( undefined === labels ) {
			next.labels = {
				legend: __( 'Show features of', 'leitvia-studio' ),
				/* translators: %d: number of features shown after filtering, singular. */
				one: __( '%d feature shown', 'leitvia-studio' ),
				/* translators: %d: number of features shown after filtering, plural. */
				other: __( '%d features shown', 'leitvia-studio' ),
			};
		}
		if ( next.countLabel !== countLabel || next.plans || next.labels ) {
			setAttributes( next );
		}
	} );
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Filter', 'leitvia-studio' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Filter by plan', 'leitvia-studio' ) }
						help={ __(
							'Visitors can hide features by plan badge. Needs at least two different badges.',
							'leitvia-studio'
						) }
						checked={ filter }
						onChange={ ( value ) =>
							setAttributes( { filter: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<section { ...useBlockProps() }>
				<h2 className="wp-block-leitvia-feature-group__heading">
					<RichText
						tagName="span"
						className="wp-block-leitvia-feature-group__title"
						aria-label={ __( 'Heading', 'leitvia-studio' ) }
						placeholder={ __( 'Features', 'leitvia-studio' ) }
						value={ heading }
						onChange={ ( value ) =>
							setAttributes( { heading: value } )
						}
						allowedFormats={ [] }
					/>{ ' ' }
					<span className="wp-block-leitvia-feature-group__count">
						{ countLabel }
					</span>
				</h2>
				<ul
					{ ...useInnerBlocksProps(
						{ className: 'wp-block-leitvia-feature-group__list' },
						{ template: TEMPLATE }
					) }
				/>
			</section>
		</>
	);
}
