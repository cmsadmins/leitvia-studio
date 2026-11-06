// Query filter (leitvia/query-filter): without this module the form submits as a GET request and the page reloads
// with the filters in the URL, so every filtered address can be shared and is rendered on the server. With it, a
// change fetches that same address and replaces the results of this query in place, for every query, not only those
// with enhanced pagination (the block does not support client navigation, so core leaves the router out of these
// queries and only this module touches their markup). The form itself stays in the document: focus and the status
// region survive, nothing changes context (WCAG 3.2.2). Apply filters is hidden, since it has nothing left to do.
//
// Dropdowns and radio buttons wait longer than checkboxes: arrow keys on a closed dropdown fire change on every step,
// and a request starts only once the keys have rested. A newer change aborts the older request at once, and only the
// last response speaks in the status region, so fast changes are announced once. Any failure opens the address normally.
//
// Load more and the pagination keep working: Load more listens on the document, and the pagination is taken over
// here, with focus on the first new post. leitvia:content tells the reveal module about the new elements.
const FORM = '.wp-block-leitvia-query-filter form';
const QUERY = '.wp-block-query';
const POSTS = '.wp-block-post-template';
const QUICK = 250;
const PATIENT = 600;

const forms = () => [ ...document.querySelectorAll( FORM ) ];

const hideApply = () =>
	document
		.querySelectorAll( `${ FORM } [type="submit"]` )
		.forEach( ( button ) => ( button.hidden = true ) );

const address = () => {
	const url = new URL( window.location.href );
	url.hash = '';
	return url.href;
};

// The current URL with this query's filters replaced by the form, or dropped for a reset, and its page parameter gone.
const target = ( form, reset ) => {
	const id = form.dataset.leitviaQuery;
	const url = new URL( address() );
	[ ...url.searchParams.keys() ]
		.filter(
			( key ) =>
				key.startsWith( `qf-${ id }-` ) || key === `query-${ id }-page`
		)
		.forEach( ( key ) => url.searchParams.delete( key ) );
	for ( const [ key, value ] of reset ? [] : new window.FormData( form ) ) {
		if ( value && key.startsWith( `qf-${ id }-` ) ) {
			url.searchParams.append( key, value );
		}
	}
	return url.href;
};

// Replaces everything in the query but the path down to the form with the same parts of the new page, whatever the
// nesting: at each level all siblings of the path go, and the siblings from the new page take their place.
function replaceResults( form, next ) {
	const query = form.closest( QUERY );
	const added = [];
	for (
		let keep = form, fresh = next;
		keep !== query;
		keep = keep.parentElement, fresh = fresh.parentElement
	) {
		[ ...keep.parentElement.children ]
			.filter( ( child ) => child !== keep )
			.forEach( ( child ) => child.remove() );
		const nodes = [ ...fresh.parentElement.children ];
		const index = nodes.indexOf( fresh );
		keep.before( ...nodes.slice( 0, index ) );
		keep.after( ...nodes.slice( index + 1 ) );
		added.push( ...nodes.filter( ( node ) => node !== fresh ) );
	}
	return added;
}

// The form keeps its elements and takes over their state (Back, reset), the reset link and the text of the status.
function updateForm( form, next ) {
	for ( const field of form.querySelectorAll(
		'select, [type="checkbox"]'
	) ) {
		const twin = [ ...next.elements ].find(
			( { name, value, type } ) =>
				name === field.name &&
				( 'checkbox' !== type || value === field.value )
		);
		if ( 'checkbox' === field.type ) {
			field.checked = !! twin?.checked;
		} else if ( twin ) {
			field.value = twin.value;
		}
	}
	form.querySelector( 'a' ).href = next.querySelector( 'a' ).href;
	// The same text twice is no change for a screen reader; the space makes it speak again.
	const status = form.querySelector( '[role="status"]' );
	const text = next.querySelector( '[role="status"]' ).textContent;
	status.textContent =
		status.textContent === text ? text + String.fromCharCode( 160 ) : text;
}

let running;
let shown = address();

async function load( href, targets, { push = true, paged = false } = {} ) {
	running?.abort();
	if ( push && href === shown ) {
		return;
	}
	// Requests stay on this site; a link to anywhere else is followed as a link.
	if ( new URL( href ).origin !== window.location.origin ) {
		window.location.assign( href );
		return;
	}
	const run = ( running = new window.AbortController() );
	const busy = targets.flatMap( ( form ) => [
		...form
			.closest( QUERY )
			.querySelectorAll( `${ POSTS }, .wp-block-query-no-results` ),
	] );
	busy.forEach( ( el ) => el.setAttribute( 'aria-busy', 'true' ) );
	try {
		const response = await window.fetch( href, { signal: run.signal } );
		if ( ! response.ok ) {
			throw new Error( response.statusText );
		}
		const page = new window.DOMParser().parseFromString(
			await response.text(),
			'text/html'
		);
		// The filtered page has the same layout: each form sits at the same position.
		const all = forms();
		const fresh = page.querySelectorAll( FORM );
		const pairs = targets.map( ( form ) => [
			form,
			fresh[ all.indexOf( form ) ],
		] );
		if (
			pairs.some(
				( [ form, next ] ) =>
					next?.dataset.leitviaQuery !== form.dataset.leitviaQuery
			)
		) {
			throw new Error( 'other layout' );
		}
		if ( push ) {
			window.history.pushState( null, '', href );
		}
		shown = href;
		const active = targets[ 0 ].ownerDocument.activeElement;
		const added = pairs.flatMap( ( [ form, next ] ) => {
			const nodes = replaceResults( form, next );
			updateForm( form, next );
			return nodes;
		} );
		hideApply();
		// Focus that sat in the replaced results moves on, it never falls back to the top of the page.
		if ( ! active.isConnected ) {
			const [ form ] = targets;
			(
				( paged &&
					form.closest( QUERY ).querySelector( `${ POSTS } a` ) ) ||
				form.querySelector( 'select, input:not([type="hidden"])' )
			)?.focus();
		}
		document.dispatchEvent(
			new window.CustomEvent( 'leitvia:content', { detail: added } )
		);
	} catch ( error ) {
		if ( 'AbortError' !== error.name ) {
			if ( push ) {
				window.location.assign( href );
			} else {
				window.location.reload();
			}
		}
	} finally {
		if ( run === running ) {
			busy.forEach( ( el ) => el.removeAttribute( 'aria-busy' ) );
		}
	}
}

let timer;

const now = ( ...args ) => {
	window.clearTimeout( timer );
	load( ...args );
};

hideApply();

document.addEventListener( 'change', ( event ) => {
	const form = event.target.closest?.( FORM );
	if ( form ) {
		running?.abort();
		window.clearTimeout( timer );
		timer = window.setTimeout(
			() => load( target( form ), [ form ] ),
			event.target.matches( 'select, [type="radio"]' ) ? PATIENT : QUICK
		);
	}
} );

document.addEventListener( 'submit', ( event ) => {
	const form = event.target.closest?.( FORM );
	if ( form ) {
		event.preventDefault();
		now( target( form ), [ form ] );
	}
} );

// In the capture phase, so the click reaches core's pagination handler of a query with enhanced pagination no more.
document.addEventListener(
	'click',
	( event ) => {
		const link = event.target.closest?.(
			`${ FORM } a, .wp-block-query-pagination a`
		);
		const reset = link?.closest( FORM );
		const form = reset ?? link?.closest( QUERY )?.querySelector( FORM );
		if (
			! form ||
			event.button ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		if ( reset ) {
			now( target( form, true ), [ form ] );
		} else {
			now( link.href, [ form ], { paged: true } );
		}
	},
	true
);

// A jump to an anchor also fires popstate, with the same address and nothing to load.
window.addEventListener( 'popstate', () => {
	if ( address() !== shown ) {
		now( address(), forms(), { push: false } );
	}
} );
