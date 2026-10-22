// Query filter (leitvia/query-filter): without this module the form submits as a GET request and the page reloads
// with the filters in the URL. Inside a Query Loop with enhanced pagination (a router region), a change or a submit
// navigates through the Interactivity API router instead: the URL changes, the posts and the result count in the
// status region update, focus stays in the form, and the browser's Back button restores the previous state.
const FORM = '.wp-block-leitvia-query-filter form';

// The current URL with this query's filters replaced by the form and its page parameter dropped.
const target = ( form ) => {
	const id = form.dataset.leitviaQuery;
	const url = new URL( window.location.href );
	[ ...url.searchParams.keys() ]
		.filter(
			( key ) =>
				key.startsWith( `qf-${ id }-` ) || key === `query-${ id }-page`
		)
		.forEach( ( key ) => url.searchParams.delete( key ) );
	for ( const [ key, value ] of new window.FormData( form ) ) {
		if ( value && key.startsWith( `qf-${ id }-` ) ) {
			url.searchParams.append( key, value );
		}
	}
	return url.href;
};

let runs = 0;

async function navigate( form ) {
	const doc = form.ownerDocument;
	const active = form.contains( doc.activeElement )
		? doc.activeElement
		: null;
	const id = form.dataset.leitviaQuery;
	// Registered by core as a script module; the build lists it as a dynamic dependency.
	// eslint-disable-next-line import/no-unresolved
	const { actions } = await import( '@wordpress/interactivity-router' );
	const href = target( form );
	const run = ++runs;
	await actions.navigate( href );
	// Before the page has hydrated the router does not swap the region; then the page loads as without JavaScript.
	if ( run === runs && window.location.href !== href ) {
		window.location.assign( href );
		return;
	}
	// Posts appended by Load more do not belong to the new page.
	document
		.querySelectorAll( '[data-leitvia-appended]' )
		.forEach( ( item ) => item.remove() );
	const current = document.querySelector(
		`${ FORM }[data-leitvia-query="${ id }"]`
	);
	if ( active && current && ! current.contains( doc.activeElement ) ) {
		[ ...current.elements ]
			.find(
				( el ) => el.name === active.name && el.value === active.value
			)
			?.focus();
	}
}

const routed = ( event ) => {
	const form = event.target.closest?.( FORM );
	return form?.closest( '[data-wp-router-region]' ) ? form : null;
};

document.addEventListener( 'change', ( event ) => {
	const form = routed( event );
	if ( form ) {
		navigate( form );
	}
} );

document.addEventListener( 'submit', ( event ) => {
	const form = routed( event );
	if ( form ) {
		event.preventDefault();
		navigate( form );
	}
} );
