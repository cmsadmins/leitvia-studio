// Format leitvia/highlight: <mark class="leitvia-highlight is-<style> is-<color>"> for words in running text and
// headings. The look comes from assets/highlight.css, which Studio loads only where content uses the format
// (Blocks::highlight_style()). No script on the front end.
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	registerFormatType,
	applyFormat,
	removeFormat,
	getActiveFormat,
	useAnchor,
} from '@wordpress/rich-text';
import { RichTextToolbarButton } from '@wordpress/block-editor';
import { Button, Popover, SelectControl } from '@wordpress/components';

const name = 'leitvia/highlight';
const className = 'leitvia-highlight';

export const STYLES = [ 'marker', 'underline', 'circle' ];
export const COLORS = [ 'accent', 'warning', 'success' ];

const styleOptions = () => [
	{ value: 'marker', label: __( 'Marker', 'leitvia-studio' ) },
	{ value: 'underline', label: __( 'Underline', 'leitvia-studio' ) },
	{ value: 'circle', label: __( 'Circle', 'leitvia-studio' ) },
];
const colorOptions = () => [
	{ value: 'accent', label: __( 'Accent', 'leitvia-studio' ) },
	{ value: 'warning', label: __( 'Warning', 'leitvia-studio' ) },
	{ value: 'success', label: __( 'Success', 'leitvia-studio' ) },
];

// Style and color of an applied format, from its classes.
const parse = ( classes = '' ) => {
	const list = classes.split( /\s+/ );
	const pick = ( options ) =>
		options.find( ( o ) => list.includes( `is-${ o }` ) ) ?? options[ 0 ];
	return { style: pick( STYLES ), color: pick( COLORS ) };
};

function Edit( { value, onChange, isActive, contentRef } ) {
	const [ open, setOpen ] = useState( false );
	const anchor = useAnchor( { editableContentElement: contentRef.current } );
	const current = parse( getActiveFormat( value, name )?.attributes?.class );
	const apply = ( next ) =>
		onChange(
			applyFormat( value, {
				type: name,
				attributes: {
					class: `is-${ next.style } is-${ next.color }`,
				},
			} )
		);
	return (
		<>
			<RichTextToolbarButton
				icon="marker"
				title={ __( 'Highlight', 'leitvia-studio' ) }
				onClick={ () => setOpen( ! open ) }
				isActive={ isActive }
			/>
			{ open && (
				<Popover
					anchor={ anchor }
					placement="bottom"
					onClose={ () => setOpen( false ) }
					focusOnMount="firstElement"
				>
					<div className="leitvia-highlight-popover">
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Style', 'leitvia-studio' ) }
							value={ current.style }
							options={ styleOptions() }
							onChange={ ( style ) =>
								apply( { ...current, style } )
							}
						/>
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Color', 'leitvia-studio' ) }
							value={ current.color }
							options={ colorOptions() }
							onChange={ ( color ) =>
								apply( { ...current, color } )
							}
							help={ __(
								'Screen readers do not always announce a highlight. Say in words what matters, do not rely on the mark alone.',
								'leitvia-studio'
							) }
						/>
						<Button
							__next40pxDefaultSize
							variant="secondary"
							onClick={ () => {
								if ( isActive ) {
									onChange( removeFormat( value, name ) );
								} else {
									apply( current );
								}
								setOpen( false );
							} }
						>
							{ isActive
								? __( 'Remove highlight', 'leitvia-studio' )
								: __( 'Apply highlight', 'leitvia-studio' ) }
						</Button>
					</div>
				</Popover>
			) }
		</>
	);
}

registerFormatType( name, {
	title: __( 'Highlight', 'leitvia-studio' ),
	tagName: 'mark',
	className,
	attributes: { class: 'class' },
	edit: Edit,
} );
