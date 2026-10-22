import { __, sprintf } from '@wordpress/i18n';
import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';

const number = ( value ) => {
	const parsed = parseFloat( value );
	return Number.isFinite( parsed ) ? parsed : 0;
};

// The editor shows no map: loading tiles here would contact the tile server without anyone agreeing.
export default function Edit( { attributes, setAttributes } ) {
	const { heading, lat, lng, zoom, markers, height, linkText } = attributes;
	const setMarker = ( index, changes ) =>
		setAttributes( {
			markers: markers.map( ( marker, i ) =>
				i === index ? { ...marker, ...changes } : marker
			),
		} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Map', 'leitvia-studio' ) }>
					<TextControl
						__next40pxDefaultSize
						type="number"
						step="any"
						label={ __( 'Center latitude', 'leitvia-studio' ) }
						value={ lat }
						onChange={ ( value ) =>
							setAttributes( { lat: number( value ) } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						type="number"
						step="any"
						label={ __( 'Center longitude', 'leitvia-studio' ) }
						value={ lng }
						onChange={ ( value ) =>
							setAttributes( { lng: number( value ) } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 1 }
						max={ 19 }
						label={ __( 'Zoom level', 'leitvia-studio' ) }
						value={ zoom }
						onChange={ ( value ) =>
							setAttributes( {
								zoom: Math.min(
									19,
									Math.max( 1, parseInt( value, 10 ) || 1 )
								),
							} )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Height', 'leitvia-studio' ) }
						value={ height }
						options={ [
							{
								label: __( 'Small', 'leitvia-studio' ),
								value: 'small',
							},
							{
								label: __( 'Medium', 'leitvia-studio' ),
								value: 'medium',
							},
							{
								label: __( 'Large', 'leitvia-studio' ),
								value: 'large',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { height: value } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Link text', 'leitvia-studio' ) }
						value={ linkText }
						onChange={ ( value ) =>
							setAttributes( { linkText: value } )
						}
					/>
				</PanelBody>
				{ markers.map( ( marker, index ) => (
					<PanelBody
						key={ index }
						title={
							marker.title ||
							sprintf(
								/* translators: %d: number of the place. */
								__( 'Place %d', 'leitvia-studio' ),
								index + 1
							)
						}
					>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Name', 'leitvia-studio' ) }
							value={ marker.title }
							onChange={ ( title ) =>
								setMarker( index, { title } )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Address', 'leitvia-studio' ) }
							value={ marker.address }
							onChange={ ( address ) =>
								setMarker( index, { address } )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							type="number"
							step="any"
							label={ __( 'Latitude', 'leitvia-studio' ) }
							value={ marker.lat }
							onChange={ ( value ) =>
								setMarker( index, { lat: number( value ) } )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							type="number"
							step="any"
							label={ __( 'Longitude', 'leitvia-studio' ) }
							value={ marker.lng }
							onChange={ ( value ) =>
								setMarker( index, { lng: number( value ) } )
							}
						/>
						<Button
							__next40pxDefaultSize
							variant="secondary"
							isDestructive
							onClick={ () =>
								setAttributes( {
									markers: markers.filter(
										( _, i ) => i !== index
									),
								} )
							}
						>
							{ __( 'Remove place', 'leitvia-studio' ) }
						</Button>
					</PanelBody>
				) ) }
				<PanelBody>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ () =>
							setAttributes( {
								markers: [
									...markers,
									{ title: '', address: '', lat, lng },
								],
							} )
						}
					>
						{ __( 'Add place', 'leitvia-studio' ) }
					</Button>
				</PanelBody>
			</InspectorControls>
			<div
				{ ...useBlockProps( {
					className: `is-height-${ height }`,
				} ) }
			>
				<RichText
					tagName="h2"
					className="wp-block-leitvia-map__heading"
					placeholder={ __( 'Heading (optional)', 'leitvia-studio' ) }
					value={ heading }
					onChange={ ( value ) =>
						setAttributes( { heading: value } )
					}
				/>
				<div className="leitvia-consent__placeholder">
					<p>
						{ __(
							'The interactive map loads for visitors after they agree. Add places in the block settings.',
							'leitvia-studio'
						) }
					</p>
				</div>
				{ markers.length > 0 && (
					<ul className="wp-block-leitvia-map__places">
						{ markers.map( ( marker, index ) => (
							<li key={ index }>
								<strong>{ marker.title }</strong>
								{ marker.address && (
									<span>{ marker.address }</span>
								) }
								<span>{ linkText }</span>
							</li>
						) ) }
					</ul>
				) }
			</div>
		</>
	);
}
