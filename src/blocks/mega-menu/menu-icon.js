// Icon of a menu item: the attribute icon of the mega menu and leitviaIcon of core navigation links and submenus
// (Mega_Menu::link_args() and link_icon() on the server). The icon is decorative and stands before the text.
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { Button, PanelBody } from '@wordpress/components';
import { IconPicker } from '../icon/picker';

const BLOCKS = [ 'core/navigation-link', 'core/navigation-submenu' ];

/**
 * Panel with the icon picker and a button that removes the icon.
 *
 * @param {Object}                 props
 * @param {string}                 props.value    Icon name, '' for none.
 * @param {(name: string) => void} props.onChange Called with the new name.
 */
export function IconPanel( { value, onChange } ) {
	return (
		<PanelBody
			title={ __( 'Icon', 'leitvia-studio' ) }
			initialOpen={ !! value }
		>
			<IconPicker
				label={ __( 'Icon before the text', 'leitvia-studio' ) }
				value={ value || undefined }
				onChange={ onChange }
			/>
			{ value && (
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ () => onChange( '' ) }
				>
					{ __( 'No icon', 'leitvia-studio' ) }
				</Button>
			) }
		</PanelBody>
	);
}

addFilter(
	'blocks.registerBlockType',
	'leitvia-studio/menu-icon',
	( settings, name ) =>
		BLOCKS.includes( name ) && ! settings.attributes?.leitviaIcon
			? {
					...settings,
					attributes: {
						...settings.attributes,
						leitviaIcon: { type: 'string' },
					},
				}
			: settings
);

addFilter(
	'editor.BlockEdit',
	'leitvia-studio/menu-icon',
	createHigherOrderComponent(
		( BlockEdit ) => ( props ) =>
			props.isSelected && BLOCKS.includes( props.name ) ? (
				<>
					<BlockEdit { ...props } />
					<InspectorControls>
						<IconPanel
							value={ props.attributes.leitviaIcon }
							onChange={ ( next ) =>
								props.setAttributes( {
									leitviaIcon: next || undefined,
								} )
							}
						/>
					</InspectorControls>
				</>
			) : (
				<BlockEdit { ...props } />
			),
		'withLeitviaMenuIcon'
	)
);
