import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { Button, PanelBody } from '@wordpress/components';
import { IconPicker, Svg } from '../icon/picker';

export default function Edit( {
	attributes: { icon, text },
	setAttributes,
	context,
} ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Icon', 'leitvia-studio' ) }>
					<IconPicker
						label={ __( 'Icon of this item', 'leitvia-studio' ) }
						value={ icon }
						onChange={ ( next ) => setAttributes( { icon: next } ) }
					/>
					{ icon && (
						<Button
							__next40pxDefaultSize
							variant="secondary"
							onClick={ () =>
								setAttributes( { icon: undefined } )
							}
						>
							{ __(
								'Use the icon of the list',
								'leitvia-studio'
							) }
						</Button>
					) }
				</PanelBody>
			</InspectorControls>
			<li { ...useBlockProps() }>
				<Svg
					name={
						icon || context[ 'leitvia/iconListIcon' ] || 'check'
					}
				/>
				<RichText
					identifier="text"
					tagName="span"
					className="wp-block-leitvia-icon-list-item__text"
					aria-label={ __( 'List item', 'leitvia-studio' ) }
					placeholder={ __( 'List item', 'leitvia-studio' ) }
					value={ text }
					onChange={ ( next ) => setAttributes( { text: next } ) }
				/>
			</li>
		</>
	);
}
