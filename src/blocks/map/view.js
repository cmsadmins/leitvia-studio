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
	// The attribution stands below the map, as a line of text, not as a control in its corner: there markers that
	// the view or the pan of a popup moves into the corner would cover its small links (WCAG 2.5.8).
	const map = L.map( canvas, {
		scrollWheelZoom: false,
		attributionControl: false,
	} ).setView( [ lat, lng ], zoom );
	L.tileLayer( canvas.dataset.tiles, { maxZoom: 19 } ).addTo( map );
	// The attribution is the fixed markup of Map::render(), as Leaflet would insert it.
	canvas.insertAdjacentHTML(
		'afterend',
		`<p class="wp-block-leitvia-map__attribution">${ canvas.dataset.attribution }</p>`
	);
	const places = JSON.parse( canvas.dataset.markers );
	const markers = [];
	for ( const place of places ) {
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
				const popup = document.createElement( 'div' );
				popup.append( document.createElement( 'p' ) );
				popup.firstChild.textContent = text.join( ', ' );
				// A link the server passed (Map::render()), named with the place.
				if ( place.url ) {
					const link = document.createElement( 'a' );
					link.href = place.url;
					link.textContent = place.linkText;
					link.setAttribute( 'aria-label', place.linkLabel );
					popup.append( link );
				}
				return popup;
			} )
			.addTo( map );
		marker.getElement()?.setAttribute( 'aria-label', text.join( ', ' ) );
		markers.push( marker );
		// Keyboard and screen reader users land in the popup: on its link, else on its text. Escape, the close
		// button or a click elsewhere closes it, and focus returns to the marker unless it already moved on.
		marker.on( 'popupopen', ( event ) => {
			const content = event.popup
				.getElement()
				.querySelector( '.leaflet-popup-content' );
			const link = content.querySelector( 'a[href]' );
			if ( ! link ) {
				content.tabIndex = -1;
			}
			( link || content ).focus( { preventScroll: true } );
		} );
		marker.on( 'popupclose', ( event ) => {
			const active = canvas.ownerDocument.activeElement;
			if (
				active === canvas.ownerDocument.body ||
				event.popup.getElement()?.contains( active )
			) {
				marker.getElement()?.focus( { preventScroll: true } );
			}
		} );
	}
	// data-fit: show all markers, zoomed in at most that far.
	if ( canvas.dataset.fit && places.length ) {
		map.fitBounds(
			places.map( ( place ) => [ place.lat, place.lng ] ),
			{ maxZoom: Number( canvas.dataset.fit ), padding: [ 32, 32 ] }
		);
	}
	// Markers closer than a target (WCAG 2.5.8, 24 px) move apart on a ring around their place, at every zoom, so
	// each stays a target of its own. The popup still opens at the place itself.
	// ponytail: greedy ring of 6 per 26 px, fine for a few close places; clustering if a map shows hundreds.
	const declutter = () => {
		const placed = [];
		for ( const marker of markers ) {
			const at = map.latLngToLayerPoint( marker.getLatLng() );
			let to = at;
			for (
				let step = 0;
				step < 18 && placed.some( ( p ) => p.distanceTo( to ) < 26 );
				step++
			) {
				const angle = ( step * Math.PI ) / 3;
				const radius = 26 * ( 1 + Math.floor( step / 6 ) );
				to = at.add( [
					Math.cos( angle ) * radius,
					Math.sin( angle ) * radius,
				] );
			}
			placed.push( to );
			const icon = marker.getElement();
			if ( icon ) {
				icon.style.marginLeft = `${ to.x - at.x - 12 }px`;
				icon.style.marginTop = `${ to.y - at.y - 12 }px`;
			}
		}
	};
	map.on( 'zoomend', declutter );
	declutter();
	// Leaflet closes a popup on Escape only while the map itself has the focus; the focus sits in the popup here.
	canvas.addEventListener( 'keydown', ( event ) => {
		if (
			'Escape' === event.key &&
			event.target.closest( '.leaflet-popup' )
		) {
			map.closePopup();
		}
	} );
}

const scan = ( root ) => root.querySelectorAll( CANVAS ).forEach( draw );

document.querySelectorAll( '.wp-block-leitvia-map' ).forEach( ( block ) => {
	scan( block );
	new window.MutationObserver( () => scan( block ) ).observe( block, {
		childList: true,
		subtree: true,
	} );
} );
