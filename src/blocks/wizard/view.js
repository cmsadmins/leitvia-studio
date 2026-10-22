// Wizard (leitvia/wizard): the server renders the first step visible, the others hidden, and Back, Next and the
// progress text; without scripting a <noscript> style shows every step and the submit button. This module moves
// between the steps, checks the required fields of a step with the browser's own validation before Next, and moves
// focus to the legend of the new step.
function enhance( form ) {
	const steps = [
		...form.querySelectorAll( ':scope > .wp-block-leitvia-wizard-step' ),
	];
	const nav = form.querySelector( ':scope > .wp-block-leitvia-wizard__nav' );
	if ( steps.length < 2 || ! nav ) {
		return;
	}
	const back = nav.querySelector( '[data-wizard="back"]' );
	const next = nav.querySelector( '[data-wizard="next"]' );
	const progress = nav.querySelector( '.wp-block-leitvia-wizard__progress' );
	const submit = form.querySelector(
		':scope > .wp-block-leitvia-wizard__submit'
	);
	let current = 0;

	const valid = () => {
		const invalid = [
			...steps[ current ].querySelectorAll( 'input, select, textarea' ),
		].find( ( field ) => ! field.checkValidity() );
		invalid?.reportValidity();
		return ! invalid;
	};

	const show = ( index ) => {
		current = index;
		steps.forEach( ( step, i ) => ( step.hidden = i !== index ) );
		back.hidden = 0 === index;
		next.hidden = index === steps.length - 1;
		submit.hidden = index !== steps.length - 1;
		progress.textContent = progress.dataset.template
			.replace( '%1$d', index + 1 )
			.replace( '%2$d', steps.length );
		steps[ index ].querySelector( ':scope > legend' )?.focus();
	};

	back.addEventListener( 'click', () => show( current - 1 ) );
	next.addEventListener( 'click', () => valid() && show( current + 1 ) );
	// Enter in a field before the last step goes on instead of sending the form early.
	form.addEventListener( 'submit', ( event ) => {
		if ( current < steps.length - 1 ) {
			event.preventDefault();
			if ( valid() ) {
				show( current + 1 );
			}
		}
	} );
}

document.querySelectorAll( 'form.wp-block-leitvia-wizard' ).forEach( enhance );
