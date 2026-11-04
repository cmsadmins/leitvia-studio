import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { IconPicker } from '../icon/picker';

const TEMPLATE = [ [ 'leitvia/icon-list-item' ], [ 'leitvia/icon-list-item' ] ];

export default function Edit( { attributes: { icon }, setAttributes } ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Icon', 'leitvia-studio' ) }>
					<IconPicker
						label={ __( 'Icon of the list', 'leitvia-studio' ) }
						value={ icon }
						onChange={ ( next ) => setAttributes( { icon: next } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<ul
				{ ...useInnerBlocksProps( useBlockProps( { role: 'list' } ), {
					template: TEMPLATE,
				} ) }
			/>
		</>
	);
}
