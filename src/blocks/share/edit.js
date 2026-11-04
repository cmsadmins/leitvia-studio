import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	Disabled,
	PanelBody,
	SelectControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';

const SERVICES = metadata.attributes.services.items.enum;
const NAMES = {
	linkedin: 'LinkedIn',
	bluesky: 'Bluesky',
	facebook: 'Facebook',
	whatsapp: 'WhatsApp',
	threads: 'Threads',
	xing: 'XING',
	email: __( 'Email', 'leitvia-studio' ),
	copy: __( 'Copy link', 'leitvia-studio' ),
};

// The order of the channels is the order in which they were switched on: one switched on again moves to the end.
export default function Edit( { attributes, setAttributes } ) {
	const { services, display } = attributes;
	const toggle = ( service, on ) =>
		setAttributes( {
			services: on
				? [ ...services, service ]
				: services.filter( ( s ) => s !== service ),
		} );
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Channels', 'leitvia-studio' ) }>
					{ [
						...services,
						...SERVICES.filter( ( s ) => ! services.includes( s ) ),
					].map( ( service ) => (
						<CheckboxControl
							key={ service }
							label={ NAMES[ service ] }
							checked={ services.includes( service ) }
							onChange={ ( on ) => toggle( service, on ) }
						/>
					) ) }
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Display', 'leitvia-studio' ) }
						value={ display }
						options={ [
							{
								label: __( 'Icon and text', 'leitvia-studio' ),
								value: 'icon-text',
							},
							{
								label: __( 'Text', 'leitvia-studio' ),
								value: 'text',
							},
							{
								label: __( 'Icon only', 'leitvia-studio' ),
								value: 'icon',
							},
						] }
						onChange={ ( next ) =>
							setAttributes( { display: next } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Disabled>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
				/>
			</Disabled>
		</div>
	);
}
