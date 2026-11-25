// Mega menu (leitvia/mega-menu): APG disclosure navigation on top of the server markup (class-mega-menu.php).
// Without this module the item is a link to its overview page and the panel shows while the focus is in the item
// (style.scss). With it (data-ready on the item), a button next to the link opens the
// panel: Escape closes it and returns focus to the button, focus leaving the item closes it, only one panel is open.
// Hover opens after 150 ms and closes 300 ms after the pointer left, so the pointer may cross the gap to the panel.
// In the overlay each column head of the panel (class leitvia-mega-menu-head) gets a button that shows its column,
// a second level of the accordion; Escape inside an open column closes it first.
//
// In the overlay of a navigation the server marked with data-leitvia-collapsible (a submenu or a mega menu,
// Mega_Menu::collapsible_overlay()), the core submenus become collapsible the same way. Core keeps
// them open there (state.isSubmenuOpen is true while the overlay is open), so this module takes over their toggles
// while the overlay is open and leaves the desktop menu to core.
const hover = window.matchMedia( '(hover: hover) and (pointer: fine)' );
const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select, textarea, [tabindex]:not([tabindex^="-"])';
const menus = [];
const inOverlay = ( el ) => !! el.closest( '.is-menu-open' );

// A button in each column head that has more than its head, after the title; the label pattern comes from the server
// (%s submenu, as on the button of the item). It only shows in the overlay (style.scss).
function columns( panel, button ) {
	const name = panel.dataset.columnLabel || '%s';
	for ( const head of panel.querySelectorAll(
		'.wp-block-column > .leitvia-mega-menu-head'
	) ) {
		const column = head.parentElement;
		if ( column.children.length < 2 ) {
			continue;
		}
		const toggler = document.createElement( 'button' );
		toggler.type = 'button';
		toggler.className = 'wp-block-leitvia-mega-menu__column-toggle';
		toggler.setAttribute( 'aria-expanded', 'false' );
		toggler.setAttribute(
			'aria-label',
			name.replace( '%s', head.textContent.trim() )
		);
		toggler.innerHTML = button.innerHTML;
		toggler.addEventListener( 'click', () => {
			const open = column.classList.toggle( 'is-collapsed' );
			toggler.setAttribute( 'aria-expanded', String( ! open ) );
		} );
		column.classList.add( 'is-collapsed' );
		head.append( toggler );
	}
}

function toggle( menu, open, byHover = false ) {
	clearTimeout( menu.timer );
	if ( open ) {
		menus.forEach( ( other ) => other !== menu && toggle( other, false ) );
	}
	menu.byHover = open && byHover;
	menu.button.setAttribute( 'aria-expanded', String( open ) );
	menu.panel.hidden = ! open;
	if ( open ) {
		fit( menu.panel );
	}
}

// A wide panel (four columns, a card) moves sideways until it stays 16 px inside the window, and a tall one scrolls
// inside itself from 16 px above the bottom of the window, so no link ends up below it (WCAG 2.4.11).
function fit( panel ) {
	panel.style.removeProperty( '--leitvia-mega-shift' );
	panel.style.removeProperty( '--leitvia-mega-max' );
	if ( inOverlay( panel ) ) {
		return;
	}
	const box = panel.getBoundingClientRect();
	panel.style.setProperty(
		'--leitvia-mega-max',
		`${ Math.max( 160, window.innerHeight - box.top - 16 ) }px`
	);
	const room = document.documentElement.clientWidth;
	const shift =
		Math.min( 0, room - 16 - box.right ) || Math.max( 0, 16 - box.left );
	if ( shift ) {
		panel.style.setProperty( '--leitvia-mega-shift', `${ shift }px` );
	}
}

for ( const item of document.querySelectorAll(
	'.wp-block-leitvia-mega-menu'
) ) {
	const button = item.querySelector(
		':scope > .wp-block-leitvia-mega-menu__toggle'
	);
	const panel = item.querySelector(
		':scope > .wp-block-leitvia-mega-menu__panel'
	);
	if ( ! button || ! panel ) {
		continue;
	}
	const menu = { item, button, panel, timer: 0, byHover: false };
	panel.hidden = true;
	item.dataset.ready = '';
	columns( panel, button );
	const later = ( open, delay ) => {
		clearTimeout( menu.timer );
		menu.timer = setTimeout( () => toggle( menu, open, true ), delay );
	};
	menus.push( menu );
	button.hidden = false;
	button.addEventListener( 'click', () =>
		// A click on a panel that hover just opened keeps it open.
		toggle(
			menu,
			menu.byHover || 'true' !== button.getAttribute( 'aria-expanded' )
		)
	);
	item.addEventListener( 'keydown', ( event ) => {
		const column = event.target.closest( '.wp-block-column' );
		const head = column?.querySelector(
			':scope > .leitvia-mega-menu-head .wp-block-leitvia-mega-menu__column-toggle'
		);
		if (
			'Escape' === event.key &&
			inOverlay( item ) &&
			head &&
			head !== event.target &&
			'true' === head.getAttribute( 'aria-expanded' )
		) {
			event.stopPropagation();
			head.focus();
			head.click();
		} else if ( 'Escape' === event.key && ! panel.hidden ) {
			event.stopPropagation();
			// Focus first: hiding the focused element would move focus to the body and close the overlay.
			button.focus();
			toggle( menu, false );
		}
	} );
	item.addEventListener( 'focusout', ( event ) => {
		if (
			event.relatedTarget &&
			! item.contains( event.relatedTarget ) &&
			! inOverlay( item )
		) {
			toggle( menu, false );
		}
	} );
	item.addEventListener( 'pointerenter', ( event ) => {
		if (
			'mouse' === event.pointerType &&
			hover.matches &&
			! inOverlay( item ) &&
			( panel.hidden || menu.byHover )
		) {
			later( true, 150 );
		}
	} );
	item.addEventListener( 'pointerleave', ( event ) => {
		if ( 'mouse' === event.pointerType && menu.byHover ) {
			later( false, 300 );
		} else if ( panel.hidden ) {
			clearTimeout( menu.timer );
		}
	} );
}

document.addEventListener( 'click', ( event ) => {
	menus.forEach(
		( menu ) =>
			! menu.item.contains( event.target ) &&
			! inOverlay( menu.item ) &&
			toggle( menu, false )
	);
} );

for ( const container of document.querySelectorAll(
	'.wp-block-navigation__responsive-container'
) ) {
	if ( ! container.closest( '[data-leitvia-collapsible]' ) ) {
		continue;
	}
	container.classList.add( 'has-leitvia-collapsible' );
	const expanded = new Set();
	const isOpen = () => container.classList.contains( 'is-menu-open' );
	const toggles = () =>
		container.querySelectorAll( '.wp-block-navigation-submenu__toggle' );
	const sync = () => {
		for ( const button of isOpen() ? toggles() : [] ) {
			const value = String( expanded.has( button ) );
			if ( button.getAttribute( 'aria-expanded' ) !== value ) {
				button.setAttribute( 'aria-expanded', value );
			}
		}
	};
	let wasOpen = false;
	// Core sets aria-expanded="true" on every toggle when the overlay opens; this puts back the state kept here.
	// The overlay opens with every group closed except the ones that lead to the current page.
	new window.MutationObserver( () => {
		if ( isOpen() && ! wasOpen ) {
			expanded.clear();
			for ( const button of toggles() ) {
				if (
					button
						.closest( '.has-child' )
						?.classList.contains( 'current-menu-ancestor' )
				) {
					expanded.add( button );
				}
			}
			menus.forEach(
				( menu ) =>
					container.contains( menu.item ) && toggle( menu, false )
			);
		}
		wasOpen = isOpen();
		sync();
	} ).observe( container, {
		attributes: true,
		subtree: true,
		attributeFilter: [ 'class', 'aria-expanded' ],
	} );

	// Capture phase: core's own click handler on the toggle never runs while the overlay is open.
	container.addEventListener(
		'click',
		( event ) => {
			// The label of a group without an address (a link without href) toggles like the chevron beside it.
			const button =
				event.target.closest(
					'.wp-block-navigation-submenu__toggle'
				) ??
				event.target
					.closest( '.has-child > a:not([href])' )
					?.parentElement.querySelector(
						':scope > .wp-block-navigation-submenu__toggle'
					);
			if ( ! button || ! isOpen() ) {
				return;
			}
			event.stopPropagation();
			button.focus();
			if ( ! expanded.delete( button ) ) {
				expanded.add( button );
			}
			sync();
		},
		true
	);

	container.addEventListener(
		'keydown',
		( event ) => {
			if ( ! isOpen() ) {
				return;
			}
			const active = event.target;
			if ( 'Escape' === event.key ) {
				// The nearest open group around the focus closes; with none open, core closes the overlay.
				for (
					let item = active.closest( '.has-child' );
					item && container.contains( item );
					item = item.parentElement.closest( '.has-child' )
				) {
					const button = item.querySelector(
						':scope > .wp-block-navigation-submenu__toggle'
					);
					if ( expanded.has( button ) ) {
						event.stopPropagation();
						button.focus();
						expanded.delete( button );
						sync();
						return;
					}
				}
			} else if ( 'Tab' === event.key ) {
				// Core's focus trap knows only what was visible when the overlay opened.
				event.stopPropagation();
				const list = [
					...container.querySelectorAll( FOCUSABLE ),
				].filter( ( el ) =>
					el.checkVisibility( { checkVisibilityCSS: true } )
				);
				const target = event.shiftKey
					? active === list[ 0 ] && list.at( -1 )
					: active === list.at( -1 ) && list[ 0 ];
				if ( target ) {
					event.preventDefault();
					target.focus();
				}
			}
		},
		true
	);
}
