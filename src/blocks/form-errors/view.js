// Error summary (leitvia/form-errors) for every form inside the block. Once this module runs, the forms validate here
// instead of in the browser bubbles: on submit the invalid fields are listed above the form as links, each field gets
// aria-invalid and a message next to it, and the summary takes the focus. Errors the server already marked
// (aria-invalid="true", Gravity Forms field errors) are listed right on load. Without this module nothing changes.
const B = 'wp-block-leitvia-form-errors';
let count = 0;
const idOf = ( el, prefix ) =>
	el.id || ( el.id = `${ B }-${ prefix }-${ ++count }` );
const text = ( el ) => el?.textContent.replace( /\s+/g, ' ' ).trim() ?? '';

// A radio group is one field, represented by its first radio.
const keyOf = ( field ) =>
	field.type === 'radio' && field.name
		? ( field.form?.querySelector(
				`input[type="radio"][name="${ window.CSS.escape( field.name ) }"]`
			) ?? field )
		: field;

// The visible name of the field without required markers.
function nameOf( field ) {
	const label =
		field.type === 'radio'
			? field.closest( 'fieldset' )?.querySelector( 'legend' )
			: field.labels?.[ 0 ];
	if ( label ) {
		const copy = label.cloneNode( true );
		copy.querySelectorAll(
			'[class*="required"], input, select, textarea'
		).forEach( ( el ) => el.remove() );
		const name = text( copy ).replace( /\s*\*$/, '' );
		if ( name ) {
			return name;
		}
	}
	return (
		field.getAttribute( 'aria-label' ) || field.placeholder || field.name
	);
}

for ( const block of document.querySelectorAll( `.${ B }` ) ) {
	const summary = block.querySelector( `:scope > .${ B }__summary` );
	if ( ! summary ) {
		continue;
	}
	const title = summary.querySelector( `.${ B }__title` );
	const list = summary.querySelector( `.${ B }__list` );
	let titles = [];
	try {
		titles = JSON.parse( summary.dataset.titles );
	} catch {}
	summary.setAttribute( 'aria-labelledby', idOf( title, 'title' ) );
	// field => { message element, whether this module made it, describedby before, edited since }
	const errors = new Map();

	// Messages of corrected fields keep their space until the next submit, so nothing moves under the pointer.
	const resolved = new Set();

	const clear = ( field, keepSpace = false ) => {
		const error = errors.get( field );
		if ( error.own && keepSpace ) {
			error.message.classList.add( 'is-resolved' );
			resolved.add( error.message );
		} else if ( error.own ) {
			error.message.remove();
		}
		field.removeAttribute( 'aria-invalid' );
		if ( error.describedby === null ) {
			field.removeAttribute( 'aria-describedby' );
		} else {
			field.setAttribute( 'aria-describedby', error.describedby );
		}
		errors.delete( field );
	};

	const mark = ( field, message, existing ) => {
		if ( errors.has( field ) ) {
			clear( field );
		}
		const describedby = field.getAttribute( 'aria-describedby' );
		let element = existing;
		if ( ! element && message ) {
			element = document.createElement( 'span' );
			element.className = `${ B }__message`;
			element.textContent = message;
			// Between label and input, or after the label of a checkbox or radio.
			const label = field.labels?.[ 0 ];
			if ( /^(checkbox|radio)$/.test( field.type ) && label ) {
				label.after( element );
			} else {
				field.before( element );
			}
		}
		const ids = ( describedby ?? '' ).split( /\s+/ ).filter( Boolean );
		if ( element && ! ids.includes( idOf( element, 'message' ) ) ) {
			ids.unshift( element.id );
		}
		field.setAttribute( 'aria-invalid', 'true' );
		if ( ids.length ) {
			field.setAttribute( 'aria-describedby', ids.join( ' ' ) );
		}
		errors.set( field, {
			message: element,
			own: !! element && ! existing,
			describedby,
			edited: false,
		} );
	};

	const render = () => {
		// In document order.
		const fields = [
			...block.querySelectorAll( 'input, select, textarea' ),
		].filter( ( field ) => errors.has( field ) );
		list.replaceChildren(
			...fields.map( ( field ) => {
				const item = document.createElement( 'li' );
				const link = document.createElement( 'a' );
				link.href = `#${ idOf( field, 'field' ) }`;
				link.textContent = [
					nameOf( field ),
					text( errors.get( field ).message ),
				]
					.filter( Boolean )
					.join( ': ' );
				item.append( link );
				return item;
			} )
		);
		// ponytail: PHP hands over the headings for 1 to 20 errors; above 20 the form for 20 gets the real number, which
		// is wrong only in languages whose plural changes again beyond 20.
		const heading =
			titles[ Math.min( fields.length, titles.length ) - 1 ] ?? '';
		title.textContent = heading.replace( '%d', fields.length );
		summary.hidden = fields.length === 0;
	};

	const show = () => {
		render();
		summary.scrollIntoView( { block: 'start' } );
		summary.focus( { preventScroll: true } );
	};

	block
		.querySelectorAll( 'form' )
		.forEach( ( form ) => ( form.noValidate = true ) );

	// Capture phase: runs before the handlers of the form itself, which never see a submit with errors.
	block.addEventListener(
		'submit',
		( event ) => {
			const form = event.target;
			const invalid = new Set(
				[ ...form.elements ]
					.filter(
						( field ) =>
							field.willValidate && ! field.validity.valid
					)
					.map( keyOf )
			);
			if ( ! invalid.size ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			resolved.forEach( ( message ) => message.remove() );
			resolved.clear();
			for ( const field of errors.keys() ) {
				if (
					field.form === form &&
					! invalid.has( field ) &&
					errors.get( field ).own
				) {
					clear( field );
				}
			}
			invalid.forEach( ( field ) =>
				mark( field, field.dataset.error || field.validationMessage )
			);
			show();
		},
		true
	);

	const edited = ( event ) => {
		const error = errors.get( keyOf( event.target ) );
		if ( error ) {
			error.edited = true;
		}
	};
	block.addEventListener( 'input', edited );
	block.addEventListener( 'change', edited );
	// A corrected field loses its error when the visitor leaves it. The summary stays as it is until the next submit,
	// for the same reason as the message space.
	block.addEventListener( 'focusout', ( event ) => {
		const field = keyOf( event.target );
		if ( errors.get( field )?.edited && field.validity?.valid ) {
			clear( field, true );
		}
	} );

	list.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( 'a' );
		const field =
			link &&
			document.getElementById(
				decodeURIComponent( link.hash.slice( 1 ) )
			);
		if ( field ) {
			event.preventDefault();
			( field.labels?.[ 0 ] ?? field ).scrollIntoView( {
				block: 'start',
			} );
			field.focus( { preventScroll: true } );
		}
	} );

	// Errors from the server: fields marked invalid and the fields of Gravity Forms error containers.
	const marked = block.querySelectorAll(
		'form [aria-invalid="true"], form .gfield_error :is(input, select, textarea):not([type="hidden"])'
	);
	for ( const field of new Set( [ ...marked ].map( keyOf ) ) ) {
		const ref =
			field.getAttribute( 'aria-errormessage' ) ||
			field.getAttribute( 'aria-describedby' )?.split( /\s+/ )[ 0 ];
		const existing =
			field
				.closest( '.gfield' )
				?.querySelector(
					'.validation_message, .gfield_validation_message'
				) ?? ( ref ? document.getElementById( ref ) : null );
		mark(
			field,
			field.dataset.error || field.validationMessage,
			field.dataset.error ? null : existing
		);
	}
	if ( errors.size ) {
		show();
	}
}
