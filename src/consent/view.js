// Script module @leitvia/consent: loads third-party content behind the consent placeholder (Consent::placeholder())
// and drives the block leitvia/consent-settings. The services a visitor always allows are a JSON list in
// localStorage['leitvia-consent:<path of the site>'], written only after the visitor asks for it. The path keeps the
// consent with its site: sites of a subdirectory network, or installations below one another, share an origin and so
// their local storage. A list under the old key 'leitvia-consent' moves over on a site at the root path only.
//
// Events for consent managers: `leitvia:consent-request` (bubbles from the placeholder before anything loads,
// cancelable, detail { service }) and `leitvia:consent-granted` (dispatched by the manager on document, detail
// { service }), which loads every placeholder of that service on the page.
import { store, getElement, getContext } from '@wordpress/interactivity';

const LEGACY = 'leitvia-consent';

// state.site comes from the server (Consent::placeholder() and the consent-settings block), never guessed here.
const storageKey = () => `${ LEGACY }:${ state.site }`;

const allowed = () => {
	try {
		const storage = window.localStorage;
		let raw = storage.getItem( storageKey() );
		if ( null === raw && '/' === state.site ) {
			raw = storage.getItem( LEGACY );
			if ( null !== raw ) {
				storage.setItem( storageKey(), raw );
				storage.removeItem( LEGACY );
			}
		}
		const list = JSON.parse( raw );
		return Array.isArray( list ) ? list : [];
	} catch {
		return [];
	}
};

const save = ( list ) => {
	try {
		window.localStorage.setItem( storageKey(), JSON.stringify( list ) );
	} catch {}
};

const roots = ( service ) =>
	[ ...document.querySelectorAll( '.leitvia-consent' ) ].filter(
		( root ) => root.dataset.leitviaService === service
	);

/**
 * Inserts the template of one placeholder. With `ask`, a consent manager can cancel through leitvia:consent-request.
 * Returns the content element, or null when nothing loaded.
 *
 * @param {HTMLElement} root The .leitvia-consent element.
 * @param {boolean}     ask  Whether to dispatch leitvia:consent-request first.
 * @return {HTMLElement|null} The content element.
 */
function load( root, ask ) {
	const service = root.dataset.leitviaService;
	if (
		root.classList.contains( 'is-loaded' ) ||
		( ask &&
			! root.dispatchEvent(
				new CustomEvent( 'leitvia:consent-request', {
					bubbles: true,
					cancelable: true,
					detail: { service },
				} )
			) )
	) {
		return null;
	}
	const content = root.querySelector( '.leitvia-consent__content' );
	content.append(
		document.importNode(
			root.querySelector( 'template[data-leitvia-consent]' ).content,
			true
		)
	);
	root.classList.add( 'is-loaded' );
	root.querySelector( '.leitvia-consent__placeholder' ).hidden = true;
	root.closest( '.has-leitvia-consent' )?.classList.remove(
		'has-leitvia-consent'
	);
	root.querySelector( '.leitvia-consent__status' ).textContent =
		root.dataset.leitviaLoaded;
	return content;
}

document.addEventListener( 'leitvia:consent-granted', ( event ) =>
	roots( event.detail?.service ).forEach( ( root ) => load( root, false ) )
);

const { state } = store( 'leitvia/consent', {
	state: {
		noScript: false,
		list: [],
		status: '',
		get hasList() {
			return state.list.length > 0;
		},
	},
	actions: {
		load() {
			const root = getElement().ref.closest( '.leitvia-consent' );
			const service = root.dataset.leitviaService;
			const content = load( root, true );
			if ( ! content ) {
				return;
			}
			( content.querySelector( 'iframe' ) || content ).focus();
			if ( root.querySelector( 'input[type="checkbox"]' ).checked ) {
				save( [
					...allowed().filter( ( key ) => key !== service ),
					service,
				] );
				roots( service ).forEach( ( other ) => load( other, true ) );
			}
		},
		revoke() {
			const { item, revoked } = getContext();
			save( allowed().filter( ( key ) => key !== item.key ) );
			state.list = state.list.filter(
				( entry ) => entry.key !== item.key
			);
			state.status = revoked.replace( '%s', item.label );
		},
	},
	callbacks: {
		init() {
			const root = getElement().ref;
			if ( allowed().includes( root.dataset.leitviaService ) ) {
				load( root, true );
			}
		},
		list() {
			state.list = allowed().map( ( key ) => ( {
				key,
				label: state.labels?.[ key ] || key,
			} ) );
		},
	},
} );
