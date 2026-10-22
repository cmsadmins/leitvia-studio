// Preview of a pattern in an iframe of a fixed width, scaled down to the width available.
import { useEffect, useRef, useState } from '@wordpress/element';
import { useResizeObserver } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { previewUrl } from './common';

const DEFAULT_HEIGHT = 900;
// A page reporting more is cut off; the frame never grows without limit.
const MAX_HEIGHT = 10000;

/**
 * @param {Object}  props
 * @param {string}  props.patternRef  Pattern reference.
 * @param {string}  props.style       Variation slug, '' for the theme default.
 * @param {number}  props.set         Token set ID, applied to this preview only; 0 for none.
 * @param {number}  props.width       Viewport width in px.
 * @param {string}  props.title       Title of the iframe.
 * @param {boolean} props.decorative  Hidden from assistive technology and the tab order, for gallery thumbnails.
 * @param {boolean} props.annotations Marks the annotated blocks.
 */
export default function PreviewFrame( {
	patternRef,
	style = '',
	set = 0,
	width,
	title,
	decorative = false,
	annotations = false,
} ) {
	const src = previewUrl( patternRef, style, {
		...( set ? { set } : {} ),
		...( annotations ? { annotations: 1 } : {} ),
	} );
	const frame = useRef();
	// Thumbnails show the top of the page in the 4:3 box of the gallery grid.
	const [ height, setHeight ] = useState(
		decorative ? ( width * 3 ) / 4 : DEFAULT_HEIGHT
	);
	const [ resizeListener, { width: available } ] = useResizeObserver();
	const scale = available ? Math.min( 1, available / width ) : 0;
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		if ( decorative ) {
			return;
		}
		const origin = new URL( src, window.location.href ).origin;
		const onMessage = ( event ) => {
			if (
				event.origin === origin &&
				event.source === frame.current?.contentWindow &&
				'leitvia-studio:height' === event.data?.type
			) {
				const value = Number( event.data.height );
				if ( value > 0 ) {
					setHeight( Math.min( MAX_HEIGHT, Math.ceil( value ) ) );
				}
			}
		};
		window.addEventListener( 'message', onMessage );
		return () => window.removeEventListener( 'message', onMessage );
	}, [ src, decorative ] );

	// The frame keeps its real width, so media queries see the chosen viewport; only the rendering is scaled.
	const notice = failed && ! decorative && (
		<Notice status="warning" isDismissible={ false }>
			{ __(
				'The preview could not be loaded. Your session may have expired; reload the page and try again.',
				'leitvia-studio'
			) }
		</Notice>
	);
	return (
		<>
			{ notice }
			<div
				className="leitvia-studio-preview-frame"
				style={
					decorative
						? undefined
						: { height: scale ? height * scale : DEFAULT_HEIGHT }
				}
			>
				{ resizeListener }
				{ !! scale && (
					<iframe
						ref={ frame }
						src={ src }
						title={ title }
						width={ width }
						height={ height }
						loading="lazy"
						style={ {
							width,
							height,
							transform: `scale(${ scale })`,
						} }
						// The server answers a refused preview (for example an expired nonce) with plain text.
						onLoad={ () =>
							setFailed(
								'text/plain' ===
									frame.current?.contentDocument?.contentType
							)
						}
						{ ...( decorative
							? { tabIndex: -1, 'aria-hidden': true, inert: '' }
							: {} ) }
					/>
				) }
			</div>
		</>
	);
}
