// Map (leitvia/map): the canvas sits in the <template> of the consent placeholder. Once @leitvia/consent inserts it
// (or the server renders it directly for tiles from the site itself), this module loads Leaflet from build/, a chunk
// of its own, and draws the map. Nothing else loads before that.
const CANVAS = '.wp-block-leitvia-map__canvas';

async function draw( canvas ) {
	if ( canvas.dataset.drawn ) {
		return;
	}
	canvas.dataset.drawn = '1';
	const { default: L } = await import(
		/* webpackChunkName: "blocks/map/leaflet" */ './leaflet'
	);
	const [ lat, lng, zoom ] = JSON.parse( canvas.dataset.view );
	const map = L.map( canvas, { scrollWheelZoom: false } ).setView(
		[ lat, lng ],
		zoom
	);
	L.tileLayer( canvas.dataset.tiles, {
		maxZoom: 19,
		attribution: canvas.dataset.attribution,
	} ).addTo( map );
	for ( const place of JSON.parse( canvas.dataset.markers ) ) {
		const text = [ place.title, place.address ].filter( Boolean );
		const marker = L.marker( [ place.lat, place.lng ], {
			icon: L.divIcon( {
				className: 'wp-block-leitvia-map__marker',
				iconSize: [ 24, 24 ],
			} ),
			title: place.title,
			keyboard: true,
		} )
			.bindPopup( () => {
				const popup = document.createElement( 'p' );
				popup.textContent = text.join( ', ' );
				return popup;
			} )
			.addTo( map );
		marker.getElement()?.setAttribute( 'aria-label', text.join( ', ' ) );
	}
}

const scan = ( root ) => root.querySelectorAll( CANVAS ).forEach( draw );

document.querySelectorAll( '.wp-block-leitvia-map' ).forEach( ( block ) => {
	scan( block );
	new window.MutationObserver( () => scan( block ) ).observe( block, {
		childList: true,
		subtree: true,
	} );
} );
