// Entry point of the Leitvia Studio admin screens.
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import App from './admin/app';
import { notify } from './admin/notify';
import VariantsPanel from './lab/variants-panel';
import AnnotationsPanel from './lab/annotations-panel';
import UsagePanel from './lab/usage-panel';
import StatesPanel from './lab/states-panel';
import ChecksPanel from './lab/checks-panel';
import './admin/style.scss';

/* global __webpack_require__ */
/* eslint-disable camelcase -- webpack's runtime name. */
// Lazy screen chunks load their CSS at runtime; in right-to-left languages they get the -rtl.css twin that
// webpack.config.js writes next to each file. Entry CSS is switched by WordPress (wp_style_add_data rtl).
if ( document.documentElement.dir === 'rtl' && __webpack_require__.miniCssF ) {
	const cssFile = __webpack_require__.miniCssF;
	__webpack_require__.miniCssF = ( chunkId ) =>
		cssFile( chunkId ).replace( /\.css$/, '-rtl.css' );
}
/* eslint-enable camelcase */

window.leitviaStudio.registerLabPanel( 'leitvia/variants', VariantsPanel );
window.leitviaStudio.registerLabPanel(
	'leitvia/annotations',
	AnnotationsPanel
);
window.leitviaStudio.registerLabPanel( 'leitvia/usage', UsagePanel );
window.leitviaStudio.registerLabPanel( 'leitvia/states', StatesPanel );
window.leitviaStudio.registerLabPanel( 'leitvia/checks', ChecksPanel );

// Panel scripts of add-ons load in the footer as well; they have all run before the DOM is ready.
domReady( () => {
	const root = document.getElementById( 'leitvia-studio-root' );
	if ( root ) {
		createRoot( root ).render( <App screen={ root.dataset.screen } /> );
	}

	// Product notes of add-ons (Admin::render_slot()); dismissing hides them for this user on every Studio screen.
	const notes = document.querySelector( '.leitvia-studio-notes' );
	notes
		?.querySelector( '.leitvia-studio-notes__dismiss' )
		.addEventListener( 'click', () =>
			apiFetch( {
				path: '/leitvia-studio/v1/notes/dismiss',
				method: 'POST',
			} )
				.then( () => {
					notes.remove();
					const heading = root?.querySelector( 'h1' );
					heading?.setAttribute( 'tabindex', '-1' );
					heading?.focus();
					speak( __( 'Product notes dismissed.', 'leitvia-studio' ) );
				} )
				.catch( () =>
					notify(
						'error',
						__(
							'The product notes could not be dismissed.',
							'leitvia-studio'
						)
					)
				)
		);
} );
