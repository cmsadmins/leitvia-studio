// Marks the link of the section being read with aria-current="location". It moves no focus and announces nothing;
// the links work without this module. With display companion (Table_Of_Contents::companion()) it also shows the
// chapter button while the list is out of view and the end of the content is not, with the section being read and
// the reading progress. The button opens a modal dialog with the same list: focus on the current entry, Escape, the
// close button and a click outside close it and focus returns to the button; an entry closes it and moves focus to
// its heading.
const reduced = window.matchMedia( '(prefers-reduced-motion: reduce)' );
const PREFIX = '.wp-block-leitvia-table-of-contents__';

for ( const nav of document.querySelectorAll(
	'.wp-block-leitvia-table-of-contents'
) ) {
	const list = nav.querySelector( ':scope > ol' );
	const links = [ ...nav.querySelectorAll( 'a[href^="#"]' ) ];
	const flow = [ ...list.querySelectorAll( 'a[href^="#"]' ) ];
	const targets = flow.map( ( link ) =>
		document.getElementById(
			decodeURIComponent( link.getAttribute( 'href' ).slice( 1 ) )
		)
	);
	const companion = nav.querySelector( PREFIX + 'companion' );
	const dialog = companion?.querySelector( 'dialog' );
	const button = companion?.querySelector( PREFIX + 'open' );
	const content =
		nav.closest( '.wp-block-post-content, .entry-content, main' ) ??
		document.body;
	let current = -1;

	const text = ( el, value ) => {
		if ( el.textContent !== value ) {
			el.textContent = value;
		}
	};
	const companionUpdate = () => {
		const box = content.getBoundingClientRect();
		const past = list.getBoundingClientRect().bottom < 0;
		const ahead = box.bottom > window.innerHeight;
		companion.classList.toggle(
			'is-visible',
			( past && ahead ) ||
				dialog.open ||
				companion.contains( companion.ownerDocument.activeElement )
		);
		const at = Math.max( current, 0 );
		text(
			companion.querySelector( PREFIX + 'current' ),
			flow[ at ].textContent
		);
		text(
			companion.querySelector( PREFIX + 'count' ),
			companion.dataset.count
				.replace( '%1$d', at + 1 )
				.replace( '%2$d', flow.length )
		);
		const read = Math.round(
			100 *
				Math.min(
					1,
					Math.max(
						0,
						-box.top /
							Math.max( 1, box.height - window.innerHeight )
					)
				)
		);
		const progress = companion.querySelector( '[role="progressbar"]' );
		// The value changes in steps of ten, so assistive technology is not told of every pixel scrolled.
		const step = String( Math.floor( read / 10 ) * 10 );
		if ( progress.getAttribute( 'aria-valuenow' ) !== step ) {
			progress.setAttribute( 'aria-valuenow', step );
		}
		progress.style.setProperty( '--leitvia-toc-progress', read / 100 );
	};
	// The current section is the last heading above the upper third of the viewport.
	const update = () => {
		const line = window.innerHeight / 3;
		current = -1;
		targets.forEach( ( target, i ) => {
			if ( target && target.getBoundingClientRect().top <= line ) {
				current = i;
			}
		} );
		const href =
			current < 0 ? null : flow[ current ].getAttribute( 'href' );
		links.forEach( ( link ) =>
			link.getAttribute( 'href' ) === href
				? link.setAttribute( 'aria-current', 'location' )
				: link.removeAttribute( 'aria-current' )
		);
		if ( companion ) {
			companionUpdate();
		}
	};
	// A scroll listener, not an IntersectionObserver: the companion follows the progress on every frame, and a jump
	// (anchor, Home and End) crosses no threshold.
	let frame = 0;
	const schedule = () => {
		frame =
			frame ||
			window.requestAnimationFrame( () => {
				frame = 0;
				update();
			} );
	};
	window.addEventListener( 'scroll', schedule, { passive: true } );
	window.addEventListener( 'resize', schedule );

	if ( companion ) {
		const entries = [ ...dialog.querySelectorAll( 'a[href^="#"]' ) ];
		let jumping = false;
		companion.hidden = false;
		companion.addEventListener( 'focusout', schedule );
		button.addEventListener( 'click', () => {
			dialog.showModal();
			entries[ Math.max( current, 0 ) ].focus();
		} );
		companion
			.querySelector( PREFIX + 'close' )
			.addEventListener( 'click', () => dialog.close() );
		// A click on the backdrop hits the dialog itself; the sheet fills the dialog.
		dialog.addEventListener( 'click', ( event ) => {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'close', () => {
			if ( ! jumping ) {
				button.focus();
			}
			jumping = false;
			schedule();
		} );
		entries.forEach( ( link, i ) =>
			link.addEventListener( 'click', ( event ) => {
				const target = targets[ i ];
				if ( ! target ) {
					return;
				}
				event.preventDefault();
				jumping = true;
				dialog.close();
				if ( ! target.hasAttribute( 'tabindex' ) ) {
					target.setAttribute( 'tabindex', '-1' );
				}
				target.focus( { preventScroll: true } );
				// scrollIntoView honours the scroll-margin of the headings (style.scss).
				target.scrollIntoView( {
					behavior: reduced.matches ? 'auto' : 'smooth',
				} );
				window.history.pushState(
					null,
					'',
					link.getAttribute( 'href' )
				);
			} )
		);
	}
	update();
}
