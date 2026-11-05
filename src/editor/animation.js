// Animation on scroll for every block (includes/blocks/class-animation.php): the attributes leitviaAnimation and
// leitviaMotion and a panel "Animation" in the block settings with the effect, its strength and a button "Preview".
// Only the block comment carries the values; the server adds the classes on the front end, so the saved markup and the
// editor canvas stay as they are and no block becomes invalid. The preview sets the same classes on the block in the
// canvas for the length of the effect, so it plays the rules of the front end (src/blocks/reveal/style.scss), which the
// editor loads with the reveal block; with reduced motion it plays nothing, like the front end.
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import {
	InspectorControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { Button, PanelBody, SelectControl } from '@wordpress/components';

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

// Blocks whose parallax has something to move: the background of a cover, the line of a timeline.
const PARALLAX = [ 'core/cover', 'leitvia/timeline' ];

// Site default of the Studio settings (Registrar::enqueue_editor()): off, subtle or bold.
const SITE = window.leitviaStudioMotion ?? 'subtle';

const effects = () => [
	{ value: 'none', label: __( 'None', 'leitvia-studio' ) },
	{ value: 'fade', label: __( 'Appear', 'leitvia-studio' ) },
	{ value: 'rise', label: __( 'Rise', 'leitvia-studio' ) },
	{ value: 'zoom', label: __( 'Zoom', 'leitvia-studio' ) },
	{ value: 'slide-start', label: __( 'Slide from start', 'leitvia-studio' ) },
	{ value: 'slide-end', label: __( 'Slide from end', 'leitvia-studio' ) },
	{ value: 'wipe-up', label: __( 'Wipe up', 'leitvia-studio' ) },
	{ value: 'wipe-start', label: __( 'Wipe from start', 'leitvia-studio' ) },
	{ value: 'blur', label: __( 'Blur', 'leitvia-studio' ) },
	{ value: 'mask', label: __( 'Mask', 'leitvia-studio' ) },
	{ value: 'stagger', label: __( 'One after another', 'leitvia-studio' ) },
	{ value: 'parallax', label: __( 'Parallax', 'leitvia-studio' ) },
];

/**
 * Plays the effect once on the block in the canvas with the classes of the front end.
 *
 * @param {string} clientId Block client ID.
 * @param {string} effect   Effect.
 * @param {string} motion   Strength.
 */
function preview( clientId, effect, motion ) {
	const doc =
		document.querySelector( 'iframe[name="editor-canvas"]' )
			?.contentDocument ?? document;
	const el = doc.getElementById( `block-${ clientId }` );
	if ( ! el ) {
		return;
	}
	const classes = [
		'has-lv-animation',
		`is-lv-animation-${ effect }`,
		`is-lv-motion-${ motion }`,
	];
	const targets = 'stagger' === effect ? [ ...el.children ] : [ el ];
	const clear = () => {
		el.classList.remove( ...classes, 'is-lv-revealed' );
		targets.forEach( ( target ) => {
			target.classList.remove( 'is-lv-revealed' );
			target.style.removeProperty( '--leitvia-reveal-step' );
		} );
	};
	clear();
	// Read the layout once, so the animation starts again.
	void el.offsetWidth;
	el.classList.add( ...classes );
	targets.forEach( ( target, step ) => {
		target.style.setProperty(
			'--leitvia-reveal-step',
			String( Math.min( step, 5 ) )
		);
		target.classList.add( 'is-lv-revealed' );
	} );
	window.setTimeout( clear, 1800 );
}

function Panel( { name, clientId, attributes, setAttributes } ) {
	const { leitviaAnimation: effect, leitviaMotion: motion } = attributes;
	// "One after another" needs inner blocks, parallax a cover or a timeline; a block that has one keeps it.
	const hasChildren = useSelect(
		( select ) => select( blockEditorStore ).getBlockCount( clientId ) > 0,
		[ clientId ]
	);
	const options = effects().filter(
		( { value } ) =>
			value === effect ||
			( 'stagger' !== value && 'parallax' !== value ) ||
			( 'stagger' === value && hasChildren ) ||
			( 'parallax' === value && PARALLAX.includes( name ) )
	);
	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'Animation', 'leitvia-studio' ) }
				initialOpen={ 'none' !== effect }
			>
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'Effect on scroll', 'leitvia-studio' ) }
					value={ effect }
					options={ options }
					onChange={ ( leitviaAnimation ) =>
						setAttributes( { leitviaAnimation } )
					}
					help={
						'off' === SITE
							? __(
									'Motion is off for this site in the Studio settings, so visitors see no animation.',
									'leitvia-studio'
								)
							: __(
									'Visitors who turned on reduced motion see no animation. Use it for one or two sections, not in the first screen, the header or the navigation. The editor shows the block without the effect.',
									'leitvia-studio'
								)
					}
				/>
				{ 'none' !== effect && (
					<>
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Strength', 'leitvia-studio' ) }
							value={ motion ?? '' }
							options={ [
								{
									value: '',
									label: __(
										'Site default',
										'leitvia-studio'
									),
								},
								{
									value: 'subtle',
									label: __( 'Subtle', 'leitvia-studio' ),
								},
								{
									value: 'bold',
									label: __( 'Bold', 'leitvia-studio' ),
								},
							] }
							onChange={ ( value ) =>
								setAttributes( {
									leitviaMotion: value || undefined,
								} )
							}
							help={ __(
								'Bold moves twice as far and a little longer. Use it for images and galleries of lively brands.',
								'leitvia-studio'
							) }
						/>
						{ 'parallax' !== effect && 'off' !== SITE && (
							<Button
								__next40pxDefaultSize
								variant="secondary"
								onClick={ () =>
									preview(
										clientId,
										effect,
										motion ??
											( 'bold' === SITE
												? 'bold'
												: 'subtle' )
									)
								}
							>
								{ __( 'Preview', 'leitvia-studio' ) }
							</Button>
						) }
					</>
				) }
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
							enum: [
								'none',
								'fade',
								'rise',
								'stagger',
								'zoom',
								'slide-start',
								'slide-end',
								'wipe-up',
								'wipe-start',
								'blur',
								'mask',
								'parallax',
							],
							default: 'none',
						},
						leitviaMotion: {
							type: 'string',
							enum: [ 'subtle', 'bold' ],
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
					<Panel { ...props } />
				</>
			) : (
				<BlockEdit { ...props } />
			),
		'withLeitviaAnimation'
	)
);
