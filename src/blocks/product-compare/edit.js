import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	PanelBody,
	Placeholder,
	SearchControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';

const MAX = 4;
// Products of the own site through the WooCommerce Store API; only published products are listed there.
const fetchProducts = ( query ) =>
	apiFetch( { path: addQueryArgs( '/wc/store/v1/products', query ) } ).catch(
		() => []
	);

export default function Edit( { attributes, setAttributes } ) {
	const { caption, products, features, highlight } = attributes;
	const [ search, setSearch ] = useState( '' );
	const [ found, setFound ] = useState( [] );
	const [ chosen, setChosen ] = useState( [] );
	const ids = products.join( ',' );

	useEffect( () => {
		fetchProducts( { search, per_page: 20 } ).then( setFound );
	}, [ search ] );
	useEffect( () => {
		if ( ids ) {
			fetchProducts( { include: ids, per_page: MAX } ).then( setChosen );
		} else {
			setChosen( [] );
		}
	}, [ ids ] );

	const toggle = ( id, on ) => {
		const next = on
			? [ ...products, id ]
			: products.filter( ( p ) => p !== id );
		setAttributes( {
			products: next,
			highlight: next.includes( highlight ) ? highlight : 0,
		} );
	};
	const list = [
		...chosen,
		...found.filter( ( p ) => ! products.includes( p.id ) ),
	];
	// Attributes of the chosen products: a global attribute by its taxonomy, a product attribute by its name.
	const attributeOptions = new Map(
		chosen.flatMap( ( p ) =>
			( p.attributes ?? [] ).map( ( a ) => [
				a.taxonomy || a.name,
				decodeEntities( a.name ),
			] )
		)
	);

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Table', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Caption', 'leitvia-studio' ) }
						help={ __(
							'Names what is compared, for screen readers and as the table title.',
							'leitvia-studio'
						) }
						value={ caption }
						onChange={ ( value ) =>
							setAttributes( { caption: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Products', 'leitvia-studio' ) }>
					<SearchControl
						__nextHasNoMarginBottom
						label={ __( 'Search products', 'leitvia-studio' ) }
						value={ search }
						onChange={ setSearch }
					/>
					{ list.map( ( p ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ p.id }
							label={ decodeEntities( p.name ) }
							checked={ products.includes( p.id ) }
							disabled={
								! products.includes( p.id ) &&
								products.length >= MAX
							}
							onChange={ ( on ) => toggle( p.id, on ) }
						/>
					) ) }
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Highlighted product', 'leitvia-studio' ) }
						value={ highlight }
						options={ [
							{ label: __( 'None', 'leitvia-studio' ), value: 0 },
							...chosen.map( ( p ) => ( {
								label: decodeEntities( p.name ),
								value: p.id,
							} ) ),
						] }
						onChange={ ( value ) =>
							setAttributes( { highlight: Number( value ) } )
						}
					/>
				</PanelBody>
				{ attributeOptions.size > 0 && (
					<PanelBody title={ __( 'Attributes', 'leitvia-studio' ) }>
						{ [ ...attributeOptions ].map( ( [ key, label ] ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ key }
								label={ label }
								checked={ features.includes( key ) }
								onChange={ ( on ) =>
									setAttributes( {
										features: on
											? [ ...features, key ]
											: features.filter(
													( f ) => f !== key
												),
									} )
								}
							/>
						) ) }
					</PanelBody>
				) }
			</InspectorControls>
			{ products.length < 2 ? (
				<Placeholder
					icon={ metadata.icon }
					label={ __( 'Product comparison', 'leitvia-studio' ) }
					instructions={ __(
						'Choose two to four products in the block settings.',
						'leitvia-studio'
					) }
				/>
			) : (
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
				/>
			) }
		</div>
	);
}
