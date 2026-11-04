// Animation on scroll for every block (includes/blocks/class-animation.php): the attribute leitviaAnimation and a panel
// "Animation" in the block settings. Only the block comment carries the value; the server adds the classes on the
// front end, so the saved markup and the editor canvas stay as they are and no block becomes invalid.
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import {
	InspectorControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';

// Same list as Animation::EXCLUDED (tests/unit/animation.test.mjs compares them).
export const EXCLUDED = [
	'core/freeform',
	'core/html',
	'core/shortcode',
	'core/missing',
	'core/block',
	'core/pattern',
	'core/template-part',
	'core/post-content',
	'core/more',
	'core/nextpage',
	'core/legacy-widget',
	'core/widget-group',
	'core/navigation',
	'core/navigation-link',
	'core/navigation-submenu',
	'core/home-link',
	'core/page-list',
	'core/page-list-item',
	'leitvia/reveal',
	'leitvia/mega-menu',
];

function Panel( { clientId, value, onChange } ) {
	// "One after another" needs inner blocks; a block that has it keeps the option.
	const hasChildren = useSelect(
		( select ) => select( blockEditorStore ).getBlockCount( clientId ) > 0,
		[ clientId ]
	);
	const options = [
		{ value: 'none', label: __( 'None', 'leitvia-studio' ) },
		{ value: 'fade', label: __( 'Appear', 'leitvia-studio' ) },
		{ value: 'rise', label: __( 'Rise', 'leitvia-studio' ) },
	];
	if ( hasChildren || 'stagger' === value ) {
		options.push( {
			value: 'stagger',
			label: __( 'One after another', 'leitvia-studio' ),
		} );
	}
	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'Animation', 'leitvia-studio' ) }
				initialOpen={ 'none' !== value }
			>
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'Effect on scroll', 'leitvia-studio' ) }
					value={ value }
					options={ options }
					onChange={ onChange }
					help={ __(
						'Visitors who turned on reduced motion see no animation. Use it for one or two sections, not in the header or navigation. The editor shows the block without the effect.',
						'leitvia-studio'
					) }
				/>
			</PanelBody>
		</InspectorControls>
	);
}

addFilter(
	'blocks.registerBlockType',
	'leitvia-studio/animation',
	( settings, name ) =>
		EXCLUDED.includes( name ) || settings.attributes?.leitviaAnimation
			? settings
			: {
					...settings,
					attributes: {
						...settings.attributes,
						leitviaAnimation: {
							type: 'string',
							enum: [ 'none', 'fade', 'rise', 'stagger' ],
							default: 'none',
						},
					},
				}
);

addFilter(
	'editor.BlockEdit',
	'leitvia-studio/animation',
	createHigherOrderComponent(
		( BlockEdit ) => ( props ) =>
			props.isSelected && props.attributes.leitviaAnimation ? (
				<>
					<BlockEdit { ...props } />
					<Panel
						clientId={ props.clientId }
						value={ props.attributes.leitviaAnimation }
						onChange={ ( leitviaAnimation ) =>
							props.setAttributes( { leitviaAnimation } )
						}
					/>
				</>
			) : (
				<BlockEdit { ...props } />
			),
		'withLeitviaAnimation'
	)
);
