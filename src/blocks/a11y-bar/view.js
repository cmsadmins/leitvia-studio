// Text size, high contrast and read aloud for the accessibility bar. Settings go to localStorage only after a click;
// the inline script before the bar (class-easy-language.php) applies them on the next page before the first paint.
// Read aloud only uses a voice of the device (localService) in the language of the page, never one from the cloud.
const KEY = 'leitvia-a11y';
const root = document.documentElement;
const controls = document.querySelector(
	'.wp-block-leitvia-a11y-bar__controls'
);

if ( controls ) {
	let settings = {};
	try {
		settings = JSON.parse( window.localStorage.getItem( KEY ) ) || {};
	} catch {}
	const value = controls.querySelector( '.wp-block-leitvia-a11y-bar__value' );
	const contrast = controls.querySelector( '[data-action="contrast"]' );
	const apply = () => {
		const size = settings.size || 100;
		root.style.fontSize = 100 === size ? '' : `${ size }%`;
		value.textContent = `${ size }\u00a0%`;
		root.classList.toggle( 'leitvia-high-contrast', !! settings.contrast );
		contrast?.setAttribute(
			'aria-pressed',
			String( !! settings.contrast )
		);
	};
	const change = ( next ) => {
		settings = { ...settings, ...next };
		try {
			window.localStorage.setItem( KEY, JSON.stringify( settings ) );
		} catch {}
		apply();
	};
	const step = ( delta ) =>
		Math.min( 200, Math.max( 100, ( settings.size || 100 ) + delta ) );

	const synth = window.speechSynthesis;
	const button = controls.querySelector( '[data-action="read"]' );
	const label = button.textContent;
	const lang = ( root.lang || 'en' ).toLowerCase().split( '-' )[ 0 ];
	const voice = () =>
		synth
			?.getVoices()
			.find(
				( v ) =>
					v.localService &&
					lang === v.lang.toLowerCase().split( /[-_]/ )[ 0 ]
			);
	const offer = () => {
		button.hidden = ! voice();
	};
	const read = () => {
		const local = voice();
		if ( synth.speaking || ! local ) {
			synth.cancel();
			return;
		}
		const utterance = new window.SpeechSynthesisUtterance(
			document.querySelector( 'main' )?.innerText ?? ''
		);
		utterance.voice = local;
		utterance.lang = local.lang;
		utterance.onend = utterance.onerror = () => {
			button.textContent = label;
		};
		button.textContent = button.dataset.stop;
		synth.speak( utterance );
	};
	if ( synth ) {
		offer();
		synth.addEventListener( 'voiceschanged', offer );
		window.addEventListener( 'pagehide', () => synth.cancel() );
	}

	const actions = {
		smaller: () => change( { size: step( -25 ) } ),
		larger: () => change( { size: step( 25 ) } ),
		reset: () => change( { size: 100 } ),
		contrast: () => change( { contrast: ! settings.contrast } ),
		read,
	};
	controls.addEventListener( 'click', ( event ) =>
		actions[ event.target.closest( 'button' )?.dataset.action ]?.()
	);

	apply();
	// Only now, so the restored size is not announced on load.
	value.setAttribute( 'role', 'status' );
	controls.hidden = false;
}
