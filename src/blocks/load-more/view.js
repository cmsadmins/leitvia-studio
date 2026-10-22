// Load more (leitvia/load-more): without this module the link opens the next page of the query. With it, the posts
// of that page are appended to the list, focus moves to the first new post, the status region announces how many
// came in, and the link points to the page after, or goes away on the last page.
const BLOCK = '.wp-block-leitvia-load-more';
const LIST = '.wp-block-post-template';

async function more( block, link ) {
	const query = block.closest( '.wp-block-query' );
	const list = query?.querySelector( LIST );
	if ( new URL( link.href ).origin !== window.location.origin ) {
		throw new Error( 'other origin' );
	}
	const response = await window.fetch( link.href );
	if ( ! list || ! response.ok ) {
		throw new Error( response.statusText );
	}
	const page = new window.DOMParser().parseFromString(
		await response.text(),
		'text/html'
	);
	// The next page has the same layout: the query sits at the same position. On the last page it has no Load more.
	const index = [ ...document.querySelectorAll( '.wp-block-query' ) ].indexOf(
		query
	);
	const next = page.querySelectorAll( '.wp-block-query' )[ index ];
	const added = [ ...( next?.querySelector( LIST )?.children ?? [] ) ].map(
		( item ) => {
			item.dataset.leitviaAppended = '';
			item.tabIndex = -1;
			return document.adoptNode( item );
		}
	);
	list.append( ...added );
	added[ 0 ]?.focus();
	const status = block.querySelector( '[role="status"]' );
	status.textContent = status.dataset.loaded.replace(
		'%d',
		String( added.length )
	);
	const href = next?.querySelector( `${ BLOCK } a` )?.href;
	if ( href ) {
		link.href = href;
	} else {
		link.remove();
	}
}

document.addEventListener( 'click', ( event ) => {
	const link = event.target.closest?.( `${ BLOCK } a` );
	if (
		! link ||
		event.button ||
		event.metaKey ||
		event.ctrlKey ||
		event.shiftKey ||
		event.altKey
	) {
		return;
	}
	event.preventDefault();
	link.setAttribute( 'aria-busy', 'true' );
	more( link.closest( BLOCK ), link )
		.catch( () => window.location.assign( link.href ) )
		.finally( () => link.removeAttribute( 'aria-busy' ) );
} );
